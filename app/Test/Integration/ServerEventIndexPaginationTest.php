<?php

require_once __DIR__ . '/IntegrationTestCase.php';

App::uses('ServerSyncTool', 'Tools');
App::uses('HttpSocketExtended', 'Tools');
App::uses('RedisTool', 'Tools');

/**
 * Specification tests for Server::getEventIdsFromServer()'s pagination guards.
 *
 * This loop walks a remote instance's whole event index one page at a time.
 * Three of its four exit conditions exist purely to survive a remote that does
 * not behave: one that ignores `limit` and answers with the entire index, and
 * one that honours `limit` but ignores `page` and therefore replays the first
 * page forever. Neither can be produced by a healthy peer, so neither has ever
 * been exercised - the second one is an infinite loop against a live MISP, and
 * before this seam the only way to reach it was to run a deliberately broken
 * remote instance.
 *
 * These are SPECIFICATIONS (ADR 0002): the guards are there on purpose and a
 * change that breaks one of these assertions is a hang or a data-loss bug, not
 * a re-baseline.
 *
 * NOTE ON REDIS: injecting a fake sync client removes the HTTP dependency but
 * NOT the Redis one. getEventIndexFromServer() calls RedisTool::init()
 * unconditionally before every page - it revalidates each page against its own
 * cached ETag - so this whole file skips cleanly when Redis is unavailable.
 * (The task brief placed that call in getEventIdsFromServer(); it is actually
 * one frame down in getEventIndexFromServer(), Server.php:1075. The effect is
 * the same: once per page, unconditionally.)
 *
 * The fake never sets an `etag` header, so no cache write happens and the test
 * leaves no Redis keys behind.
 */
class ServerEventIndexPaginationTest extends IntegrationTestCase
{
    /** @var mixed */
    private $savedChunkSize;

    protected function setUp(): void
    {
        parent::setUp();
        try {
            RedisTool::init();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Redis is not reachable: ' . $e->getMessage());
        }
        $this->savedChunkSize = Configure::read('MISP.event_index_pull_chunk_size');
        // A tiny page keeps the scripted fixtures readable. The production
        // default is 10000; the loop logic does not depend on the number.
        Configure::write('MISP.event_index_pull_chunk_size', 2);
    }

    protected function tearDown(): void
    {
        if ($this->savedChunkSize === null) {
            Configure::delete('MISP.event_index_pull_chunk_size');
        } else {
            Configure::write('MISP.event_index_pull_chunk_size', $this->savedChunkSize);
        }
        parent::tearDown();
    }

    /** One row of the remote's minimal event index. */
    private function row(int $id, string $uuid, int $published = 1): array
    {
        return [
            'id' => $id,
            'uuid' => $uuid,
            'published' => $published,
            'timestamp' => 1600000000,
            'orgc_uuid' => 'ffffffff-0000-0000-0000-000000000001',
        ];
    }

    private function uuid(int $n): string
    {
        return sprintf('00000000-0000-4000-8000-%012d', $n);
    }

    /**
     * Run the private loop against a scripted remote.
     *
     * $all = true skips the blocklist/published/staleness filtering so the
     * assertions are about pagination alone; the unpublished-drop test below
     * flips it.
     */
    private function fetch(array $pages, bool $all = true, bool $force = true): array
    {
        $sync = new PagingServerSync($pages);
        // ClassRegistry has to hand out the model BEFORE ReflectionMethod is
        // asked for it: Cake loads model classes lazily, so reflecting on the
        // bare name first fails with `Class "Server" does not exist`.
        $server = $this->model('Server');
        $method = new ReflectionMethod('Server', 'getEventIdsFromServer');
        $method->setAccessible(true);
        $uuids = $method->invoke($server, $sync, $all, true, $force);
        return [$uuids, $sync];
    }

    // ---------------------------------------------------------- normal paging

    public function testAnEmptyFirstPageEndsTheWalkImmediately(): void
    {
        list($uuids, $sync) = $this->fetch([[]]);

        $this->assertSame([], $uuids);
        $this->assertCount(1, $sync->calls, 'an empty page must not be followed by another request');
    }

    public function testAShortPageIsTheLastPage(): void
    {
        list($uuids, $sync) = $this->fetch([
            [$this->row(1, $this->uuid(1))], // 1 < pageSize 2
        ]);

        $this->assertSame([$this->uuid(1)], $uuids);
        $this->assertCount(1, $sync->calls);
    }

    public function testAFullPageIsFollowedByTheNextOne(): void
    {
        list($uuids, $sync) = $this->fetch([
            [$this->row(1, $this->uuid(1)), $this->row(2, $this->uuid(2))],
            [$this->row(3, $this->uuid(3))],
        ]);

        $this->assertSame([$this->uuid(1), $this->uuid(2), $this->uuid(3)], $uuids);
        $this->assertCount(2, $sync->calls);
        $this->assertSame(1, $sync->calls[0]['page']);
        $this->assertSame(2, $sync->calls[1]['page']);
    }

    /**
     * The remote applies LIMIT/OFFSET with no implicit ordering, so the
     * request must pin a unique sort key or page boundaries can skip or
     * duplicate events. This asserts the request, not the response.
     */
    public function testEveryPageRequestPinsAUniqueAscendingSortKey(): void
    {
        list(, $sync) = $this->fetch([
            [$this->row(1, $this->uuid(1)), $this->row(2, $this->uuid(2))],
            [],
        ]);

        foreach ($sync->calls as $call) {
            $this->assertSame('id', $call['sort']);
            $this->assertSame('asc', $call['direction']);
            $this->assertSame(2, $call['limit']);
        }
    }

    public function testTheSameEventOnTwoPagesIsCountedOnce(): void
    {
        list($uuids) = $this->fetch([
            [$this->row(1, $this->uuid(1)), $this->row(2, $this->uuid(2))],
            [$this->row(3, $this->uuid(2)), $this->row(4, $this->uuid(4))],
            [],
        ]);

        $this->assertSame(
            [$this->uuid(1), $this->uuid(2), $this->uuid(4)],
            $uuids,
            'uuids are deduplicated and stay in first-seen order'
        );
    }

    // ------------------------------------------------- the misbehaving remotes

    /**
     * A remote too old to understand `limit` returns its entire index in one
     * response. More rows than were asked for is the tell: that page already
     * IS the complete set, so it is processed and the walk stops - which
     * reproduces the pre-pagination behaviour exactly.
     */
    public function testARemoteThatIgnoresTheLimitIsHandledInOneRequest(): void
    {
        list($uuids, $sync) = $this->fetch([
            [$this->row(1, $this->uuid(1)), $this->row(2, $this->uuid(2)), $this->row(3, $this->uuid(3))],
            [$this->row(4, $this->uuid(4))], // must never be requested
        ]);

        $this->assertSame([$this->uuid(1), $this->uuid(2), $this->uuid(3)], $uuids);
        $this->assertCount(1, $sync->calls, 'an over-long page is the whole index; stop there');
    }

    /**
     * The dangerous one: a remote that honours `limit` but ignores `page`
     * replays page 1 forever. The index is sorted by Event.id asc, so the max
     * id must strictly advance between pages; when it does not, the page is
     * still processed (it may hold events we have not seen) and then the walk
     * stops. Without this guard the loop never terminates.
     */
    public function testARemoteThatIgnoresThePageParameterDoesNotLoopForever(): void
    {
        list($uuids, $sync) = $this->fetch([
            [$this->row(1, $this->uuid(1)), $this->row(2, $this->uuid(2))],
            [$this->row(1, $this->uuid(1)), $this->row(2, $this->uuid(2))],
            [$this->row(1, $this->uuid(1)), $this->row(2, $this->uuid(2))],
        ]);

        $this->assertSame([$this->uuid(1), $this->uuid(2)], $uuids);
        $this->assertCount(2, $sync->calls, 'the second identical page must be the last request');
    }

    /**
     * The guard is `<=`, not `<`: a page whose max id went BACKWARDS is just
     * as broken as one that stood still, and is caught the same way.
     */
    public function testAPageWhoseMaxIdGoesBackwardsAlsoStopsTheWalk(): void
    {
        list($uuids, $sync) = $this->fetch([
            [$this->row(10, $this->uuid(10)), $this->row(11, $this->uuid(11))],
            [$this->row(1, $this->uuid(1)), $this->row(2, $this->uuid(2))],
            [$this->row(20, $this->uuid(20)), $this->row(21, $this->uuid(21))],
        ]);

        $this->assertSame(
            [$this->uuid(10), $this->uuid(11), $this->uuid(1), $this->uuid(2)],
            $uuids,
            'the offending page is kept, the walk stops after it'
        );
        $this->assertCount(2, $sync->calls);
    }

    /**
     * KNOWN-DEFECT: the advance check runs on the max id of the page as
     * DELIVERED, before any filtering, which is right - but it is computed
     * with `max(array_column($eventArray, 'id'))` and array_column silently
     * yields [] for rows that carry no `id`. max([]) throws a ValueError on
     * PHP 8 (a warning returning false on PHP 7), so a remote answering with
     * a well-formed but id-less minimal index crashes the pull instead of
     * being rejected as a malformed response. The `minimal=1` index always
     * includes `id` today, so this is latent rather than live - but the guard
     * that is supposed to protect against a misbehaving remote is itself
     * unguarded against one.
     *
     * The test asserts the crash rather than a clean error: a fix would turn
     * this into a caught, reported failure, and this assertion is what would
     * then need updating.
     */
    public function testAnIndexWithoutIdsCrashesTheAdvanceCheck(): void
    {
        $this->expectException(\Throwable::class);
        $this->fetch([
            [['uuid' => $this->uuid(1), 'published' => 1, 'timestamp' => 1600000000]],
        ]);
    }

    // ------------------------------------------------------ the published drop

    /**
     * SPECIFICATION: an unpublished event on the remote is not ours to pull -
     * it has not been released by its owner. The drop happens per page, so it
     * must survive pagination.
     */
    public function testUnpublishedEventsAreDroppedFromEveryPage(): void
    {
        list($uuids) = $this->fetch(
            [
                [$this->row(1, $this->uuid(1), 1), $this->row(2, $this->uuid(2), 0)],
                [$this->row(3, $this->uuid(3), 0), $this->row(4, $this->uuid(4), 1)],
                [],
            ],
            false, // $all = false, so the filtering pipeline runs
            true   // $force = true, so staleness filtering does not need local rows
        );

        $this->assertSame([$this->uuid(1), $this->uuid(4)], $uuids);
    }

    /**
     * CHARACTERIZATION: with $all = true - the "just tell me what is over
     * there" mode used by syncProposals() and the overlap report - nothing is
     * filtered, and unpublished events come back too.
     */
    public function testTheAllModeKeepsUnpublishedEvents(): void
    {
        list($uuids) = $this->fetch([
            [$this->row(1, $this->uuid(1), 0)],
        ], true);

        $this->assertSame([$this->uuid(1)], $uuids);
    }

    /**
     * CHARACTERIZATION: a page emptied entirely by filtering is NOT treated as
     * an empty page. The break decision uses the number of rows the remote
     * delivered, not the number that survived, so the walk correctly carries
     * on to the next page.
     */
    public function testAPageFilteredDownToNothingStillAdvances(): void
    {
        list($uuids, $sync) = $this->fetch(
            [
                [$this->row(1, $this->uuid(1), 0), $this->row(2, $this->uuid(2), 0)],
                [$this->row(3, $this->uuid(3), 1)],
            ],
            false,
            true
        );

        $this->assertSame([$this->uuid(3)], $uuids);
        $this->assertCount(2, $sync->calls, 'a fully filtered page must not end the walk');
    }
}

/**
 * ServerSyncTool replaced by a scripted list of index pages.
 *
 * parent::__construct() is deliberately not called, so no socket exists; any
 * remote call other than eventIndex() would fatal, which is the point - a test
 * that unexpectedly reaches the network fails instead of hanging.
 */
class PagingServerSync extends ServerSyncTool
{
    /** @var array<int,array> pages returned in order */
    private $pages;

    /** @var array<int,array> the filter rules each call received */
    public $calls = [];

    public function __construct(array $pages)
    {
        $this->pages = $pages;
    }

    public function eventIndex($params = [], $etag = null)
    {
        $this->calls[] = $params;
        $page = array_shift($this->pages);
        $response = new HttpSocketResponseExtended();
        $response->code = 200;
        // No etag header, so getEventIndexFromServer() writes nothing to Redis.
        $response->body = json_encode($page === null ? [] : array_values($page));
        return $response;
    }

    public function server()
    {
        return ['Server' => ['id' => 0, 'name' => 'fake', 'internal' => 0, 'pull_rules' => '']];
    }

    public function serverId()
    {
        return 0;
    }

    public function serverName()
    {
        return 'fake';
    }

    public function debug($message)
    {
    }

    public function isSupported($flag)
    {
        return false;
    }
}
