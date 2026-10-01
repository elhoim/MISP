<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../Vendor/autoload.php';
require_once APP . 'Controller/Component/ACLComponent.php';

/**
 * SPECIFICATION coverage for ACLComponent, the ACL table that gates every
 * controller action (and, per the assignment brief, doubles as a
 * string-dispatch registry making many otherwise-unreferenced controller
 * actions reachable).
 *
 * ACLComponent's constructor is exempt from PHP's LSP override-compatibility
 * checks (constructors are never checked), so it loads fine under the unit
 * bootstrap's generic Component stub. RestResponseComponent is NOT similarly
 * loadable here - see the class-level KNOWN-DEFECT note below - so this file
 * covers ACLComponent only, and leaves one skipped placeholder documenting
 * why RestResponseComponent could not be reached.
 *
 * These are assertions about a rule that must hold (which permission shape
 * admits or refuses which role), not a snapshot of incidental output.
 */
class ResponseAndAclComponentTest extends TestCase
{
    protected function setUp(): void
    {
        Configure::reset();
    }

    private function makeAcl(): ACLComponent
    {
        return new ACLComponent(new ComponentCollection());
    }

    /** A role with every permission flag ACL_LIST references present and off. */
    private function baseRole(array $overrides = []): array
    {
        $perms = [
            'perm_add', 'perm_admin', 'perm_analyst_data', 'perm_audit', 'perm_auth',
            'perm_decaying', 'perm_delegate', 'perm_galaxy_editor', 'perm_modify',
            'perm_modify_org', 'perm_object_template', 'perm_publish', 'perm_publish_kafka',
            'perm_publish_zmq', 'perm_regexp_access', 'perm_server_sign', 'perm_sharing_group',
            'perm_sighting', 'perm_site_admin', 'perm_sync', 'perm_tag_editor', 'perm_tagger',
            'perm_template', 'perm_warninglist',
        ];
        $role = array_fill_keys($perms, 0);
        return array_merge($role, $overrides);
    }

    private function user(array $roleOverrides = [], array $userOverrides = []): array
    {
        return array_merge([
            'id' => 1,
            'org_id' => 1,
            'Role' => $this->baseRole($roleOverrides),
        ], $userOverrides);
    }

    // ============================================================ ACLComponent

    public function testSiteAdminBypassesEveryRuleShape(): void
    {
        $acl = $this->makeAcl();
        $admin = $this->user(['perm_site_admin' => 1]);

        // 'add' needs perm_add (single-permission rule) - admin lacks it but still passes.
        $this->assertTrue(
            $acl->canUserAccess($admin, 'attributes', 'add'),
            'a site admin must reach every action regardless of the specific permission rule'
        );
        // checkAttachments is an empty rule list => site-admin-only.
        $this->assertTrue(
            $acl->canUserAccess($admin, 'attributes', 'checkAttachments'),
            'the empty-rule (site-admin-only) shape must admit a site admin'
        );
    }

    public function testSinglePermissionRuleGatesOnExactlyThatFlag(): void
    {
        $acl = $this->makeAcl();
        $withAdd = $this->user(['perm_add' => 1]);
        $withoutAdd = $this->user();

        $this->assertTrue(
            $acl->canUserAccess($withAdd, 'attributes', 'add'),
            'attributes.add is gated on perm_add alone'
        );
        $this->assertFalse(
            $acl->canUserAccess($withoutAdd, 'attributes', 'add'),
            'a user without perm_add and without site-admin must be refused'
        );
    }

    public function testEmptyRuleListIsSiteAdminOnly(): void
    {
        $acl = $this->makeAcl();
        // checkAttachments carries an empty rule array in ACL_LIST, which
        // the docblock defines as "site admin only" - no ordinary
        // permission, however many are held, can satisfy it.
        $everyPermUser = $this->user(array_fill_keys([
            'perm_add', 'perm_admin', 'perm_analyst_data', 'perm_audit', 'perm_auth',
            'perm_decaying', 'perm_sync', 'perm_tagger',
        ], 1));

        $this->assertFalse(
            $acl->canUserAccess($everyPermUser, 'attributes', 'checkAttachments'),
            'an empty rule list must refuse every non-site-admin, no matter how many other permissions they hold'
        );
    }

    public function testOrRuleAdmitsAnyListedPermission(): void
    {
        $acl = $this->makeAcl();
        $decayingOnly = $this->user(['perm_decaying' => 1]);
        $adminOnly = $this->user(['perm_admin' => 1]);
        $neither = $this->user();

        // decayingModel.add => OR(perm_admin, perm_decaying)
        $this->assertTrue($acl->canUserAccess($decayingOnly, 'decayingModel', 'add'), 'either OR-listed permission must suffice (perm_decaying)');
        $this->assertTrue($acl->canUserAccess($adminOnly, 'decayingModel', 'add'), 'either OR-listed permission must suffice (perm_admin)');
        $this->assertFalse($acl->canUserAccess($neither, 'decayingModel', 'add'), 'neither OR-listed permission held must refuse');
    }

    public function testAndRuleRequiresEveryListedConditionIncludingADynamicCheck(): void
    {
        $acl = $this->makeAcl();
        // attributes.deleteSelection => AND(theming_enabled, perm_add), and
        // theming_enabled is a *dynamic* check reading MISP.enable_themes,
        // not a Role flag - this exercises AND mixing a config-driven check
        // with an ordinary permission.
        $permOnly = $this->user(['perm_add' => 1]);
        $this->assertFalse(
            $acl->canUserAccess($permOnly, 'attributes', 'deleteSelection'),
            'AND must fail while the dynamic condition (themes enabled) is unmet, even with the permission held'
        );

        Configure::write('MISP.enable_themes', 1);
        $this->assertTrue(
            $acl->canUserAccess($permOnly, 'attributes', 'deleteSelection'),
            'AND must pass once every listed condition, dynamic or role-based, is satisfied'
        );

        $themeOnly = $this->user();
        Configure::write('MISP.enable_themes', 1);
        $this->assertFalse(
            $acl->canUserAccess($themeOnly, 'attributes', 'deleteSelection'),
            'AND must still fail if the role permission half is missing, even with the dynamic half satisfied'
        );
    }

    public function testWildcardRuleAdmitsAnyAuthenticatedShapeOfUser(): void
    {
        $acl = $this->makeAcl();
        $noPerms = $this->user();

        $this->assertTrue(
            $acl->canUserAccess($noPerms, 'attributes', 'restSearch'),
            "the '*' rule marker must admit a user holding no permissions at all"
        );
    }

    public function testBarePermissionRuleAsADynamicCheckNameIsResolvedAsDynamicNotAsARoleFlag(): void
    {
        $acl = $this->makeAcl();
        // analystData.viewForObject => ['theming_enabled'] - a single-entry
        // rule whose entry is a *dynamic check name*, not a Role permission
        // flag. checkAccess() must look it up in $dynamicChecks rather than
        // reading $user['Role']['theming_enabled'] (which would not exist).
        $user = $this->user();

        $this->assertFalse(
            $acl->canUserAccess($user, 'analystData', 'viewForObject'),
            'with themes disabled, the single-entry dynamic-check rule must refuse access'
        );

        Configure::write('MISP.enable_themes', 1);
        $this->assertTrue(
            $acl->canUserAccess($user, 'analystData', 'viewForObject'),
            'once the dynamic check condition holds, the single-entry dynamic-check rule must admit access'
        );
    }

    public function testUnknownControllerIsARuntimeExceptionNotAFalse(): void
    {
        $acl = $this->makeAcl();
        $user = $this->user(['perm_site_admin' => 1]);

        // canUserAccess() deliberately converts the internal NotFoundException
        // into a RuntimeException rather than returning false, so callers
        // cannot mistake "no such controller" for "access denied".
        $this->expectException(RuntimeException::class);
        $acl->canUserAccess($user, 'thisControllerDoesNotExistInTheAclTable', 'add');
    }

    // ======================================================== RestResponseComponent

    /**
     * KNOWN-DEFECT (test infrastructure, not production code): RestResponseComponent
     * (and every other Controller/Component that overrides initialize()) declares
     * `public function initialize(Controller $controller)`, matching the real
     * CakePHP Component base exactly. app/Test/Support/FrameworkStubs.php's unit
     * stub, however, declares the base as `initialize($c = null)` - untyped and
     * optional. PHP's override-compatibility check treats a required, typed
     * parameter overriding an optional, untyped one as incompatible on both
     * counts, which raises an uncatchable "Fatal error: Declaration ... must be
     * compatible" at class-declaration time, i.e. merely `require`-ing
     * RestResponseComponent.php aborts the whole PHPUnit process (PHPUnit loads
     * every *Test.php file under Test/ for discovery regardless of --filter, so
     * this would break every other unit test in the suite, not just this file).
     * The mechanism and fix are one line: FrameworkStubs.php's Component stub
     * should declare `initialize(Controller $controller)` like the real class
     * does, matching what BlockListComponent, AdminCrudComponent, CRUDComponent,
     * DeprecationComponent, IndexFilterComponent and CompressedRequestHandlerComponent
     * all already assume. That file is shared test infrastructure other agents
     * are concurrently relying on, so it is deliberately NOT touched here.
     *
     * The RestResponseComponent::describe()/getAllApis()/getApiInfo() test design
     * (a generic ClassRegistry model double answering every property/method
     * __setup()'s full-table walk touches; a request double whose is('ajax')
     * returns true so isAutomaticTool() short-circuits before the unstubbed
     * CakeRequest::header() static call; getAllApis()/getApiInfo() asserted as
     * plain arrays since only describe() routes through the JSON-encoding
     * prepareResponse() path) is worked out and ready to drop in once the stub
     * is fixed.
     */
    public function testRestResponseComponentCannotBeLoadedUnderTheCurrentComponentStub(): void
    {
        $this->markTestSkipped(
            'RestResponseComponent.php cannot be require\'d under the unit bootstrap: its '
            . 'initialize(Controller $controller) override is incompatible with the '
            . 'FrameworkStubs Component stub\'s initialize($c = null), which PHP treats as an '
            . 'uncatchable fatal at class-declaration time. See the KNOWN-DEFECT docblock above.'
        );
    }
}
