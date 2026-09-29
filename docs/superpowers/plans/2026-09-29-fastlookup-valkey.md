# fastLookup on Valkey: Dual Bloom Backends and Sharded Filters Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Serve the fastLookup Bloom index from RedisBloom and from valkey-bloom through one code path, shard filters so large instances stay under Valkey's 128 MiB object limit, and measure both against a module-free bitmap prototype.

**Architecture:** `FastLookupFilter` records each generation's Bloom `TYPE` (`bloom_type`) and shard count (`shards`) in its info hash. PHP reads that layout with one `HMGET`, passes every shard key to the Lua scripts, and a single guard (`requireGeneration`) checks the sentinel, the layout, each shard's name, existence and type. Tokens are routed to a shard by digest bytes 4–7. The harness picks the server image from `MISP_FASTLOOKUP_BACKEND`. A throwaway branch swaps the `BF.*` calls for `SETBIT`/`BITFIELD_RO` bitmaps, selected by an environment variable, so the same benchmark runs on servers without any module.

**Tech Stack:** PHP 8.3 (CakePHP 2.x, phpredis), Redis Lua 5.1 scripts, RedisBloom (Redis 8.2), valkey-bloom (valkey-bundle 8.1), PHPUnit 8.5, podman, bash, Python 3.

**Spec:** `docs/superpowers/specs/2026-09-29-fastlookup-valkey-design.md`. It is the binding authority. Read it before starting any task.

## Global Constraints

- Commits are signed (`git commit -S`), use gitchangelog prefixes (`chg: [fastLookup] …`), and carry exactly one trailer: `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Add no other trailers or session links.
- Nothing is pushed to MISP/MISP#11168 or to `feature/attributes-fast-lookup-batched`. Do not push at all unless the user asks. The spec allows pushing to the fork only, with no PR.
- Follow the repo `CLAUDE.md` comment rules. Keep comments minimal, and never put task, finding or plan ids (`T1b`, `Review Focus`, …) in code.
- Keep 80 columns where the surrounding code keeps them: in shell scripts, docs and new PHP helper files. `FastLookupFilter.php`, its test and the contract use long lines, so match that file's style there. Do not rewrap existing code.
- Cluster mode (Redis Cluster and Valkey cluster) is out of scope. The scripts touch several keys of one generation at once.
- Indexes already in Redis must keep working. That means `bloom-1` and `bloom-2` namespaces, and legacy generations with a single key `g:<gen>:bf` and no `bloom_type` or `shards` fields.
- `SHARD_BYTES = 67108864` (64 MiB). `S = max(1, ceil(estimatedBytes / SHARD_BYTES))`, where `estimatedBytes = capacity × (−ln rate) / (ln 2)² / 8`. Each shard reserves `ceil(capacity / S)` at the same rate. A token's shard is `unpack('N', substr(token, 5, 4))[1] % S`.
- Allowed Bloom types: `const BLOOM_TYPES = ['MBbloom--', 'bloomfltr'];`.
- New code always writes schema `bloom-3`. `metadata()` and `checkpoint()` accept `bloom-1`, `bloom-2` and `bloom-3`.
- Backend images: `MISP_FASTLOOKUP_BACKEND=redis` selects `docker.io/library/redis:8.2`, and `valkey` selects `docker.io/valkey/valkey-bundle:8.1`. `MISP_REDIS_IMAGE` still overrides either one. Start the server with options only, and never pass `--loadmodule`.
- No system actions in implementer tasks: no installs, and no container, service, mount or daemon start, stop or restart. Any step marked **(controller)** starts disposable containers, and only the controller runs it. Temporary files go under `~/tmp`, never `/tmp`.
- Pure unit suite: `app/Vendor/bin/phpunit app/Test/FastLookupFilterTest.php`. If the host has no ext-intl, the ext-intl IDN test elsewhere in `app/Test/` fails, and that is expected.

## Review Focus

1. **Reserve fails part-way.** The case is a later shard hitting valkey-bloom's memory limit, OOM, or a missing command. No shard key may survive, and the error must be *Unavailable*, never *Corrupt*, because Corrupt resets the namespace. This is pinned by the T1b contract test "A reserve failing part-way keeps no shard" and by the T1a unit test `testUnsupportedFilterTypeAtReserveIsNotCorruption`.
2. **Half a layout in the info hash.** Examples are `bloom_type` without `shards`, `shards` without `bloom_type`, a `shards` of `0`, `x` or `1025`, and a `bloom_type` of `string`. A live generation must be Corrupt, and a building generation must be omitted. This is pinned by the T1b unit tests `testCorruptFilterLayoutIsCorruption` and `testBuildingGenerationWithACorruptLayoutIsOmitted`, and by the T1b contract test "A layout without … fails closed".
3. **One shard is missing, is a string, or has the other module's type** while its siblings are healthy. The generation must fail closed and never report "absent". This is pinned by the T1b contract per-shard loop and by the T1a contract test "A recorded type of the other module fails closed".
4. **Capacity is not divisible by S.** The shards together must hold at least the requested capacity, and the FP estimate uses the reserved total. This is pinned by the T1b unit test `testShardCapacityRoundsUpSoTheShardsHoldTheWholeCapacity`.
5. **`shards` is above the keys present**, for example after tampering or a truncated restore. The guard's key-name check must make this Corrupt. A *smaller* value cannot be detected, just like a tampered `buckets` today, and stays out of scope. This is pinned by the T1b contract test "A shard count above the shards present fails closed".

---

## File ownership and execution order

T1, T2 and T3 run in parallel in separate worktrees. Each file has exactly one owner.

| Task | Branch / worktree | Owns |
|---|---|---|
| T0 (controller) | none | `~/tmp/fl-valkey/*`, worktree creation |
| T1a → T1b (sequential) | `feature/attributes-fast-lookup-valkey`, `~/code/misp-fastlookup-valkey` | `app/Lib/Tools/FastLookupFilter.php`, `app/Test/FastLookupFilterTest.php`, `tests/benchmarks/FastLookupFilterRedisContract.php` (all of it, including its version line), `docs/development/fastlookup.md` |
| T2 | `feature/attributes-fast-lookup-valkey-harness`, `~/code/misp-fastlookup-valkey-harness` | `tests/benchmarks/FastLookupIntegration.sh`, `tests/benchmarks/FastLookupIntegration.php`, `tests/benchmarks/FastLookupScale.php`, new `tests/benchmarks/FastLookupBackend.php`, new `app/Test/FastLookupBackendVersionsTest.php` |
| T3 | `spike/fastlookup-bitmap-bloom`, `~/code/misp-fastlookup-bitmap` (never merged) | its own copy of `app/Lib/Tools/FastLookupFilter.php`, new `tests/benchmarks/FastLookupBitmapSmoke.php` |
| T4 (controller) | all three | merges, suite runs, benchmarks, report |

The Contract PHP and Integration/Scale PHP files are split deliberately. T1 owns the contract outright because nearly every change there is an assertion. T2 owns Integration.php and Scale.php, including the two lines T1's key rename breaks. T2 makes those lines match both the old `g:<gen>:bf` key and the new `g:<gen>:bf:<i>` keys, so T2 needs nothing from T1.

---

### Task 0 (controller): worktrees and the `~/tmp/fl-valkey` harness

**Files:**
- Create: `~/tmp/fl-valkey/up.sh`, `down.sh`, `php.sh`, `gate.sh`, `extract.py` (outside the repo)
- Copy: `~/tmp/fl-batched/zz-override.ini` → `~/tmp/fl-valkey/zz-override.ini`

**Interfaces:**
- Produces: `up.sh <redis-bloom|valkey-bloom|redis-plain|valkey-plain>` leaves `fl-valkey-db` (MariaDB 10.11) running on `~/tmp/fl-valkey/sock/mysql/mysql.sock`, and a fresh `fl-valkey-kv` on `~/tmp/fl-valkey/sock/redis/redis.sock`. `php.sh <script> [args]` runs PHP from `$PWD` with `/cake`, `/mysql`, `/redis` and `/out` mounted, and passes `FL_*` and `MISP_FASTLOOKUP_*` through. `gate.sh <worktree> <tag>` runs a full-scale benchmark and writes `~/tmp/fl-valkey/out/<tag>.json`. `extract.py` is described in T4.

- [ ] **Step 1: Record the base and create the worktrees**

```bash
BASE=$(git -C ~/code/misp-fastlookup-valkey rev-parse HEAD)   # the plan commit
echo "$BASE" > ~/tmp/fl-valkey-base.txt
git -C ~/code/misp-fastlookup-valkey worktree add \
    -b feature/attributes-fast-lookup-valkey-harness \
    ~/code/misp-fastlookup-valkey-harness "$BASE"
git -C ~/code/misp-fastlookup-valkey worktree add \
    -b spike/fastlookup-bitmap-bloom ~/code/misp-fastlookup-bitmap "$BASE"
for wt in ~/code/misp-fastlookup-valkey-harness ~/code/misp-fastlookup-bitmap; do
    cp -a ~/code/misp-fastlookup-valkey/app/Vendor "$wt/app/Vendor"
    git -C "$wt" submodule update --init app/Lib/cakephp \
        || cp -a ~/code/misp-fastlookup-valkey/app/Lib/cakephp/. \
                 "$wt/app/Lib/cakephp/"
done
```

- [ ] **Step 2: Write `~/tmp/fl-valkey/up.sh`**

```bash
#!/bin/bash
# up.sh <redis-bloom|valkey-bloom|redis-plain|valkey-plain>: disposable
# MariaDB 10.11 plus one fresh key-value server, on unix sockets only.
H=$HOME/tmp/fl-valkey; KV=${1:?usage: up.sh <backend>}
args=(--port 0 --unixsocket /socket/redis.sock --unixsocketperm 777
      --save '' --appendonly no)
case $KV in
  redis-bloom)  image=docker.io/library/redis:8.2;        entry=() ;;
  valkey-bloom) image=docker.io/valkey/valkey-bundle:8.1; entry=() ;;
  redis-plain)  image=docker.io/library/redis:8.2
                entry=(--entrypoint redis-server) ;;
  valkey-plain) image=${FL_VALKEY_PLAIN_IMAGE:-docker.io/valkey/valkey:8.1}
                entry=(--entrypoint valkey-server) ;;
  *) echo "unknown backend $KV" >&2; exit 2 ;;
esac
mkdir -p "$H/sock/mysql" "$H/sock/redis" "$H/out"
chmod 777 "$H/sock/mysql" "$H/sock/redis"
podman container exists fl-valkey-db || podman run -d --pull=never \
  --name fl-valkey-db --network=none -e MARIADB_ALLOW_EMPTY_ROOT_PASSWORD=1 \
  -v "$H/sock/mysql:/run/mysqld" docker.io/library/mariadb:10.11 \
  --socket=/run/mysqld/mysql.sock --max-allowed-packet=64M >/dev/null
podman rm --force fl-valkey-kv >/dev/null 2>&1
rm -f "$H/sock/redis/redis.sock"
podman run -d --pull=never --name fl-valkey-kv --network=none "${entry[@]}" \
  -v "$H/sock/redis:/socket" "$image" "${args[@]}" >/dev/null || exit 1
for i in $(seq 60); do
  podman exec fl-valkey-db sh -c \
    'mariadb-admin --socket=/run/mysqld/mysql.sock ping --silent' \
    >/dev/null 2>&1 \
  && podman exec fl-valkey-kv sh -c \
    'cli=$(command -v valkey-cli || command -v redis-cli) &&
     "$cli" -s /socket/redis.sock ping' >/dev/null 2>&1 \
  && { chmod 777 "$H/sock/mysql/mysql.sock" 2>/dev/null
       echo "$KV" > "$H/sock/backend"; echo "up $KV"; exit 0; }
  sleep 1
done
echo "services did not start" >&2; podman logs fl-valkey-kv >&2; exit 1
```

- [ ] **Step 3: Write `down.sh` and `php.sh`**

```bash
#!/bin/bash
# down.sh: remove the fl-valkey containers.
podman rm --force fl-valkey-db fl-valkey-kv >/dev/null 2>&1; echo down
```

```bash
#!/bin/bash
# php.sh <script> [args]: PHP from the current worktree in misp-live:tmp,
# with /cake, /mysql, /redis and /out; FL_* and MISP_FASTLOOKUP_* pass through.
H=$HOME/tmp/fl-valkey
envs=()
for v in $(compgen -e | grep -E '^(FL_|MISP_FASTLOOKUP_)'); do
  envs+=(-e "$v=${!v}")
done
podman run --rm --pull=never --network=none --entrypoint php "${envs[@]}" \
  -v "$PWD:/work:ro" -v "$PWD/app/Lib/cakephp/lib/Cake:/cake:ro" \
  -v "$H/sock/mysql:/mysql" -v "$H/sock/redis:/redis" -v "$H/out:/out" \
  -v "$H/zz-override.ini:/usr/local/etc/php/conf.d/zz-override.ini:ro" \
  -w /work localhost/misp-live:tmp -d display_errors=stderr \
  -d memory_limit=4G "$@"
```

- [ ] **Step 4: Write `gate.sh`**

`~/tmp/fl-batched/gate.sh` hard-codes two cgroup scope ids. This copy resolves them from the running containers.

```bash
#!/bin/bash
# gate.sh <worktree> <tag>: full-scale FastLookupScale run against the running
# fl-valkey services with container CPU accounting; writes out/<tag>.json.
# MISP_FASTLOOKUP_FILTER passes through (bitmap selects the prototype).
H=$HOME/tmp/fl-valkey; W=${1:?worktree}; T=${2:?tag}
cpustat() {
  local cid path
  cid=$(podman inspect -f '{{.Id}}' "$1") || return 1
  path=/sys/fs/cgroup/user.slice/user-$(id -u).slice
  path=$path/user@$(id -u).service/user.slice/libpod-$cid.scope/cpu.stat
  [[ -r $path ]] || path=/sys/fs/cgroup$(podman inspect \
    -f '{{.State.CgroupPath}}' "$1")/cpu.stat
  [[ -r $path ]] || { echo "no cpu.stat for $1" >&2; return 1; }
  echo "$path"
}
db=$(cpustat fl-valkey-db) && kv=$(cpustat fl-valkey-kv) || exit 1
cd "$W" || exit 1
podman run --rm --pull=never --network=none --name "fl-valkey-php-$T" \
  --entrypoint php \
  -e FL_PER_TYPE=100000 -e FL_PER_EVENT=100 -e FL_LOOKUP=10000 \
  -e FL_SQL_BASELINE=1 -e FL_RUNS=3 -e FL_CPU_STAT=db=/dbcpu,redis=/rediscpu \
  -e FL_OUT="/out/$T.json" \
  -e MISP_FASTLOOKUP_FILTER="${MISP_FASTLOOKUP_FILTER:-}" \
  -v "$W:/work:ro" -v "$W/app/Lib/cakephp/lib/Cake:/cake:ro" \
  -v "$H/sock/mysql:/mysql" -v "$H/sock/redis:/redis" -v "$H/out:/out" \
  -v "$db:/dbcpu:ro" -v "$kv:/rediscpu:ro" \
  -v "$H/zz-override.ini:/usr/local/etc/php/conf.d/zz-override.ini:ro" \
  -w /work localhost/misp-live:tmp -d display_errors=stderr \
  -d memory_limit=6G \
  tests/benchmarks/FastLookupScale.php /cake /mysql/mysql.sock \
  /redis/redis.sock
```

- [ ] **Step 5: Make the scripts executable and copy the ini**

```bash
mkdir -p ~/tmp/fl-valkey/out ~/tmp/fl-valkey/tmp
chmod +x ~/tmp/fl-valkey/*.sh
cp ~/tmp/fl-batched/zz-override.ini ~/tmp/fl-valkey/
bash -n ~/tmp/fl-valkey/up.sh ~/tmp/fl-valkey/php.sh ~/tmp/fl-valkey/gate.sh
```
Expected: no output.

- [ ] **Step 6: Get the plain Valkey image (controller decision, once)**

`docker.io/valkey/valkey:8.1` is not in the local image store, and every script uses `--pull=never`. Choose one of these:
- pull it (`podman pull docker.io/valkey/valkey:8.1`);
- or, if pulling is not allowed, set `FL_VALKEY_PLAIN_IMAGE=docker.io/valkey/valkey-bundle:8.1`. The `--entrypoint valkey-server` bypasses the bundle's module loader. Confirm it by checking that `versions.modules` in T4's JSON has no `bf`.

---

### Task T1a: Allow both Bloom types, and record the type per generation

**Files:**
- Modify: `app/Lib/Tools/FastLookupFilter.php:23-25` (class docblock), `:49` (`BLOOM_TYPE`), `:98-104` (add `bloomTypeAllowed` after `estimatedFalsePositiveRate`), `:200-210` (reserve script), `:833-849` (`guardScript`)
- Modify: `app/Test/FastLookupFilterTest.php` (new tests; the `evalRedis` double records scripts)
- Modify: `tests/benchmarks/FastLookupFilterRedisContract.php:68-102` and `:227-229` (new section), `:339` (report line)
- Modify: `docs/development/fastlookup.md:9-17` (Requirements)

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `const BLOOM_TYPES = ['MBbloom--', 'bloomfltr']`, which replaces `BLOOM_TYPE`;
  - `public static function bloomTypeAllowed($type): bool`;
  - info-hash field `bloom_type`, written by `reserve()`;
  - reserve error reply `unsupported Bloom filter type`, which surfaces as `FastLookupIndexUnavailableException`, not Corrupt;
  - `private function bloomTypesScript(): string`, which yields the Lua `local BLOOM_TYPES = {['MBbloom--'] = true, ['bloomfltr'] = true}`.
  - The keys stay as they are: single `g:<gen>:bf`, and schema still `bloom-2`.

- [ ] **Step 1: Write the failing unit tests**

In `app/Test/FastLookupFilterTest.php`, have the `evalRedis` double record scripts:

```php
    /** Answers every eval() with $reply and records its scripts. */
    private function evalRedis($reply)
    {
        return new class($reply) {
            public $scripts = [];
            private $reply;
            public function __construct($reply) { $this->reply = $reply; }
            public function eval($script, $args, $keys) { $this->scripts[] = $script; return $this->reply; }
            public function clearLastError() { return true; }
            public function getLastError() { return null; }
        };
    }
```

Add these tests before the `testScopeDisagreeingWithConfigurationFailsClosed` test:

```php
    // -- Bloom filter types ----------------------------------------------------

    public function testRedisBloomAndValkeyBloomTypesAreAllowed(): void
    {
        $this->assertSame(['MBbloom--', 'bloomfltr'], FastLookupFilter::BLOOM_TYPES);
        foreach (FastLookupFilter::BLOOM_TYPES as $type) {
            $this->assertTrue(FastLookupFilter::bloomTypeAllowed($type), $type);
        }
        foreach (['string', 'hash', '', 'mbbloom--', 'MBbloom-- ', false, null, 1] as $type) {
            $this->assertFalse(FastLookupFilter::bloomTypeAllowed($type), var_export($type, true));
        }
    }

    public function testGuardAcceptsEitherTypeAndEnforcesTheRecordedOne(): void
    {
        $redis = $this->evalRedis([false, false, false]);
        $this->filter(null, $redis)->prefixLengths('generation');
        $this->assertStringContainsString("local BLOOM_TYPES = {['MBbloom--'] = true, ['bloomfltr'] = true}", $redis->scripts[0]);
        $this->assertStringContainsString('(bloomType and found ~= bloomType)', $redis->scripts[0]);
    }

    public function testReserveRecordsTheFilterTypeAndRefusesAnyOther(): void
    {
        $calls = $this->reserveCalls(['hGetAll' => $this->validMetadataFields()]);
        $reserve = array_values(array_filter($calls, function ($call) {
            return $call[0] === 'eval' && strpos($call[1][0], 'BF.RESERVE') !== false;
        }));
        $script = $reserve[0][1][0];
        $this->assertStringContainsString("local BLOOM_TYPES = {['MBbloom--'] = true, ['bloomfltr'] = true}", $script);
        $this->assertStringContainsString("'bloom_type', bloomType", $script);
        $this->assertStringContainsString("redis.error_reply('unsupported Bloom filter type')", $script);
    }

    public function testUnsupportedFilterTypeAtReserveIsNotCorruption(): void
    {
        $redis = $this->recordingRedis(['hGetAll' => $this->validMetadataFields(), 'eval' => false,
            'getLastError' => 'unsupported Bloom filter type']);
        try {
            $this->filter(null, $redis)->reserve('next', 'fingerprint', 1000, 0.001, 1);
            $this->fail('An unsupported filter type must fail the reserve.');
        } catch (FastLookupIndexUnavailableException $e) {
            $this->assertNotInstanceOf(FastLookupIndexCorruptException::class, $e);
        }
        $this->assertNotContains('scan', $redis->calls, 'Nothing is reclaimed after a refused reserve.');
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `app/Vendor/bin/phpunit app/Test/FastLookupFilterTest.php`
Expected: FAIL. You should see `Error: Undefined constant FastLookupFilter::BLOOM_TYPES`, and failed assertions on the missing script fragments. The other 91 tests still pass.

- [ ] **Step 3: Implement**

Replace line 49, `const BLOOM_TYPE = 'MBbloom--';`, with:

```php
    /** The TYPE of a RedisBloom and of a valkey-bloom filter. */
    const BLOOM_TYPES = ['MBbloom--', 'bloomfltr'];
```

After `estimatedFalsePositiveRate()`, add:

```php
    public static function bloomTypeAllowed($type): bool
    {
        return is_string($type) && in_array($type, self::BLOOM_TYPES, true);
    }
```

Update the class docblock's first line to:

```php
 * Redis side of fast lookup: one Bloom filter (RedisBloom or valkey-bloom) per
 * generation holding every token, plus append-only postings for IP-range and
 * domain tokens.
```

In `reserve()`, replace the first `$this->evaluate(<<<'LUA'` block (the one holding `BF.RESERVE`) with:

```php
        $this->evaluate($this->bloomTypesScript() . <<<'LUA'
if redis.call('HGET', KEYS[1], 'live') == ARGV[1] then return redis.error_reply('a rebuild must use a fresh generation') end
if redis.call('EXISTS', KEYS[2]) ~= 0 or redis.call('EXISTS', KEYS[3]) ~= 0 then return redis.error_reply('generation keys already exist') end
redis.call('BF.RESERVE', KEYS[3], ARGV[4], ARGV[3], 'NONSCALING')
local bloomType = redis.call('TYPE', KEYS[3]).ok
if not BLOOM_TYPES[bloomType] then
    redis.call('DEL', KEYS[3])
    return redis.error_reply('unsupported Bloom filter type')
end
redis.call('HSET', KEYS[2], '!', ARGV[1], 'capacity', ARGV[3], 'rate', ARGV[4], 'inserted', '0', 'stale', '0', 'buckets', ARGV[5], 'cursor', '0',
    'bloom_type', bloomType, 'p4', string.rep('0', 33), 'p6', string.rep('0', 129), 'pv', '0')
redis.call('HSET', KEYS[1], 'building', ARGV[1], 'building_fingerprint', ARGV[2], 'schema', ARGV[6])
return 1
LUA
            , [$this->metaKey(), $this->infoKey($generation), $this->bloomKey($generation)],
            [$generation, $fingerprint, (string)$capacity, rtrim(sprintf('%.10F', $rate), '0'), (string)$buckets, self::SCHEMA]);
```

Replace `guardScript()`, including its docblock, with:

```php
    /**
     * The one fail-closed generation guard: BF.MEXISTS reports absence for a
     * missing key and BF.MADD creates a default filter, so every script checks
     * the state sentinel and the filter's type first. A generation that
     * recorded its type must keep it. Returns an error reply, or nil when the
     * generation is intact.
     */
    private function guardScript(): string
    {
        return $this->bloomTypesScript() . <<<'LUA'
local function requireGeneration(infoKey, bloomKey, generation)
    local state = redis.call('HMGET', infoKey, '!', 'bloom_type')
    if state[1] ~= generation then return redis.error_reply('missing generation state') end
    if redis.call('EXISTS', bloomKey) ~= 1 then return redis.error_reply('missing Bloom filter') end
    local found, bloomType = redis.call('TYPE', bloomKey).ok, state[2]
    if not BLOOM_TYPES[found] or (bloomType and found ~= bloomType) then return redis.error_reply('missing Bloom filter') end
    return nil
end

LUA;
    }

    private function bloomTypesScript(): string
    {
        $entries = array_map(static function ($type) { return "['" . $type . "'] = true"; }, self::BLOOM_TYPES);
        return 'local BLOOM_TYPES = {' . implode(', ', $entries) . "}\n";
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `app/Vendor/bin/phpunit app/Test/FastLookupFilterTest.php`
Expected: `OK (95 tests, …)`.

- [ ] **Step 5: Update the contract**

In `tests/benchmarks/FastLookupFilterRedisContract.php`:

Line 68, first assertion message: `'RedisBloom is detected'` → `'A Bloom module is detected'`.

Replace line 102 (`… === FastLookupFilter::BLOOM_TYPE, 'The filter is a RedisBloom filter');`) with:

```php
    $bloomType = $redis->eval("return redis.call('TYPE', KEYS[1]).ok", [$prefix . 'g:first:bf'], 1);
    $assert(in_array($bloomType, FastLookupFilter::BLOOM_TYPES, true), 'The filter is a RedisBloom or valkey-bloom filter: ' . $bloomType);
    $assert($redis->hGet($prefix . 'g:first:info', 'bloom_type') === $bloomType, 'The generation records its filter type');
```

Directly after `$proxy->noBloomCommands = false;`, which follows "Unavailable BF commands fail closed", insert:

```php
    // Either module's filter type is served, and a generation's recorded type is enforced.
    $fifthInfo = $prefix . 'g:fifth:info';
    $fifthFilter = $prefix . 'g:fifth:bf';
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
    $redis->hDel($fifthInfo, 'bloom_type');
    $assert($lookupFifth() === true, 'A generation built before types were recorded is served');
```

Replace the final `echo json_encode([...'redis_version' => ...` line with:

```php
    $server = $redis->info('server');
    echo json_encode(['assertions' => $assertions, 'backend' => isset($server['valkey_version']) ? 'valkey' : 'redis',
        'version' => $server['valkey_version'] ?? $server['redis_version'], 'status' => 'passed'], JSON_PRETTY_PRINT), "\n";
```

- [ ] **Step 6: Lint**

Run: `php -l app/Lib/Tools/FastLookupFilter.php && php -l tests/benchmarks/FastLookupFilterRedisContract.php`
Expected: `No syntax errors detected` twice.

- [ ] **Step 7: Update the Requirements docs**

In `docs/development/fastlookup.md`, replace the first two lines of the Requirements paragraph:

```
Fast lookup needs Redis 8 or Redis Stack for the RedisBloom module (`BF.*`
commands). Without it the endpoint answers HTTP 503 with `Fast lookup requires
```

with:

```
Fast lookup needs a Bloom filter module answering the `BF.*` commands: Redis 8
or Redis Stack (RedisBloom), or Valkey 8.1 or later with valkey-bloom, for
example the `valkey/valkey-bundle` image. The index needs no configuration to
use either: each generation records the filter type it was built with, and a
filter of any other type fails closed. Without a module the endpoint answers
HTTP 503 with `Fast lookup requires
```

- [ ] **Step 8 (controller): Run the contract on both backends**

```bash
cd ~/code/misp-fastlookup-valkey
~/tmp/fl-valkey/up.sh redis-bloom && ~/tmp/fl-valkey/php.sh \
    tests/benchmarks/FastLookupFilterRedisContract.php /redis/redis.sock
~/tmp/fl-valkey/up.sh valkey-bloom && ~/tmp/fl-valkey/php.sh \
    tests/benchmarks/FastLookupFilterRedisContract.php /redis/redis.sock
```
Expected: two JSON reports with `"status": "passed"` and `"backend": "redis"` and `"backend": "valkey"` respectively. Valkey passing here is the first proof of spec Goal 1.

- [ ] **Step 9: Commit**

```bash
git add app/Lib/Tools/FastLookupFilter.php app/Test/FastLookupFilterTest.php \
    tests/benchmarks/FastLookupFilterRedisContract.php docs/development/fastlookup.md
git commit -S -m "chg: [fastLookup] Serve RedisBloom and valkey-bloom filters" \
    -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task T1b: Sharded filters and schema `bloom-3`

**Files:**
- Modify: `app/Lib/Tools/FastLookupFilter.php`: constants, constructor, static sizing helpers, `metadata()`, `reserve()`, `add()`, `markStale()`, `setCursor()`, `checkpoint()`, `activate()`, `prefixLengths()`, `candidates()`, `statistics()`, `generationInfo()`, `fenceScript()`, `guardScript()`, and key helpers (`bloomKey()` is removed)
- Modify: `app/Test/FastLookupFilterTest.php`
- Modify: `tests/benchmarks/FastLookupFilterRedisContract.php`
- Modify: `docs/development/fastlookup.md` (Storage, schema paragraph, Requirements cluster note, Verification)

**Interfaces:**
- Consumes (T1a): `BLOOM_TYPES`, `bloomTypeAllowed()`, `bloomTypesScript()`, `bloom_type`.
- Produces:
  - `const SCHEMA = 'bloom-3'`, `const SCHEMAS = ['bloom-3', 'bloom-2', 'bloom-1']` (`LEGACY_SCHEMA` is removed), `const SHARD_BYTES = 67108864`, `const MAX_SHARDS = 1024`.
  - `__construct(string $namespace, array $scope, $redis = null, int $shardBytes = self::SHARD_BYTES)`.
  - `public static function estimatedFilterBytes(int $capacity, float $rate): float`
  - `public static function shardCount(int $capacity, float $rate, int $shardBytes = self::SHARD_BYTES): int`
  - `public static function shardCapacity(int $capacity, int $shards): int`
  - `public static function shardOf(string $token, int $shards): int`
  - `metadata()['generations'][$g]['shards']` is an `?int`: `null` means a legacy single unsharded filter.
  - Info fields `shards` and `bloom_type`.
  - Keys `g:<gen>:bf:<i>` for every new generation, including S = 1. Legacy `g:<gen>:bf` is still read.
  - **T2 consumes this key layout.** The Scale key classifier and the Integration eviction scan must match `g:<gen>:bf` and `g:<gen>:bf:<i>`.
  - The Lua guard has the signature `requireGeneration(infoKey, first, generation) -> failure, shardCount`, where `KEYS[first..first+S-1]` are the shards.
  - A generation reserved by T1a code has `bloom_type` without `shards`, so T1b reads it as Corrupt. Such generations exist only in development: rebuild them.

- [ ] **Step 1: Write the failing unit tests, and update the existing ones**

Change the `filter()` helper:

```php
    private function filter($scope = null, $redis = null, int $shardBytes = FastLookupFilter::SHARD_BYTES)
    {
        return new FastLookupFilter('test-database', $scope ?? ['attribute_types' => ['domain'], 'published_only' => true],
            $redis ?? $this->disconnected(), $shardBytes);
    }
```

Update these existing tests:

```php
    public function unknownSchemas(): array
    {
        return ['v3' => ['v3'], 'a later schema' => ['bloom-4'], 'empty' => ['']];
    }

    public function testEverySchemaIsServed(): void
    {
        $this->assertSame('bloom-3', FastLookupFilter::SCHEMA);
        $this->assertSame(['bloom-3', 'bloom-2', 'bloom-1'], FastLookupFilter::SCHEMAS);
        foreach (FastLookupFilter::SCHEMAS as $schema) {
            $this->assertTrue($this->filter(null, $this->metadataDouble(['schema' => $schema]))->metadata()['ready'], $schema);
        }
    }
```

This replaces `testCurrentAndLegacySchemasAreServed`. In `testReserveStampsTheCurrentSchemaWithTheMaskedGeneration`, use `['schema' => 'bloom-1']` instead of `FastLookupFilter::LEGACY_SCHEMA`, and `'bloom-3'` at `$arguments[$keyCount + 5]`. In `testNamespaceResetWritesTheCurrentSchema`, expect `'bloom-3'`. Replace `testCheckpointAcceptsBothSchemas` with:

```php
    public function testCheckpointAcceptsEverySchema(): void
    {
        $redis = $this->recordingRedis(['eval' => 1]);
        $this->filter(null, $redis)->checkpoint('r1', false);
        [, [$script, $arguments, $keyCount]] = $redis->arguments[1];
        $this->assertStringContainsString('if schema == ARGV[i] then known = true end', $script);
        $this->assertSame(FastLookupFilter::SCHEMAS, array_slice($arguments, $keyCount + 2));
    }
```

Every path that reads a generation now does one `hMGet` first, so the doubles must answer it.

```php
    /** metadata() for a generation 'live1' whose layout HMGET answers $layout and state HMGET answers $state. */
    private function liveGenerationMetadata(array $state, string $field = 'live', array $layout = ['!' => 'live1', 'shards' => false, 'bloom_type' => false])
    {
        $fields = [$field => 'live1'] + ($field === 'live' ? [] : ['live' => '']) + $this->validMetadataFields();
        $fields['ready'] = $field === 'live' ? '1' : '0';
        return $this->filter(null, $this->recordingRedis(['hGetAll' => $fields, 'hMGet' => $layout, 'eval' => $state]))->metadata();
    }
```

- In `testCorruptPrefixMaskReplyIsCorruption`, add `'hMGet' => ['!' => 'generation', 'shards' => '1', 'bloom_type' => 'MBbloom--']` to both reply sets.
- In `transportFailures` 'BUSY on the generation check', add `'hMGet' => ['!' => 'live1', 'shards' => false, 'bloom_type' => false]`.
- In `testMissingLiveGenerationIsCorruption`, add the same `'hMGet'` reply.
- In the `evalRedis` double, add:

```php
            public function hMGet($key, $fields) { return ['!' => 'generation', 'shards' => '1', 'bloom_type' => 'MBbloom--']; }
```

Add these new tests after the Bloom filter types block:

```php
    // -- sharded filters ---------------------------------------------------------

    /** 'E', four posting-bucket bytes, then four shard bytes. */
    private function shardedToken(int $shardWord, int $bucketWord = 0): string
    {
        return 'E' . pack('N', $bucketWord) . pack('N', $shardWord);
    }

    private function keyName(string $generation, string $suffix): string
    {
        return FastLookupFilter::PREFIX . hash('sha256', 'test-database') . ':g:' . $generation . ':' . $suffix;
    }

    /** Answers hGetAll/hMGet from $replies; eval with the reply of the first fragment its script contains, else 1. */
    private function scriptedRedis(array $replies, array $scripts)
    {
        return new class($replies, $scripts) {
            public $evals = [];
            private $replies;
            private $scripts;
            public function __construct(array $replies, array $scripts) { $this->replies = $replies; $this->scripts = $scripts; }
            public function eval($script, $args, $keyCount)
            {
                $this->evals[] = [$script, $args, $keyCount];
                foreach ($this->scripts as $fragment => $reply) {
                    if (strpos($script, $fragment) !== false) { return $reply; }
                }
                return 1;
            }
            public function clearLastError() { return true; }
            public function getLastError() { return null; }
            public function __call($method, $args) { return $this->replies[$method] ?? null; }
        };
    }

    public function testShardCountSplitsAtTheShardSize(): void
    {
        $bytes = FastLookupFilter::estimatedFilterBytes(1000000, 0.001);
        $this->assertSame(1, FastLookupFilter::shardCount(1000000, 0.001, (int)ceil($bytes)));
        $this->assertSame(2, FastLookupFilter::shardCount(1000000, 0.001, (int)ceil($bytes) - 1));
        $this->assertSame(2, FastLookupFilter::shardCount(1000000, 0.001, (int)ceil($bytes / 2)));
        $this->assertSame(3, FastLookupFilter::shardCount(1000000, 0.001, (int)floor($bytes / 2)));
    }

    public function testDefaultShardsKeepEveryFilterUnderHalfValkeysLimit(): void
    {
        $this->assertSame(67108864, FastLookupFilter::SHARD_BYTES);
        $this->assertSame(1, FastLookupFilter::shardCount(1, 0.5));
        $this->assertSame(1, FastLookupFilter::shardCount(1000000, 0.001));
        $this->assertSame(1, FastLookupFilter::shardCount(37000000, 0.001));
        $this->assertSame(2, FastLookupFilter::shardCount(38000000, 0.001));
        $this->assertSame(2, FastLookupFilter::shardCount(40000000, 0.001));
        $this->assertSame(3, FastLookupFilter::shardCount(80000000, 0.001));
        $this->assertSame(9, FastLookupFilter::shardCount(300000000, 0.001));
    }

    public function testShardCapacityRoundsUpSoTheShardsHoldTheWholeCapacity(): void
    {
        $this->assertSame(1000, FastLookupFilter::shardCapacity(1000, 1));
        $this->assertSame(4, FastLookupFilter::shardCapacity(10, 3));
        $this->assertSame(333334, FastLookupFilter::shardCapacity(1000000, 3));
        $this->assertSame(20000000, FastLookupFilter::shardCapacity(40000000, 2));
        foreach ([[10, 3], [1000001, 7], [80000000, 3]] as [$capacity, $shards]) {
            $total = $shards * FastLookupFilter::shardCapacity($capacity, $shards);
            $this->assertGreaterThanOrEqual($capacity, $total);
            $this->assertLessThan($capacity + $shards, $total);
        }
    }

    public function testShardOfUsesDigestBytesFourToSeven(): void
    {
        $this->assertSame(1, FastLookupFilter::shardOf($this->shardedToken(7), 3));
        $this->assertSame(0, FastLookupFilter::shardOf($this->shardedToken(0xFFFFFFFF), 3));
        $this->assertSame(0, FastLookupFilter::shardOf($this->shardedToken(5, 123), 1));
        $this->assertSame(FastLookupFilter::shardOf($this->shardedToken(9, 1), 4), FastLookupFilter::shardOf($this->shardedToken(9, 0xABCDEF01), 4));
    }

    public function testInvalidShardSizeCannotConstructAFilter(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->filter(null, null, 0);
    }

    public function testReserveBeyondTheShardLimitFailsBeforeAnyRedisCall(): void
    {
        $this->expectException(OverflowException::class);
        $this->filter(null, null, 1)->reserve('next', 'fingerprint', 1000000, 0.001, 1);
    }

    public function testReserveSplitsTheFilterIntoShards(): void
    {
        $redis = $this->recordingRedis(['hGetAll' => $this->validMetadataFields(), 'eval' => 1, 'scan' => []]);
        try {
            $this->filter(null, $redis, 1 << 20)->reserve('next', 'fingerprint', 1000000, 0.001, 1);
        } catch (FastLookupIndexUnavailableException $e) {
            // The double's SCAN cursor never ends the cleanup.
        }
        $reserve = array_values(array_filter($redis->arguments, function ($call) {
            return $call[0] === 'eval' && strpos($call[1][0], 'BF.RESERVE') !== false;
        }));
        [$script, $arguments, $keyCount] = $reserve[0][1];
        $this->assertSame(4, $keyCount);
        $this->assertSame([$this->keyName('next', 'bf:0'), $this->keyName('next', 'bf:1')], array_slice($arguments, 2, 2));
        $this->assertSame('500000', $arguments[$keyCount + 2]);
        $this->assertSame('bloom-3', $arguments[$keyCount + 5]);
        $this->assertSame(['2', '1000000'], array_slice($arguments, $keyCount + 6, 2));
        $this->assertStringContainsString("'shards', ARGV[7], 'bloom_type', bloomType", $script);
        $this->assertStringContainsString("for j = 3, i do redis.call('DEL', KEYS[j]) end", $script);
    }

    public function testLegacyGenerationReadsItsUnshardedFilter(): void
    {
        $redis = $this->recordingRedis(['hGetAll' => ['live' => 'live1'] + $this->validMetadataFields(),
            'hMGet' => ['!' => 'live1', 'shards' => false, 'bloom_type' => false], 'eval' => $this->generationState([false, false, false])]);
        $meta = $this->filter(null, $redis)->metadata();
        $this->assertNull($meta['generations']['live1']['shards']);
        $evals = array_values(array_filter($redis->arguments, function ($call) { return $call[0] === 'eval'; }));
        [, $arguments, $keyCount] = $evals[0][1];
        $this->assertSame([$this->keyName('live1', 'info'), $this->keyName('live1', 'bf')], array_slice($arguments, 0, $keyCount));
    }

    public function testShardedGenerationGuardsEveryShard(): void
    {
        $redis = $this->recordingRedis(['hGetAll' => ['live' => 'live1'] + $this->validMetadataFields(),
            'hMGet' => ['!' => 'live1', 'shards' => '3', 'bloom_type' => 'bloomfltr'], 'eval' => $this->generationState([false, false, false])]);
        $this->assertSame(3, $this->filter(null, $redis)->metadata()['generations']['live1']['shards']);
        $evals = array_values(array_filter($redis->arguments, function ($call) { return $call[0] === 'eval'; }));
        [$script, $arguments, $keyCount] = $evals[0][1];
        $this->assertStringContainsString('requireGeneration(KEYS[1], 2, ARGV[1])', $script);
        $this->assertSame([$this->keyName('live1', 'info'), $this->keyName('live1', 'bf:0'), $this->keyName('live1', 'bf:1'),
            $this->keyName('live1', 'bf:2')], array_slice($arguments, 0, $keyCount));
    }

    public function corruptLayouts(): array
    {
        return [
            'zero shards' => [['!' => 'live1', 'shards' => '0', 'bloom_type' => 'bloomfltr']],
            'non-decimal shards' => [['!' => 'live1', 'shards' => 'x', 'bloom_type' => 'bloomfltr']],
            'too many shards' => [['!' => 'live1', 'shards' => '1025', 'bloom_type' => 'bloomfltr']],
            'shards without a type' => [['!' => 'live1', 'shards' => '2', 'bloom_type' => false]],
            'a type without shards' => [['!' => 'live1', 'shards' => false, 'bloom_type' => 'MBbloom--']],
            'a foreign type' => [['!' => 'live1', 'shards' => '2', 'bloom_type' => 'string']],
            'another generation' => [['!' => 'other', 'shards' => '2', 'bloom_type' => 'MBbloom--']],
        ];
    }

    /** @dataProvider corruptLayouts */
    public function testCorruptFilterLayoutIsCorruption(array $layout): void
    {
        $this->expectException(FastLookupIndexCorruptException::class);
        $this->liveGenerationMetadata($this->generationState([false, false, false]), 'live', $layout);
    }

    /** @dataProvider corruptLayouts */
    public function testBuildingGenerationWithACorruptLayoutIsOmitted(array $layout): void
    {
        $meta = $this->liveGenerationMetadata($this->generationState([false, false, false]), 'building', $layout);
        $this->assertSame('live1', $meta['building']);
        $this->assertSame([], $meta['generations']);
    }

    public function testRefusedLayoutReadIsNotCorruption(): void
    {
        $redis = $this->recordingRedis(['hGetAll' => ['live' => 'live1'] + $this->validMetadataFields(), 'hMGet' => false]);
        try {
            $this->filter(null, $redis)->metadata();
            $this->fail('A refused layout read must fail closed.');
        } catch (FastLookupIndexUnavailableException $e) {
            $this->assertNotInstanceOf(FastLookupIndexCorruptException::class, $e);
        }
    }

    public function testAddSendsEachTokenToItsShard(): void
    {
        [$t4, $t7, $t10] = [$this->shardedToken(4), $this->shardedToken(7), $this->shardedToken(10)];
        $redis = $this->scriptedRedis(['hMGet' => ['!' => 'live1', 'shards' => '2', 'bloom_type' => 'bloomfltr']], []);
        $this->filter(null, $redis)->add('live1', [['id' => '1', 'tokens' => [$t4, $t7, $t10]]]);
        $adds = array_values(array_filter($redis->evals, function ($eval) { return strpos($eval[0], 'BF.MADD') !== false; }));
        $this->assertCount(1, $adds);
        [$script, $arguments, $keyCount] = $adds[0];
        $this->assertStringContainsString("redis.call('BF.MADD', KEYS[3 + shard]", $script);
        $this->assertSame([$this->keyName('live1', 'bf:0'), $this->keyName('live1', 'bf:1')], array_slice($arguments, 2, 2));
        $this->assertSame(['live1', '0', '2', $t4, $t10, '1', '1', $t7], array_slice($arguments, $keyCount));
    }

    public function testCandidatesProbeEachTokenInItsShard(): void
    {
        [$t4, $t7, $t10] = [$this->shardedToken(4), $this->shardedToken(7), $this->shardedToken(10)];
        $redis = $this->scriptedRedis([
            'hGetAll' => ['live' => 'live1', 'fingerprint' => 'f'] + $this->validMetadataFields(),
            'hMGet' => ['!' => 'live1', 'shards' => '2', 'bloom_type' => 'bloomfltr'],
        ], [
            'BF.MEXISTS' => [1, [false, false, false]],
            "'capacity', 'rate', 'inserted'" => $this->generationState([false, false, false]),
        ]);
        $plan = [];
        foreach ([$t4, $t7, $t10] as $token) { $plan[] = [['token' => $token, 'kind' => 'exact']]; }
        $result = $this->filter(null, $redis)->candidates('live1', $plan);
        $this->assertSame([false, false, false], array_column($result, 'exact'));
        $probes = array_values(array_filter($redis->evals, function ($eval) { return strpos($eval[0], 'BF.MEXISTS') !== false; }));
        [, $arguments, $keyCount] = $probes[0];
        $this->assertSame(4, $keyCount);
        $this->assertSame([0, 0, $t4, 0, 1, $t7, 0, 0, $t10], array_slice($arguments, $keyCount + 3));
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `app/Vendor/bin/phpunit app/Test/FastLookupFilterTest.php`
Expected: FAIL with `Undefined constant FastLookupFilter::SHARD_BYTES`. The whole class errors because of the new `filter()` default.

- [ ] **Step 3: Implement the constants, constructor and sizing helpers**

Replace the `SCHEMA`/`LEGACY_SCHEMA` docblock and constants (lines 40-46) and T1a's `BLOOM_TYPES` block with:

```php
    /**
     * Stamped when a generation is reserved: 'bloom-2' added IP prefix masks,
     * 'bloom-3' sharded filters. Code that knows only older values fails
     * closed on a newer one; older namespaces keep being served.
     */
    const SCHEMA = 'bloom-3';
    const SCHEMAS = ['bloom-3', 'bloom-2', 'bloom-1'];
    const PREFIX = 'misp:fast_lookup:bf1:';
    const LEGACY_PREFIX = 'misp:fast_lookup:v3:';
    /** The TYPE of a RedisBloom and of a valkey-bloom filter. */
    const BLOOM_TYPES = ['MBbloom--', 'bloomfltr'];
    /** Half of valkey-bloom's default 128 MiB bf.bloom-memory-usage-limit. */
    const SHARD_BYTES = 67108864;
    const MAX_SHARDS = 1024;
```

Add the property `private $shardBytes;` after `private $redis;`. Change the constructor signature, and put the check first:

```php
    /** $shardBytes exists for tests: it forces several shards at small sizes. */
    public function __construct(string $namespace, array $scope, $redis = null, int $shardBytes = self::SHARD_BYTES)
    {
        if ($shardBytes < 1) {
            throw new InvalidArgumentException('Invalid fastLookup shard size.');
        }
```

At the end of the constructor, add `$this->shardBytes = $shardBytes;`.

After `bloomTypeAllowed()`, add:

```php
    /** RedisBloom sizing: -ln(p)/ln(2)^2 bits per entry. */
    public static function estimatedFilterBytes(int $capacity, float $rate): float
    {
        return max(1, $capacity) * -log($rate) / (log(2) ** 2) / 8;
    }

    public static function shardCount(int $capacity, float $rate, int $shardBytes = self::SHARD_BYTES): int
    {
        return max(1, (int)ceil(self::estimatedFilterBytes($capacity, $rate) / $shardBytes));
    }

    public static function shardCapacity(int $capacity, int $shards): int
    {
        return intdiv($capacity + $shards - 1, $shards);
    }

    /** Digest bytes 4-7; the posting bucket uses bytes 0-3. */
    public static function shardOf(string $token, int $shards): int
    {
        return unpack('N', $token, 5)[1] % $shards;
    }
```

In `metadata()`, replace `[self::SCHEMA, self::LEGACY_SCHEMA]` with `self::SCHEMAS`.

- [ ] **Step 4: Implement the layout read, the key helpers and the guard**

Replace `private function bloomKey($generation)` with:

```php
    /** A legacy generation (null shards) has one unsharded filter. */
    private function filterKeys(string $generation, ?int $shards): array
    {
        if ($shards === null) {
            return [$this->generationPrefix($generation) . 'bf'];
        }
        $keys = [];
        for ($i = 0; $i < $shards; ++$i) {
            $keys[] = $this->generationPrefix($generation) . 'bf:' . $i;
        }
        return $keys;
    }

    /** The shard count a generation records; null for a legacy one. */
    private function shardLayout(string $generation): ?int
    {
        $state = $this->call('hMGet', [$this->infoKey($generation), ['!', 'shards', 'bloom_type']]);
        if (!is_array($state)) {
            throw new FastLookupIndexUnavailableException('Redis could not read the fastLookup generation state.');
        }
        if (($state['!'] ?? false) !== $generation) {
            throw new FastLookupIndexCorruptException('A fastLookup generation is missing.');
        }
        $shards = $state['shards'] ?? false;
        $type = $state['bloom_type'] ?? false;
        if (($shards === false || $shards === null) && ($type === false || $type === null)) {
            return null;
        }
        if (!is_string($shards) || !preg_match('/\A[1-9][0-9]{0,3}\z/', $shards) || (int)$shards > self::MAX_SHARDS
            || !self::bloomTypeAllowed($type)) {
            throw new FastLookupIndexCorruptException('The fastLookup filter layout is corrupt.');
        }
        return (int)$shards;
    }

    private function fenceKeys(string $generation): array
    {
        return array_merge([$this->metaKey(), $this->infoKey($generation)],
            $this->filterKeys($generation, $this->shardLayout($generation)));
    }
```

Replace `fenceScript()` and `guardScript()`. Keep `bloomTypesScript()` from T1a.

```php
    /** KEYS[1..2] = metadata, generation state; KEYS[3..] = its filters; ARGV[1] = a live or building generation. */
    private function fenceScript(): string
    {
        return $this->guardScript() . <<<'LUA'
if ARGV[1] ~= redis.call('HGET', KEYS[1], 'live') and ARGV[1] ~= redis.call('HGET', KEYS[1], 'building') then return redis.error_reply('generation changed') end
local failure, SHARDS = requireGeneration(KEYS[2], 3, ARGV[1])
if failure then return failure end

LUA;
    }
    /**
     * The one fail-closed generation guard: BF.MEXISTS reports absence for a
     * missing key and BF.MADD creates a default filter, so every script checks
     * the state sentinel, the layout, and each shard's name and type first.
     * KEYS[first..] hold the filters. Returns an error reply, or nil and the
     * shard count when the generation is intact.
     */
    private function guardScript(): string
    {
        return $this->bloomTypesScript() . <<<'LUA'
local function requireGeneration(infoKey, first, generation)
    local state = redis.call('HMGET', infoKey, '!', 'shards', 'bloom_type')
    if state[1] ~= generation then return redis.error_reply('missing generation state') end
    local base, shards, bloomType = string.sub(infoKey, 1, -5) .. 'bf', state[2], state[3]
    local sharded, count = shards or bloomType, 1
    if sharded then
        if not shards or not string.match(shards, '^[1-9][0-9]*$') or not BLOOM_TYPES[bloomType or ''] then
            return redis.error_reply('missing Bloom filter')
        end
        count = tonumber(shards)
    end
    for i = 0, count - 1 do
        local key = KEYS[first + i]
        if key ~= (sharded and base .. ':' .. i or base) or redis.call('EXISTS', key) ~= 1 then
            return redis.error_reply('missing Bloom filter')
        end
        local found = redis.call('TYPE', key).ok
        if not BLOOM_TYPES[found] or (bloomType and found ~= bloomType) then
            return redis.error_reply('missing Bloom filter')
        end
    end
    return nil, count
end

LUA;
    }
```

- [ ] **Step 5: Implement `reserve()`**

After the sizing check (`throw new InvalidArgumentException('Invalid fastLookup filter sizing.');`), add:

```php
        $shards = self::shardCount($capacity, $rate, $this->shardBytes);
        if ($shards > self::MAX_SHARDS) {
            throw new OverflowException('The fastLookup filter would need more than ' . self::MAX_SHARDS . ' shards.');
        }
```

Replace T1a's reserve `evaluate` with:

```php
        $this->evaluate($this->bloomTypesScript() . <<<'LUA'
if redis.call('HGET', KEYS[1], 'live') == ARGV[1] then return redis.error_reply('a rebuild must use a fresh generation') end
for i = 2, #KEYS do
    if redis.call('EXISTS', KEYS[i]) ~= 0 then return redis.error_reply('generation keys already exist') end
end
local bloomType
for i = 3, #KEYS do
    local reply = redis.pcall('BF.RESERVE', KEYS[i], ARGV[4], ARGV[3], 'NONSCALING')
    local failure = type(reply) == 'table' and reply.err and reply
    if not failure then
        local found = redis.call('TYPE', KEYS[i]).ok
        bloomType = bloomType or found
        if not BLOOM_TYPES[found] or found ~= bloomType then
            failure = redis.error_reply('unsupported Bloom filter type')
        end
    end
    if failure then
        for j = 3, i do redis.call('DEL', KEYS[j]) end
        return failure
    end
end
redis.call('HSET', KEYS[2], '!', ARGV[1], 'capacity', ARGV[8], 'rate', ARGV[4], 'inserted', '0', 'stale', '0', 'buckets', ARGV[5], 'cursor', '0',
    'shards', ARGV[7], 'bloom_type', bloomType, 'p4', string.rep('0', 33), 'p6', string.rep('0', 129), 'pv', '0')
redis.call('HSET', KEYS[1], 'building', ARGV[1], 'building_fingerprint', ARGV[2], 'schema', ARGV[6])
return 1
LUA
            , array_merge([$this->metaKey(), $this->infoKey($generation)], $this->filterKeys($generation, $shards)),
            [$generation, $fingerprint, (string)self::shardCapacity($capacity, $shards), rtrim(sprintf('%.10F', $rate), '0'),
                (string)$buckets, self::SCHEMA, (string)$shards, (string)$capacity]);
```

The rest of `reserve()` (bucket creation and `deleteGenerations`) is unchanged.

- [ ] **Step 6: Implement `add()`, `markStale()`, `setCursor()` and `activate()`**

In `add()`, replace

```php
        $fence = [$this->metaKey(), $this->infoKey($generation), $this->bloomKey($generation)];
```

with

```php
        $fence = $this->fenceKeys($generation);
        $shards = count($fence) - 2;
```

Then replace the `foreach (array_chunk(... self::FILTER_BATCH) ...)` loop with:

```php
        foreach (array_chunk(array_map('strval', array_keys($tokens)), self::FILTER_BATCH) as $chunk) {
            $groups = [];
            foreach ($chunk as $token) { $groups[self::shardOf($token, $shards)][] = $token; }
            ksort($groups);
            $args = [$generation];
            foreach ($groups as $shard => $members) {
                array_push($args, (string)$shard, (string)count($members), ...$members);
            }
            $this->evaluate($this->fenceScript() . <<<'LUA'
local count, i = 0, 2
while i <= #ARGV do
    local shard, n = tonumber(ARGV[i]), tonumber(ARGV[i + 1])
    if not shard or not n or shard < 0 or shard >= SHARDS or n < 1 then return redis.error_reply('malformed filter request') end
    local added = redis.call('BF.MADD', KEYS[3 + shard], unpack(ARGV, i + 2, i + 1 + n))
    for _, flag in ipairs(added) do if flag == 1 then count = count + 1 end end
    i = i + 2 + n
end
redis.call('HINCRBY', KEYS[2], 'inserted', count)
return count
LUA
                , $fence, $args);
        }
```

In `markStale()`, `setCursor()` and `activate()`, replace `[$this->metaKey(), $this->infoKey($generation), $this->bloomKey($generation)]` with `$this->fenceKeys($generation)`. In `markStale()`, keep the `if ($count < 1) { return; }` guard before it.

- [ ] **Step 7: Implement `checkpoint()`**

```php
        $this->evaluate(<<<'LUA'
local schema, known = redis.call('HGET', KEYS[1], 'schema'), false
for i = 3, #ARGV do
    if schema == ARGV[i] then known = true end
end
if not known then return redis.error_reply('index missing') end
if ARGV[2] == '1' and redis.call('HGET', KEYS[1], 'live') == '' then return redis.error_reply('no live generation') end
redis.call('HSET', KEYS[1], 'revision', ARGV[1], 'ready', ARGV[2])
return 1
LUA
            , [$this->metaKey()], array_merge([$revision, $ready ? '1' : '0'], self::SCHEMAS));
```

- [ ] **Step 8: Implement `prefixLengths()` and `generationInfo()`**

In `prefixLengths()`, change the Lua call to `requireGeneration(KEYS[1], 2, ARGV[1])`. Change the keys to:

```php
            , array_merge([$this->infoKey($generation)], $this->filterKeys($generation, $this->shardLayout($generation))), [$generation]);
```

In `generationInfo()`:

```php
    private function generationInfo(string $generation): array
    {
        $shards = $this->shardLayout($generation);
        $reply = $this->evaluate($this->guardScript() . <<<'LUA'
local failure = requireGeneration(KEYS[1], 2, ARGV[1])
if failure then return failure end
return redis.call('HMGET', KEYS[1], 'capacity', 'rate', 'inserted', 'stale', 'buckets', 'cursor', 'p4', 'p6', 'pv')
LUA
            , array_merge([$this->infoKey($generation)], $this->filterKeys($generation, $shards)), [$generation]);
```

The validation that follows is unchanged. Extend the returned array with `'shards' => $shards`.

- [ ] **Step 9: Implement `candidates()`**

Replace the section from `$buckets = $before['generations'][$generation]['buckets'];` through the end of the Lua heredoc and its `, $keys, $args);` with:

```php
        $info = $before['generations'][$generation];
        $buckets = $info['buckets'];
        $filterKeys = $this->filterKeys($generation, $info['shards']);
        $shards = count($filterKeys);
        $count = 0;
        foreach (array_chunk(array_map('strval', array_keys($plan)), self::READ_BATCH_SIZE) as $batch) {
            $keys = array_merge([$this->metaKey(), $this->infoKey($generation)], $filterKeys);
            $args = [$generation, (string)($maximumIds * 21), $prefixVersion ?? '-'];
            foreach ($batch as $token) {
                array_push($args, $token[0] === 'E' ? 0 : $this->keyIndex($keys, $this->postingKey($generation, $token, $buckets)),
                    self::shardOf($token, $shards), $token);
            }
            $reply = $this->evaluate($this->guardScript() . $this->postingScript() . <<<'LUA'
if redis.call('HGET', KEYS[1], 'live') ~= ARGV[1] or redis.call('HGET', KEYS[1], 'ready') ~= '1' then return redis.error_reply('index changed') end
local failure, shards = requireGeneration(KEYS[2], 3, ARGV[1])
if failure then return failure end
if ARGV[3] ~= '-' and (redis.call('HGET', KEYS[2], 'pv') or '') ~= ARGV[3] then return {2} end
local groups, order, count = {}, {}, 0
for i = 4, #ARGV, 3 do
    count = count + 1
    local shard = tonumber(ARGV[i + 1])
    if not shard or shard < 0 or shard >= shards then return redis.error_reply('malformed filter request') end
    if not groups[shard] then
        groups[shard] = {tokens = {}, slots = {}}
        order[#order + 1] = shard
    end
    local group = groups[shard]
    group.tokens[#group.tokens + 1] = ARGV[i + 2]
    group.slots[#group.slots + 1] = count
end
local present = {}
for _, shard in ipairs(order) do
    local group = groups[shard]
    local flags = redis.call('BF.MEXISTS', KEYS[3 + shard], unpack(group.tokens))
    for j, slot in ipairs(group.slots) do present[slot] = flags[j] end
end
local bytes, result = 0, {}
for n = 1, count do
    local keyIndex, token = tonumber(ARGV[1 + 3 * n]), ARGV[3 + 3 * n]
    if present[n] ~= 1 then
        result[n] = false
    elseif keyIndex == 0 then
        result[n] = '1'
    else
        local bucket = KEYS[keyIndex]
        if redis.call('HGET', bucket, '!') ~= ARGV[1] then return redis.error_reply('missing posting bucket') end
        local raw = readPosting(bucket, bucket .. ':' .. hex(token), token, ARGV[1])
        bytes = bytes + #raw
        if bytes > tonumber(ARGV[2]) then return {0} end
        result[n] = raw
    end
end
return {1, result}
LUA
                , $keys, $args);
```

The PHP reply handling that follows is unchanged.

- [ ] **Step 10: Implement `statistics()`**

Replace `$filterBytes = $this->memory($this->bloomKey($generation), $reason);` with:

```php
        $filterBytes = 0;
        foreach ($this->filterKeys($generation, $info['shards']) as $key) {
            $filterBytes = $this->sumMemory($filterBytes, $this->memory($key, $reason));
        }
        $reserved = $info['shards'] === null ? $info['capacity']
            : $info['shards'] * self::shardCapacity($info['capacity'], $info['shards']);
```

In the returned array, use `self::estimatedFalsePositiveRate($reserved, $info['rate'], $info['inserted'])`.

Update the class docblock's first sentence to: `Redis side of fast lookup: one Bloom filter (RedisBloom or valkey-bloom) per generation, split into shards of at most 64 MiB, holding every token, plus append-only postings for IP-range and domain tokens.`

- [ ] **Step 11: Run the unit tests to verify they pass**

Run: `app/Vendor/bin/phpunit app/Test/FastLookupFilterTest.php`
Expected: `OK (121 tests, …)`: 95 from T1a, plus 12 new tests and 14 data-provider cases. Then run `grep -nE 'bloomKey|LEGACY_SCHEMA|BLOOM_TYPE\b' app/Lib/Tools/FastLookupFilter.php`. Expected: no output.

- [ ] **Step 12: Update the contract for the sharded key layout and `bloom-3`**

At the top, after `$legacy = …`, add:

```php
$shardedNamespace = 'contract-' . bin2hex(random_bytes(16));
$shardedPrefix = FastLookupFilter::PREFIX . hash('sha256', $shardedNamespace) . ':';
$bigNamespace = 'contract-' . bin2hex(random_bytes(16));
$bigPrefix = FastLookupFilter::PREFIX . hash('sha256', $bigNamespace) . ':';
$isValkey = isset($redis->info('server')['valkey_version']);
```

In the proxy, add `public $failShardReserve = false;`. Inside `__call`, after the `noBloomCommands` block, add:

```php
        if ($lower === 'eval' && $this->failShardReserve && strpos($args[0], 'BF.RESERVE') !== false) {
            $args[0] = str_replace("redis.pcall('BF.RESERVE', KEYS[i],", "redis.pcall(i == 5 and 'BF.UNLOADEDRESERVE' or 'BF.RESERVE', KEYS[i],", $args[0], $replaced);
            if ($replaced !== 1) { throw new RuntimeException('The reserve script no longer has the shard reserve call.'); }
        }
```

Make these key and schema edits in the existing body. S = 1 for every existing reservation, so the key is `:bf:0`.
- `'bloom-2', 'A reset namespace carries the current schema'` → `'bloom-3'`.
- `[$prefix . 'g:first:bf']` (T1a's type line) → `'g:first:bf:0'`.
- Every `$prefix . 'g:third:bf'` (three places) → `'g:third:bf:0'`.
- `$redis->del($prefix . 'g:sixth:bf');` → `'g:sixth:bf:0'`.
- `$assert(!$redis->exists($prefix . 'g:seventh:bf'), "$message reserves nothing");` → `$assert(!$keys($prefix . 'g:seventh:*'), "$message reserves nothing");`.
- `$bf = $prefix . 'g:eighth:bf';` → `'g:eighth:bf:0'`.
- `['schema' => 'bloom-2', 'building' => 'eighth']` → `'bloom-3'`.
- `$redis->hSet($prefix . 'metadata', 'schema', 'bloom-3');` before "An unknown schema fails closed" → `'bloom-4'`. The restore line after `checkpoint() refuses an unknown schema` → `'bloom-3'`.
- In T1a's type section: `$fifthFilter = $prefix . 'g:fifth:bf:0';`. Replace its last two lines (`$redis->hDel($fifthInfo, 'bloom_type');` and the "built before types were recorded" assertion) with:

```php
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
```

The `$transient` assertions on `$prefix . 'g:fifth:bf'` stay as they are, because `fifth` is now legacy.

Before the `$server = $redis->info('server');` report line, insert:

```php
    // Sharded filters: each token lives in one shard, and every shard is guarded.
    $sharded = new FastLookupFilter($shardedNamespace, $scope, $proxy, 16384);
    $assert(FastLookupFilter::shardCount(100000, 0.01, 16384) === 8, 'The test shard size gives eight shards');
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
    for ($i = 0; $i < 100000; $i += 1000) {
        $rows = [];
        for ($j = $i; $j < $i + 1000; ++$j) {
            $rows[] = ['id' => (string)($j + 1), 'type' => 'domain', 'tokens' => [$shardedTokens[] = $token('E', 'sharded-' . $j)]];
        }
        $sharded->add('s1', $rows);
    }
    $inserted = $sharded->metadata()['generations']['s1']['inserted'];
    $assert($inserted >= 98000 && $inserted <= 100000, "Inserted counts new tokens across shards: $inserted");
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
    $assert($missing === 0, 'No false negatives across 100,000 sharded tokens');
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

    // Default shards keep a large filter under valkey-bloom's 128 MiB object limit.
    $big = new FastLookupFilter($bigNamespace, $scope, $proxy);
    $big->reserve('big', str_repeat('b', 64), 40000000, 0.001, 1);
    $assert($big->metadata()['generations']['big']['shards'] === 2, 'A 40M-token filter takes two shards');
    for ($i = 0; $i < 2; ++$i) {
        $bytes = $redis->rawCommand('MEMORY', 'USAGE', $bigPrefix . 'g:big:bf:' . $i, 'SAMPLES', 0);
        $assert(is_int($bytes) && $bytes > 0 && $bytes < 134217728, "Shard $i stays under 128 MiB: " . json_encode($bytes));
    }
    if ($isValkey) {
        try {
            $single = $redis->rawCommand('BF.RESERVE', $bigPrefix . 'single', '0.001', '80000000', 'NONSCALING');
            $error = (string)$redis->getLastError();
        } catch (RedisException $e) {
            $single = false;
            $error = $e->getMessage();
        }
        $redis->clearLastError();
        $assert($single === false && strpos($error, 'exceeds bloom object memory limit') !== false, 'One 80M-token filter exceeds the default limit: ' . $error);
    }
    foreach (array_chunk($keys($bigPrefix . '*'), 500) as $batch) { $redis->del($batch); }
```

Change the `finally` block so it also removes the two new namespaces:

```php
} finally {
    foreach (array_chunk(array_merge($keys($prefix . '*'), $keys($legacy . '*'), $keys($shardedPrefix . '*'), $keys($bigPrefix . '*')), 500) as $batch) { $redis->del($batch); }
}
```

- [ ] **Step 13: Lint and rerun the unit suite**

Run: `php -l tests/benchmarks/FastLookupFilterRedisContract.php && app/Vendor/bin/phpunit app/Test/FastLookupFilterTest.php`
Expected: no syntax errors, and `OK`.

- [ ] **Step 14: Update the docs**

In `docs/development/fastlookup.md`, **Storage and consistency**, replace

```
Redis holds one RedisBloom filter per generation (key prefix
`misp:fast_lookup:bf1:<sha256(namespace)>:`, no TTL). The filter is a single
`NONSCALING` `BF.RESERVE` holding every exact, range and domain token, sized to
`max(1,000,000, 1.5 × 2 × in-scope attributes)`: two tokens per attribute
headroom at 1.5x, with a 1,000,000-token floor.
```

with

```
Redis holds one Bloom filter per generation (key prefix
`misp:fast_lookup:bf1:<sha256(namespace)>:`, no TTL) holding every exact,
range and domain token, sized to `max(1,000,000, 1.5 × 2 × in-scope
attributes)`: two tokens per attribute headroom at 1.5x, with a
1,000,000-token floor. The filter is split into S `NONSCALING` shards
`g:<generation>:bf:<i>`, where S = max(1, ⌈estimated bytes / 64 MiB⌉) and
estimated bytes = capacity × −ln(rate) / ln(2)² / 8. Each shard reserves
⌈capacity / S⌉ at the configured rate, so every shard keeps that
false-positive rate. A token's shard is its digest bytes 4–7 modulo S; its
posting bucket uses bytes 0–3. valkey-bloom refuses a Bloom object over
128 MiB by default (`bf.bloom-memory-usage-limit`), which a single filter
reaches at about 25M in-scope attributes; 64 MiB shards stay under it with no
configuration. The info hash records the shard count (`shards`) and the
filter type (`bloom_type`); a generation built before sharding has one
filter `g:<generation>:bf` and neither field, and is served as it is.
```

The sentence that followed the old text on its last line (`` `BF.MEXISTS`/`BF.MADD` only prove absence; … ``) now continues the new block, so rewrap the paragraph to 80 columns.

Replace the schema paragraph that starts `Reserving a generation with masks stamps` and ends `served until the next rebuild replaces their generation.` with:

```
Reserving a generation stamps the namespace metadata schema: `bloom-2` added
the IP prefix masks and `bloom-3` the sharded filters. Earlier releases know
only the older values and fail closed on a newer one rather than writing a
generation they cannot read; `bloom-1` and `bloom-2` namespaces keep being
served until the next rebuild replaces their generation.
```

At the end of **Requirements**, append:

```
Redis Cluster and Valkey cluster mode are not supported: every index script
touches several keys of one generation.
```

In **Verification**, replace the three benchmark lines of the code block and the two paragraphs that follow it up to `Use disposable databases` with:

```bash
bash tests/benchmarks/FastLookupIntegration.sh /path/to/cakephp/lib/Cake
MISP_FASTLOOKUP_BACKEND=valkey bash tests/benchmarks/FastLookupIntegration.sh /path/to/cakephp/lib/Cake contract
php tests/benchmarks/FastLookupFilterRedisContract.php /path/to/disposable/redis.sock
FL_PER_TYPE=100000 php tests/benchmarks/FastLookupScale.php /path/to/cakephp/lib/Cake /path/to/disposable/mysql.sock /path/to/disposable/redis.sock
```

```
`FastLookupFilterRedisContract.php` exercises the `BF.*` commands directly, so
it needs Redis 8, Redis Stack or Valkey with valkey-bloom. The shell runner's
second argument picks the runner: `integration` (the default), `contract` or
`scale`. `MISP_FASTLOOKUP_BACKEND=redis|valkey` picks `redis:8.2` or
`valkey/valkey-bundle:8.1`; `MISP_REDIS_IMAGE` still overrides the image,
which must provide a Bloom module.
```

In the next paragraph, change `Its defaults are `localhost/misp-live:tmp`, `mariadb:10.11` and `redis:8`` to `Its defaults are `localhost/misp-live:tmp`, `mariadb:10.11` and `redis:8.2``.

- [ ] **Step 15 (controller): Run the contract on both backends**

Run the same two commands as T1a Step 8.
Expected: both `"status": "passed"`. On Valkey the "One 80M-token filter exceeds the default limit" assertion runs, and on Redis it is skipped.

- [ ] **Step 16: Commit**

```bash
git add app/Lib/Tools/FastLookupFilter.php app/Test/FastLookupFilterTest.php \
    tests/benchmarks/FastLookupFilterRedisContract.php docs/development/fastlookup.md
git commit -S -m "chg: [fastLookup] Shard Bloom filters below Valkey's object limit" \
    -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task T2: Backend-parameterised harness

Work in `~/code/misp-fastlookup-valkey-harness`.

**Files:**
- Create: `tests/benchmarks/FastLookupBackend.php`
- Create: `app/Test/FastLookupBackendVersionsTest.php`
- Modify: `tests/benchmarks/FastLookupIntegration.sh` (whole file)
- Modify: `tests/benchmarks/FastLookupIntegration.php:47` (require), `:437-443` (eviction scan), `:510-511` (versions)
- Modify: `tests/benchmarks/FastLookupScale.php:66` (require), `:334-337` (key classifier), `:475-477` (versions)

**Interfaces:**
- Consumes: T1b's key layout, `g:<gen>:bf` or `g:<gen>:bf:<i>`. Written to match both, so this task does not depend on T1.
- Produces:
  - `function fastLookupBackendVersions($redis): array{backend: 'redis'|'valkey', backend_version: string, redis: ?string, modules: ?array<string,int|string|null>}`;
  - `FastLookupIntegration.sh CAKE_DIR [integration|contract|scale]` with `MISP_FASTLOOKUP_BACKEND` and `MISP_REDIS_IMAGE`;
  - the Scale and Integration JSON `versions` gains `backend`, `backend_version` and `modules`, and keeps `redis`.

- [ ] **Step 1: Write the failing test**

`app/Test/FastLookupBackendVersionsTest.php`:

```php
<?php

use PHPUnit\Framework\TestCase;

class FastLookupBackendVersionsTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/../../tests/benchmarks/FastLookupBackend.php';
    }

    private function server(array $info, $modules)
    {
        return new class($info, $modules) {
            private $info;
            private $modules;
            public function __construct(array $info, $modules)
            {
                $this->info = $info;
                $this->modules = $modules;
            }
            public function info($section) { return $this->info; }
            public function rawCommand(...$args)
            {
                if ($this->modules instanceof Throwable) { throw $this->modules; }
                return $this->modules;
            }
        };
    }

    public function testValkeyIsNamedByItsOwnVersionField(): void
    {
        $versions = fastLookupBackendVersions($this->server(
            ['redis_version' => '7.2.4', 'valkey_version' => '8.1.10'],
            [['name', 'json', 'ver', 10002, 'path', '/x', 'args', []],
             ['name', 'bf', 'ver', 10001, 'path', '/y', 'args', []]]));
        $this->assertSame(['backend' => 'valkey', 'backend_version' => '8.1.10',
            'redis' => '7.2.4', 'modules' => ['bf' => 10001, 'json' => 10002]],
            $versions);
    }

    public function testRedisIsTheDefault(): void
    {
        $versions = fastLookupBackendVersions(
            $this->server(['redis_version' => '8.2.10'], []));
        $this->assertSame(['backend' => 'redis', 'backend_version' => '8.2.10',
            'redis' => '8.2.10', 'modules' => []], $versions);
    }

    public function testRefusedModuleListIsUnknownNotEmpty(): void
    {
        foreach ([false, new RuntimeException('NOPERM')] as $reply) {
            $versions = fastLookupBackendVersions(
                $this->server(['redis_version' => '8.2.10'], $reply));
            $this->assertNull($versions['modules']);
        }
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `app/Vendor/bin/phpunit app/Test/FastLookupBackendVersionsTest.php`
Expected: FAIL. `require_once` warns about a missing file, and every test then errors with `Call to undefined function fastLookupBackendVersions()`.

- [ ] **Step 3: Implement `tests/benchmarks/FastLookupBackend.php`**

```php
<?php
/**
 * The server behind a benchmark's Redis connection: its name, version and
 * loaded modules ('bf' is the Bloom module on both Redis and Valkey).
 */
function fastLookupBackendVersions($redis): array
{
    $server = $redis->info('server');
    $valkey = isset($server['valkey_version']);
    $modules = null;
    try {
        $list = $redis->rawCommand('MODULE', 'LIST');
        if (is_array($list)) {
            $modules = [];
            foreach ($list as $module) {
                if (!is_array($module)) {
                    continue;
                }
                $fields = [];
                for ($i = 0; $i + 1 < count($module); $i += 2) {
                    $fields[$module[$i]] = $module[$i + 1];
                }
                if (isset($fields['name'])) {
                    $modules[$fields['name']] = $fields['ver'] ?? null;
                }
            }
            ksort($modules);
        }
    } catch (Throwable $e) {
    }
    return [
        'backend' => $valkey ? 'valkey' : 'redis',
        'backend_version' => $valkey
            ? $server['valkey_version'] : $server['redis_version'],
        'redis' => $server['redis_version'] ?? null,
        'modules' => $modules,
    ];
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `app/Vendor/bin/phpunit app/Test/FastLookupBackendVersionsTest.php`
Expected: `OK (3 tests, 4 assertions)`.

- [ ] **Step 5: Wire the helper into the Scale and Integration runners**

In both `FastLookupScale.php` and `FastLookupIntegration.php`, after the `App::uses('RedisTool', 'Tools');` line, add:

```php
require_once __DIR__ . '/FastLookupBackend.php';
```

In `FastLookupScale.php`, replace the key-class match:

```php
        if (preg_match('/^g:[^:]+:(bf(?::\d+)?|info|x:\d+:[0-9a-f]+|x:\d+)$/', $rest, $m)) {
            if (strpos($m[1], 'bf') === 0) {
                $class = 'bloom_filter';
            } elseif ($m[1] === 'info') {
                $class = 'global';
            } else {
                $class = substr_count($m[1], ':') === 2 ? 'overflow_postings' : 'postings';
            }
        } else {
```

and the versions:

```php
$report['versions'] = ['php' => PHP_VERSION, 'mariadb' => $pdo->query('SELECT VERSION()')->fetchColumn(),
    'hash_max_listpack' => $redis->config('GET', 'hash-max-listpack-*')] + fastLookupBackendVersions($redis);
```

In `FastLookupIntegration.php`, replace the filter-eviction scan:

```php
    foreach ($redis->scan($cursor, $namespace . 'g:*:bf*', 1000) ?: [] as $key) { $filterKeys[] = $key; }
} while ($cursor !== 0);
same(true, count($filterKeys) >= 1, 'the live Bloom filter exists');
```

and the versions:

```php
$report = ['checks' => $checks, 'versions' => ['php' => PHP_VERSION,
    'mariadb' => $pdo->query('SELECT VERSION()')->fetchColumn()] + fastLookupBackendVersions($redis),
```

- [ ] **Step 6: Lint**

Run: `for f in tests/benchmarks/FastLookupBackend.php tests/benchmarks/FastLookupScale.php tests/benchmarks/FastLookupIntegration.php; do php -l "$f"; done`
Expected: three `No syntax errors detected` lines.

- [ ] **Step 7: Rewrite `tests/benchmarks/FastLookupIntegration.sh`**

```bash
#!/usr/bin/env bash
# Run only disposable local images: no pull, published ports, or production DB.
# Usage: FastLookupIntegration.sh CAKE_DIR [integration|contract|scale]
# MISP_FASTLOOKUP_BACKEND=redis|valkey picks the server; MISP_REDIS_IMAGE
# overrides its image. FL_* variables reach the scale runner.
set -euo pipefail
usage='Usage: FastLookupIntegration.sh /path/to/cakephp/lib/Cake'
usage+=' [integration|contract|scale]'
test_checkout=$(cd "$(dirname "$0")/../.." && pwd)
cake_source=$(cd "${1:?$usage}" && pwd)
mode=${2:-integration}
case "$mode" in
    integration)
        runner=(tests/benchmarks/FastLookupIntegration.php
            /cake /mysql/mysql.sock /redis/redis.sock)
        memory=512M ;;
    contract)
        runner=(tests/benchmarks/FastLookupFilterRedisContract.php
            /redis/redis.sock)
        memory=512M ;;
    scale)
        runner=(tests/benchmarks/FastLookupScale.php
            /cake /mysql/mysql.sock /redis/redis.sock)
        memory=6G ;;
    *) echo "$usage" >&2; exit 2 ;;
esac
backend=${MISP_FASTLOOKUP_BACKEND:-redis}
case "$backend" in
    redis) default_redis_image=docker.io/library/redis:8.2 ;;
    valkey) default_redis_image=docker.io/valkey/valkey-bundle:8.1 ;;
    *) echo 'MISP_FASTLOOKUP_BACKEND must be redis or valkey.' >&2; exit 2 ;;
esac
php_image=${MISP_PHP_IMAGE:-localhost/misp-live:tmp}
db_image=${MISP_MARIADB_IMAGE:-docker.io/library/mariadb:10.11}
redis_image=${MISP_REDIS_IMAGE:-$default_redis_image}
test_dir=$(mktemp -d "${TMPDIR:-/tmp}/misp-fastlookup.XXXXXXXX")
db_container="misp-fastlookup-db-${test_dir##*.}"
redis_container="misp-fastlookup-redis-${test_dir##*.}"
php_container="misp-fastlookup-php-${test_dir##*.}"
cleanup() {
    podman rm --force "$php_container" "$redis_container" "$db_container" \
        >/dev/null 2>&1 || true
    rm -rf "$test_dir"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
chmod 755 "$test_dir"
mkdir "$test_dir/mysql" "$test_dir/redis"
chmod 777 "$test_dir/mysql" "$test_dir/redis"
podman run -d --pull=never --name "$db_container" --network=none \
    -e MARIADB_ALLOW_EMPTY_ROOT_PASSWORD=1 \
    --tmpfs "/var/lib/mysql:rw,size=${MISP_MARIADB_TMPFS:-512m}" \
    -v "$test_dir/mysql:/run/mysqld" "$db_image" \
    --skip-networking --socket=/run/mysqld/mysql.sock >/dev/null
# Given options only, both images' entrypoints start the server with their
# bundled modules; an explicit --loadmodule would load one twice and abort.
podman run -d --pull=never --name "$redis_container" --network=none \
    -v "$test_dir/redis:/socket" "$redis_image" \
    --port 0 --unixsocket /socket/redis.sock --unixsocketperm 777 \
    --save '' --appendonly no >/dev/null
cli='cli=$(command -v valkey-cli || command -v redis-cli) && "$cli"'
cli+=' -s /socket/redis.sock'
ready=false
for attempt in {1..45}; do
    # Ignore the temporary server used during MariaDB initialization.
    if podman exec "$db_container" sh -c 'test "$(cat /proc/1/comm)" = mariadbd && mariadb-admin --socket=/run/mysqld/mysql.sock ping --silent' >/dev/null 2>&1 && \
        podman exec "$redis_container" sh -c "$cli ping" >/dev/null 2>&1; then
        ready=true
        break
    fi
    sleep 1
done
if [[ "$ready" != true ]]; then
    podman logs "$db_container"
    podman logs "$redis_container"
    exit 1
fi
echo "fastLookup backend: $backend ($redis_image)" >&2
podman exec "$redis_container" sh -c "$cli INFO server" \
    | grep -E '^(redis|valkey)_version:' >&2 || true
env_args=()
while IFS= read -r name; do
    case "$name" in FL_OUT|FL_CPU_STAT) continue ;; esac
    env_args+=(-e "$name=${!name}")
done < <(compgen -e | grep '^FL_' || true)
podman run --rm --pull=never --name "$php_container" --network=none \
    --entrypoint php "${env_args[@]}" \
    -v "$test_checkout:/work:ro" -v "$cake_source:/cake:ro" \
    -v "$test_dir/mysql:/mysql" -v "$test_dir/redis:/redis" -w /work \
    "$php_image" -d auto_prepend_file= -d pcov.enabled=0 \
    -d memory_limit="$memory" "${runner[@]}"
```

- [ ] **Step 8: Check the script syntax**

Run: `bash -n tests/benchmarks/FastLookupIntegration.sh && bash tests/benchmarks/FastLookupIntegration.sh app/Lib/cakephp/lib/Cake bogus; echo "exit $?"`
Expected: the usage line is printed, then `exit 2`. No container starts, because the mode is rejected before any `podman` call.

- [ ] **Step 9 (controller): Verify on both backends**

```bash
cd ~/code/misp-fastlookup-valkey-harness
export TMPDIR=$HOME/tmp/fl-valkey/tmp; mkdir -p "$TMPDIR"
C=app/Lib/cakephp/lib/Cake
for b in redis valkey; do
  for m in contract integration; do
    MISP_FASTLOOKUP_BACKEND=$b bash tests/benchmarks/FastLookupIntegration.sh \
      $C $m > ~/tmp/fl-valkey/out/t2-$b-$m.json 2> ~/tmp/fl-valkey/out/t2-$b-$m.log
    echo "$b $m exit $?"
  done
done
FL_PER_TYPE=1000 MISP_FASTLOOKUP_BACKEND=valkey bash \
  tests/benchmarks/FastLookupIntegration.sh $C scale \
  | python3 -c 'import json,sys; print(json.load(sys.stdin)["versions"])'
```

Expected before T1 is merged:
- `redis contract` and `redis integration` exit 0, with `"backend": "redis"` in the integration JSON `versions`;
- both Valkey runs print `fastLookup backend: valkey` and `valkey_version:8.1.x` on stderr, then fail at the base code's `MBbloom--` guard ("Reserve creates a building generation"). That proves the harness reached valkey-bloom;
- the scale line shows `'backend': 'valkey'` and `'modules': {… 'bf': …}` only if the run reaches the report, which it does not before T1. Rerun it after the T4 merge.

- [ ] **Step 10: Commit**

```bash
git add tests/benchmarks/FastLookupBackend.php \
    app/Test/FastLookupBackendVersionsTest.php \
    tests/benchmarks/FastLookupIntegration.sh \
    tests/benchmarks/FastLookupIntegration.php tests/benchmarks/FastLookupScale.php
git commit -S -m "chg: [fastLookup] Run the Redis harness on Redis or Valkey" \
    -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task T3: Prototype C, a module-free bitmap Bloom (throwaway branch)

Work in `~/code/misp-fastlookup-bitmap` on `spike/fastlookup-bitmap-bloom`. This branch is never merged.

**Approach and why:** add an in-place mode to `FastLookupFilter`, selected by `MISP_FASTLOOKUP_FILTER=bitmap` when the object is constructed. The alternatives lose:
- A `FastLookupBitmapFilter` subclass cannot reuse the private helpers (`evaluate`, `guardScript`, key helpers) without making about a dozen of them protected. It would also still need a swap in `FastLookupIndexManager::filter()`, because `AttributeFastLookupTool` builds its own manager and never sees an injected filter.
- The environment variable reaches every construction site (the manager, the tool, and Scale's `$manager->filter()`) and touches one file.
- The branch's diff of `FastLookupFilter.php` then *is* the difficulty measurement for T4.

The existing bloom path is unchanged apart from the guard's call signature. The positions come from spec §4:
- k = round(−log2 p) hashes;
- m = ⌈capacity × −ln p / ln²2⌉ bits;
- h1 = digest bytes 0–3, h2 = bytes 4–7 | 1, and posᵢ = (h1 + i·h2) mod m;
- shard bitmaps `g:<gen>:bm:<i>` of at most 2²⁹ bits (64 MiB);
- the guard checks `TYPE == 'string'` and the expected `STRLEN`.

**Files:**
- Modify: `app/Lib/Tools/FastLookupFilter.php` (spike branch copy)
- Create: `tests/benchmarks/FastLookupBitmapSmoke.php`

**Interfaces:**
- Consumes: the base `FastLookupFilter` (pre-T1: single `g:<gen>:bf`, `BLOOM_TYPE`).
- Produces:
  - `public static $bitmapShardBits = 536870912`
  - `public static function bitmapBits(int $capacity, float $rate): int`
  - `public static function bitmapHashes(float $rate): int`
  - `public static function bitmapPositions(string $token, int $bits, int $hashes): array` (a flat list `[shard, offset, …]`)
  - info fields `bm_bits` and `bm_hashes`
  - `statistics()['filter_bytes']` sums the bitmap shards.
  - T4 reads `filter_bytes`, because Scale files `bm:` keys under `global`.

- [ ] **Step 1: Write the failing smoke test**

`tests/benchmarks/FastLookupBitmapSmoke.php`:

```php
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
```

- [ ] **Step 2 (controller): Run it to verify it fails**

```bash
cd ~/code/misp-fastlookup-bitmap
~/tmp/fl-valkey/up.sh valkey-plain && ~/tmp/fl-valkey/php.sh \
    tests/benchmarks/FastLookupBitmapSmoke.php /redis/redis.sock
```
Expected: FAIL with `Call to undefined method FastLookupFilter::bitmapHashes()`. The implementer can confirm the same locally, without a server, via `php -l` plus the missing methods.

- [ ] **Step 3: Implement the mode, the sizing and the keys**

After `private $redis;`, add:

```php
    /** A module-free bitmap Bloom filter in place of the BF.* commands. */
    private $bitmap;
    public static $bitmapShardBits = 536870912;
```

At the end of the constructor, add `$this->bitmap = getenv('MISP_FASTLOOKUP_FILTER') === 'bitmap';`. At the start of `moduleState()`, add:

```php
        if ($this->bitmap) {
            try { $this->connection()->rawCommand('PING'); } catch (Throwable $e) { return 'unreachable'; }
            return 'available';
        }
```

After `estimatedFalsePositiveRate()`, add:

```php
    public static function bitmapBits(int $capacity, float $rate): int
    {
        return (int)ceil(max(1, $capacity) * -log($rate) / (log(2) ** 2));
    }

    public static function bitmapHashes(float $rate): int
    {
        return max(1, (int)round(-log($rate, 2)));
    }

    /** Flat [shard, offset, ...] by double hashing: h1 = digest bytes 0-3, h2 = bytes 4-7 | 1. */
    public static function bitmapPositions(string $token, int $bits, int $hashes): array
    {
        $h1 = unpack('N', $token, 1)[1];
        $h2 = unpack('N', $token, 5)[1] | 1;
        $pairs = [];
        for ($i = 0; $i < $hashes; ++$i) {
            $position = ($h1 + $i * $h2) % $bits;
            $pairs[] = intdiv($position, self::$bitmapShardBits);
            $pairs[] = $position % self::$bitmapShardBits;
        }
        return $pairs;
    }
```

Next to `bloomKey()`, add:

```php
    /** The generation's filter keys: its BF key, or its bitmap shards. */
    private function filterKeys(string $generation, ?int $bits = null): array
    {
        if (!$this->bitmap) {
            return [$this->bloomKey($generation)];
        }
        $bits = $bits ?? $this->bitmapLayout($generation)[0];
        $keys = [];
        for ($i = 0, $n = intdiv($bits + self::$bitmapShardBits - 1, self::$bitmapShardBits); $i < $n; ++$i) {
            $keys[] = $this->generationPrefix($generation) . 'bm:' . $i;
        }
        return $keys;
    }

    /** [bits, hashes] a bitmap generation records. */
    private function bitmapLayout(string $generation): array
    {
        $state = $this->call('hMGet', [$this->infoKey($generation), ['!', 'bm_bits', 'bm_hashes']]);
        if (!is_array($state)) {
            throw new FastLookupIndexUnavailableException('Redis could not read the fastLookup generation state.');
        }
        $bits = (string)($state['bm_bits'] ?? '');
        $hashes = (string)($state['bm_hashes'] ?? '');
        if (($state['!'] ?? false) !== $generation || !ctype_digit($bits) || !ctype_digit($hashes) || (int)$bits < 1 || (int)$hashes < 1) {
            throw new FastLookupIndexCorruptException('A fastLookup generation is missing.');
        }
        return [(int)$bits, (int)$hashes];
    }
```

- [ ] **Step 4: Change the guard to take a first-key index, and add the bitmap guard**

Replace `guardScript()` with:

```php
    private function guardScript(): string
    {
        if ($this->bitmap) {
            return 'local SHARD_BITS = ' . self::$bitmapShardBits . "\n" . <<<'LUA'
local function requireGeneration(infoKey, first, generation)
    local state = redis.call('HMGET', infoKey, '!', 'bm_bits')
    if state[1] ~= generation then return redis.error_reply('missing generation state') end
    local bits = tonumber(state[2])
    if not bits or bits < 1 then return redis.error_reply('missing Bloom filter') end
    local base, shards = string.sub(infoKey, 1, -5) .. 'bm:', math.ceil(bits / SHARD_BITS)
    for i = 0, shards - 1 do
        local key = KEYS[first + i]
        local size = math.ceil(math.min(SHARD_BITS, bits - i * SHARD_BITS) / 8)
        if key ~= base .. i or redis.call('TYPE', key).ok ~= 'string' or redis.call('STRLEN', key) ~= size then
            return redis.error_reply('missing Bloom filter')
        end
    end
    return nil, shards
end

LUA;
        }
        return "local BLOOM_TYPE = '" . self::BLOOM_TYPE . "'\n" . <<<'LUA'
local function requireGeneration(infoKey, first, generation)
    if redis.call('HGET', infoKey, '!') ~= generation then return redis.error_reply('missing generation state') end
    local bloomKey = KEYS[first]
    if redis.call('EXISTS', bloomKey) ~= 1 or redis.call('TYPE', bloomKey).ok ~= BLOOM_TYPE then return redis.error_reply('missing Bloom filter') end
    return nil, 1
end

LUA;
    }
```

In `fenceScript()`, replace the call with `local failure, SHARDS = requireGeneration(KEYS[2], 3, ARGV[1])`. Change the other call sites:
- `prefixLengths()` and `generationInfo()`: `requireGeneration(KEYS[1], 2, ARGV[1])`, with keys `array_merge([$this->infoKey($generation)], $this->filterKeys($generation))`;
- `candidates()` bloom script: `requireGeneration(KEYS[2], 3, ARGV[1])`;
- `markStale()`, `setCursor()` and `activate()`: keys `array_merge([$this->metaKey(), $this->infoKey($generation)], $this->filterKeys($generation))`;
- `statistics()`: `$filterBytes = 0; foreach ($this->filterKeys($generation) as $key) { $filterBytes = $this->sumMemory($filterBytes, $this->memory($key, $reason)); }`.

- [ ] **Step 5: Add the bitmap reserve**

In `reserve()`, wrap the existing `BF.RESERVE` `evaluate` in `if (!$this->bitmap) { … } else { … }`. Pull `$rateString = rtrim(sprintf('%.10F', $rate), '0');` out above it, and use it in both branches. The bitmap branch:

```php
            $bits = self::bitmapBits($capacity, $rate);
            $this->evaluate(<<<'LUA'
if redis.call('HGET', KEYS[1], 'live') == ARGV[1] then return redis.error_reply('a rebuild must use a fresh generation') end
for i = 2, #KEYS do
    if redis.call('EXISTS', KEYS[i]) ~= 0 then return redis.error_reply('generation keys already exist') end
end
local bits, shardBits = tonumber(ARGV[7]), tonumber(ARGV[9])
for i = 3, #KEYS do
    local size = math.ceil(math.min(shardBits, bits - (i - 3) * shardBits) / 8)
    redis.call('SETRANGE', KEYS[i], size - 1, string.char(0))
end
redis.call('HSET', KEYS[2], '!', ARGV[1], 'capacity', ARGV[3], 'rate', ARGV[4], 'inserted', '0', 'stale', '0', 'buckets', ARGV[5], 'cursor', '0',
    'bm_bits', ARGV[7], 'bm_hashes', ARGV[8], 'p4', string.rep('0', 33), 'p6', string.rep('0', 129), 'pv', '0')
redis.call('HSET', KEYS[1], 'building', ARGV[1], 'building_fingerprint', ARGV[2], 'schema', ARGV[6])
return 1
LUA
                , array_merge([$this->metaKey(), $this->infoKey($generation)], $this->filterKeys($generation, $bits)),
                [$generation, $fingerprint, (string)$capacity, $rateString, (string)$buckets, self::SCHEMA,
                    (string)$bits, (string)self::bitmapHashes($rate), (string)self::$bitmapShardBits]);
```

- [ ] **Step 6: Add the bitmap add**

In `add()`, compute the fence:

```php
        $layout = $this->bitmap ? $this->bitmapLayout($generation) : null;
        $fence = array_merge([$this->metaKey(), $this->infoKey($generation)], $this->filterKeys($generation, $layout[0] ?? null));
```

Wrap the existing `BF.MADD` chunk loop in `if (!$this->bitmap) { … } else { … }`. The bitmap branch:

```php
            [$bits, $hashes] = $layout;
            foreach (array_chunk(array_map('strval', array_keys($tokens)), self::FILTER_BATCH) as $chunk) {
                $args = [$generation, (string)$hashes];
                foreach ($chunk as $token) { array_push($args, ...self::bitmapPositions($token, $bits, $hashes)); }
                $this->evaluate($this->fenceScript() . <<<'LUA'
local k, count, i = tonumber(ARGV[2]), 0, 3
while i <= #ARGV do
    local fresh = false
    for j = i, i + 2 * k - 1, 2 do
        local shard = tonumber(ARGV[j])
        if shard >= SHARDS then return redis.error_reply('malformed filter request') end
        if redis.call('SETBIT', KEYS[3 + shard], ARGV[j + 1], 1) == 0 then fresh = true end
    end
    if fresh then count = count + 1 end
    i = i + 2 * k
end
redis.call('HINCRBY', KEYS[2], 'inserted', count)
return count
LUA
                    , $fence, $args);
            }
```

- [ ] **Step 7: Add the bitmap candidates**

Before the batch loop in `candidates()`, add:

```php
        [$bits, $hashes] = $this->bitmap ? $this->bitmapLayout($generation) : [0, 0];
        $filterKeys = $this->bitmap ? $this->filterKeys($generation, $bits) : [$this->bloomKey($generation)];
```

Inside the loop, use `$keys = array_merge([$this->metaKey(), $this->infoKey($generation)], $filterKeys);`. Replace the argument build and the `evaluate` call with:

```php
            $args = [$generation, (string)($maximumIds * 21), $prefixVersion ?? '-'];
            if ($this->bitmap) { $args[] = (string)$hashes; }
            foreach ($batch as $token) {
                array_push($args, $token[0] === 'E' ? 0 : $this->keyIndex($keys, $this->postingKey($generation, $token, $buckets)), $token);
                if ($this->bitmap) { array_push($args, ...self::bitmapPositions($token, $bits, $hashes)); }
            }
            $reply = $this->evaluate($this->guardScript() . $this->postingScript() . ($this->bitmap ? self::BITMAP_CANDIDATES : self::BLOOM_CANDIDATES), $keys, $args);
```

Move the existing candidates Lua body, unchanged apart from the guard call, into `const BLOOM_CANDIDATES = <<<'LUA' … LUA;`. Add:

```php
    const BITMAP_CANDIDATES = <<<'LUA'
if redis.call('HGET', KEYS[1], 'live') ~= ARGV[1] or redis.call('HGET', KEYS[1], 'ready') ~= '1' then return redis.error_reply('index changed') end
local failure, shards = requireGeneration(KEYS[2], 3, ARGV[1])
if failure then return failure end
if ARGV[3] ~= '-' and (redis.call('HGET', KEYS[2], 'pv') or '') ~= ARGV[3] then return {2} end
local k = tonumber(ARGV[4])
local stride = 2 + 2 * k
local offsets, owners, missing, count = {}, {}, {}, 0
for i = 5, #ARGV, stride do
    count = count + 1
    for j = i + 2, i + stride - 1, 2 do
        local shard = tonumber(ARGV[j])
        if shard >= shards then return redis.error_reply('malformed filter request') end
        if not offsets[shard] then offsets[shard], owners[shard] = {}, {} end
        offsets[shard][#offsets[shard] + 1] = ARGV[j + 1]
        owners[shard][#owners[shard] + 1] = count
    end
end
for shard, list in pairs(offsets) do
    for s = 1, #list, 1000 do
        local ops = {}
        for j = s, math.min(s + 999, #list) do
            ops[#ops + 1] = 'GET'; ops[#ops + 1] = 'u1'; ops[#ops + 1] = list[j]
        end
        local bits = redis.call('BITFIELD_RO', KEYS[3 + shard], unpack(ops))
        for j, bit in ipairs(bits) do
            if bit == 0 then missing[owners[shard][s + j - 1]] = true end
        end
    end
end
local bytes, result = 0, {}
for n = 1, count do
    local at = 5 + (n - 1) * stride
    local keyIndex, token = tonumber(ARGV[at]), ARGV[at + 1]
    if missing[n] then
        result[n] = false
    elseif keyIndex == 0 then
        result[n] = '1'
    else
        local bucket = KEYS[keyIndex]
        if redis.call('HGET', bucket, '!') ~= ARGV[1] then return redis.error_reply('missing posting bucket') end
        local raw = readPosting(bucket, bucket .. ':' .. hex(token), token, ARGV[1])
        bytes = bytes + #raw
        if bytes > tonumber(ARGV[2]) then return {0} end
        result[n] = raw
    end
end
return {1, result}
LUA;
```

This is one `BITFIELD_RO` per shard per 1,000 offsets, not k `GETBIT` calls per token. That keeps C's lookup cost a property of the approach, not of the prototype.

- [ ] **Step 8: Lint and check that the bloom mode is unchanged**

Run: `php -l app/Lib/Tools/FastLookupFilter.php && php -l tests/benchmarks/FastLookupBitmapSmoke.php && app/Vendor/bin/phpunit app/Test/FastLookupFilterTest.php`
Expected: no syntax errors, and `OK (91 tests, 118 assertions)`, the same as the base.

- [ ] **Step 9 (controller): Run the smoke on both plain servers**

```bash
cd ~/code/misp-fastlookup-bitmap
for kv in redis-plain valkey-plain; do
  ~/tmp/fl-valkey/up.sh $kv && ~/tmp/fl-valkey/php.sh \
      tests/benchmarks/FastLookupBitmapSmoke.php /redis/redis.sock
done
```
Expected: two reports with `"status": "passed"`, one for `redis` and one for `valkey`.

- [ ] **Step 10: Commit (spike branch only)**

```bash
git add app/Lib/Tools/FastLookupFilter.php tests/benchmarks/FastLookupBitmapSmoke.php
git commit -S -m "chg: [fastLookup] Prototype a module-free bitmap Bloom filter" \
    -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task T4 (controller): Merge, both-backend suites, benchmarks, report

**Files:**
- Create: `~/tmp/fl-valkey/extract.py`
- Output: `~/tmp/fl-valkey/out/{A-redis,A-valkey,C-redis,C-valkey}.json`, `summary.json`, `difficulty.txt`, and an HTML report artifact

**Interfaces:**
- Consumes:
  - T1's key layout and `metadata()['generations'][g]['shards']`;
  - T2's `versions.backend`, `backend_version` and `modules`, and the Integration.sh modes;
  - T3's `MISP_FASTLOOKUP_FILTER=bitmap` and `statistics.filter_bytes`;
  - T0's scripts.
- Produces: the comparison report (spec §5).

- [ ] **Step 1: Merge T2 into the T1 branch and into the spike**

```bash
BASE=$(cat ~/tmp/fl-valkey-base.txt)
git -C ~/code/misp-fastlookup-valkey merge --no-ff -S \
    feature/attributes-fast-lookup-valkey-harness \
    -m "chg: [fastLookup] Merge the backend-parameterised harness" \
    -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
git -C ~/code/misp-fastlookup-bitmap merge --no-ff -S \
    feature/attributes-fast-lookup-valkey-harness \
    -m "chg: [fastLookup] Merge the backend-parameterised harness" \
    -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```
Expected: both merge cleanly, because the file sets are disjoint.

- [ ] **Step 2: Run the pure suites on the merged branch**

```bash
cd ~/code/misp-fastlookup-valkey
app/Vendor/bin/phpunit app/Test/FastLookupFilterTest.php
app/Vendor/bin/phpunit app/Test/FastLookupBackendVersionsTest.php
```
Expected: both `OK`.

- [ ] **Step 3: Run the suites on both backends**

```bash
cd ~/code/misp-fastlookup-valkey
export TMPDIR=$HOME/tmp/fl-valkey/tmp; mkdir -p "$TMPDIR"
for b in redis valkey; do
  for m in contract integration; do
    MISP_FASTLOOKUP_BACKEND=$b bash tests/benchmarks/FastLookupIntegration.sh \
      app/Lib/cakephp/lib/Cake $m > ~/tmp/fl-valkey/out/merged-$b-$m.json \
      2> ~/tmp/fl-valkey/out/merged-$b-$m.log || echo "FAIL $b $m"
  done
done
H=$HOME/tmp/fl-valkey
for kv in redis-bloom valkey-bloom; do
  $H/up.sh $kv && podman run --rm --pull=never --network=none \
    --entrypoint php -v "$PWD:/work" \
    -v "$H/sock/mysql:/mysql" -v "$H/sock/redis:/redis" \
    -v "$H/zz-override.ini:/usr/local/etc/php/conf.d/zz-override.ini:ro" \
    -e MISP_FASTLOOKUP_LIFECYCLE_SOCKET=/mysql/mysql.sock \
    -e MISP_FASTLOOKUP_REDIS_SOCKET=/redis/redis.sock -w /work \
    localhost/misp-live:tmp -d display_errors=stderr \
    app/Vendor/bin/phpunit --filter \
    'FastLookup(DeletionIntegration|IndexLifecycleIntegration|SqlCollation)Test' \
    app/Test/ || echo "FAIL lifecycle $kv"
done
```
Expected:
- no `FAIL` lines;
- every contract JSON has `"status": "passed"`;
- integration JSON `versions.backend` reads `redis` and `valkey` respectively, and `versions.modules.bf` is present in both;
- the lifecycle PHPUnit group passes on both servers.

- [ ] **Step 4: Write `~/tmp/fl-valkey/extract.py`**

```python
#!/usr/bin/env python3
"""extract.py RUN.json... > summary.json: the spec §5 metrics side by side.

Only results_sha256 must agree across configurations; candidate counts and
measured false-positive rates legitimately differ between hash families.
"""
import json
import sys

runs = {p.rsplit('/', 1)[-1].removesuffix('.json'): json.load(open(p))
        for p in sys.argv[1:]}
names = list(runs)
summary = {'configs': {}, 'totals': {}, 'lookups': {},
           'digests_identical': True}
for name, r in runs.items():
    st = r.get('statistics') or {}
    summary['configs'][name] = {
        'versions': r['versions'],
        'build_seconds_total': r['build_seconds_total'],
        'redis_bytes_total': r['redis_bytes_total'],
        'redis_bytes_per_attribute': r['redis_bytes_per_attribute'],
        'filter_bytes': st.get('filter_bytes'),
        'filter_bytes_per_attribute': (
            round(st['filter_bytes'] / r['parameters']['per_type']
                  / len(r['types']), 2)
            if st.get('filter_bytes') and 'parameters' in r
            and 'per_type' in r['parameters'] else None),
        'posting_bytes': st.get('posting_bytes'),
        'capacity': st.get('capacity'),
        'inserted': st.get('inserted'),
        'estimated_false_positive_rate':
            st.get('estimated_false_positive_rate'),
        'measured_false_positive_rate': r['measured_false_positive_rate'],
    }
    summary['totals'][name] = {}
for t in runs[names[0]]['types']:
    for kind in ('hits', 'misses', 'expansions'):
        row, digests = {}, set()
        for name, r in runs.items():
            lk = r['types'].get(t, {}).get('lookups', {}).get(kind)
            if not lk:
                continue
            cpu = lk['cpu_seconds']
            row[name] = {'seconds': lk['seconds'],
                         'cpu_total': cpu.get('total'),
                         'cpu_redis': cpu.get('redis'),
                         'candidates_seconds': lk['candidates_seconds'],
                         'matched': lk['matched']}
            digests.add(lk['results_sha256'])
            tot = summary['totals'][name].setdefault(
                kind, {'seconds': 0.0, 'cpu_total': 0.0, 'cpu_redis': 0.0})
            tot['seconds'] += lk['seconds']
            tot['cpu_total'] += cpu.get('total') or 0.0
            tot['cpu_redis'] += cpu.get('redis') or 0.0
        if not row:
            continue
        same = len(digests) == 1 and len(row) == len(runs)
        summary['digests_identical'] &= same
        summary['lookups'][f'{t}/{kind}'] = {'runs': row,
                                             'digest_identical': same}
json.dump(summary, sys.stdout, indent=2)
print('DIGESTS', 'IDENTICAL' if summary['digests_identical'] else 'DIFFER',
      file=sys.stderr)
```

Before relying on `filter_bytes_per_attribute`, check the key name in the existing gate output. If the Scale report's `parameters` names the per-type count differently, `filter_bytes_per_attribute` stays `null`. In that case divide `filter_bytes` by 1,700,000 by hand.

```bash
python3 -c "import json;print(json.load(open('$HOME/tmp/fl-batched/out/gate-new.json'))['parameters'])"
```

- [ ] **Step 5: Run the four benchmarks, one at a time on a quiet host**

Check the quota first (`jobs-helper.sh quota`). Each run is a full 1.7M-attribute load, build and lookup set.

```bash
A=~/code/misp-fastlookup-valkey; C=~/code/misp-fastlookup-bitmap
H=~/tmp/fl-valkey
$H/up.sh redis-bloom  && $H/gate.sh $A A-redis  > $H/out/A-redis.log 2>&1
$H/up.sh valkey-bloom && $H/gate.sh $A A-valkey > $H/out/A-valkey.log 2>&1
$H/up.sh redis-plain  && MISP_FASTLOOKUP_FILTER=bitmap \
    $H/gate.sh $C C-redis  > $H/out/C-redis.log 2>&1
$H/up.sh valkey-plain && MISP_FASTLOOKUP_FILTER=bitmap \
    $H/gate.sh $C C-valkey > $H/out/C-valkey.log 2>&1
$H/down.sh
```
Expected:
- four JSON files;
- `versions.backend` is `redis`, `valkey`, `redis` and `valkey`;
- `versions.modules` has `bf` for the A runs. For the C runs, record whatever it shows. `redis:8.2` may still list `bf` when its entrypoint is bypassed; C never calls it, but note it in the report.

- [ ] **Step 6: Extract the metrics and the difficulty figures**

```bash
H=~/tmp/fl-valkey; BASE=$(cat ~/tmp/fl-valkey-base.txt)
python3 $H/extract.py $H/out/A-redis.json $H/out/A-valkey.json \
    $H/out/C-redis.json $H/out/C-valkey.json > $H/out/summary.json
{
  echo "== A code";  git -C ~/code/misp-fastlookup-valkey diff --numstat \
      $BASE..HEAD -- app/Lib/Tools/FastLookupFilter.php
  echo "== A tests"; git -C ~/code/misp-fastlookup-valkey diff --numstat \
      $BASE..HEAD -- app/Test/FastLookupFilterTest.php \
      tests/benchmarks/FastLookupFilterRedisContract.php
  echo "== harness"; git -C ~/code/misp-fastlookup-valkey diff --numstat \
      $BASE..HEAD -- tests/benchmarks/FastLookupIntegration.sh \
      tests/benchmarks/FastLookupIntegration.php \
      tests/benchmarks/FastLookupScale.php \
      tests/benchmarks/FastLookupBackend.php \
      app/Test/FastLookupBackendVersionsTest.php
  echo "== C code";  git -C ~/code/misp-fastlookup-bitmap diff --numstat \
      $BASE..HEAD -- app/Lib/Tools/FastLookupFilter.php
  echo "== C tests"; git -C ~/code/misp-fastlookup-bitmap diff --numstat \
      $BASE..HEAD -- tests/benchmarks/FastLookupBitmapSmoke.php
} > $H/out/difficulty.txt
```
Expected: stderr prints `DIGESTS IDENTICAL`. If it prints `DIFFER`, stop and investigate before reporting. Different results mean a correctness bug, not a performance finding.

- [ ] **Step 7: Build and publish the HTML report**

Load the `artifact-design` skill first, then write `~/tmp/fl-valkey/report.html` from `summary.json` and `difficulty.txt`, and publish it with the Artifact tool. Sections:
1. **Answer first:** can one code path serve RedisBloom and valkey-bloom (yes or no, with the evidence), at what cost, and is C worth pursuing.
2. **Configurations:** backend, version and modules for each of the four runs, the host, and `FL_RUNS=3`.
3. **Build and memory:**
   - build seconds;
   - Redis/Valkey bytes per attribute;
   - filter bytes (C comes from `statistics.filter_bytes`);
   - posting bytes;
   - estimated and measured FP rate.
4. **Lookups:**
   - per-type hits, misses and expansions, as wall and CPU (total and redis), with totals per kind;
   - the digest-identity table.
5. **Sharding evidence:**
   - at 1.7M attributes (capacity 5.1M), S = 1 in every A run, so the benchmark does not exercise sharding;
   - cite the contract's 8-shard seam case and the 40M two-shard reserve (both backends), plus Valkey's refused single 80M reserve.
6. **Difficulty:**
   - lines changed, from `difficulty.txt`;
   - new moving parts: the layout `HMGET`, the guard key-name check, the reserve rollback, the schema bump, and for C the PHP hashing plus `BITFIELD_RO`;
   - operator requirements: a module or none, the image, no configuration;
   - failure modes: partial layout, shard eviction, and for C, `STRLEN` drift;
   - test surface.
7. **Caveats:**
   - one host;
   - the C prototype is unfenced by a `TYPE` distinct from plain strings, so any string of the right length passes its guard;
   - the 503 message still names RedisBloom only;
   - cluster mode is out of scope.

Give a chat summary of the same answer, with the report link.

---

## Spec coverage map

| Spec item | Task |
|---|---|
| §1 `BLOOM_TYPES`, TYPE check at reserve (fail closed, nothing kept), `bloom_type` recorded, guard allowlist and recorded type, legacy against allowlist | T1a; per-shard form in T1b |
| §2 S formula, `SHARD_BYTES`, `ceil(capacity/S)`, `bf:<i>` keys, digest bytes 4–7, legacy S = 1, grouped `BF.MADD`/`BF.MEXISTS`, `inserted` across shards, per-shard guard, `filter_bytes` sum, FP from total, `bloom-3`, accept 1/2/3, test seam | T1b |
| §3 unit tests (shard maths, allowlist, legacy, schemas) | T1a, T1b |
| §3 backend-parameterised runners (`MISP_FASTLOOKUP_BACKEND`, `MISP_REDIS_IMAGE`) | T2 (sh modes; the contract and scale go through it); T0 for full-scale |
| §3 contract additions (S > 1 via seam, deleted/foreign shard, `bloom_type`, foreign type, legacy, Valkey large reserve) | T1a, T1b |
| §3 docs (backends, shard layout, Valkey note, cluster) | T1a, T1b |
| §4 bitmap prototype (k, m, double hashing, `bm:<i>` ≤ 64 MiB, SETBIT/GETBIT-family Lua, string + STRLEN guard, smoke subset) | T3 |
| §5 four configurations, metrics, difficulty, conclusion, HTML report + chat summary | T0, T4 |
| Execution (parallel T1/T2/T3, merge T2 into T1, both-backend suites, then T4) | ownership table, T4 Steps 1–3 |

## Resolved spec ambiguities

- **"30M-capacity reserve (S = 2)"**: 30M at 0.001 estimates about 51 MiB, which gives S = 1. The S = 2 boundary is about 37.3M. The contract therefore reserves **40M** (S = 2, about 36 MB per shard) on both backends. On Valkey it also asserts that a single raw 80M `BF.RESERVE` is refused, as the spec's evidence says.
- **"A namespace whose generation is sharded"**: every generation new code reserves uses the sharded layout (`shards` ≥ 1, `bf:<i>`), even when S = 1, and stamps `bloom-3`. Only generations built before this change are unsharded.
- **The contract file's owner**: T1 owns the whole file, including its backend or version report line. T2 owns the Integration and Scale PHP, including the two lines the key rename would break. T2 writes those lines to match both layouts.
- **The contract and scale "runners"**: the spec names them. They are modes of `FastLookupIntegration.sh`, not new scripts. Full-scale T4 runs use the persistent `~/tmp/fl-valkey` services.
- **T1 split**: T1a covers types (one key, `bloom-2`, still readable by the batched release on Redis). T1b covers shards and `bloom-3`.
- **C's selection mechanism**: an environment variable read in `FastLookupFilter`'s constructor (see T3). The manager, the tool and Scale are untouched.
- **Out of scope and left unchanged**: the `missing` 503 text ("requires the RedisBloom module (Redis 8 or Redis Stack)") lives in `FastLookupIndexManager.php`, which no task owns. T4 lists it as a follow-up.
