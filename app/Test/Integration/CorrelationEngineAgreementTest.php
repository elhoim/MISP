<?php

require_once __DIR__ . '/IntegrationTestCase.php';

/**
 * SPECIFICATION: the three correlation engines - Default, NoAcl and OnDemand
 * - are documented as interchangeable strategies selected purely by
 * MISP.correlation_engine (Correlation.php:1328). If a site admin gets a
 * different related-event set out of one engine than another for the same
 * fixture data, the engines are not actually interchangeable and that is a
 * real bug, not a quirk to characterize.
 *
 * CorrelationEngineTest already compares Default against NoAcl. This suite
 * extends the same fixture and assertion to include OnDemand, which is the
 * engine that CorrelationEngineTest and the wider integration suite leave at
 * 0% coverage (317 of 317 statements uncovered before this file), because
 * nothing ever flips MISP.correlation_engine to 'OnDemand' and exercises it.
 */
class CorrelationEngineAgreementTest extends IntegrationTestCase
{
    private const ENGINES = ['Default', 'NoAcl', 'OnDemand'];

    /** @var string|null */
    private $originalEngine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalEngine = Configure::read('MISP.correlation_engine');
    }

    protected function tearDown(): void
    {
        // Each engine writes its own correlation table (default_correlations
        // / no_acl_correlations; OnDemand has none), and Event::delete only
        // purges through whichever engine is active at the time. Purge
        // explicitly through all three first so no row outlives this test in
        // a table the *original* engine's cleanup wouldn't touch.
        foreach ($this->createdEventIds as $eventId) {
            foreach (self::ENGINES as $engine) {
                try {
                    $this->freshCorrelationModel($engine)->purgeCorrelations($eventId);
                } catch (\Throwable $e) {
                    // Best effort, matching the base class's own cleanup.
                }
            }
        }
        // Restore the engine before the base class removes fixture events, so
        // the Event::delete cleanup runs against the configuration the
        // instance started with, exactly as CorrelationEngineTest does.
        Configure::write('MISP.correlation_engine', $this->originalEngine);
        // ClassRegistry caches the Correlation model together with whichever
        // behaviour the engine setting selected when it was built. Leaving it
        // cached would hand the next suite in this process a Correlation bound
        // to an engine it never asked for.
        ClassRegistry::removeObject('Correlation');
        parent::tearDown();
    }

    /**
     * A genuinely fresh Correlation model for the given engine.
     *
     * ClassRegistry::init('Correlation', true) is NOT enough on its own: the
     * second argument is CakePHP's $strict flag (throw vs. return false on a
     * missing class), not "force a new instance" - init() still returns the
     * already-registered singleton via ClassRegistry::_duplicate() if one
     * exists. Since the engine is selected once, in the constructor
     * (Correlation.php:__construct -> getCorrelationModelName), reusing the
     * cached object across engines would silently keep running the FIRST
     * engine for every iteration and make every agreement assertion below
     * pass vacuously. removeObject() first forces init() to actually
     * construct a new Correlation with the newly-written Configure key.
     */
    private function correlationModelFor(string $engine)
    {
        $correlation = $this->freshCorrelationModel($engine);
        $this->assertSame(
            $engine,
            $correlation->getCorrelationModelName(),
            'the Correlation model must actually be running the requested engine, or every '
            . 'agreement assertion in this file would pass vacuously against a single cached engine'
        );
        return $correlation;
    }

    /** Same construction, without the assertion, for use from tearDown(). */
    private function freshCorrelationModel(string $engine)
    {
        Configure::write('MISP.correlation_engine', $engine);
        ClassRegistry::removeObject('Correlation');
        return ClassRegistry::init('Correlation', true);
    }

    /**
     * Deterministic per-test IP octet instead of CorrelationEngineTest's
     * random_int: this suite runs sequentially with other integration
     * suites sharing one database, and a value tied to the test name is
     * reproducible across reruns while still being distinct between the
     * tests in this file, which is all the collision-avoidance random_int
     * was buying. crc32() keeps it inside a valid single octet (2-250).
     */
    private function octetFor(string $seed): int
    {
        return 2 + (crc32($seed) % 249);
    }

    /**
     * The three engines must agree, for a site admin, on which events
     * correlate. This is the core claim: interchangeable engines must
     * produce the same answer to the same question.
     */
    public function testAllThreeEnginesAgreeForASiteAdmin(): void
    {
        $value = '198.51.100.' . $this->octetFor(__METHOD__);

        $firstId = $this->createEvent('three-engine agreement A', [
            ['type' => 'ip-dst', 'value' => $value],
        ]);
        $secondId = $this->createEvent('three-engine agreement B', [
            ['type' => 'ip-dst', 'value' => $value],
        ]);

        $user = $this->adminUser();
        if (empty($user['Role']['perm_site_admin'])) {
            $this->markTestSkipped('user 1 is not a site admin on this instance');
        }

        $results = [];
        foreach (self::ENGINES as $engine) {
            $correlation = $this->correlationModelFor($engine);
            $correlation->generateCorrelation(false, $firstId);
            $correlation->generateCorrelation(false, $secondId);

            $related = $correlation->fetchRelatedEventIds($user, $firstId, []);
            if ($related === null) {
                $this->markTestSkipped("engine $engine does not expose fetchRelatedEventIds");
            }
            $ids = array_map('intval', (array)$related);
            sort($ids);
            $results[$engine] = $ids;
        }

        $this->assertContains(
            $secondId,
            $results['Default'],
            'sanity check: the Default engine must see the shared-value event as related at all'
        );

        $this->assertSame(
            $results['Default'],
            $results['NoAcl'],
            'for a site admin the ACL filter is a no-op, so Default and NoAcl must agree'
        );
        $this->assertSame(
            $results['Default'],
            $results['OnDemand'],
            'OnDemand computes correlations live instead of from a stored table, but for a '
            . 'site admin it must report the same related events as Default'
        );
    }

    /**
     * Same claim as above, but through runGetAttributesRelatedToEvent
     * instead of fetchRelatedEventIds - a second, independently-implemented
     * entry point per engine (Correlation.php:1142 calls
     * fetchRelatedEventIds; the attribute-level correlation view calls
     * runGetAttributesRelatedToEvent directly), so agreement on one does not
     * imply agreement on the other.
     */
    public function testAllThreeEnginesAgreeOnRelatedAttributesForASiteAdmin(): void
    {
        $value = '198.51.100.' . $this->octetFor(__METHOD__);

        $firstId = $this->createEvent('three-engine attribute agreement A', [
            ['type' => 'ip-dst', 'value' => $value],
        ]);
        $secondId = $this->createEvent('three-engine attribute agreement B', [
            ['type' => 'ip-dst', 'value' => $value],
        ]);

        $user = $this->adminUser();
        if (empty($user['Role']['perm_site_admin'])) {
            $this->markTestSkipped('user 1 is not a site admin on this instance');
        }

        $results = [];
        foreach (self::ENGINES as $engine) {
            $correlation = $this->correlationModelFor($engine);
            $correlation->generateCorrelation(false, $firstId);
            $correlation->generateCorrelation(false, $secondId);

            // NoAclCorrelationBehavior::runGetAttributesRelatedToEvent takes
            // one fewer argument than Default/OnDemand (no $sgids) - the
            // documented, deliberate divergence from
            // CorrelationEngineTest::testTheOnlyKnownSignatureDivergenceIsTheAclArgument.
            // PHP discards the extra argument for NoAcl, so a single call
            // site works for all three.
            $related = $correlation->runGetAttributesRelatedToEvent($user, $firstId, []);
            // Keyed by the parent attribute id; each value is a list of
            // correlation rows whose 'id' is the RELATED event's id (see
            // DefaultCorrelationBehavior::runGetAttributesRelatedToEvent).
            $eventIds = [];
            foreach ((array)$related as $group) {
                foreach ((array)$group as $row) {
                    if (isset($row['id'])) {
                        $eventIds[] = (int)$row['id'];
                    }
                }
            }
            $eventIds = array_values(array_unique($eventIds));
            sort($eventIds);
            $results[$engine] = $eventIds;
        }

        $this->assertContains(
            $secondId,
            $results['Default'],
            'sanity check: the Default engine must report the related attribute at the event level'
        );

        $this->assertSame(
            $results['Default'],
            $results['NoAcl'],
            'runGetAttributesRelatedToEvent must agree between Default and NoAcl for a site admin'
        );
        $this->assertSame(
            $results['Default'],
            $results['OnDemand'],
            'runGetAttributesRelatedToEvent must agree between Default and OnDemand for a site admin'
        );
    }
}
