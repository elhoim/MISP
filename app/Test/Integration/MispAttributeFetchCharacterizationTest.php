<?php

require_once __DIR__ . '/IntegrationTestCase.php';
require_once __DIR__ . '/../Support/Snapshot.php';

use MispTest\Support\Snapshot;

/**
 * Characterization of MispAttribute's READ path.
 *
 * MispAttribute.php sits at 22% coverage on 2508 statements. fetchAttributes()
 * is the single largest untouched public method (247 statements) - it is the
 * attribute equivalent of Event::fetchEvent(), the hub every attribute-listing
 * API action, restSearch() export and sync pull path go through. This suite
 * also covers fetchAttribute(), fetchRelated(), buildConditions() and
 * buildFilterConditions() (which reaches the set_filter_* family by STRING
 * DISPATCH through the filter table in Event::set_filter_uuid() /
 * Event::set_filter_tags() - a scope of 'Attribute' routes back to
 * $this->Attribute->set_filter_uuid() / set_filter_tags(), the MispAttribute
 * versions of those methods, not Event's own logic), and attachTagsToAttributes().
 *
 * These are CHARACTERIZATION tests (ADR 0002): they record what the code
 * does today without claiming it is correct, so that a refactor which
 * changes the shape of a result fails loudly. A behaviour pinned here may
 * well be wrong; what matters is that it does not change by accident.
 */
class MispAttributeFetchCharacterizationTest extends IntegrationTestCase
{
    /** @var int|null */
    private $eventId;

    /** @var int|null */
    private $ipAttributeId;

    /** @var int|null */
    private $domainAttributeId;

    /** @var int|null */
    private $md5AttributeId;

    /** @var array<int,int> tag ids created by this test, removed in tearDown */
    private $createdTagIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->eventId = $this->createEvent('MispAttribute fetch characterization', [
            ['type' => 'ip-dst', 'value' => '8.8.8.8'],
            ['type' => 'domain', 'value' => 'example.com'],
            ['type' => 'md5', 'value' => 'd41d8cd98f00b204e9800998ecf8427e',
             'category' => 'Payload delivery'],
        ]);
        $attributes = $this->model('MispAttribute')->find('list', [
            'conditions' => ['Attribute.event_id' => $this->eventId],
            'fields' => ['Attribute.type', 'Attribute.id'],
            'recursive' => -1,
        ]);
        // find('list') collapses on a repeated key (type), so re-fetch keyed
        // by value instead to unambiguously recover each fixture's id.
        $byValue = $this->model('MispAttribute')->find('list', [
            'conditions' => ['Attribute.event_id' => $this->eventId],
            'fields' => ['Attribute.value', 'Attribute.id'],
            'recursive' => -1,
        ]);
        $this->ipAttributeId = (int)$byValue['8.8.8.8'];
        $this->domainAttributeId = (int)$byValue['example.com'];
        $this->md5AttributeId = (int)$byValue['d41d8cd98f00b204e9800998ecf8427e'];
    }

    protected function tearDown(): void
    {
        foreach ($this->createdTagIds as $tagId) {
            try {
                $this->model('AttributeTag')->deleteAll(['AttributeTag.tag_id' => $tagId], false);
                $this->model('Tag')->delete($tagId);
            } catch (\Throwable $e) {
                // Best effort: a test must not fail during cleanup.
            }
        }
        $this->createdTagIds = [];
        parent::tearDown();
    }

    private function pin(string $name, $result): void
    {
        [$ok, $message] = Snapshot::compare($name, $result);
        $this->assertTrue($ok, $message);
    }

    /** Create a tag and attach it to one attribute, tracked for cleanup. */
    private function tagAttribute(int $attributeId, string $tagName): int
    {
        $tagId = (int)$this->model('Tag')->quickAdd($tagName);
        $this->assertNotFalse($tagId, "could not create fixture tag '$tagName'");
        $this->createdTagIds[] = $tagId;
        $ok = $this->model('AttributeTag')->save(['AttributeTag' => [
            'attribute_id' => $attributeId,
            'event_id' => $this->eventId,
            'tag_id' => $tagId,
            'local' => 0,
        ]]);
        $this->assertNotEmpty($ok, "could not attach tag '$tagName' to attribute $attributeId");
        return $tagId;
    }

    // ----------------------------------------------------- fetchAttributes

    private function fetch(array $options): array
    {
        $options['conditions'] = array_merge(
            ['Attribute.event_id' => $this->eventId],
            $options['conditions'] ?? []
        );
        return $this->model('MispAttribute')->fetchAttributes($this->adminUser(), $options);
    }

    public function testDefaultFetchShape(): void
    {
        $result = $this->fetch([]);
        $this->assertCount(3, $result, 'fetching by event_id must return exactly the three fixture attributes');
        $this->pin('fetchattributes_default', $result);
    }

    public function testTypeFilterAppliesAtAttributeConditionLevel(): void
    {
        $result = $this->fetch(['conditions' => ['Attribute.type' => 'ip-dst']]);
        $this->assertCount(1, $result);
        $this->assertSame('ip-dst', $result[0]['Attribute']['type']);
        $this->pin('fetchattributes_type_filter', $result);
    }

    public function testCategoryFilterAppliesAtAttributeConditionLevel(): void
    {
        $result = $this->fetch(['conditions' => ['Attribute.category' => 'Payload delivery']]);
        $this->assertCount(1, $result);
        $this->assertSame('md5', $result[0]['Attribute']['type']);
    }

    public function testValueFilterMatchingNothingReturnsEmptyNotFatal(): void
    {
        $result = $this->fetch(['conditions' => [
            'Attribute.value1' => 'no-attribute-has-this-value-198.51.100.254',
        ]]);
        $this->assertSame([], $result, 'a filter matching nothing must return an empty array, not a fatal');
    }

    public function testIncludeContextAttachesEventAndOrgData(): void
    {
        $result = $this->fetch(['includeContext' => true]);
        $this->assertNotEmpty($result);
        foreach ($result as $attr) {
            $this->assertArrayHasKey('Org', $attr['Event'], 'includeContext must attach the owning Org');
            $this->assertArrayHasKey('Orgc', $attr['Event'], 'includeContext must attach the creating Orgc');
            $this->assertArrayHasKey('ThreatLevel', $attr['Event'], 'the per-attribute pipeline always attaches ThreatLevel, with or without includeContext');
        }
    }

    public function testIncludeSightingsAttachesSightingKey(): void
    {
        $result = $this->fetch(['includeSightings' => true]);
        $this->assertNotEmpty($result);
        foreach ($result as $attr) {
            $this->assertArrayHasKey('Sighting', $attr['Attribute'], 'includeSightings must attach a Sighting key even with zero sightings recorded');
        }
    }

    public function testIncludeCorrelationsAttachesRelatedAttributeKey(): void
    {
        $result = $this->fetch(['includeCorrelations' => true]);
        $this->assertNotEmpty($result);
        foreach ($result as $attr) {
            $this->assertArrayHasKey('RelatedAttribute', $attr['Attribute']);
        }
    }

    public function testIncludeEventTagsAttachesEventTagKey(): void
    {
        $result = $this->fetch(['includeEventTags' => true]);
        $this->assertNotEmpty($result);
        foreach ($result as $attr) {
            $this->assertArrayHasKey('EventTag', $attr, 'includeEventTags must stamp EventTag onto every returned attribute row');
        }
    }

    public function testIncludeAttributeUuidStampsEventUuid(): void
    {
        $result = $this->fetch(['includeAttributeUuid' => true]);
        $eventUuid = $this->model('Event')->field('uuid', ['Event.id' => $this->eventId]);
        $this->assertNotEmpty($result);
        foreach ($result as $attr) {
            $this->assertSame($eventUuid, $attr['Attribute']['event_uuid']);
        }
    }

    public function testDeletedOnlyExcludesLiveAttributes(): void
    {
        $result = $this->fetch(['deleted' => 'only']);
        $this->assertSame([], $result, 'none of the fixture attributes are deleted, so deleted=only must return nothing');
    }

    public function testTagFilterAttachesAttributeTagAndTag(): void
    {
        $this->tagAttribute($this->ipAttributeId, 'misp-attribute-characterization-tag');
        $result = $this->fetch(['conditions' => ['Attribute.id' => $this->ipAttributeId]]);
        $this->assertCount(1, $result);
        $this->assertNotEmpty($result[0]['AttributeTag'], 'attachTagsToAttributes must have populated AttributeTag');
        $this->assertSame(
            'misp-attribute-characterization-tag',
            $result[0]['AttributeTag'][0]['Tag']['name'],
            'attachTagsToAttributes must hydrate the Tag under each AttributeTag entry'
        );
    }

    public function testListOptionReturnsEventIdKeyedList(): void
    {
        $result = $this->model('MispAttribute')->fetchAttributes($this->adminUser(), [
            'list' => true,
            'conditions' => ['Attribute.event_id' => $this->eventId],
        ]);
        $this->assertIsArray($result);
        $this->assertArrayHasKey($this->eventId, $result, "list mode's find('list') keys the result by event_id");
        $this->assertSame($this->eventId, $result[$this->eventId]);
    }

    public function testListWithEventIdsOptionReturnsUniqueColumn(): void
    {
        $result = $this->model('MispAttribute')->fetchAttributes($this->adminUser(), [
            'list' => true,
            'event_ids' => true,
            'conditions' => ['Attribute.event_id' => $this->eventId],
        ]);
        $this->assertSame(
            [$this->eventId],
            array_values($result),
            'list+event_ids must collapse the three fixture attributes to one unique event id'
        );
    }

    public function testResultCountWithRealCountIsPopulatedBeforeFetch(): void
    {
        // fetchAttributes() only runs the COUNT query when $result_count is
        // not literally false (`$result_count !== false && $real_count`) -
        // the default `false` means "caller does not want a count at all",
        // distinct from "count not yet computed".
        $resultCount = true;
        $this->fetchWithCount(['conditions' => ['Attribute.event_id' => $this->eventId]], $resultCount, true);
        $this->assertSame(3, $resultCount, 'real_count must run a COUNT query ahead of the fetch');
    }

    private function fetchWithCount(array $options, &$resultCount, $realCount): array
    {
        return $this->model('MispAttribute')->fetchAttributes($this->adminUser(), $options, $resultCount, $realCount);
    }

    public function testResultCountWithRealCountAndNoMatchesShortCircuitsToEmpty(): void
    {
        $resultCount = true;
        $result = $this->fetchWithCount(
            ['conditions' => ['Attribute.value1' => 'no-attribute-has-this-value-198.51.100.254']],
            $resultCount,
            true
        );
        $this->assertSame([], $result);
        $this->assertSame(0, $resultCount);
    }

    public function testUnknownEventIdReturnsEmpty(): void
    {
        $result = $this->model('MispAttribute')->fetchAttributes($this->adminUser(), [
            'conditions' => ['Attribute.event_id' => 999999999],
        ]);
        $this->assertSame([], $result, 'an unknown event id must yield an empty result, not a fatal');
    }

    // ------------------------------------------------------- fetchAttribute

    public function testFetchAttributeShape(): void
    {
        $this->tagAttribute($this->md5AttributeId, 'misp-attribute-characterization-fetchattribute-tag');
        $result = $this->model('MispAttribute')->fetchAttribute($this->md5AttributeId);
        $this->assertNotEmpty($result);
        $this->assertSame('md5', $result['Attribute']['type']);
        $this->assertArrayNotHasKey('AttributeTag', $result, 'fetchAttribute flattens AttributeTag into Attribute.Tag and unsets the original key');
        $this->assertArrayHasKey('Tag', $result['Attribute']);
        $this->assertSame('misp-attribute-characterization-fetchattribute-tag', $result['Attribute']['Tag'][0]['name']);
        $this->assertArrayNotHasKey('Object', $result, 'a non-object attribute must have its empty Object key unset');
    }

    public function testFetchAttributeUnknownIdReturnsEmptyArray(): void
    {
        $result = $this->model('MispAttribute')->fetchAttribute(999999999);
        $this->assertSame([], $result);
    }

    // -------------------------------------------------------- fetchRelated

    public function testFetchRelatedMatchesOnValueForASimpleType(): void
    {
        $sharedValue = 'fetchrelated-characterization-' . $this->eventId;
        // 'text' accepts any free-form string, unlike 'domain' which
        // validates FQDN syntax - a plain marker string is enough here.
        $this->createEvent('fetchRelated match holder', [
            ['type' => 'text', 'value' => $sharedValue],
        ]);
        $resultArray = [
            ['default_type' => 'text', 'value' => $sharedValue],
        ];
        $this->model('MispAttribute')->fetchRelated($this->adminUser(), $resultArray);
        $this->assertArrayHasKey('related', $resultArray[0], 'fetchRelated must add a related key to every entry');
        $this->assertNotEmpty($resultArray[0]['related'], 'the text attribute created above shares its value and must be found');
        foreach ($resultArray[0]['related'] as $related) {
            $this->assertSame($sharedValue, $related['Attribute']['value']);
        }
    }

    public function testFetchRelatedSplitsCompositeTypeAndMatchesOnFirstPieceOnly(): void
    {
        // ip-dst|port is in PRIMARY_ONLY_CORRELATING_TYPES, so fetchRelated
        // must correlate on pieces[0] ('9.9.9.9') alone, not the full
        // composite string - a plain ip-dst attribute holding that first
        // piece is expected to match.
        $ip = '9.9.9.' . (100 + ($this->eventId % 100));
        $this->createEvent('fetchRelated composite match holder', [
            ['type' => 'ip-dst', 'value' => $ip],
        ]);
        $resultArray = [
            ['default_type' => 'ip-dst|port', 'value' => $ip . '|8080'],
        ];
        $this->model('MispAttribute')->fetchRelated($this->adminUser(), $resultArray);
        $this->assertNotEmpty(
            $resultArray[0]['related'],
            'PRIMARY_ONLY_CORRELATING_TYPES must correlate composite ip-dst|port on its first piece'
        );
        foreach ($resultArray[0]['related'] as $related) {
            $this->assertSame($ip, $related['Attribute']['value']);
        }
    }

    public function testFetchRelatedOnEmptyResultArrayIsANoOp(): void
    {
        $resultArray = [];
        $this->model('MispAttribute')->fetchRelated($this->adminUser(), $resultArray);
        $this->assertSame([], $resultArray);
    }

    // ------------------------------------------------------- buildConditions

    public function testSiteAdminGetsNoAclConditions(): void
    {
        $conditions = $this->model('MispAttribute')->buildConditions($this->adminUser());
        $this->assertSame([], $conditions, 'a site admin is not restricted by buildConditions');
    }

    public function testNonAdminGetsOrgAndSharingGroupRestriction(): void
    {
        $user = [
            'org_id' => 999999,
            'Role' => ['perm_site_admin' => 0, 'perm_sync' => 0],
        ];
        $conditions = $this->model('MispAttribute')->buildConditions($user);
        $this->assertSame(
            999999,
            $conditions['OR'][0]['Event.org_id'],
            'a non-admin is always allowed attributes on events owned by their own org'
        );
        $this->assertArrayHasKey('AND', $conditions['OR']);
    }

    /**
     * buildConditions() memoises into the private $aclConditionsCache under
     * the key "<perm_site_admin>-<org_id>" (MispAttribute.php:1906-1910,
     * 1971). Two things are worth pinning: that the key really does separate
     * two orgs sharing one model instance, and that the cache is a cache -
     * a repeat call is answered from it rather than recomputed. The second
     * is asserted by poisoning the stored entry and observing the poisoned
     * value come back, which no re-computing implementation could return.
     */
    public function testBuildConditionsMemoisesPerRoleAndOrgUnderDistinctKeys(): void
    {
        $model = $this->model('MispAttribute');
        $user42 = ['org_id' => 42, 'Role' => ['perm_site_admin' => 0, 'perm_sync' => 0]];
        $user43 = ['org_id' => 43, 'Role' => ['perm_site_admin' => 0, 'perm_sync' => 0]];

        $first = $model->buildConditions($user42);
        $second = $model->buildConditions($user43);
        $this->assertSame(42, $first['OR'][0]['Event.org_id']);
        $this->assertSame(43, $second['OR'][0]['Event.org_id'], 'the two orgs must not collide on one cache key');

        $property = new ReflectionProperty(get_class($model), 'aclConditionsCache');
        $property->setAccessible(true);
        $cache = $property->getValue($model);
        $this->assertArrayHasKey('0-42', $cache, 'a non-admin org 42 is stored under "0-42"');
        $this->assertArrayHasKey('0-43', $cache);

        $cache['0-42'] = ['POISONED' => true];
        $property->setValue($model, $cache);
        $this->assertSame(
            ['POISONED' => true],
            $model->buildConditions($user42),
            'a second call for the same (role, org) is served from the cache, not recomputed'
        );

        // Leave no poisoned entry behind for the next test in this process.
        unset($cache['0-42'], $cache['0-43']);
        $property->setValue($model, $cache);
    }

    // ------------------------------------------------- buildFilterConditions

    private function buildFilterConditions(array $params, $skipBuildConditions = true): array
    {
        return $this->model('MispAttribute')->buildFilterConditions($this->adminUser(), $params, $skipBuildConditions);
    }

    public function testBuildFilterConditionsWithNoParamsIsEmpty(): void
    {
        $conditions = $this->buildFilterConditions([]);
        $this->assertSame([], $conditions, 'no filter params must yield no conditions');
    }

    public function testBuildFilterConditionsRunsBuildConditionsWhenNotSkipped(): void
    {
        $conditions = $this->buildFilterConditions([], false);
        $this->assertSame(
            $this->model('MispAttribute')->buildConditions($this->adminUser()),
            $conditions,
            'skipBuildConditions=false must fold buildConditions() straight into the returned conditions'
        );
    }

    /**
     * uuid under the 'Attribute' scope dispatches, via Event::set_filter_uuid()'s
     * string-dispatch table, to MispAttribute::set_filter_uuid() itself
     * (Event.php:4068, `$this->{$options['scope']}->set_filter_uuid(...)`).
     * A uuid that matches no event collapses to a plain Attribute.uuid IN (...)
     * clause - the subquery branch only fires when the uuid resolves to a real
     * event (covered below).
     */
    public function testBuildFilterConditionsForUuidNotMatchingAnyEventUsesPlainInClause(): void
    {
        $attributeUuid = $this->model('MispAttribute')->field('uuid', ['Attribute.id' => $this->ipAttributeId]);
        $conditions = $this->buildFilterConditions(['uuid' => [$attributeUuid]]);
        $this->assertSame(
            ['AND' => [['OR' => ['Attribute.uuid' => [$attributeUuid]]]]],
            $conditions
        );
    }

    /**
     * The reverse case: a uuid that DOES match an event's own uuid causes
     * set_filter_uuid() to widen the search with an event-scoped subquery
     * OR'd onto the plain Attribute.uuid clause (MispAttribute.php:4025-4034).
     */
    public function testBuildFilterConditionsForUuidMatchingAnEventAddsSubquery(): void
    {
        $eventUuid = $this->model('Event')->field('uuid', ['Event.id' => $this->eventId]);
        $conditions = $this->buildFilterConditions(['uuid' => [$eventUuid]]);
        $sql = json_encode($conditions);
        $this->assertStringContainsString(
            'Attribute.event_id IN (SELECT id FROM events',
            $sql === false ? '' : $sql,
            'a uuid that resolves to a real event must add an Attribute.event_id subquery, not just a plain uuid IN clause'
        );
    }

    public function testBuildFilterConditionsForTypeUsesGenericAddFilter(): void
    {
        $conditions = $this->buildFilterConditions(['type' => 'ip-dst']);
        $this->pin('buildfilterconditions_type', $conditions);
    }

    /**
     * tags under the 'Attribute' scope dispatches through Event::set_filter_tags()
     * (Event.php:4236) to MispAttribute::set_filter_tags() unconditionally.
     * With no attribute-selective filter alongside it, the OR-tag branch
     * takes the "tag-only" uncorrelated-IN path (MispAttribute.php:1401-1413)
     * rather than the EXISTS path used when the query is already selective.
     */
    public function testBuildFilterConditionsForPositiveTagUsesUncorrelatedInWhenNotSelective(): void
    {
        $tagId = $this->tagAttribute($this->ipAttributeId, 'misp-attribute-characterization-filter-tag');
        $conditions = $this->buildFilterConditions(['tags' => ['misp-attribute-characterization-filter-tag']]);
        $sql = json_encode($conditions);
        $this->assertStringContainsString("IN ({$tagId})", $sql);
        $this->assertStringContainsString('attribute_tags', $sql);
        $this->assertStringContainsString('event_tags', $sql, 'the OR-tag branch searches both attribute_tags and event_tags');
    }

    /**
     * The same positive tag filter, but with an attribute-selective filter
     * (value) present alongside it, takes the EXISTS path instead
     * (MispAttribute.php:1379-1395) - the comment above the branch explains
     * why: a correlated EXISTS is cheap once the outer scan is already
     * narrowed by the selective filter.
     */
    public function testBuildFilterConditionsForPositiveTagUsesExistsWhenAttributeSelective(): void
    {
        $this->tagAttribute($this->ipAttributeId, 'misp-attribute-characterization-selective-tag');
        $conditions = $this->buildFilterConditions([
            'tags' => ['misp-attribute-characterization-selective-tag'],
            'value' => '8.8.8.8',
        ]);
        $sql = json_encode($conditions);
        $this->assertStringContainsString('EXISTS (', $sql);
    }

    public function testBuildFilterConditionsForNegativeTagUsesNotExists(): void
    {
        $this->tagAttribute($this->ipAttributeId, 'misp-attribute-characterization-negative-tag');
        $conditions = $this->buildFilterConditions(['tags' => ['!misp-attribute-characterization-negative-tag']]);
        $sql = json_encode($conditions);
        $this->assertStringContainsString('NOT EXISTS (', $sql);
    }

    public function testBuildFilterConditionsForAndGroupTagAddsOneClausePerTag(): void
    {
        $tagAId = $this->tagAttribute($this->ipAttributeId, 'misp-attribute-characterization-and-a');
        $tagBId = $this->tagAttribute($this->domainAttributeId, 'misp-attribute-characterization-and-b');
        $conditions = $this->buildFilterConditions([
            'tags' => ['AND' => ['misp-attribute-characterization-and-a', 'misp-attribute-characterization-and-b']],
        ]);
        $sql = json_encode($conditions);
        $this->assertStringContainsString("tag_id = {$tagAId}", $sql);
        $this->assertStringContainsString("tag_id = {$tagBId}", $sql);
    }

    /**
     * A tag name with no matching Tag row makes Tag::fetchTagIdsSimple()
     * return [-1] as a sentinel "match nothing". set_filter_tags()
     * special-cases that sentinel for the OR (0) and AND (2) tag groups by
     * adding a literal 'Event.id' => -1 condition (MispAttribute.php:1354-1355,
     * 1482-1483). That sentinel WORKS: fetchAttributes() joins the events
     * table under the alias 'Event' unconditionally (MispAttribute.php:2211-2219,
     * an inner join on `Event.id = Attribute.event_id`), and 'Event.id' is in
     * its default field list, so `Event.id = -1` is a real, exclusionary
     * predicate against a column that always exists - an unknown tag name
     * excludes every row, which is exactly what a tag filter should do.
     *
     * (An earlier revision of this file annotated the sentinel as a
     * KNOWN-DEFECT, claiming 'Event.id' is never joined under the 'Attribute'
     * scope and that the condition is therefore silently dropped. That is
     * wrong - the join above is unconditional - so the annotation has been
     * removed rather than propagated into the defect register. The second
     * assertion below proves the point end to end instead of by reading.)
     */
    public function testUnknownTagNameExcludesEveryAttribute(): void
    {
        $conditions = $this->buildFilterConditions(['tags' => ['no-such-tag-name-anywhere']]);
        $this->assertSame(
            [0 => ['Event.id' => -1]],
            $conditions,
            'an unmatched tag name resolves to the sentinel condition Event.id => -1'
        );
        $result = $this->fetch(['conditions' => $conditions]);
        $this->assertSame(
            [],
            $result,
            'the sentinel is exclusionary, not inert: fetching with it returns nothing, '
            . 'even though the same fetch without it returns the three fixture attributes'
        );
        $this->assertCount(
            3,
            $this->fetch([]),
            'control: without the sentinel the very same query returns all three fixture attributes'
        );
    }

    // -------------------------------------------- restSearch (paramsOnly)

    /**
     * restSearch(..., paramsOnly=true) runs buildFilterConditions() (and
     * therefore the whole set_filter_* dispatch table) before returning,
     * without touching the export/TmpFileTool machinery - the cheapest way
     * to exercise that path end to end the way a real API caller does.
     */
    public function testRestSearchParamsOnlyBuildsConditionsFromFilters(): void
    {
        $params = $this->model('MispAttribute')->restSearch(
            $this->adminUser(),
            'json',
            ['eventid' => $this->eventId, 'type' => 'ip-dst'],
            true
        );
        $this->assertIsArray($params);
        $this->assertArrayHasKey('conditions', $params);
        $sql = json_encode($params['conditions']);
        $this->assertStringContainsString('"Attribute.type IN":["ip-dst"]', $sql);
    }

    public function testRestSearchInvalidFormatThrows(): void
    {
        $this->expectException(NotFoundException::class);
        $this->model('MispAttribute')->restSearch($this->adminUser(), 'no-such-format', []);
    }

    // -------------------------------------------------- attachTagsToAttributes

    /**
     * The early `if (empty($tagIdsToFetch)) return;` guard
     * (MispAttribute.php:2516-2518) is the only thing standing between the
     * hydration loop and an attribute row that has no 'AttributeTag' key at
     * all: that loop dereferences $attribute['AttributeTag'] unguarded
     * (MispAttribute.php:2534). The previous version of this test passed an
     * attribute with an EMPTY AttributeTag list and asserted it came back
     * empty - true of any implementation, including one that does nothing at
     * all, so it discriminated nothing. This version pins where the guard
     * actually bites - whether the batch is short-circuited at all is decided
     * by the OTHER rows in it, not by the row being looked at - by asserting
     * both sides of that switch in one test.
     */
    public function testAttachTagsToAttributesLeavesUntaggedRowsAloneOnlyWhileNoRowIsTagged(): void
    {
        $model = $this->model('MispAttribute');

        // No tags anywhere in the batch: the guard returns before the
        // hydration loop, so even a row missing the key entirely survives.
        $untaggedOnly = [
            ['Attribute' => ['id' => $this->ipAttributeId], 'AttributeTag' => []],
            ['Attribute' => ['id' => $this->domainAttributeId]],
        ];
        $model->attachTagsToAttributes($untaggedOnly, []);
        $this->assertSame([], $untaggedOnly[0]['AttributeTag'], 'an empty tag list must be left exactly as given');
        $this->assertArrayNotHasKey(
            'AttributeTag',
            $untaggedOnly[1],
            'the guard returns early, so a row with no AttributeTag key is not given one'
        );

        // One tagged row in the same batch passes the guard; the hydration
        // loop now runs over every row - the tagged row gets its Tag
        // hydrated, the untagged sibling is visited but left empty.
        $tagId = $this->tagAttribute($this->ipAttributeId, 'misp-attribute-characterization-attach-guard');
        $mixed = [
            ['Attribute' => ['id' => $this->ipAttributeId], 'AttributeTag' => [['tag_id' => $tagId, 'local' => 0]]],
            ['Attribute' => ['id' => $this->domainAttributeId], 'AttributeTag' => []],
        ];
        $model->attachTagsToAttributes($mixed, []);
        $this->assertSame(
            'misp-attribute-characterization-attach-guard',
            $mixed[0]['AttributeTag'][0]['Tag']['name'],
            'the tagged row is hydrated with its Tag'
        );
        $this->assertSame([], $mixed[1]['AttributeTag'], 'an untagged sibling row is still left empty');
    }

    public function testAttachTagsToAttributesDropsNonExportableTagsByDefault(): void
    {
        $exportableTagId = $this->tagAttribute($this->ipAttributeId, 'misp-attribute-characterization-exportable');
        // A non-exportable tag: quickAdd() always sets exportable=1, so flip
        // it back off directly to exercise the culling branch.
        $hiddenTagId = (int)$this->model('Tag')->quickAdd('misp-attribute-characterization-hidden');
        $this->createdTagIds[] = $hiddenTagId;
        $this->model('Tag')->updateAll(['Tag.exportable' => 0], ['Tag.id' => $hiddenTagId]);
        $this->model('AttributeTag')->save(['AttributeTag' => [
            'attribute_id' => $this->ipAttributeId,
            'event_id' => $this->eventId,
            'tag_id' => $hiddenTagId,
            'local' => 0,
        ]]);

        $attributes = [
            [
                'Attribute' => ['id' => $this->ipAttributeId],
                'AttributeTag' => [
                    ['tag_id' => $exportableTagId, 'local' => 0],
                    ['tag_id' => $hiddenTagId, 'local' => 0],
                ],
            ],
        ];
        $this->model('MispAttribute')->attachTagsToAttributes($attributes, []);
        $this->assertCount(1, $attributes[0]['AttributeTag'], 'a non-exportable tag must be culled by default');
        $this->assertSame($exportableTagId, $attributes[0]['AttributeTag'][0]['Tag']['id']);
    }

    public function testAttachTagsToAttributesIncludesAllTagsWhenRequested(): void
    {
        $hiddenTagId = (int)$this->model('Tag')->quickAdd('misp-attribute-characterization-includeall');
        $this->createdTagIds[] = $hiddenTagId;
        $this->model('Tag')->updateAll(['Tag.exportable' => 0], ['Tag.id' => $hiddenTagId]);

        $attributes = [
            [
                'Attribute' => ['id' => $this->ipAttributeId],
                'AttributeTag' => [['tag_id' => $hiddenTagId, 'local' => 0]],
            ],
        ];
        $this->model('MispAttribute')->attachTagsToAttributes($attributes, ['includeAllTags' => true]);
        $this->assertCount(1, $attributes[0]['AttributeTag'], 'includeAllTags must keep a non-exportable tag rather than culling it');
    }
}
