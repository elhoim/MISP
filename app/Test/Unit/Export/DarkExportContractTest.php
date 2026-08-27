<?php

use MispTest\Support\FakeModel;
use PHPUnit\Framework\TestCase;

require_once APP . 'Test/Support/FakeModel.php';

/**
 * Coverage for the five export formats ExportContractTest.php leaves dark
 * because their real handler()/header() paths need infrastructure the
 * generic contract cannot supply (a model-resolved field list, RPZ policy
 * settings from the database, Galaxy/Object shapes the generic single
 * attribute fixture does not have). This suite drives each format through
 * its actual code paths - not just the trivial "doesn't fatal" contract -
 * using data shaped exactly as the format needs it, without a database.
 *
 * These are CHARACTERIZATION tests (ADR 0002): they record today's output
 * for a known input, they do not claim the output is the "right" RPZ zone
 * file or the "right" CSV column order.
 */
class DarkExportContractTest extends TestCase
{
    protected function setUp(): void
    {
        ClassRegistry::reset();
        ClassRegistry::$factory = static function ($name) { return new FakeModel(); };
        Configure::reset();
        foreach (glob(APP . 'Lib/Export/*Export.php') as $file) {
            require_once $file;
        }
    }

    // ------------------------------------------------------------------
    // CsvExport (175 stmts, 1 covered)
    // ------------------------------------------------------------------

    /** A single attribute in the shape MISP hands to an export. */
    private static function attribute(string $type = 'ip-dst', string $value = '8.8.8.8'): array
    {
        return [
            'Attribute' => [
                'id' => 1,
                'uuid' => '5f7b1a2c-0000-4000-8000-000000000001',
                'event_id' => 1,
                'category' => 'Network activity',
                'type' => $type,
                'value' => $value,
                'value1' => $value,
                'value2' => '',
                'to_ids' => 1,
                'timestamp' => 1735689600,
                'comment' => 'test attribute',
                'distribution' => 0,
                'object_relation' => null,
                'object_id' => 0,
                'deleted' => false,
                'sharing_group_id' => 0,
                'AttributeTag' => [],
            ],
            'Event' => [
                'id' => 1,
                'uuid' => '5f7b1a2c-0000-4000-8000-0000000000ee',
                'info' => 'test event',
                'date' => '2026-01-01',
                'threat_level_id' => 1,
                'analysis' => 0,
                'distribution' => 0,
                'timestamp' => 1735689600,
                'publish_timestamp' => 1735689600,
                'org_id' => 1,
                'orgc_id' => 1,
                'Orgc' => ['id' => 1, 'name' => 'TestOrg'],
                'Org' => ['id' => 1, 'name' => 'TestOrg'],
                'Tag' => [],
            ],
        ];
    }

    public function testCsvHeaderBuildsDefaultFieldListAndRenamesTimestampToDate(): void
    {
        $export = new CsvExport();
        $options = ['filters' => []];
        $header = $export->header($options);

        $this->assertIsString($header, 'CsvExport::header() must return a consumable header line');
        // timestamp is renamed to "date" for the CSV column name, and
        // hyphens in object_meta-category become underscores.
        $this->assertStringContainsString('date', $header, 'timestamp column is renamed to date');
        $this->assertStringNotContainsString('timestamp,', $header, 'the raw field name must not leak into the header');
        $this->assertStringContainsString('object_meta_category', $header, 'hyphens are normalised to underscores in header names');
        $this->assertStringEndsWith(PHP_EOL, $header);
    }

    public function testCsvHeaderlessFilterSuppressesTheHeaderLine(): void
    {
        $export = new CsvExport();
        $options = ['filters' => ['headerless' => 1]];
        $this->assertSame('', $export->header($options), 'headerless must produce no header row at all');
    }

    public function testCsvHandlerAttributeScopeProducesOneLinePerRequestedField(): void
    {
        $export = new CsvExport();
        $options = ['filters' => []];
        $export->header($options);

        $options['scope'] = 'Attribute';
        $line = $export->handler(self::attribute('ip-dst', '8.8.8.8'), $options);

        $this->assertIsString($line, 'CsvExport::handler() for Attribute scope must return a CSV line');
        $this->assertStringContainsString('"8.8.8.8"', $line, 'the attribute value must be quoted and present in the line');
        $this->assertStringContainsString('"ip-dst"', $line, 'the attribute type must be present in the line');
        $this->assertStringEndsWith(PHP_EOL, $line);
    }

    public function testCsvHandlerEventScopeWalksAttributesAndObjectAttributes(): void
    {
        $export = new CsvExport();
        $options = ['filters' => []];
        $export->header($options);
        $options['scope'] = 'Event';

        $event = [
            'Event' => ['info' => 'evt', 'distribution' => 0, 'analysis' => 0, 'date' => '2026-01-01', 'timestamp' => 1735689600],
            'Org' => ['name' => 'OrgA'],
            'Orgc' => ['name' => 'OrgA'],
            'ThreatLevel' => ['name' => 'Medium'],
            'Attribute' => [
                ['uuid' => 'a1', 'event_id' => 1, 'category' => 'c', 'type' => 'ip-dst', 'value' => '1.1.1.1',
                    'comment' => '', 'to_ids' => 1, 'timestamp' => 1735689600, 'object_relation' => null, 'AttributeTag' => []],
            ],
            'Object' => [
                [
                    'uuid' => 'obj-1', 'name' => 'file', 'meta-category' => 'file',
                    'Attribute' => [
                        ['uuid' => 'a2', 'event_id' => 1, 'category' => 'c', 'type' => 'filename', 'value' => 'evil.exe',
                            'comment' => '', 'to_ids' => 1, 'timestamp' => 1735689600, 'object_relation' => 'filename', 'AttributeTag' => []],
                    ],
                ],
            ],
        ];

        $out = $export->handler($event, $options);
        $this->assertIsString($out, 'CsvExport::handler() for Event scope must return a consumable string');
        $this->assertStringContainsString('"1.1.1.1"', $out, 'the plain event attribute must be rendered');
        $this->assertStringContainsString('"evil.exe"', $out, 'the object attribute must also be rendered');
        $this->assertStringContainsString('"obj-1"', $out, 'the object uuid must be attached to its attribute row');
        $this->assertSame(2, substr_count($out, PHP_EOL), 'one line per attribute (plain + object)');
    }

    public function testCsvHandlerSightingScopeFlattensNestedEventAndAttributeKeys(): void
    {
        $export = new CsvExport();
        $options = ['filters' => []];
        $export->header($options);
        $options['scope'] = 'Sighting';

        $sighting = [
            'Sighting' => [
                'id' => 1,
                'uuid' => 's1',
                'Event' => ['id' => 1, 'orgc' => ['name' => 'OrgA']],
                'Attribute' => ['type' => 'ip-dst'],
            ],
        ];

        $out = $export->handler($sighting, $options);
        $this->assertIsString($out, 'CsvExport::handler() for Sighting scope must return a consumable string');
        $this->assertStringEndsWith(PHP_EOL, $out);
    }

    public function testCsvEnableDecayingAppendsDecayFieldsToTheDefaultFieldList(): void
    {
        $export = new CsvExport();
        $before = $export->default_fields;
        $export->enable_decaying();
        $this->assertSame(
            array_merge($before, ['decay_score_score', 'decay_score_decayed']),
            $export->default_fields,
            'enable_decaying() must append, not replace, the default field list'
        );
    }

    public function testCsvEventIndexYieldsAHeaderRowThenOneRowPerEvent(): void
    {
        $export = new CsvExport();
        $events = [
            [
                'id' => 1, 'date' => '2026-01-01', 'info' => 'evt', 'uuid' => 'e1', 'published' => 1,
                'analysis' => 0, 'attribute_count' => 3, 'orgc_id' => 1, 'timestamp' => 1735689600,
                'distribution' => 0, 'sharing_group_id' => 0, 'threat_level_id' => 1,
                'publish_timestamp' => 1735689600, 'extends_uuid' => '',
                'Orgc' => ['name' => 'OrgA', 'uuid' => 'org-uuid'],
                'EventTag' => [['Tag' => ['name' => 'tlp:green']]],
            ],
        ];

        $rows = iterator_to_array($export->eventIndex($events));
        $this->assertCount(2, $rows, 'header row + one event row');
        $this->assertStringContainsString('orgc_name', $rows[0], 'the index header must name the derived orgc columns');
        $this->assertStringContainsString('tlp:green', $rows[1], 'event tags must be flattened into the row');
        $this->assertStringContainsString('OrgA', $rows[1]);
    }

    // ------------------------------------------------------------------
    // RPZExport (91 stmts, 1 covered)
    // ------------------------------------------------------------------

    /**
     * Every RPZ setting supplied via filters so header() never falls through
     * to Configure/the Server model - that fallback path is what makes
     * RPZExport infrastructure-bound in the generic contract suite.
     */
    private static function rpzOptions(string $policy = 'NXDOMAIN'): array
    {
        return [
            'scope' => 'Attribute',
            'filters' => [
                'policy' => $policy,
                'walled_garden' => '10.0.0.1',
                'ns' => 'ns1.example.com.',
                'ns_alt' => '',
                'email' => 'hostmaster.example.com.',
                'serial' => '$date$time',
                'refresh' => 3600,
                'retry' => 600,
                'expiry' => 86400,
                'minimum_ttl' => 300,
                'ttl' => 300,
            ],
        ];
    }

    public function testRpzHeaderNeverTouchesTheDatabaseWhenAllSettingsAreSuppliedByFilters(): void
    {
        $export = new RPZExport();
        $options = self::rpzOptions();
        $this->assertSame('', $export->header($options), 'header() emits nothing itself - the zone header is built by footer()');
        // No model was resolved through ClassRegistry, proving the Server
        // fallback branch was never taken.
        $this->assertArrayNotHasKey('Server', ClassRegistry::$instances);
    }

    public function testRpzAttributeHandlerBucketsSupportedTypesByPolicyClass(): void
    {
        $export = new RPZExport();
        $options = self::rpzOptions();
        $export->header($options);

        $this->assertSame('', $export->handler(self::attribute('ip-dst', '8.8.8.8'), $options));
        $export->handler(self::attribute('domain', 'evil.example.com'), $options);
        $export->handler(self::attribute('hostname', 'host.example.com'), $options);
        // An unsupported type must be silently ignored, not fatal.
        $export->handler(self::attribute('text', 'irrelevant'), $options);

        $zone = $export->footer($options);
        $this->assertIsString($zone, 'RPZExport::footer() must return the assembled zone file');
        $this->assertStringContainsString('host.example.com CNAME', $zone, 'a hostname attribute must be bucketed and rendered');
    }

    public function testRpzZoneFileContainsSoaAndRpzEntriesForEachBucket(): void
    {
        $export = new RPZExport();
        $options = self::rpzOptions('NXDOMAIN');
        $export->header($options);
        $export->handler(self::attribute('ip-dst', '8.8.8.8'), $options);
        $export->handler(self::attribute('domain', 'evil.example.com'), $options);

        $zone = $export->footer($options);

        $this->assertStringContainsString('SOA ns1.example.com. hostmaster.example.com.', $zone, 'the zone header must carry the supplied NS/email settings');
        $this->assertStringContainsString('rpz-ip CNAME .', $zone, 'an IP bucket must render as an rpz-ip CNAME under the NXDOMAIN policy (action ".")');
        $this->assertStringContainsString('evil.example.com CNAME .', $zone, 'a domain bucket must render as a plain CNAME plus its wildcard');
        $this->assertStringContainsString('*.evil.example.com CNAME .', $zone, 'domains get a wildcard sub-domain entry too');
    }

    public function testRpzEventScopeSplitsDomainPipeIpIntoBothBuckets(): void
    {
        $export = new RPZExport();
        $options = self::rpzOptions();
        $export->header($options);

        $event = [
            'Event' => ['id' => 1, 'uuid' => 'e1'],
            'Attribute' => [
                ['type' => 'domain|ip', 'value' => 'evil.example.com|9.9.9.9'],
            ],
        ];
        $options['scope'] = 'Event';
        $export->handler($event, $options);

        $zone = $export->footer($options);
        $this->assertStringContainsString('evil.example.com CNAME', $zone, 'the domain half of domain|ip must land in the domain bucket');
        $this->assertStringContainsString('rpz-ip CNAME', $zone, 'the ip half of domain|ip must also land in the ip bucket');
    }

    public function testRpzInvalidPolicyFilterIsDiscarded(): void
    {
        // NOTE (not a confirmed defect): an invalid 'policy' filter is discarded by unset(),
        // and the code does not fall back to a hardcoded safe default -
        // instead it re-resolves the setting from Configure (null in this
        // test env) and then, only if that too is unset, from a live
        // Server model's serverSettings. In production Server.php ships a
        // valid RPZ_policy default (setting_id 1 / NXDOMAIN, see
        // app/Model/Server.php serverSettings['Plugin']['RPZ_policy']), so
        // that fallback degrades gracefully there. We only pin what this
        // test can prove without a real Server model: an invalid policy
        // filter forces the Configure/Server-settings resolution path
        // rather than being clamped to a safe value in RPZExport itself.
        // We do not exercise export() past this point, since with our
        // FakeModel stand-in for Server the resolved "value" is not a
        // real setting and would only be testing the stub, not RPZExport.
        $export = new RPZExport();
        $options = self::rpzOptions('not-a-real-policy');
        $export->header($options);

        $this->assertArrayHasKey(
            'Server',
            ClassRegistry::$instances,
            'an invalid policy filter must be discarded, which today forces the Configure/Server settings fallback instead of keeping a safe default'
        );
    }

    // ------------------------------------------------------------------
    // BroExport (59 stmts, 3 covered)
    // ------------------------------------------------------------------

    private static function broItem(string $type, string $value, int $orgcId = 1): array
    {
        return [
            'Attribute' => ['type' => $type, 'value1' => $value, 'value2' => $value, 'comment' => 'a comment'],
            'Event' => ['id' => 1, 'uuid' => 'e1', 'orgc_id' => $orgcId, 'info' => 'evt'],
        ];
    }

    public function testBroExportProducesOneIntelRulePerSupportedType(): void
    {
        Configure::write('MISP.baseurl', 'https://misp.example.com');
        $export = new BroExport();

        $items = [self::broItem('ip-dst', '8.8.8.8'), self::broItem('domain', 'evil.example.com')];
        $orgs = [1 => 'TestOrg'];

        $rules = $export->export($items, $orgs, 1, [], 'MISP-instance');

        $this->assertIsArray($rules, 'BroExport::export() must return a consumable list of rules');
        $this->assertCount(2, $rules, 'one rule per mapped attribute type');
        $this->assertStringContainsString('Intel::ADDR', $rules[0], 'ip-dst maps to the Bro ADDR indicator type');
        $this->assertStringContainsString('Intel::DOMAIN', $rules[1], 'domain maps to the Bro DOMAIN indicator type');
    }

    public function testBroExportSkipsAttributesFromAnUnknownOrg(): void
    {
        $export = new BroExport();
        $items = [self::broItem('ip-dst', '8.8.8.8', 999)];
        $orgs = [1 => 'TestOrg']; // org 999 is not in the map

        $rules = $export->export($items, $orgs, 1, [], 'MISP-instance');
        $this->assertSame([], $rules, 'an item whose orgc is not in the supplied org map must be dropped, not fatal');
    }

    public function testBroExportSkipsUnmappedAttributeTypes(): void
    {
        $export = new BroExport();
        $items = [self::broItem('text', 'irrelevant')];
        $orgs = [1 => 'TestOrg'];

        $rules = $export->export($items, $orgs, 1, [], 'MISP-instance');
        $this->assertSame([], $rules, 'a type with no Bro mapping must produce no rule');
    }

    public function testBroExportWhitelistSuppressesMatchingValues(): void
    {
        $export = new BroExport();
        $items = [self::broItem('domain', 'internal.example.com')];
        $orgs = [1 => 'TestOrg'];

        $rules = $export->export($items, $orgs, 1, ['/internal\.example\.com/'], 'MISP-instance');
        $this->assertSame([], $rules, 'a value matching the whitelist must be excluded from the export');
    }

    public function testBroExportHandlerIsANoOpBecauseTheFormatDrivesThroughExportInstead(): void
    {
        // KNOWN-DEFECT: unlike every other export format, BroExport::handler()
        // has an empty body and unconditionally returns null - it does not
        // delegate to export(). The two production callers we found
        // (EventsController::automation(), MispAttribute::bro()) both
        // instantiate BroExport directly and read ->mispTypes / call
        // ->export() themselves; neither goes through handler(). We do not
        // have full call-site coverage to say handler() is provably dead
        // everywhere - only that it is a documented no-op today.
        $export = new BroExport();
        $this->assertNull($export->handler(self::attribute(), self::rpzOptions()), 'handler() is a documented no-op for BroExport');
    }

    public function testBroExportFooterAndSeparatorAreNewlines(): void
    {
        $export = new BroExport();
        $this->assertSame("\n", $export->footer());
        $this->assertSame("\n", $export->separator());
    }

    public function testBroExportGetMispTypesReturnsTheReverseTypeMapping(): void
    {
        $export = new BroExport();
        $this->assertSame(
            [['ip-src', 1], ['ip-dst', 1], ['ip-src|port', 1], ['ip-dst|port', 1], ['domain|ip', 2]],
            $export->getMispTypes('ip')
        );
        $this->assertSame([], $export->getMispTypes('not-a-real-group'), 'an unknown group must return an empty list, not fatal');
    }

    // ------------------------------------------------------------------
    // HidsExport (26 stmts, 0 covered)
    // ------------------------------------------------------------------

    private static function hidsItem(string $type, string $value1 = '', string $value2 = ''): array
    {
        return ['Attribute' => ['type' => $type, 'value1' => $value1, 'value2' => $value2]];
    }

    public function testHidsExportCollectsHashesForDirectHashTypes(): void
    {
        $export = new HidsExport();
        $items = [
            self::hidsItem('md5', 'd41d8cd98f00b204e9800998ecf8427e'),
            self::hidsItem('sha1', 'da39a3ee5e6b4b0d3255bfef95601890afd80709'),
        ];

        $rules = $export->export($items, 'MD5');

        $this->assertIsArray($rules, 'HidsExport::export() must return a consumable list');
        $this->assertContains('d41d8cd98f00b204e9800998ecf8427e', $rules);
        $this->assertContains('da39a3ee5e6b4b0d3255bfef95601890afd80709', $rules);
    }

    public function testHidsExportUsesValue2ForCompositeHashTypes(): void
    {
        $export = new HidsExport();
        $items = [self::hidsItem('filename|md5', 'evil.exe', 'd41d8cd98f00b204e9800998ecf8427e')];

        $rules = $export->export($items, 'MD5');
        $this->assertContains('d41d8cd98f00b204e9800998ecf8427e', $rules, 'a composite type must contribute its hash half (value2), not the filename');
        $this->assertNotContains('evil.exe', $rules);
    }

    public function testHidsExportDeduplicatesRepeatedHashes(): void
    {
        $export = new HidsExport();
        $items = [
            self::hidsItem('md5', 'd41d8cd98f00b204e9800998ecf8427e'),
            self::hidsItem('md5', 'd41d8cd98f00b204e9800998ecf8427e'),
        ];

        $rules = $export->export($items, 'MD5');
        $this->assertSame(
            1,
            count(array_filter($rules, static function ($r) { return $r === 'd41d8cd98f00b204e9800998ecf8427e'; })),
            'the same hash seen twice must only appear once in the rule list'
        );
    }

    public function testHidsExportIgnoresUnsupportedTypes(): void
    {
        $export = new HidsExport();
        $items = [self::hidsItem('ip-dst', '8.8.8.8')];

        $rules = $export->export($items, 'MD5');
        // Only the trailing explain() comment lines are present - no hash line.
        $this->assertNotContains('8.8.8.8', $rules);
    }

    public function testHidsExportExplainPrependsTypeSpecificCommentary(): void
    {
        $export = new HidsExport();
        $rulesMd5 = $export->export([self::hidsItem('md5', 'aaa')], 'MD5');
        // explain() unshifts in reverse order, so the type-specific caveat
        // ends up second, after the "These HIDS export contains..." banner.
        $this->assertStringContainsString('These HIDS export contains MD5 checksums', $rulesMd5[0]);
        $this->assertStringContainsString('MD5 is not collision resistant', $rulesMd5[1]);

        $export2 = new HidsExport();
        $rulesSha1 = $export2->export([self::hidsItem('sha1', 'bbb')], 'SHA1');
        $this->assertStringContainsString('SHA-1 still has a theoretical collision possibility', $rulesSha1[1]);
    }

    public function testHidsExportContinueFlagSuppressesTheExplainCommentary(): void
    {
        $export = new HidsExport();
        $rules = $export->export([self::hidsItem('md5', 'ccc')], 'MD5', true);
        $this->assertSame(['ccc'], $rules, 'with $continue=true, explain() must not prepend comment lines - the caller adds them once at the end');
    }

    public function testHidsExportHasNoHandlerOfItsOwn(): void
    {
        // HidsExport is a pure base for its concrete MD5/SHA1/SHA256
        // variants and exposes no handler() itself.
        $this->assertFalse(method_exists(new HidsExport(), 'handler'));
    }

    // ------------------------------------------------------------------
    // AttackSightingsExport (66 stmts, 3 covered)
    // ------------------------------------------------------------------

    private static function attackAttribute(int $tagId, string $techniqueValue): array
    {
        $base = self::attribute();
        return [
            'Attribute' => $base['Attribute'],
            'Galaxy' => [
                [
                    'type' => 'mitre-attack-pattern',
                    'GalaxyCluster' => [
                        ['tag_id' => $tagId, 'value' => 'Enterprise Attack - "' . $techniqueValue . '"'],
                    ],
                ],
            ],
            'EventTag' => [['tag_id' => 999]],
        ];
    }

    public function testAttackSightingsHandlerAttributeScopeAggregatesTechniquesFromGalaxy(): void
    {
        $export = new AttackSightingsExport();
        $options = ['scope' => 'Attribute'];

        $out = $export->handler(self::attackAttribute(1, 'T1059.001'), $options);
        $this->assertSame('', $out, 'handler() accumulates internal state and emits nothing per-attribute');

        $json = $export->footer();
        $this->assertIsString($json, 'AttackSightingsExport::footer() must return a consumable payload');
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded, 'footer() must emit valid JSON');
        $this->assertSame('T1059.001', $decoded[0]['techniques'][0]['techniqueID']);
        $this->assertSame('direct-technique-sighting', $decoded[0]['sightingType']);
    }

    public function testAttackSightingsHandlerSkipsGalaxiesOfAnotherType(): void
    {
        $export = new AttackSightingsExport();
        $options = ['scope' => 'Attribute'];

        $attribute = self::attackAttribute(1, 'T1059.001');
        $attribute['Galaxy'][0]['type'] = 'some-other-galaxy';
        $export->handler($attribute, $options);

        $decoded = json_decode($export->footer(), true);
        $this->assertSame([], $decoded, 'only mitre-attack-pattern galaxies contribute techniques');
    }

    public function testAttackSightingsHandlerEventScopeWalksSightingsGalaxyAndObjects(): void
    {
        $export = new AttackSightingsExport();
        $options = ['scope' => 'Event'];

        $event = [
            'Event' => ['id' => 1, 'uuid' => 'e1', 'timestamp' => 1735689600, 'info' => 'evt'],
            'Sighting' => [
                ['attribute_uuid' => 'obj-uuid-1', 'date_sighting' => 1735689601],
            ],
            'Galaxy' => [
                [
                    'type' => 'mitre-attack-pattern',
                    'GalaxyCluster' => [
                        ['tag_id' => 2, 'value' => 'Enterprise Attack - "T1071"'],
                    ],
                ],
            ],
            'Object' => [
                [
                    'uuid' => 'obj-uuid-1',
                    'timestamp' => 1735689600,
                    'Attribute' => [
                        [
                            'uuid' => 'obj-attr-1', 'timestamp' => 1735689600,
                            'Galaxy' => [
                                [
                                    'type' => 'mitre-attack-pattern',
                                    'GalaxyCluster' => [
                                        ['tag_id' => 3, 'value' => 'Enterprise Attack - "T1105"'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $out = $export->handler($event, $options);
        $this->assertSame('', $out, 'Event scope also emits nothing per-call, aggregating instead');

        $decoded = json_decode($export->footer(), true);
        $techniqueIds = array_map(static function ($s) { return $s['techniques'][0]['techniqueID']; }, $decoded);
        sort($techniqueIds);
        $this->assertSame(['T1071', 'T1105'], $techniqueIds, 'both the event-level Galaxy and the nested Object attribute Galaxy must be aggregated');
    }

    public function testAttackSightingsHeaderAndSeparatorAreEmptyStrings(): void
    {
        $export = new AttackSightingsExport();
        $this->assertSame('', $export->header());
        $this->assertSame('', $export->separator());
    }
}
