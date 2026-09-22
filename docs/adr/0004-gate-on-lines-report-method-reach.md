# 4. Gate on covered lines; report method reach

Date: 2026-08-27

## Status

Accepted

## Context

The refactor-safety-net plan named public-method reach as its success metric, on the grounds that "Event is 60 % covered" tells a developer nothing about whether their refactor is safe, whereas "112 of 125 public methods are pinned" does. Union coverage was kept only as a ratchet.

Two things have since been learned.

The first is that a coverage *percentage* is a bad gate regardless of what it measures. `app/phpunit.xml` sets `processUncoveredFiles="false"`, so the statement universe contains only the files a suite happened to load. Adding a characterization test for a large, under-covered file therefore inflates the denominator: at 90e6d4d9 the denominator rose by 2,324 statements while the numerator rose by 35, and the gate went red on a commit that added tests and broke nothing. The ratchet was gating on test loading, not on coverage.

The second is that method reach moves for reasons unrelated to test quality. Adding a public method lowers reach even when the new method is trivial, so a reach gate taxes ordinary feature work. Reach is the better metric and the worse gate.

## Decision

The gate counts **covered lines**, an absolute number that no denominator change can move. Method reach is computed and reported, but does not fail a build.

Reach is promoted to a gate only once the number has been stable across roughly ten merges — a floor that moves under you produces noise, not certainty.

The gate is enforced on the fork, where the merge button is ours. Upstream is offered the same job in report-only mode. A gate we cannot enforce is not a gate, and demanding one is how an otherwise useful contribution gets refused on politics rather than merit.

## Consequences

Excluding permanently-dark code from the whitelist — the schema-migration ladder in `AppModel::updateDatabase`, benchmark and training shells — changes the reported percentage but cannot move the line gate. Those exclusions are therefore safe to make, and must be made in their own commit so they are not mistaken for progress.

A line floor does not notice a file whose coverage falls while another's rises. That is accepted: the snapshot diffs and the live suite catch behavioural regressions, and the floor exists to stop drift, not to police distribution.

Two numbers now travel together — lines for the gate, reach for the reader. The pairing is deliberate: the first is what fails a build, the second is what tells a reviewer whether a refactor is safe.
