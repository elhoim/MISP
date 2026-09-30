<?php

/**
 * Pure Bloom filter sizing for fast lookup: bytes per filter, and shard sizes
 * against valkey-bloom's bf.bloom-memory-usage-limit.
 */
class FastLookupSizing
{
    /**
     * The default shard size, half valkey-bloom's default limit. Larger shards
     * need an opt-in: a node whose limit is below a Bloom object's size
     * cannot load its data at start-up.
     */
    const SHARD_BYTES = 67108864;
    const MEMORY_LIMIT_CONFIG = 'bf.bloom-memory-usage-limit';
    /** valkey-bloom's default bf.bloom-memory-usage-limit. */
    const DEFAULT_MEMORY_LIMIT = 134217728;
    /** Share of the server's Bloom object limit one shard may use. */
    const MEMORY_LIMIT_SHARE = 0.9;

    /** RedisBloom sizing: -ln(p)/ln(2)^2 bits per entry. */
    public static function estimatedFilterBytes(int $capacity, float $rate): float
    {
        return max(1, $capacity) * -log($rate) / (log(2) ** 2) / 8;
    }

    public static function shardCount(int $capacity, float $rate, int $shardBytes = self::SHARD_BYTES): int
    {
        return max(1, (int)ceil(self::estimatedFilterBytes($capacity, $rate) / $shardBytes));
    }

    /**
     * SHARD_BYTES, or the opt-in, never above what the live limit accepts. A
     * null limit (RedisBloom, or unreadable) keeps SHARD_BYTES.
     */
    public static function shardBytesFor(?int $memoryLimit, ?int $optIn = null): int
    {
        return $memoryLimit === null ? self::SHARD_BYTES : min($optIn ?? self::SHARD_BYTES, self::limitShardBytes($memoryLimit));
    }

    /** The largest shard a Bloom object limit accepts. */
    public static function limitShardBytes(int $memoryLimit): int
    {
        return max(1, (int)floor(self::MEMORY_LIMIT_SHARE * $memoryLimit));
    }

    /** The smallest limit, in whole MiB and never below the default, whose shard holds the whole filter. */
    public static function recommendedMemoryLimit(int $capacity, float $rate): int
    {
        $mib = 1048576;
        $bytes = (int)ceil(self::estimatedFilterBytes($capacity, $rate) / self::MEMORY_LIMIT_SHARE / $mib) * $mib;
        return max(self::DEFAULT_MEMORY_LIMIT, $bytes);
    }
}
