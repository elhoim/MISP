<?php

require_once __DIR__ . '/IntegrationTestCase.php';
require_once __DIR__ . '/../Support/Snapshot.php';

use MispTest\Support\Snapshot;

/**
 * Characterization of MispObject's READ path.
 *
 * MispObject.php sits at 0.8% coverage on 1151 statements - 2 of its 30
 * public methods are touched at all. This is the structural twin of
 * EventFetchCharacterizationTest: it exercises fetchObjects() (the object
 * equivalent of Event::fetchEvent(), and the hub every object-listing API
 * action and sync pull path go through), plus the smaller methods that feed
 * it or sit next to it - buildFilterConditions, buildConditions,
 * fetchObjectSimple, findSimilarObjects, attributeCleanup and
 * syncObjectAndAttributeSeen.
 *
 * These are CHARACTERIZATION tests (ADR 0002): they record what the code
 * does today without claiming it is correct, so that a refactor which
 * changes the shape of a result fails loudly. A behaviour pinned here may
 * well be wrong; what matters is that it does not change by accident.
 */
class MispObjectFetchCharacterizationTest extends IntegrationTestCase
{
    /** @var int|null */
    private $eventId;

    /** @var int|null */
    private $objectAId;

    /** @var int|null */
    private $objectBId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->eventId = $this->createEvent('MispObject fetch characterization', [
            ['type' => 'ip-dst', 'value' => '8.8.4.4'],
        ]);
        $this->objectAId = $this->createObject($this->eventId, 'test-object-a', [
            ['type' => 'text', 'object_relation' => 'text', 'value' => 'alpha', 'to_ids' => 0],
            ['type' => 'md5', 'object_relation' => 'hash', 'value' => 'd41d8cd98f00b204e9800998ecf8427e',
             'category' => 'Payload delivery', 'to_ids' => 1],
        ]);
        $this->objectBId = $this->createObject($this->eventId, 'test-object-b', [
            ['type' => 'text', 'object_relation' => 'text', 'value' => 'beta', 'to_ids' => 0],
        ]);
        // Object B is SOFT-DELETED so the fixture can tell the deleted=true
        // path apart from the default one. Without a deleted row anywhere in
        // the fixture, "include deleted" and "exclude deleted" return the
        // same bytes and any test comparing them asserts nothing.
        // unpublish=false keeps deleteObject() off the Event model (it would
        // need an 'Event' key this flat fetch does not carry).
        $this->model('Object')->deleteObject($this->findObjectWithAttributes($this->objectBId), false, false);
    }

    /**
     * deleteObject()'s soft path reads $object['Attribute'] directly rather
     * than fetching it, so a flat find('first') is not enough on its own.
     */
    private function findObjectWithAttributes(int $id): array
    {
        $object = $this->model('Object')->find('first', [
            'recursive' => -1,
            'conditions' => ['Object.id' => $id],
        ]);
        $attributes = $this->model('MispAttribute')->find('all', [
            'recursive' => -1,
            'conditions' => ['Attribute.object_id' => $id],
        ]);
        $object['Attribute'] = array_column($attributes, 'Attribute');
        return $object;
    }

    /**
     * Create a fixture object with attributes, using saveObject() the way a
     * real caller does (without going through an on-disk template - the
     * required template fields are supplied directly, which saveObject()
     * accepts when no $template array is passed).
     *
     * @param array<int,array<string,mixed>> $attributes
     */
    private function createObject(int $eventId, string $name, array $attributes): int
    {
        $objectModel = $this->model('Object');
        $object = [
            'Object' => [
                'name' => $name,
                'meta-category' => 'test',
                'description' => 'MispObjectFetchCharacterizationTest fixture',
                'template_version' => 1,
                // Deterministic (not CakeText::uuid()) so it is stable
                // across test runs: 'template_uuid' is not an id key
                // Snapshot aliases away, so a random value here would make
                // every fetchObjects()/fetchObjectSimple() snapshot fail on
                // the very next run even with nothing else changed.
                'template_uuid' => $this->deterministicUuid($name),
                'distribution' => 5,
            ],
            'Attribute' => [],
        ];
        foreach ($attributes as $attribute) {
            $object['Attribute'][] = array_merge([
                'event_id' => $eventId,
                'category' => 'Other',
                'to_ids' => 0,
                'distribution' => 5,
                'uuid' => CakeText::uuid(),
            ], $attribute);
        }
        $result = $objectModel->saveObject($object, $eventId, false, $this->adminUser());
        $this->assertIsNumeric(
            $result,
            "could not create fixture object '$name': " . json_encode($result)
        );
        return (int)$result;
    }

    /**
     * A stable string derived from $seed that satisfies CakePHP's uuid
     * validation rule (Validation::uuid()), which is stricter than plain hex
     * grouping: it requires a version nibble (0-7) at the start of the third
     * group and a variant nibble (0/8/9/a/b) at the start of the fourth.
     */
    private function deterministicUuid(string $seed): string
    {
        $hash = md5($seed);
        return sprintf(
            '%s-%s-4%s-8%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 13, 3),
            substr($hash, 17, 3),
            substr($hash, 20, 12)
        );
    }

    private function pin(string $name, array $result): void
    {
        [$ok, $message] = Snapshot::compare($name, $result);
        $this->assertTrue($ok, $message);
    }

    // ------------------------------------------------------- fetchObjects

    public function testDefaultFetchShape(): void
    {
        $result = $this->model('Object')->fetchObjects($this->adminUser(), [
            'conditions' => ['Object.event_id' => $this->eventId],
        ]);
        $this->assertCount(2, $result, 'fetching by event_id must return exactly the two fixture objects');
        // fetchObjects() never filters on Object.deleted - the soft-deleted
        // object B is returned like any other. What the default DOES drop is
        // its (equally soft-deleted) attributes, via the contain-level
        // Attribute.deleted = 0 condition (MispObject.php:760-761).
        $this->assertSame('1', (string)$result[1]['Object']['deleted'], 'a soft-deleted object is still returned by default');
        $this->assertSame([], $result[1]['Attribute'], 'its deleted attributes are not, by default');
        $this->pin('fetchobjects_default', $result);
    }

    public function testMetadataOnlyOmitsAttributes(): void
    {
        $result = $this->model('Object')->fetchObjects($this->adminUser(), [
            'conditions' => ['Object.event_id' => $this->eventId],
            'metadata' => 1,
        ]);
        $this->assertArrayNotHasKey(
            'Attribute',
            $result[0] ?? [],
            'metadata=1 must not hydrate attributes - that is its entire purpose'
        );
        $this->pin('fetchobjects_metadata_only', $result);
    }

    /**
     * The 'deleted' option is NOT an object-level filter: fetchObjects()
     * places no condition on Object.deleted at all. All it does is decide
     * whether the contained Attribute set is narrowed to deleted = 0
     * (MispObject.php:760-761), and only for a perm_sync user with
     * metadata off. So the difference this test pins is entirely in the
     * ATTRIBUTE lists, not in which objects come back.
     */
    public function testDeletedIncludedForASyncCapableUser(): void
    {
        $user = $this->adminUser();
        $user['Role']['perm_sync'] = 1;
        $result = $this->model('Object')->fetchObjects($user, [
            'conditions' => ['Object.event_id' => $this->eventId],
            'deleted' => true,
        ]);
        $this->assertCount(2, $result);
        $this->assertCount(
            1,
            $result[1]['Attribute'],
            "deleted=true must hydrate the soft-deleted object's soft-deleted attribute, which the default fetch omits"
        );
        $this->assertSame('1', (string)$result[1]['Attribute'][0]['deleted']);
        $this->assertSame('beta', $result[1]['Attribute'][0]['value']);
        $this->pin('fetchobjects_deleted_included', $result);
    }

    public function testTypeFilterAppliesToContainedAttributes(): void
    {
        // Attribute is fetched through 'contain', a separate query from the
        // Object/Event query - so unlike Object.* fields, an Attribute
        // filter has to be given inside contain, not the top-level
        // 'conditions' (that produces "Unknown column Attribute.type").
        $result = $this->model('Object')->fetchObjects($this->adminUser(), [
            'conditions' => ['Object.event_id' => $this->eventId],
            'contain' => ['Attribute' => ['conditions' => ['Attribute.type' => 'md5']]],
        ]);
        $this->pin('fetchobjects_type_filter', $result);
    }

    public function testIncludeEventUuidAddsItToEveryObject(): void
    {
        $result = $this->model('Object')->fetchObjects($this->adminUser(), [
            'conditions' => ['Object.event_id' => $this->eventId],
            'includeEventUuid' => true,
        ]);
        // event_uuid is a fresh random UUID every run (createEvent() below
        // generates it via CakeText::uuid(), unlike the 'uuid' key Snapshot
        // knows to alias), so its value cannot be pinned wholesale - assert
        // that it is present and matches the fixture event's own uuid
        // instead.
        $eventUuid = $this->model('Event')->field('uuid', ['Event.id' => $this->eventId]);
        $this->assertNotEmpty($result);
        foreach ($result as $object) {
            $this->assertArrayHasKey(
                'event_uuid',
                $object['Object'],
                'includeEventUuid must stamp event_uuid onto every returned object'
            );
            $this->assertSame($eventUuid, $object['Object']['event_uuid']);
        }
    }

    public function testOrderAndLimitAreHonoured(): void
    {
        $result = $this->model('Object')->fetchObjects($this->adminUser(), [
            'conditions' => ['Object.event_id' => $this->eventId],
            'order' => ['Object.id' => 'DESC'],
            'limit' => 1,
        ]);
        $this->assertCount(1, $result, 'limit must cap the result count');
        $this->assertSame(
            $this->objectBId,
            (int)$result[0]['Object']['id'],
            'order DESC on Object.id must put the most recently created object first'
        );
    }

    public function testFilterMatchingNothingReturnsEmptyNotFatal(): void
    {
        // Attribute.value is a virtual (computed) field, not a real column,
        // so it cannot appear in a WHERE clause - value1 is the real column.
        $result = $this->model('Object')->fetchObjects($this->adminUser(), [
            'conditions' => ['Object.event_id' => $this->eventId],
            'contain' => ['Attribute' => [
                'conditions' => ['Attribute.value1' => 'no-attribute-has-this-value-198.51.100.254'],
            ]],
        ]);
        $this->assertIsArray($result);
        $this->pin('fetchobjects_filter_matches_nothing', $result);
    }

    // -------------------------------------------------- fetchObjectSimple

    public function testFetchObjectSimpleShape(): void
    {
        $result = $this->model('Object')->fetchObjectSimple($this->adminUser(), [
            'conditions' => ['Object.event_id' => $this->eventId],
        ]);
        $this->assertCount(2, $result);
        $this->pin('fetchobjectsimple_default', $result);
    }

    public function testFetchObjectSimpleRespectsFieldsOption(): void
    {
        $result = $this->model('Object')->fetchObjectSimple($this->adminUser(), [
            'conditions' => ['Object.event_id' => $this->eventId],
            'fields' => ['Object.id', 'Object.uuid'],
        ]);
        foreach ($result as $row) {
            $this->assertArrayNotHasKey(
                'name',
                $row['Object'],
                'a fields option that omits name must not hydrate it'
            );
        }
    }

    // ----------------------------------------------------- buildConditions

    public function testSiteAdminGetsNoConditions(): void
    {
        $conditions = $this->model('Object')->buildConditions($this->adminUser());
        $this->assertSame([], $conditions, 'a site admin is not restricted by buildConditions');
    }

    public function testNonAdminGetsOrgAndSharingGroupRestriction(): void
    {
        $user = [
            'id' => 999,
            'org_id' => 1,
            'Role' => ['perm_site_admin' => 0, 'perm_sync' => 0],
        ];
        $conditions = $this->model('Object')->buildConditions($user);
        $this->assertArrayHasKey('AND', $conditions);
        $this->assertSame(
            1,
            $conditions['AND']['OR']['Event.org_id'],
            'a non-admin is always allowed objects on events owned by their own org'
        );
    }

    // ------------------------------------------------- buildFilterConditions

    /**
     * eventid is routed through set_filter_eventid() under the 'Event'
     * scope, which folds the id straight into a structured 'Event.id'
     * condition (unlike an 'Attribute'-scope filter, which is wrapped in a
     * subquery over attributes.object_id - see the combined test below).
     * The id itself is DB-dependent (auto-increment), so it is asserted
     * directly rather than pinned in a snapshot.
     */
    public function testBuildFilterConditionsForEventId(): void
    {
        $params = ['eventid' => $this->eventId];
        $conditions = $this->model('Object')->buildFilterConditions($params);
        $this->assertSame(
            ['AND' => [['AND' => ['OR' => ['Event.id' => [(string)$this->eventId]]]]]],
            $conditions
        );
    }

    public function testBuildFilterConditionsForObjectName(): void
    {
        $params = ['object_name' => 'test-object-a'];
        $conditions = $this->model('Object')->buildFilterConditions($params);
        $this->pin('buildfilterconditions_object_name', $conditions);
    }

    public function testBuildFilterConditionsCombinesMultipleFilters(): void
    {
        $params = ['eventid' => $this->eventId, 'type' => 'md5', 'to_ids' => 1];
        $conditions = $this->model('Object')->buildFilterConditions($params);
        $sql = json_encode($conditions);
        // Each simple_params entry appends its own subquery clause rather
        // than merging into one WHERE - eventid, type and to_ids each
        // surface as their own "Object.id IN (...)" subquery (nested inside
        // one another, since later clauses are ANDed onto the growing
        // condition array that earlier ones already wrote into).
        $this->assertGreaterThanOrEqual(
            2,
            substr_count($sql, 'Object.id IN ('),
            'eventid, type and to_ids each contribute their own subquery clause'
        );
        $this->assertStringContainsString("Attribute`.`type` IN ('md5')", $sql);
        $this->assertStringContainsString('Attribute`.`to_ids` = 1', $sql);
    }

    public function testBuildFilterConditionsWithNoParamsIsEmpty(): void
    {
        $params = [];
        $conditions = $this->model('Object')->buildFilterConditions($params);
        $this->assertSame([], $conditions, 'no filter params must yield no conditions');
    }

    // -------------------------------------------------------------------
    // KNOWN-DEFECT: buildFilterConditions() takes its $params BY REFERENCE
    // (MispObject.php:142) and its 'ignore' branch (MispObject.php:155-158)
    // writes $params['to_ids'] = [0, 1] and $params['published'] = [0, 1]
    // into it. Inside the method that is deliberate and it works: the
    // injection happens before the simple_params loop, so 'to_ids' (Attribute
    // scope) and 'published' (Event scope) are picked up and do widen the
    // query as intended. The defect is that the write ESCAPES - because the
    // parameter is a reference, the caller's own array is mutated, and a
    // caller that reuses $params after the call (to log the request, to
    // re-key it, to pass it on to a second builder) sees two filters it never
    // supplied. Nothing in the return value signals it, and the by-reference
    // parameter is the only hint in the signature.
    // -------------------------------------------------------------------
    public function testIgnoreParamInjectsToIdsAndPublishedIntoCallersParams(): void
    {
        $params = ['ignore' => 1];
        $this->model('Object')->buildFilterConditions($params);
        $this->assertSame(
            [0, 1],
            $params['to_ids'],
            'KNOWN-DEFECT: ignore=1 mutates the caller-owned $params array with to_ids => [0,1]'
        );
        $this->assertSame(
            [0, 1],
            $params['published'],
            'KNOWN-DEFECT: ignore=1 mutates the caller-owned $params array with published => [0,1]'
        );
    }

    // -------------------------------------------------------- attributeCleanup

    public function testAttributeCleanupIsANoOpWithoutAttributes(): void
    {
        $this->assertSame(
            ['Object' => ['name' => 'x']],
            $this->model('Object')->attributeCleanup(['Object' => ['name' => 'x']])
        );
    }

    public function testAttributeCleanupDropsUnsavedAttributes(): void
    {
        $result = $this->model('Object')->attributeCleanup([
            'Attribute' => [
                ['object_relation' => 'a', 'value' => 'keep', 'save' => 1],
                ['object_relation' => 'b', 'value' => 'drop', 'save' => 0],
            ],
        ]);
        $this->assertCount(1, $result['Attribute'], 'save=0 must drop the attribute entirely');
        $this->assertSame('keep', array_values($result['Attribute'])[0]['value']);
    }

    public function testAttributeCleanupResolvesValueSelect(): void
    {
        $result = $this->model('Object')->attributeCleanup([
            'Attribute' => [
                ['object_relation' => 'a', 'value' => 'ignored', 'value_select' => 'picked-value'],
            ],
        ]);
        $this->assertSame('picked-value', $result['Attribute'][0]['value']);
        $this->assertArrayNotHasKey('value_select', $result['Attribute'][0]);
    }

    public function testAttributeCleanupLeavesManualValueAloneWhenSentinelChosen(): void
    {
        $result = $this->model('Object')->attributeCleanup([
            'Attribute' => [
                ['object_relation' => 'a', 'value' => 'typed-in', 'value_select' => 'Enter value manually'],
            ],
        ]);
        $this->assertSame('typed-in', $result['Attribute'][0]['value']);
    }

    public function testAttributeCleanupDefaultsFirstAndLastSeenToNull(): void
    {
        $result = $this->model('Object')->attributeCleanup([
            'Attribute' => [
                ['object_relation' => 'a', 'value' => 'v'],
            ],
        ]);
        $this->assertNull($result['Attribute'][0]['first_seen']);
        $this->assertNull($result['Attribute'][0]['last_seen']);
    }

    // ------------------------------------------------------ findSimilarObjects

    public function testFindSimilarObjectsMatchesOnAttributeValue(): void
    {
        $template = ['ObjectTemplate' => ['uuid' => 'no-such-template-uuid-0000-000000000000']];
        [$count, $objects, $flattened, $flattenedNoVal] = $this->model('Object')->findSimilarObjects(
            $this->adminUser(),
            $this->eventId,
            [['object_relation' => 'text', 'type' => 'text', 'value' => 'alpha']],
            $template
        );
        // The fixture objects carry a deterministic per-name template_uuid
        // (see createObject() above), which never matches the fabricated
        // template uuid used here, so fetchObjects() inside
        // findSimilarObjects() filters every match back out - the similarity
        // count still reflects the raw attribute-value match before that
        // filter runs.
        $this->assertSame(1, $count, 'exactly one attribute in the fixture has value "alpha"');
        $this->assertSame([], $objects, 'template_uuid mismatch must exclude the object from the returned list');
        $this->assertSame(
            ['text.text.alpha' => 0],
            $flattened
        );
        $this->assertSame(['text.text' => 0], $flattenedNoVal);
    }

    public function testFindSimilarObjectsReturnsAllZerosWhenNothingMatches(): void
    {
        $template = ['ObjectTemplate' => ['uuid' => 'no-such-template-uuid-0000-000000000000']];
        $result = $this->model('Object')->findSimilarObjects(
            $this->adminUser(),
            $this->eventId,
            [['object_relation' => 'text', 'type' => 'text', 'value' => 'no-attribute-has-this-value']],
            $template
        );
        $this->assertSame([0, [], [], []], $result);
    }

    // -------------------------------------------------- syncObjectAndAttributeSeen

    public function testSyncObjectAndAttributeSeenIsANoOpWithoutForcedValues(): void
    {
        $object = ['Object' => ['id' => $this->objectAId, 'first_seen' => null]];
        $this->assertSame($object, $this->model('Object')->syncObjectAndAttributeSeen($object, []));
    }

    public function testSyncObjectAndAttributeSeenAppliesFirstSeenToInlineAttributes(): void
    {
        $object = [
            'Object' => ['id' => $this->objectAId, 'first_seen' => null],
            'Attribute' => [
                ['object_relation' => 'text', 'first_seen' => null],
                ['object_relation' => 'first-seen', 'first_seen' => null, 'value' => 'old'],
            ],
        ];
        $result = $this->model('Object')->syncObjectAndAttributeSeen(
            $object,
            ['first_seen' => '2020-01-01T00:00:00+00:00']
        );
        $this->assertSame('2020-01-01T00:00:00+00:00', $result['Object']['first_seen']);
        $this->assertSame('2020-01-01T00:00:00+00:00', $result['Attribute'][0]['first_seen']);
        $this->assertSame(
            '2020-01-01T00:00:00+00:00',
            $result['Attribute'][1]['value'],
            'an attribute whose object_relation is literally "first-seen" also gets its value overwritten'
        );
    }

    public function testSyncObjectAndAttributeSeenFetchesAttributesWhenNotInline(): void
    {
        $object = ['Object' => ['id' => $this->objectAId]];
        $result = $this->model('Object')->syncObjectAndAttributeSeen(
            $object,
            ['last_seen' => '2020-06-15T00:00:00+00:00']
        );
        $this->assertSame('2020-06-15T00:00:00+00:00', $result['Object']['last_seen']);
        $this->assertCount(
            2,
            $result['Attribute'],
            'without an inline Attribute key, the method must fall back to fetching the object\'s attributes from the database'
        );
        foreach ($result['Attribute'] as $attribute) {
            $this->assertSame('2020-06-15T00:00:00+00:00', $attribute['last_seen']);
        }
    }

    public function testUnknownEventIdReturnsEmptyForFetchObjects(): void
    {
        $result = $this->model('Object')->fetchObjects($this->adminUser(), [
            'conditions' => ['Object.event_id' => 999999999],
        ]);
        $this->assertSame([], $result, 'an unknown event id must yield an empty result, not a fatal');
    }
}
