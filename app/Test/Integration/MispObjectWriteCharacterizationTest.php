<?php

require_once __DIR__ . '/IntegrationTestCase.php';

/**
 * Characterization of MispObject's WRITE path: saveObject, captureObject,
 * editObject, deltaMerge, reviseObject, resolveUpdatedTemplate,
 * prepareTemplate, deleteObject and groupAttributesIntoObject.
 *
 * These are the object-model twins of the methods EventWriteCharacterizationTest
 * pins for Event, and for the same reason: sync, feed ingest and the REST API
 * all write objects through this path, and nothing pinned it before this file.
 * EventWriteCharacterizationTest found three real defects in the analogous
 * Event methods (a bare-id return where an array was expected, a stale-update
 * path reported as an error, duplicate-uuid handling that surprises callers).
 * This file goes looking for the same CLASSES of problem here, and finds
 * them: captureObject collapses "created" and "silently dropped as a
 * duplicate" onto the same `true` return value, and editObject does the same
 * for "silently dropped because another event already owns this uuid". A
 * fourth, structural one turned up along the way: captureObject() reads
 * attributes from $object['Object']['Attribute'] while saveObject() and
 * deltaMerge() read the sibling key $object['Attribute'] - passing either
 * shape to the wrong method is not an error, it just silently keeps zero
 * attributes.
 *
 * CHARACTERIZATION, not specification (ADR 0002): these record today's
 * behaviour so a change is detected. Where that behaviour is surprising, the
 * KNOWN-DEFECT comments below explain the mechanism rather than fixing it.
 */
class MispObjectWriteCharacterizationTest extends IntegrationTestCase
{
    /**
     * Build a minimal, valid object payload. saveObject() accepts the
     * template fields supplied directly on Object when no $template array is
     * passed - no on-disk ObjectTemplate row is needed.
     *
     * $eventId is required here (unlike captureAttribute(), saveAttributes() -
     * the sink for this top-level 'Attribute' shape used by saveObject() and
     * deltaMerge() - never stamps event_id onto the rows itself, and the
     * attributes.event_id column has no default, so a caller that forgets it
     * gets SQLSTATE[HY000] 1364 rather than a validation error).
     */
    private function objectPayload(int $eventId, string $name, array $attributes = [], array $overrides = []): array
    {
        $object = array_merge([
            'name' => $name,
            'meta-category' => 'test',
            'description' => 'MispObjectWriteCharacterizationTest fixture',
            'template_version' => 1,
            // Deterministic, not CakeText::uuid(): a random uuid here would
            // make the duplicate-detection tests flaky (checkForDuplicateObjects
            // keys its cache on template_uuid).
            'template_uuid' => $this->deterministicUuid($name),
            'distribution' => 5,
        ], $overrides);

        $attrs = [];
        foreach ($attributes as $attribute) {
            $attrs[] = array_merge([
                'event_id' => $eventId,
                'category' => 'Other',
                'to_ids' => 0,
                'distribution' => 5,
                'uuid' => CakeText::uuid(),
            ], $attribute);
        }
        return ['Object' => $object, 'Attribute' => $attrs];
    }

    /**
     * captureObject() (and, through it, editObject()'s no-uuid/captureObject
     * delegation branch) does not read the sibling 'Attribute' key that
     * saveObject()/deltaMerge() use - it only looks at $object['Object']['Attribute']
     * (MispObject.php:1227-1229), matching the shape of Event JSON's nested
     * Object.Attribute. Passing the sibling-Attribute shape from objectPayload()
     * to captureObject() silently captures ZERO attributes - no error, no
     * validation failure, just an object with none of the attributes it was
     * given. This helper builds the shape captureObject() actually reads.
     */
    private function captureObjectPayload(string $name, array $attributes = [], array $overrides = []): array
    {
        // event_id is irrelevant here - captureAttribute() stamps it itself -
        // so any placeholder value is fine.
        $payload = $this->objectPayload(0, $name, $attributes, $overrides);
        $object = $payload['Object'];
        $object['Attribute'] = $payload['Attribute'];
        return ['Object' => $object];
    }

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

    private function findObject(int $id): array
    {
        return $this->model('Object')->find('first', [
            'recursive' => -1,
            'conditions' => ['Object.id' => $id],
        ]);
    }

    /**
     * find('first', ['recursive' => -1, ...]) - the convention this file
     * otherwise uses to keep queries flat - never hydrates the Attribute
     * hasMany association, so an Object row fetched that way has no
     * 'Attribute' key at all. deltaMerge() and deleteObject() both read
     * $object['Attribute'] directly (not via a fetch of their own), so tests
     * that feed them a real object need it queried in explicitly.
     */
    private function findObjectWithAttributes(int $id): array
    {
        $object = $this->findObject($id);
        $attributes = $this->model('MispAttribute')->find('all', [
            'recursive' => -1,
            'conditions' => ['Attribute.object_id' => $id, 'Attribute.deleted' => 0],
        ]);
        $object['Attribute'] = array_column($attributes, 'Attribute');
        return $object;
    }

    private function countObjectsForEvent(int $eventId): int
    {
        return (int)$this->model('Object')->find('count', [
            'recursive' => -1,
            'conditions' => ['Object.event_id' => $eventId],
        ]);
    }

    // ------------------------------------------------------------ saveObject

    public function testSaveObjectCreatesAnObjectAndReturnsItsBareId(): void
    {
        $eventId = $this->createEvent('characterize saveObject', []);
        $payload = $this->objectPayload($eventId, 'save-object-basic', [
            ['type' => 'text', 'object_relation' => 'text', 'value' => 'hello'],
        ]);

        $result = $this->model('Object')->saveObject($payload, $eventId, false, $this->adminUser());

        // saveObject() returns the bare integer id on success - not an array,
        // not a boolean. A caller that treats every result as "truthy means
        // ok" and only inspects it for an 'error'/'value' key (as
        // groupAttributesIntoObject does via is_numeric()) works; one that
        // expects an array shape does not.
        $this->assertIsNumeric($result, 'saveObject must return a bare numeric id on success');
        $created = $this->findObjectWithAttributes((int)$result);
        $this->assertNotEmpty($created, 'the object must exist after saveObject');
        $this->assertSame('save-object-basic', $created['Object']['name']);
        $this->assertCount(1, $created['Attribute'], 'the inline attribute must be saved and linked to the object');
    }

    public function testSaveObjectWithoutTemplateInfoReportsTheFirstMissingFieldOnly(): void
    {
        $eventId = $this->createEvent('characterize saveObject missing template', []);
        $payload = $this->objectPayload($eventId, 'save-object-missing', []);
        unset($payload['Object']['name'], $payload['Object']['description']);

        $result = $this->model('Object')->saveObject($payload, $eventId, false, $this->adminUser());

        // templateFields is iterated in declaration order (name first) and
        // the loop returns on the FIRST missing key - 'description' being
        // missing too is never reported. The error is keyed 'template', not
        // 'error' or 'validationErrors', so a caller checking for those
        // finds nothing wrong here.
        $this->assertIsArray($result);
        $this->assertArrayHasKey('template', $result);
        $this->assertStringContainsString(
            '(name)',
            $result['template'][0],
            'saveObject reports only the first missing template field (name), not every missing field'
        );
        $this->assertSame(0, $this->countObjectsForEvent($eventId), 'no object row must be created on this path');
    }

    public function testSaveObjectAcceptsATemplateArrayInPlaceOfInlineFields(): void
    {
        $eventId = $this->createEvent('characterize saveObject with template', []);
        $payload = ['Object' => ['distribution' => 5], 'Attribute' => []];
        $template = ['ObjectTemplate' => [
            'name' => 'from-template',
            'meta-category' => 'test',
            'description' => 'supplied via $template, not inline',
            'version' => 3,
            'uuid' => $this->deterministicUuid('from-template'),
        ]];

        $result = $this->model('Object')->saveObject($payload, $eventId, $template, $this->adminUser());

        $this->assertIsNumeric($result);
        $created = $this->findObject((int)$result);
        $this->assertSame('from-template', $created['Object']['name']);
        $this->assertSame('3', (string)$created['Object']['template_version'], 'template_version is copied from ObjectTemplate.version');
    }

    // --------------------------------------------------------- captureObject

    public function testCaptureObjectCreatesAnObjectAndReturnsBooleanTrue(): void
    {
        $eventId = $this->createEvent('characterize captureObject', []);
        $payload = $this->captureObjectPayload('capture-object-basic', [
            ['type' => 'text', 'object_relation' => 'text', 'value' => 'v1'],
        ]);

        $result = $this->model('Object')->captureObject($payload, $eventId, $this->adminUser());

        $this->assertSame(true, $result, 'captureObject signals success with the boolean true, not the new id');
        $this->assertSame(1, $this->countObjectsForEvent($eventId));
        $stored = $this->model('Object')->find('first', ['recursive' => -1, 'conditions' => ['Object.event_id' => $eventId]]);
        $stored = $this->findObjectWithAttributes((int)$stored['Object']['id']);
        $this->assertCount(1, $stored['Attribute'], 'the nested Object.Attribute entry must be captured onto the object');
    }

    /**
     * KNOWN-DEFECT: captureObject() only reads attributes from
     * $object['Object']['Attribute'] (MispObject.php:1227-1229). saveObject() and
     * deltaMerge() read them from a SIBLING 'Attribute' key instead
     * ($object['Attribute']). The two public entry points for writing an
     * object accept structurally different payloads for the exact same
     * concept, and passing the wrong one is not an error: captureObject()
     * happily creates the object and silently keeps zero attributes, because
     * `!empty($object['Object']['Attribute'])` is simply false and the
     * foreach never runs.
     */
    public function testCaptureObjectSilentlyDropsAttributesGivenAsASiblingKeyInsteadOfNested(): void
    {
        $eventId = $this->createEvent('characterize captureObject sibling attribute shape', []);
        // objectPayload() returns the saveObject()/deltaMerge() shape:
        // ['Object' => [...], 'Attribute' => [...]] - deliberately wrong for
        // captureObject().
        $payload = $this->objectPayload($eventId, 'capture-object-sibling-shape', [
            ['type' => 'text', 'object_relation' => 'text', 'value' => 'never-stored'],
        ]);
        $this->assertArrayHasKey('Attribute', $payload, 'sanity: the sibling key is really there in the payload');

        $result = $this->model('Object')->captureObject($payload, $eventId, $this->adminUser());

        $this->assertSame(true, $result, 'KNOWN-DEFECT: captureObject reports success even though it captured no attributes at all');
        $this->assertSame(1, $this->countObjectsForEvent($eventId), 'the object row itself is still created');
        $attributeCount = $this->model('MispAttribute')->find('count', [
            'recursive' => -1,
            'conditions' => ['Attribute.event_id' => $eventId],
        ]);
        $this->assertSame(0, $attributeCount, 'KNOWN-DEFECT: the sibling-shaped attribute is silently discarded, with no error and no log entry distinguishing this from a genuinely empty object');
    }

    public function testCaptureObjectValidationFailureReturnsTheStringFail(): void
    {
        $eventId = $this->createEvent('characterize captureObject validation failure', []);
        // 'name' is a required Object field; omitting it fails model
        // validation inside save(), which is a different failure mode from
        // the missing-template-field check saveObject() performs up front.
        $payload = $this->captureObjectPayload('capture-object-invalid', []);
        unset($payload['Object']['name']);

        $result = $this->model('Object')->captureObject($payload, $eventId, $this->adminUser());

        // Three distinct return shapes now exist across this file for "did
        // not save": saveObject's array of validationErrors, saveObject's
        // array keyed 'template', and captureObject's bare string 'fail'.
        // None of them are the boolean false a naive truthiness check would
        // expect from a failed save.
        $this->assertSame('fail', $result, "captureObject reports a validation failure as the literal string 'fail'");
        $this->assertSame(0, $this->countObjectsForEvent($eventId));
    }

    /**
     * KNOWN-DEFECT: captureObject() with breakOnDuplicate returns the exact
     * same value - boolean true - for "object created" and for "object
     * silently dropped because an identical one already exists" (MispObject.php:1202-1207,
     * the `if ($duplicate) { ...; return true; }` branch). A
     * caller that only checks truthiness - which the return type invites,
     * since `true` is the sole success signal on the happy path too - cannot
     * tell a captured object from a dropped one without re-querying the
     * database. Contrast with Event::_add(), which at least returns a
     * different value shape (the existing event's id) on a duplicate.
     */
    public function testCaptureObjectWithBreakOnDuplicateDropsSilentlyAndReturnsTheSameTrueAsSuccess(): void
    {
        $eventId = $this->createEvent('characterize captureObject duplicate', []);
        $payload = $this->captureObjectPayload('capture-object-dup', [
            ['type' => 'text', 'object_relation' => 'text', 'value' => 'same-value'],
        ]);

        $first = $this->model('Object')->captureObject($payload, $eventId, $this->adminUser());
        $this->assertSame(true, $first);
        $this->assertSame(1, $this->countObjectsForEvent($eventId));

        $duplicatePayload = $this->captureObjectPayload('capture-object-dup', [
            ['type' => 'text', 'object_relation' => 'text', 'value' => 'same-value'],
        ]);
        $second = $this->model('Object')->captureObject($duplicatePayload, $eventId, $this->adminUser(), true, true);

        $this->assertSame(
            $first,
            $second,
            'KNOWN-DEFECT: a dropped duplicate and a real save both return boolean true, indistinguishable to the caller'
        );
        $this->assertSame(1, $this->countObjectsForEvent($eventId), 'the duplicate must not create a second object row');
    }

    // ------------------------------------------------------------ editObject

    public function testEditObjectWithoutAUuidDelegatesToCaptureObject(): void
    {
        $eventId = $this->createEvent('characterize editObject no uuid', []);
        $event = $this->model('Event')->find('first', [
            'recursive' => -1,
            'conditions' => ['Event.id' => $eventId],
        ]);
        // editObject() passes $object straight to captureObject() unchanged,
        // so this needs the nested Object.Attribute shape too.
        $payload = $this->captureObjectPayload('edit-object-no-uuid', [
            ['type' => 'text', 'object_relation' => 'text', 'value' => 'v'],
        ])['Object'];

        $result = $this->model('Object')->editObject($payload, $event, $this->adminUser(), false);

        $this->assertSame(true, $result, 'no uuid on the object routes editObject straight into captureObject, whose success value it returns as-is');
        $this->assertSame(1, $this->countObjectsForEvent((int)$event['Event']['id']));
    }

    public function testEditObjectWithANewerTimestampAppliesTheChange(): void
    {
        $eventId = $this->createEvent('characterize editObject newer', []);
        $event = $this->model('Event')->find('first', ['recursive' => -1, 'conditions' => ['Event.id' => $eventId]]);
        $uuid = CakeText::uuid();
        $base = $this->captureObjectPayload('edit-object-newer', [], ['uuid' => $uuid, 'timestamp' => time() - 3600]);
        $objectId = $this->model('Object')->captureObject($base, $eventId, $this->adminUser());
        $this->assertSame(true, $objectId);

        $newer = ['uuid' => $uuid, 'name' => 'edit-object-newer', 'timestamp' => time(), 'distribution' => 5];
        $nothingToChange = false;
        $result = $this->model('Object')->editObject($newer, $event, $this->adminUser(), false, false, $nothingToChange);

        $this->assertSame(true, $result);
        $this->assertFalse($nothingToChange, 'a newer timestamp is a real edit, not a no-op');
        $stored = $this->model('Object')->find('first', ['recursive' => -1, 'conditions' => ['Object.uuid' => $uuid]]);
        $this->assertSame((int)$newer['timestamp'], (int)$stored['Object']['timestamp']);
    }

    /**
     * A stale update (incoming timestamp <= what is already stored) is a
     * no-op reported as SUCCESS (`true`), with the fact that nothing changed
     * surfaced only through the by-reference $nothingToChange flag. This is
     * the opposite of Event::_edit(), which reports the equivalent stale case
     * as an array with an 'error' key (see EventWriteCharacterizationTest and
     * MISP/MISP#911) - two sibling models disagree on whether "nothing to do"
     * is success or failure.
     */
    public function testEditObjectWithAStaleTimestampIsANoOpReportedAsSuccessUnlikeEvent(): void
    {
        $eventId = $this->createEvent('characterize editObject stale', []);
        $event = $this->model('Event')->find('first', ['recursive' => -1, 'conditions' => ['Event.id' => $eventId]]);
        $uuid = CakeText::uuid();
        $now = time();
        $base = $this->captureObjectPayload('edit-object-stale', [], ['uuid' => $uuid, 'timestamp' => $now]);
        $this->model('Object')->captureObject($base, $eventId, $this->adminUser());

        $stale = ['uuid' => $uuid, 'name' => 'edit-object-stale-attempt', 'timestamp' => $now - 7200, 'distribution' => 5];
        $nothingToChange = false;
        $result = $this->model('Object')->editObject($stale, $event, $this->adminUser(), false, false, $nothingToChange);

        $this->assertSame(true, $result, 'a stale object edit is reported as success (true), not an error array');
        $this->assertTrue($nothingToChange, 'the no-op is only visible through the by-reference $nothingToChange flag');
        $stored = $this->model('Object')->find('first', ['recursive' => -1, 'conditions' => ['Object.uuid' => $uuid]]);
        $this->assertSame('edit-object-stale', $stored['Object']['name'], 'the stale payload must not have overwritten the stored name');
    }

    /**
     * KNOWN-DEFECT: when the incoming uuid already belongs to an object on a
     * DIFFERENT event, editObject() writes a log entry and returns boolean
     * `true` (MispObject.php:1297-1301) - the exact same value the real
     * success path returns. Nothing distinguishes "your edit was applied"
     * from "your edit was silently discarded because of a uuid collision"
     * except reading the audit log. A sync client acting on the return value
     * alone believes the write succeeded.
     */
    public function testEditObjectWithAUuidOwnedByAnotherEventIsDroppedButReportsTrue(): void
    {
        $ownerId = $this->createEvent('characterize editObject uuid owner', []);
        $otherId = $this->createEvent('characterize editObject uuid other', []);
        $otherEvent = $this->model('Event')->find('first', ['recursive' => -1, 'conditions' => ['Event.id' => $otherId]]);

        $uuid = CakeText::uuid();
        $owned = $this->captureObjectPayload('edit-object-collision', [], ['uuid' => $uuid]);
        $this->model('Object')->captureObject($owned, $ownerId, $this->adminUser());
        $this->assertSame(1, $this->countObjectsForEvent($ownerId));

        $collidingEdit = ['uuid' => $uuid, 'name' => 'edit-object-collision-attempt', 'timestamp' => time(), 'distribution' => 5];
        $result = $this->model('Object')->editObject($collidingEdit, $otherEvent, $this->adminUser(), false);

        $this->assertSame(
            true,
            $result,
            "KNOWN-DEFECT: a uuid collision with another event's object is dropped but reported as the same true a real edit returns"
        );
        $this->assertSame(0, $this->countObjectsForEvent($otherId), 'no object must be created on the colliding event');
        $stillOwned = $this->model('Object')->find('first', ['recursive' => -1, 'conditions' => ['Object.uuid' => $uuid]]);
        $this->assertSame($ownerId, (int)$stillOwned['Object']['event_id'], 'the original object must remain on its original event');
    }

    // ----------------------------------------------------------- deltaMerge

    public function testDeltaMergeUpdatesTheObjectAndReturnsItsBareId(): void
    {
        $eventId = $this->createEvent('characterize deltaMerge', []);
        $payload = $this->objectPayload($eventId, 'delta-merge-basic', [
            ['type' => 'text', 'object_relation' => 'text', 'value' => 'orig', 'uuid' => CakeText::uuid()],
        ]);
        $objectId = $this->model('Object')->saveObject($payload, $eventId, false, $this->adminUser());
        $existing = $this->findObject((int)$objectId);

        $objectToSave = ['Object' => ['comment' => 'merged in'], 'Attribute' => []];
        $result = $this->model('Object')->deltaMerge($existing, $objectToSave, false, $this->adminUser());

        // deltaMerge(), like saveObject(), returns the bare integer id on
        // success and $this->validationErrors (an array) on failure - the
        // same int-or-array duality.
        $this->assertSame((int)$objectId, (int)$result);
        $stored = $this->findObject((int)$objectId);
        $this->assertSame('merged in', $stored['Object']['comment']);
    }

    public function testDeltaMergeDropsAttributesAbsentFromTheIncomingSet(): void
    {
        $eventId = $this->createEvent('characterize deltaMerge drop', []);
        $keepUuid = CakeText::uuid();
        $dropUuid = CakeText::uuid();
        $payload = $this->objectPayload($eventId, 'delta-merge-drop', [
            ['type' => 'text', 'object_relation' => 'a', 'value' => 'keep-me', 'uuid' => $keepUuid],
            ['type' => 'text', 'object_relation' => 'b', 'value' => 'drop-me', 'uuid' => $dropUuid],
        ]);
        $objectId = $this->model('Object')->saveObject($payload, $eventId, false, $this->adminUser());
        $existing = $this->findObjectWithAttributes((int)$objectId);

        // Only the 'keep-me' attribute is present in objectToSave - deltaMerge
        // (not onlyAddNewAttribute) treats every originalAttribute it did not
        // match against by uuid as no longer wanted and soft-deletes it.
        $objectToSave = ['Object' => [], 'Attribute' => [
            ['uuid' => $keepUuid, 'object_relation' => 'a', 'type' => 'text', 'value' => 'keep-me',
             'category' => 'Other', 'to_ids' => 0, 'distribution' => 5],
        ]];
        $this->model('Object')->deltaMerge($existing, $objectToSave, false, $this->adminUser());

        $remaining = $this->model('MispAttribute')->find('all', [
            'recursive' => -1,
            'conditions' => ['Attribute.object_id' => (int)$objectId, 'Attribute.deleted' => 0],
            'fields' => ['Attribute.uuid'],
        ]);
        $this->assertCount(1, $remaining, 'the attribute absent from the incoming set must be soft-deleted, not left in place');
        $this->assertSame($keepUuid, $remaining[0]['Attribute']['uuid']);
    }

    public function testDeltaMergeWithOnlyAddNewAttributeAddsWithoutTouchingExisting(): void
    {
        $eventId = $this->createEvent('characterize deltaMerge onlyAdd', []);
        $keepUuid = CakeText::uuid();
        $payload = $this->objectPayload($eventId, 'delta-merge-only-add', [
            ['type' => 'text', 'object_relation' => 'a', 'value' => 'untouched', 'uuid' => $keepUuid],
        ]);
        $objectId = $this->model('Object')->saveObject($payload, $eventId, false, $this->adminUser());
        $existing = $this->findObject((int)$objectId);

        $objectToSave = ['Object' => [], 'Attribute' => [
            ['object_relation' => 'b', 'type' => 'text', 'value' => 'brand-new',
             'category' => 'Other', 'to_ids' => 0, 'distribution' => 5, 'uuid' => CakeText::uuid()],
        ]];
        $result = $this->model('Object')->deltaMerge($existing, $objectToSave, true, $this->adminUser());

        $this->assertSame((int)$objectId, (int)$result, 'onlyAddNewAttribute still returns the object id, from $this->id set by the earlier object save');
        $attributes = $this->model('MispAttribute')->find('all', [
            'recursive' => -1,
            'conditions' => ['Attribute.object_id' => (int)$objectId, 'Attribute.deleted' => 0],
            'fields' => ['Attribute.value'],
            'order' => ['Attribute.value' => 'ASC'],
        ]);
        $values = array_column(array_column($attributes, 'Attribute'), 'value');
        sort($values);
        $this->assertSame(['brand-new', 'untouched'], $values, 'the original attribute must survive when onlyAddNewAttribute is set');
    }

    // ---------------------------------------------------------- deleteObject

    public function testDeleteObjectHardDeleteCascadesAttributes(): void
    {
        $eventId = $this->createEvent('characterize deleteObject hard', []);
        $payload = $this->objectPayload($eventId, 'delete-object-hard', [
            ['type' => 'text', 'object_relation' => 'a', 'value' => 'v'],
        ]);
        $objectId = (int)$this->model('Object')->saveObject($payload, $eventId, false, $this->adminUser());
        $object = $this->findObject($objectId);

        $result = $this->model('Object')->deleteObject($object, true);

        $this->assertSame(true, $result);
        $this->assertEmpty($this->findObject($objectId), 'hard delete must remove the object row');
        $orphans = $this->model('MispAttribute')->find('count', [
            'recursive' => -1,
            'conditions' => ['Attribute.object_id' => $objectId],
        ]);
        $this->assertSame(0, (int)$orphans, 'hard delete must cascade to the object\'s attributes');
    }

    public function testDeleteObjectSoftDeleteMarksDeletedWithoutRemovingTheRow(): void
    {
        $eventId = $this->createEvent('characterize deleteObject soft', []);
        $payload = $this->objectPayload($eventId, 'delete-object-soft', [
            ['type' => 'text', 'object_relation' => 'a', 'value' => 'v'],
        ]);
        $objectId = (int)$this->model('Object')->saveObject($payload, $eventId, false, $this->adminUser());
        $object = $this->findObjectWithAttributes($objectId);

        $result = $this->model('Object')->deleteObject($object, false);

        $this->assertSame(true, $result);
        $stored = $this->findObject($objectId);
        $this->assertNotEmpty($stored, 'a soft delete must leave the row in place');
        $this->assertSame('1', (string)$stored['Object']['deleted'], 'a soft delete must set the deleted flag');
        $attributes = $this->model('MispAttribute')->find('all', [
            'recursive' => -1,
            'conditions' => ['Attribute.object_id' => $objectId],
        ]);
        foreach ($attributes as $attribute) {
            $this->assertSame('1', (string)$attribute['Attribute']['deleted'], 'a soft object delete must soft-delete its attributes too');
        }
    }

    // -------------------------------------------------- groupAttributesIntoObject

    public function testGroupAttributesIntoObjectMovesLooseAttributesOntoTheNewObject(): void
    {
        $eventId = $this->createEvent('characterize groupAttributesIntoObject', [
            ['type' => 'ip-dst', 'value' => '203.0.113.50'],
        ]);
        $looseAttribute = $this->model('MispAttribute')->find('first', [
            'recursive' => -1,
            'conditions' => ['Attribute.event_id' => $eventId],
        ]);
        $originalAttributeId = (int)$looseAttribute['Attribute']['id'];

        $payload = $this->objectPayload($eventId, 'group-attributes', []);
        $result = $this->model('Object')->groupAttributesIntoObject(
            $this->adminUser(),
            $eventId,
            $payload,
            false,
            [$originalAttributeId],
            [$originalAttributeId => 'ip'],
            false
        );

        $this->assertIsNumeric($result, 'success returns the numeric new object id, same convention as saveObject()');
        $objectId = (int)$result;

        // The original loose attribute is soft-deleted (hard_delete_attribute
        // was false) and a NEW attribute row is captured under the object -
        // groupAttributesIntoObject does not simply repoint object_id on the
        // original row.
        $original = $this->model('MispAttribute')->find('first', [
            'recursive' => -1,
            'conditions' => ['Attribute.id' => $originalAttributeId],
        ]);
        $this->assertSame('1', (string)$original['Attribute']['deleted'], 'the original loose attribute must be (soft) deleted, not left dangling with object_id=0');

        $moved = $this->model('MispAttribute')->find('first', [
            'recursive' => -1,
            'conditions' => ['Attribute.object_id' => $objectId, 'Attribute.deleted' => 0],
        ]);
        $this->assertNotEmpty($moved, 'a new attribute row must exist under the created object');
        $this->assertSame('203.0.113.50', $moved['Attribute']['value']);
        $this->assertSame('ip', $moved['Attribute']['object_relation']);
        $this->assertNotSame(
            $originalAttributeId,
            (int)$moved['Attribute']['id'],
            'the moved attribute is a new row (new id), not the original renumbered'
        );
    }

    public function testGroupAttributesIntoObjectWithNoMatchingSelectedAttributesReportsAStringError(): void
    {
        $eventId = $this->createEvent('characterize groupAttributesIntoObject no match', []);
        $payload = $this->objectPayload($eventId, 'group-attributes-empty', []);

        $result = $this->model('Object')->groupAttributesIntoObject(
            $this->adminUser(),
            $eventId,
            $payload,
            false,
            [999999999],
            [999999999 => 'ip'],
            false
        );

        // Object creation already succeeded by this point (saveObject ran
        // first) - the object is NOT rolled back even though the method goes
        // on to report failure via a translated string rather than the
        // numeric id its own is_numeric() guard checks for on the earlier path.
        $this->assertIsString($result);
        $this->assertSame(1, $this->countObjectsForEvent($eventId), 'the object saved before the attribute lookup failed is not rolled back');
    }

    // ------------------------------------------------------ resolveUpdatedTemplate

    public function testResolveUpdatedTemplateWithNoNewerTemplateReturnsAllFalseDefaults(): void
    {
        $object = [
            'Object' => ['template_uuid' => 'no-such-template-uuid-0000-000000000000', 'template_version' => 1],
            'Attribute' => [],
        ];
        $template = ['ObjectTemplateElement' => []];

        $result = $this->model('Object')->resolveUpdatedTemplate($template, $object);

        $this->assertSame([
            'updateable_attribute' => false,
            'not_updateable_attribute' => false,
            'newer_template_version' => false,
            'original_template_unknown' => false,
            'template' => $template,
        ], $result, 'with no newer ObjectTemplate row for this uuid, every flag stays at its false default and the original $template is echoed back unchanged');
    }

    // ------------------------------------------------------------- prepareTemplate

    public function testPrepareTemplateFillsDefaultsFromTypeDefinitionsWhenRequestIsEmpty(): void
    {
        $template = [
            'ObjectTemplate' => ['id' => 1],
            'ObjectTemplateElement' => [
                ['object_relation' => 'ip', 'type' => 'ip-dst', 'ui-priority' => 1, 'categories' => []],
            ],
        ];

        $result = $this->model('Object')->prepareTemplate($template);

        // prepareTemplate() returns the SAME $template array (not a
        // separate structure): it strips ObjectTemplateElement, then rebuilds
        // it element-by-element with defaults filled in from
        // Attribute::typeDefinitions/categoryDefinitions.
        $this->assertArrayHasKey('ObjectTemplateElement', $result);
        $element = $result['ObjectTemplateElement'][0];
        $this->assertSame(
            'ip-dst',
            $element['type'],
            'the element keeps its declared type when no prior request supplies one'
        );
        $this->assertArrayHasKey(
            'default_category',
            $element,
            'a known type gets default_category/to_ids filled in from Attribute::typeDefinitions'
        );
        $this->assertContains(
            'Network activity',
            $element['categories'],
            'an empty categories array on the input element is treated as unset and recomputed from categoryDefinitions'
        );
    }

    public function testPrepareTemplateOmitsAnElementForAnUnknownAttributeTypeWithAWarning(): void
    {
        $template = [
            'ObjectTemplate' => ['id' => 1],
            'ObjectTemplateElement' => [
                ['object_relation' => 'x', 'type' => 'not-a-real-attribute-type', 'ui-priority' => 1, 'categories' => []],
            ],
        ];

        $result = $this->model('Object')->prepareTemplate($template);

        $this->assertArrayNotHasKey(
            'ObjectTemplateElement',
            $result,
            'an unrecognised type produces zero elements, so the key is never (re)created - a caller iterating it without isset() gets an undefined-key warning, not an empty list'
        );
        $this->assertArrayHasKey('warnings', $result);
        $this->assertStringContainsString('not-a-real-attribute-type', $result['warnings'][0]);
    }

    // --------------------------------------------------------------- reviseObject

    public function testReviseObjectInjectsANonCollidingAttributeAsMergeable(): void
    {
        $template = ['ObjectTemplateElement' => []];
        $object = ['Attribute' => [
            ['object_relation' => 'a', 'type' => 'text', 'value' => 'existing'],
        ]];
        $revisedObject = ['Attribute' => [
            ['object_relation' => 'b', 'type' => 'text', 'value' => 'new-one'],
        ]];

        $result = $this->model('Object')->reviseObject($revisedObject, $object, $template);

        $this->assertCount(1, $result['revised_object_both']['mergeable']);
        $this->assertSame('new-one', $result['revised_object_both']['mergeable'][0]['value']);
        $this->assertEmpty($result['revised_object_both']['notMergeable']);
        $this->assertCount(2, $result['object']['Attribute'], 'the non-colliding attribute is appended into the working object');
    }

    public function testReviseObjectFlagsAValueCollisionOnASingleAttributeAsNotMergeable(): void
    {
        $template = ['ObjectTemplateElement' => [
            // 'multiple' left unset/false: only one value is allowed for this relation+type.
            ['object_relation' => 'a', 'type' => 'text', 'multiple' => false],
        ]];
        $object = ['Attribute' => [
            ['object_relation' => 'a', 'type' => 'text', 'value' => 'original'],
        ]];
        $revisedObject = ['Attribute' => [
            ['object_relation' => 'a', 'type' => 'text', 'value' => 'conflicting'],
        ]];

        $result = $this->model('Object')->reviseObject($revisedObject, $object, $template);

        $this->assertEmpty($result['revised_object_both']['mergeable']);
        $this->assertCount(1, $result['revised_object_both']['notMergeable']);
        $this->assertSame('original', $result['revised_object_both']['notMergeable'][0]['current_value']);
        $this->assertTrue($result['revised_object_both']['notMergeable'][0]['merge-possible']);
    }
}
