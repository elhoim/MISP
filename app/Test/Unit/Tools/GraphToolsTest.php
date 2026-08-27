<?php

use MispTest\Support\FakeModel;
use PHPUnit\Framework\TestCase;

require_once APP . 'Test/Support/FakeModel.php';
require_once APP . 'Lib/Tools/EventGraphTool.php';
require_once APP . 'Lib/Tools/CorrelationGraphTool.php';
require_once APP . 'Lib/Tools/EventTimelineTool.php';
require_once APP . 'Lib/Tools/DistributionGraphTool.php';

/**
 * `Event` is a hard `Event $eventModel` type-hint on two of these tools'
 * constructors (CorrelationGraphTool, DistributionGraphTool). The real
 * CakePHP model needs a database connection to build, so a minimal stand-in
 * that only satisfies the class name is declared once, here, for the whole
 * file. It behaves exactly like FakeModel (any method returns [] unless
 * canned, any property is a nested fake unless set).
 */
if (!class_exists('Event', false)) {
    eval('class Event extends \MispTest\Support\FakeModel {}');
}

/**
 * CorrelationGraphTool unconditionally builds `new OrgImgHelper(new View())`
 * in its constructor -- there is no seam to skip it. Rather than pull in
 * CakePHP's real View/Helper stack, this declares the minimal local stand-ins
 * OrgImgHelper's real (in-repo) code needs: a View with an Image helper, and
 * an AppHelper that actually stores the View it's given (the shared
 * FrameworkStubs `Helper` stub deliberately does not, so it must be
 * overridden here before OrgImgHelper.php is loaded).
 */
if (!class_exists('View', false)) {
    eval('class View {
        public $Image;
        public $viewVars = ["baseurl" => "https://misp.test"];
        public function __construct($c = null) { $this->Image = new \MispTest\Support\FakeModel(["base64" => "data:image/png;base64,Zg=="]); }
    }');
}
// A stand-in AppHelper: the real one is a thin wrapper whose protected
// $_View is not reachable the way these helpers use it. NOTE this declaration
// wins the AppHelper name for the WHOLE PHPUnit process, because PHPUnit
// includes every *Test.php during discovery. Any suite that afterwards does an
// unguarded `require_once APP . 'View/Helper/AppHelper.php'` will hit an
// uncatchable "Cannot declare class AppHelper" fatal that aborts the entire
// run. That happened once and cost 793 unexecuted tests. Guard any such load
// with class_exists('AppHelper', false).
if (!class_exists('AppHelper', false)) {
    eval('class AppHelper extends Helper {
        public $_View;
        public function __construct($view = null, $s = []) { parent::__construct($view, $s); $this->_View = $view; }
    }');
}
require_once APP . 'View/Helper/OrgImgHelper.php';

/**
 * Behavioural tests for the pure graph/timeline/distribution shaping tools in
 * Lib/Tools. All four take an already-fetched event array (or equivalent)
 * from an injected model and reshape it into vis.js/timeline/chart-ready
 * arrays; none of them touch HTTP, and the only "database" use is a single
 * fetchEvent()-shaped call, which is stubbed via FakeModel's canned returns.
 *
 * Where the source docblock or naming implies a rule ("filter rules select
 * which nodes appear"), the assertion is a SPECIFICATION. Where behaviour is
 * simply what the code happens to do today (e.g. object-colour assignment,
 * the sighting-timeline extrapolation heuristic), it is a CHARACTERIZATION.
 * Each test says which via its own comment.
 */
class GraphToolsTest extends TestCase
{
    protected function setUp(): void
    {
        ClassRegistry::reset();
    }

    // ============================================================ EventGraphTool

    private function eventGraphFixture(): array
    {
        $attr = [
            'id' => 10, 'uuid' => 'attr-uuid-10', 'event_id' => 1,
            'category' => 'Network activity', 'type' => 'ip-dst', 'value' => '1.2.3.4',
            'comment' => 'c1', 'to_ids' => 1, 'timestamp' => 1000, 'distribution' => 0,
            'object_relation' => null,
            'AttributeTag' => [['Tag' => ['id' => 5, 'name' => 'tlp:green', 'colour' => '#00ff00']]],
        ];
        $objAttr = [
            'id' => 20, 'uuid' => 'oa-uuid-20', 'event_id' => 1,
            'category' => 'Other', 'type' => 'text', 'value' => 'v1',
            'comment' => '', 'to_ids' => 0, 'timestamp' => 1000, 'distribution' => 0,
            'object_relation' => 'first-seen', 'AttributeTag' => [],
        ];
        $obj = [
            'id' => 30, 'uuid' => 'obj-uuid-30', 'name' => 'file', 'meta-category' => 'file',
            'template_uuid' => 'tmpl-uuid-1', 'event_id' => 1, 'comment' => '',
            'Attribute' => [$objAttr],
            'ObjectReference' => [
                ['id' => 40, 'uuid' => 'ref-uuid-40', 'referenced_type' => 0, 'referenced_id' => 10,
                    'relationship_type' => 'contains', 'comment' => '', 'event_id' => 1, 'deleted' => false],
            ],
        ];
        return [['Event' => ['id' => 1, 'uuid' => 'evt-uuid-1'], 'Object' => [$obj], 'Attribute' => [$attr]]];
    }

    private function newEventGraphTool(array $filterRules = [], $fullevent = null): EventGraphTool
    {
        $eventModel = new FakeModel(['fetchEvent' => $fullevent ?? $this->eventGraphFixture()]);
        $eventModel->analysisLevels = [0 => 'Initial compromise'];
        $eventModel->Attribute = new FakeModel();
        $eventModel->Attribute->distributionLevels = [0 => 'Your organisation only'];
        $tagModel = new FakeModel(['find' => ['5' => 'tlp:green']]);

        $tool = new EventGraphTool();
        $tool->construct($eventModel, $tagModel, ['id' => 1], $filterRules);
        return $tool;
    }

    /**
     * NOTE ON SCOPE: get_references() is exercised here with an
     * attribute-only fixture (no Object). With any Object present it
     * unconditionally reaches addObjectColors() -> ColourPaletteTool::
     * generatePaletteFromString() -> Validation::uuid(), and the shared
     * Test/Support/FrameworkStubs.php Validation stub does not implement
     * uuid() (only ip()/url()) -- calling it is a hard `Call to undefined
     * method` PHP Error, not a catchable/assertable condition, and I do not
     * have write access to that shared file to add it. The object-node and
     * ObjectReference-relation shaping this same loop produces is instead
     * covered below via get_generic_from_key() and get_tags(), which build
     * the identical Object/ObjectReference structures but never call
     * addObjectColors().
     */
    public function testGetReferencesBuildsAttributeNodesFromAnAttributeOnlyEvent(): void
    {
        $fullevent = [['Event' => ['id' => 1, 'uuid' => 'evt-uuid-1'], 'Object' => [], 'Attribute' => [
            ['id' => 10, 'uuid' => 'attr-uuid-10', 'event_id' => 1, 'category' => 'Network activity',
                'type' => 'ip-dst', 'value' => '1.2.3.4', 'comment' => 'c1', 'to_ids' => 1, 'timestamp' => 1000,
                'distribution' => 0, 'object_relation' => null,
                'AttributeTag' => [['Tag' => ['id' => 5, 'name' => 'tlp:green', 'colour' => '#00ff00']]]],
        ]]];
        $tool = $this->newEventGraphTool([], $fullevent);
        $json = $tool->get_references(1);

        $this->assertCount(1, $json['items']);
        $this->assertSame('attribute', $json['items'][0]['node_type']);
        $this->assertSame('1.2.3.4', $json['items'][0]['label'], 'attribute label is its value');
        $this->assertSame([], $json['relations'], 'relations only ever come from ObjectReference, so an attribute-only event has none');
    }

    public function testGetReferencesOnMissingEventReturnsEmptyShapeNotNull(): void
    {
        $tool = $this->newEventGraphTool([], []); // fetchEvent() -> empty result
        $json = $tool->get_references(999);

        $this->assertSame([], $json['items']);
        $this->assertSame([], $json['relations']);
    }

    public function testTagPresenceDoNotContainFilterRemovesTaggedAttributeButKeepsUntaggedObject(): void
    {
        // SPECIFICATION: a "Do not contain <tag>" rule drops any attribute
        // carrying that tag; an object survives if none of its OWN
        // attributes (not the parent event's top-level attributes) carry it.
        // Routed through get_tags() rather than get_references() -- see the
        // scope note above -- but both apply the identical filtering step
        // before shaping items, so this exercises the same specification.
        // A second, untagged top-level attribute is included purely so at
        // least one survives filtering -- see the KNOWN-DEFECT test below
        // for why an event left with zero top-level attributes is unsafe.
        $fullevent = $this->eventGraphFixture();
        $fullevent[0]['Attribute'][] = [
            'id' => 11, 'uuid' => 'attr-uuid-11', 'event_id' => 1, 'category' => 'Other',
            'type' => 'comment', 'value' => 'untagged', 'comment' => '', 'to_ids' => 0, 'timestamp' => 1000,
            'distribution' => 0, 'object_relation' => null, 'AttributeTag' => [],
        ];
        $filterRules = ['presence' => [], 'value' => [], 'tag_presence' => [['Do not contain', 'tlp:green']]];
        $tool = $this->newEventGraphTool($filterRules, $fullevent);
        $json = $tool->get_tags(1);

        $ids = array_column($json['items'], 'id');
        $this->assertNotContains(10, $ids, 'the tlp:green-tagged attribute must be filtered out');
        $this->assertContains(11, $ids, 'the untagged attribute is unaffected');
        $this->assertContains('o-30', $ids, 'the object has no tagged attributes of its own, so it survives the same rule');
    }

    public function testGetTagsRecordsTheWrongObjectRelationBecauseOfAStaleLoopVariable(): void
    {
        // KNOWN-DEFECT: inside get_tags()'s object-attribute loop
        // (`foreach ($obj['Attribute'] as $ObjAttr)`), the existing_object_
        // relation bookkeeping line reads `$attr['object_relation']`
        // instead of `$ObjAttr['object_relation']`. $attr is whatever the
        // PRECEDING top-level-attribute foreach left behind (or, if the
        // event has no top-level attributes at all, undefined -- a PHP
        // warning). Consequence: the "existing object relations" set shown
        // to the filter-rule UI never reflects the object's own attributes;
        // it silently repeats the last top-level attribute's
        // object_relation (here, null -> the '' key) for every object
        // attribute instead of collecting 'first-seen'.
        $tool = $this->newEventGraphTool();
        $json = $tool->get_tags(1);

        $this->assertArrayHasKey('', $json['existing_object_relation'], 'the stale $attr (object_relation === null) is recorded instead of the object attribute\'s own value');
        $this->assertArrayNotHasKey('first-seen', $json['existing_object_relation'], 'the object attribute\'s real object_relation, "first-seen", is never reached');
    }

    public function testTagPresenceContainFilterKeepsOnlyTaggedItems(): void
    {
        // SPECIFICATION: the inverse rule keeps only items carrying the tag.
        $filterRules = ['presence' => [], 'value' => [], 'tag_presence' => [['Contains', 'tlp:green']]];
        $tool = $this->newEventGraphTool($filterRules);
        $json = $tool->get_tags(1);

        $ids = array_column($json['items'], 'id');
        $this->assertContains(10, $ids, 'the tagged attribute matches "Contains"');
        $this->assertNotContains('o-30', $ids, 'the object carries no matching tag on its own attributes, so "Contains" drops it');
    }

    public function testGetTagsBuildsTagNodesAndEdgesFromAttributeTags(): void
    {
        $tool = $this->newEventGraphTool();
        $json = $tool->get_tags(1);

        $tagNode = null;
        foreach ($json['items'] as $item) {
            if (($item['node_type'] ?? null) === 'tag') {
                $tagNode = $item;
            }
        }
        $this->assertNotNull($tagNode, 'every distinct tag becomes its own node');
        $this->assertSame('tlp:green', $tagNode['label']);

        $tagEdge = null;
        foreach ($json['relations'] as $rel) {
            if ($rel['to'] === 'tlp:green') {
                $tagEdge = $rel;
            }
        }
        $this->assertNotNull($tagEdge, 'the attribute must link to the tag node');
        $this->assertSame(10, $tagEdge['from']);
    }

    public function testGetGenericFromKeyGroupsAttributesByTheRequestedField(): void
    {
        $tool = $this->newEventGraphTool();
        $json = $tool->get_generic_from_key(1, 'category');

        $keyNode = null;
        foreach ($json['items'] as $item) {
            if (($item['node_type'] ?? null) === 'keyType' && $item['label'] === '"Network activity"') {
                $keyNode = $item;
            }
        }
        $this->assertNotNull($keyNode, 'the attribute category becomes a keyType node, json-encoded');

        // Also the sole reachable coverage (see the scope note above
        // get_references' tests) of ObjectReference -> 'relations' shaping,
        // since this method builds it identically but without the
        // addObjectColors()/Validation::uuid() crash.
        $objectItem = null;
        foreach ($json['items'] as $item) {
            if (($item['node_type'] ?? null) === 'object') {
                $objectItem = $item;
            }
        }
        $this->assertSame('o-30', $objectItem['id'], 'object node ids are prefixed to avoid colliding with attribute ids');

        $referenceRelation = null;
        foreach ($json['relations'] as $rel) {
            if (isset($rel['from']) && $rel['from'] === 'o-30') {
                $referenceRelation = $rel;
            }
        }
        $this->assertNotNull($referenceRelation, 'the object ObjectReference must produce a relation edge');
        $this->assertSame(10, $referenceRelation['to'], 'referenced_type 0 (attribute) is not object-prefixed, unlike referenced_type 1');
    }

    public function testGetGenericFromKeyRejectsAKeyNotOnTheAllowlist(): void
    {
        // SPECIFICATION: __authorized_JSON_key guards which Attribute
        // columns can be pivoted on, so an unlisted key must yield nothing
        // rather than leak an arbitrary field.
        $tool = $this->newEventGraphTool();
        $json = $tool->get_generic_from_key(1, 'not_a_real_column');

        $this->assertSame([], $json['items']);
        $this->assertSame([], $json['relations']);
    }

    public function testConstructForRefFetchesObjectReferenceByUuid(): void
    {
        $refModel = new FakeModel();
        $refModel->ObjectReference = new FakeModel([
            'find' => [['ObjectReference' => ['id' => 40, 'uuid' => 'ref-uuid-40', 'relationship_type' => 'contains']]],
        ]);

        $tool = new EventGraphTool();
        $tool->construct_for_ref($refModel, ['id' => 1]);
        $result = $tool->get_reference_data('ref-uuid-40');

        $this->assertSame('contains', $result[0]['ObjectReference']['relationship_type']);
    }

    public function testGetReferenceDataThrowsNotFoundWhenReferenceIsMissing(): void
    {
        $refModel = new FakeModel();
        $refModel->ObjectReference = new FakeModel(['find' => []]);

        $tool = new EventGraphTool();
        $tool->construct_for_ref($refModel, ['id' => 1]);

        $this->expectException(NotFoundException::class);
        $tool->get_reference_data('does-not-exist');
    }

    public function testGetObjectTemplatesThrowsNotFoundWhenNoneExist(): void
    {
        $refModel = new FakeModel();
        $refModel->ObjectTemplate = new FakeModel(['find' => []]);

        $tool = new EventGraphTool();
        $tool->construct_for_ref($refModel, ['id' => 1]);

        $this->expectException(NotFoundException::class);
        $tool->get_object_templates();
    }

    // ======================================================= CorrelationGraphTool

    private function correlationGraphFixture(): array
    {
        return [[
            'Event' => [
                'id' => 1, 'uuid' => 'evt-1',
                'info' => 'Test Event Info that is quite long for truncation check',
                'analysis' => 0, 'distribution' => 0, 'date' => '2026-01-01',
            ],
            'Orgc' => ['id' => 1, 'name' => 'OrgA', 'uuid' => 'org-uuid-1'],
            'RelatedEvent' => [
                ['Event' => [
                    'id' => 2, 'uuid' => 'evt-2', 'info' => 'Related event',
                    'analysis' => 0, 'distribution' => 0, 'date' => '2026-01-02',
                    'Orgc' => ['id' => 1, 'name' => 'OrgA'],
                ]],
            ],
            'RelatedAttribute' => [10 => [['id' => 2]]],
            'EventTag' => [['Tag' => ['id' => 5, 'name' => 'tlp:green', 'colour' => '#00ff00']]],
            'Galaxy' => [],
            'Object' => [],
            'Attribute' => [
                ['id' => 10, 'uuid' => 'attr-10', 'event_id' => 1, 'value' => '1.2.3.4',
                    'category' => 'Network activity', 'type' => 'ip-dst', 'to_ids' => 1, 'comment' => ''],
            ],
        ]];
    }

    private function newCorrelationGraphTool(array $data = []): CorrelationGraphTool
    {
        $eventModel = new Event(['fetchEvent' => $this->correlationGraphFixture()]);
        $eventModel->analysisLevels = [0 => 'Initial compromise'];
        $eventModel->Attribute = new FakeModel();
        $eventModel->Attribute->distributionLevels = [0 => 'Your organisation only'];
        $eventModel->EventTag = new FakeModel();
        $eventModel->EventTag->Tag = new FakeModel([
            'find' => ['Tag' => ['id' => 7, 'name' => 'expanded-tag', 'colour' => '#123456']],
            'fetchSimpleEventsForTag' => [
                ['id' => 3, 'uuid' => 'evt-3', 'info' => 'Tagged event', 'analysis' => 0, 'distribution' => 0, 'date' => '2026-01-03', 'Orgc' => ['id' => 1, 'name' => 'OrgA']],
            ],
        ]);

        $taxonomyModel = new FakeModel([
            'getTaxonomyForTag' => ['Taxonomy' => ['namespace' => 'tlp', 'description' => 'Traffic Light Protocol'], 'TaxonomyPredicate' => ['expanded' => 'Green']],
        ]);
        $galaxyModel = new FakeModel();

        return new CorrelationGraphTool($eventModel, $taxonomyModel, $galaxyModel, ['id' => 1], $data);
    }

    public function testBuildGraphJsonForEventCreatesEventTagAttributeAndCorrelatedEventNodes(): void
    {
        $tool = $this->newCorrelationGraphTool();
        $json = $tool->buildGraphJson(1, 'event', 'create');

        $types = array_column($json['nodes'], 'type');
        $this->assertContains('event', $types, 'the anchor event must be a node');
        $this->assertContains('tag', $types, 'the event tag must be a node');
        $this->assertContains('attribute', $types, 'the correlated attribute (present in RelatedAttribute) must be a node');
        $this->assertSame(2, count(array_filter($json['nodes'], static fn ($n) => $n['type'] === 'event')), 'the anchor event plus the event it correlates to via RelatedAttribute');

        $anchor = null;
        foreach ($json['nodes'] as $node) {
            if ($node['type'] === 'event' && $node['id'] === 1) {
                $anchor = $node;
            }
        }
        $this->assertNotNull($anchor);
        $this->assertStringStartsWith('(1) ', $anchor['name']);
        $this->assertStringEndsWith('...', $anchor['name'], 'info longer than 32 chars is truncated with an ellipsis');
        $this->assertSame('OrgA', $anchor['org']);
        $this->assertSame('tlp', $this->findTagNode($json)['taxonomy'], 'taxonomy metadata is attached to non-galaxy tags when a matching taxonomy exists');

        $this->assertNotEmpty($json['links'], 'nodes must be connected to the anchor event');
    }

    private function findTagNode(array $json): ?array
    {
        foreach ($json['nodes'] as $node) {
            if ($node['type'] === 'tag') {
                return $node;
            }
        }
        return null;
    }

    public function testBuildGraphJsonSkipsGalaxyStyleTagsFromEventTag(): void
    {
        // SPECIFICATION: galaxy cluster tags (misp-galaxy:...) are rendered
        // via the separate 'Galaxy' array, not as generic tag nodes, so
        // __handleTags must not duplicate them.
        $eventModel = new Event(['fetchEvent' => [[
            'Event' => ['id' => 1, 'uuid' => 'evt-1', 'info' => 'e', 'analysis' => 0, 'distribution' => 0, 'date' => '2026-01-01'],
            'Orgc' => ['id' => 1, 'name' => 'OrgA'],
            'EventTag' => [['Tag' => ['id' => 9, 'name' => 'misp-galaxy:threat-actor="X"', 'colour' => '#fff']]],
            'Galaxy' => [], 'Object' => [], 'Attribute' => [], 'RelatedEvent' => [], 'RelatedAttribute' => [],
        ]]]);
        $eventModel->analysisLevels = [0 => 'x'];
        $eventModel->Attribute = new FakeModel();
        $eventModel->Attribute->distributionLevels = [0 => 'x'];

        $tool = new CorrelationGraphTool($eventModel, new FakeModel(), new FakeModel(), ['id' => 1], []);
        $json = $tool->buildGraphJson(1, 'event', 'create');

        $types = array_column($json['nodes'], 'type');
        $this->assertNotContains('tag', $types, 'a misp-galaxy: tag must not become a generic tag node');
    }

    public function testBuildGraphJsonDeleteActionReturnsDataUnchanged(): void
    {
        $seed = ['nodes' => [['type' => 'event', 'id' => 1]], 'links' => []];
        $tool = $this->newCorrelationGraphTool($seed);

        $json = $tool->buildGraphJson(1, 'event', 'delete');

        $this->assertSame($seed, $json, 'a delete action is a pure no-op on the graph data');
    }

    public function testExpandTagAddsEventsButLeavesExpandedFlagUnset(): void
    {
        $tool = $this->newCorrelationGraphTool();
        $json = $tool->buildGraphJson(7, 'tag', 'create');

        $tagNode = null;
        $tagIndex = null;
        foreach ($json['nodes'] as $i => $node) {
            if ($node['type'] === 'tag' && $node['id'] === 7) {
                $tagNode = $node;
                $tagIndex = $i;
            }
        }
        $this->assertNotNull($tagNode, 'expanding a tag must create its node');
        $this->assertSame(1, count(array_filter($json['nodes'], static fn ($n) => $n['type'] === 'event')), 'fetchSimpleEventsForTag results become event nodes');

        // KNOWN-DEFECT: __expandTag() (and __expandGalaxy()) write the
        // "expanded" flag to $this->_json['nodes'][...] instead of
        // $this->data['nodes'][...]. $this->_json is never otherwise
        // declared or read, so this write silently creates a dead dynamic
        // property and the flag on the actual returned graph is never
        // updated. Consequence: a client that re-expands the same tag node
        // gets duplicate work done (cleanLinks() runs again, nodes are
        // re-deduplicated only via graphJsonContains) because the UI can
        // never see expanded=1 on this node from this code path.
        $this->assertFalse($json['nodes'][$tagIndex]['expanded'], 'today, the node\'s expanded flag stays at its creation-time value (falsy) because the intended update writes to a nonexistent $this->_json instead of $this->data');
    }

    // =========================================================== EventTimelineTool

    private function newEventTimelineTool($fullevent, array $filterRules = []): EventTimelineTool
    {
        $eventModel = new FakeModel(['fetchEvent' => $fullevent]);
        $eventModel->analysisLevels = [0 => 'x'];
        $eventModel->Attribute = new FakeModel(['isImage' => false]);
        $eventModel->Attribute->distributionLevels = [0 => 'x'];
        $eventModel->Object = new FakeModel();
        $eventModel->Object->ObjectTemplate = new FakeModel();

        $tool = new EventTimelineTool();
        $tool->construct($eventModel, ['id' => 1], $filterRules);
        return $tool;
    }

    public function testGetTimelineBuildsAttributeAndObjectItemsWithSightingDates(): void
    {
        $attr = ['id' => 10, 'uuid' => 'a-uuid', 'value' => '1.2.3.4', 'event_id' => 1,
            'timestamp' => 1000, 'first_seen' => null, 'last_seen' => null, 'type' => 'ip-dst'];
        $objAttrFirstSeen = ['id' => 21, 'uuid' => 'oa1', 'value' => '2026-01-01T00:00:00',
            'event_id' => 1, 'timestamp' => 1000, 'type' => 'datetime', 'object_relation' => 'first-seen'];
        $objAttrOther = ['id' => 22, 'uuid' => 'oa2', 'value' => 'blah',
            'event_id' => 1, 'timestamp' => 1000, 'type' => 'text', 'object_relation' => 'other'];
        $obj = ['id' => 30, 'uuid' => 'obj1', 'name' => 'file', 'meta-category' => 'file',
            'template_uuid' => 'tmpl', 'event_id' => 1, 'timestamp' => 1000,
            'first_seen' => null, 'last_seen' => '2026-02-01',
            'Attribute' => [$objAttrFirstSeen, $objAttrOther]];
        $fullevent = [['Object' => [$obj], 'Attribute' => [$attr], 'Sighting' => [
            ['attribute_id' => 10, 'date_sighting' => 123456],
        ]]];

        $tool = $this->newEventTimelineTool($fullevent);
        $json = $tool->get_timeline(1);

        $attrItem = null;
        $objItem = null;
        foreach ($json['items'] as $item) {
            if ($item['group'] === 'attribute') {
                $attrItem = $item;
            }
            if ($item['group'] === 'object') {
                $objItem = $item;
            }
        }
        $this->assertNotNull($attrItem);
        $this->assertSame([123456], $attrItem['date_sighting'], 'sightings are grouped by attribute_id');

        $this->assertNotNull($objItem);
        $this->assertTrue($objItem['first_seen_overwrite'], 'the object had no first_seen of its own, so the first-seen sub-attribute value is substituted');
        $this->assertSame('2026-01-01T00:00:00', $objItem['first_seen']);
        $this->assertFalse($objItem['last_seen_overwrite'], 'the object already had a last_seen, so no sub-attribute may override it');
        $this->assertSame('2026-02-01', $objItem['last_seen']);
        $this->assertCount(2, $objItem['Attribute'], 'both object attributes are listed as sub-items regardless of the seen-overwrite role');
    }

    public function testGetSightingTimelineExtrapolatesAPositiveSightingToNowWhenNoFalsePositiveFollows(): void
    {
        // CHARACTERIZATION: the extrapolation heuristic documented in the
        // class -- a lone positive sighting is considered "up" from the
        // moment it was seen until now, absent a contradicting negative.
        $event = [[
            'Attribute' => [['id' => 10, 'uuid' => 'a-uuid', 'value' => '1.2.3.4', 'event_id' => 1, 'timestamp' => 1000]],
            'Sighting' => [
                ['id' => 100, 'uuid' => 's-100', 'attribute_id' => 10, 'type' => '0', 'date_sighting' => 1000000],
            ],
        ]];
        $eventModel = new FakeModel(['fetchEvent' => $event]);
        $tool = new EventTimelineTool();
        $tool->construct($eventModel, ['id' => 1], []);

        $json = $tool->get_sighting_timeline(1);

        $this->assertCount(1, $json['items']);
        $item = $json['items'][0];
        $this->assertSame('sighting_positive', $item['group']);
        $this->assertSame(1000000 * 1000, $item['first_seen'], 'date_sighting is converted from seconds to milliseconds');
        $this->assertGreaterThan($item['first_seen'], $item['last_seen'], 'with no next false positive, the positive window is extrapolated up to "now"');
    }

    public function testGetSightingTimelineClosesAPositiveWindowAtTheNextFalsePositive(): void
    {
        // CHARACTERIZATION: a false positive sighting ends the preceding
        // positive window and opens a negative one that itself extrapolates
        // to now, per the class's documented strategy.
        $event = [[
            'Attribute' => [['id' => 10, 'uuid' => 'a-uuid', 'value' => '1.2.3.4', 'event_id' => 1, 'timestamp' => 1000]],
            'Sighting' => [
                ['id' => 100, 'uuid' => 's-100', 'attribute_id' => 10, 'type' => '0', 'date_sighting' => 1000000],
                ['id' => 101, 'uuid' => 's-101', 'attribute_id' => 10, 'type' => '1', 'date_sighting' => 2000000],
            ],
        ]];
        $eventModel = new FakeModel(['fetchEvent' => $event]);
        $tool = new EventTimelineTool();
        $tool->construct($eventModel, ['id' => 1], []);

        $json = $tool->get_sighting_timeline(1);

        $this->assertCount(2, $json['items'], 'the positive window and the trailing negative window');
        $this->assertSame('sighting_positive', $json['items'][0]['group']);
        $this->assertSame(1000000 * 1000, $json['items'][0]['first_seen']);
        $this->assertSame(2000000 * 1000, $json['items'][0]['last_seen'], 'the positive window ends exactly at the false positive');

        $this->assertSame('sighting_negative', $json['items'][1]['group']);
        $this->assertSame(2000000 * 1000, $json['items'][1]['first_seen']);
        $this->assertGreaterThan($json['items'][1]['first_seen'], $json['items'][1]['last_seen'], 'no positive sighting follows, so the negative window is extrapolated to now');
    }

    // ======================================================= DistributionGraphTool

    private function newDistributionGraphTool(array $servers = []): DistributionGraphTool
    {
        $eventModel = new Event();
        $eventModel->SharingGroup = new FakeModel(['fetchAllAuthorised' => []]);
        $eventModel->distributionLevels = [
            0 => 'Your organisation only', 1 => 'This community only', 2 => 'Connected communities',
            3 => 'All communities', 4 => 'Sharing group', 5 => 'Inherit event',
        ];
        $eventModel->distributionDescriptions = [
            0 => ['formdesc' => 'd0'], 1 => ['formdesc' => 'd1'], 2 => ['formdesc' => 'd2'],
            3 => ['formdesc' => 'd3'], 4 => ['formdesc' => 'd4'], 5 => ['formdesc' => 'd5'],
        ];
        $eventModel->Orgc = new FakeModel(['createConditions' => [], 'find' => ['OrgB']]);

        $user = ['Organisation' => ['id' => 1, 'name' => 'MyOrg']];
        return new DistributionGraphTool($eventModel, $servers, $user, 0);
    }

    public function testGetDistributionsGraphWithNoEventReturnsOnlyTheStaticShape(): void
    {
        $tool = $this->newDistributionGraphTool(['server1.example.org']);
        $json = $tool->get_distributions_graph(-1);

        $this->assertSame([0, 1, 2, 3, 4, 5], array_keys($json['event']), 'id -1 short-circuits before the event-level distribution (5) is folded away');
        foreach (['event', 'attribute', 'object', 'obj_attr'] as $bucket) {
            $this->assertSame(0, array_sum($json[$bucket]), "$bucket must be all-zero with no event loaded");
        }
        $this->assertSame([], $json['sharingGroupRepartition']);
        $this->assertContains('MyOrg', $json['additionalDistributionInfo'][0], 'the requesting org itself is always offered at the "org only" level');
        $this->assertContains('OrgB', $json['additionalDistributionInfo'][1], 'other local orgs are offered at the "community" level');
        $this->assertContains('server1.example.org', $json['additionalDistributionInfo'][2], 'configured sync servers are offered at the "connected communities" level');
        $this->assertSame([], $json['additionalDistributionInfo'][4], 'sharing groups are only known once a real event is loaded');
    }

    public function testGetDistributionsGraphCountsAndFoldsInheritedDistributionIntoTheEventLevel(): void
    {
        // SPECIFICATION: distribution level 5 ("inherit event") is not a
        // real bucket in the output -- its count must be merged into
        // whatever the event's own distribution level is, and the key
        // removed.
        $eventModel = new Event(['fetchEvent' => [[
            'Event' => ['id' => 2, 'distribution' => 1],
            'Attribute' => [
                ['id' => 10, 'distribution' => 3],
            ],
            'Object' => [[
                'id' => 30, 'distribution' => 5,
                'Attribute' => [
                    ['id' => 20, 'distribution' => 4, 'SharingGroup' => ['name' => 'SG-Alpha']],
                ],
            ]],
        ]]]);
        $eventModel->SharingGroup = new FakeModel(['fetchAllAuthorised' => []]);
        $eventModel->distributionLevels = [
            0 => 'Your organisation only', 1 => 'This community only', 2 => 'Connected communities',
            3 => 'All communities', 4 => 'Sharing group', 5 => 'Inherit event',
        ];
        $eventModel->distributionDescriptions = [
            0 => ['formdesc' => 'd0'], 1 => ['formdesc' => 'd1'], 2 => ['formdesc' => 'd2'],
            3 => ['formdesc' => 'd3'], 4 => ['formdesc' => 'd4'], 5 => ['formdesc' => 'd5'],
        ];
        $eventModel->Orgc = new FakeModel(['createConditions' => [], 'find' => []]);
        $user = ['Organisation' => ['id' => 1, 'name' => 'MyOrg']];
        $tool = new DistributionGraphTool($eventModel, [], $user, 0);

        $json = $tool->get_distributions_graph(2);

        $this->assertArrayNotHasKey(5, $json['event'], 'the inherit-event bucket is removed after folding');
        $this->assertSame(1, $json['event'][1], 'the object\'s inherited distribution (5) folds into the event\'s own level (1)');
        $this->assertSame(1, $json['event'][3], 'the top-level attribute keeps its own explicit distribution');
        $this->assertSame(1, $json['event'][4], 'the object attribute keeps its own explicit (sharing group) distribution');

        $this->assertSame(1, $json['object'][1], 'the object itself is counted under the folded level');
        $this->assertSame(1, $json['attribute'][3]);
        $this->assertSame(1, $json['obj_attr'][4]);

        $this->assertSame(['SG-Alpha'], $json['additionalDistributionInfo'][4], 'the sharing group actually used on data is surfaced by name');
        $this->assertSame(['SG-Alpha' => 1], $json['sharingGroupRepartition']);
    }
}
