<?php

use MispTest\Support\FakeModel;
use PHPUnit\Framework\TestCase;

require_once APP . 'Test/Support/FakeModel.php';

/**
 * A CakePHP 2 Helper needs a `View` instance in its constructor. Rather than
 * pull in the real View/Helper stack (templates, request, response, the
 * whole controller chain), this declares the minimal local stand-ins the
 * helpers under test actually touch: a View with viewVars/Image, and an
 * AppHelper that stores the View it's given (the shared FrameworkStubs
 * `Helper` stub deliberately does not, so it must be overridden here before
 * any of the real Helper/*.php files are loaded). This mirrors the stand-ins
 * GraphToolsTest.php declares for the same reason -- both are guarded by
 * class_exists so whichever of the two test files loads first wins and the
 * other is a no-op.
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

require_once APP . 'View/Helper/UserNameHelper.php';
require_once APP . 'View/Helper/OrgImgHelper.php';
require_once APP . 'View/Helper/PivotHelper.php';
require_once APP . 'View/Helper/ScopedCSSHelper.php';
require_once APP . 'View/Helper/GenericPickerHelper.php';
require_once APP . 'View/Helper/CommandHelper.php';
require_once APP . 'Controller/Component/ACLComponent.php';
require_once APP . 'View/Helper/AclHelper.php';

/**
 * Behavioural tests for the small, mostly-pure View/Helper formatters.
 *
 * These build strings/arrays from plain input; none of them render a real
 * .ctp template. Where a test asserts a documented rule (e.g. "an org
 * without a matching image file falls back to plain text"), it is a
 * SPECIFICATION. Where it merely records what today's code happens to do
 * (e.g. the exact CSS-scoping wrapper markup), it is a CHARACTERIZATION --
 * each test says which via its own comment.
 */
class SmallHelpersTest extends TestCase
{
    /** @var View */
    private static $view;

    public static function setUpBeforeClass(): void
    {
        self::$view = new View();
    }

    // ============================================================ UserNameHelper

    public function testConvertEmailToNameTitleCasesEachDotSegmentOfTheLocalPart(): void
    {
        $helper = new UserNameHelper(self::$view);

        $this->assertSame('John Doe', $helper->convertEmailToName('john.doe@example.com'));
        $this->assertSame('Admin', $helper->convertEmailToName('admin@example.com'));
        $this->assertSame('A B C', $helper->convertEmailToName('a.b.c@example.com'));
    }

    public function testPrependReturnsTheExactMatchEasterEggForASpecificAddress(): void
    {
        // SPECIFICATION: one address is matched by exact equality (not substring).
        $helper = new UserNameHelper(self::$view);

        $this->assertSame(
            '<span class="bold white">Graphman</span> ',
            $helper->prepend('sami.mokaddem@circl.lu'),
            'the graphman easter egg is an exact-match rule, not a substring rule'
        );
    }

    public function testPrependMatchesEachSubstringRuleIndependently(): void
    {
        $helper = new UserNameHelper(self::$view);

        $this->assertStringContainsString('fa-horse-head', $helper->prepend('enrico.lovat@example.com'));
        $this->assertStringContainsString('fa-smile-beam', $helper->prepend('christophe.vandeplas@example.com'));
        $this->assertStringContainsString('fa-cheese', $helper->prepend('m.j.nassette@example.com'));
        $this->assertStringContainsString('fa-bug', $helper->prepend('pinoy.jeroen@example.com'));
        // 'rand' + 'ecrime' rule requires both substrings, case-insensitively.
        $this->assertStringContainsString('fa-camera', $helper->prepend('Rand.Someone@eCrime-unit.example'));
    }

    public function testPrependReturnsEmptyStringForAnUnmatchedAddress(): void
    {
        $helper = new UserNameHelper(self::$view);

        $this->assertSame('', $helper->prepend('nobody.special@example.com'));
    }

    public function testPrependMissesTheSaadRuleWhenTheSecondSubstringStartsAtPositionZero(): void
    {
        // KNOWN-DEFECT: prepend()'s first branch is
        //   strpos($lower_email, 'saad') !== false && strpos($lower_email, 'thehive-project')
        // -- the second strpos() is missing its `!== false` comparison. strpos()
        // returns the *offset* of the match, and PHP's loose `&&` truthiness
        // treats offset 0 as false. So whenever 'thehive-project' happens to
        // start at position 0 of the (lowercased) email, this rule silently
        // fails to fire even though both substrings are genuinely present,
        // and the address falls through to the default '' return instead of
        // getting the smile icon. An address where 'thehive-project' starts
        // anywhere else in the string is unaffected.
        $helper = new UserNameHelper(self::$view);

        $email = 'thehive-project@saad-test.example'; // 'thehive-project' is at offset 0
        $this->assertSame(
            '',
            $helper->prepend($email),
            'KNOWN-DEFECT: offset-0 match of thehive-project is falsy in `&&`, so the saad/thehive icon never renders here'
        );
    }

    // ============================================================= OrgImgHelper

    public function testGetNameWithImgReturnsEmptyStringWhenOrganisationKeyIsMissing(): void
    {
        $helper = new OrgImgHelper(self::$view);

        $this->assertSame('', $helper->getNameWithImg(['NotOrganisation' => ['id' => 1]]));
    }

    public function testGetNameWithImgEmbedsABase64ImageWhenAMatchingFileExists(): void
    {
        // CIRCL.png ships in app/files/img/orgs/ in this checkout.
        $helper = new OrgImgHelper(self::$view);

        $html = $helper->getNameWithImg(['Organisation' => ['id' => 999, 'name' => 'CIRCL']]);

        $this->assertStringContainsString('<img', $html, 'a matching org image file must be embedded');
        $this->assertStringContainsString('data:image/png;base64,', $html);
        $this->assertStringContainsString('CIRCL', $html);
        $this->assertStringContainsString('/organisations/view/999', $html, 'link falls back to the org id when no explicit link is given');
    }

    public function testGetNameWithImgFallsBackToPlainLinkWhenNoImageFileMatches(): void
    {
        $helper = new OrgImgHelper(self::$view);

        $html = $helper->getNameWithImg(['Organisation' => ['id' => 12345, 'name' => 'NoSuchOrgOnDisk']]);

        $this->assertStringNotContainsString('<img', $html, 'no image file on disk means no <img> tag');
        $this->assertStringContainsString('NoSuchOrgOnDisk', $html);
        $this->assertStringContainsString('/organisations/view/12345', $html);
    }

    public function testGetOrgLogoAsBase64ReturnsNullWhenNoImageMatches(): void
    {
        $helper = new OrgImgHelper(self::$view);

        $this->assertNull($helper->getOrgLogoAsBase64(['id' => 'no-such-org-uuid', 'name' => 'Ghost Org']));
    }

    public function testGetOrgLogoAsBase64ReturnsTheEncodedImageWhenAFileMatchesById(): void
    {
        // ADMIN.png ships in app/files/img/orgs/, matched by 'id'.
        $helper = new OrgImgHelper(self::$view);

        $base64 = $helper->getOrgLogoAsBase64(['id' => 'ADMIN', 'name' => 'Administration']);

        $this->assertSame('data:image/png;base64,Zg==', $base64);
    }

    public function testGetOrgLogoWrapsTheImageInALinkByDefault(): void
    {
        $helper = new OrgImgHelper(self::$view);

        $html = $helper->getOrgLogo(['Organisation' => ['id' => 'ADMIN', 'name' => 'Administration']], 32);

        $this->assertStringContainsString('<a href=', $html, 'getOrgLogo defaults to withLink=true');
        $this->assertStringContainsString('width="32"', $html);
    }

    public function testGetOrgLogoV2ReturnsEmptyStringWhenNoImageIsAvailable(): void
    {
        // SPECIFICATION per docblock: "If there is no logo for the organisation, return nothing".
        $helper = new OrgImgHelper(self::$view);

        $this->assertSame('', $helper->getOrgLogoV2(['Organisation' => ['id' => 'no-such-org', 'name' => 'Ghost']], 32));
    }

    // =============================================================== PivotHelper

    public function testConvertPivotToHTMLMarksTheCurrentEventAsTheActivePivot(): void
    {
        $helper = new PivotHelper(self::$view);
        $pivot = [
            'id' => 5,
            'info' => 'Root event',
            'date' => '2026-01-01',
            'height' => 50,
            'deletable' => false,
            'children' => [],
        ];

        ob_start();
        $helper->convertPivotToHTML($pivot, 5);
        $html = ob_get_clean();

        $this->assertStringContainsString('activePivot', $html, 'a pivot matching the current event id is flagged active');
        $this->assertStringContainsString('/events/view/5/1/5', $html);
        $this->assertStringContainsString('5: Root event', $html);
        $this->assertStringNotContainsString('pivotDelete', $html, 'non-deletable pivots must not render the remove-pivot link');
    }

    public function testConvertPivotToHTMLRendersDeletableChildPivotsWithARemoveLink(): void
    {
        $helper = new PivotHelper(self::$view);
        $pivot = [
            'id' => 1,
            'info' => 'Root',
            'date' => '2026-01-01',
            'height' => 50,
            'deletable' => false,
            'children' => [
                [
                    'id' => 2,
                    'info' => 'Child',
                    'date' => '2026-01-02',
                    'height' => 100,
                    'deletable' => true,
                    'children' => [],
                ],
            ],
        ];

        ob_start();
        $helper->convertPivotToHTML($pivot, 1);
        $html = ob_get_clean();

        $this->assertStringContainsString('pivotDelete', $html, 'a deletable child pivot must render the remove-pivot link');
        $this->assertStringContainsString('/events/removePivot/2/1', $html);
        $this->assertStringContainsString('2: Child', $html);
    }

    // ========================================================== ScopedCSSHelper

    public function testCreateScopedCSSLeavesHtmlUntouchedWhenNoScopedStyleTagIsPresent(): void
    {
        $helper = new ScopedCSSHelper(self::$view);
        $html = '<div>plain widget html</div>';

        $result = $helper->createScopedCSS($html);

        $this->assertSame($html, $result['bundle']);
        $this->assertSame($html, $result['html']);
        $this->assertSame('', $result['css']);
        $this->assertSame('', $result['seed']);
        $this->assertSame($html, $result['originalHtml']);
    }

    public function testCreateScopedCSSPrependsADataScopedSelectorToEverySelectorLine(): void
    {
        $helper = new ScopedCSSHelper(self::$view);
        $css = ".foo {" . PHP_EOL . "color: red;" . PHP_EOL . "}" . PHP_EOL
             . ".bar," . PHP_EOL . ".baz {" . PHP_EOL . "color: blue;" . PHP_EOL . "}";
        $html = '<style widget-scoped>' . $css . '</style><div>widget body</div>';

        $result = $helper->createScopedCSS($html);

        $this->assertNotSame('', $result['seed'], 'a scoped style block must generate a seed');
        $this->assertIsNumeric($result['seed']);
        $selector = sprintf('[data-scoped="%s"]', $result['seed']);
        $this->assertStringContainsString($selector . ' .foo {', $result['css']);
        $this->assertStringContainsString($selector . ' .bar,', $result['css']);
        $this->assertStringContainsString($selector . ' .baz {', $result['css']);
        $this->assertStringContainsString('data-scoped="' . $result['seed'] . '"', $result['html'], 'the remaining html is wrapped in a div carrying the same scope id');
        $this->assertStringContainsString('widget body', $result['html']);
        $this->assertStringNotContainsString('widget-scoped', $result['html'], 'the <style widget-scoped> block itself is stripped out of the html');
        $this->assertSame($html, $result['originalHtml'], 'originalHtml must be untouched even though html/css are rewritten');
    }

    // ===================================================== GenericPickerHelper

    public function testAddSelectParamsSerializesOptionsAndDefaultsAdditionalDataToAnEmptyObject(): void
    {
        $helper = new GenericPickerHelper(self::$view);

        $html = $helper->add_select_params(['select_options' => ['id' => 'my-select', 'multiple' => 'multiple']]);

        $this->assertStringContainsString('id="my-select"', $html);
        $this->assertStringContainsString('multiple="multiple"', $html);
        $this->assertStringContainsString('data-additionaldata="[]"', $html, 'no additionalData means a json_encode([])-shaped default, not an empty attribute');
    }

    public function testAddSelectParamsEncodesAdditionalDataAndFunctionName(): void
    {
        $helper = new GenericPickerHelper(self::$view);

        $html = $helper->add_select_params([
            'select_options' => ['additionalData' => ['foo' => 'bar']],
            'functionName' => 'doThing',
        ]);

        $this->assertStringContainsString('data-functionname="doThing"', $html);
        $this->assertStringContainsString(h(json_encode(['foo' => 'bar'])), $html);
    }

    public function testAddOptionMarksTheOptionSelectedOrDisabled(): void
    {
        $helper = new GenericPickerHelper(self::$view);

        $selected = $helper->add_option(['name' => 'Option A', 'value' => 'a', 'selected' => true]);
        $disabled = $helper->add_option(['name' => 'Option B', 'value' => 'b', 'disabled' => true]);
        $plain = $helper->add_option(['name' => 'Option C']);

        $this->assertStringContainsString('selected', $selected);
        $this->assertStringContainsString('disabled', $disabled);
        $this->assertStringNotContainsString('selected', $disabled, 'a disabled option must not also be pre-selected');
        $this->assertSame('<option value="Option C">Option C</option>', $plain, 'falls back to name as value when value is not given');
    }

    public function testAddPillRendersAnIconAndFallsBackToTheEndpointSubmitPattern(): void
    {
        $helper = new GenericPickerHelper(self::$view);

        $html = $helper->add_pill(['name' => 'Threat Actor', 'value' => '/galaxies/view/1', 'icon' => 'globe'], ['functionName' => '']);

        $this->assertStringContainsString('fa-globe', $html);
        $this->assertStringContainsString('Threat Actor', $html);
        $this->assertStringContainsString('data-endpoint="/galaxies/view/1"', $html, 'with no functionName on either the param or the defaults, it falls back to the fetchRequestedData endpoint pattern');
        $this->assertStringNotContainsString('fa-th', $html, 'the matrix-picker glyph is only rendered when isMatrix is set');
    }

    public function testBuildTemplateReturnsEmptyStringWhenNoTemplateKeyIsGiven(): void
    {
        $helper = new GenericPickerHelper(self::$view);

        $this->assertSame('', $helper->build_template(['name' => 'no template here']));
    }

    // ============================================================= CommandHelper

    public function testConvertQuotesRendersQuoteAndCodeBlocksAsDivAndPreTags(): void
    {
        $helper = new CommandHelper(self::$view);
        $helper->Html = new FakeModel(['link' => function ($text, $url) {
            return sprintf('<a href="%s">%s</a>', $url, $text);
        }]);

        $this->assertSame('<div class="quote">hello</div>', $helper->convertQuotes('[quote]hello[/quote]'));
        $this->assertSame('<pre>some code</pre>', $helper->convertQuotes('[code]some code[/code]'));
    }

    public function testConvertQuotesBuildsEventAndThreadLinksFromTheNumericId(): void
    {
        // Needs a real base URL: MISP.baseurl unset means the fallback
        // (rtrim(Router::url('/', true), '/')) resolves to a bare path,
        // which filter_var(FILTER_VALIDATE_URL) correctly rejects as not a
        // URL, so this must configure a scheme+host to reach the "well
        // formed" branch at all.
        Configure::write('MISP.baseurl', 'https://misp.test');
        $helper = new CommandHelper(self::$view);
        $helper->Html = new FakeModel(['link' => function ($text, $url) {
            return sprintf('<a href="%s">%s</a>', $url, $text);
        }]);

        $this->assertSame('<a href="https://misp.test/events/view/123"> Event 123</a>', $helper->convertQuotes('[event]123[/event]'));
        $this->assertSame('<a href="https://misp.test/threads/view/42"> Thread 42</a>', $helper->convertQuotes('[thread]42[/thread]'));

        Configure::delete('MISP.baseurl');
    }

    public function testConvertQuotesFlagsANonNumericEventReferenceAsMalformed(): void
    {
        // SPECIFICATION: [event]/[thread] bodies must be numeric ids.
        $helper = new CommandHelper(self::$view);
        $helper->Html = new FakeModel(['link' => function ($text, $url) {
            return sprintf('<a href="%s">%s</a>', $url, $text);
        }]);

        $this->assertSame('%MALFORMED URL%', $helper->convertQuotes('[event]not-a-number[/event]'));
    }

    public function testConvertQuotesReturnsAFixedMessageForUnbalancedTags(): void
    {
        $helper = new CommandHelper(self::$view);
        $helper->Html = new FakeModel(['link' => function ($text, $url) {
            return sprintf('<a href="%s">%s</a>', $url, $text);
        }]);

        $this->assertSame('Malformed syntax.', $helper->convertQuotes('[code]unterminated'));
    }

    // ================================================================ AclHelper

    private function makeAclHelper(ACLComponent $acl, array $me): AclHelper
    {
        $view = new View();
        $view->viewVars['aclComponent'] = $acl;
        $view->viewVars['me'] = $me;
        return new AclHelper($view);
    }

    public function testConstructorRequiresAnACLComponentInstance(): void
    {
        $view = new View();
        $view->viewVars['aclComponent'] = new stdClass();
        $view->viewVars['me'] = ['id' => 1];

        $this->expectException(InvalidArgumentException::class);
        new AclHelper($view);
    }

    public function testConstructorRequiresANonEmptyMeVariable(): void
    {
        $collection = new ComponentCollection();
        $acl = new ACLComponent($collection);

        $view = new View();
        $view->viewVars['aclComponent'] = $acl;
        $view->viewVars['me'] = [];

        $this->expectException(InvalidArgumentException::class);
        new AclHelper($view);
    }

    public function testCanModifyEventDelegatesToTheACLComponentWithTheCurrentUser(): void
    {
        $collection = new ComponentCollection();
        $acl = new ACLComponent($collection);
        $me = [
            'Role' => ['perm_site_admin' => true, 'perm_modify' => true, 'perm_modify_org' => true],
            'org_id' => 1,
        ];
        $helper = $this->makeAclHelper($acl, $me);

        $event = ['Event' => ['locked' => false, 'orgc_id' => 1, 'user_id' => 1]];

        $this->assertTrue(
            $helper->canModifyEvent($event),
            'a site admin must be able to modify any event via the delegated ACL check'
        );
    }

    public function testCanPublishGalaxyClusterIgnoresThePublishPermissionOnTheHelper(): void
    {
        // KNOWN-DEFECT: AclHelper::canPublishGalaxyCluster() delegates to
        // ACLComponent::canModifyGalaxyCluster() instead of the dedicated
        // ACLComponent::canPublishGalaxyCluster(). The real ACL method
        // additionally requires $user['Role']['perm_publish']; the helper
        // never checks it. Consequence: a user who can modify a galaxy
        // cluster but does NOT have perm_publish is (incorrectly, per the
        // component's own rule) reported as able to publish it when asked
        // through this helper.
        $collection = new ComponentCollection();
        $acl = new ACLComponent($collection);
        $me = [
            'Role' => ['perm_site_admin' => false, 'perm_galaxy_editor' => true, 'perm_publish' => false],
            'org_id' => 7,
        ];
        $helper = $this->makeAclHelper($acl, $me);
        $cluster = ['GalaxyCluster' => ['default' => false, 'orgc_id' => 7]];

        $this->assertFalse(
            $acl->canPublishGalaxyCluster($me, $cluster),
            'sanity check: the real ACL component correctly refuses publish without perm_publish'
        );
        $this->assertTrue(
            $helper->canPublishGalaxyCluster($cluster),
            'KNOWN-DEFECT: the helper reuses canModifyGalaxyCluster and so ignores perm_publish entirely'
        );
    }
}
