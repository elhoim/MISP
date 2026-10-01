<?php

use PHPUnit\Framework\TestCase;

/**
 * Coverage for MISP's dependency-free array->string converters and the
 * structural rules of ServerSettingGroups.
 *
 * XMLConverterTool and IOCExportTool sections are CHARACTERIZATION tests:
 * they pin what these pure converters actually emit today, including two
 * KNOWN-DEFECTs documented inline. The ServerSettingGroups section is a
 * SPECIFICATION: it asserts the invariant the class's own docblock promises
 * — every declared setting belongs to exactly one section, and an unknown
 * setting is never silently dropped.
 */
class ConverterToolsTest extends TestCase
{
    protected function tearDown(): void
    {
        // Several tests toggle this global setting; never let it leak into
        // a later test in this class (or another agent's suite) if an
        // assertion fails mid-test and skips an inline reset.
        Configure::delete('MISP.showorg');
    }

    // ============================================================ XMLConverterTool

    public function testGenerateTopAndBottomFrameAResponseDocument(): void
    {
        $tool = new XMLConverterTool();
        $this->assertSame('<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL . '<response>' . PHP_EOL, $tool->generateTop());
        $this->assertSame('</response>' . PHP_EOL, $tool->generateBottom());
    }

    public function testRecursiveEchoWrapsScalarsAndRepeatsListEntries(): void
    {
        $tool = new XMLConverterTool();
        $xml = $tool->recursiveEcho(array(
            'id' => 1,
            'Attribute' => array(
                array('value' => 'a'),
                array('value' => 'b'),
            ),
        ));

        $this->assertSame('<id>1</id><Attribute><value>a</value></Attribute><Attribute><value>b</value></Attribute>', $xml);
    }

    public function testRecursiveEchoSelfClosesEmptyNullAndEmptyStringFields(): void
    {
        $tool = new XMLConverterTool();
        $xml = $tool->recursiveEcho(array(
            'empty_string' => '',
            'is_null' => null,
            'empty_list' => array(),
        ));

        $this->assertSame('<empty_string/><is_null/><empty_list/>', $xml);
    }

    public function testRecursiveEchoRendersFalseAsZeroRatherThanSelfClosing(): void
    {
        // false is treated as a "present but zero" value, not an absent one -
        // it takes the '0' branch, distinct from '' / null above.
        $tool = new XMLConverterTool();
        $xml = $tool->recursiveEcho(array('to_ids' => false));
        $this->assertSame('<to_ids>0</to_ids>', $xml, 'false must render as the literal value 0, not a self-closing tag');
    }

    public function testRecursiveEchoEscapesXmlSpecialCharacters(): void
    {
        $tool = new XMLConverterTool();
        $xml = $tool->recursiveEcho(array('info' => '<a> & "quote" \'apos\''));

        $this->assertSame('<info>&lt;a&gt; &amp; &quot;quote&quot; &apos;apos&apos;</info>', $xml);
    }

    public function testRecursiveEchoEscapesAmpersandBeforeIntroducingNewOnes(): void
    {
        // & is escaped first in __toEscape/__escapeWith. If < were escaped
        // first (producing "&lt;") and then & were escaped, the & from that
        // very substitution would be re-escaped into "&amp;lt;". Pin that it
        // is not.
        $tool = new XMLConverterTool();
        $xml = $tool->recursiveEcho(array('info' => '<'));
        $this->assertSame('<info>&lt;</info>', $xml, 'escaping order must not double-encode the ampersand it introduces');
    }

    public function testFrameCollectionYieldsHeaderBodyVersionAndFooter(): void
    {
        $tool = new XMLConverterTool();
        $parts = iterator_to_array($tool->frameCollection('<Event><id>1</id></Event>', '2.4.190'));
        $joined = implode('', $parts);

        $this->assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL . '<response>' . PHP_EOL, $joined);
        $this->assertStringContainsString('<Event><id>1</id></Event>' . PHP_EOL, $joined);
        $this->assertStringContainsString('<xml_version>2.4.190</xml_version>', $joined);
        $this->assertStringEndsWith('</response>' . PHP_EOL, $joined);
    }

    public function testFrameCollectionOmitsVersionTagWhenNoVersionGiven(): void
    {
        $tool = new XMLConverterTool();
        $joined = implode('', iterator_to_array($tool->frameCollection('<Event/>')));
        $this->assertStringNotContainsString('xml_version', $joined);
    }

    public function testConvertProducesParsableXmlAndHidesOrgWhenShoworgIsOff(): void
    {
        Configure::write('MISP.showorg', false);
        $tool = new XMLConverterTool();
        $event = $this->minimalEvent();

        $xml = $tool->convert($event, false);

        $sx = new SimpleXMLElement('<root>' . $xml . '</root>');
        $this->assertSame('2', (string)$sx->Event->id);
        $this->assertSame('Test event <ok>', (string)$sx->Event->info, 'info must survive the round trip unescaped by the XML parser');
        $this->assertFalse(isset($sx->Event->Org), 'Org must be hidden when MISP.showorg is off and the caller is not a site admin');
    }

    public function testConvertKeepsOrgWhenShoworgIsOn(): void
    {
        Configure::write('MISP.showorg', true);
        $tool = new XMLConverterTool();
        $event = $this->minimalEvent();

        $xml = $tool->convert($event, false);
        $sx = new SimpleXMLElement('<root>' . $xml . '</root>');

        $this->assertTrue(isset($sx->Event->Org), 'Org must be present once MISP.showorg is enabled');
        $this->assertSame('OrgA', (string)$sx->Event->Org->name);
    }

    public function testConvertMovesTopLevelAttributeUnderEvent(): void
    {
        Configure::write('MISP.showorg', true);
        $tool = new XMLConverterTool();
        $event = $this->minimalEvent();
        $event['Attribute'] = array(
            array('id' => 10, 'uuid' => 'attr-uuid', 'type' => 'ip-src', 'value' => '1.2.3.4', 'to_ids' => true),
        );

        $xml = $tool->convert($event, false);
        $sx = new SimpleXMLElement('<root>' . $xml . '</root>');

        $this->assertSame('1.2.3.4', (string)$sx->Event->Attribute->value, 'a top-level Attribute list must be rearranged under Event');
        $this->assertSame('1', (string)$sx->Event->Attribute->to_ids, 'true must render as 1, mirroring the false-as-0 rule');
    }

    public function testConvertArrayNeverPopulatesRelatedAttributeBecauseEventIsUndefinedInsideTheHelper(): void
    {
        // KNOWN-DEFECT: XMLConverterTool::__rearrangeAttributes() guards the
        // RelatedAttribute enrichment with
        //   isset($event['Event']['RelatedAttribute']) && isset($event['Event']['RelatedAttribute'][$value['id']])
        // but $event is not a parameter of that method, not captured from an
        // enclosing closure, and not global - it is simply undefined in that
        // scope. isset() on an undefined variable is false rather than a
        // fatal error, so this branch can never run: no attribute ever gains
        // a RelatedAttribute key via this path, regardless of what the event
        // being converted actually contains under Event.RelatedAttribute.
        Configure::write('MISP.showorg', true);
        $tool = new XMLConverterTool();
        $event = $this->minimalEvent();
        $event['Event']['RelatedAttribute'] = array(10 => array(array('id' => 99, 'info' => 'related')));
        $event['Attribute'] = array(
            array('id' => 10, 'uuid' => 'attr-uuid', 'type' => 'ip-src', 'value' => '1.2.3.4', 'to_ids' => true),
        );

        $xmlArray = $tool->convertArray($event, false);

        $this->assertArrayNotHasKey(
            'RelatedAttribute',
            $xmlArray['Event']['Attribute'][0],
            'the RelatedAttribute enrichment branch is dead code, so it must never attach the key'
        );
    }

    public function testConvertArrayMapsEventTagToTagAndStripsOrgId(): void
    {
        Configure::write('MISP.showorg', true);
        $tool = new XMLConverterTool();
        $event = $this->minimalEvent();
        $event['EventTag'] = array(
            array('Tag' => array('id' => 5, 'name' => 'tlp:red', 'org_id' => 3)),
        );

        $xmlArray = $tool->convertArray($event, false);

        $this->assertSame('tlp:red', $xmlArray['Event']['Tag'][0]['name']);
        $this->assertArrayNotHasKey('org_id', $xmlArray['Event']['Tag'][0], 'org_id must not be exposed for an event tag');
    }

    public function testConvertArrayWrapsEventLevelShadowAttributeOrgAsAList(): void
    {
        Configure::write('MISP.showorg', true);
        $tool = new XMLConverterTool();
        $event = $this->minimalEvent();
        $event['ShadowAttribute'] = array(
            array('id' => 1, 'value' => 'x', 'Org' => array('id' => 9, 'name' => 'ShadowOrg'), 'EventOrg' => array('id' => 9, 'name' => 'ShadowOrg')),
        );

        $xmlArray = $tool->convertArray($event, false);
        $sa = $xmlArray['Event']['ShadowAttribute'][0];

        $this->assertSame('ShadowOrg', $sa['Org'][0]['name'], 'Org must be wrapped as a single-element list so it renders as one <Org> tag, not scalar fields on ShadowAttribute');
        $this->assertSame('ShadowOrg', $sa['EventOrg'][0]['name']);
    }

    public function testConvertArrayRestructuresRelatedEventAndDropsUserId(): void
    {
        Configure::write('MISP.showorg', true);
        $tool = new XMLConverterTool();
        $event = $this->minimalEvent();
        $event['RelatedEvent'] = array(
            array('Event' => array(
                'id' => 99, 'info' => 'Related', 'user_id' => 42,
                'Org' => array('id' => 1, 'name' => 'OrgA'),
                'Orgc' => array('id' => 1, 'name' => 'OrgA'),
            )),
        );

        $xmlArray = $tool->convertArray($event, false);
        $related = $xmlArray['Event']['RelatedEvent'][0]['Event'][0];

        $this->assertSame('99', (string)$related['id']);
        $this->assertArrayNotHasKey('user_id', $related, 'a related event must not leak the linking user id');
        $this->assertSame('OrgA', $related['Org'][0]['name'], 'Org must be wrapped as a single-element list, mirroring the top-level Event');
    }

    public function testConvertArrayMapsAttributeTagToTagAndStripsInternalFields(): void
    {
        Configure::write('MISP.showorg', true);
        $tool = new XMLConverterTool();
        $event = $this->minimalEvent();
        $event['Attribute'] = array(array(
            'id' => 10, 'uuid' => 'attr-uuid', 'type' => 'ip-src', 'value' => '1.2.3.4', 'to_ids' => true,
            'value1' => '1.2.3.4', 'value2' => '', 'category_order' => 3,
            'AttributeTag' => array(
                array('Tag' => array('id' => 7, 'name' => 'attr-tag', 'org_id' => 3)),
            ),
        ));

        $xmlArray = $tool->convertArray($event, false);
        $attribute = $xmlArray['Event']['Attribute'][0];

        $this->assertArrayNotHasKey('value1', $attribute);
        $this->assertArrayNotHasKey('value2', $attribute);
        $this->assertArrayNotHasKey('category_order', $attribute);
        $this->assertArrayNotHasKey('AttributeTag', $attribute, 'AttributeTag must be consumed and removed');
        $this->assertSame('attr-tag', $attribute['Tag'][0]['name']);
        $this->assertArrayNotHasKey('org_id', $attribute['Tag'][0]);
    }

    public function testConvertArrayWrapsAnEventLevelSharingGroupAsAList(): void
    {
        // Contrast case for the KNOWN-DEFECT below: at the event level,
        // convertArray() reads $event['SharingGroup'] (top-level) and writes
        // to the *different* location $event['Event']['SharingGroup'][0], so
        // there is no self-overwrite-then-unset - the event-level wrap
        // actually survives to the export, unlike the attribute-level one.
        Configure::write('MISP.showorg', true);
        $tool = new XMLConverterTool();
        $event = $this->minimalEvent();
        $event['SharingGroup'] = array(
            'id' => 4,
            'name' => 'SG',
            'SharingGroupOrg' => array(
                array('Organisation' => array('id' => 1, 'name' => 'OrgA')),
            ),
        );

        $xmlArray = $tool->convertArray($event, false);
        $sg = $xmlArray['Event']['SharingGroup'][0];

        $this->assertSame('SG', $sg['name']);
        $this->assertSame('OrgA', $sg['SharingGroupOrg'][0]['Organisation'][0]['name'], 'SharingGroupOrg entries wrap their Organisation as a single-element list too');
    }

    public function testConvertArrayDropsAnAttributesSharingGroupEntirely(): void
    {
        // KNOWN-DEFECT: __rearrangeAttributes() intends to wrap an
        // attribute's SharingGroup as a single-element list, the same way
        // the event-level SharingGroup is wrapped a few lines earlier in
        // convertArray(). But the event-level code reads from $event
        // top-level and writes into $event['Event']['SharingGroup'][0] - two
        // different array locations - while the attribute-level code both
        // reads AND writes $attributes[$key]['SharingGroup'], then
        // immediately unsets that same key:
        //   $attributes[$key]['SharingGroup'][0] = $attributes[$key]['SharingGroup'];
        //   unset($attributes[$key]['SharingGroup']);
        // The unset removes the key the previous line just populated, so an
        // attribute's SharingGroup is silently deleted from the XML export
        // rather than wrapped.
        Configure::write('MISP.showorg', true);
        $tool = new XMLConverterTool();
        $event = $this->minimalEvent();
        $event['Attribute'] = array(array(
            'id' => 10, 'uuid' => 'attr-uuid', 'type' => 'ip-src', 'value' => '1.2.3.4', 'to_ids' => true,
            'SharingGroup' => array('id' => 4, 'name' => 'SG'),
        ));

        $xmlArray = $tool->convertArray($event, false);

        $this->assertArrayNotHasKey(
            'SharingGroup',
            $xmlArray['Event']['Attribute'][0],
            'an attribute-level SharingGroup never survives to the exported XML'
        );
    }

    private function minimalEvent(): array
    {
        return array(
            'Event' => array(
                'id' => 2,
                'uuid' => 'event-uuid',
                'date' => '2024-01-01',
                'info' => 'Test event <ok>',
            ),
            'Org' => array('id' => 1, 'name' => 'OrgA'),
            'Orgc' => array('id' => 1, 'name' => 'OrgA'),
        );
    }

    // ================================================================ IOCExportTool

    public function testCheckValidTypeForIocAcceptsOnlyTheListedCategories(): void
    {
        $tool = new IOCExportTool();
        $this->assertTrue($tool->checkValidTypeForIOC(array('category' => 'Network activity')));
        $this->assertFalse($tool->checkValidTypeForIOC(array('category' => 'Internal reference')));
    }

    public function testGenerateAttributeSkipsAttributesNotFlaggedForIds(): void
    {
        $tool = new IOCExportTool();
        $attribute = array('category' => 'Network activity', 'type' => 'ip-src', 'value' => '1.2.3.4', 'to_ids' => 0, 'uuid' => 'u1');
        $this->assertFalse($tool->generateAttribute($attribute), 'to_ids=0 must be excluded from the IOC document');
    }

    public function testGenerateAttributeSkipsCategoriesNotEligibleForIoc(): void
    {
        $tool = new IOCExportTool();
        $attribute = array('category' => 'Internal reference', 'type' => 'ip-src', 'value' => '1.2.3.4', 'to_ids' => 1, 'uuid' => 'u1');
        $this->assertFalse($tool->generateAttribute($attribute));
    }

    public function testGenerateAttributeFramesASimpleIndicator(): void
    {
        $tool = new IOCExportTool();
        $attribute = array('category' => 'Network activity', 'type' => 'ip-src', 'value' => '1.2.3.4', 'to_ids' => 1, 'uuid' => 'attr-uuid');

        $xml = $tool->generateAttribute($attribute);

        $this->assertStringContainsString('IndicatorItem id="attr-uuid"', $xml);
        $this->assertStringContainsString('search="PortItem/remoteIP"', $xml);
        $this->assertStringContainsString('<Content type="IP">1.2.3.4</Content>', $xml);
    }

    public function testGenerateAttributeUnwrapsANestedAttributeKey(): void
    {
        $tool = new IOCExportTool();
        $wrapped = array('Attribute' => array(
            'category' => 'Network activity', 'type' => 'ip-src', 'value' => '1.2.3.4', 'to_ids' => 1, 'uuid' => 'attr-uuid',
        ));

        $xml = $tool->generateAttribute($wrapped);
        $this->assertStringContainsString('<Content type="IP">1.2.3.4</Content>', $xml);
    }

    public function testGenerateAttributeFramesACompositeIndicatorAsTwoAndedItems(): void
    {
        $tool = new IOCExportTool();
        $attribute = array(
            'category' => 'Payload delivery', 'type' => 'filename|md5',
            'value' => 'test.exe|d41d8cd98f00b204e9800998ecf8427e',
            'to_ids' => 1, 'uuid' => 'composite-uuid',
        );

        $xml = $tool->generateAttribute($attribute);

        $this->assertStringContainsString('<Indicator operator="AND" id="composite-uuid">', $xml);
        $this->assertStringContainsString('<Content type="string">test.exe</Content>', $xml);
        $this->assertStringContainsString('<Content type="md5">d41d8cd98f00b204e9800998ecf8427e</Content>', $xml);
    }

    public function testGenerateAttributeTreatsMalwareSampleAsFilenameMd5Composite(): void
    {
        $tool = new IOCExportTool();
        $attribute = array(
            'category' => 'Payload delivery', 'type' => 'malware-sample',
            'value' => 'test.exe|d41d8cd98f00b204e9800998ecf8427e',
            'to_ids' => 1, 'uuid' => 'ms-uuid',
        );

        $xml = $tool->generateAttribute($attribute);
        $this->assertStringContainsString('<Content type="string">test.exe</Content>', $xml, 'malware-sample must be remapped to the filename|md5 composite');
    }

    public function testFrameIndicatorIndentsFurtherWhenPartOfAComposite(): void
    {
        $tool = new IOCExportTool();
        $plain = $tool->frameIndicator(array('FileItem', 'FileItem/Md5sum', 'md5'), 'u1', 'abc', false);
        $nested = $tool->frameIndicator(array('FileItem', 'FileItem/Md5sum', 'md5'), 'u1', 'abc', true);

        $this->assertStringStartsWith(str_repeat(' ', 6) . '<IndicatorItem', $plain);
        $this->assertStringStartsWith(str_repeat(' ', 8) . '<IndicatorItem', $nested);
    }

    public function testGenerateSingleTopEmbedsEventMetadata(): void
    {
        $tool = new IOCExportTool();
        $event = array(
            'Event' => array('id' => 2, 'uuid' => 'event-uuid', 'date' => '2024-01-01', 'info' => 'Test event'),
            'Orgc' => array('name' => 'OrgA'),
        );

        $top = $tool->generateSingleTop($event);

        $this->assertStringContainsString('id="event-uuid"', $top);
        $this->assertStringContainsString('<short_description>Event #2</short_description>', $top);
        $this->assertStringContainsString('<authored_by>OrgA</authored_by>', $top);
    }

    public function testGenerateTopEmbedsTheRequestingUsersOrganisation(): void
    {
        $tool = new IOCExportTool();
        $top = $tool->generateTop(array('Organisation' => array('name' => 'OrgB')));

        $this->assertStringContainsString('<authored_by>OrgB</authored_by>', $top);
        $this->assertStringContainsString('<short_description>Filtered indicator list</short_description>', $top);
    }

    public function testBuildAllForAnEventScopeProducesAWellFormedIocDocument(): void
    {
        $tool = new IOCExportTool();
        $data = array(
            'Event' => array('id' => 2, 'uuid' => 'event-uuid', 'date' => '2024-01-01', 'info' => 'Test event'),
            'Orgc' => array('name' => 'OrgA'),
            'Attribute' => array(
                array('category' => 'Network activity', 'type' => 'ip-src', 'value' => '1.2.3.4', 'to_ids' => 1, 'uuid' => 'a1'),
                // filtered out: not eligible for IOC export, must not appear or break the document
                array('category' => 'Internal reference', 'type' => 'comment', 'value' => 'note', 'to_ids' => 1, 'uuid' => 'a2'),
            ),
        );

        $ioc = $tool->buildAll(array('Organisation' => array('name' => 'OrgA')), $data, 'event');

        $sx = new SimpleXMLElement($ioc);
        $this->assertStringContainsString('<Content type="IP">1.2.3.4</Content>', $ioc);
        $this->assertStringNotContainsString('IndicatorItem id="a2"', $ioc, 'an ineligible attribute must not leak into the document');
        $this->assertSame('event-uuid', (string)$sx['id']);
    }

    public function testBuildAllForANonEventScopeUsesTheGenericTop(): void
    {
        $tool = new IOCExportTool();
        $data = array(
            'Attribute' => array(
                array('category' => 'Network activity', 'type' => 'ip-src', 'value' => '5.6.7.8', 'to_ids' => 1, 'uuid' => 'a1'),
            ),
        );

        $ioc = $tool->buildAll(array('Organisation' => array('name' => 'OrgA')), $data, 'search');

        $this->assertStringContainsString('<short_description>Filtered indicator list</short_description>', $ioc);
        $this->assertStringContainsString('<Content type="IP">5.6.7.8</Content>', $ioc);
    }

    public function testConvertReturnsOnlyIndicatorsWithNoHeaderOrFooter(): void
    {
        $tool = new IOCExportTool();
        $data = array('Attribute' => array(
            array('category' => 'Network activity', 'type' => 'ip-src', 'value' => '9.9.9.9', 'to_ids' => 1, 'uuid' => 'a1'),
        ));

        $result = $tool->convert($data);

        $this->assertStringContainsString('<Content type="IP">9.9.9.9</Content>', $result);
        $this->assertStringNotContainsString('<?xml', $result, 'convert() must not include the document header');
        $this->assertStringNotContainsString('</ioc>', $result, 'convert() must not include the document footer');
    }

    public function testGetResultIsDeadCodeThatWarnsOnAnUndefinedProperty(): void
    {
        // KNOWN-DEFECT: getResult() reads $this->__final, but no method in
        // this class ever assigns that property (grep confirms the single
        // occurrence of "__final" in the file is this read). PHP 8 raises
        // "Undefined property" for the access - pinned here via a local error
        // handler rather than expectWarning(), which PHPUnit 9 deprecates -
        // so the method can never return a built document: it always warns
        // and yields null instead.
        $caught = null;
        set_error_handler(static function (int $errno, string $errstr) use (&$caught): bool {
            $caught = $errstr;
            return true;
        }, E_WARNING);

        $result = (new IOCExportTool())->getResult();

        restore_error_handler();

        $this->assertStringContainsString('__final', (string)$caught, 'reading the property must warn about the very property that is never set');
        $this->assertNull($result, 'with the property never assigned, getResult() can only ever return null');
    }

    // =========================================================== ServerSettingGroups

    public function tabsWithStaticDefinitionsProvider(): array
    {
        return array(
            'SimpleBackgroundJobs' => array('SimpleBackgroundJobs'),
            'Proxy' => array('Proxy'),
            'Encryption' => array('Encryption'),
            'MISP' => array('MISP'),
            'Security' => array('Security'),
        );
    }

    /**
     * @dataProvider tabsWithStaticDefinitionsProvider
     */
    public function testEveryDeclaredSettingBelongsToExactlyOneSection(string $tab): void
    {
        $definitions = ServerSettingGroups::definitions($tab);
        $this->assertNotEmpty($definitions, "$tab must declare at least one section for this invariant to be meaningful");

        $allSettings = array();
        foreach ($definitions as $section) {
            foreach ($section['settings'] as $name) {
                $allSettings[] = $name;
            }
        }

        $this->assertSame(
            count($allSettings),
            count(array_unique($allSettings)),
            "every setting declared under $tab must be claimed by exactly one section, not zero and not several"
        );
    }

    public function testSplitDistributesKnownSettingsToTheirDeclaredSectionsInOrder(): void
    {
        $settings = array(
            array('setting' => 'SimpleBackgroundJobs.max_job_history_ttl', 'level' => 1),
            array('setting' => 'SimpleBackgroundJobs.redis_host', 'level' => 0),
            array('setting' => 'SimpleBackgroundJobs.supervisor_port', 'level' => 2),
            array('setting' => 'SimpleBackgroundJobs.enabled', 'level' => 0),
        );

        $sections = ServerSettingGroups::split('SimpleBackgroundJobs', $settings);

        $this->assertSame(array('jobs', 'jobs-redis', 'supervisor'), array_column($sections, 'id'), 'sections must appear in their declared order, and only sections that received a setting');
        $this->assertSame(
            array('SimpleBackgroundJobs.enabled', 'SimpleBackgroundJobs.max_job_history_ttl'),
            array_column($sections[0]['settings'], 'setting'),
            // KNOWN-DEFECT: split() walks $definition['settings'] (the
            // static declaration order) and looks each name up in the input,
            // so a section's settings always come out in *declared* order
            // regardless of the order they arrived in - here 'enabled' before
            // 'max_job_history_ttl', though the input above has it second.
            // The class docblock for split() promises severity-sorted input
            // order is preserved ("criticals come first"); it is not - a
            // critical (level 0) input setting that is declared after a
            // recommended one in $groups still renders after it.
            'declared order wins over input/severity order, contradicting the split() docblock'
        );
    }

    public function testSplitSendsAnUnknownSettingToTheCatchAllSection(): void
    {
        $settings = array(
            array('setting' => 'SimpleBackgroundJobs.enabled', 'level' => 0),
            array('setting' => 'SimpleBackgroundJobs.this_setting_does_not_exist', 'level' => 2),
        );

        $sections = ServerSettingGroups::split('SimpleBackgroundJobs', $settings);
        $catchAll = end($sections);

        $this->assertSame(ServerSettingGroups::FALLBACK_ID, $catchAll['id'], 'an unrecognised setting must not be silently dropped');
        $this->assertSame(
            array('SimpleBackgroundJobs.this_setting_does_not_exist'),
            array_column($catchAll['settings'], 'setting')
        );
    }

    public function testSplitTitlesTheCatchAllAsSettingsWhenItIsTheOnlySection(): void
    {
        // A tab with no matching declared section at all still must not lose
        // the setting - and the lone section is titled generically rather
        // than "Other settings" (there is nothing for it to be "other" than).
        $settings = array(array('setting' => 'SimpleBackgroundJobs.totally_unknown', 'level' => 2));

        $sections = ServerSettingGroups::split('SimpleBackgroundJobs', $settings);

        $this->assertCount(1, $sections);
        $this->assertSame(ServerSettingGroups::FALLBACK_ID, $sections[0]['id']);
    }

    public function testSplitOmitsHiddenSettingsFromEverySection(): void
    {
        $settings = array(
            array('setting' => 'Security.salt', 'level' => 0),
        );

        $sections = ServerSettingGroups::split('Security', $settings);

        $this->assertTrue(ServerSettingGroups::isHidden('Security.salt'));
        foreach ($sections as $section) {
            $names = array_column($section['settings'], 'setting');
            $this->assertNotContains('Security.salt', $names, 'a hidden setting must not surface in any section, including the catch-all');
        }
    }

    public function testSplitOmitsSectionsThatReceivedNoSettings(): void
    {
        // Proxy declares 'proxy-endpoint' and 'proxy-authentication'; feed
        // only a setting from the first.
        $settings = array(array('setting' => 'Proxy.host', 'level' => 0));

        $sections = ServerSettingGroups::split('Proxy', $settings);

        $this->assertSame(array('proxy-endpoint'), array_column($sections, 'id'), 'an empty section must be dropped, not emitted with an empty settings list');
    }

    public function testSplitByPluginSubGroupOrdersDeclaredGroupsBeforeUnknownAlphabeticalOnes(): void
    {
        $settings = array(
            array('setting' => 'Plugin.Zzz_unknown_module_setting', 'level' => 2, 'subGroup' => 'Zzz'),
            array('setting' => 'Plugin.Cortex_services_url', 'level' => 0, 'subGroup' => 'Cortex'),
            array('setting' => 'Plugin.Aaa_unknown_module_setting', 'level' => 2, 'subGroup' => 'Aaa'),
            array('setting' => 'Plugin.Import_ocr_enabled', 'level' => 2, 'subGroup' => 'Import'),
        );

        $sections = ServerSettingGroups::split('Plugin', $settings);

        // Import precedes Cortex in $subGroupTabs declaration order; both
        // precede the unknown groups, which are alphabetical (Aaa, Zzz) -
        // there is no single shared catch-all id for this tab.
        $this->assertSame(array('import', 'cortex', 'aaa', 'zzz'), array_column($sections, 'id'));
        $this->assertNotContains(ServerSettingGroups::FALLBACK_ID, array_column($sections, 'id'), 'subGroup-driven tabs never use the flat catch-all id');
    }

    public function testSplitByPluginSubGroupGivesUnknownGroupsAGenericStyle(): void
    {
        $settings = array(array('setting' => 'Plugin.Mystery_enabled', 'level' => 2, 'subGroup' => 'Mystery'));

        $sections = ServerSettingGroups::split('Plugin', $settings);

        $this->assertSame('mystery', $sections[0]['id']);
        $this->assertSame('puzzle-piece', $sections[0]['icon']);
        $this->assertSame('#6c757d', $sections[0]['accent']);
    }

    public function testSplitByPluginSubGroupDerivesTheGroupFromTheSettingNameWhenNotSupplied(): void
    {
        // subGroupOf() falls back to the token before the first underscore
        // of the part after the first dot when 'subGroup' is absent.
        $settings = array(array('setting' => 'Plugin.Enrichment_dns_enabled', 'level' => 2));

        $sections = ServerSettingGroups::split('Plugin', $settings);

        $this->assertSame('enrichment', $sections[0]['id']);
        $this->assertSame('Enrichment modules', $sections[0]['title'], 'the derived group must still resolve to its declared style');
    }

    public function testWithCountersCountsErrorsPerLevelButExcludesDeprecated(): void
    {
        $settings = array(
            array('setting' => 'SimpleBackgroundJobs.enabled', 'level' => 0, 'error' => true),
            array('setting' => 'SimpleBackgroundJobs.max_job_history_ttl', 'level' => 1, 'error' => true),
            array('setting' => 'SimpleBackgroundJobs.redis_host', 'level' => 0), // no error
            array('setting' => 'SimpleBackgroundJobs.redis_port', 'level' => 3, 'error' => true), // deprecated
        );

        $sections = ServerSettingGroups::split('SimpleBackgroundJobs', $settings);
        $jobsSection = $sections[0];
        $redisSection = $sections[1];

        $this->assertSame(array(0 => 1, 1 => 1, 2 => 0), $jobsSection['errorsByLevel']);
        $this->assertSame(array(0 => 0, 1 => 0, 2 => 0), $redisSection['errorsByLevel'], 'a deprecated (level 3) error must not be counted');
    }

    public function testIsKnownTabAndHasGroups(): void
    {
        $this->assertTrue(ServerSettingGroups::isKnownTab('Security'));
        $this->assertFalse(ServerSettingGroups::isKnownTab('NotARealTab'));

        $this->assertTrue(ServerSettingGroups::hasGroups('Security'), 'Security has a static $groups entry');
        $this->assertTrue(ServerSettingGroups::hasGroups('Plugin'), 'Plugin is driven by $subGroupTabs instead');
        $this->assertFalse(ServerSettingGroups::hasGroups('correlations'), 'correlations has neither and falls back to a single flat list at the view layer');
    }
}
