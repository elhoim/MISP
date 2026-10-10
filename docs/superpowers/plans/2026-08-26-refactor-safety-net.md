# Refactor safety net — plan

**Goal:** a developer reviewing a PR can trust the suite instead of testing by hand, and the god-models can be restructured without fear.

**Not a goal:** faster CI. Latency was explicitly deprioritised; certainty wins.

**Targets:** `Model/Event.php` + `Model/Server.php` (split), the correlation strategies, and the `restSearch` response layer.

**Success metric:** public-method reach on the target classes. Union coverage stays only as a ratchet. "Event is 60 % covered" tells a developer nothing about whether their refactor is safe; "112 of 125 public methods are pinned" does.

## Measured starting point

| Class | Public methods | Exercised by live suite | Never executed |
|---|---:|---:|---:|
| `Model/Event.php` | 125 | 70 | 55 |
| `Model/Server.php` | 143 | 74 | 69 |
| **Total** | **268** | **144 (54 %)** | **124 (46 %)** |

Highest-traffic methods, to pin first: `fetchEvent` (566 lines), `_add` (312), `_edit` (281), `processFreeTextData` (148), `quickDelete` (100), `restSearch` (87); `push` (234), `pull` (129), `update` (70), `getEventIndexFromServer` (73).

Largest never executed: `fetchPaginatedObjects` (451), `processModuleResultsData` (355), `fetchPaginatedAttributes` (249); `runTestSyncRules` (88), `testNDJSONLogPath` (76).

## Approach

Characterization (golden-master) for Event/Server — record what the code does today so refactors are detected. Specification tests for correlation, where correctness genuinely matters and equivalence assertions already exist. See ADR 0002 for how known defects are handled.

Test data: model factories building real rows, wrapped in a transaction rolled back per test. All 105 tables are InnoDB, so rollback is sound. No new dependency, no fixture framework.

## Rounds

**R1 — harness.** Extend `IntegrationTestCase` with factories (`anEvent()`, `anAttribute()`, `aServer()`) and a snapshot helper: normalise (strip ids/timestamps/uuids), compare against a committed file, regenerate only under an explicit `--update` flag. Never auto-regenerate — the reviewable diff *is* the safety mechanism.

**R2 — `restSearch` snapshots.** One generic seam at `AppController.php:1523` serves every controller, so this is the highest-leverage single item. Matrix of scope × `returnFormat` × filter. Annotate the `returnFormat=text` snapshot `KNOWN-DEFECT`.

**R3 — Event.** Pin the 70 exercised methods, heaviest first.

**R4 — Server.** Pin the 74 exercised methods, sync paths first (`push`, `pull`, `update`).

**R5 — dead-surface triage.** The 124 never-executed methods are the plan's most interesting finding: 46 % of the god-models' public surface. Each is either dead code (delete — which shrinks the refactor rather than testing it), admin-only (pin via Layer 3), or reachable only by an untested path (pin via Layer 2). Triage before writing tests; deleting beats pinning.

**R6 — promote the gate.** Method-reach reported from R1, kept report-only until the number stabilises, then made a failing gate. A floor that moves under you produces noise, not certainty.

## Gates

Snapshot diffs and live-suite failures gate immediately. Union ratchet stays at the CI-reproducible floor (ADR 0003). Method reach is report-only until R6.

## Sequencing note

R2 delivers most of the "no manual testing" value on its own, because the REST response layer is what a reviewer would otherwise poke by hand. R5 may reduce R3/R4 substantially — triage before writing tests against methods that should not exist.
