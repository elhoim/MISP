# Context

Glossary for MISP's test suites. Terms only — no implementation detail, no plans.

## Test layers

**Layer 1 / unit** — a test that runs with no database, no Redis, no HTTP and no network. It may read fixtures from disk.

**Layer 2 / integration** — a test that may use the database and Redis, and drives real models directly, but never goes through HTTP or the authentication stack.

**Layer 3 / live** — a test that exercises the deployed system over HTTP against a running instance.

**Capability boundary** — the rule that assigns a test to a layer: a behaviour is tested in the *highest-numbered layer that can assert it, and no higher*. Correlation strategies need model internals, so they are Layer 2, not Layer 3. Export formats are pure transforms, so they are Layer 1 even though the API also exercises them.

## Kinds of test

**Conformance test** — a single data-driven test over a *family* of similar units (all widgets, all workflow modules, all export formats) asserting the contract every member must satisfy. Its distinguishing value is that it fails when someone *adds* a malformed member.

**Characterization test** — a test that records what the code does *today*, without claiming it is correct. Its purpose is to detect unintended change during refactoring.

**Specification test** — a test that asserts intended behaviour. It may fail against current code, and that failure is meaningful.

**Golden snapshot** — a committed, normalised record of a system response used as the expected value in a characterization test. Normalised means volatile fields (ids, timestamps, uuids) are stripped so a diff shows semantic change only.

**KNOWN-DEFECT** — an annotation on a golden snapshot recording that the captured output is a bug being preserved for refactor safety, not intended behaviour. Each one is cross-referenced to a specification test asserting the desired behaviour, which is skipped while the defect stands.

## Coverage

**Statement map** — the set of executable statement lines, per file, that coverage is measured against. Derived from the PHPUnit clover report and shared by all layers so their figures are comparable.

**Unit coverage** — statements executed by the Layer 1 and Layer 2 suites.

**Live coverage** — statements executed by the Layer 3 suite, attributed by instrumenting the PHP runtime per request.

**Union coverage** — statements executed by *either* suite, counted once. Not the sum: a line both suites reach counts once.

**Ratchet** — a build gate that fails when union coverage falls below a recorded floor. The floor is a number the continuous-integration environment can itself reproduce.

**Public-method reach** — of a class's public methods, the proportion having at least one test that exercises them. Distinct from statement coverage, and the more meaningful signal for refactoring safety: a percentage of lines says nothing about whether a given method is pinned.

**Exercised method** — a public method that at least one test executes. Its complement, a method no test executes, is either dead or reachable only by an untested path; which of the two is a question, not a conclusion.

## Instance state

**Isolation contract** — the requirement that any live test mutating global server settings restores them on *any* exit, including interruption. Named because its absence has broken subsequent runs in ways whose symptoms point nowhere near the cause.
