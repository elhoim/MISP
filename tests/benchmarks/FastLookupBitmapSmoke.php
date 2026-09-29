<?php
/** Bitmap prototype smoke: add, lookup, guard, generation swap. Disposable server only. */
declare(strict_types=1);

putenv('MISP_FASTLOOKUP_FILTER=bitmap');
require_once __DIR__ . '/../../app/Lib/Tools/FastLookupFilter.php';
if (!class_exists('Redis') || empty($argv[1])) {
    throw new RuntimeException('Usage: php FastLookupBitmapSmoke.php /disposable/redis.sock');
}
$redis = new Redis();
$redis->connect($argv[1]);
FastLookupFilter::$bitmapShardBits = 65536;
$namespace = 'bitmap-smoke-' . bin2hex(random_bytes(8));
$prefix = FastLookupFilter::PREFIX . hash('sha256', $namespace) . ':';
$filter = new FastLookupFilter($namespace, ['attribute_types' => ['domain'], 'published_only' => true], $redis);
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
try {
    $assert($filter->moduleState() === 'available', 'The bitmap filter needs no module');
    $assert(FastLookupFilter::bitmapHashes(0.01) === 7 && FastLookupFilter::bitmapBits(100000, 0.01) === 958506, 'Sizing follows the spec');
    $filter->reserve('one', str_repeat('a', 64), 100000, 0.01, 1);
    $shards = $keys($prefix . 'g:one:bm:*');
    $assert(count($shards) === 15, 'A 958,506-bit filter takes fifteen 65,536-bit shards: ' . count($shards));
    foreach ($shards as $key) { $assert($redis->type($key) === Redis::REDIS_STRING, 'Every shard is a string'); }
    $present = [];
    for ($i = 0; $i < 100000; $i += 1000) {
        $rows = [];
        for ($j = $i; $j < $i + 1000; ++$j) { $rows[] = ['id' => (string)($j + 1), 'tokens' => [$present[] = $token('E', 'present-' . $j)]]; }
        $filter->add('one', $rows);
    }
    $filter->checkpoint('r1', false);
    $filter->activate('one', str_repeat('a', 64));
    $filter->checkpoint('r2', true);
    $missing = 0;
    foreach (array_chunk($present, 5000) as $chunk) {
        $plan = array_map(static function ($t) { return [['token' => $t, 'kind' => 'exact']]; }, $chunk);
        foreach ($filter->candidates('one', $plan) as $row) { $missing += $row['exact'] ? 0 : 1; }
    }
    $assert($missing === 0, 'No false negatives across 100,000 tokens');
    $positives = 0;
    for ($i = 0; $i < 20000; $i += 5000) {
        $plan = [];
        for ($j = $i; $j < $i + 5000; ++$j) { $plan[] = [['token' => $token('E', 'absent-' . $j), 'kind' => 'exact']]; }
        foreach ($filter->candidates('one', $plan) as $row) { $positives += $row['exact'] ? 1 : 0; }
    }
    $assert($positives / 20000 <= 0.02, 'False-positive rate within twice the configured 1%: ' . ($positives / 20000));

    $probe = [[['token' => $present[0], 'kind' => 'exact']]];
    $shard = $prefix . 'g:one:bm:3';
    $saved = $redis->dump($shard);
    $redis->del($shard);
    $throws(static function () use ($filter) { $filter->metadata(); }, FastLookupIndexCorruptException::class, 'A missing bitmap shard fails closed');
    $throws(static function () use ($filter, $probe) { $filter->candidates('one', $probe); }, FastLookupIndexUnavailableException::class, 'A missing bitmap shard never answers');
    $redis->set($shard, 'x');
    $throws(static function () use ($filter) { $filter->metadata(); }, FastLookupIndexCorruptException::class, 'A truncated bitmap shard fails closed');
    $throws(static function () use ($filter, $present) { $filter->add('one', [['id' => '9', 'tokens' => [$present[1]]]]); }, FastLookupIndexCorruptException::class, 'add() refuses a truncated shard');
    $redis->del($shard);
    $redis->restore($shard, 0, $saved);
    $assert($filter->candidates('one', $probe)[0]['exact'] === true, 'The restored shard answers');

    $filter->reserve('two', str_repeat('b', 64), 1000, 0.001, 1);
    $fresh = $token('E', 'second-generation');
    $filter->add('two', [['id' => '1', 'tokens' => [$fresh]]]);
    $filter->checkpoint('r3', false);
    $filter->activate('two', str_repeat('b', 64));
    $filter->checkpoint('r4', true);
    $assert(!$keys($prefix . 'g:one:*'), 'Activation removes the previous generation');
    $assert($filter->candidates('two', [[['token' => $fresh, 'kind' => 'exact']]])[0]['exact'] === true, 'The new generation answers');
    $throws(static function () use ($filter, $probe) { $filter->candidates('one', $probe); }, FastLookupIndexUnavailableException::class, 'The old generation no longer answers');

    $server = $redis->info('server');
    echo json_encode(['assertions' => $assertions, 'backend' => isset($server['valkey_version']) ? 'valkey' : 'redis',
        'version' => $server['valkey_version'] ?? $server['redis_version'], 'status' => 'passed'], JSON_PRETTY_PRINT), "\n";
} finally {
    foreach (array_chunk($keys($prefix . '*'), 500) as $batch) { $redis->del($batch); }
}
