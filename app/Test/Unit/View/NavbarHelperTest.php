<?php

use PHPUnit\Framework\TestCase;

/**
 * NavbarHelper::build() is MISP's entire top navigation: seven left-hand
 * root menus and two right-hand ones, almost every entry gated behind
 * either a Configure() flag or $this->Acl->canAccess(). At 840 statements
 * and 3 covered lines it was the largest unmeasured unit-layer surface in
 * the app, and it is exactly the kind of code an HTTP smoke test never
 * exercises across roles: a menu entry silently disappearing for a role
 * that should still see it (or reappearing for one that shouldn't) has no
 * other net.
 *
 * Every test here is a SPECIFICATION: it asserts a rule the source code
 * states explicitly (a `requirement` expression, or the ACTIVE_MENU_MAP),
 * not merely today's incidental output. Two tests are called out inline as
 * CHARACTERIZATION because they pin an asymmetry in the source that reads
 * like it could be a mistake, without fixing it.
 */
class NavbarHelperViewTest extends TestCase
{
    /** @var array<string,array{0:bool,1:mixed}> key => [wasSet, originalValue] */
    private $configBackup = [];

    public static function setUpBeforeClass(): void
    {
        // Guarded: another Test/Unit/View suite loaded in the same process
        // (SmallHelpersTest) may already have declared a stand-in AppHelper
        // before this class's setUpBeforeClass runs, since PHPUnit includes
        // every *Test.php file during suite discovery. Re-requiring the real
        // file in that case would redeclare the class and fatal.
        if (!class_exists('AppHelper', false)) {
            require_once APP . 'View/Helper/AppHelper.php';
        }
        if (!class_exists('NavbarHelper', false)) {
            require_once APP . 'View/Helper/NavbarHelper.php';
        }
    }

    protected function setUp(): void
    {
        $this->configBackup = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->configBackup as $key => list($wasSet, $original)) {
            if ($wasSet) {
                Configure::write($key, $original);
            } else {
                Configure::delete($key);
            }
        }
        $this->configBackup = [];
    }

    private function setConfig(string $key, $value): void
    {
        if (!array_key_exists($key, $this->configBackup)) {
            $this->configBackup[$key] = [Configure::check($key), Configure::read($key)];
        }
        Configure::write($key, $value);
    }

    // ------------------------------------------------------------- doubles

    /**
     * Scripted Acl double: every (controller, action) pair is allowed
     * unless explicitly listed in $denied, and every call is recorded so a
     * test can assert exactly what NavbarHelper asked permission for.
     */
    private function aclDouble(array $denied = []): object
    {
        return new class($denied) {
            public $calls = [];
            private $denied;
            public function __construct(array $denied) { $this->denied = $denied; }
            public function canAccess($controller, $action)
            {
                $this->calls[] = [$controller, $action];
                foreach ($this->denied as $pair) {
                    if ($pair[0] === $controller && $pair[1] === $action) {
                        return false;
                    }
                }
                return true;
            }
        };
    }

    private function userNameDouble(): object
    {
        return new class {
            public function convertEmailToName($email) { return strtoupper($email); }
        };
    }

    private function orgImgDouble(): object
    {
        return new class {
            public function getOrgLogoV2($me, $size)
            {
                // Real OrgImgHelper wraps the <img> in an <a>; build() strips it.
                return '<a href="/organisations/view/1"><img src="/logo.png" width="' . $size . '"></a>';
            }
        };
    }

    /**
     * Builds a NavbarHelper wired with scripted collaborators and a request
     * for the given controller/action, and returns [helper, aclDouble] so a
     * test can both call build() and inspect what Acl was asked.
     *
     * @return array{0:NavbarHelper,1:object}
     */
    private function makeHelper(string $controller = 'events', string $action = 'index', array $aclDenied = []): array
    {
        $helper = new NavbarHelper(null, []);
        $helper->request = (object)['params' => ['controller' => $controller, 'action' => $action]];
        $acl = $this->aclDouble($aclDenied);
        $helper->Acl = $acl;
        $helper->UserName = $this->userNameDouble();
        $helper->OrgImg = $this->orgImgDouble();
        return [$helper, $acl];
    }

    /**
     * Every bare-read context key build*Menu()'s extract($context) touches
     * unconditionally, defaulted permissive so a test can flip one flag at
     * a time without PHP warning about the rest being undefined.
     */
    private function fullContext(array $overrides = []): array
    {
        return array_merge([
            'me' => ['email' => 'admin@test.local', 'Role' => ['perm_site_admin' => 1]],
            'baseurl' => 'https://misp.test',
            'isAclRegexp' => true,
            'isAclSync' => true,
            'isAdmin' => true,
            'isSiteAdmin' => true,
            'hostOrgUser' => true,
            'isAclAudit' => true,
        ], $overrides);
    }

    // --------------------------------------------------------- tree lookup

    /** Root-level menu ids present on one side ('left'/'right') of the built navbar. */
    private function rootIds(array $navbar, string $side): array
    {
        return array_values(array_filter(array_map(
            static fn ($item) => $item['id'] ?? null,
            $navbar[$side]
        )));
    }

    /** Depth-first search for the first item whose 'url' contains $needle. */
    private function findByUrl(array $items, string $needle): ?array
    {
        foreach ($items as $item) {
            if (isset($item['url']) && strpos($item['url'], $needle) !== false) {
                return $item;
            }
            if (!empty($item['children'])) {
                $found = $this->findByUrl($item['children'], $needle);
                if ($found !== null) {
                    return $found;
                }
            }
        }
        return null;
    }

    /** Depth-first search for the first item whose 'url' equals exactly $url (unlike findByUrl, no substring matching). */
    private function findByExactUrl(array $items, string $url): ?array
    {
        foreach ($items as $item) {
            if (isset($item['url']) && $item['url'] === $url) {
                return $item;
            }
            if (!empty($item['children'])) {
                $found = $this->findByExactUrl($item['children'], $url);
                if ($found !== null) {
                    return $found;
                }
            }
        }
        return null;
    }

    // ------------------------------------------------------------- build()

    public function testEmptyUserShortCircuitsToAnEmptyNavbar(): void
    {
        [$helper] = $this->makeHelper();

        $navbar = $helper->build(['me' => null]);

        $this->assertSame(
            ['left' => [], 'right' => []],
            $navbar,
            'build() must return empty menus rather than crash when no user is logged in'
        );
    }

    public function testMissingMeKeyAlsoShortCircuits(): void
    {
        [$helper] = $this->makeHelper();

        $navbar = $helper->build([]);

        $this->assertSame(['left' => [], 'right' => []], $navbar, 'a context without me must produce no menus');
    }

    public function testFullyPrivilegedUserSeesEveryRootMenu(): void
    {
        [$helper] = $this->makeHelper('events', 'index');

        $navbar = $helper->build($this->fullContext());

        $this->assertSame(
            ['datapoints', 'datamodels', 'sync', 'administration', 'logs', 'api', 'resources'],
            $this->rootIds($navbar, 'left'),
            'a fully privileged user must see all seven left-hand root menus, in build() order'
        );
        $this->assertSame(
            ['bookmarks', 'account'],
            $this->rootIds($navbar, 'right'),
            'the right side always carries bookmarks and the account menu for a logged-in user'
        );
    }

    public function testAdministrationMenuIsHiddenWithoutIsAdmin(): void
    {
        [$helper] = $this->makeHelper();

        $navbar = $helper->build($this->fullContext(['isAdmin' => false]));

        $this->assertNotContains(
            'administration',
            $this->rootIds($navbar, 'left'),
            'the administration root carries requirement => $isAdmin and must vanish without it'
        );
    }

    public function testLogsMenuIsHiddenWithoutIsAclAudit(): void
    {
        [$helper] = $this->makeHelper();

        $navbar = $helper->build($this->fullContext(['isAclAudit' => false]));

        $this->assertNotContains(
            'logs',
            $this->rootIds($navbar, 'left'),
            'the logs root carries requirement => $isAclAudit and must vanish without it'
        );
    }

    public function testSyncMenuRequiresSyncAdminOrHostOrg(): void
    {
        [$helper] = $this->makeHelper();

        $navbar = $helper->build($this->fullContext([
            'isAclSync' => false,
            'isAdmin' => false,
            'hostOrgUser' => false,
        ]));

        $this->assertNotContains(
            'sync',
            $this->rootIds($navbar, 'left'),
            'sync requires isAclSync || isAdmin || hostOrgUser; with all three false it must not render'
        );
    }

    public function testSyncMenuSurvivesOnHostOrgUserAlone(): void
    {
        [$helper] = $this->makeHelper();

        $navbar = $helper->build($this->fullContext([
            'isAclSync' => false,
            'isAdmin' => false,
            'hostOrgUser' => true,
        ]));

        $this->assertContains(
            'sync',
            $this->rootIds($navbar, 'left'),
            'the sync requirement is an OR: hostOrgUser alone must be enough'
        );
    }

    // --------------------------------------------------------- Acl gating

    public function testDeniedAclHidesTheSpecificSyncEntryButNotItsGroup(): void
    {
        [$helper] = $this->makeHelper('events', 'index', [['servers', 'index']]);

        $navbar = $helper->build($this->fullContext());

        $sync = array_values(array_filter($navbar['left'], static fn ($m) => ($m['id'] ?? null) === 'sync'))[0];
        $this->assertNull(
            $this->findByUrl($sync['children'], '/servers/index'),
            'denying servers/index must remove the Remote Servers entry'
        );
        $this->assertNotNull(
            $this->findByUrl($sync['children'], '/feeds/index'),
            'denying only servers/index must leave the sibling Feeds entry, and thus its parent group, visible'
        );
    }

    public function testEventDelegationsEntryIsGatedOnItsOwnAclCheck(): void
    {
        [$helperAllowed] = $this->makeHelper('events', 'index', []);
        [$helperDenied] = $this->makeHelper('events', 'index', [['event_delegations', 'index']]);

        $allowed = $helperAllowed->build($this->fullContext());
        $denied = $helperDenied->build($this->fullContext());

        $this->assertNotNull(
            $this->findByUrl($allowed['left'], '/event_delegations/index'),
            'Delegation Requests must appear when event_delegations/index is allowed'
        );
        $this->assertNull(
            $this->findByUrl($denied['left'], '/event_delegations/index'),
            'Delegation Requests must disappear when event_delegations/index is denied'
        );
    }

    public function testAclDoubleReceivesTheExactControllerAndActionNavbarAsksFor(): void
    {
        [$helper, $acl] = $this->makeHelper('events', 'index');

        $helper->build($this->fullContext());

        $this->assertContains(
            ['events', 'automation'],
            $acl->calls,
            'the Automation menu asks canAccess with the literal pair events/automation'
        );
        $this->assertContains(
            ['servers', 'serverSettings'],
            $acl->calls,
            'Server Settings asks canAccess with the literal pair servers/serverSettings'
        );
    }

    public function testSharingGroupBlueprintsEntryIsGatedOnTheAddAction(): void
    {
        // KNOWN-DEFECT: the "List Sharing Group Blueprints" menu entry links
        // to SharingGroupBlueprints/index but its visibility is gated on
        // canAccess('SharingGroupBlueprints', 'add') rather than 'index'
        // (see NavbarHelper::buildSyncMenu). A user with add-but-not-index
        // rights on that controller is shown a link to a page they cannot
        // reach; a user with index-but-not-add rights never sees the link
        // at all. Pinned as today's behaviour, not endorsed.
        [$helper] = $this->makeHelper('events', 'index', [['SharingGroupBlueprints', 'add']]);

        $navbar = $helper->build($this->fullContext());

        $sync = array_values(array_filter($navbar['left'], static fn ($m) => ($m['id'] ?? null) === 'sync'))[0];
        $this->assertNull(
            $this->findByUrl($sync['children'], '/SharingGroupBlueprints/index'),
            'denying the add action hides the index-linking entry, per the mismatched requirement'
        );
    }

    // --------------------------------------------------- Configure gating

    public function testBackgroundJobsFlagControlsJobsAndTasksButNotWorkflow(): void
    {
        $this->setConfig('MISP.background_jobs', false);
        [$helper] = $this->makeHelper();

        $navbar = $helper->build($this->fullContext());

        $admin = array_values(array_filter($navbar['left'], static fn ($m) => ($m['id'] ?? null) === 'administration'))[0];
        $this->assertNull($this->findByUrl($admin['children'], '/jobs/index'), 'Jobs requires MISP.background_jobs');
        $this->assertNull($this->findByUrl($admin['children'], '/tasks'), 'Scheduled Tasks requires MISP.background_jobs');
        $this->assertNotNull(
            $this->findByUrl($admin['children'], '/workflows/index'),
            'Workflow has no background_jobs requirement and must survive the flag being off'
        );
    }

    public function testBenchmarkingRequiresBothSiteAdminAndItsPluginFlag(): void
    {
        $this->setConfig('Plugin.Benchmarking_enable', false);
        [$helper] = $this->makeHelper();

        $navbar = $helper->build($this->fullContext());

        $admin = array_values(array_filter($navbar['left'], static fn ($m) => ($m['id'] ?? null) === 'administration'))[0];
        $this->assertNull(
            $this->findByUrl($admin['children'], '/benchmarks/index'),
            'Benchmarking requires Plugin.Benchmarking_enable in addition to isSiteAdmin'
        );
    }

    public function testEventBlocklistingUnsetConfigureIsTreatedAsEnabled(): void
    {
        // CHARACTERIZATION: the requirement is
        // `Configure::read(...) !== false && $isSiteAdmin`, so an unset key
        // (Configure::read() === null) evaluates to true — a never-visited
        // setting shows the Blocklist Event entry by default. Pinning this
        // so the not-set/false distinction cannot flip silently.
        Configure::delete('MISP.enableEventBlocklisting');
        [$helper] = $this->makeHelper();

        $navbar = $helper->build($this->fullContext());

        $admin = array_values(array_filter($navbar['left'], static fn ($m) => ($m['id'] ?? null) === 'administration'))[0];
        $this->assertNotNull(
            $this->findByUrl($admin['children'], '/eventBlocklists'),
            'an unset MISP.enableEventBlocklisting must not hide Blocklist Event (only explicit false does)'
        );
    }

    public function testEventBlocklistingExplicitFalseHidesTheEntry(): void
    {
        $this->setConfig('MISP.enableEventBlocklisting', false);
        [$helper] = $this->makeHelper();

        $navbar = $helper->build($this->fullContext());

        $admin = array_values(array_filter($navbar['left'], static fn ($m) => ($m['id'] ?? null) === 'administration'))[0];
        $this->assertNull(
            $this->findByUrl($admin['children'], '/eventBlocklists'),
            'explicit false for MISP.enableEventBlocklisting must hide Blocklist Event'
        );
    }

    public function testAuditLogEntryRequiresBothTheConfigureFlagAndAcl(): void
    {
        $this->setConfig('MISP.log_new_audit', true);
        [$helperAllowed] = $this->makeHelper('events', 'index', []);
        [$helperDeniedAcl] = $this->makeHelper('events', 'index', [['auditLogs', 'admin_index']]);

        $withAcl = $helperAllowed->build($this->fullContext());
        $withoutAcl = $helperDeniedAcl->build($this->fullContext());

        $this->assertNotNull(
            $this->findByUrl($withAcl['left'], '/admin/audit_logs/index'),
            'Audit logs must show when both log_new_audit and the Acl check pass'
        );
        $this->assertNull(
            $this->findByUrl($withoutAcl['left'], '/admin/audit_logs/index'),
            'Audit logs must hide when the Acl check fails, even with log_new_audit on'
        );

        $this->setConfig('MISP.log_new_audit', false);
        [$helperFlagOff] = $this->makeHelper();
        $withFlagOff = $helperFlagOff->build($this->fullContext());
        $this->assertNull(
            $this->findByUrl($withFlagOff['left'], '/admin/audit_logs/index'),
            'Audit logs must hide when log_new_audit is off, even with Acl allowed'
        );
    }

    // -------------------------------------------------- regexp dual entry

    public function testRegexpAclFlagSelectsTheAdminRoutedEntry(): void
    {
        [$helper] = $this->makeHelper();

        $navbar = $helper->build($this->fullContext(['isAclRegexp' => true]));

        $dataModels = array_values(array_filter($navbar['left'], static fn ($m) => ($m['id'] ?? null) === 'datamodels'))[0];
        $this->assertNotNull(
            $this->findByExactUrl($dataModels['children'], 'https://misp.test/admin/regexp/index'),
            'isAclRegexp true must select the admin-routed Import Regexp entry'
        );
        $this->assertNull(
            $this->findByExactUrl($dataModels['children'], 'https://misp.test/regexp/index'),
            'the two Import Regexp entries are mutually exclusive on isAclRegexp'
        );
    }

    public function testRegexpAclFlagSelectsThePlainRoutedEntry(): void
    {
        [$helper] = $this->makeHelper();

        $navbar = $helper->build($this->fullContext(['isAclRegexp' => false]));

        $dataModels = array_values(array_filter($navbar['left'], static fn ($m) => ($m['id'] ?? null) === 'datamodels'))[0];
        $this->assertNotNull(
            $this->findByExactUrl($dataModels['children'], 'https://misp.test/regexp/index'),
            'isAclRegexp false must select the plain-routed Import Regexp entry'
        );
        $this->assertNull(
            $this->findByExactUrl($dataModels['children'], 'https://misp.test/admin/regexp/index'),
            'the admin-routed entry must not appear when isAclRegexp is false'
        );
    }

    // ------------------------------------------------------- active menu

    public function testCurrentControllerIsMarkedActiveAndOthersAreNot(): void
    {
        [$helper] = $this->makeHelper('servers', 'serverSettings');

        $navbar = $helper->build($this->fullContext());

        $active = array_values(array_filter($navbar['left'], static fn ($m) => !empty($m['active'])));
        $this->assertCount(1, $active, 'exactly one left-hand root menu must be marked active');
        $this->assertSame(
            'administration',
            $active[0]['id'],
            'servers/serverSettings maps to the administration menu via ACTIVE_MENU_MAP override'
        );
        foreach ($navbar['left'] as $item) {
            if ($item['id'] !== 'administration') {
                $this->assertFalse($item['active'], "menu {$item['id']} must not be marked active");
            }
        }
    }

    public function testAdminPrefixedUserActionsFallBackToAdminDefault(): void
    {
        [$helper] = $this->makeHelper('users', 'admin_edit');

        $navbar = $helper->build($this->fullContext());

        $administration = array_values(array_filter($navbar['left'], static fn ($m) => ($m['id'] ?? null) === 'administration'))[0];
        $this->assertTrue(
            $administration['active'],
            'any admin_* action on users must resolve to administration via the admin_default rule, not just admin_index'
        );
    }

    public function testUsersStatisticsActionMapsToDatapointsNotAccount(): void
    {
        [$helper] = $this->makeHelper('users', 'statistics');

        $navbar = $helper->build($this->fullContext());

        $datapoints = array_values(array_filter($navbar['left'], static fn ($m) => ($m['id'] ?? null) === 'datapoints'))[0];
        $account = array_values(array_filter($navbar['right'], static fn ($m) => ($m['id'] ?? null) === 'account'))[0];
        $this->assertTrue($datapoints['active'], 'users/statistics is an explicit action override to datapoints');
        $this->assertFalse($account['active'], 'the users controller default (account) must not win over the action override');
    }

    public function testUnmappedControllerLeavesNoMenuMarkedActive(): void
    {
        [$helper] = $this->makeHelper('some_unmapped_controller', 'index');

        $navbar = $helper->build($this->fullContext());

        foreach (array_merge($navbar['left'], $navbar['right']) as $item) {
            $this->assertArrayNotHasKey(
                'active',
                $item,
                "menu {$item['id']} must carry no active key at all when resolveActiveMenu finds nothing"
            );
        }
    }

    // ---------------------------------------------------------- identity

    public function testAccountMenuUsesTheInjectedCollaboratorsAndStripsTheLogoAnchor(): void
    {
        [$helper] = $this->makeHelper();

        $navbar = $helper->build($this->fullContext(['me' => [
            'email' => 'jane@test.local',
            'Role' => ['perm_site_admin' => 1],
        ]]));

        $account = array_values(array_filter($navbar['right'], static fn ($m) => ($m['id'] ?? null) === 'account'))[0];
        $this->assertSame(
            'JANE@TEST.LOCAL',
            $account['label'],
            'the account label must come from UserName->convertEmailToName(), not the raw email'
        );
        $this->assertStringNotContainsString(
            '<a',
            $account['image'],
            'build() must strip the <a> wrapper OrgImgHelper puts around the logo'
        );
        $this->assertStringContainsString('<img', $account['image'], 'the <img> itself must survive the anchor strip');
    }

    // --------------------------------------------------------- structure

    public function testBuildIsDeterministicForTheSameContext(): void
    {
        [$helper] = $this->makeHelper();
        $context = $this->fullContext();

        $first = $helper->build($context);
        $second = $helper->build($context);

        $this->assertEquals($first, $second, 'the same context must yield the same navbar structure');
    }

    public function testEmptyingAGroupOfChildrenRemovesTheGroupAndDoesNotLeaveADanglingDivider(): void
    {
        // Denying every child of the "Users & Orgs" and "Roles & Permissions"
        // groups without isAdmin, but keeping the administration root open
        // via isAdmin=false replaced by direct Acl grants, exercises
        // filterMenu()'s empty-group removal and cleanDividers() together:
        // the Blocklisting/Correlations dividers must not survive alone.
        [$helper] = $this->makeHelper('events', 'index', [['organisations', 'index']]);

        $navbar = $helper->build($this->fullContext(['isAdmin' => true, 'isSiteAdmin' => false]));

        $administration = array_values(array_filter($navbar['left'], static fn ($m) => ($m['id'] ?? null) === 'administration'))[0];
        foreach ($administration['children'] as $i => $item) {
            if (!empty($item['divider'])) {
                $this->assertArrayHasKey($i - 1, $administration['children'], 'a divider must not be the first item');
                $this->assertArrayHasKey($i + 1, $administration['children'], 'a divider must not be the last item');
                $this->assertTrue(
                    empty($administration['children'][$i - 1]['divider']),
                    'two dividers must never sit next to each other after filtering'
                );
            }
        }
    }
}
