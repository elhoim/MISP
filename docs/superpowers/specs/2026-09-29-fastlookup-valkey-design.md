# fastLookup on Valkey: dual Bloom backends and sharded filters

Status: approved design (2026-09-29).
- Branch `feature/attributes-fast-lookup-valkey` is stacked on `feature/attributes-fast-lookup-batched`.
- Prototype branch: `spike/fastlookup-bitmap-bloom`.
- Nothing is committed to MISP/MISP#11168 or its stacked PR branch. The new branches are pushed to the fork only, with no PR.

## Goal

1. The fastLookup index runs, with identical results, on both backends:
   - **Redis 8 / Redis Stack** with RedisBloom;
   - **Valkey 8.1+** with `valkey-bloom`, for example the `valkey/valkey-bundle` image.
   - One code path serves both, with no operator configuration.
2. Large instances work on Valkey without raising `bf.bloom-memory-usage-limit`. That limit is 128 MiB per Bloom object by default.
3. A comparison report covers:
   - RedisBloom vs valkey-bloom vs a module-free bitmap Bloom prototype, on build time, memory, and hit/miss/expansion latency;
   - implementation difficulty;
   - the answer to "can the code support both RedisBloom and Valkey".

## Evidence (probe, valkey-bundle 8.1.10, module `bf` version 10001)

- `BF.RESERVE <key> <rate> <capacity> NONSCALING`, `BF.MADD` and `BF.MEXISTS` accept the same syntax and give the same integer replies as RedisBloom.
- Lua `redis.call`/`server.call`, `COMMAND INFO`, `MEMORY USAGE` and `TYPE(...).ok` all work.
- **The `TYPE` of a Bloom key is `bloomfltr`** on Valkey, against `MBbloom--` on RedisBloom. Our fail-closed guard compares that exact string.
- `COMMAND INFO` for an unknown command returns a nil element, as on Redis, so `moduleState()`'s missing-module detection carries over.
- **Size cap:** `bf.bloom-memory-usage-limit = 134217728` (128 MiB).
  - A reserve of capacity 80M at 0.001 fails with `ERR operation exceeds bloom object memory limit`.
  - Capacity 50M works: `MEMORY USAGE` gives 89,860,211 bytes, about 1.797 B per unit of capacity.
  - Our sizing rule is capacity = max(1e6, 3 × attributes). A single filter therefore stops fitting at about 25M in-scope attributes.
- Both modules register the module name `bf`, so `MODULE LIST` cannot tell them apart.

## Design

### 1. Bloom type recorded per generation (`FastLookupFilter`)

- `const BLOOM_TYPES = ['MBbloom--', 'bloomfltr'];` replaces the single `BLOOM_TYPE`.
- **At reserve.** The reserve script creates the first shard, reads `TYPE(shard).ok`, and fails closed (error reply, nothing kept) unless it is in `BLOOM_TYPES`. It then records the type as info field `bloom_type`.
- **Every shard of a generation must have that type.**
- **The guard (`requireGeneration`) now checks:**
  - the sentinel;
  - the `bloom_type` field, which must be in the allowlist;
  - for each shard, `EXISTS` and a `TYPE` equal to `bloom_type`.
  - A missing or unknown type fails with the existing `missing Bloom filter` error, so it maps to Corrupt.
- **Legacy generations** (no `bloom_type`, a single key `g:<gen>:bf`) are checked against the allowlist, so a Redis index built by an earlier version keeps working.

### 2. Sharded filters

- **Shard count.** Info field `shards` = S = max(1, ceil(estimatedBytes / SHARD_BYTES)).
  - `SHARD_BYTES = 67108864` (64 MiB, half Valkey's default cap).
  - `estimatedBytes = capacity × (−ln rate) / (ln 2)² / 8`.
  - Each shard reserves `ceil(capacity / S)` at the same rate, so every shard keeps the configured false-positive rate.
- **Keys:** `g:<gen>:bf:<i>` for i in 0..S−1.
- **A token's shard** = `unpack('N', substr(token, 5, 4))[1] % S`. This uses digest bytes 4–7 (0-based); the posting bucket uses bytes 0–3, so the two are independent.
- **Legacy generations** (no `shards` field) use the single key `g:<gen>:bf`, i.e. S = 1 with the legacy key name.
- **Scripts:**
  - `add()` groups tokens by shard and runs one `BF.MADD` per shard inside the same fenced script.
  - `candidates()` sends the shard index with each token and runs one `BF.MEXISTS` per shard, then reassembles replies in token order.
  - The `inserted` counter keeps counting newly-set tokens across shards.
- **Guard.** It checks every shard. There is at most about 9 shards at 100M attributes.
- **`statistics()`:** `filter_bytes` is the sum of `MEMORY USAGE` over shards. `estimated_false_positive_rate` uses the total capacity and inserted count, which is exact because every shard is sized by the same formula.
- **Schema.** A namespace whose generation is sharded is stamped **`bloom-3`**. `metadata()` and `checkpoint()` accept `bloom-1`, `bloom-2` and `bloom-3`. Code that predates sharding expects `bloom-2` at most, so it fails closed, the same fence pattern as `bloom-2`. New code never writes an older value.
- **Test seam.** `SHARD_BYTES` can be overridden through a constructor argument, used only by tests, to force S > 1 at small scale.

### 3. Harness and tests

- **Redis-free unit tests** (`FastLookupFilterTest`):
  - shard maths: S for boundary capacities, shard capacity, and the shard of a token;
  - the type allowlist;
  - a legacy generation (no `shards`/`bloom_type`);
  - the schema values accepted.
- **Backend-parameterised runs:**
  - `tests/benchmarks/FastLookupIntegration.sh`, the contract runner and the scale runner take `MISP_REDIS_IMAGE` (existing) or a new `MISP_FASTLOOKUP_BACKEND=redis|valkey`, which picks `redis:8.2` or `valkey/valkey-bundle:8.1`.
  - The contract, end-to-end runner and scale benchmark each run on both.
- **Contract additions:**
  - S > 1 via the seam: adds and lookups across shards, and every shard guarded.
  - A deleted or foreign-typed shard fails closed.
  - `bloom_type` is recorded.
  - A foreign `bloom_type` fails closed.
  - Legacy single-key generations are served.
  - On Valkey, a real 30M-capacity reserve (S = 2 at 64 MiB) succeeds where a single filter of 80M would not. The result stays below the default cap.
- **Docs** (`docs/development/fastlookup.md`): supported backends, the shard layout, and the Valkey note (no configuration needed).

### 4. Prototype C: module-free bitmap Bloom (`spike/fastlookup-bitmap-bloom`, throwaway)

- **Filter.** A plain bitmap Bloom filter.
  - m bits and k = round(−log2(rate)) hash functions (10 at 0.001).
  - Bit positions are computed in PHP from the token digest by double hashing: h1 = bytes 0–3, h2 = bytes 4–7 | 1, pos_i = (h1 + i·h2) mod m. The 8-byte digest is enough for positions; this is a prototype.
- **Storage.** Shard bitmaps are keys `g:<gen>:bm:<i>`, at most 64 MiB each (well under the 512 MiB string limit). A position maps to a (shard, offset) pair.
- **Scripts.** Lua receives (shard key index, offset) pairs and uses `SETBIT`/`GETBIT` with the same fencing, sentinels and postings as today. The type check becomes `TYPE == 'string'` plus an expected `STRLEN`.
- **Scope.** Only what the contract smoke (subset: add, lookup, guard, generation swap) and the scale benchmark need. It is never merged.

### 5. Comparison report (controller)

- **Each benchmark run:** `FastLookupScale.php` at 1.7M attributes (17 types × 100k), `FL_RUNS=3`, with CPU accounting.
- **The four configurations benchmarked:**
  - A on Redis 8.2;
  - A on Valkey 8.1 bundle;
  - C on Redis 8.2 (no module);
  - C on Valkey 8.1 (plain image, no module).
- **Metrics:**
  - build time;
  - Redis/Valkey memory per attribute;
  - hits/misses/expansions wall and CPU per type;
  - result digests, which must be identical across all four configurations.
- **Difficulty:**
  - lines changed;
  - new moving parts;
  - operator requirements (modules, configuration, image);
  - failure modes;
  - test surface.
- **Conclusion:** can one code path support RedisBloom and Valkey, and at what cost; is C worth pursuing.
- **Delivery:** an HTML report artifact, plus a summary in chat.

## Out of scope

- Valkey cluster mode, and Redis Cluster. The index uses multi-key Lua scripts, so it needs a single node or hash tags. This is unchanged from #11168; noted in the docs.
- DragonflyDB, KeyDB and Garnet.
- Changing the default backend or the MISP install docs.

## Execution

These tasks run in parallel as sub-agents in separate worktrees, because they touch disjoint files:
- T1: portability and sharding (`FastLookupFilter`, its tests, docs);
- T2: harness parameterised by backend (`tests/benchmarks/*.sh`/`*.php` runner plumbing);
- T3: prototype C (its own branch and worktree).

The controller then merges T2 into the T1 branch, runs the suites on both backends, and runs T4, the benchmarks and report.
