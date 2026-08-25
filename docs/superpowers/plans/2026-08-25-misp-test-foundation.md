# MISP Test Foundation (P0) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make MISP's test coverage measurable, tracked in CI, and give both test suites a shared foundation so per-file framework stubs stop being copy-pasted.

**Architecture:** Three changes that unblock everything else. (1) Upgrade PHPUnit so `--coverage-*` works at all on PHP 8. (2) Add a shared `app/Test/bootstrap.php` providing the framework stubs that today are duplicated inside each test file. (3) Ship the pcov `auto_prepend_file` instrumentation that attributes coverage to the live Python suite, plus merge/report tooling and a CI job that publishes unit / live / union numbers with a ratchet.

**Tech Stack:** PHP 8.1–8.3, PHPUnit 9.6, pcov, CakePHP 2 (vendored), Python 3 (merge/report tooling), GitHub Actions, podman/docker for local reproduction.

**Spec:** `docs/superpowers/specs/2026-08-25-misp-test-suites-design.md`

## Global Constraints

- PHP requirement stays `>=8.1.0,<9.0.0` — do not widen or narrow it.
- `phpunit/phpunit` must be `^9.6` (php-code-coverage 7, shipped with PHPUnit ^8, aborts with `This version of PHPUnit does not support code coverage on PHP 8`).
- Coverage filter MUST exclude: `app/Lib/cakephp`, `app/Vendor`, `app/Plugin/DebugKit`, `app/Plugin/CakeResque`. The last two are vendored third-party *test* code.
- The existing 477 tests must still pass after every task. That number is the regression gate.
- Layer boundary (enforced by which testsuite a file lives in): **Unit** = no DB, no Redis, no HTTP, no network. **Integration** = DB/Redis allowed, no HTTP. **Live** = full stack.
- Baseline to beat, measured on commit `ff132f4d` with the P0 coverage filter applied:
  unit 1.77 %, live 18.46 %, union 19.67 % of 117,925 statement lines. (An unfiltered
  measurement gives 122,618 statements / 18.95 % union, because it counts the DebugKit and
  CakeResque plugins' own test code.)
- This targets the `elhoim/MISP` fork; upstream-merge compatibility is explicitly NOT a constraint, but keep Task 1 cherry-pickable.

---

## File Structure

| File | Responsibility |
|---|---|
| `app/composer.json` (modify) | PHPUnit version floor |
| `app/phpunit.xml` (create) | Testsuite definitions + coverage filter — single source of truth for what counts |
| `app/Test/Support/FrameworkStubs.php` (create) | The framework stub classes, one place only |
| `app/Test/bootstrap.php` (create) | Loads composer autoload + stubs + real-parent loader |
| `app/Test/CryptGpgExtendedTest.php` (modify) | Skip instead of error when GPG is unconfigured |
| `build/coverage/covcollect.php` (create) | pcov per-request instrumentation for the live suite |
| `build/coverage/merge_coverage.py` (create) | Merge pcov captures into one file→lines map |
| `build/coverage/report.py` (create) | Intersect with clover map; emit unit/live/union + per-subsystem |
| `.github/workflows/coverage.yml` (create) | Run both suites, publish numbers, ratchet |
| `docs/testing.md` (create) | How to run each suite; the environment gotchas that cost real debugging time |

---

### Task 1: Make coverage possible (PHPUnit 9.6 + phpunit.xml)

**Files:**
- Modify: `app/composer.json` (the `require-dev` block)
- Create: `app/phpunit.xml`

**Interfaces:**
- Consumes: nothing.
- Produces: `app/phpunit.xml` with testsuite names `unit` and `integration`, and a `<coverage>` block. Later tasks and plans run `./app/Vendor/bin/phpunit -c app/phpunit.xml --testsuite unit`.

- [ ] **Step 1: Prove the defect exists**

Run, on a checkout with dependencies installed:

```bash
./app/Vendor/bin/phpunit app/Test/ --coverage-text
```

Expected: the run prints `Error: This version of PHPUnit does not support code coverage on PHP 8` and produces no coverage report. This is the failing state Task 1 fixes.

- [ ] **Step 2: Raise the PHPUnit floor**

In `app/composer.json`, inside `require-dev`, change:

```json
        "phpunit/phpunit": "^8",
```

to:

```json
        "phpunit/phpunit": "^9.6",
```

- [ ] **Step 3: Install and confirm the new version**

```bash
cd app && composer update phpunit/phpunit --with-dependencies --no-interaction && cd ..
./app/Vendor/bin/phpunit --version
```

Expected: `PHPUnit 9.6.x`.

- [ ] **Step 4: Create the coverage configuration**

Create `app/phpunit.xml`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="Test/bootstrap.php"
         colors="true"
         convertDeprecationsToExceptions="false"
         cacheResultFile="tmp/.phpunit.result.cache">
  <testsuites>
    <testsuite name="unit">
      <directory suffix="Test.php">Test/Unit</directory>
      <directory suffix="Test.php">Test</directory>
      <exclude>Test/Integration</exclude>
    </testsuite>
    <testsuite name="integration">
      <directory suffix="Test.php">Test/Integration</directory>
    </testsuite>
  </testsuites>
  <coverage processUncoveredFiles="false">
    <include>
      <directory suffix=".php">Model</directory>
      <directory suffix=".php">Controller</directory>
      <directory suffix=".php">Lib</directory>
      <directory suffix=".php">Console</directory>
      <directory suffix=".php">Plugin</directory>
      <directory suffix=".php">View</directory>
    </include>
    <exclude>
      <directory>Lib/cakephp</directory>
      <directory>Vendor</directory>
      <directory>Plugin/DebugKit</directory>
      <directory>Plugin/CakeResque</directory>
    </exclude>
  </coverage>
</phpunit>
```

Note: `bootstrap` points at `Test/bootstrap.php`, created in Task 2. Until then, create a one-line placeholder so this task is independently runnable:

```bash
mkdir -p app/Test/Unit app/Test/Integration
printf '<?php\nrequire_once __DIR__ . "/../Vendor/autoload.php";\n' > app/Test/bootstrap.php
```

- [ ] **Step 5: Verify coverage now works and tests still pass**

```bash
cd app && ../app/Vendor/bin/phpunit -c phpunit.xml --coverage-clover ../clover.xml; cd ..
```

Expected: `Tests: 477` with no errors, and `Generating code coverage report in Clover XML format ... done`. Confirm the report exists and contains all 590 files:

```bash
python3 -c "import xml.etree.ElementTree as ET; r=ET.parse('clover.xml').getroot(); print(len([f for f in r.iter('file')]), 'files')"
```

Expected: `528 files` — that is 590 source files minus the 62 vendored plugin files
(DebugKit 50, CakeResque 12) that the `<exclude>` block removes. If you see 590, the
exclude is not being applied.

- [ ] **Step 6: Clear the deprecation warnings the upgrade surfaces**

PHPUnit 9 deprecates `assertRegExp()`. Five call sites use it; the upgrade is what surfaces
them, so they are fixed in this commit rather than left as noise:

```bash
sed -i 's/\$this->assertRegExp(/$this->assertMatchesRegularExpression(/g' \
    app/Test/EventTemplateInfoRendererTest.php app/Test/WidgetCacheTest.php
```

Verify no call sites remain and the suite is clean:

```bash
grep -rn 'assertRegExp(' app/Test/ || echo "none remaining"
./app/Vendor/bin/phpunit -c app/phpunit.xml | tail -3
```

Expected: `none remaining`, then `Tests: 477, Assertions: 1087, Skipped: 2` with **no**
warnings line.

- [ ] **Step 7: Commit**

```bash
git add app/composer.json app/composer.lock app/phpunit.xml app/Test/bootstrap.php \
        app/Test/EventTemplateInfoRendererTest.php app/Test/WidgetCacheTest.php
git commit -S -m "test: upgrade PHPUnit to 9.6 and add phpunit.xml so coverage runs on PHP 8

php-code-coverage 7 (bundled with PHPUnit ^8) refuses to run on PHP 8,
while composer.json requires PHP >=8.1 - so --coverage-* was impossible.
Adds a coverage filter excluding vendored CakePHP, Composer packages and
the DebugKit/CakeResque plugins' own test code."
```

---

### Task 2: Shared framework stubs and bootstrap

**Files:**
- Create: `app/Test/Support/FrameworkStubs.php`
- Modify: `app/Test/bootstrap.php` (replace the Task 1 placeholder)
- Test: `app/Test/Unit/BootstrapLoadabilityTest.php`

**Interfaces:**
- Consumes: `app/phpunit.xml` from Task 1.
- Produces:
  - `MispTest\Support\FrameworkStubs::install(): void` — declares stub classes if absent; idempotent.
  - `MispTest\Support\FrameworkStubs::loadRealParents(string $file): void` — requires the real CakePHP/in-repo parent classes a given source file needs before it is included.
  - Constants `APP`, `DS`, `ROOT`, `WWW_ROOT`, `TMP` defined by the bootstrap.

- [ ] **Step 1: Write the failing test**

This test encodes the measured claim that the stub set makes the codebase loadable. Create `app/Test/Unit/BootstrapLoadabilityTest.php`:

```php
<?php

use PHPUnit\Framework\TestCase;

/**
 * The bootstrap's contract: every dependency-light source file must be
 * loadable standalone. Measured baseline was 261/279 candidate files;
 * with loadRealParents() the target is all of them.
 */
class BootstrapLoadabilityTest extends TestCase
{
    public function fileProvider(): array
    {
        $dirs = [
            APP . 'Lib/Tools', APP . 'Lib/Export', APP . 'Lib/Dashboard',
            APP . 'Model/WorkflowModules', APP . 'View/Helper',
        ];
        $cases = [];
        foreach ($dirs as $dir) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
            foreach ($it as $f) {
                if ($f->isFile() && $f->getExtension() === 'php') {
                    $cases[str_replace(APP, '', $f->getPathname())] = [$f->getPathname()];
                }
            }
        }
        return $cases;
    }

    /** @dataProvider fileProvider */
    public function testFileLoadsStandalone(string $path): void
    {
        $cmd = sprintf(
            '%s -d error_reporting=0 %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(APP . 'Test/Support/load_probe.php'),
            escapeshellarg($path)
        );
        $out = trim((string)shell_exec($cmd));
        $this->assertSame('OK', $out, "Failed to load $path standalone: $out");
    }
}
```

- [ ] **Step 2: Run it to make sure it fails**

```bash
./app/Vendor/bin/phpunit -c app/phpunit.xml --filter BootstrapLoadabilityTest
```

Expected: FAIL — `load_probe.php` does not exist yet, so every case reports a PHP "Could not open input file" message instead of `OK`.

- [ ] **Step 3: Write the stubs**

Create `app/Test/Support/FrameworkStubs.php`. These are the exact stubs measured to load 261/279 files:

```php
<?php

namespace MispTest\Support;

class FrameworkStubs
{
    public static function install(): void
    {
        if (!defined('DS'))       { define('DS', DIRECTORY_SEPARATOR); }
        if (!defined('ROOT'))     { define('ROOT', dirname(dirname(__DIR__))); }
        if (!defined('APP'))      { define('APP', dirname(__DIR__) . DS); }
        if (!defined('WWW_ROOT')) { define('WWW_ROOT', APP . 'webroot' . DS); }
        if (!defined('TMP'))      { define('TMP', APP . 'tmp' . DS); }

        // Each stub is declared only if the real class is absent, so a test
        // that loads the genuine CakePHP class keeps the genuine behaviour.
        if (!class_exists('App', false)) {
            eval('class App {
                public static function uses($a = null, $b = null) {}
                public static function import($a = null, $b = null) {}
                public static function path($a = null, $b = null) { return []; }
            }');
        }
        if (!class_exists('Configure', false)) {
            eval('class Configure {
                private static $d = [];
                public static function read($k = null) { return self::$d[$k] ?? null; }
                public static function write($k, $v = null) { self::$d[$k] = $v; }
                public static function check($k) { return isset(self::$d[$k]); }
                public static function delete($k) { unset(self::$d[$k]); }
            }');
        }
        if (!class_exists('ClassRegistry', false)) {
            eval('class ClassRegistry {
                public static $stubs = [];
                public static function init($n) {
                    return self::$stubs[$n] ?? new \stdClass();
                }
            }');
        }
        foreach ([
            'Component'      => 'class Component { public function __construct($c = null, $s = []) {} public function initialize($c = null) {} }',
            'Helper'         => 'class Helper { public function __construct($v = null, $s = []) {} }',
            'Model'          => 'class Model { public $name; public function __construct($i = false, $t = null, $d = null) {} }',
            'Shell'          => 'class Shell { public function __construct($o = null, $c = null) {} }',
            'Controller'     => 'class Controller {}',
            'ModelBehavior'  => 'class ModelBehavior {}',
            'CakeObject'     => 'class CakeObject {}',
        ] as $class => $decl) {
            if (!class_exists($class, false)) { eval($decl); }
        }
        if (!class_exists('AppModel', false))      { eval('class AppModel extends Model {}'); }
        if (!class_exists('AppController', false)) { eval('class AppController extends Controller {}'); }
        if (!class_exists('AppShell', false))      { eval('class AppShell extends Shell {}'); }
        if (!class_exists('AppHelper', false))     { eval('class AppHelper extends Helper {}'); }
        if (!class_exists('CakeText', false)) {
            eval('class CakeText { public static function tokenize($d, $s = ",", $l = "(", $r = ")") { return explode($s, $d); } }');
        }
        if (!class_exists('Inflector', false)) {
            eval('class Inflector {
                public static function underscore($s) { return strtolower(preg_replace("/(?<!^)[A-Z]/", "_$0", $s)); }
                public static function camelize($s) { return str_replace(" ", "", ucwords(str_replace("_", " ", $s))); }
                public static function pluralize($s) { return $s . "s"; }
                public static function singularize($s) { return rtrim($s, "s"); }
            }');
        }
        if (!class_exists('CakeLog', false)) {
            eval('class CakeLog { public static $lines = []; public static function write($a, $b) { self::$lines[] = [$a, $b]; } }');
        }
        foreach (['CakeException', 'NotFoundException', 'MethodNotAllowedException',
                  'InternalErrorException', 'ForbiddenException'] as $e) {
            if (!class_exists($e, false)) { eval("class $e extends \\Exception {}"); }
        }
    }

    /**
     * Some files extend a real CakePHP or in-repo class that no stub can
     * stand in for. Load the genuine parent before including the file.
     */
    public static function loadRealParents(string $file): void
    {
        $map = [
            'Model/WorkflowModules' => [APP . 'Model/WorkflowModules/WorkflowBaseModule.php'],
            'Lib/Export/Nids'       => [APP . 'Lib/Export/NidsExport.php'],
            'Lib/Export/Stix'       => [APP . 'Lib/Export/StixExport.php'],
            'Lib/Export/Context'    => [APP . 'Lib/Export/ContextExport.php'],
            'Module_splunk_hec'     => [APP . 'Model/WorkflowModules/action/Module_webhook.php'],
        ];
        $cake = APP . 'Lib/cakephp/lib/Cake/';
        $cakeMap = [
            'AuthComponent'      => $cake . 'Controller/Component/AuthComponent.php',
            'SecurityComponent'  => $cake . 'Controller/Component/SecurityComponent.php',
            'PaginatorHelper'    => $cake . 'View/Helper/PaginatorHelper.php',
            'CakeEmail'          => $cake . 'Network/Email/CakeEmail.php',
            'HttpSocketResponse' => $cake . 'Network/Http/HttpSocketResponse.php',
            'BaseAuthenticate'   => $cake . 'Controller/Component/Auth/BaseAuthenticate.php',
            'CakeEventManager'   => $cake . 'Event/CakeEventManager.php',
        ];
        foreach ($map as $needle => $parents) {
            if (strpos($file, $needle) !== false) {
                foreach ($parents as $p) {
                    if ($p !== $file && is_file($p)) { require_once $p; }
                }
            }
        }
        $src = (string)file_get_contents($file);
        foreach ($cakeMap as $class => $path) {
            if (strpos($src, $class) !== false && !class_exists($class, false) && is_file($path)) {
                require_once $path;
            }
        }
    }
}
```

- [ ] **Step 4: Write the load probe used by the test**

Create `app/Test/Support/load_probe.php`:

```php
<?php
require_once __DIR__ . '/../bootstrap.php';

$file = $argv[1] ?? '';
if ($file === '' || !is_file($file)) {
    echo "FAIL no such file\n";
    exit(1);
}
try {
    \MispTest\Support\FrameworkStubs::loadRealParents($file);
    require_once $file;
    echo "OK\n";
} catch (\Throwable $e) {
    echo 'FAIL ' . get_class($e) . ': ' . str_replace("\n", ' ', $e->getMessage()) . "\n";
}
```

- [ ] **Step 5: Replace the bootstrap placeholder**

Overwrite `app/Test/bootstrap.php`:

```php
<?php
/**
 * Shared bootstrap for MISP's PHP test suites.
 *
 * Unit tests get framework stubs instead of a CakePHP bootstrap, so they
 * run with no database, no Redis and no HTTP. Integration tests require
 * Test/Integration/IntegrationTestCase.php on top of this.
 */
require_once __DIR__ . '/../Vendor/autoload.php';
require_once __DIR__ . '/Support/FrameworkStubs.php';

\MispTest\Support\FrameworkStubs::install();
```

- [ ] **Step 6: Run the loadability test**

```bash
./app/Vendor/bin/phpunit -c app/phpunit.xml --filter BootstrapLoadabilityTest
```

Expected: PASS for every case. If a file fails, add its parent to `loadRealParents()`'s `$cakeMap` — do not weaken the assertion or skip the file.

- [ ] **Step 7: Confirm no regression**

```bash
./app/Vendor/bin/phpunit -c app/phpunit.xml --testsuite unit
```

Expected: the 477 pre-existing tests still pass, plus the new loadability cases.

- [ ] **Step 8: Commit**

```bash
git add app/Test/bootstrap.php app/Test/Support app/Test/Unit/BootstrapLoadabilityTest.php
git commit -S -m "test: add shared bootstrap and framework stubs

Centralises the framework stubs each test file currently re-declares.
BootstrapLoadabilityTest asserts every dependency-light source file under
Lib/Tools, Lib/Export, Lib/Dashboard, Model/WorkflowModules and
View/Helper loads standalone, so later test tranches can rely on it."
```

---

### Task 3: Migrate existing tests onto the bootstrap

**Files:**
- Modify: all 19 files in `app/Test/*.php` (move to `app/Test/Unit/`, delete inline stub blocks)

**Interfaces:**
- Consumes: `FrameworkStubs::install()` from Task 2 (already applied by the bootstrap before any test loads).
- Produces: no new interfaces. `app/Test/Unit/` becomes the home of layer-1 tests.

- [ ] **Step 1: Record the baseline**

```bash
./app/Vendor/bin/phpunit -c app/phpunit.xml --testsuite unit | tail -3
```

Write down the exact counts (expected `Tests: 477, Assertions: 1087`). This is the invariant for this task: behaviour must not change.

- [ ] **Step 2: Move the files**

```bash
git mv app/Test/AttributeValidationToolTest.php app/Test/Unit/
git mv app/Test/CanonicalTypeAdapterTest.php app/Test/Unit/
git mv app/Test/CidrToolTest.php app/Test/Unit/
git mv app/Test/CollectionCaptureTest.php app/Test/Unit/
git mv app/Test/CollectionPullTest.php app/Test/Unit/
git mv app/Test/CollectionPushTest.php app/Test/Unit/
git mv app/Test/ComplexTypeToolTest.php app/Test/Unit/
git mv app/Test/CryptGpgExtendedTest.php app/Test/Unit/
git mv app/Test/DashboardURLValidatorTest.php app/Test/Unit/
git mv app/Test/EventTemplateInfoRendererTest.php app/Test/Unit/
git mv app/Test/EventTemplateInstantiatorTest.php app/Test/Unit/
git mv app/Test/EventTemplateValidatorTest.php app/Test/Unit/
git mv app/Test/FinancialToolTest.php app/Test/Unit/
git mv app/Test/JSONConverterToolTest.php app/Test/Unit/
git mv app/Test/MailLogToolTest.php app/Test/Unit/
git mv app/Test/PewPewMapWidgetTest.php app/Test/Unit/
git mv app/Test/ServerSyncCollectionNegotiationTest.php app/Test/Unit/
git mv app/Test/WidgetCacheTest.php app/Test/Unit/
git mv app/Test/WidgetSchemaTest.php app/Test/Unit/
```

- [ ] **Step 3: Fix the relative requires**

Each moved file requires source relative to its old location. Update one directory level:

```bash
sed -i "s|__DIR__ . '/../Lib/|__DIR__ . '/../../Lib/|g; \
        s|__DIR__ . '/../Model/|__DIR__ . '/../../Model/|g; \
        s|__DIR__ . '/../Vendor/|__DIR__ . '/../../Vendor/|g; \
        s|__DIR__ . '/../Config/|__DIR__ . '/../../Config/|g" app/Test/Unit/*.php
```

- [ ] **Step 4: Run and confirm the invariant holds**

```bash
./app/Vendor/bin/phpunit -c app/phpunit.xml --testsuite unit | tail -3
```

Expected: the same test and assertion counts recorded in Step 1 (plus the loadability cases from Task 2). If any file errors on a redeclared class, delete that file's inline stub block — the bootstrap now provides it — rather than re-adding a guard.

- [ ] **Step 5: Remove now-redundant inline stubs**

For each file that declares its own `App`, `Configure`, `ClassRegistry`, `Inflector` or `CakeText` inside a `class_exists` guard, delete that block. Re-run after each file:

```bash
./app/Vendor/bin/phpunit -c app/phpunit.xml --testsuite unit | tail -3
```

Expected: counts unchanged after every deletion.

- [ ] **Step 6: Commit**

```bash
git add -A app/Test
git commit -S -m "test: move existing tests to Test/Unit and drop duplicated stubs

No behaviour change - same 477 tests, same assertions. The framework
stubs each file used to declare now come from the shared bootstrap."
```

---

### Task 4: CryptGpgExtendedTest skips when GPG is unconfigured

**Files:**
- Modify: `app/Test/Unit/CryptGpgExtendedTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: nothing.

Rationale: running the documented command on a checkout without GPG configured produces **errors**, not skips. An error means "the suite is broken"; a skip means "this needs GPG". Only the second is true.

- [ ] **Step 1: Reproduce the failure**

```bash
mv app/Config/config.php /tmp/config.php.bak 2>/dev/null || true
cp app/Config/config.default.php app/Config/config.php
./app/Vendor/bin/phpunit -c app/phpunit.xml --filter GpgToolTest
```

Expected: 2 errors — `The 'homedir' "" is not readable or does not exist`.

- [ ] **Step 2: Add the guard**

In `app/Test/Unit/CryptGpgExtendedTest.php`, replace the body of `private function init()`'s config read so the test skips when GPG is not usable. Insert at the top of `init()`:

```php
        $configFile = __DIR__ . '/../../Config/config.php';
        if (!is_file($configFile)) {
            $this->markTestSkipped('app/Config/config.php is not present; GPG tests need a configured instance.');
        }
        include $configFile;
        $homedir = $config['GnuPG']['homedir'] ?? '';
        if ($homedir === '' || !is_dir($homedir)) {
            $this->markTestSkipped('GnuPG.homedir is not configured; skipping GPG-backed tests.');
        }
        if (!is_file($config['GnuPG']['binary'] ?? '/usr/bin/gpg')) {
            $this->markTestSkipped('gpg binary not available; skipping GPG-backed tests.');
        }
```

- [ ] **Step 3: Verify it now skips rather than errors**

```bash
./app/Vendor/bin/phpunit -c app/phpunit.xml --filter GpgToolTest
```

Expected: `OK, but incomplete, skipped, or risky tests!` with 2 skipped and **0 errors**.

- [ ] **Step 4: Verify it still runs when GPG IS configured**

```bash
mkdir -p /tmp/gnupghome && chmod 700 /tmp/gnupghome
gpg --no-tty --batch --pinentry-mode=loopback --passphrase travistest \
    --homedir /tmp/gnupghome --gen-key build/gpg
php -r '$f="app/Config/config.php"; $s=file_get_contents($f);
        $s=str_replace("\x27homedir\x27           => \x27\x27,", "\x27homedir\x27 => \x27/tmp/gnupghome\x27,", $s);
        file_put_contents($f,$s);'
./app/Vendor/bin/phpunit -c app/phpunit.xml --filter GpgToolTest
```

Expected: the tests execute (1 may still fail on a missing passphrase — that is a separate environment concern, not this task's scope; it must not be an *error* caused by the guard).

- [ ] **Step 5: Restore your config and commit**

```bash
mv /tmp/config.php.bak app/Config/config.php 2>/dev/null || true
git add app/Test/Unit/CryptGpgExtendedTest.php
git commit -S -m "test: skip GPG tests when GnuPG is not configured

Running the documented 'phpunit app/Test/' on a checkout without a GPG
homedir produced errors, implying a broken suite. It is a missing
precondition, so mark it skipped."
```

---

### Task 5: Live-suite coverage instrumentation

**Files:**
- Create: `build/coverage/covcollect.php`

**Interfaces:**
- Consumes: nothing.
- Produces: JSON capture files at `${MISP_COV_DIR}/<pid>-<rand>.json`, each `{"<abs source path>": [<executed line numbers>], ...}`. Consumed by Task 6's `merge_coverage.py`.

- [ ] **Step 1: Write the instrumentation**

Create `build/coverage/covcollect.php`. This is the exact script proven to capture 2,617 requests:

```php
<?php
/**
 * Per-request coverage collector for MISP's live test suite.
 *
 * Enable by setting, in php.ini for both the web SAPI and CLI:
 *     pcov.enabled=1
 *     pcov.directory=/var/www/MISP/app
 *     auto_prepend_file=/path/to/build/coverage/covcollect.php
 *
 * Collection only happens once the flag file exists, so instance setup
 * (schema import, runUpdates, updateJSON) is not counted as test coverage.
 */
$covDir = getenv('MISP_COV_DIR') ?: '/cov';

if (extension_loaded('pcov') && @file_exists($covDir . '/ENABLED')) {
    \pcov\start();
    register_shutdown_function(function () use ($covDir) {
        \pcov\stop();
        // pcov\inclusive matches exact file paths, not directories, so
        // collect everything and filter here.
        $data = \pcov\collect(\pcov\all);
        $appRoot = getenv('MISP_APP_ROOT') ?: '/var/www/MISP/app/';
        $hit = [];
        foreach ($data as $file => $lines) {
            if (strpos($file, $appRoot) !== 0)          { continue; }
            if (strpos($file, '/Vendor/') !== false)     { continue; }
            if (strpos($file, '/Lib/cakephp/') !== false) { continue; }
            $covered = [];
            foreach ($lines as $ln => $count) {
                if ($count > 0) { $covered[] = $ln; }
            }
            if ($covered) { $hit[$file] = $covered; }
        }
        if ($hit) {
            @file_put_contents(
                sprintf('%s/%d-%s.json', $covDir, getmypid(), bin2hex(random_bytes(6))),
                json_encode($hit)
            );
        }
    });
}
```

- [ ] **Step 2: Verify it captures a single request**

With MISP running under a PHP configured as above:

```bash
mkdir -p /cov && chmod 777 /cov && touch /cov/ENABLED
curl -sS -H "Authorization: $AUTH" -H "Accept: application/json" \
     http://127.0.0.1/servers/getVersion > /dev/null
ls /cov/*.json | wc -l
```

Expected: at least `1`. Inspect it:

```bash
python3 -c "import json,glob; d=json.load(open(sorted(glob.glob('/cov/*.json'))[-1])); \
print(len(d),'files',sum(len(v) for v in d.values()),'lines')"
```

Expected: roughly `45 files 957 lines` for `getVersion`. If you get `0 files`, `pcov.directory` is wrong or `auto_prepend_file` is not applied to the web SAPI — check with a script printing `ini_get('auto_prepend_file')`.

- [ ] **Step 3: Verify the gate works**

```bash
rm /cov/ENABLED && rm -f /cov/*.json
curl -sS -H "Authorization: $AUTH" http://127.0.0.1/servers/getVersion > /dev/null
ls /cov/*.json 2>/dev/null | wc -l
```

Expected: `0` — setup activity must not be counted.

- [ ] **Step 4: Commit**

```bash
git add build/coverage/covcollect.php
git commit -S -m "test: add pcov per-request collector for the live suite

Gated on a flag file so instance setup is excluded from coverage."
```

---

### Task 6: Coverage merge and report tooling

**Files:**
- Create: `build/coverage/merge_coverage.py`
- Create: `build/coverage/report.py`
- Test: `build/coverage/test_report.py`

**Interfaces:**
- Consumes: capture files from Task 5; a clover XML from Task 1.
- Produces:
  - `merge_coverage.py <cov_dir> <out.json>` → `{"<rel path>": [lines]}`
  - `report.py <clover.xml> <merged.json>` → prints the table and writes `coverage-summary.json` with keys `unit_pct`, `live_pct`, `union_pct`, `statements`.

- [ ] **Step 1: Write the failing test**

Create `build/coverage/test_report.py`:

```python
import json, subprocess, sys, textwrap
from pathlib import Path

CLOVER = textwrap.dedent("""\
    <?xml version="1.0" encoding="UTF-8"?>
    <coverage><project>
      <file name="/app/Lib/Tools/A.php">
        <line num="1" type="stmt" count="1"/>
        <line num="2" type="stmt" count="0"/>
        <line num="3" type="stmt" count="0"/>
        <line num="4" type="stmt" count="0"/>
      </file>
    </project></coverage>
""")

def test_union_counts_each_line_once(tmp_path: Path) -> None:
    clover = tmp_path / "clover.xml"; clover.write_text(CLOVER)
    # live covers lines 2 and 3; unit covers line 1. Union = 3/4 = 75%.
    merged = tmp_path / "merged.json"
    merged.write_text(json.dumps({"Lib/Tools/A.php": [2, 3]}))
    out = subprocess.run(
        [sys.executable, "build/coverage/report.py", str(clover), str(merged),
         "--json", str(tmp_path / "summary.json")],
        capture_output=True, text=True, check=True)
    s = json.loads((tmp_path / "summary.json").read_text())
    assert s["statements"] == 4
    assert s["unit_pct"] == 25.0
    assert s["live_pct"] == 50.0
    assert s["union_pct"] == 75.0
```

- [ ] **Step 2: Run it to confirm it fails**

```bash
python3 -m pytest build/coverage/test_report.py -v
```

Expected: FAIL — `report.py` does not exist.

- [ ] **Step 3: Write the merger**

Create `build/coverage/merge_coverage.py`:

```python
#!/usr/bin/env python3
"""Merge pcov capture files into one {relative path: [lines]} map."""
import collections, glob, json, os, sys

def main() -> int:
    if len(sys.argv) < 3:
        print("usage: merge_coverage.py <cov_dir> <out.json> [app_root]", file=sys.stderr)
        return 2
    cov_dir, out_path = sys.argv[1], sys.argv[2]
    app_root = sys.argv[3] if len(sys.argv) > 3 else "/var/www/MISP/app/"
    merged: dict[str, set] = collections.defaultdict(set)
    captures = 0
    for fn in glob.glob(os.path.join(cov_dir, "*.json")):
        if os.path.basename(fn) in ("MERGED.json",):
            continue
        try:
            with open(fn) as fh:
                data = json.load(fh)
        except (OSError, ValueError):
            continue
        captures += 1
        for path, lines in data.items():
            merged[path.replace(app_root, "")].update(lines)
    with open(out_path, "w") as fh:
        json.dump({k: sorted(v) for k, v in merged.items()}, fh)
    print(f"merged {captures} captures -> {len(merged)} files, "
          f"{sum(len(v) for v in merged.values())} lines")
    return 0

if __name__ == "__main__":
    raise SystemExit(main())
```

- [ ] **Step 4: Write the reporter**

Create `build/coverage/report.py`:

```python
#!/usr/bin/env python3
"""Report unit / live / union coverage against a clover statement map."""
import argparse, collections, json, xml.etree.ElementTree as ET

def load_clover(path: str):
    root = ET.parse(path).getroot()
    stmt, unit = {}, {}
    for f in root.iter("file"):
        rel = (f.get("name") or f.get("path") or "")
        rel = rel.split("/app/")[-1] if "/app/" in rel else rel
        s, u = set(), set()
        for ln in f.findall("line"):
            n = int(ln.get("num"))
            s.add(n)
            if int(ln.get("count", 0)) > 0:
                u.add(n)
        stmt[rel], unit[rel] = s, u
    return stmt, unit

def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("clover"); ap.add_argument("merged")
    ap.add_argument("--json", dest="json_out")
    ap.add_argument("--min-union", type=float, default=None,
                    help="fail if union coverage is below this percentage")
    a = ap.parse_args()

    stmt, unit = load_clover(a.clover)
    with open(a.merged) as fh:
        live_raw = json.load(fh)
    live = {p: set(v) for p, v in live_raw.items()}

    inter = {p: live.get(p, set()) & stmt[p] for p in stmt}
    total = sum(len(v) for v in stmt.values())
    tu = sum(len(v) for v in unit.values())
    ti = sum(len(v) for v in inter.values())
    tun = sum(len(unit[p] | inter[p]) for p in stmt)

    pct = lambda n: round(100.0 * n / total, 2) if total else 0.0
    print(f"statements  {total}")
    print(f"unit        {tu:7d}  {pct(tu):6.2f}%")
    print(f"live        {ti:7d}  {pct(ti):6.2f}%")
    print(f"union       {tun:7d}  {pct(tun):6.2f}%")

    agg = collections.defaultdict(lambda: [0, 0, 0])
    for p in stmt:
        parts = p.split("/")
        d = "/".join(parts[:2]) if len(parts) > 2 else parts[0]
        agg[d][0] += len(stmt[p])
        agg[d][1] += len(unit[p])
        agg[d][2] += len(inter[p])
    print(f"\n{'subsystem':30s} {'stmts':>7s} {'unit%':>7s} {'live%':>7s}")
    for d, (s, u, i) in sorted(agg.items(), key=lambda kv: -kv[1][0]):
        if s < 400:
            continue
        print(f"{d:30s} {s:7d} {100.0*u/s:6.2f}% {100.0*i/s:6.2f}%")

    summary = {"statements": total, "unit_pct": pct(tu),
               "live_pct": pct(ti), "union_pct": pct(tun)}
    if a.json_out:
        with open(a.json_out, "w") as fh:
            json.dump(summary, fh, indent=2)
    if a.min_union is not None and summary["union_pct"] < a.min_union:
        print(f"\nFAIL: union {summary['union_pct']}% < required {a.min_union}%")
        return 1
    return 0

if __name__ == "__main__":
    raise SystemExit(main())
```

- [ ] **Step 5: Run the test to confirm it passes**

```bash
python3 -m pytest build/coverage/test_report.py -v
```

Expected: PASS.

- [ ] **Step 6: Confirm against the real baseline**

```bash
python3 build/coverage/merge_coverage.py /cov /tmp/merged.json
python3 build/coverage/report.py clover.xml /tmp/merged.json
```

Expected, on commit `ff132f4d` with the full live suite run: `unit 1.77%`, `live 18.46%`, `union 19.67%`, `statements 117925`. Deviations mean the capture set or clover map is incomplete — investigate before proceeding.

- [ ] **Step 7: Commit**

```bash
git add build/coverage/merge_coverage.py build/coverage/report.py build/coverage/test_report.py
git commit -S -m "test: add coverage merge and reporting tooling

Intersects live pcov captures with the PHPUnit clover statement map so
unit, live and union coverage are directly comparable."
```

---

### Task 7: CI workflow with a coverage ratchet

**Files:**
- Create: `.github/workflows/coverage.yml`
- Create: `docs/testing.md`

**Interfaces:**
- Consumes: everything from Tasks 1–6.
- Produces: a `coverage-summary.json` artifact per run; a build failure when union coverage drops below the floor.

- [ ] **Step 1: Write the workflow**

Create `.github/workflows/coverage.yml`. It mirrors the existing `main.yml` setup, then adds instrumentation. Set `MIN_UNION` to the current measured floor.

```yaml
name: coverage

on:
  push:
    branches: [ '2.5' ]
  pull_request:
    branches: [ '2.5' ]

env:
  MIN_UNION: '18.5'

jobs:
  coverage:
    runs-on: ubuntu-24.04
    services:
      mariadb:
        image: mariadb:10.11
        env:
          MARIADB_ROOT_PASSWORD: bar
          MARIADB_DATABASE: misp
          MARIADB_USER: misp
          MARIADB_PASSWORD: blah
        ports: [ '3306:3306' ]
        options: >-
          --health-cmd="mariadb-admin ping -h 127.0.0.1 -uroot -pbar"
          --health-interval=5s --health-timeout=3s --health-retries=30
      redis:
        image: redis:7
        ports: [ '6379:6379' ]
        options: >-
          --health-cmd="redis-cli ping" --health-interval=5s
          --health-timeout=3s --health-retries=30

    steps:
      - uses: actions/checkout@v5
        with:
          submodules: 'recursive'

      - name: Setup PHP with pcov
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          extensions: mysql, mbstring, xml, opcache, readline, redis, gd, apcu, intl, zip, pcntl, pcov
          ini-values: memory_limit=2048M, pcov.enabled=1

      # The zip BINARY is required (not just libzip/ext-zip): MISP shells out
      # to `zip` when building attachment archives, and its absence surfaces
      # as proc_open(): posix_spawn() failed: No such file or directory.
      - name: Install system deps
        run: sudo apt-get -y update && sudo apt-get -y install zip unzip gnupg

      - name: Install PHP dependencies
        run: cd app && composer install --no-progress --no-interaction

      - name: Unit suite with coverage
        run: |
          cd app
          ../app/Vendor/bin/phpunit -c phpunit.xml --testsuite unit \
            --coverage-clover ../clover.xml
          cd ..

      - name: Install and configure MISP
        run: bash build/coverage/ci_setup_misp.sh

      - name: Live suite under instrumentation
        env:
          MISP_COV_DIR: ${{ github.workspace }}/cov
          MISP_APP_ROOT: ${{ github.workspace }}/app/
        run: |
          mkdir -p "$MISP_COV_DIR" && chmod 777 "$MISP_COV_DIR"
          touch "$MISP_COV_DIR/ENABLED"
          bash build/coverage/ci_run_live_suite.sh

      - name: Merge and report
        env:
          MISP_COV_DIR: ${{ github.workspace }}/cov
        run: |
          python3 build/coverage/merge_coverage.py "$MISP_COV_DIR" merged.json \
            "${{ github.workspace }}/app/"
          python3 build/coverage/report.py clover.xml merged.json \
            --json coverage-summary.json --min-union "$MIN_UNION"

      - name: Upload coverage artifacts
        if: ${{ always() }}
        uses: actions/upload-artifact@v4
        with:
          name: coverage
          path: |
            clover.xml
            merged.json
            coverage-summary.json
```

Note: `ci_setup_misp.sh` and `ci_run_live_suite.sh` extract the install and run steps that `main.yml` performs inline. Create them by copying the corresponding blocks from `.github/workflows/main.yml` verbatim, so the two workflows cannot drift in how MISP is installed.

- [ ] **Step 2: Write the developer documentation**

Create `docs/testing.md` documenting: the three layers and their capability boundary; how to run each suite; how to reproduce coverage locally with podman; and the environment preconditions that each cost real debugging time —

```markdown
- The `zip` **binary** must be installed (not just `libzip-dev`), or MISP's
  attachment paths fail with `proc_open(): posix_spawn() failed: No such file
  or directory`.
- `app/files/scripts/*` submodules must be initialised, or the STIX paths
  fail the same way.
- PyMISP needs its own `pymisp/data/misp-objects` submodule initialised, plus
  `pip install ".[fileobjects]"`, or object-building tests raise
  `NewAttributeError: ... Is the object template missing?`.
- `MISP.baseurl` must match the port the tests reach; a mismatch makes
  `testlive_security.py` follow a redirect to a dead port.
- `MISP.host_org_id` can only be set AFTER `cake User init` creates the org.
- Interrupting `testlive_security.py` leaves `Security.auth` set to
  `ShibbAuth.ApacheShibb` in `app/Config/config.php`, after which the login
  page renders with no form and every later run fails at setUpClass. Reset it
  with `build/coverage/reset_instance.sh` before re-running.
```

- [ ] **Step 3: Verify the ratchet fires**

Temporarily set `MIN_UNION: '99'` and push to a branch. Expected: the "Merge and report" step fails with `FAIL: union 18.95% < required 99%`. Restore `18.5` afterwards.

- [ ] **Step 4: Verify a clean run passes**

Push with `MIN_UNION: '18.5'`. Expected: green, and the `coverage` artifact contains a `coverage-summary.json` with `union_pct` at or above 18.95.

- [ ] **Step 5: Commit**

```bash
git add .github/workflows/coverage.yml build/coverage/ci_setup_misp.sh \
        build/coverage/ci_run_live_suite.sh docs/testing.md
git commit -S -m "ci: measure and ratchet unit, live and union coverage

Runs the PHPUnit suite with clover, then the live Python suite under pcov
instrumentation, and fails if union coverage regresses below the floor."
```

---

## Self-Review

**Spec coverage.** §5.1 (bootstrap, PHPUnit 9.6, phpunit.xml, GPG skip) → Tasks 1, 2, 4. §4 directory layout → Tasks 1–3 create `Test/Unit`, `Test/Integration`, `Test/Support`. §9 (measurement + CI) → Tasks 5, 6, 7. §8 (isolation contract) → partially: `docs/testing.md` in Task 7 documents the failure mode and Task 7 references `reset_instance.sh`, but the Layer 3 `tearDownClass`/`atexit` handlers belong to the live-suite plan, which owns those files. Noted as a deliberate hand-off, not a gap. §5.2–5.4, §6, §7 (the actual test tranches) are explicitly out of scope for P0 and covered by the follow-up plans below.

**Placeholder scan.** No TBD/TODO. Every code step carries runnable content. The one indirection — `ci_setup_misp.sh` / `ci_run_live_suite.sh` "copy the blocks from main.yml" — is deliberate: reproducing ~120 lines of install script inline would guarantee drift from the workflow it must mirror.

**Type consistency.** `FrameworkStubs::install()` and `FrameworkStubs::loadRealParents(string $file)` are declared in Task 2 and used with those exact names in `load_probe.php` (Task 2) and nowhere else. `merge_coverage.py <cov_dir> <out.json> [app_root]` (Task 6 Step 3) matches its invocation in Task 7's workflow, which passes all three arguments. `report.py <clover> <merged> --json --min-union` matches both the test in Step 1 and the workflow.

---

## Follow-up plans (not this plan)

Each needs its own plan once P0 lands, because their tasks depend on the bootstrap interfaces created here:

1. **Unit tranche 1 — zero-overlap wins.** Dashboard widget conformance (6,058 stmts, 0 % live) and workflow module conformance (4,283 stmts, 0 % live). Highest measured value in the whole programme.
2. **Unit tranche 2 — pure transforms.** AttachmentObjectBuilder, graph/timeline tools, view helpers, exports with golden files.
3. **Live suite extension.** `testlive_dashboards.py`, `testlive_workflows.py`, `testlive_export_formats.py`, the shared `tests/lib/misp_live.py`, and the §8 isolation contract.
4. **Layer 2 integration harness.** Correlation cross-strategy equivalence and console shells.
