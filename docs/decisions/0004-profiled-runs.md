# ADR-0004: Profiled runs (`--profile`)

- Status: accepted
- Date: 2026-11-01

## Context

SPEC 5.3 asks for an optional `--profile` that turns the Magento profiler on during the run, keeps the report in
`var/indexer-bench/<indexer>/<run_id>/`, uses the JSON output of `mage-os/module-profiler` when installed (a
suggested dependency, not a required one) and keeps profiled runs out of `compare`.

`mage-os/module-profiler` 1.0.0 activates its drivers in its `bootstrap.php`, from `MAGE_PROFILER` or
`var/profiler.flag`, before the ObjectManager exists. Activated that way it profiles the whole `bin/magento`
process: the fingerprint, the safeguards, every repeat and the storage of the results.

## Decision

- `RunProfiler` attaches the module's `Timeline` driver (the `json` output) at run time with
  `Profiler::add()` + `Profiler::enable()` right before the measured reindex, and after it calls
  `Profiler::disable()`, `Timeline::flush()` and `Profiler::reset()`. Only the reindex is profiled, one report per
  repeat. The module's plugins check `Profiler::isEnabled()` on every call, so they record from that moment on.
- The report is written by the module to its own directory (`var/log/profiler/`, `MAGE_PROFILER_REPORT_DIR`,
  pruned by its retention). The files that appeared there during the repeat are copied to
  `var/indexer-bench/<indexer>/<run_id>/` (`ProfileStore`), so they outlive that retention.
- Forked workers (`MAGE_INDEXER_THREADS_COUNT > 1`) inherit the driver and write their own report when they exit;
  they are copied too: one report per process.
- Without the module (`isAvailable()`: class present and `MageOS_Profiler` enabled) `--profile` warns and the run is
  measured without profiling, stored with `profiled = 0`. Magento's own `html`/`csvfile` outputs are not used:
  they are not machine-readable and `html` cannot be used from the CLI.
- A run is stored with `profiled = 1` when the driver was attached **or** the profiler was already on for the
  process (`MAGE_PROFILER` or `var/profiler.flag` when `bin/magento` started): either way the run carries the
  profiler's overhead. In that second case the command warns, does not attach a second driver and does not copy
  any report; the module writes its own at the end of the process.
- `profiled = 1` runs are left out of label medians in `compare` and `history` and refused as a single run in
  `compare` (ADR-0002, ADR-0003). `history` and `export` still list them, marked.

## Consequences

- Profiled metrics are stored, so the overhead can be measured: `history` shows profiled and plain runs side by side.
- `mage-os/module-profiler` writes SQL bound values to disk with `MAGE_PROFILER_SQL=query`; the copies under
  `var/indexer-bench/` must be treated like the originals.
- A report path is `var/indexer-bench/<indexer>/<run_id>/<report>.json`, next to the snapshots of a label made only
  of digits (`var/indexer-bench/<indexer>/<label>/*.tsv`). They do not collide (different extensions), and such a
  label cannot be compared by label anyway (ADR-0003).
