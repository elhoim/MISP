<?php

require_once __DIR__ . '/IntegrationTestCase.php';

App::uses('ServerSyncTool', 'Tools');
App::uses('HttpSocketExtended', 'Tools');
App::uses('RedisTool', 'Tools');

/**
 * Characterization of Server::pull()'s failure handling and of its technique
 * dispatch.
 *
 * pull() used to be unreachable from a test: it built its own ServerSyncTool,
 * whose socket is private and has no setter, so every branch below needed a
 * second, running MISP instance to observe. The optional trailing
 * `ServerSyncTool $serverSync = null` argument - the same shape
 * checkVersionCompatibility() has carried at Server.php:3254 for years - lets
 * a fake stand in, and the whole error path becomes ordinary array-in,
 * string-out code.
 *
 * These are CHARACTERIZATIONS (ADR 0002) except where noted: they record the
 * message pull() returns today so that a refactor of the error handling fails
 * loudly. The one SPECIFICATION here is that a 403 is reported differently
 * from every other failure - that distinction is the difference between an
 * admin looking at their sync user's permissions and an admin looking at
 * their network, and losing it is a regression rather than a re-baseline.
 *
 * What is deliberately NOT covered: the event loop itself (__pullEvent), the
 * proposal/sighting/analyst-data/collection sub-pulls, and the galaxy cluster
 * pull. Those each deserve their own file and their own fake surface; this
 * one stops at the point where pull() has decided whether it can proceed.
 *
 * Sibling files: ServerPullTransformTest covers the pure transforms inside
 * the pull path (filterRuleToParameter, __checkIfEventSaveAble,
 * __updatePulledEventBeforeInsert); ServerVersionCompatibilityTest covers the
 * version ladder. Nothing here repeats them.
 */
class ServerPullSyncSeamTest extends IntegrationTestCase
{
    /** @var int|null */
    private $serverId;

    protected function setUp(): void
    {
        parent::setUp();
        $serverModel = $this->model('Server');
        $serverModel->create();
        $saved = $serverModel->save([
            'Server' => [
                'name' => 'pull seam fixture',
                // .invalid is reserved by RFC 2606 and never resolves, so if a
                // future refactor drops the injected client and builds a real
                // one the test fails on connection rather than reaching out.
                'url' => 'https://pull-seam.invalid',
                'authkey' => str_repeat('a', 40),
                'org_id' => 1,
                'remote_org_id' => 1,
                'push' => 0,
                'pull' => 1,
                // servers has NOT NULL columns with no DB default;
                // omitting any of them makes save() fail with a 1364.
                'self_signed' => 0,
                'pull_rules' => '',
                'push_rules' => '',
                'pull_galaxy_clusters' => 0,
                'internal' => 0,
            ],
        ]);
        $this->assertNotEmpty($saved, 'could not create the fixture server');
        $this->serverId = (int)$serverModel->id;
    }

    protected function tearDown(): void
    {
        if ($this->serverId) {
            try {
                $this->model('Server')->delete($this->serverId);
            } catch (\Throwable $e) {
                // Best effort: a test must not fail during cleanup.
            }
            $this->serverId = null;
        }
        parent::tearDown();
    }

    private function server(): array
    {
        return $this->model('Server')->find('first', [
            'recursive' => -1,
            'conditions' => ['Server.id' => $this->serverId],
        ]);
    }

    /**
     * getEventIndexFromServer() calls RedisTool::init() unconditionally on
     * every page it fetches, so a fake sync client removes the HTTP dependency
     * but NOT the Redis one. Tests that reach the event index say so here.
     */
    private function requireRedis(): void
    {
        try {
            RedisTool::init();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Redis is not reachable: ' . $e->getMessage());
        }
    }

    /** A 403 response wrapped the way HttpSocketExtended wraps one. */
    private function forbidden(): HttpSocketHttpException
    {
        $response = new HttpSocketResponseExtended();
        $response->code = 403;
        $response->body = '{"name":"Authentication failed."}';
        return new HttpSocketHttpException($response, 'https://pull-seam.invalid/servers/getVersion');
    }

    private function pull($serverSync, string $technique = 'full')
    {
        return $this->model('Server')->pull(
            $this->adminUser(),
            $technique,
            $this->server(),
            false,
            false,
            $serverSync
        );
    }

    // ------------------------------------------- the version handshake fails

    /**
     * SPECIFICATION: a 403 on the version handshake is the single most common
     * sync misconfiguration - a sync user without perm_sync, or a stale auth
     * key - and the message must point the admin at permissions rather than at
     * the network. Collapsing it into the generic branch would be a
     * regression, not a re-baseline.
     */
    public function testAForbiddenVersionHandshakeIsReportedAsAPermissionProblem(): void
    {
        $message = $this->pull(new PullSeamServerSync($this->forbidden()));

        $this->assertIsString($message, 'a failed handshake aborts the pull with a message');
        $this->assertStringContainsString('Not authorised', $message);
        $this->assertStringContainsString('invalid auth key', $message);
    }

    /**
     * CHARACTERIZATION: every non-403 failure is reported by echoing the
     * exception's own message, whatever it happens to say.
     */
    public function testAnyOtherHandshakeFailureEchoesTheExceptionMessage(): void
    {
        $message = $this->pull(new PullSeamServerSync(new Exception('connection timed out')));

        $this->assertSame('connection timed out', $message);
    }

    /**
     * CHARACTERIZATION: the 403 branch keys off the HTTP status code, not off
     * the exception class alone - a 500 wrapped in the very same exception
     * type falls through to the generic message.
     */
    public function testAFiveHundredIsNotTreatedAsAPermissionProblem(): void
    {
        $response = new HttpSocketResponseExtended();
        $response->code = 500;
        $response->body = 'boom';
        $exception = new HttpSocketHttpException($response, 'https://pull-seam.invalid/servers/getVersion');

        $message = $this->pull(new PullSeamServerSync($exception));

        $this->assertStringNotContainsString('Not authorised', $message);
        $this->assertStringContainsString('500', $message);
    }

    /**
     * CHARACTERIZATION: the failure is written to the audit log against the
     * server it concerns, so an admin can find it without reading a worker's
     * stderr.
     */
    public function testAFailedHandshakeIsRecordedInTheLog(): void
    {
        $before = $this->model('Log')->find('count', [
            'conditions' => ['Log.model' => 'Server', 'Log.model_id' => $this->serverId],
        ]);

        $this->pull(new PullSeamServerSync($this->forbidden()));

        $after = $this->model('Log')->find('count', [
            'conditions' => ['Log.model' => 'Server', 'Log.model_id' => $this->serverId],
        ]);
        $this->assertGreaterThan($before, $after, 'the pull failure was not logged');
    }

    // ----------------------------------- the event id fetch fails afterwards

    /**
     * SPECIFICATION: the handshake succeeded, so the auth key is valid for
     * /servers/getVersion; a 403 on the event index means the sync user is
     * missing a permission for that endpoint specifically. This is the SECOND
     * of the two 403 catches in pull() and it carries the same message as the
     * first - which is what makes the message useful: whichever call is
     * rejected, the admin is told to look at permissions.
     */
    public function testAForbiddenEventIndexIsAlsoReportedAsAPermissionProblem(): void
    {
        $this->requireRedis();

        $sync = new PullSeamServerSync($this->healthyInfo());
        $sync->eventIndexThrows = $this->forbidden();

        $message = $this->pull($sync, 'full');

        $this->assertIsString($message);
        $this->assertStringContainsString('Not authorised', $message);
    }

    /**
     * CHARACTERIZATION: an unusable technique is not validated up front. It
     * travels all the way into __getEventIdListBasedOnPullTechnique, throws
     * there, and is then reported through the same catch that handles a
     * network failure - so the caller receives a *string* rather than seeing
     * an InvalidArgumentException. Note this needs no remote call at all: the
     * dispatch rejects the value before any request is made.
     */
    public function testAnUnknownTechniqueIsReturnedAsAMessageNotThrown(): void
    {
        $message = $this->pull(new PullSeamServerSync($this->healthyInfo()), 'sideways');

        $this->assertSame('Invalid pull technique `sideways`.', $message);
    }

    // ------------------------------------------------- the technique dispatch

    /**
     * The dispatch itself is private, and driving it through pull() would drag
     * in the whole event loop. Reflection keeps the assertions on the branch
     * under test, consistent with ServerPullTransformTest's stated practice.
     */
    private function dispatch($technique, $serverSync)
    {
        $method = new ReflectionMethod('Server', '__getEventIdListBasedOnPullTechnique');
        $method->setAccessible(true);
        return $method->invoke($this->model('Server'), $technique, $serverSync, false);
    }

    /**
     * SPECIFICATION: a numeric technique is a single remote event id and must
     * not trigger an index fetch. The fake throws from every remote call, so
     * this assertion is only satisfied if no call was made.
     */
    public function testANumericTechniquePullsExactlyThatEventWithoutAnIndexFetch(): void
    {
        $this->assertSame([42], $this->dispatch('42', new PullSeamServerSync($this->healthyInfo())));
    }

    /** SPECIFICATION: a UUID technique is likewise a single event, passed through verbatim. */
    public function testAUuidTechniquePullsExactlyThatEvent(): void
    {
        $uuid = '5c0c2b4e-1f60-4a5e-9c2f-0a2b3c4d5e6f';
        $this->assertSame([$uuid], $this->dispatch($uuid, new PullSeamServerSync($this->healthyInfo())));
    }

    /**
     * KNOWN-DEFECT: `is_numeric()` accepts far more than an event id, and
     * since PHP 7.1 intval() honours the exponent - so a technique of '1e3'
     * is accepted and silently pulls event 1000, three orders of magnitude
     * away from anything the operator could have meant. Floats behave the
     * same way ('3.9' becomes event 3). A stricter guard (ctype_digit, or an
     * intval round-trip check) would reject these, but changing it is a
     * behaviour change for anyone who has scripted a float-ish technique, so
     * this pins the current mapping rather than fixing it.
     *
     * (Verified against the container's PHP 8: intval('1e3') === 1000.)
     */
    public function testScientificNotationIsAcceptedAsAnEventIdAndExpanded(): void
    {
        $this->assertSame([1000], $this->dispatch('1e3', new PullSeamServerSync($this->healthyInfo())));
    }

    /** KNOWN-DEFECT, same guard: a float technique is truncated, not rejected. */
    public function testAFloatTechniqueIsTruncatedToAnEventId(): void
    {
        $this->assertSame([3], $this->dispatch('3.9', new PullSeamServerSync($this->healthyInfo())));
    }

    /** SPECIFICATION: anything that is neither keyword, number nor UUID is rejected. */
    public function testAnUnrecognisedTechniqueThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->dispatch('sideways', new PullSeamServerSync($this->healthyInfo()));
    }

    /**
     * KNOWN-DEFECT: an empty technique is not "invalid" by the first three
     * tests, but it is also not numeric and not a UUID, so it lands on the
     * throw. That is the right outcome, but the message interpolates the
     * empty string and reads "Invalid pull technique ``", which tells an
     * operator reading the log nothing about which server sent it.
     */
    public function testAnEmptyTechniqueThrowsWithAnUninformativeMessage(): void
    {
        try {
            $this->dispatch('', new PullSeamServerSync($this->healthyInfo()));
            $this->fail('an empty technique should not be accepted');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Invalid pull technique ``.', $e->getMessage());
        }
    }

    /** A remote advertising the local version, so the handshake succeeds. */
    private function healthyInfo(): array
    {
        $local = $this->model('Server')->checkMISPVersion();
        return [
            'version' => sprintf('%d.%d.%d', $local['major'], $local['minor'], $local['hotfix']),
            'perm_sync' => true,
        ];
    }
}

/**
 * ServerSyncTool with the HTTP layer removed.
 *
 * parent::__construct() is deliberately NOT called, so no socket is ever
 * built and the parent's private $server/$socket stay unset - which means
 * every method the exercised path touches has to be overridden here. Any
 * remote call that is NOT overridden would fatal on the missing socket, and
 * that is on purpose: a test that unexpectedly reaches the network fails
 * loudly instead of hanging on a connect timeout.
 */
class PullSeamServerSync extends ServerSyncTool
{
    /** @var array|Throwable the getVersion payload, or what info() should throw */
    private $injectedInfo;

    /** @var Throwable|null thrown from eventIndex() when set */
    public $eventIndexThrows;

    /** @var array pages eventIndex() replays, one per call */
    public $pages = [];

    /** @var array the filter rules each eventIndex() call received */
    public $eventIndexCalls = [];

    public function __construct($injectedInfo)
    {
        $this->injectedInfo = $injectedInfo;
    }

    public function info()
    {
        if ($this->injectedInfo instanceof Throwable) {
            throw $this->injectedInfo;
        }
        return $this->injectedInfo;
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

    /** CakeLog is not configured the same way under PHPUnit; stay silent. */
    public function debug($message)
    {
    }

    public function eventIndex($params = [], $etag = null)
    {
        $this->eventIndexCalls[] = $params;
        if ($this->eventIndexThrows) {
            throw $this->eventIndexThrows;
        }
        $page = array_shift($this->pages);
        $response = new HttpSocketResponseExtended();
        $response->code = 200;
        $response->body = json_encode($page === null ? [] : $page);
        return $response;
    }
}
