<?php

require_once __DIR__ . '/IntegrationTestCase.php';

App::uses('ServerSyncTool', 'Tools');
App::uses('HttpSocketExtended', 'Tools');
App::uses('RedisTool', 'Tools');

/**
 * Characterization of Server::push()'s gatekeeping and technique dispatch.
 *
 * Everything below runs before a single event leaves the instance, and none of
 * it was reachable from a test: push() built its own ServerSyncTool, whose
 * socket is private with no setter. With the optional trailing
 * `ServerSyncTool $serverSync = null` argument - the shape
 * checkVersionCompatibility() has carried at Server.php:3254 for years - the
 * remote's capabilities become an injected array and the gate can be driven
 * directly.
 *
 * push() forwards its injected client to syncProposals(), which otherwise
 * builds its own and fetches the remote event id list over the wire. That
 * forwarding is what makes the tests below network-free; without it every test
 * that gets past the gate would open a real socket.
 *
 * Sibling files: ServerVersionCompatibilityTest covers the version ladder
 * inside checkVersionCompatibility(); ServerPushRuleFilterTest covers
 * eventFilterPushableServers() and convertUUIDsToIDs(). This file covers only
 * what push() does with their RESULTS.
 *
 * NOTE ON REDIS: the tests that get past the gate reach syncProposals() ->
 * getEventIdsFromServer() -> getEventIndexFromServer(), which calls
 * RedisTool::init() unconditionally (Server.php:1075). A fake sync client does
 * not remove that dependency, so those tests skip cleanly without Redis; the
 * gate tests return before it and need nothing.
 */
class ServerPushSyncSeamTest extends IntegrationTestCase
{
    /** @var int|null */
    private $serverId;

    /** @var array<int,string> uuids of the events this test published, in creation order */
    private $eventUuids = [];

    /** @var array<int,int> ids of the events this test published, in creation order */
    private $eventIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $serverModel = $this->model('Server');
        $serverModel->create();
        $saved = $serverModel->save([
            'Server' => [
                'name' => 'push seam fixture',
                // RFC 2606 reserved: if a refactor drops the injected client
                // and builds a real one, the test fails rather than reaching
                // out to something that exists.
                'url' => 'https://push-seam.invalid',
                'authkey' => str_repeat('b', 40),
                'org_id' => 1,
                'remote_org_id' => 1,
                'push' => 1,
                'pull' => 0,
                // servers has NOT NULL columns with no DB default;
                // omitting any of them makes save() fail with a 1364.
                'self_signed' => 0,
                'pull_rules' => '',
                'push_rules' => '',
                // Every optional sub-push is off, so the assertions are about
                // the event push alone and each sub-push returns early.
                'push_sightings' => 0,
                'push_galaxy_clusters' => 0,
                'push_analyst_data' => 0,
                'internal' => 0,
                'lastpushedid' => 0,
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
        $this->eventIds = [];
        $this->eventUuids = [];
        parent::tearDown();
    }

    private function requireRedis(): void
    {
        try {
            RedisTool::init();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Redis is not reachable: ' . $e->getMessage());
        }
    }

    private function serverRow(): array
    {
        return $this->model('Server')->find('first', [
            'recursive' => -1,
            'conditions' => ['Server.id' => $this->serverId],
        ]);
    }

    private function setServerField(string $field, $value): void
    {
        $this->model('Server')->save([
            'Server' => ['id' => $this->serverId, $field => $value],
        ], ['callbacks' => false, 'validate' => false]);
    }

    /** A remote at exactly the local version, advertising $permissions. */
    private function remote(array $permissions = []): array
    {
        $local = $this->model('Server')->checkMISPVersion();
        return array_merge([
            'version' => sprintf('%d.%d.%d', $local['major'], $local['minor'], $local['hotfix']),
        ], $permissions);
    }

    private function push($serverSync, string $technique = 'full')
    {
        return $this->model('Server')->push(
            $this->serverId,
            $technique,
            false,
            null,
            $this->adminUser(),
            $serverSync
        );
    }

    // ------------------------------------------------------ before the gate

    /**
     * SPECIFICATION: an unknown server id is rejected before anything is sent.
     * The lookup happens ahead of the sync client, so the injected client is
     * never touched.
     */
    public function testAnUnknownServerIdIsRejected(): void
    {
        $this->expectException(NotFoundException::class);
        $this->model('Server')->push(
            0,
            'full',
            false,
            null,
            $this->adminUser(),
            new PushSeamServerSync($this->remote(['perm_sync' => true]))
        );
    }

    // ---------------------------------- what push() does with the gate result

    /**
     * SPECIFICATION: a remote that can neither sync nor accept sightings has
     * nothing to offer, and push() must not attempt an upload. The two
     * capability flags are collapsed into a single message that is then
     * returned as a string - so callers see a string, not an array, in the
     * refusal case.
     */
    public function testARemoteWithNeitherSyncNorSightingPermissionIsRefused(): void
    {
        $result = $this->push(new PushSeamServerSync($this->remote()));

        $this->assertSame('Remote instance is outdated or no permission to push.', $result);
    }

    /**
     * SPECIFICATION: sighting-only is a real and supported configuration - a
     * remote that may receive sightings but not events. It must NOT be
     * collapsed into the refusal above.
     */
    public function testASightingOnlyRemoteIsNotRefused(): void
    {
        $result = $this->push(new PushSeamServerSync($this->remote(['perm_sighting' => true])));

        $this->assertIsArray($result, 'a sighting-only remote is a legitimate push target');
        $this->assertSame([[], []], $result, 'no events are offered and none fail');
    }

    /**
     * SPECIFICATION: when the version check itself fails - a connection error,
     * a 403, a malformed answer - checkVersionCompatibility() returns a string
     * rather than the capability array, and push() must abort with it instead
     * of dereferencing it as an array.
     */
    public function testAFailedVersionCheckAbortsWithItsOwnMessage(): void
    {
        $response = new HttpSocketResponseExtended();
        $response->code = 403;
        $response->body = '{"name":"Authentication failed."}';
        $exception = new HttpSocketHttpException($response, 'https://push-seam.invalid/servers/getVersion');

        $result = $this->push(new PushSeamServerSync($exception));

        $this->assertIsString($result);
        $this->assertStringContainsString('Connection to the server has failed', $result);
        $this->assertStringContainsString('403', $result);
    }

    /** CHARACTERIZATION: that abort is written to the audit log against the server. */
    public function testAFailedVersionCheckIsLogged(): void
    {
        $before = $this->model('Log')->find('count', [
            'conditions' => ['Log.model' => 'Server', 'Log.model_id' => $this->serverId],
        ]);

        $this->push(new PushSeamServerSync(new Exception('connection refused')));

        $after = $this->model('Log')->find('count', [
            'conditions' => ['Log.model' => 'Server', 'Log.model_id' => $this->serverId],
        ]);
        $this->assertGreaterThan($before, $after, 'the push refusal was not logged');
    }

    // ------------------------------------------------- the technique dispatch

    /**
     * SPECIFICATION: 'full', 'incremental' and a numeric event id are the only
     * accepted techniques; anything else is a programming error and throws.
     * Unlike pull(), which converts the same mistake into a returned string,
     * push() lets the exception escape to its caller.
     */
    public function testAnUnknownTechniqueThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->push(new PushSeamServerSync($this->remote(['perm_sync' => true])), 'sideways');
    }

    /**
     * KNOWN-DEFECT: the technique is validated INSIDE the
     * `canPush && Server.push` block, so an invalid technique is silently
     * accepted whenever the server has push disabled. The same call is a
     * hard exception on a push-enabled server and a quiet success on a
     * push-disabled one, which makes a scripted typo impossible to notice
     * until the server is enabled. Validating the argument before the
     * capability gate would fix it; that is a behaviour change for anyone
     * relying on the quiet path, so this pins the current behaviour.
     */
    public function testAnUnknownTechniqueIsNotValidatedWhenPushIsDisabled(): void
    {
        $this->setServerField('push', 0);

        $result = $this->push(new PushSeamServerSync($this->remote(['perm_sync' => true])), 'sideways');

        $this->assertSame([[], []], $result, 'the invalid technique was never looked at');
    }

    /**
     * KNOWN-DEFECT: the numeric branch is `intval($technique) !== 0`, so the
     * event id 0 - which no MISP event has, but which is what a caller passing
     * an unset variable produces - falls through to the throw with the message
     * "Technique parameter must be 'full', 'incremental' or event ID.". That is
     * the right outcome by accident: the guard is testing for "not zero" as a
     * proxy for "is a number", so a technique of '0abc' is also intval'd to 0
     * and rejected, while '3abc' is accepted as event 3.
     */
    public function testATechniqueOfZeroIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->push(new PushSeamServerSync($this->remote(['perm_sync' => true])), '0');
    }

    public function testATrailingGarbageTechniqueIsAcceptedAsThatEventId(): void
    {
        $this->requireRedis();
        $this->publishEvents(2);

        $sync = new PushSeamServerSync($this->remote(['perm_sync' => true]));
        $this->push($sync, $this->eventIds[1] . 'abc');

        $this->assertSame(
            [$this->eventUuids[1]],
            $this->offeredUuids($sync),
            "intval() truncated the technique instead of rejecting it"
        );
    }

    /**
     * Create and publish $count events owned by the local org, and remember
     * their ids and uuids in creation order.
     */
    private function publishEvents(int $count): void
    {
        $eventModel = $this->model('Event');
        for ($i = 0; $i < $count; $i++) {
            $id = $this->createEvent("push seam event $i", [
                ['type' => 'ip-dst', 'value' => '10.0.0.' . (100 + $i)],
            ]);
            // createEvent() leaves the event unpublished; push() only offers
            // published events, so flip it without going through publish(),
            // which would try to talk to every configured server.
            $eventModel->save(
                ['Event' => ['id' => $id, 'published' => 1]],
                ['callbacks' => false, 'validate' => false, 'fieldList' => ['id', 'published']]
            );
            $this->eventIds[] = $id;
            $this->eventUuids[] = $eventModel->find('first', [
                'recursive' => -1,
                'conditions' => ['Event.id' => $id],
                'fields' => ['Event.uuid'],
            ])['Event']['uuid'];
        }
    }

    /**
     * The uuids push() offered the remote, restricted to the events this test
     * created. The find inside push() sees every published event on the
     * instance, so an exact comparison would be a fact about the developer's
     * database rather than about the technique dispatch.
     */
    private function offeredUuids(PushSeamServerSync $sync): array
    {
        $this->assertNotEmpty($sync->filterRequests, 'push() never asked the remote to filter anything');
        $offered = array_column(array_column($sync->filterRequests[0], 'Event'), 'uuid');
        return array_values(array_intersect($offered, $this->eventUuids));
    }

    /**
     * SPECIFICATION: 'full' means every published event, regardless of what
     * was pushed before.
     */
    public function testFullOffersEveryPublishedEvent(): void
    {
        $this->requireRedis();
        $this->publishEvents(2);
        $this->setServerField('lastpushedid', $this->eventIds[0]);

        $sync = new PushSeamServerSync($this->remote(['perm_sync' => true]));
        $this->push($sync, 'full');

        $this->assertSame($this->eventUuids, $this->offeredUuids($sync));
    }

    /**
     * SPECIFICATION: 'incremental' resumes from the server's lastpushedid, so
     * an event at or below that watermark is not offered again.
     */
    public function testIncrementalResumesAfterTheLastPushedId(): void
    {
        $this->requireRedis();
        $this->publishEvents(2);
        $this->setServerField('lastpushedid', $this->eventIds[0]);

        $sync = new PushSeamServerSync($this->remote(['perm_sync' => true]));
        $this->push($sync, 'incremental');

        $this->assertSame([$this->eventUuids[1]], $this->offeredUuids($sync));
    }

    /** SPECIFICATION: a numeric technique offers exactly that one event. */
    public function testANumericTechniqueOffersOnlyThatEvent(): void
    {
        $this->requireRedis();
        $this->publishEvents(2);

        $sync = new PushSeamServerSync($this->remote(['perm_sync' => true]));
        $this->push($sync, (string)$this->eventIds[0]);

        $this->assertSame([$this->eventUuids[0]], $this->offeredUuids($sync));
    }

    // -------------------------------------------------- the per-event filters

    /**
     * SPECIFICATION: a push rule excluding the event's tag must remove it
     * before it is even offered to the remote for filtering. This is the
     * security-relevant half of the loop - a rule that fails open leaks an
     * event to a server that was explicitly excluded.
     *
     * (ServerPushRuleFilterTest asserts eventFilterPushableServers() in
     * isolation; this asserts that push() actually applies it.)
     */
    public function testAnEventExcludedByAPushRuleIsNeverOffered(): void
    {
        $this->requireRedis();
        // TWO events: if the only fixture event were excluded the request
        // would be empty, getEventIdsForPush() would return before ever
        // calling the remote, and the assertion below would be about the
        // recorder's database rather than about the rule. The second event
        // guarantees a request exists and makes the assertion selective.
        $this->publishEvents(2);

        $tagId = $this->tagEvent($this->eventIds[0], 'push-seam-blocked');
        $this->setServerField('push_rules', json_encode(['tags' => ['NOT' => [$tagId]]]));

        $sync = new PushSeamServerSync($this->remote(['perm_sync' => true]));
        $this->push($sync, 'full');

        $this->assertSame(
            [$this->eventUuids[1]],
            $this->offeredUuids($sync),
            'the tagged event was offered to a server whose rules exclude it'
        );
    }

    /**
     * CHARACTERIZATION: when the remote filters everything out, push() reports
     * nothing pushed and nothing failed, and does NOT move lastpushedid. The
     * watermark only advances inside the non-empty branch, so a run that
     * offered events but was told to send none leaves it where it was.
     */
    public function testAnEmptyFilterResultLeavesTheWatermarkAlone(): void
    {
        $this->requireRedis();
        $this->publishEvents(1);
        $this->setServerField('lastpushedid', 0);

        $result = $this->push(new PushSeamServerSync($this->remote(['perm_sync' => true])), 'full');

        $this->assertSame([[], []], $result);
        $this->assertSame(
            '0',
            (string)$this->serverRow()['Server']['lastpushedid'],
            'lastpushedid moved even though nothing was pushed'
        );
    }

    private function tagEvent(int $eventId, string $tagName): int
    {
        $tagModel = $this->model('Tag');
        $tagId = $tagModel->captureTag(['name' => $tagName], $this->adminUser());
        $this->assertNotEmpty($tagId, "could not create the tag $tagName");
        $eventTag = $this->model('EventTag');
        $eventTag->create();
        $this->assertNotEmpty(
            $eventTag->save(['EventTag' => ['event_id' => $eventId, 'tag_id' => $tagId]]),
            'could not attach the tag to the event'
        );
        return (int)$tagId;
    }
}

/**
 * ServerSyncTool with the HTTP layer removed.
 *
 * parent::__construct() is deliberately NOT called, so no socket exists and
 * every method the push path touches must be overridden here. Anything not
 * overridden fatals on the missing socket - which is the desired failure mode:
 * a test that unexpectedly reaches the network breaks loudly rather than
 * hanging on a connect timeout.
 */
class PushSeamServerSync extends ServerSyncTool
{
    /** @var array|Throwable the getVersion payload, or what info() should throw */
    private $injectedInfo;

    /** @var array<int,array> every payload passed to filterEventIdsForPush() */
    public $filterRequests = [];

    /** @var array the uuids the remote claims to want; empty means "send nothing" */
    public $acceptUuids = [];

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

    public function filterEventIdsForPush(array $events)
    {
        $this->filterRequests[] = $events;
        return $this->jsonResponse($this->acceptUuids);
    }

    /** Reached via syncProposals(), which push() now hands this same client. */
    public function eventIndex($params = [], $etag = null)
    {
        return $this->jsonResponse([]);
    }

    private function jsonResponse(array $payload): HttpSocketResponseExtended
    {
        $response = new HttpSocketResponseExtended();
        $response->code = 200;
        $response->body = json_encode($payload);
        return $response;
    }

    public function server()
    {
        return ['Server' => [
            'id' => 0,
            'name' => 'fake',
            'internal' => 0,
            'pull_rules' => '',
            'push_sightings' => 0,
            'push_analyst_data' => 0,
        ]];
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
