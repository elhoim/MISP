<?php

use PHPUnit\Framework\TestCase;

/**
 * Framework-free, Redis-free coverage of FastLookupFilter's validation paths:
 * everything the class rejects before it would touch Redis. No container,
 * no CakePHP bootstrap — safe to run in plain CI PHPUnit.
 *
 * Ported from the deleted app/Test/FastLookupIndexTest.php (see
 * `git show 28b587103^:app/Test/FastLookupIndexTest.php`) onto
 * FastLookupFilter's current API. Divergences from the old test are called
 * out per case below; a couple of old cases have no equivalent any more
 * because FastLookupFilter validates less than FastLookupIndex did (no
 * attribute `type` check in add(), no batch-size cap) — those are listed at
 * the bottom instead of being silently dropped.
 */
class FastLookupFilterTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/../Lib/Tools/FastLookupFilter.php';
    }

    /** A connection double that fails any call, so a test only passes when validation throws first. */
    private function disconnected()
    {
        return new class {
            public function __call($method, $args)
            {
                throw new RuntimeException('Disconnected.');
            }
        };
    }

    private function filter($scope = null, $redis = null, int $shardBytes = FastLookupFilter::SHARD_BYTES)
    {
        return new FastLookupFilter('test-database', $scope ?? ['attribute_types' => ['domain'], 'published_only' => true],
            $redis ?? $this->disconnected(), $shardBytes);
    }

    private function token(string $prefix): string
    {
        return $prefix . str_repeat('x', 8);
    }

    // -- constructor / scope -------------------------------------------------

    /** @dataProvider invalidScopes */
    public function testInvalidScopeCannotConstructAFilter($scope): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->filter($scope);
    }

    public function invalidScopes(): array
    {
        return [
            'empty scope' => [[]],
            'empty type list' => [['attribute_types' => []]],
            'type list not an array' => [['attribute_types' => 'domain']],
            'empty type string' => [['attribute_types' => ['']]],
            'non-string type' => [['attribute_types' => [42]]],
            'type with a null byte' => [['attribute_types' => ["domain\0hostname"]]],
            'type over 255 bytes' => [['attribute_types' => [str_repeat('a', 256)]]],
            'published_only not boolean' => [['attribute_types' => ['domain'], 'published_only' => 'true']],
        ];
    }

    // -- metadata() with no usable Redis -------------------------------------

    public function testUnavailableRedisCannotProduceEmptyMetadata(): void
    {
        $this->expectException(FastLookupIndexUnavailableException::class);
        $this->filter()->metadata();
    }

    // -- add(): malformed prepared rows and tokens ---------------------------

    /**
     * @dataProvider invalidPreparedRows
     * Ported from FastLookupIndexTest::invalidAttributes. The old
     * addAttributes(generation, revision, rows) also rejected a row whose
     * 'type' disagreed with the scope (e.g. type 'ip-src' in a domain-only
     * scope); FastLookupFilter::add() has no revision parameter and no
     * longer looks at 'type' at all (id/tokens only), so that case is
     * dropped here rather than faked into a false pass.
     */
    public function testMalformedPreparedRowsAreRejectedBeforeAnyRedisWrite($row): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->filter()->add('generation', [$row]);
    }

    public function invalidPreparedRows(): array
    {
        $token = $this->token('E');
        return [
            'empty row' => [[]],
            'id with leading zero digit only' => [['id' => '0', 'tokens' => [$token]]],
            'id with leading zero' => [['id' => '01', 'tokens' => [$token]]],
            'id not a string' => [['id' => 1, 'tokens' => [$token]]],
            'tokens not an array' => [['id' => '1', 'tokens' => 'not-an-array']],
            'token of the wrong length' => [['id' => '1', 'tokens' => ['example.org']]],
            'token with an unknown kind prefix' => [['id' => '1', 'tokens' => [$this->token('X')]]],
            'token 9 bytes over budget' => [['id' => '1', 'tokens' => [str_repeat('E', 18)]]],
        ];
    }

    public function testOversizedAttributeFailsRatherThanDroppingTokens(): void
    {
        $this->expectException(OverflowException::class);
        $this->filter()->add('generation', [['id' => '1', 'tokens' => array_fill(0, 1025, $this->token('E'))]]);
    }

    // -- identifier() validation across call sites ---------------------------

    /**
     * @dataProvider invalidIdentifiers
     * add() validates its $generation argument with identifier() before it
     * ever reaches Redis, so this exercises the shared identifier() guard
     * (empty, disallowed characters, over 128 bytes, embedded NUL) without
     * needing a connection.
     */
    public function testInvalidGenerationIsRejectedBeforeAnyRedisCall($generation): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->filter()->add($generation, []);
    }

    public function invalidIdentifiers(): array
    {
        return [
            'empty string' => [''],
            'contains a space' => ['bad generation'],
            'over 128 bytes' => [str_repeat('a', 129)],
            'embedded NUL' => ["gen\0eration"],
        ];
    }

    public function testInvalidGenerationIsRejectedByCandidatesBeforeTheBudgetOrPlanIsChecked(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->filter()->candidates('bad generation', []);
    }

    public function testInvalidGenerationIsRejectedByActivateBeforeAnyRedisCall(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->filter()->activate('bad generation', 'fingerprint');
    }

    public function testInvalidRevisionIsRejectedByCheckpointBeforeAnyRedisCall(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->filter()->checkpoint('bad revision', true);
    }

    public function testInvalidCursorIsRejectedBySetCursor(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->filter()->setCursor('generation', 'not-a-decimal-id');
    }

    // -- candidates(): invalid query plans ------------------------------------

    /**
     * @dataProvider invalidQueryPlans
     * Ported from FastLookupIndexTest::invalidQueries onto the current
     * candidates(generation, queryTokens, maximumIds) shape, whose entries
     * are ['token' => ..., 'kind' => ...] (the old 'type' key is gone).
     * All of this validation runs before candidates() calls metadata(), so
     * the disconnected double never gets invoked.
     */
    public function testInvalidQueryPlanCannotBeInterpretedAsANegativeMatch($plan): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->filter()->candidates('generation', $plan);
    }

    public function invalidQueryPlans(): array
    {
        return [
            'position value is not a token list' => [[null]],
            'entry is not an array' => [[['not-an-array']]],
            'entry missing token/kind' => [[[[]]]],
            'entry token has the wrong shape' => [[[['token' => 'bad', 'kind' => 'exact']]]],
            'entry kind disagrees with the token prefix' => [[[['token' => $this->token('E'), 'kind' => 'domain']]]],
        ];
    }

    /** @dataProvider invalidCandidateBudgets */
    public function testInvalidCandidateBudgetIsRejectedBeforeThePlanIsRead($maximumIds): void
    {
        $this->expectException(InvalidArgumentException::class);
        // A plan that would itself be invalid too, to prove the budget check runs first.
        $this->filter()->candidates('generation', [null], $maximumIds);
    }

    public function invalidCandidateBudgets(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
            'over the 500000 cap' => [500001],
        ];
    }

    // -- reserve(): invalid sizing --------------------------------------------

    /**
     * @dataProvider invalidReserveCalls
     * reserve() validates generation, fingerprint and sizing before it reads
     * or writes anything in Redis (the live-generation lookup only happens
     * after these checks), so the disconnected double is never invoked.
     */
    public function testReserveRejectsInvalidIdentifiersAndSizing($generation, $fingerprint, $capacity, $rate, $rangeEntries): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->filter()->reserve($generation, $fingerprint, $capacity, $rate, $rangeEntries);
    }

    public function invalidReserveCalls(): array
    {
        return [
            'invalid generation identifier' => ['bad generation', 'fingerprint', 1000000, 0.001, 0],
            'invalid fingerprint identifier' => ['generation', 'bad fingerprint', 1000000, 0.001, 0],
            'zero capacity' => ['generation', 'fingerprint', 0, 0.001, 0],
            'negative capacity' => ['generation', 'fingerprint', -1, 0.001, 0],
            'zero rate' => ['generation', 'fingerprint', 1000000, 0.0, 0],
            'rate at 1' => ['generation', 'fingerprint', 1000000, 1.0, 0],
            'rate over 1' => ['generation', 'fingerprint', 1000000, 1.5, 0],
            'negative range entries' => ['generation', 'fingerprint', 1000000, 0.001, -1],
        ];
    }

    // -- pure helpers ----------------------------------------------------------

    public function testBucketsForRoundsUpToTheNext64EntryBucket(): void
    {
        $this->assertSame(1, FastLookupFilter::bucketsFor(0));
        $this->assertSame(1, FastLookupFilter::bucketsFor(1));
        $this->assertSame(1, FastLookupFilter::bucketsFor(64));
        $this->assertSame(2, FastLookupFilter::bucketsFor(65));
        $this->assertSame(2, FastLookupFilter::bucketsFor(128));
        $this->assertSame(3, FastLookupFilter::bucketsFor(129));
    }

    public function testBucketsForNeverExceedsTheMaximum(): void
    {
        $this->assertSame(4194304, FastLookupFilter::bucketsFor(PHP_INT_MAX >> 8));
    }

    public function testEstimatedFalsePositiveRateIsZeroWithNothingInserted(): void
    {
        $this->assertSame(0.0, FastLookupFilter::estimatedFalsePositiveRate(1000000, 0.001, 0));
    }

    public function testEstimatedFalsePositiveRateMatchesTheDocumentedFormula(): void
    {
        $capacity = 1000000;
        $rate = 0.001;
        $inserted = 500000;
        $hashes = (int)ceil(-log($rate) / log(2));
        $bits = -log($rate) / (log(2) ** 2) * $capacity;
        $expected = (1 - exp(-$hashes * $inserted / $bits)) ** $hashes;
        $this->assertSame($expected, FastLookupFilter::estimatedFalsePositiveRate($capacity, $rate, $inserted));
    }

    public function testEstimatedFalsePositiveRateIncreasesAsMoreIsInserted(): void
    {
        $low = FastLookupFilter::estimatedFalsePositiveRate(1000000, 0.001, 100000);
        $high = FastLookupFilter::estimatedFalsePositiveRate(1000000, 0.001, 900000);
        $this->assertGreaterThan($low, $high);
    }

    // -- metadata(): corrupt Redis state must fail closed ----------------------

    /** Every field a healthy FastLookupFilter::metadata() would read successfully, for the default test scope. */
    private function validMetadataFields(): array
    {
        return [
            'schema' => FastLookupFilter::SCHEMA,
            'live' => '',
            'building' => '',
            'fingerprint' => '',
            'building_fingerprint' => '',
            'revision' => '1',
            'ready' => '1',
            'scope' => json_encode(['attribute_types' => ['domain'], 'published_only' => true], JSON_THROW_ON_ERROR),
        ];
    }

    private function metadataDouble(array $overrides, bool $unset = false)
    {
        $fields = $this->validMetadataFields();
        if ($unset) {
            foreach (array_keys($overrides) as $key) { unset($fields[$key]); }
        } else {
            $fields = array_merge($fields, $overrides);
        }
        return new class($fields) {
            private $fields;
            public function __construct(array $fields) { $this->fields = $fields; }
            public function hGetAll($key) { return $this->fields; }
        };
    }

    /** Sanity check: the base fixture is a metadata() success, so the corrupt variants below are testing one thing at a time. */
    public function testValidMetadataFixtureDoesNotThrow(): void
    {
        $meta = $this->filter(null, $this->metadataDouble([]))->metadata();
        $this->assertNull($meta['live']);
        $this->assertTrue($meta['ready']);
    }

    /** @dataProvider unknownSchemas */
    public function testWrongSchemaVersionFailsClosed(string $schema): void
    {
        $this->expectException(FastLookupIndexCorruptException::class);
        $this->filter(null, $this->metadataDouble(['schema' => $schema]))->metadata();
    }

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

    /** The reserve() and reset calls a double records, up to the cleanup the double cannot finish. */
    private function reserveCalls(array $replies): array
    {
        $redis = $this->recordingRedis($replies + ['eval' => 1, 'scan' => []]);
        try {
            $this->filter(null, $redis)->reserve('next', 'fingerprint', 1000, 0.001, 1);
        } catch (FastLookupIndexUnavailableException $e) {
            // The double's SCAN cursor never ends the cleanup.
        }
        return $redis->arguments;
    }

    public function testReserveStampsTheCurrentSchemaWithTheMaskedGeneration(): void
    {
        $calls = $this->reserveCalls(['hGetAll' => ['schema' => 'bloom-1'] + $this->validMetadataFields()]);
        $reserve = array_values(array_filter($calls, function ($call) {
            return $call[0] === 'eval' && strpos($call[1][0], 'BF.RESERVE') !== false;
        }));
        $this->assertCount(1, $reserve);
        [$script, $arguments, $keyCount] = $reserve[0][1];
        $this->assertStringContainsString("'p4'", $script);
        $this->assertStringContainsString("'building', ARGV[1], 'building_fingerprint', ARGV[2], 'schema', ARGV[6]", $script);
        $this->assertSame('bloom-3', $arguments[$keyCount + 5]);
        $this->assertNotContains('hMSet', array_column($calls, 0), 'A served legacy namespace is not reset.');
    }

    public function testNamespaceResetWritesTheCurrentSchema(): void
    {
        $calls = $this->reserveCalls(['hGetAll' => []]);
        $reset = array_values(array_filter($calls, function ($call) { return $call[0] === 'hMSet'; }));
        $this->assertCount(1, $reset);
        $this->assertSame('bloom-3', $reset[0][1][1]['schema']);
    }

    public function testCheckpointAcceptsEverySchema(): void
    {
        $redis = $this->recordingRedis(['eval' => 1]);
        $this->filter(null, $redis)->checkpoint('r1', false);
        [, [$script, $arguments, $keyCount]] = $redis->arguments[1];
        $this->assertStringContainsString('if schema == ARGV[i] then known = true end', $script);
        $this->assertSame(FastLookupFilter::SCHEMAS, array_slice($arguments, $keyCount + 2));
    }

    /** metadata() for a generation 'live1' whose layout HMGET answers $layout and state HMGET answers $state. */
    private function liveGenerationMetadata(array $state, string $field = 'live', array $layout = ['!' => 'live1', 'shards' => false, 'bloom_type' => false])
    {
        $fields = [$field => 'live1'] + ($field === 'live' ? [] : ['live' => '']) + $this->validMetadataFields();
        $fields['ready'] = $field === 'live' ? '1' : '0';
        return $this->filter(null, $this->recordingRedis(['hGetAll' => $fields, 'hMGet' => $layout, 'eval' => $state]))->metadata();
    }

    private function generationState(array $masks): array
    {
        return array_merge(['1000', '0.001', '0', '0', '1', '0'], $masks);
    }

    public function testLegacyAndMaskedGenerationStatesAreValid(): void
    {
        $this->assertSame(1000, $this->liveGenerationMetadata($this->generationState([false, false, false]))['generations']['live1']['capacity']);
        $this->assertSame(1000, $this->liveGenerationMetadata($this->generationState([null, null, null]))['generations']['live1']['capacity']);
        $masked = $this->generationState([str_repeat('0', 33), str_repeat('0', 128) . '1', '4']);
        $this->assertSame(1000, $this->liveGenerationMetadata($masked)['generations']['live1']['capacity']);
    }

    /** @dataProvider corruptPrefixStates */
    public function testLiveGenerationWithACorruptPrefixStateIsCorrupt(array $masks): void
    {
        $this->expectException(FastLookupIndexCorruptException::class);
        $this->liveGenerationMetadata($this->generationState($masks));
    }

    public function testBuildingGenerationWithACorruptPrefixStateIsOmitted(): void
    {
        $meta = $this->liveGenerationMetadata($this->generationState([false, str_repeat('0', 129), '0']), 'building');
        $this->assertSame('live1', $meta['building']);
        $this->assertSame([], $meta['generations']);
    }

    public function testCorruptPrefixMaskReplyIsCorruption(): void
    {
        $layout = ['!' => 'generation', 'shards' => '1', 'bloom_type' => 'MBbloom--'];
        foreach ([['hMGet' => $layout, 'eval' => false, 'getLastError' => 'corrupt prefix mask'], ['hMGet' => $layout, 'eval' => new RuntimeException('corrupt prefix mask')]] as $replies) {
            try {
                $this->filter(null, $this->recordingRedis($replies))
                    ->add('generation', [['id' => '1', 'tokens' => [$this->token('I')], 'networks' => [[4, 24]]]]);
                $this->fail('A corrupt prefix mask must fail closed.');
            } catch (FastLookupIndexCorruptException $e) {
                $this->assertSame('The fastLookup IP prefix state is corrupt.', $e->getMessage());
            }
        }
    }

    public function testMissingRequiredFieldFailsClosed(): void
    {
        $this->expectException(FastLookupIndexCorruptException::class);
        $this->filter(null, $this->metadataDouble(['live' => null], true))->metadata();
    }

    public function testReadyOutsideZeroOrOneFailsClosed(): void
    {
        $this->expectException(FastLookupIndexCorruptException::class);
        $this->filter(null, $this->metadataDouble(['ready' => '2']))->metadata();
    }

    public function testCorruptRevisionIdentifierFailsClosed(): void
    {
        $this->expectException(FastLookupIndexCorruptException::class);
        $this->filter(null, $this->metadataDouble(['revision' => 'bad revision!']))->metadata();
    }

    public function testCorruptLiveGenerationIdentifierFailsClosed(): void
    {
        $this->expectException(FastLookupIndexCorruptException::class);
        $this->filter(null, $this->metadataDouble(['live' => 'bad generation!']))->metadata();
    }

    public function testUnparsableScopeJsonFailsClosed(): void
    {
        $this->expectException(FastLookupIndexCorruptException::class);
        $this->filter(null, $this->metadataDouble(['scope' => '{not valid json']))->metadata();
    }

    /** Records every Redis call; $replies maps a method to a value or a Throwable to throw. */
    private function recordingRedis(array $replies)
    {
        return new class($replies) {
            public $calls = [];
            public $arguments = [];
            private $replies;
            public function __construct(array $replies) { $this->replies = $replies; }
            public function __call($method, $args)
            {
                $this->calls[] = $method;
                $this->arguments[] = [$method, $args];
                $reply = $this->replies[$method] ?? null;
                if ($reply instanceof Throwable) { throw $reply; }
                return $reply;
            }
        };
    }

    /** @dataProvider transportFailures */
    public function testTransportFailureReadingMetadataIsNotCorruption(array $replies): void
    {
        try {
            $this->filter(null, $this->recordingRedis($replies))->metadata();
            $this->fail('A failed read must fail closed.');
        } catch (FastLookupIndexUnavailableException $e) {
            $this->assertNotInstanceOf(FastLookupIndexCorruptException::class, $e);
        }
    }

    public function transportFailures(): array
    {
        return [
            'timeout' => [['hGetAll' => new RuntimeException('read error on connection')]],
            'refused read' => [['hGetAll' => false, 'getLastError' => 'LOADING Redis is loading the dataset in memory']],
            'BUSY on the generation check' => [['hGetAll' => ['schema' => 'bloom-1', 'live' => 'live1', 'building' => '',
                'fingerprint' => 'f', 'building_fingerprint' => '', 'revision' => '1', 'ready' => '1',
                'scope' => json_encode(['attribute_types' => ['domain'], 'published_only' => true])],
                'hMGet' => ['!' => 'live1', 'shards' => false, 'bloom_type' => false],
                'eval' => new RuntimeException('BUSY Redis is busy running a script.')]],
        ];
    }

    public function testMissingOrMistypedMetadataIsCorruption(): void
    {
        foreach ([['hGetAll' => []], ['hGetAll' => false, 'getLastError' => 'WRONGTYPE Operation against a key holding the wrong kind of value']] as $replies) {
            try {
                $this->filter(null, $this->recordingRedis($replies))->metadata();
                $this->fail('Missing metadata must fail closed.');
            } catch (FastLookupIndexCorruptException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testMissingLiveGenerationIsCorruption(): void
    {
        $this->expectException(FastLookupIndexCorruptException::class);
        $this->filter(null, $this->recordingRedis(['hGetAll' => ['schema' => FastLookupFilter::SCHEMA, 'live' => 'live1', 'building' => '',
            'fingerprint' => 'f', 'building_fingerprint' => '', 'revision' => '1', 'ready' => '1',
            'scope' => json_encode(['attribute_types' => ['domain'], 'published_only' => true])],
            'hMGet' => ['!' => 'live1', 'shards' => false, 'bloom_type' => false],
            'eval' => new RuntimeException('missing Bloom filter')]))->metadata();
    }

    public function testModuleStateTellsAMissingModuleFromAnUnreachableRedis(): void
    {
        $cases = [
            'available' => [['rawCommand' => [['bf.mexists', -2, ['readonly']]]], 'available'],
            'unknown command, as phpredis returns it' => [['rawCommand' => [false]], 'missing'],
            'unknown command, nil element' => [['rawCommand' => [null]], 'missing'],
            'refused command' => [['rawCommand' => false], 'unreachable'],
            'connection failure' => [['rawCommand' => new RuntimeException('Connection refused')], 'unreachable'],
        ];
        foreach ($cases as $name => [$replies, $state]) {
            $this->assertSame($state, $this->filter(null, $this->recordingRedis($replies))->moduleState(), $name);
        }
    }

    public function testTransportFailureDuringReserveNeverResetsTheNamespace(): void
    {
        $redis = $this->recordingRedis(['hGetAll' => new RuntimeException('read error on connection')]);
        try {
            $this->filter(null, $redis)->reserve('next', 'fingerprint', 1000, 0.001, 1);
            $this->fail('reserve() must fail when Redis cannot be read.');
        } catch (FastLookupIndexUnavailableException $e) {
            $this->assertNotInstanceOf(FastLookupIndexCorruptException::class, $e);
        }
        $this->assertSame(['hGetAll'], $redis->calls, 'Nothing was deleted, reset or reserved.');
    }

    public function testMissingMetadataDuringReserveStartsACleanNamespace(): void
    {
        $redis = $this->recordingRedis(['hGetAll' => [], 'eval' => 1, 'scan' => []]);
        try {
            $this->filter(null, $redis)->reserve('next', 'fingerprint', 1000, 0.001, 1);
        } catch (FastLookupIndexUnavailableException $e) {
            // The double's SCAN cursor never ends the cleanup; the reset already happened.
        }
        $this->assertSame(['hGetAll', 'del', 'hMSet'], array_slice($redis->calls, 0, 3));
    }

    // -- IP prefix lengths -----------------------------------------------------

    /** @dataProvider malformedNetworks */
    public function testMalformedNetworksAreRejectedBeforeAnyRedisWrite($networks): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->filter()->add('generation', [['id' => '1', 'tokens' => [$this->token('I')], 'networks' => $networks]]);
    }

    public function malformedNetworks(): array
    {
        return [
            'not a list' => ['x'],
            'unknown family' => [[[5, 1]]],
            'IPv4 length over 32' => [[[4, 33]]],
            'IPv6 length over 128' => [[[6, 129]]],
            'negative length' => [[[4, -1]]],
            'family as a string' => [[['4', 8]]],
            'missing length' => [[[4]]],
        ];
    }

    public function testInvalidPrefixVersionIsRejectedBeforeAnyRedisCall(): void
    {
        foreach (['x', '-1'] as $version) {
            try {
                $this->filter()->candidates('generation', [], 10, $version);
                $this->fail('An invalid prefix version must be rejected.');
            } catch (InvalidArgumentException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

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
            public function hMGet($key, $fields) { return ['!' => 'generation', 'shards' => '1', 'bloom_type' => 'MBbloom--']; }
        };
    }

    public function testPrefixLengthsParsesMasks(): void
    {
        $reply = [str_repeat('0', 13) . '1' . str_repeat('0', 19), str_repeat('0', 128) . '1', '7'];
        $this->assertSame(['version' => '7', 'lengths' => [4 => [13 => true], 6 => [128 => true]]],
            $this->filter(null, $this->evalRedis($reply))->prefixLengths('generation'));
        $this->assertSame(['version' => '', 'lengths' => null],
            $this->filter(null, $this->evalRedis([false, false, false]))->prefixLengths('generation'));
    }

    /** @dataProvider corruptPrefixStates */
    public function testPartialOrMalformedPrefixStateIsCorrupt(array $reply): void
    {
        $this->expectException(FastLookupIndexCorruptException::class);
        $this->filter(null, $this->evalRedis($reply))->prefixLengths('generation');
    }

    public function corruptPrefixStates(): array
    {
        return [
            'missing IPv4 mask' => [[false, str_repeat('0', 129), '0']],
            'short IPv4 mask' => [[str_repeat('0', 32), str_repeat('0', 129), '0']],
            'non-binary mask' => [[str_repeat('2', 33), str_repeat('0', 129), '0']],
            'non-decimal version' => [[str_repeat('0', 33), str_repeat('0', 129), 'x']],
            'missing version' => [[str_repeat('0', 33), str_repeat('0', 129), false]],
        ];
    }

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

    public function fullShardReplies(): array
    {
        return [
            'refused script' => [['eval' => false, 'getLastError' => 'Bloom filter is full']],
            'script error thrown' => [['eval' => new RuntimeException('Bloom filter is full')]],
        ];
    }

    /** @dataProvider fullShardReplies */
    public function testFullShardFailsTheAddWithTheFullGeneration(array $replies): void
    {
        $redis = $this->recordingRedis(['hMGet' => ['!' => 'live1', 'shards' => '2', 'bloom_type' => 'bloomfltr']] + $replies);
        try {
            $this->filter(null, $redis)->add('live1', [['id' => '1', 'tokens' => [$this->shardedToken(4)]]]);
            $this->fail('A full shard must fail the add.');
        } catch (FastLookupIndexFullException $e) {
            $this->assertSame('live1', $e->generation);
            $this->assertNotInstanceOf(FastLookupIndexCorruptException::class, $e);
        }
        $evals = array_values(array_filter($redis->arguments, function ($call) { return $call[0] === 'eval'; }));
        $this->assertStringContainsString("return redis.error_reply('Bloom filter is full')", $evals[0][1][0]);
    }

    public function testStatisticsEstimateFalsePositivesFromTheReservedTotal(): void
    {
        $filter = $this->filter(null, new class(['live' => 'live1'] + $this->validMetadataFields()) {
            private $fields;
            public function __construct(array $fields) { $this->fields = $fields; }
            public function hGetAll($key) { return $this->fields; }
            public function hMGet($key, $fields) { return ['!' => 'live1', 'shards' => '3', 'bloom_type' => 'bloomfltr']; }
            public function eval($script, $args, $keys) { return ['1000', '0.001', '900', '0', '1', '0', false, false, false]; }
            public function rawCommand(...$args) { return 100; }
            public function hScan($key, &$cursor, $pattern = null, $count = 0) { $cursor = 0; return ['!' => 'live1']; }
            public function clearLastError() { return true; }
            public function getLastError() { return null; }
        });
        $this->assertSame(1002, FastLookupFilter::reservedCapacity(1000, 3));
        $this->assertSame(1000, FastLookupFilter::reservedCapacity(1000, null));
        $stats = $filter->statistics('live1');
        $this->assertSame(1000, $stats['capacity']);
        $this->assertSame(FastLookupFilter::estimatedFalsePositiveRate(1002, 0.001, 900), $stats['estimated_false_positive_rate']);
        $this->assertNotSame(FastLookupFilter::estimatedFalsePositiveRate(1000, 0.001, 900), $stats['estimated_false_positive_rate']);
        $this->assertSame(300, $stats['filter_bytes'], 'Every shard is measured.');
    }

    public function testFilterIsFullAtItsReservedCapacity(): void
    {
        $this->assertFalse(FastLookupFilter::filterFull(1000, null, 999));
        $this->assertTrue(FastLookupFilter::filterFull(1000, null, 1000));
        $this->assertFalse(FastLookupFilter::filterFull(1000, 3, 1001), 'Three shards reserve 1002.');
        $this->assertTrue(FastLookupFilter::filterFull(1000, 3, 1002));
    }

    public function testCandidatesRefuseAFullGenerationBeforeProbing(): void
    {
        $redis = $this->scriptedRedis([
            'hGetAll' => ['live' => 'live1', 'fingerprint' => 'f'] + $this->validMetadataFields(),
            'hMGet' => ['!' => 'live1', 'shards' => false, 'bloom_type' => false],
        ], ["'capacity', 'rate', 'inserted'" => ['1000', '0.001', '1000', '0', '1', '0', false, false, false]]);
        try {
            $this->filter(null, $redis)->candidates('live1', [[['token' => $this->shardedToken(1), 'kind' => 'exact']]]);
            $this->fail('A full generation must never answer.');
        } catch (FastLookupIndexFullException $e) {
            $this->assertSame('live1', $e->generation);
        }
        $this->assertSame([], array_filter($redis->evals, function ($eval) { return strpos($eval[0], 'BF.MEXISTS') !== false; }));
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

    public function testScopeDisagreeingWithConfigurationFailsClosed(): void
    {
        $this->expectException(FastLookupIndexCorruptException::class);
        $mismatched = json_encode(['attribute_types' => ['hostname'], 'published_only' => true], JSON_THROW_ON_ERROR);
        $this->filter(null, $this->metadataDouble(['scope' => $mismatched]))->metadata();
    }
}

/*
 * Deleted FastLookupIndexTest cases with no FastLookupFilter equivalent
 * (kept here rather than silently dropped, per the task brief):
 *
 * - testOversizedWriteBatchFailsRatherThanDroppingAttributes: the old
 *   addAttributes() capped a single call at 500 attributes and threw
 *   OverflowException over that. FastLookupFilter::add() has no such cap —
 *   FILTER_BATCH/POSTING_BATCH only chunk the Redis round trips, they don't
 *   reject a large $prepared array.
 *
 * - The 'type' mismatch row/entry cases in FastLookupIndexTest's
 *   invalidAttributes/invalidQueries (e.g. an attribute or query token
 *   carrying `'type' => 'ip-src'` under a domain-only scope): add() and
 *   candidates() no longer take or validate a `type` field at all, only
 *   `id`/`tokens` and `token`/`kind` respectively, so there is nothing left
 *   to assert there.
 */
