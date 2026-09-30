<?php
use PHPUnit\Framework\TestCase;

/**
 * MISP.fast_lookup_valkey_shard_bytes through Server::serverSettingsEditValue,
 * the path the web UI, the API and setSetting share. Server is loaded without
 * the CakePHP bootstrap and instantiated without its constructor; saving
 * writes to Configure instead of config.php.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class FastLookupValkeyShardBytesSettingTest extends TestCase
{
    const NAME = 'MISP.fast_lookup_valkey_shard_bytes';

    private $server;
    private $setting;

    protected function setUp(): void
    {
        require_once __DIR__ . '/../Vendor/autoload.php';
        require_once __DIR__ . '/fixtures/FastLookupConfigurationStub.php';
        require_once __DIR__ . '/fixtures/FastLookupServerSettingStubs.php';
        foreach (['DS' => '/', 'ROOT' => dirname(__DIR__, 2), 'APP' => dirname(__DIR__) . '/', 'APP_DIR' => 'app'] as $constant => $value) {
            if (!defined($constant)) { define($constant, $value); }
        }
        require_once __DIR__ . '/../Lib/Tools/FastLookupSizing.php';
        require_once __DIR__ . '/../Lib/Tools/FastLookupConfig.php';
        require_once __DIR__ . '/../Lib/Tools/EnvSetting.php';
        require_once __DIR__ . '/../Model/Server.php';
        Configure::clear();
        $this->server = (new ReflectionClass(new class extends Server {
            public function __construct() {}
            public function serverSettingsSaveValue($setting, $value, $fileOnly = false)
            {
                Configure::write($setting, $value);
                return true;
            }
        }))->newInstanceWithoutConstructor();
        $generate = new ReflectionMethod('Server', 'generateServerSettings');
        $generate->setAccessible(true);
        $this->setting = $generate->invoke($this->server)['MISP']['fast_lookup_valkey_shard_bytes'] + ['name' => self::NAME];
    }

    private function edit($value, bool $cli = false)
    {
        return $this->server->serverSettingsEditValue('SYSTEM', $this->setting, $value, false, $cli);
    }

    public function testTheSettingIsAnOptionalNumericWithARawValueHook(): void
    {
        $this->assertSame('numeric', $this->setting['type']);
        $this->assertSame(0, $this->setting['value'], 'Unset is 0, what an emptied numeric setting saves.');
        $this->assertTrue($this->setting['null']);
        $this->assertSame('fastLookupValkeyShardBytesBeforeHook', $this->setting['beforeHook']);
        $this->assertStringContainsString('prevents that node from loading its data at start-up', $this->setting['description']);
    }

    /** @dataProvider rejectedValues */
    public function testMalformedOrSmallValuesAreRejectedAndNothingIsSaved($value): void
    {
        Configure::write(self::NAME, 134217728);
        $result = $this->edit($value);
        $this->assertIsString($result, 'Rejected: ' . json_encode($value));
        $this->assertStringContainsString('at least 67108864', $result);
        $this->assertSame(134217728, Configure::read(self::NAME), 'The previous value is kept.');
    }

    public function rejectedValues(): array
    {
        return [
            '64MiB' => ['64MiB'],
            '1.5' => ['1.5'],
            'true' => ['true'],
            'boolean true' => [true],
            'below the 64 MiB floor' => ['1000'],
            'one byte under the floor' => ['67108863'],
            'negative' => ['-134217728'],
            'exponent' => ['1e9'],
            // setSetting casts '1.5' and 'true' to 1 before the hook.
            'CLI-cast 1.5 or true' => [1],
        ];
    }

    public function testAPlainByteCountIsStoredAsAnInt(): void
    {
        $this->assertTrue($this->edit('134217728'));
        $this->assertSame(134217728, Configure::read(self::NAME));
        $this->assertSame(134217728, FastLookupConfig::valkeyShardBytes());
        $this->assertTrue($this->edit(67108864, true), 'The floor itself, from setSetting.');
        $this->assertSame(67108864, Configure::read(self::NAME));
    }

    /** @dataProvider clearingValues */
    public function testEmptyClearsTheSetting($value, bool $cli): void
    {
        Configure::write(self::NAME, 134217728);
        $this->assertTrue($this->edit($value, $cli));
        $this->assertNull(FastLookupConfig::valkeyShardBytes());
    }

    public function clearingValues(): array
    {
        return [
            'web UI empty field' => ['', false],
            'web UI 0' => ['0', false],
            'setSetting --null' => [null, true],
            'setSetting 0' => [0, true],
        ];
    }
}
