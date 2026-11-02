# ADR-0003: Verdicts of indexer-bench:compare

- Status: accepted
- Date: 2026-10-31

## Context

SPEC 5.7 shows a report with a verdict per metric and asks to flag as a regression any metric worse by more than
`--threshold` percent, failing with `--strict`. It does not say which metrics are better when lower, how to judge
a metric starting from zero or not available, nor how `--baseline`/`--candidate` tell a run ID from a label.

## Decision

- A reference made only of digits is a run ID; anything else is a label, read as the median of its comparable runs
  (ADR-0002). A label made only of digits can therefore not be compared by label: use another label.
- Every metric is a cost (time, memory, statements, rows read or written, temporary tables, lock waits, handler
  calls): lower is better. `index_rows` is the exception: it only has to stay the same and is shown as `changed`,
  never as improved or regressed; the `Output` line is what proves correctness.
- A change within ±threshold is `same` (noise). A change exactly at the threshold is still `same`.
- A metric not available on either side (`NULL`, e.g. SQL metrics of a parallel run, ADR-0001) is `n/a` and never
  fails the comparison.
- From 0 to more than 0 is a regression with no percentage (`+∞`): a slow query or a disk temporary table appearing
  is what the threshold is meant to catch.
- `--strict` fails on any regression and on `Output: DIFFER`. A missing snapshot only prints `not compared`.
- Fingerprint and work differences are warnings, never failures: the operator decides whether the comparison holds.

## Consequences

- Comparing runs measured in parallel with serial ones compares only the server counters and wall time.
- Partial runs given with `--ids` store only the number of IDs: two such runs with the same count but different
  IDs are not detected as different work.
