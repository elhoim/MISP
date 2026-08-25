#!/usr/bin/env python3
"""Report unit / live / union coverage against a clover statement map.

The clover report from PHPUnit supplies both the denominator (every
executable statement line) and the unit numerator. The merged pcov map
supplies the live numerator. Intersecting the two makes the suites
directly comparable, which is the only way to see where they overlap.
"""
from __future__ import annotations

import argparse
import collections
import json
import xml.etree.ElementTree as ET


def load_clover(path: str) -> tuple[dict[str, set[int]], dict[str, set[int]]]:
    root = ET.parse(path).getroot()
    stmt: dict[str, set[int]] = {}
    unit: dict[str, set[int]] = {}
    for node in root.iter("file"):
        raw = node.get("name") or node.get("path") or ""
        rel = raw.split("/app/")[-1] if "/app/" in raw else raw
        lines, covered = set(), set()
        for line in node.findall("line"):
            num = int(line.get("num"))
            lines.add(num)
            if int(line.get("count", 0)) > 0:
                covered.add(num)
        stmt[rel], unit[rel] = lines, covered
    return stmt, unit


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("clover", help="clover.xml from the PHPUnit run")
    ap.add_argument("merged", help="merged.json from merge_coverage.py")
    ap.add_argument("--json", dest="json_out", help="write the summary here")
    ap.add_argument("--min-union", type=float, default=None,
                    help="exit non-zero if union coverage is below this percentage")
    args = ap.parse_args()

    stmt, unit = load_clover(args.clover)
    with open(args.merged) as handle:
        live = {path: set(lines) for path, lines in json.load(handle).items()}

    inter = {path: live.get(path, set()) & stmt[path] for path in stmt}
    total = sum(len(v) for v in stmt.values())
    n_unit = sum(len(v) for v in unit.values())
    n_live = sum(len(v) for v in inter.values())
    n_union = sum(len(unit[p] | inter[p]) for p in stmt)
    n_both = sum(len(unit[p] & inter[p]) for p in stmt)

    def pct(n: int) -> float:
        return round(100.0 * n / total, 2) if total else 0.0

    print(f"statements  {total}")
    print(f"unit        {n_unit:7d}  {pct(n_unit):6.2f}%")
    print(f"live        {n_live:7d}  {pct(n_live):6.2f}%")
    print(f"union       {n_union:7d}  {pct(n_union):6.2f}%")
    print(f"both        {n_both:7d}")

    agg: dict[str, list[int]] = collections.defaultdict(lambda: [0, 0, 0])
    for path in stmt:
        parts = path.split("/")
        key = "/".join(parts[:2]) if len(parts) > 2 else parts[0]
        agg[key][0] += len(stmt[path])
        agg[key][1] += len(unit[path])
        agg[key][2] += len(inter[path])

    print(f"\n{'subsystem':30s} {'stmts':>7s} {'unit%':>7s} {'live%':>7s}")
    for key, (s, u, i) in sorted(agg.items(), key=lambda kv: -kv[1][0]):
        if s < 400:
            continue
        print(f"{key:30s} {s:7d} {100.0 * u / s:6.2f}% {100.0 * i / s:6.2f}%")

    summary = {
        "statements": total,
        "unit_pct": pct(n_unit),
        "live_pct": pct(n_live),
        "union_pct": pct(n_union),
        "both_lines": n_both,
    }
    if args.json_out:
        with open(args.json_out, "w") as handle:
            json.dump(summary, handle, indent=2)

    if args.min_union is not None and summary["union_pct"] < args.min_union:
        print(f"\nFAIL: union {summary['union_pct']}% < required {args.min_union}%")
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
