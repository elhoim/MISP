# Widening the safety net — plan

Successor to `2026-08-26-refactor-safety-net.md`, which delivered R1–R5. That plan
asked "can a reviewer trust the suite instead of testing by hand?". This one asks a
sharper question, because the answer to the first turned out to be measurable:

**Where is the next defect?**

The previous round wrote roughly 1,900 tests and found, as a side effect, about ten
real bugs — enrichment silently forced synchronous since 2022, galaxy-cluster push
dead on the default configuration, `intval('1e3')` quietly pulling event 1000, an
`'OR'` key overwritten in an array literal so a push filter had never once run. That
hit rate is the reason this round optimises for defect yield first and coverage
volume second.

**Not a goal:** a higher percentage for its own sake. See ADR 0004.

## Measured starting point (2026-08-27 @ 279e399b64)

Unit + integration only; the live suite is not in these numbers.

| | Statements | Covered | % |
|---|---:|---:|---:|
| Whole tree (533 files, filtered) | 115,336 | 13,289 | 11.5 % |
| `Model/` top-level | 40,025 | 6,650 | 16.6 % |
| `Controller/` top-level | 32,774 | 0 | 0.0 % † |
| `Console/Command/` | 12,323 | 0 | 0.0 % |
| `Lib/Tools/` | 8,660 | 1,515 | 17.5 % |

† **An artifact, not a fact.** Neither PHP harness dispatches HTTP; the live Python
suite does reach controllers. Any controller work must wait on a measured live run.

Public-method reach on the god-models:

| Model | Public | Touched | File cov |
|---|---:|---:|---:|
| `Server.php` | 143 | 60 (42 %) | 59.7 % |
| `MispAttribute.php` | 87 | 28 (32 %) | 22.0 % |
| `Event.php` | 125 | 25 (20 %) | 16.3 % |
| `MispObject.php` | 30 | **2 (7 %)** | **0.8 %** |

## Rounds

**W0 — honest denominator.** Exclude permanently-dark code from the whitelist in its
own commit: `AppModel::updateDatabase` (1,435 stmts of append-only migration ladder,
each arm run once per instance lifetime), `updateMISP`, `runUpdates`,
`SearchPerformanceShell`, `TrainingShell`, `DevShell`, `BenchmarkTool`, `Ls22Shell` —
about 4,500 statements, 3.9 % of the tree. The gate counts lines, so this cannot move it; only
the reported percentage changes, which is precisely why the commit must stand alone
and say so.

**W1 — the correlation engines.** `NoAclCorrelationBehavior` (321 stmts) and
`OnDemandCorrelationBehavior` (317) are dark because `Correlation.php:1328` selects
the engine from `Configure::read('MISP.correlation_engine')` and the existing
`CorrelationEngineTest` only ever exercises the default. Flipping that key and
re-running the same equivalence assertions covers 638 statements. One config key, no
seam, no new fixtures — the cheapest large win available, and a genuine correctness
test: the three engines are supposed to agree.

**W2 — `MispObject`.** 0.8 % covered on 1,151 statements, and structurally the twin
of `Event`, whose equivalents yielded three defects. Read path first
(`fetchObjects`, `buildFilterConditions`, `fetchObjectSimple`, `findSimilarObjects`,
~350 stmts), then the write path (`deltaMerge`, `captureObject`, `editObject`,
`reviseObject`, `resolveUpdatedTemplate`, ~470 stmts). Object templates are already
on disk under `app/files/misp-objects`. This is where the next bug most likely is.

**W3 — `MispAttribute`.** `fetchAttributes` (247), `restSearch` (110),
`set_filter_tags` (110), `buildFilterConditions` (67), the `captureAttribute` /
`editAttribute` / `afterSave` write path. Note the `set_filter_*` family is reached
by string dispatch, so driving `restSearch` with the right parameters covers it
transitively — as `EventRestSearchCharacterizationTest` already demonstrates.

**W4 — unit-layer bulk, parallelisable.** No seams, patterns already established:
the pure graph and export tools (`EventGraphTool`, `CorrelationGraphTool`,
`EventTimelineTool`, `DistributionGraphTool`, `XMLConverterTool`, `IOCExportTool`,
`ServerSettingGroups`, and the five dark `Lib/Export` classes, ~1,650 stmts);
`NavbarHelper` (837 stmts behind one public `build()`, every branch a
`Configure::read` or an `Acl` call, both stubbable) plus the 21 smaller helpers
(~500); `RestResponseComponent` (1,464) and `ACLComponent` (288), which need one
component-construction helper in `Test/Support/`. Mechanical enough to fan out
across agents.

**W5 — extend the sync seam.** `ServerSyncTool $serverSync = null` is now proven on
`push`/`pull`/`syncProposals` and unblocked 38 tests. Extend the same pattern to
`runTestSyncRules`, `runConnectionTest`, `getRemoteUser`, `serverEventsOverlap`,
`syncGalaxyClusters`, `update` — about 280 statements on a pattern that already has
upstream precedent at `Server.php:3254`.

**W6 — controllers, only after measurement.** Blocked on a live-suite coverage run.
Write nothing here until per-controller live numbers exist.

**W7 — a second instance.** The only way to reach the *capture* half of sync: a
loopback peer always holds an equal copy, so skip-on-equal keeps every transfer at
zero by construction. `testlive_collection_sync.py` already has a
`REMOTE_HOST`/`REMOTE_AUTH` mode built for this. Deferred deliberately — new
containers and two databases that must not pollute each other, and this project has
already shown what shared-state mistakes cost.

## Not to be tested

Reachable, but testing them is ratchet inflation or actively unsafe:
the migration ladder (W0); `SearchPerformanceShell` (a benchmark harness);
`TrainingShell` (destructive provisioning); `Ls22Shell` (operator-driven remote
orchestration, see below); `LiveShell` (**`cake Live` with no
subcommand takes the instance offline** — never invoke); `TestLdapAuth.php`, which is
itself a shipped test double.

`Ls22Shell` (635 stmts) was investigated as a deletion candidate and **cleared**. Its
name is only the year it was first written: `git log --follow` shows it updated for a
fresh exercise in 2022, 2023, 2024 and 2025, most recently `817a5bdb57`
"chg: [console:ls-shell] Updated for LS25" (2025-05-12). It is a fleet-orchestration
shell that drives *other* MISP instances over their REST APIs from an operator-supplied
`instances.csv`, so it has no in-tree callers by design and can never be unit-tested as
written. Zero coverage was not evidence of death — for a CakePHP shell invoked as
`cake Ls22 <task>` from an operator's shell, the absence of call sites is expected and
meaningless. It joins the W0 exclusion list instead.

## Blocked on infrastructure

Named so nobody re-derives them: misp-modules (~560 stmts of enrichment), ZeroMQ
(`PubSubTool`, 87), an S3 endpoint (`AWSS3Client`, 88), the stix2 python toolchain
(~276), an MTA (~230 of mail paths), binary sample fixtures (~200), a resque worker
runtime (~656), and the six auth plugins (1,498) — of which `CertAuth` and
`ShibbAuth` read only `$_SERVER` and *are* unit-testable with a request double.

## Gates

Covered lines, absolute, currently 29,600. Method reach reported, not gated, until
stable across ~10 merges. Enforced on the fork; offered upstream report-only.
See ADR 0004.

## Defect handling

Unchanged from ADR 0002 — pin with `KNOWN-DEFECT`, open a separate upstream PR, keep
the test asserting today's behaviour. New in this round: a register in
`docs/testing.md` listing every pinned defect and its PR, so what is known-broken can
be read off one table instead of grepped for.
