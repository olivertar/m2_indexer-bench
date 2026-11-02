# Changelog

All notable changes to `orangecat/module-indexer-bench` are documented here.

## [Unreleased]

## [0.4.0] - 2026-11-02

### Changed (packaging)
- MIT licence; published on GitHub (`olivertar/m2_indexer-bench`). Requires `orangecat/module-indexer-boost` ^0.4.

### Added
- Admin page *System > Tools > Indexer Benchmarks* (ACL `Orangecat_IndexerBench::view`): overview per indexer
  (labels and last runs), run detail with a Markdown download, and compare. Read-only, GET only; it shows the runs of
  this installation's database (`BenchmarkOverview`, `RunDetail`, `CompareView`).
- `RunReader::getIndexers()` and `getLabels()`; `StoredRun::$error` holds the error of a failed run.

### Changed
- `RunExporter::describeWork()`, `describeStatus()` and `describeEnvironment()` (was the private `environment()`)
  are public, shared with the admin page.
- Requires `magento/module-backend`.

## [0.3.0] - 2026-11-02

### Added
- `compare --format=markdown`: the report as Markdown, for `docs/benchmarks/` (SPEC 6.5).

### Fixed
- `run` and `run-partial` measure in the adminhtml area with its DI configuration, as `bin/magento indexer:reindex`
  does (`IndexerArea`). Without it `catalogrule_rule` failed with "Area code is not set" and other indexers ran
  without their adminhtml plugins.
- `history`: the trend of a label compares it with the previous label of the same kind only (full or partial
  reindex); it compared partial runs with full ones (e.g. "+3329 %").

## [0.2.0] - 2026-11-01

### Added
- Module skeleton (`Orangecat_IndexerBench`, depends on `Orangecat_IndexerBoost`).
- Tables `orangecat_indexer_bench_run`, `orangecat_indexer_bench_metric` (composite key, cascade on run delete) and
  `orangecat_indexer_bench_snapshot`.
- `IndexerAdapterInterface` (SPEC 5.1).
- `CanonicalDumper`, `RowSerializer`, `TableDump`: canonical streamed dump of an index table with MD5, keyset
  pagination over a validated unique NOT NULL key, read-only through `ReadOnlyConnection`.
- `SnapshotDiff`, `DiffLine`: first differences between two dumps, merged by key.
- Integration tests of the dumper with small fixture tables (physical order, NULL, escapes, multibyte, batches, diff).
- Adapters for `catalog_category_product`, `catalog_product_price` (dimension tables of the active mode),
  `catalogrule_rule`, `catalogrule_product`, `catalog_product_attribute`, `cataloginventory_stock` and `inventory`;
  `AdapterPool`, `TableAdapter`, `ProductIdUniverse`.
- Dumper leaves out auto-increment surrogate IDs and accepts nullable key columns while they hold no NULL.
- Integration tests: every adapter's tables exist and dump with their canonical key; price tables follow the
  dimensions mode; inventory with only the default stock has nothing to dump.
- Metrics: `QueryCounter` + `QueryCounterPlugin` (around `Mysql::query()`, active only while measuring),
  `GlobalStatusReader`, `ProcessMemory`, `MetricCollector`. SQL metrics `NULL` in parallel runs; `rows_*` `NULL`
  where `Innodb_rows_*` do not exist (MariaDB); `handler_*` metrics added. ADR-0001.
- Environment fingerprint (`EnvironmentFingerprint`, `HostInfo`, `GitCommitReader`) reusing the IndexerBoost
  database and catalog providers, and `FingerprintComparator` for the differences that invalidate a comparison.
- Safeguards: `ProductionGuard` (production mode needs host and database in `indexer_bench/allowed_hosts`),
  `QuietnessCheck` (running `index` cron jobs, pending changelog of the measured indexer) and `Safeguards`
  (warnings, or abort with `--strict`; traffic warning). Depends on `Magento_Cron`.
- `indexer-bench:run` (`RunCommand`, `SeriesRunner`, `RunRequest`, `RunRecord`, `RunOutcome`, `Median`,
  `ResourceModel\BenchRun`): series of measured full reindexes with `--repeat`, `--warmup`, `--skip-reset`,
  `--mode`, `--notes`, `--strict` and `--threads` (checked against `MAGE_INDEXER_THREADS_COUNT`). One run row per
  repeat; median derived, not stored. ADR-0002.
- `indexer-bench:run-partial` (`RunPartialCommand`, `IdSampler`): `reindexList()` of the adapter's partial indexer
  over a reproducible sample (`-c`, `--seed`, default 42) or explicit `--ids`, stored as `kind = partial` with
  `sample_size` and `seed`. Shared flow of both commands in `AbstractSeriesCommand`; the runner is `SeriesRunner`.
- `indexer-bench:snapshot` (`SnapshotCommand`, `SnapshotTaker`, `SnapshotComparer`, `SnapshotResult`,
  `SnapshotComparison`, `TableComparison`, `ResourceModel\BenchSnapshot`): canonical dumps per label under
  `var/indexer-bench/<indexer>/<label>/`, checksums per table and global, `--diff` with `MATCH`/`DIFFER` (exit 1)
  and the first differing rows. Guarded by `ProductionGuard`.
- `indexer-bench:compare` (`CompareCommand`, `RunSelector`, `RunSelection`, `MetricComparator`, `MetricComparison`,
  `RunReader`, `StoredRun`): SPEC 5.7 report of a run or label median against another, with fingerprint and work
  warnings, the output verdict from the snapshots, `--threshold` and `--strict`. ADR-0003.
- `indexer-bench:history` (`HistoryCommand`, `HistoryBuilder`, `History`, `LabelSummary`): last `--limit` runs of
  an indexer with one `--metric` (unit suffix optional: `wall_time` is `wall_time_s`), and per label the median,
  min and max over its comparable runs with the trend from the previous label.
- `indexer-bench:export` (`ExportCommand`, `RunExporter`): one run (`--run`, the last one by default) as Markdown
  ready for `docs/benchmarks/` or as JSON with raw values and the full fingerprint; `--output` writes a file.
- `ValueFormatter` and `MetricOrder` shared by every report; `RunReader::getRecentRuns()` and `getLatestRun()`.
- `--profile` on `indexer-bench:run` and `run-partial` (`RunProfiler`, `ProfileStore`): each repeat recorded by the
  `mage-os/module-profiler` JSON driver attached around the reindex only, reports copied to
  `var/indexer-bench/<indexer>/<run_id>/`, run stored with `profiled = 1`. Without the module it warns and measures
  without profiling; with the profiler already on (`MAGE_PROFILER`) every run is stored as profiled. ADR-0004.
- `bin/bench-magento`: `bin/magento` against a benchmark copy of the database with its own cache
  (`MAGENTO_DC__OVERRIDE`). README "Benchmark database".
- Phase B validation on a `medium` catalog (42 040 products): two baselines in a row give `MATCH` and -0.3 % wall
  time for `catalog_product_price` and `catalog_category_product`; report in `docs/benchmarks/` of the repository.
- ADR-0005 (canonical dump keys), ADR-0006 (separate benchmark database), ADR-0007 (staging and inventory limits);
  README "Decisions" and "Known limits".

### Fixed
- The partial sample universe takes `DISTINCT entity_id`, so a product with several staging versions (Adobe
  Commerce `row_id`) is not sampled twice.

