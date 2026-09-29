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
                if ($this->modules instanceof Throwable) {
                    throw $this->modules;
                }
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

    public function testMalformedModuleEntriesAreSkipped(): void
    {
        $versions = fastLookupBackendVersions($this->server(
            ['redis_version' => '8.2.10'],
            ['junk', [], ['name'], ['name', 'bf', 'ver', 7], ['ver', 1]]));
        $this->assertSame(['bf' => 7], $versions['modules']);
    }

    public function testMissingInfoDoesNotWarn(): void
    {
        $versions = fastLookupBackendVersions($this->server([], []));
        $this->assertNull($versions['backend_version']);
    }

    public function testFilterKeyNames(): void
    {
        foreach (['g:1:bf' => true, 'g:1:bf:3' => true, 'g:1:bfx' => false,
            'g:1:bf:' => false, 'g:1:bf:x' => false] as $key => $expected) {
            $this->assertSame($expected, fastLookupIsFilterKey($key), $key);
        }
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
