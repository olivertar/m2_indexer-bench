# ADR-0002: Series of runs, derived median and the --threads option

- Status: accepted
- Date: 2026-10-31

## Context

SPEC 5.2: `indexer-bench:run --repeat=N` "stores every repeat and the median", drops the first one with `--warmup`,
and accepts `--threads=N`.

- The run table (SPEC 5.5) has no column telling a measured run from an aggregate one.
- `MAGE_INDEXER_THREADS_COUNT` reaches `Magento\Indexer\Model\ProcessManager` as an `init_parameter`, fixed when
  `bin/magento` builds the object manager. Changing the environment inside the command does not change the worker
  count of the reindex it measures.

## Decision

- Every repeat is one row of `orangecat_indexer_bench_run` (`repeat_index` from 0, `warmup` = 1 for the first one
  with `--warmup`), with its metrics. Rows are written when the repeat ends, in one transaction: an interrupted run
  leaves nothing behind.
- The median is **derived**, never stored: `run` prints it, and `compare`/`history` compute it from the repeats of a
  label, leaving out warm-ups and failed runs. Values not available (`NULL`) are left out of a metric's median.
- A failed reindex is stored with `status = failed`, its error and no metrics, and stops the series: the index may be
  half built, so later repeats would not measure the same work.
- An indexer already `working` is not measured: `Indexer::reindexAll()` would return at once.
- `--threads=N` is a check, not a setting: the command refuses to run when `MAGE_INDEXER_THREADS_COUNT` (1 when
  unset) differs, and tells how to start it with the right value.

- Partial runs (`run-partial`) follow the same rules, without reset and without warm-up. The sample is a partial
  Fisher-Yates shuffle over the sorted universe driven by `Random\Randomizer::getInt()` on `Mt19937`: `shuffle()`
  and `pickArrayKeys()` may change their algorithm between PHP versions, which would give a baseline and a
  candidate different IDs for the same seed. A unit test pins the sample of seed 42.

## Consequences

- No aggregate rows to keep in sync; deleting a run updates the median by itself.
- Labels must be reused only for the same series: repeats of a label are what the median is taken over.
- Any `MAGE_INDEXER_THREADS_COUNT > 1` counts as a parallel run (ADR-0001), even for indexers that do not fork.
