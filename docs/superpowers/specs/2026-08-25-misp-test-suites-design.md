# MISP 2.5 — Two-Layer Test Suite Design

**Status:** design, pending review
**Date:** 2026-08-25
**Baseline commit:** `ff132f4d` (MISP 2.5.44)
**Target:** `elhoim/MISP` fork, optimised for coverage (not upstream-merge-constrained)

---

## 1. Measured baseline

Every number here was measured this session, not estimated. Two harnesses, one commit.

| Suite | Covered / 117,925 stmt lines | % | Files touched |
|---|---:|---:|---:|
| PHPUnit unit suite (19 files, 477 tests) | 2,093 | **1.77 %** | 21 / 528 |
| Live suite (~300 tests) | 21,764 | **18.46 %** | 223 / 528 |
| **Union** | 23,192 | **19.67 %** | 231 / 528 |
| Lines in both | 665 | — | |

> **Denominator note.** These are the *filtered* figures produced by the
> `app/phpunit.xml` shipped in P0, which excludes vendored CakePHP, Composer
> packages and the DebugKit/CakeResque plugins' own test code. An earlier
> unfiltered measurement (122,618 statements: unit 1.71 %, live 17.78 %,
> union 18.95 %) counted 4,693 statements of third-party plugin test code in
> the denominator. The filtered numbers above are the ones the CI ratchet uses.

Live coverage per subsystem:

| Subsystem | Live % | Note |
|---|---:|---|
| `Model` (top-level) | 28.01 | best-covered area |
| `Controller` | 16.62 | |
| `Controller/Component` | 34.43 | |
| `Lib/Tools` | 22.26 | |
| `Lib/Export` | 20.58 | |
| `Model/Behavior` | 24.32 | |
| `View/Helper` | 3.99 | |
| `Console/Command` | **1.22** | effectively dark |
| `Lib/Dashboard` | **0.00** | never executed |
| `Model/WorkflowModules` | **0.00** | never executed |

**23,150 statements across 58 files >150 stmts are dark in both suites.**

Two structural facts drive the whole design:

1. **Every candidate file loads standalone** under the shared framework stubs — no DB, no CakePHP bootstrap. An ad-hoc harness first measured 261/279 (94 %); the productionised `BootstrapLoadabilityTest` shipped in P0 reaches **252/252 (100 %)** once in-repo parent classes are loaded and the stub signatures match the real ones. Unit-testing MISP is not blocked by framework coupling.
2. **86.7 % of the proposed unit targets are untouched by the live suite.** Overlap by area: Dashboard 0 %, WorkflowModules 0 %, AttachmentObjectBuilder 0 %, graph tools 3.2 %, View/Helper 4.0 %, exports 13–32 %, behaviors 24 %, components 34 %, deepening 40 %.

### Correction to an earlier assumption
The original plan scoped `Console/Command` out on the assumption that CI exercised it. Measurement says **1.22 %**. It is MISP's single largest dark block (~12,000 stmts) and is back in scope.

---

## 2. Goals and non-goals

**Goals**
- Raise measured union coverage from 18.95 % toward ~35–40 % in tranches, each independently reviewable.
- Make coverage a *tracked number* (both suites) rather than a one-off measurement.
- Kill the stub duplication in `app/Test/` before it scales to hundreds of files.
- Cover what each layer is actually good at, with no redundant work between layers.

**Non-goals**
- Upstream-merge compatibility (explicitly out of scope for this fork).
- Rewriting existing passing tests. The 19 existing files stay; they gain a shared bootstrap.
- Chasing 100 %. Generated code, vendored plugins and view templates stay excluded.

---

## 3. Approaches considered

**A. Keep the bare-stub convention, add files.**
Every test file re-declares its own `App`/`Configure`/`ClassRegistry` stubs, as today.
*Pro:* zero infrastructure, matches existing code exactly.
*Con:* the stub block is already ~40 lines duplicated across files with `class_exists` guards to stop them colliding. At 100+ files this becomes the dominant maintenance cost, and collisions (the real `WorkflowBaseModule` vs a stub) are already observable.

**B. Shared bootstrap + three explicit layers. ← recommended**
One `app/Test/bootstrap.php` owns the framework stubs; tests declare which layer they belong to; each layer has a defined capability boundary and its own PHPUnit testsuite.
*Pro:* removes duplication, makes the unit/integration split enforceable, lets conformance tests scale across whole file families, keeps the fast suite fast.
*Con:* one-time refactor of the existing 19 files (mechanical — delete stub blocks, require bootstrap).

**C. Adopt CakePHP 2's full test framework (fixtures, `ControllerTestCase`).**
*Pro:* idiomatic for the framework; controller tests attribute coverage natively.
*Con:* CakePHP 2's fixture layer is slow and dated, MISP's models are heavily interdependent (`Event` 6,168 stmts, `Server` 6,700), and the live Python suite already covers controllers over HTTP at 16.6 %. Large investment duplicating existing signal.

**Decision: B**, with a narrow slice of C (real-DB PHP tests) where asserting internals is the only way to get the assertion — see Layer 2.

---

## 4. Architecture — three layers

The split rule is a capability boundary, not a taste preference:

| Layer | May use | May NOT use | Answers |
|---|---|---|---|
| **1. Unit** (PHP) | pure PHP, fixtures on disk | DB, Redis, HTTP, network | "is this function correct for this input?" |
| **2. Integration** (PHP) | DB, Redis, model internals | HTTP, auth stack | "do these components agree with each other?" |
| **3. Live** (Python) | full HTTP stack, auth, ACL | — | "does the deployed system behave?" |

A behaviour is tested in the **highest-numbered layer that can assert it, and no higher**. Correlation strategies need model internals → Layer 2, not Layer 3. Export formats are pure transforms → Layer 1, even though restSearch also exercises them.

### Directory layout

```
app/
  phpunit.xml                       # NEW  testsuites: unit, integration; coverage filter
  Test/
    bootstrap.php                   # NEW  shared framework stubs + real-parent loader
    Support/
      FrameworkStubs.php            # NEW  App, Configure, ClassRegistry, Component, Helper, Shell…
      Contract/
        WidgetContract.php          # NEW  reusable assertions for the widget family
        ModuleContract.php          # NEW  reusable assertions for workflow modules
        ExportContract.php          # NEW  reusable assertions for export formats
      Fixture/
        events/*.json               # NEW  canonical event/attribute/object fixtures
        golden/*.txt                # NEW  expected export output (golden files)
        binaries/                   # NEW  small PE/ELF samples for AttachmentObjectBuilder
    Unit/                           # NEW  layer 1 (existing 19 files migrate here)
    Integration/                    # NEW  layer 2
      IntegrationTestCase.php       #      DB connect, transaction rollback per test
tests/
  lib/misp_live.py                  # NEW  shared session/connector/cleanup helpers
  testlive_dashboards.py            # NEW  layer 3
  testlive_workflows.py             # NEW
  testlive_export_formats.py        # NEW
  testlive_console.py               # NEW
build/coverage/
  covcollect.php                    # NEW  pcov auto_prepend instrumentation (built & proven)
  merge_coverage.py                 # NEW  merge captures → clover-comparable JSON
  report.py                         # NEW  unit / live / union / per-subsystem table
.github/workflows/coverage.yml      # NEW  runs both suites, publishes the three numbers
```

---

## 5. Layer 1 — unit suite

### 5.1 Shared bootstrap (PR0)
`app/Test/bootstrap.php` provides the stub set proven this session to load 261/279 candidate files, plus a loader for the 18 files needing a real parent class (`AuthComponent`, `SecurityComponent`, `CakeEmail`, `PaginatorHelper`, `HttpSocketResponse`, `BaseAuthenticate`, `CakeEventManager` — all present in `app/Lib/cakephp`). Existing tests drop their inline stubs and require it.

Also in PR0:
- `composer.json`: `phpunit/phpunit` `^8` → `^9.6`. **This is a defect fix, not a preference**: php-code-coverage 7 refuses to run on PHP 8 (`This version of PHPUnit does not support code coverage on PHP 8`) while composer.json requires PHP ≥8.1, so `--coverage-*` is currently impossible for everyone.
- `app/phpunit.xml` with coverage include/exclude. Exclude `app/Lib/cakephp`, `app/Vendor`, `app/Plugin/DebugKit`, `app/Plugin/CakeResque` — the last two are vendored third-party *test* code that pollutes any whitelist.
- `CryptGpgExtendedTest` skips cleanly when no GPG homedir is configured (today it errors for anyone running the documented `phpunit app/Test/`).

### 5.2 The conformance-test pattern — the core idea
The largest gaps are *families of similar files*: 66 dashboard widgets, 69 workflow modules, 26 export formats. Writing 66 bespoke test files is the wrong shape. Instead one data-driven test discovers the family and asserts the contract every member must satisfy:

```php
// ~200 LOC total, touches all 66 widget files
public function widgetProvider(): array   // glob Lib/Dashboard/*Widget.php
public function testWidgetContract(string $class): void
{
    $w = new $class();
    $this->assertNonEmptyString($w->title);
    $this->assertNonEmptyString($w->render);          // template exists on disk
    $this->assertValidAgainstWidgetSchema($w->params); // reuses tested WidgetSchema
    $this->assertHasHandler($w);
    $this->assertDeclaresAclScope($w);
}
```

This is the highest coverage-per-line in the plan: it executes every file's class body, property initialisation and constructor, and it *fails when someone adds a malformed widget* — a class of bug no per-file test suite catches, because the failure is about the family, not the member.

The same shape applies to workflow modules (`$id` unique across registry, `$params` valid, `exec()` signature) and exports (every format handles every attribute type without fatal).

### 5.3 Golden-file testing for exports
`Lib/Export` is pure `input → string`. Fixture event → expected output file on disk. Adding an attribute type to the matrix is one fixture line, not a test method. This is what makes covering 40+ attribute types across 19 formats affordable, and it directly addresses the caveat that live coverage of exports means "ran once for one type", not "correct for all types".

### 5.4 Targets, ranked by measured value

| # | Target | Stmts | Live overlap | Why |
|---|---|---:|---:|---|
| U1 | Dashboard widgets (66 files) | 6,058 | **0 %** | biggest block, base machinery already tested, conformance stage ~200 LOC |
| U2 | Workflow modules (69 files) | 4,283 | **0 %** | user-authored automation; a mis-evaluated condition mis-routes intelligence |
| U3 | AttachmentObjectBuilder | 804 | **0 %** | single file, 45 methods, PE/ELF parsing, high bug surface |
| U4 | Graph & timeline tools (9) | 1,523 | 3.2 % | deterministic structure-in/out |
| U5 | View helpers (21) | 1,427 | 4.0 % | `NavbarHelper` alone is 886 |
| U6 | IDS exports (4) | 1,197 | 13.1 % | security-critical output; golden files |
| U7 | Format/util helpers (17) | 1,365 | 17.7 % | pure static functions, cheapest per line |
| U8 | Tabular/text exports (15) | 830 | 31.6 % | golden files; overlap is execution-only, not assertion |
| U9 | `ACLComponent` conformance | 309 | 47.6 % | see below |

**On U9 and the overlap trap.** `ACLComponent` reads 47.6 % live-covered, which makes it look redundant. It is not: those lines execute because the ACL map is *consulted* while serving requests. The conformance test asserts what execution never can — that every controller action **has** an entry and every entry names a real action. A missing entry is invisible to a request that never tries the missing route. Overlapping lines are not overlapping value; the same applies to exports.

**Deprioritised on evidence:** the original PR10 "deepen partial coverage" is now the weakest item at 39.7 % overlap. `EventTemplateInstantiator` (40.8 % live) is already well exercised and `ServerSyncTool` (29.5 % live, and further covered by `testlive_sync.py` which needs a second instance) is partly so — drop both, keep only the parsing/validation core (`ComplexTypeTool`, `AttributeValidationTool`) where unit tests pin malformed-input branches no live test constructs.

---

## 6. Layer 2 — PHP integration suite

Small and deliberate. Only for behaviour that needs model internals *and* cannot be asserted over HTTP.

`IntegrationTestCase` connects to a dedicated test database and wraps each test in a transaction rolled back in `tearDown`, so tests are order-independent — the property the current live suite lacks (see §8).

Targets:
- **Correlation behaviors** (`DefaultCorrelationBehavior` 522, `NoAclCorrelationBehavior` 337, `OnDemandCorrelationBehavior` 336; the last is 0 % in both suites). The valuable test is a **cross-strategy equivalence test**: the same fixture through all three must yield identical correlation sets. That is an assertion about agreement between implementations, which no single HTTP call can make.
- **Console shells** (~12,000 stmts, 1.22 % live). Shells are invoked via `Console/cake`; a thin harness that runs a shell with argv and captures output covers argument parsing, validation and output formatting without HTTP. `CLIShell` (2,165) and `SearchPerformanceShell` (1,500) first.
- **`CRUDComponent` / `IOCImportComponent`** — the latter is 0 % in both suites.

---

## 7. Layer 3 — Python live suite

Extends the existing `testlive_*.py` style, reusing PyMISP. Targets strictly what Layers 1–2 cannot reach: the deployed HTTP surface.

`tests/lib/misp_live.py` factors out what every existing script re-implements: base URL/key loading, admin+user connectors, org/user creation, and **guaranteed cleanup**.

New scripts, chosen from the measured dark map:
- `testlive_dashboards.py` — `DashboardsController` is **875 stmts, 0 % in both suites**; nothing in CI ever requests a dashboard. Covers widget listing, rendering, persistence, and the ACL scope on each.
- `testlive_workflows.py` — `Model/Workflow.php` is **1,084 stmts, 0 % in both**. Covers enabling a workflow, triggering it on event publish, and module execution end-to-end (complements U2, which tests modules in isolation).
- `testlive_export_formats.py` — drives `/events/restSearch` across every `returnFormat`, asserting non-empty well-formed output per format. Turns "ran once" into "runs for all formats".
- `testlive_console.py` — smoke-runs each console shell's `--help`/no-arg path via the API-adjacent CLI, for the shells Layer 2 does not deep-test.

Thin controller surfaces the endpoint histogram flagged (feeds, galaxies, servers, sightings, warninglists, taxonomies) get added to `testlive_comprehensive_local.py` rather than new files.

---

## 8. Test isolation contract (learned the hard way)

During this session, killing `testlive_security.py` mid-run left `Security.auth` set to `ShibbAuth.ApacheShibb` persisted in `config.php`. Every subsequent login returned a page with no login form, and the whole suite failed at `setUpClass` until the setting was removed by hand. The suite mutates global server settings and only restores them on a clean exit.

Requirements for all Layer 3 scripts:
1. Any script mutating a server setting records the prior value and restores it in `tearDownClass`, **and** registers an `atexit`/signal handler so an interrupted run still restores.
2. CI resets `config.php` from `config.default.php` between scripts.
3. A `tests/lib/reset_instance.py` helper restores a known-good state, runnable standalone.

This is not hypothetical robustness work — it cost real debugging time this session and it will bite anyone running the suite locally.

---

## 9. Coverage measurement and CI

The instrumentation is already built and proven this session:
- `build/coverage/covcollect.php` — set as `auto_prepend_file`; starts pcov per request/CLI invocation and dumps executed lines on shutdown, gated by a flag file so setup work is excluded. 2,617 captures merged cleanly.
- `merge_coverage.py` + `report.py` — merge captures and intersect with the PHPUnit clover statement map to emit unit / live / union and the per-subsystem table.

`.github/workflows/coverage.yml` runs the unit suite with `--coverage-clover`, then the live suite under instrumentation, then publishes the three numbers and uploads both artifacts. A **ratchet** fails the build if union coverage decreases.

Environment lessons folded into the CI/dev docs (each cost debugging time this session): the `zip` **binary** is required (not just `libzip-dev`); `app/files/scripts/*` submodules must be initialised or `proc_open` fails with `ENOENT`; PyMISP needs its own `pymisp/data/misp-objects` submodule plus the `fileobjects` extra; `MISP.baseurl` must match the in-container port; `MISP.host_org_id` can only be set after `User init`.

---

## 10. Sequencing — projected vs actual

| Phase | Contents | Projected | **Actual** |
|---|---|---:|---:|
| — | baseline (`ff132f4d`, filtered) | — | **19.67 %** |
| **P0** | bootstrap, phpunit.xml, PHPUnit 9.6, coverage CI | 19.67 % | **19.67 %** ✅ |
| **P1a** | widget + workflow-module conformance | ~25 % | **22.67 %** |
| **P1b** | export format contract | — | **23.28 %** |
| **P1c** | pure Lib/Tools behaviour + NavbarHelper | — | **23.41 %** |
| P2–P4 | remaining tranches | ~38 % | not yet run |

Subsystem movement from P1:

| Subsystem | Unit before | Unit after | Live |
|---|---:|---:|---:|
| `Lib/Dashboard` | 10.40 % | **44.26 %** | 0.00 % |
| `Model/WorkflowModules` | 0.00 % | **35.97 %** | 0.00 % |
| `Lib/Export` | 0.00 % | **34.02 %** | 20.58 % |
| `Lib/Tools` | 13.08 % | **15.36 %** | 22.27 % |
| `View/Helper` | 0.00 % | 0.28 % | 3.99 % |

### What P1 actually taught

**Reflection-only conformance adds no coverage.** The first cut of the widget
and module suites asserted contracts through `ReflectionClass` and moved the
number by *zero*: reading property defaults never executes a method body. All
of the gain came from *constructing* every widget and module and driving
`handler()`. The conformance assertions are still worth keeping — they catch a
malformed *new* widget, which no per-file test does — but they must be paired
with execution or they buy safety without coverage. This is the §11 risk
landing in practice, on the first attempt.

**Execution needs a support layer, not just stubs.** Three additions were
required before anything would run: a permissive `FakeModel` for
`ClassRegistry`-resolved collaborators, CakePHP's global helpers (`__()` above
all — MISP source calls it pervasively), and an autoloader over `app/Lib`,
because `App::uses()` is a no-op under the stubs.

**The zero-overlap prediction held exactly.** `Lib/Dashboard` and
`Model/WorkflowModules` were 0.00 % live, so their unit gains passed into the
union nearly 1:1 (+3.02 pp unit → +3.00 pp union).

**`View/Helper` resists layer 1.** `NavbarHelper` is 886 statements but
yielded 60: with injected collaborators returning empty, the per-controller
branches short-circuit. Driving them needs real `Acl`/`Html` helpers, which is
integration-layer work — this tranche should move to Layer 2.

**`AttachmentObjectBuilder` (804 stmts, 0 % in both) was deferred.** Its
`build()` requires real binary samples and installed object templates; it is a
fixtures project, not a quick win.

## 11. Risks

- **Conformance tests can be shallow.** Executing a class body is not asserting its behaviour. Mitigation: each family gets conformance *plus* behavioural tests for its heaviest members (§5.2 stage b). Report both file-touch and branch numbers so the shallowness is visible.
- **Golden files rot.** Mitigation: a single regeneration script, and golden diffs reviewed as part of the PR that changes behaviour.
- **Layer 2 is the risky layer.** A DB-backed PHP harness for CakePHP 2 is the piece most likely to over-run. It is sequenced last (P4) deliberately, and P1–P3 deliver ~33 % without it.
- **Fork divergence.** Optimising for coverage over upstream-mergeability means rebases get harder. Mitigation: keep P0 (the only change to existing files and to `composer.json`) as small and cherry-pickable as possible.

---

## 12. Open questions for review

1. Does the Layer 1/2/3 split rule match how you want to work, or should Layer 2 be dropped entirely (P1–P3 reach ~33 % without it)?
2. `Console/Command` is ~12,000 dark statements. Worth the Layer 2 harness, or accept it stays dark?
3. Coverage ratchet in CI: hard fail on decrease, or report-only?
