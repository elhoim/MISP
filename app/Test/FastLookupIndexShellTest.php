<?php
use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class FastLookupIndexShellTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/fixtures/FastLookupRealFilterStatics.php';
        require_once __DIR__ . '/fixtures/FastLookupLifecycleStubs.php';
        require_once __DIR__ . '/fixtures/FastLookupLifecycleShellStubs.php';
        require_once __DIR__ . '/../Lib/Tools/FastLookupIndexManager.php';
        require_once __DIR__ . '/../Console/Command/AdminShell.php';
    }

    public function testCliRebuildDrainsBackfillAndReportsReady()
    {
        $attribute = new FastLookupLifecycleAttribute();
        $attribute->db->events = ['1' => true, '9' => true];
        $attribute->db->attributes = [
            ['id' => '11', 'event_id' => '1', 'type' => 'domain', 'value1' => 'one.test', 'value2' => '', 'deleted' => false],
            ['id' => '19', 'event_id' => '9', 'type' => 'domain', 'value1' => 'nine.test', 'value2' => '', 'deleted' => false],
        ];
        ClassRegistry::$attribute = $attribute;
        $shell = new AdminShell();
        $shell->MispAttribute = $attribute;
        $shell->Job = new Job();
        $shell->args = ['5', '1'];
        $this->assertTrue(method_exists($shell, 'rebuildFastLookup'), 'The admin shell exposes persistent IOC backfill.');
        $shell->rebuildFastLookup();
        $status = json_decode(end($shell->output), true);
        $this->assertSame('ready', $status['status']);
        $this->assertSame(2, $status['progress']['processed_attributes']);
        $this->assertTrue($shell->Job->success);
    }

    public function testPendingWorkerRequeuesRemainderInsteadOfSilentlyLeavingDirtyEvents()
    {
        $attribute = new FastLookupLifecycleAttribute();
        $attribute->db->events = ['1' => true, '9' => true];
        ClassRegistry::$attribute = $attribute;
        $manager = new FastLookupIndexManager($attribute);
        $manager->startRebuild();
        $manager->runBatch(3);
        FastLookupIndexManager::recordChange($attribute, '1');
        FastLookupIndexManager::recordChange($attribute, '9');
        Configure::$values['MISP.background_jobs'] = true;
        $shell = new AdminShell();
        $shell->MispAttribute = $attribute;
        $shell->Job = new Job();
        $shell->args = ['7', '1'];
        $this->assertTrue(method_exists($shell, 'processFastLookup'), 'The admin shell exposes bounded IOC updates.');
        $shell->processFastLookup();
        $this->assertSame(['processFastLookup', 7, 1], $shell->Job->tool->queued[0][2]);
        $this->assertNull($shell->Job->success);
    }

    private function brokenLiveDuringBuild()
    {
        $attribute = new FastLookupLifecycleAttribute();
        $attribute->db->events = ['1' => true];
        $attribute->db->attributes = [['id' => '11', 'event_id' => '1', 'type' => 'domain', 'value1' => 'one.test', 'value2' => '', 'deleted' => false]];
        ClassRegistry::$attribute = $attribute;
        $manager = new FastLookupIndexManager($attribute);
        $manager->startRebuild();
        $manager->runBatch(1);
        $manager->startRebuild();
        // Redis restored from an older snapshot: the live checkpoint no longer matches.
        $filter = new FastLookupFilter();
        $filter->meta['revision'] = 'restored';
        return $attribute;
    }

    public function testResumeStopsOnErrorWhileBuildIsActive()
    {
        $attribute = $this->brokenLiveDuringBuild();
        $shell = new AdminShell();
        $shell->MispAttribute = $attribute;
        $shell->Job = new Job();
        $shell->args = ['5', '1'];
        try {
            $shell->resumeFastLookup();
            $this->fail('A failing index must fail the job.');
        } catch (RuntimeException $e) {
            $this->assertNotSame('The IOC index loop did not stop.', $e->getMessage());
        }
        $this->assertFalse($shell->Job->success);
        $this->assertLessThan(3, $shell->Job->progress);
    }

    public function testResumeStopsWhenRedisIsUnreachableDuringFirstBuild()
    {
        $attribute = new FastLookupLifecycleAttribute();
        $attribute->db->events = ['1' => true];
        $attribute->db->attributes = [['id' => '11', 'event_id' => '1', 'type' => 'domain', 'value1' => 'one.test', 'value2' => '', 'deleted' => false]];
        ClassRegistry::$attribute = $attribute;
        (new FastLookupIndexManager($attribute))->startRebuild();
        $redis = new FastLookupFilter();
        $redis->available = false;
        $before = $attribute->db->settings;
        $shell = new AdminShell();
        $shell->MispAttribute = $attribute;
        $shell->Job = new Job();
        $shell->args = ['5', '1'];
        try {
            $shell->resumeFastLookup();
            $this->fail('An unreachable Redis must fail the job.');
        } catch (RuntimeException $e) {
            $this->assertNotSame('The IOC index loop did not stop.', $e->getMessage());
        }
        $this->assertFalse($shell->Job->success);
        $this->assertLessThan(3, $shell->Job->progress);
        $this->assertSame($before, $attribute->db->settings, 'No SQL state is written without the lease.');
    }

    private function refusedLease(bool $live)
    {
        $attribute = new FastLookupLifecycleAttribute();
        $attribute->db->events = ['1' => true];
        $attribute->db->attributes = [['id' => '11', 'event_id' => '1', 'type' => 'domain', 'value1' => 'one.test', 'value2' => '', 'deleted' => false]];
        ClassRegistry::$attribute = $attribute;
        $manager = new FastLookupIndexManager($attribute);
        $manager->startRebuild();
        if ($live) {
            $manager->runBatch(1);
            $manager->startRebuild();
            FastLookupIndexManager::recordChange($attribute, '1');
        }
        $redis = new FastLookupFilter();
        $redis->refuseLeaseWrites = true;
        return $attribute;
    }

    /** @dataProvider liveCases */
    public function testResumeStopsWhenRedisRefusesTheLease(bool $live)
    {
        $attribute = $this->refusedLease($live);
        $before = $attribute->db->settings;
        $shell = new AdminShell();
        $shell->MispAttribute = $attribute;
        $shell->Job = new Job();
        $shell->args = ['5', '1'];
        try {
            $shell->resumeFastLookup();
            $this->fail('A refused lease must fail the job.');
        } catch (RuntimeException $e) {
            $this->assertNotSame('The IOC index loop did not stop.', $e->getMessage());
        }
        $this->assertFalse($shell->Job->success);
        $this->assertLessThan(3, $shell->Job->progress);
        $this->assertSame($before, $attribute->db->settings);
    }

    /** @dataProvider liveCases */
    public function testPendingWorkerDoesNotRequeueWhenRedisRefusesTheLease(bool $live)
    {
        $attribute = $this->refusedLease($live);
        Configure::$values['MISP.background_jobs'] = true;
        $before = $attribute->db->settings;
        $shell = new AdminShell();
        $shell->MispAttribute = $attribute;
        $shell->Job = new Job();
        $shell->args = ['7', '1'];
        try {
            $shell->processFastLookup();
            $this->fail('A refused lease must fail the job.');
        } catch (RuntimeException $e) {
        }
        $this->assertSame([], $shell->Job->tool->queued, 'The job never requeues itself behind a refused lease.');
        $this->assertFalse($shell->Job->success);
        $this->assertSame($before, $attribute->db->settings);
    }

    public function liveCases(): array
    {
        return ['first build' => [false], 'rebuild beside a live generation' => [true]];
    }

    /** Runs valkeyMemoryLimit against 30M in-scope attributes and a server limit of $limit. */
    private function memoryLimitShell($limit, bool $apply = false)
    {
        $attribute = new FastLookupLifecycleAttribute();
        $attribute->db->typeCounts = ['domain' => 30000000];
        ClassRegistry::$attribute = $attribute;
        $redis = new FastLookupFilter();
        $redis->memoryLimit = $limit;
        $shell = new AdminShell();
        $shell->MispAttribute = $attribute;
        $shell->params = ['apply' => $apply];
        $this->assertTrue(method_exists($shell, 'valkeyMemoryLimit'), 'The admin shell exposes the Valkey setup step.');
        return [$shell, $redis];
    }

    public function testValkeyMemoryLimitReportsTheRecommendationAndTheCommands()
    {
        [$shell, $redis] = $this->memoryLimitShell(134217728);
        $shell->valkeyMemoryLimit();
        $this->assertSame([
            'In-scope attributes: 30000000',
            'Filter capacity for 150% (45000000 attributes): 135000000 tokens at a false-positive rate of 0.001, about 242621791 bytes (231.4 MiB)',
            'Recommended bf.bloom-memory-usage-limit: 270532608 bytes (258.0 MiB)',
            'Current bf.bloom-memory-usage-limit: 134217728 bytes (128.0 MiB)',
            'The current limit is below the recommendation.',
            'Current MISP.fast_lookup_valkey_shard_bytes: not set (shards of at most 67108864 bytes (64.0 MiB))',
            '',
            '1. Persist the limit on every Valkey node, replicas included, in valkey.conf:',
            '     bf.bloom-memory-usage-limit 270532608',
            '   or as a server argument:',
            '     --bf.bloom-memory-usage-limit 270532608',
            '   then restart the node, or apply it at runtime with:',
            '     CONFIG SET bf.bloom-memory-usage-limit 270532608',
            '2. Only once every node has it persisted, opt in to shards of that size:',
            '     app/Console/cake Admin setSetting MISP.fast_lookup_valkey_shard_bytes 243479347',
            "   A Bloom filter larger than a node's bf.bloom-memory-usage-limit prevents that node from loading its data at start-up.",
            '3. Rebuild the index for fewer shards to take effect:',
            '     app/Console/cake Admin rebuildFastLookup',
        ], $shell->output);
        $this->assertSame([], $redis->memoryLimitSets, 'Nothing changes without --apply.');
    }

    public function testValkeyMemoryLimitShowsTheConfiguredOptIn()
    {
        FastLookupConfig::$valkeyShardBytes = 120795955;
        [$shell] = $this->memoryLimitShell(134217728);
        $shell->valkeyMemoryLimit();
        $this->assertContains('Current MISP.fast_lookup_valkey_shard_bytes: 120795955 bytes (115.2 MiB)', $shell->output);
        $constructed = FastLookupFilter::constructed();
        $this->assertSame(120795955, end($constructed)[4] ?? null, 'The manager hands the opt-in to the filter.');
    }

    public function testValkeyMemoryLimitAppliesTheRecommendationAtRuntime()
    {
        [$shell, $redis] = $this->memoryLimitShell(134217728, true);
        $shell->valkeyMemoryLimit();
        $this->assertSame([270532608], $redis->memoryLimitSets);
        $this->assertSame([
            'Set bf.bloom-memory-usage-limit to 270532608 at runtime only: it does not persist across restarts.',
            'On its own it changes nothing: shards stay at most 67108864 bytes until MISP.fast_lookup_valkey_shard_bytes is set.',
        ], array_slice($shell->output, -2));
        $this->assertNull(FastLookupConfig::$valkeyShardBytes, '--apply never sets the opt-in.');
    }

    public function testValkeyMemoryLimitNeverLowersALargerLimit()
    {
        [$shell, $redis] = $this->memoryLimitShell(536870912, true);
        $shell->valkeyMemoryLimit();
        $this->assertSame([], $redis->memoryLimitSets);
        $this->assertContains('The current limit is sufficient.', $shell->output);
        $this->assertContains('     CONFIG SET bf.bloom-memory-usage-limit 536870912', $shell->output, 'The commands keep the larger limit.');
        $this->assertContains('     bf.bloom-memory-usage-limit 536870912', $shell->output);
        $this->assertSame('Not changed: the current bf.bloom-memory-usage-limit is already at least the recommended value.', end($shell->output));
    }

    public function testValkeyMemoryLimitIsNotNeededOnRedisBloom()
    {
        [$shell, $redis] = $this->memoryLimitShell(null, true);
        $shell->valkeyMemoryLimit();
        $this->assertSame(['This server has no bf.bloom-memory-usage-limit setting (RedisBloom): no setting is needed.'], $shell->output);
        $this->assertSame([], $redis->memoryLimitSets);
    }

    public function testValkeyMemoryLimitRefusesToApplyWhatItCannotRead()
    {
        [$shell, $redis] = $this->memoryLimitShell(false, true);
        try {
            $shell->valkeyMemoryLimit();
            $this->fail('An unreadable limit must fail --apply.');
        } catch (RuntimeException $e) {
            $this->assertSame('The current bf.bloom-memory-usage-limit cannot be read, so it is not changed.', $e->getMessage());
        }
        $this->assertContains('Current bf.bloom-memory-usage-limit: unreadable (CONFIG GET failed or is disabled)', $shell->output);
        $this->assertContains('     CONFIG SET bf.bloom-memory-usage-limit 270532608', $shell->output);
        $this->assertSame([], $redis->memoryLimitSets);
    }

    public function testPendingWorkerDoesNotRequeueOnErrorWhileBuildIsActive()
    {
        $attribute = $this->brokenLiveDuringBuild();
        Configure::$values['MISP.background_jobs'] = true;
        $shell = new AdminShell();
        $shell->MispAttribute = $attribute;
        $shell->Job = new Job();
        $shell->args = ['7', '1'];
        try {
            $shell->processFastLookup();
            $this->fail('A failing index must fail the job.');
        } catch (RuntimeException $e) {
        }
        $this->assertSame([], $shell->Job->tool->queued);
        $this->assertFalse($shell->Job->success);
    }
}
