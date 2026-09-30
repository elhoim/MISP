<?php
/** Run against a disposable Redis 8 Unix socket; touches only random test namespaces. */
declare(strict_types=1);

require_once __DIR__ . '/../../app/Lib/Tools/FastLookupFilter.php';
if (!class_exists('Redis') || empty($argv[1])) {
    throw new RuntimeException('Usage: php FastLookupFilterRedisContract.php /disposable/redis.sock (phpredis required)');
}

class FastLookupFilterContractProxy
{
    public $redis;
    public $noMemory = false;
    public $noModule = false;
    /** Renames BF.* calls inside scripts, so real Redis rejects them as unknown commands. */
    public $noBloomCommands = false;
    public $maximumReplyBytes = 0;
    /** Redis cannot be reached at all. */
    public $down = false;
    public $failShardReserve = false;
    /** [KEYS index, type]: the reserve script reads that shard's TYPE as the given one. */
    public $foreignShardType = null;
    /** Runs once before the next EVAL: Redis state changes after PHP read it. */
    public $beforeEval;
    /** method => error message: the next such call fails like a timeout or BUSY reply. */
    public $failNext = [];
    public function __construct($redis) { $this->redis = $redis; }
    public function __call($name, $args)
    {
        $lower = strtolower($name);
        if ($this->down) {
            throw new RedisException('Connection refused');
        }
        if (isset($this->failNext[$lower])) {
            $message = $this->failNext[$lower];
            unset($this->failNext[$lower]);
            throw new RedisException($message);
        }
        if ($lower === 'rawcommand' && $this->noMemory && strtolower($args[0]) === 'memory') {
            throw new RuntimeException('ERR unknown command MEMORY');
        }
        if ($lower === 'rawcommand' && $this->noModule && strtolower($args[0]) === 'command') {
            // What phpredis returns for COMMAND INFO on an unknown command.
            return $this->redis->rawCommand('COMMAND', 'INFO', 'BF.UNLOADEDMEXISTS');
        }
        if ($lower === 'eval' && $this->noBloomCommands) {
            $args[0] = str_replace("'BF.", "'BF.UNLOADED", $args[0]);
        }
        if ($lower === 'eval' && $this->failShardReserve && strpos($args[0], 'BF.RESERVE') !== false) {
            $args[0] = str_replace("redis.pcall('BF.RESERVE', KEYS[i],", "redis.pcall(i == 5 and 'BF.UNLOADEDRESERVE' or 'BF.RESERVE', KEYS[i],", $args[0], $replaced);
            if ($replaced !== 1) { throw new RuntimeException('The reserve script no longer has the shard reserve call.'); }
        }
        if ($lower === 'eval' && $this->foreignShardType && strpos($args[0], 'BF.RESERVE') !== false) {
            [$index, $type] = $this->foreignShardType;
            $args[0] = str_replace("local found = redis.call('TYPE', KEYS[i]).ok", "local found = i == $index and '$type' or redis.call('TYPE', KEYS[i]).ok", $args[0], $replaced);
            if ($replaced !== 1) { throw new RuntimeException('The reserve script no longer reads each shard type.'); }
        }
        if ($lower === 'eval' && $this->beforeEval) {
            $hook = $this->beforeEval;
            $this->beforeEval = null;
            $hook();
        }
        $result = $this->redis->{$name}(...$args);
        if ($lower === 'eval' && isset($result[1]) && is_array($result[1])) {
            $this->maximumReplyBytes = max($this->maximumReplyBytes, array_sum(array_map(static function ($v) { return is_string($v) ? strlen($v) : 0; }, $result[1])));
        }
        return $result;
    }
    public function hScan($key, &$cursor, $pattern = null, $count = 0) { return $this->redis->hScan($key, $cursor, $pattern, $count); }
    public function scan(&$cursor, $pattern = null, $count = 0) { return $this->redis->scan($cursor, $pattern, $count); }
}

$redis = new Redis();
$redis->connect($argv[1]);
$proxy = new FastLookupFilterContractProxy($redis);
$namespace = 'contract-' . bin2hex(random_bytes(16));
$prefix = FastLookupFilter::PREFIX . hash('sha256', $namespace) . ':';
$legacy = FastLookupFilter::LEGACY_PREFIX . hash('sha256', $namespace) . ':';
$shardedNamespace = 'contract-' . bin2hex(random_bytes(16));
$shardedPrefix = FastLookupFilter::PREFIX . hash('sha256', $shardedNamespace) . ':';
$bigNamespace = 'contract-' . bin2hex(random_bytes(16));
$bigPrefix = FastLookupFilter::PREFIX . hash('sha256', $bigNamespace) . ':';
$isValkey = isset($redis->info('server')['valkey_version']);
$scope = ['attribute_types' => ['domain', 'ip-src'], 'published_only' => true];
$filter = new FastLookupFilter($namespace, $scope, $proxy);
$assertions = 0;
$assert = static function ($condition, $message) use (&$assertions) {
    ++$assertions;
    if (!$condition) { throw new RuntimeException($message); }
};
$throws = static function ($call, $class, $message) use ($assert) {
    try { $call(); } catch (Throwable $e) {
        $assert($e instanceof $class, $message . ': got ' . get_class($e) . ' ' . $e->getMessage());
        return;
    }
    $assert(false, $message . ': no exception');
};
$token = static function ($kind, $value) { return $kind . substr(hash('sha256', $value, true), 0, 8); };
$keys = static function ($pattern) use ($redis) {
    $all = []; $cursor = null;
    do { $batch = $redis->scan($cursor, $pattern, 500); if ($batch) { $all = array_merge($all, $batch); } } while ($cursor !== 0);
    return $all;
};
$exact = $token('E', 'example.org');
$domain = $token('D', 'example.org');
$range = $token('I', '192.0.2.0/24');
$query = [7 => [['token' => $exact, 'kind' => 'exact']], 11 => [['token' => $domain, 'kind' => 'domain']],
    13 => [['token' => $range, 'kind' => 'ip_range']], 17 => [['token' => $token('E', 'absent.example'), 'kind' => 'exact']]];

try {
    $assert($filter->moduleAvailable() && $filter->moduleState() === 'available', 'A Bloom module is detected');
    $proxy->noModule = true;
    $assert(!$filter->moduleAvailable() && $filter->moduleState() === 'missing', 'A missing Bloom module is detected');
    $proxy->noModule = false;
    $proxy->down = true;
    $assert(!$filter->moduleAvailable() && $filter->moduleState() === 'unreachable', 'Unreachable Redis is not a missing module');
    $proxy->down = false;
    $throws(static function () use ($filter) { $filter->metadata(); }, FastLookupIndexCorruptException::class, 'Missing index fails closed as corrupt');

    $redis->set($legacy . 'metadata', 'old');
    $filter->reserve('first', str_repeat('a', 64), 1000, 0.001, 128);
    $meta = $filter->metadata();
    $assert($meta['live'] === null && $meta['building'] === 'first' && $meta['ready'] === false, 'Reserve creates a building generation');
    $assert($redis->hGet($prefix . 'metadata', 'schema') === 'bloom-3', 'A reset namespace carries the current schema');
    $assert($meta['generations']['first']['capacity'] === 1000 && $meta['generations']['first']['buckets'] === 2, 'Generation state records sizing');
    $bloomType = $redis->eval("return redis.call('TYPE', KEYS[1]).ok", [$prefix . 'g:first:bf:0'], 1);
    $assert(in_array($bloomType, FastLookupFilter::BLOOM_TYPES, true), 'The filter is a RedisBloom or valkey-bloom filter: ' . $bloomType);
    $assert($redis->hGet($prefix . 'g:first:info', 'bloom_type') === $bloomType, 'The generation records its filter type');
    $throws(static function () use ($filter) { $filter->reserve('first', str_repeat('a', 64), 1000, 0.001, 128); }, FastLookupIndexUnavailableException::class, 'A generation is reserved once');

    $filter->add('first', [
        ['id' => '12', 'type' => 'domain', 'tokens' => [$exact, $domain]],
        ['id' => '13', 'type' => 'domain', 'tokens' => [$exact]],
        ['id' => '14', 'type' => 'ip-src', 'tokens' => [$range]],
    ]);
    $filter->add('first', [['id' => '12', 'type' => 'domain', 'tokens' => [$exact, $domain]]]);
    $assert($filter->metadata()['generations']['first']['inserted'] === 3, 'Re-adding counts only new filter entries');
    $filter->markStale('first', 2);
    $filter->setCursor('first', '14');
    $info = $filter->metadata()['generations']['first'];
    $assert($info['stale'] === 2 && $info['cursor'] === '14', 'Stale count and scan cursor are stored');
    $throws(static function () use ($filter, $query) { $filter->candidates('first', $query); }, FastLookupIndexUnavailableException::class, 'A building generation never answers');

    $filter->checkpoint('r1', false);
    $filter->activate('first', str_repeat('a', 64));
    $filter->checkpoint('r2', true);
    $assert(!$redis->exists($legacy . 'metadata'), 'Activation reclaims the legacy v3 index keys');
    $result = $filter->candidates('first', $query);
    $assert($result[7]['exact'] === true && $result[7]['ip_range'] === [] && $result[7]['domain'] === [], 'Exact tokens report maybe-present without IDs');
    $assert($result[11]['domain'] === ['12'], 'Domain postings are deduplicated');
    $assert($result[13]['ip_range'] === ['14'], 'Range postings keep their kind and position');
    $assert($result[17]['exact'] === false, 'An absent value is excluded by the filter');
    $throws(static function () use ($filter, $query) { $filter->candidates('other', $query); }, FastLookupIndexUnavailableException::class, 'Another generation is refused');
    $throws(static function () use ($filter) { $filter->candidates('first', [[['token' => $GLOBALS['exact'], 'kind' => 'domain']]]); }, InvalidArgumentException::class, 'Token kind mismatch is invalid');

    // No false negatives, and a false-positive rate near the configured one.
    $filter->reserve('second', str_repeat('b', 64), 100000, 0.01, 1);
    $inserted = [];
    for ($i = 0; $i < 100000; $i += 1000) {
        $rows = [];
        for ($j = $i; $j < $i + 1000; ++$j) { $rows[] = ['id' => (string)($j + 1), 'type' => 'domain', 'tokens' => [$inserted[] = $token('E', 'present-' . $j)]]; }
        $filter->add('second', $rows);
    }
    $filter->checkpoint('r3', false);
    $filter->activate('second', str_repeat('b', 64));
    $filter->checkpoint('r4', true);
    $assert(!$keys($prefix . 'g:first:*'), 'Activation removes the previous generation');
    $present = [];
    foreach ($inserted as $i => $t) { $present[$i] = [['token' => $t, 'kind' => 'exact']]; }
    $missing = 0;
    foreach (array_chunk($present, 5000, true) as $chunk) {
        foreach ($filter->candidates('second', $chunk) as $row) { $missing += $row['exact'] ? 0 : 1; }
    }
    $assert($missing === 0, 'No false negatives across 100,000 tokens');
    $absent = [];
    for ($i = 0; $i < 20000; ++$i) { $absent[$i] = [['token' => $token('E', 'absent-' . $i), 'kind' => 'exact']]; }
    $positives = 0;
    foreach (array_chunk($absent, 5000, true) as $chunk) {
        foreach ($filter->candidates('second', $chunk) as $row) { $positives += $row['exact'] ? 1 : 0; }
    }
    $assert($positives / 20000 <= 0.02, 'False-positive rate stays within twice the configured 1%: ' . ($positives / 20000));

    // Long postings overflow, stay listpack-encoded, and fail closed when evicted.
    $filter->reserve('third', str_repeat('c', 64), 1000, 0.001, 1);
    $rows = [];
    for ($id = 1000; $id < 1600; ++$id) { $rows[] = ['id' => (string)$id, 'type' => 'domain', 'tokens' => [$domain]]; }
    $filter->add('third', $rows);
    $filter->checkpoint('r5', false);
    $filter->activate('third', str_repeat('c', 64));
    $filter->checkpoint('r6', true);
    $overflow = array_values(array_filter($keys($prefix . 'g:third:x:*'), static function ($key) { return preg_match('/:x:\d+:[0-9a-f]{18}$/', $key) === 1; }));
    $assert(count($overflow) === 1, 'A long posting moves to one overflow key');
    $assert($redis->object('encoding', $prefix . 'g:third:x:0') === 'listpack', 'The bucket stays listpack-encoded');
    $assert(count($filter->candidates('third', [[['token' => $domain, 'kind' => 'domain']]])[0]['domain']) === 600, 'Overflow postings return every ID');
    $throws(static function () use ($filter, $domain) { $filter->candidates('third', [[['token' => $domain, 'kind' => 'domain']]], 10); }, OverflowException::class, 'The candidate budget never truncates');
    $stored = $redis->get($overflow[0]);
    $redis->del($overflow[0]);
    $throws(static function () use ($filter, $domain) { $filter->candidates('third', [[['token' => $domain, 'kind' => 'domain']]]); }, FastLookupIndexUnavailableException::class, 'An evicted overflow posting fails closed');
    $redis->set($overflow[0], $stored);
    $bucket = $redis->dump($prefix . 'g:third:x:0');
    $redis->del($prefix . 'g:third:x:0');
    $throws(static function () use ($filter, $domain) { $filter->candidates('third', [[['token' => $domain, 'kind' => 'domain']]]); }, FastLookupIndexUnavailableException::class, 'An evicted posting bucket fails closed');
    $throws(static function () use ($filter, $domain) { $filter->add('third', [['id' => '5', 'type' => 'domain', 'tokens' => [$domain]]]); }, FastLookupIndexUnavailableException::class, 'A write to an evicted bucket fails closed');
    $redis->restore($prefix . 'g:third:x:0', 0, $bucket);

    // The worker lease is exclusive, only its owner renews or releases it, and it expires.
    $lease = $prefix . 'worker';
    $assert($filter->acquireLease('owner', 60000), 'A free lease is acquired');
    $assert(!$filter->acquireLease('intruder', 60000), 'A held lease is exclusive');
    $assert(!$filter->renewLease('intruder', 120000) && $redis->pttl($lease) <= 60000, 'Another token cannot renew the lease');
    $filter->releaseLease('intruder');
    $assert($redis->get($lease) === 'owner', 'Another token cannot release the lease');
    $assert($filter->renewLease('owner', 120000) && $redis->pttl($lease) > 60000, 'The owner renews the lease');
    $filter->releaseLease('owner');
    $assert(!$redis->exists($lease), 'The owner releases the lease');
    $assert($filter->acquireLease('short', 200), 'A short lease is acquired');
    $assert($redis->pttl($lease) > 0 && $redis->pttl($lease) <= 200, 'The lease carries its TTL');
    usleep(300000);
    $assert(!$redis->exists($lease), 'The lease expires after its TTL');
    $assert(!$filter->renewLease('short', 60000), 'An expired lease cannot be renewed');
    $assert($filter->acquireLease('next', 60000), 'An expired lease is taken over');
    $filter->releaseLease('short');
    $assert($redis->get($lease) === 'next', "A stale owner's release leaves the new lease alone");
    $throws(static function () use ($filter) { $filter->acquireLease('not a token', 1000); }, InvalidArgumentException::class, 'A malformed lease token is refused');
    $throws(static function () use ($filter) { $filter->acquireLease('owner', 0); }, InvalidArgumentException::class, 'A lease needs a positive TTL');

    // Statistics measure every key the namespace owns; the held lease is not index data.
    $stats = $filter->statistics('third');
    $assert($stats['inserted'] === 1 && $stats['posting_entries'] === 600, 'Statistics count filter entries and posting memberships');
    $actual = 0;
    foreach ($keys($prefix . '*') as $key) {
        $assert(strpos($key, 'example.org') === false, 'Keys never contain IOCs');
        if ($key === $lease) {
            $assert($redis->pttl($key) > 0, 'The worker lease always carries a TTL');
            continue;
        }
        $actual += $redis->rawCommand('MEMORY', 'USAGE', $key, 'SAMPLES', 0);
        $assert($redis->ttl($key) === -1, 'Index keys have no TTL');
    }
    $assert(in_array($lease, $keys($prefix . '*'), true), 'The lease was checked while held');
    $filter->releaseLease('next');
    $assert($stats['shared_memory_bytes'] + $stats['filter_bytes'] + $stats['posting_bytes'] === $actual, 'Statistics include every key: ' . json_encode([$stats, $actual]));
    $redis->hSet($lease, 'corrupt', '1');
    $throws(static function () use ($filter) { $filter->renewLease('next', 1000); }, FastLookupIndexUnavailableException::class, 'A corrupt lease key fails closed');
    $redis->del($lease);
    $proxy->noMemory = true;
    $unmeasured = $filter->statistics('third');
    $assert($unmeasured['filter_bytes'] === null && !empty($unmeasured['memory_unavailable_reason']), 'Unsupported MEMORY is reported, never zero');
    $proxy->noMemory = false;

    // An evicted filter must not turn into "absent" answers or a fresh default filter.
    $redis->del($prefix . 'g:third:bf:0');
    $throws(static function () use ($filter) { $filter->metadata(); }, FastLookupIndexCorruptException::class, 'An evicted filter invalidates metadata');
    $throws(static function () use ($filter, $exact) { $filter->add('third', [['id' => '9', 'type' => 'domain', 'tokens' => [$exact]]]); }, FastLookupIndexUnavailableException::class, 'BF.MADD never recreates an evicted filter');
    $assert(!$redis->exists($prefix . 'g:third:bf:0'), 'No default filter was created');

    // An evicted *building* filter fails only that build; the live one serves.
    $filter->reserve('fifth', str_repeat('e', 64), 1000, 0.001, 1);
    $filter->add('fifth', [['id' => '1', 'type' => 'domain', 'tokens' => [$exact]]]);
    $filter->activate('fifth', str_repeat('e', 64));
    $filter->checkpoint('r5', true);
    $filter->reserve('sixth', str_repeat('f', 64), 1000, 0.001, 1);
    $redis->del($prefix . 'g:sixth:bf:0');
    $meta = $filter->metadata();
    $assert($meta['live'] === 'fifth' && $meta['building'] === 'sixth' && !isset($meta['generations']['sixth']), 'A broken building generation is omitted, not fatal');
    $assert($filter->candidates('fifth', [[['token' => $exact, 'kind' => 'exact']]])[0]['exact'] === true, 'The live filter still answers');
    $proxy->noBloomCommands = true;
    $throws(static function () use ($filter, $exact) { $filter->candidates('fifth', [[['token' => $exact, 'kind' => 'exact']]]); }, FastLookupIndexUnavailableException::class, 'Unavailable BF commands fail closed');
    $proxy->noBloomCommands = false;

    // Either module's filter type is served, and a generation's recorded type is enforced.
    $fifthInfo = $prefix . 'g:fifth:info';
    $fifthFilter = $prefix . 'g:fifth:bf:0';
    $recorded = $redis->hGet($fifthInfo, 'bloom_type');
    $assert(in_array($recorded, FastLookupFilter::BLOOM_TYPES, true), 'The live generation records an allowed type');
    $other = array_values(array_diff(FastLookupFilter::BLOOM_TYPES, [$recorded]))[0];
    $lookupFifth = static function () use ($filter, $exact) {
        return $filter->candidates('fifth', [[['token' => $exact, 'kind' => 'exact']]])[0]['exact'];
    };
    foreach (['the other module' => $other, 'a plain string' => 'string'] as $case => $foreign) {
        $redis->hSet($fifthInfo, 'bloom_type', $foreign);
        $throws(static function () use ($filter) { $filter->metadata(); }, FastLookupIndexCorruptException::class, "A recorded type of $case fails closed");
        $throws($lookupFifth, FastLookupIndexUnavailableException::class, "A recorded type of $case never answers");
    }
    $redis->hSet($fifthInfo, 'bloom_type', $recorded);
    $saved = $redis->dump($fifthFilter);
    $redis->del($fifthFilter);
    $redis->set($fifthFilter, 'x');
    $throws(static function () use ($filter) { $filter->metadata(); }, FastLookupIndexCorruptException::class, 'A string in place of the filter fails closed');
    $throws(static function () use ($filter, $exact) { $filter->add('fifth', [['id' => '2', 'type' => 'domain', 'tokens' => [$exact]]]); }, FastLookupIndexCorruptException::class, 'add() refuses a string in place of the filter');
    $redis->del($fifthFilter);
    $redis->restore($fifthFilter, 0, $saved);
    $redis->hDel($fifthInfo, 'shards');
    $throws(static function () use ($filter) { $filter->metadata(); }, FastLookupIndexCorruptException::class, 'A type without a shard count fails closed');
    // What an earlier release leaves behind: one unsharded filter and no layout fields.
    $redis->rename($fifthFilter, $prefix . 'g:fifth:bf');
    $redis->hDel($fifthInfo, 'bloom_type');
    $assert($filter->metadata()['generations']['fifth']['shards'] === null, 'A generation built before sharding reads as unsharded');
    $assert($lookupFifth() === true, 'A generation built before sharding is served');
    $legacyToken = $token('E', 'legacy-add.example');
    $filter->add('fifth', [['id' => '3', 'type' => 'domain', 'tokens' => [$legacyToken]]]);
    $assert($filter->candidates('fifth', [[['token' => $legacyToken, 'kind' => 'exact']]])[0]['exact'] === true, 'A generation built before sharding still takes new tokens');

    // A transient Redis error during reserve() never resets the namespace or deletes the live generation.
    $transient = static function ($method, $message) use ($filter, $proxy, $redis, $prefix, $assert, $exact, $keys) {
        $proxy->failNext = [$method => $message];
        try {
            $filter->reserve('seventh', str_repeat('g', 64), 1000, 0.001, 1);
            $assert(false, "reserve() failed to fail on $message");
        } catch (FastLookupIndexUnavailableException $e) {
            $assert(!$e instanceof FastLookupIndexCorruptException, "$message is not corruption");
        }
        $proxy->failNext = [];
        $assert($redis->exists($prefix . 'g:fifth:bf') === 1 && $redis->exists($prefix . 'g:fifth:info') === 1, "$message leaves the live generation's keys");
        $assert(!$keys($prefix . 'g:seventh:*'), "$message reserves nothing");
        $meta = $filter->metadata();
        $assert($meta['live'] === 'fifth' && $meta['ready'] === true, "$message leaves the live metadata");
        $assert($filter->candidates('fifth', [[['token' => $exact, 'kind' => 'exact']]])[0]['exact'] === true, "$message: the live filter still answers");
    };
    $transient('hgetall', 'read error on connection');
    $transient('eval', 'BUSY Redis is busy running a script. You can only call SCRIPT KILL or SHUTDOWN NOSCRIPT.');

    // Posting caps fail explicitly.
    $filter->reserve('fourth', str_repeat('d', 64), 1000, 0.001, 1);
    $redis->set($prefix . 'g:fourth:x:0:' . bin2hex($domain), 'fourth|' . implode(',', range(1, 500000)) . ',');
    $redis->hSet($prefix . 'g:fourth:x:0', $domain, '*');
    $throws(static function () use ($filter, $domain) { $filter->add('fourth', [['id' => '999999999', 'type' => 'domain', 'tokens' => [$domain]]]); }, OverflowException::class, 'The posting cap is an explicit resource failure');

    // IP prefix masks: set before their tokens become visible, versioned, legacy generations left alone.
    $info = $prefix . 'g:eighth:info';
    $bf = $prefix . 'g:eighth:bf:0';
    $redis->hSet($prefix . 'metadata', 'schema', 'bloom-1');
    $assert($filter->metadata()['live'] !== null, 'A legacy schema namespace is served');
    $filter->reserve('eighth', str_repeat('h', 64), 1000, 0.001, 1);
    $assert($redis->hMGet($prefix . 'metadata', ['schema', 'building']) === ['schema' => 'bloom-3', 'building' => 'eighth'], 'Reserving a masked generation stamps the current schema');
    $assert($filter->prefixLengths('eighth') === ['version' => '0', 'lengths' => [4 => [], 6 => []]], 'A new generation starts with empty masks at version 0');
    $r24 = $token('I', '198.51.100.0/24');
    $filter->add('eighth', [['id' => '21', 'type' => 'ip-src', 'tokens' => [$r24], 'networks' => [[4, 24]]]]);
    $assert($redis->hGet($info, 'p4')[24] === '1' && $filter->prefixLengths('eighth')['version'] === '1', 'A new length sets its bit and bumps the version');
    $assert($redis->rawCommand('BF.MEXISTS', $bf, $r24) === [1] && isset($filter->prefixLengths('eighth')['lengths'][4][24]), 'A visible range token has its length in the mask');
    $filter->add('eighth', [['id' => '22', 'type' => 'ip-src', 'tokens' => [$r24], 'networks' => [[4, 24]]]]);
    $assert($filter->prefixLengths('eighth')['version'] === '1', 'A known length leaves the version alone');
    $filter->add('eighth', [['id' => '23', 'type' => 'ip-src', 'tokens' => [$token('E', '198.51.100.7')]]]);
    $assert($filter->prefixLengths('eighth')['version'] === '1', 'Rows without networks leave the version alone');
    $filter->checkpoint('r7', false);
    $filter->activate('eighth', str_repeat('h', 64));
    $filter->checkpoint('r8', true);
    $rangeQuery = [[['token' => $r24, 'kind' => 'ip_range']]];
    $assert($filter->candidates('eighth', $rangeQuery, 100000, '1')[0]['ip_range'] === ['21', '22'], 'A current prefix version answers');
    $r32 = $token('I', '198.51.100.7/32');
    $r64 = $token('I', '2001:db8::/64');
    $filter->add('eighth', [['id' => '24', 'type' => 'ip-src', 'tokens' => [$r32, $r64], 'networks' => [[4, 32], [6, 64]]]]);
    $lengths = $filter->prefixLengths('eighth');
    $assert($lengths === ['version' => '2', 'lengths' => [4 => [24 => true, 32 => true], 6 => [64 => true]]], 'New lengths in both families bump the version once: ' . json_encode($lengths));
    $assert($redis->rawCommand('BF.MEXISTS', $bf, $r32, $r64) === [1, 1], 'The new range tokens are visible with their lengths set');
    $throws(static function () use ($filter, $rangeQuery) { $filter->candidates('eighth', $rangeQuery, 100000, '1'); }, FastLookupPrefixesChangedException::class, 'A stale prefix version fails the lookup');
    $throws(static function () use ($filter, $rangeQuery) { $filter->candidates('eighth', $rangeQuery, 100000, ''); }, FastLookupPrefixesChangedException::class, 'A legacy expectation fails on a masked generation');
    $assert($filter->candidates('eighth', $rangeQuery, 100000, '2')[0]['ip_range'] === ['21', '22'], 'The current prefix version answers');
    $assert($filter->candidates('eighth', $rangeQuery)[0]['ip_range'] === ['21', '22'], 'No prefix version skips the check');

    $masks = $redis->hMGet($info, ['p4', 'p6', 'pv']);
    $redis->hDel($info, 'p4', 'p6', 'pv');
    $r13 = $token('I', '192.0.0.0/13');
    $filter->add('eighth', [['id' => '25', 'type' => 'ip-src', 'tokens' => [$r13], 'networks' => [[4, 13]]]]);
    $assert($redis->hMGet($info, ['p4', 'p6', 'pv']) === ['p4' => false, 'p6' => false, 'pv' => false], 'add() never creates masks on a legacy generation');
    $assert($filter->prefixLengths('eighth') === ['version' => '', 'lengths' => null], 'A legacy generation has no lengths');
    $assert($filter->candidates('eighth', [[['token' => $r13, 'kind' => 'ip_range']]], 100000, '')[0]['ip_range'] === ['25'], 'A legacy generation matches an empty prefix version');
    // What an older release leaves behind: a legacy schema over an unmasked live generation.
    $redis->hSet($prefix . 'metadata', 'schema', 'bloom-1');
    $filter->checkpoint('r9', true);
    $assert($filter->metadata()['generations']['eighth']['capacity'] === 1000, 'A legacy namespace with a legacy live generation is valid');
    $assert($filter->candidates('eighth', [[['token' => $r13, 'kind' => 'ip_range']]], 100000, '')[0]['ip_range'] === ['25'], 'A legacy namespace keeps serving range lookups');
    $assert($redis->hGet($prefix . 'metadata', 'schema') === 'bloom-1', 'Serving never rewrites the schema');
    $redis->hSet($prefix . 'metadata', 'schema', 'bloom-4');
    $throws(static function () use ($filter) { $filter->metadata(); }, FastLookupIndexCorruptException::class, 'An unknown schema fails closed');
    $throws(static function () use ($filter) { $filter->checkpoint('r10', true); }, FastLookupIndexUnavailableException::class, 'checkpoint() refuses an unknown schema');
    $redis->hSet($prefix . 'metadata', 'schema', 'bloom-3');

    $redis->hMSet($info, $masks);
    $redis->hDel($info, 'p6');
    $throws(static function () use ($filter) { $filter->prefixLengths('eighth'); }, FastLookupIndexCorruptException::class, 'A partial prefix state is corrupt');
    $throws(static function () use ($filter) { $filter->metadata(); }, FastLookupIndexCorruptException::class, 'A live generation with a partial prefix state is corrupt');
    $r16 = $token('I', '203.0.0.0/16');
    $throws(static function () use ($filter, $r16) { $filter->add('eighth', [['id' => '26', 'type' => 'ip-src', 'tokens' => [$r16], 'networks' => [[4, 16]]]]); }, FastLookupIndexCorruptException::class, 'add() fails closed on a partial prefix state');
    $redis->hMSet($info, $masks);
    $redis->hSet($info, 'p4', str_repeat('0', 32));
    $throws(static function () use ($filter) { $filter->metadata(); }, FastLookupIndexCorruptException::class, 'A live generation with a malformed IPv4 mask is corrupt');
    $throws(static function () use ($filter, $r16) { $filter->add('eighth', [['id' => '26', 'type' => 'ip-src', 'tokens' => [$r16], 'networks' => [[4, 16]]]]); }, FastLookupIndexCorruptException::class, 'add() reports a malformed IPv4 mask as corrupt');
    $assert($redis->rawCommand('BF.MEXISTS', $bf, $r16) === [0], 'A refused mask update publishes none of its tokens');
    $redis->hMSet($info, $masks);
    foreach (['missing' => null, 'non-decimal' => 'x'] as $case => $version) {
        $redis->hMSet($info, $masks);
        if ($version === null) { $redis->hDel($info, 'pv'); } else { $redis->hSet($info, 'pv', $version); }
        $throws(static function () use ($filter, $r16) { $filter->add('eighth', [['id' => '26', 'type' => 'ip-src', 'tokens' => [$r16], 'networks' => [[4, 16]]]]); }, FastLookupIndexCorruptException::class, "add() fails closed on a $case prefix version");
        $after = $redis->hMGet($info, ['p4', 'p6', 'pv']);
        $assert($after['p4'] === $masks['p4'] && $after['p6'] === $masks['p6'] && $after['pv'] === ($version ?? false), "A $case prefix version leaves the masks and version untouched");
        $assert($redis->rawCommand('BF.MEXISTS', $bf, $r16) === [0], "A $case prefix version publishes none of the tokens");
    }
    $throws(static function () use ($filter) { $filter->prefixLengths('ninth'); }, FastLookupIndexCorruptException::class, 'A missing generation has no prefix state');

    // Sharded filters: each token lives in one shard, and every shard is guarded.
    $sharded = new FastLookupFilter($shardedNamespace, $scope, $proxy, 16384);
    $assert(FastLookupSizing::shardCount(100000, 0.01, 16384) === 8, 'The test shard size gives eight shards');
    $sharded->reserve('s1', str_repeat('s', 64), 100000, 0.01, 1);
    $assert($redis->hGet($shardedPrefix . 'metadata', 'schema') === 'bloom-3', 'A sharded namespace carries the current schema');
    $assert($sharded->metadata()['generations']['s1']['shards'] === 8, 'The generation records its shard count');
    $shardKeys = [];
    for ($i = 0; $i < 8; ++$i) { $shardKeys[] = $shardedPrefix . 'g:s1:bf:' . $i; }
    $assert(count($keys($shardedPrefix . 'g:s1:bf*')) === 8 && !$redis->exists($shardedPrefix . 'g:s1:bf'), 'Eight shard keys and no unsharded filter');
    $shardTypes = array_unique(array_map(static function ($key) use ($redis) {
        return $redis->eval("return redis.call('TYPE', KEYS[1]).ok", [$key], 1);
    }, $shardKeys));
    $assert($shardTypes === [$redis->hGet($shardedPrefix . 'g:s1:info', 'bloom_type')], 'Every shard has the recorded type');
    $shardedTokens = [];
    // 80%, where the manager schedules a rebuild: a shard's share varies, so a full load could fill one.
    for ($i = 0; $i < 80000; $i += 1000) {
        $rows = [];
        for ($j = $i; $j < $i + 1000; ++$j) {
            $rows[] = ['id' => (string)($j + 1), 'type' => 'domain', 'tokens' => [$shardedTokens[] = $token('E', 'sharded-' . $j)]];
        }
        $sharded->add('s1', $rows);
    }
    $inserted = $sharded->metadata()['generations']['s1']['inserted'];
    $assert($inserted >= 78000 && $inserted <= 80000, "Inserted counts new tokens across shards: $inserted");
    $firstInShard = [];
    foreach ($shardedTokens as $t) { $firstInShard[FastLookupFilter::shardOf($t, 8)] = $firstInShard[FastLookupFilter::shardOf($t, 8)] ?? $t; }
    ksort($firstInShard);
    $assert(array_keys($firstInShard) === range(0, 7), 'Tokens spread over every shard');
    foreach ($firstInShard as $i => $t) {
        $assert($redis->rawCommand('BF.EXISTS', $shardKeys[$i], $t) === 1, "Shard $i holds its tokens");
    }
    $sharded->checkpoint('s-r1', false);
    $sharded->activate('s1', str_repeat('s', 64));
    $sharded->checkpoint('s-r2', true);
    $missing = 0;
    foreach (array_chunk($shardedTokens, 5000) as $chunk) {
        $plan = array_map(static function ($t) { return [['token' => $t, 'kind' => 'exact']]; }, $chunk);
        foreach ($sharded->candidates('s1', $plan) as $row) { $missing += $row['exact'] ? 0 : 1; }
    }
    $assert($missing === 0, 'No false negatives across 80,000 sharded tokens: ' . $missing);
    $positives = 0;
    for ($i = 0; $i < 20000; $i += 5000) {
        $plan = [];
        for ($j = $i; $j < $i + 5000; ++$j) { $plan[] = [['token' => $token('E', 'sharded-absent-' . $j), 'kind' => 'exact']]; }
        foreach ($sharded->candidates('s1', $plan) as $row) { $positives += $row['exact'] ? 1 : 0; }
    }
    $assert($positives / 20000 <= 0.02, 'Sharded false-positive rate stays within twice the configured 1%: ' . ($positives / 20000));
    $filterBytes = array_sum(array_map(static function ($key) use ($redis) {
        return $redis->rawCommand('MEMORY', 'USAGE', $key, 'SAMPLES', 0);
    }, $shardKeys));
    $assert($sharded->statistics('s1')['filter_bytes'] === $filterBytes, 'filter_bytes sums every shard');

    $probe = [[['token' => $shardedTokens[0], 'kind' => 'exact']]];
    foreach ($shardKeys as $i => $shardKey) {
        $saved = $redis->dump($shardKey);
        $redis->del($shardKey);
        $throws(static function () use ($sharded) { $sharded->metadata(); }, FastLookupIndexCorruptException::class, "A missing shard $i fails closed");
        $throws(static function () use ($sharded, $probe) { $sharded->candidates('s1', $probe); }, FastLookupIndexUnavailableException::class, "A missing shard $i never answers");
        $redis->set($shardKey, 'x');
        $throws(static function () use ($sharded) { $sharded->metadata(); }, FastLookupIndexCorruptException::class, "A string in place of shard $i fails closed");
        $redis->del($shardKey);
        $redis->restore($shardKey, 0, $saved);
    }
    $assert($sharded->candidates('s1', $probe)[0]['exact'] === true, 'Restored shards answer again');
    $shardInfo = $shardedPrefix . 'g:s1:info';
    $redis->hSet($shardInfo, 'shards', '9');
    $throws(static function () use ($sharded) { $sharded->metadata(); }, FastLookupIndexCorruptException::class, 'A shard count above the shards present fails closed');
    $redis->hSet($shardInfo, 'shards', '8');
    foreach (['shards', 'bloom_type'] as $field) {
        $value = $redis->hGet($shardInfo, $field);
        $redis->hDel($shardInfo, $field);
        $throws(static function () use ($sharded) { $sharded->metadata(); }, FastLookupIndexCorruptException::class, "A layout without $field fails closed");
        $redis->hSet($shardInfo, $field, $value);
    }
    $assert($sharded->candidates('s1', $probe)[0]['exact'] === true, 'The restored layout answers again');
    // The same half layouts, written after PHP read a valid one: the Lua guard refuses them itself.
    $layout = $redis->hMGet($shardInfo, ['shards', 'bloom_type']);
    foreach (['without shards' => [['shards'], []], 'without bloom_type' => [['bloom_type'], []], 'with zero shards' => [[], ['shards' => '0']]] as $case => [$drop, $set]) {
        $proxy->beforeEval = static function () use ($redis, $shardInfo, $drop, $set) {
            if ($drop) { $redis->hDel($shardInfo, ...$drop); }
            if ($set) { $redis->hMSet($shardInfo, $set); }
        };
        try {
            $sharded->metadata();
            $assert(false, "The guard must refuse a layout $case");
        } catch (FastLookupIndexCorruptException $e) {
            $assert($e->getMessage() === 'A fastLookup generation is missing.', "The Lua guard refuses a layout $case: " . $e->getMessage());
        }
        $assert($proxy->beforeEval === null, "The layout $case changed between the read and the script");
        $redis->hMSet($shardInfo, $layout);
    }
    $assert($sharded->candidates('s1', $probe)[0]['exact'] === true, 'The layout restored after the guard cases answers again');

    $proxy->failShardReserve = true;
    try {
        $sharded->reserve('s2', str_repeat('t', 64), 100000, 0.01, 1);
        $assert(false, 'A reserve failing on its third shard must fail');
    } catch (FastLookupIndexUnavailableException $e) {
        $assert(!$e instanceof FastLookupIndexCorruptException, 'A failed shard reserve is not corruption');
    }
    $proxy->failShardReserve = false;
    $assert(!$keys($shardedPrefix . 'g:s2:*'), 'A reserve failing part-way keeps no shard');
    $meta = $sharded->metadata();
    $assert($meta['live'] === 's1' && $meta['building'] === null, 'A failed reserve leaves the live generation and records no build');
    $otherType = array_values(array_diff(FastLookupFilter::BLOOM_TYPES, [$redis->hGet($shardInfo, 'bloom_type')]))[0];
    foreach (['a foreign type on the first shard' => [3, 'string'], "the other module's type on a later shard" => [6, $otherType]] as $case => $foreign) {
        $proxy->foreignShardType = $foreign;
        try {
            $sharded->reserve('s2', str_repeat('t', 64), 100000, 0.01, 1);
            $assert(false, "A reserve reading $case must fail");
        } catch (FastLookupIndexUnavailableException $e) {
            $assert(!$e instanceof FastLookupIndexCorruptException, "A reserve refusing $case is not corruption");
        }
        $proxy->foreignShardType = null;
        $assert(!$keys($shardedPrefix . 'g:s2:*'), "A reserve refusing $case keeps no shard");
        $meta = $sharded->metadata();
        $assert($meta['live'] === 's1' && $meta['building'] === null, "A reserve refusing $case records no build");
    }

    // A full NONSCALING shard refuses tokens: the add fails as full, and nothing it took before reads absent.
    $tiny = new FastLookupFilter($shardedNamespace, $scope, $proxy, 8);
    $tiny->reserve('full', str_repeat('u', 64), 40, 0.01, 1);
    $fullShards = $tiny->metadata()['generations']['full']['shards'];
    $assert($fullShards > 1, "The tiny filter is sharded: $fullShards");
    $accepted = [];
    $full = null;
    for ($i = 0; $i < 1000 && $full === null; ++$i) {
        $t = $token('E', 'full-' . $i);
        try {
            $tiny->add('full', [['id' => (string)($i + 1), 'type' => 'domain', 'tokens' => [$t]]]);
            $accepted[] = $t;
        } catch (FastLookupIndexUnavailableException $e) {
            $full = $e;
        }
    }
    $assert($full instanceof FastLookupIndexFullException && $full->generation === 'full', 'A full shard fails the add as full: ' . ($full ? get_class($full) . ' ' . $full->getMessage() : 'never full'));
    $assert(!$full instanceof FastLookupIndexCorruptException, 'A full filter is not corruption');
    $fullInserted = $tiny->metadata()['generations']['full']['inserted'];
    $assert($fullInserted >= 1 && $fullInserted <= count($accepted) + 1, "The tokens stored before the refusal are counted: $fullInserted");
    $tiny->checkpoint('full-r1', false);
    $tiny->activate('full', str_repeat('u', 64));
    $tiny->checkpoint('full-r2', true);
    $plan = array_map(static function ($t) { return [['token' => $t, 'kind' => 'exact']]; }, $accepted);
    $absent = array_filter($tiny->candidates('full', $plan), static function ($row) { return !$row['exact']; });
    $assert(count($accepted) > 0 && !$absent, 'No token taken before the refusal reads absent: ' . count($absent) . ' of ' . count($accepted));

    // A legacy filter an earlier release filled may have dropped tokens silently: at its capacity it never answers.
    $sharded->reserve('legacyfull', str_repeat('v', 64), 1000, 0.01, 1);
    $legacyInfo = $shardedPrefix . 'g:legacyfull:info';
    $redis->rename($shardedPrefix . 'g:legacyfull:bf:0', $shardedPrefix . 'g:legacyfull:bf');
    $redis->hDel($legacyInfo, 'shards', 'bloom_type');
    $legacyProbe = [[['token' => $token('E', 'legacy-full'), 'kind' => 'exact']]];
    $sharded->add('legacyfull', [['id' => '1', 'type' => 'domain', 'tokens' => [$legacyProbe[0][0]['token']]]]);
    $sharded->checkpoint('lf-r1', false);
    $sharded->activate('legacyfull', str_repeat('v', 64));
    $sharded->checkpoint('lf-r2', true);
    $assert($sharded->metadata()['generations']['legacyfull']['shards'] === null, 'The generation is a legacy single filter');
    $assert($sharded->candidates('legacyfull', $legacyProbe)[0]['exact'] === true, 'A legacy filter below its capacity answers');
    $redis->hSet($legacyInfo, 'inserted', '1000');
    try {
        $sharded->candidates('legacyfull', $legacyProbe);
        $assert(false, 'A legacy filter at its capacity must never answer');
    } catch (FastLookupIndexFullException $e) {
        $assert($e->generation === 'legacyfull', 'A legacy filter at its capacity fails the lookup as full');
    }

    // Shards stay at most 64 MiB and follow valkey-bloom's object limit downward;
    // larger ones need the opt-in, capped by the live limit. RedisBloom has no limit.
    $big = new FastLookupFilter($bigNamespace, $scope, $proxy);
    $limitSetting = FastLookupSizing::MEMORY_LIMIT_CONFIG;
    $bigShards = static function (string $generation, int $capacity, ?int $optIn = null) use ($bigNamespace, $scope, $proxy, $redis, $bigPrefix) {
        $filter = new FastLookupFilter($bigNamespace, $scope, $proxy, null, $optIn);
        $filter->reserve($generation, str_repeat('b', 64), $capacity, 0.001, 1);
        $shards = $filter->metadata()['generations'][$generation]['shards'];
        $bytes = [];
        for ($i = 0; $i < $shards; ++$i) {
            $bytes[] = $redis->rawCommand('MEMORY', 'USAGE', $bigPrefix . 'g:' . $generation . ':bf:' . $i, 'SAMPLES', 0);
        }
        return $bytes;
    };
    $refusedAlone = static function (int $capacity) use ($redis, $bigPrefix) {
        try {
            $single = $redis->rawCommand('BF.RESERVE', $bigPrefix . 'single', '0.001', (string)$capacity, 'NONSCALING');
            $error = (string)$redis->getLastError();
        } catch (RedisException $e) {
            $single = false;
            $error = $e->getMessage();
        }
        $redis->clearLastError();
        $redis->del($bigPrefix . 'single');
        return $single === false && strpos($error, 'exceeds bloom object memory limit') !== false ? true : $error;
    };
    $below = static function (array $bytes, int $limit) {
        foreach ($bytes as $b) { if (!is_int($b) || $b <= 0 || $b >= $limit) { return false; } }
        return true;
    };
    $shard64 = FastLookupSizing::SHARD_BYTES + 4096;
    if (!$isValkey) {
        $assert($big->memoryLimit() === null, 'RedisBloom has no Bloom object limit');
        $bytes = $bigShards('big', 40000000);
        $assert(count($bytes) === 2 && $below($bytes, $shard64), 'A 40M-token filter takes two 64 MiB shards: ' . json_encode($bytes));
        $bytes = $bigShards('bigopt', 40000000, 241591910);
        $assert(count($bytes) === 2 && $below($bytes, $shard64), 'The opt-in is ignored on RedisBloom: ' . json_encode($bytes));
    } else {
        $originalLimit = $redis->rawCommand('CONFIG', 'GET', $limitSetting)[1];
        try {
            $assert($big->memoryLimit() === (int)$originalLimit, 'The server limit reads back: ' . $originalLimit);
            $big->setMemoryLimit(134217728);
            $bytes = $bigShards('big', 40000000);
            $assert(count($bytes) === 2 && $below($bytes, $shard64), 'Under the default limit a 40M-token filter takes two 64 MiB shards: ' . json_encode($bytes));
            $assert($refusedAlone(80000000) === true, 'One 80M-token filter exceeds the default limit');

            $big->setMemoryLimit(268435456);
            $assert($redis->rawCommand('CONFIG', 'GET', $limitSetting)[1] === '268435456', 'setMemoryLimit raises the limit at runtime');
            $estimate = FastLookupSizing::estimatedFilterBytes(60000000, 0.001);
            $assert($estimate > 67108864 && $estimate < 0.9 * 268435456, 'The 60M-token estimate lies between 64 MiB and 90% of 256 MiB');
            $bytes = $bigShards('big60', 60000000);
            $assert(count($bytes) >= 2 && $below($bytes, $shard64), 'Without the opt-in a raised limit keeps 64 MiB shards: ' . json_encode($bytes));
            $bytes = $bigShards('big60opt', 60000000, FastLookupSizing::limitShardBytes(268435456));
            $assert(count($bytes) === 1 && $bytes[0] > 67108864 && $bytes[0] < 268435456, 'With the opt-in, under 256 MiB, a 60M-token filter is one shard: ' . json_encode($bytes));

            $big->setMemoryLimit(33554432);
            $assert($refusedAlone(20000000) === true, 'One 20M-token filter exceeds a 32 MiB limit');
            $bytes = $bigShards('small10', 10000000);
            $assert(count($bytes) === 1 && $below($bytes, 33554432), 'Under 32 MiB a 10M-token filter is one shard: ' . json_encode($bytes));
            $bytes = $bigShards('small20', 20000000);
            $assert(count($bytes) >= 2 && $below($bytes, 33554432), 'Under 32 MiB a 20M-token filter takes shards under 32 MiB: ' . json_encode($bytes));
            $bytes = $bigShards('small20opt', 20000000, FastLookupSizing::limitShardBytes(268435456));
            $assert(count($bytes) >= 2 && $below($bytes, 33554432), 'The opt-in never exceeds the live limit: ' . json_encode($bytes));

            $recommended = FastLookupSizing::recommendedMemoryLimit(80000000, 0.001);
            $big->setMemoryLimit($recommended);
            $bytes = $bigShards('recommended', 80000000, FastLookupSizing::limitShardBytes($recommended));
            $assert(count($bytes) === 1 && $below($bytes, $recommended), "The recommended $recommended-byte limit and opt-in hold 80M tokens in one shard: " . json_encode($bytes));
        } finally {
            $redis->rawCommand('CONFIG', 'SET', $limitSetting, $originalLimit);
        }
        $assert($redis->rawCommand('CONFIG', 'GET', $limitSetting)[1] === $originalLimit, 'The original limit is restored');
    }
    foreach (array_chunk($keys($bigPrefix . '*'), 500) as $batch) { $redis->del($batch); }

    $server = $redis->info('server');
    echo json_encode(['assertions' => $assertions, 'backend' => isset($server['valkey_version']) ? 'valkey' : 'redis',
        'version' => $server['valkey_version'] ?? $server['redis_version'], 'status' => 'passed'], JSON_PRETTY_PRINT), "\n";
} finally {
    foreach (array_chunk(array_merge($keys($prefix . '*'), $keys($legacy . '*'), $keys($shardedPrefix . '*'), $keys($bigPrefix . '*')), 500) as $batch) { $redis->del($batch); }
}
