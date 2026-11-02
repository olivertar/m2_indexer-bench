# Orangecat_IndexerBench

Reproducible benchmark of Magento indexers with run history and canonical output verification (phase B of the
Indexer Boost SPEC). **Development and staging only**: install it as a `require-dev` dependency and never run its
commands against production.

## Installation

Requires `Orangecat_IndexerBoost` ^0.4 (the environment fingerprint reuses its doctor providers). Install it as a
development dependency, so it never reaches production; both repositories have to be declared because the packages
are not on Packagist:

```bash
composer config repositories.m2_indexer-boost vcs https://github.com/olivertar/m2_indexer-boost.git
composer config repositories.m2_indexer-bench vcs https://github.com/olivertar/m2_indexer-bench.git
composer require --dev orangecat/module-indexer-bench:^0.4
bin/magento module:enable Orangecat_IndexerBench
bin/magento setup:upgrade
```

## Benchmark database

Measure on a copy, never on the database the store or the team works with: every run invalidates and rebuilds
indexes. `bin/bench-magento` runs `bin/magento` against a copy with its own cache:

```bash
# once: clone the database (as a user allowed to create it), keep search indexes apart
mariadb -e 'CREATE DATABASE magento_bench; GRANT ALL ON magento_bench.* TO magento@"%"'
mariadb-dump --single-transaction --routines --triggers magento | mariadb magento_bench
mariadb magento_bench -e "UPDATE core_config_data SET value = 'magento2_bench'
    WHERE path = 'catalog/search/opensearch_index_prefix'"

# a bigger catalog, if needed, then a full reindex so the changelogs are empty
app/code/Orangecat/IndexerBench/bin/bench-magento setup:performance:generate-fixtures \
    setup/performance-toolkit/profiles/ce/medium.xml
app/code/Orangecat/IndexerBench/bin/bench-magento indexer:reindex

app/code/Orangecat/IndexerBench/bin/bench-magento indexer-bench:run -i catalog_product_price -l core --repeat=4 --warmup
```

- The script sets `MAGENTO_DC__OVERRIDE` with the `db` and `cache` sections of `app/etc/env.php`, changing the
  database (`BENCH_DB`, default `magento_bench`), the cache prefix (`BENCH_CACHE_PREFIX`, `bch_`) and the
  Redis/Valkey databases (`BENCH_CACHE_DB`, 2, and 3 for the page cache). The flat `MAGENTO_DC_DB__...` variables
  are not enough: Magento reads the connection as one array, which they do not override.
- Check the target before measuring: a value only the copy has (here the OpenSearch prefix) must come back,
  `bench-magento config:show catalog/search/opensearch_index_prefix`.
- On a database with the sample data, `generate-fixtures` stops at the tax rates step ("The tax rate can't be removed
  because it exists in a tax rule") after generating products, categories, websites, catalog rules and customers;
  orders are not generated. The catalog indexers do not need them.
- Results of the Phase B validation: [phase-b-validation-2026-11-01.md](https://github.com/olivertar/m2_indexer-boost/blob/main/docs/benchmarks/phase-b-validation-2026-11-01.md).

## Storage

| Table | Content |
|---|---|
| `orangecat_indexer_bench_run` | One row per run or repeat: indexer, label, `baseline`/`candidate`, `full`/`partial`, sample size and seed, repeat index, `warmup` and `profiled` flags, UTC start and end, status, error, fingerprint (JSON), notes |
| `orangecat_indexer_bench_metric` | Metrics per run (`DECIMAL(20,6)`; `NULL` = not available) |
| `orangecat_indexer_bench_snapshot` | Row count and MD5 per dumped table, `*` for the global checksum |

`warmup` and `profiled` extend SPEC 5.5: warm-up repeats are left out of medians and profiled runs out of
comparisons. Full dumps go to `var/indexer-bench/<indexer>/<label>/`, never to the database.

## Canonical dump

`CanonicalDumper` turns an index table into bytes that only depend on its data, so a core and an optimized indexer
can be compared exactly:

- **Surrogate IDs left out:** auto-increment columns outside the canonical key (`catalogrule_product.rule_product_id`,
  `catalogrule_product_price.rule_product_price_id`) number rows in insert order, so they are not dumped.
- **Streaming by key:** the table is read in batches (`batchSize`, 10 000 rows) ordered by the adapter's canonical
  key, each batch starting after the last key read (keyset pagination written as `(a > ?) OR (a = ? AND b > ?)`,
  which every server version runs on the key index). The table is never loaded whole. The key must cover a unique
  index of the table and hold no NULL, otherwise the dump is refused (rows could be skipped or repeated). Nullable key
  columns are accepted while they hold no NULL: the core declares some in unique keys
  (`catalogrule_product.customer_group_id`).
- **Format:** a header `#col1\tcol2...` then one line per row, tab-separated: key columns first, the others by name,
  `\N` for NULL, and `\\`, `\t`, `\n`, `\r` escaped. Values are written as the server returns them.
- **Checksum:** MD5 of the header and every line, computed while streaming; the dump file is optional.
- **Read-only:** queries go through `ReadOnlyConnection` from `Orangecat_IndexerBoost`.

`SnapshotDiff` merges two dumps of the same table by key in one pass and returns the first N differences: `missing`
(only in the baseline), `extra` (only in the candidate), `changed`, and `columns` when the headers differ. Keys are
compared as numbers when both values are numeric, as the database orders integer keys; text keys follow byte order,
which can differ from a case-insensitive collation.

## Adapters

| Indexer | Live tables (canonical order) | Partial path |
|---|---|---|
| `catalog_category_product` | `catalog_category_product_index_store<id>` per store view, named by the core `TableMaintainer` (`category_id, product_id, store_id`); the base table stays empty in 2.4 | `catalog_product_category` by product IDs |
| `catalog_product_price` | price tables of the active `dimensions_mode`, named by the core `DimensionCollectionFactory` + `TableMaintainer` (`entity_id, customer_group_id, website_id`), plus `catalog_product_index_tier_price` and `catalog_product_index_website` (`website_id`) | itself |
| `catalogrule_rule`, `catalogrule_product` | `catalogrule_product` (its unique key), `catalogrule_product_price` (`rule_date, website_id, customer_group_id, product_id`), `catalogrule_group_website` (`rule_id, customer_group_id, website_id`); shared index | `catalogrule_product` by product IDs |
| `catalog_product_attribute` | `catalog_product_index_eav`, `catalog_product_index_eav_decimal` (`entity_id, attribute_id, store_id, value, source_id`) | itself |
| `cataloginventory_stock` | `cataloginventory_stock_status` (`product_id, website_id, stock_id`) | itself |
| `inventory` (MSI) | `inventory_stock_<stock id>` base tables (`sku`), found by the MSI naming so MSI stays optional | none |

The partial sample universe is every product ID (`catalog_product_entity.entity_id`). With only the default stock,
`inventory_stock_1` is a view over `cataloginventory_stock_status` that the inventory indexer does not write: the
`inventory` adapter has no table to dump and the commands warn about it. Adobe Commerce staging (`row_id`): index
tables are flat on both editions, so the dump needs no version filter, and the sample universe takes
`DISTINCT entity_id`; nothing else was tested outside Open Source (ADR-0007).

Adapters are declared in `di.xml` (`AdapterPool`); tables with a fixed layout use `TableAdapter` virtual types.

## Metrics

| Metric | Source |
|---|---|
| `wall_time_s` | monotonic clock around the reindex |
| `peak_memory_mb` | `memory_get_peak_usage(true)` of the parent process, peak reset before the run |
| `sql_queries`, `sql_time_s`, `slow_queries` | `QueryCounterPlugin` around `Mysql::query()`, this process only; slow from `slowQuerySeconds` (`di.xml`, 1 s). `NULL` in parallel runs |
| `rows_read`, `rows_inserted`, `rows_updated`, `rows_deleted` | increase of `Innodb_rows_*`; `NULL` on MariaDB, which lacks them |
| `handler_read`, `handler_write`, `handler_update`, `handler_delete` | increase of `Handler_read_*` (summed), `Handler_write`, `Handler_update`, `Handler_delete`; engine calls, temporary tables included |
| `tmp_tables`, `tmp_disk_tables` | `Created_tmp_tables`, `Created_tmp_disk_tables` |
| `row_lock_waits`, `row_lock_time_ms` | `Innodb_row_lock_waits`, `Innodb_row_lock_time` |
| `handler_read_rnd_next` | `Handler_read_rnd_next` (table scans) |
| `index_rows` | `COUNT(*)` of the adapter tables after the run |

`NULL` means not available. Server counters include every connection: measure on an environment without traffic.
See ADR-0001 for parallel runs and MariaDB.

## Fingerprint

Every run stores the environment it was measured in (`EnvironmentFingerprint`, JSON):

| Key | Content |
|---|---|
| `magento` | version and edition |
| `php` | PHP version |
| `database` | engine (MySQL/MariaDB), version and the variables of the `MYSQL_VARS` doctor check plus `in_predicate_conversion_threshold` and `optimizer_use_condition_selectivity` |
| `catalog` | the `CATALOG_SIZE` doctor figures (products by type, categories, stores, websites, customer groups, rules) |
| `price_dimensions_mode`, `threads`, `batch_size` | `indexer/catalog_product_price/dimensions_mode`, `MAGE_INDEXER_THREADS_COUNT`, `indexer/batch_size` from `env.php` |
| `modules`, `indexer_boost_flags` | enabled `Orangecat_IndexerBoost*`/`IndexerBench` modules and `indexer_boost/<group>/enabled` flags |
| `git_commit`, `hostname` | commit of the Magento root read from `.git` (no `git` process), host name |

`FingerprintComparator` lists the differences that make two runs not comparable — Magento, PHP and database versions,
database variables, catalog, dimensions mode, threads, batch sizes and host — as `path: baseline -> candidate`.
Modules, Indexer Boost flags and the commit are expected to change between a baseline and a candidate and are not
reported.

## Running

```bash
bin/magento indexer-bench:run -i catalog_product_price -l core --repeat=5 --warmup --notes="before boost"
MAGE_INDEXER_THREADS_COUNT=4 bin/magento indexer-bench:run -i catalog_product_price -l core-4t --threads=4
```

Each repeat invalidates the indexer (as `indexer:reset`; not with `--skip-reset`), runs a measured full reindex and
stores one run with its metrics and the environment fingerprint. The command prints each repeat and the median of
the measured repeats (warm-up and failed ones left out); the median is not stored (ADR-0002).

- `--mode=baseline|candidate` (default `baseline`), `--strict` (see Safeguards).
- `--threads=N` only checks that `MAGE_INDEXER_THREADS_COUNT` is `N`: the variable is read when `bin/magento` starts.
- A failed reindex is stored as `failed` and stops the series; exit code 1.
- An indexer already `working` is refused (the core would skip the reindex).

### Partial runs

```bash
bin/magento indexer-bench:run-partial -i catalog_product_price -l core-500 -c 500 --repeat=5
bin/magento indexer-bench:run-partial -i catalog_product_price -l core-few --ids=12,45,3001
```

`run-partial` calls `reindexList()` on the adapter's partial indexer (e.g. `catalog_product_category` for
`catalog_category_product`) with the same IDs on every repeat, without resetting anything. IDs are sampled from the
adapter's entity universe with `--seed` (default 42): a partial Fisher-Yates shuffle driven by
`Random\Randomizer` on `Mt19937`, whose output is fixed for a seed across PHP versions, so a baseline and a
candidate on different PHP versions reindex the same IDs as long as the catalog is the same. `--ids` gives them
explicitly; unknown IDs are refused. Runs are stored with `kind = partial`, `sample_size` and `seed` (`NULL` for
`--ids`). `inventory` has no partial path.

### Profiling

```bash
bin/magento indexer-bench:run -i catalog_product_price -l core-profiled --profile
```

`--profile` records each repeat with the Magento profiler through the JSON driver of
[`mage-os/module-profiler`](https://github.com/mage-os-lab/module-profiler) (suggested, not required:
`composer require mage-os/module-profiler && bin/magento module:enable MageOS_Profiler`). The driver is attached
around the measured reindex only, not the whole command, and the report lands in
`var/indexer-bench/<indexer>/<run_id>/` (one file per process in parallel runs). The module's environment flags
apply, e.g. `MAGE_PROFILER_SQL=operation` for a shorter report.

- Profiled runs are stored with `profiled = 1` and left out of `compare` and of label medians: the profiler slows
  the reindex down.
- Without the module the command warns and measures without profiling.
- With the profiler already on for the process (`MAGE_PROFILER=json bin/magento ...` or `var/profiler.flag`) every
  run is stored as profiled, with or without `--profile`, and its report stays where that profiler writes it.

## Snapshots

```bash
bin/magento indexer-bench:snapshot -i catalog_product_price -l core
bin/magento indexer-bench:snapshot -i catalog_product_price -l boost --diff=core [--limit=10]
```

Every table of the adapter is dumped with `CanonicalDumper` to `var/indexer-bench/<indexer>/<label>/<table>.tsv`.
`orangecat_indexer_bench_snapshot` keeps one row per table (rows, MD5, dump path relative to `var/`) and a `*` row
with the global MD5: the MD5 of one `table, rows, md5` line per table, sorted by table name. Taking a snapshot with an
existing label replaces it (rows and `.tsv` files). Labels are directory names: letters, digits, `.`, `_`, `-`.

`--diff=<label>` compares with that snapshot as the baseline: `MATCH` (exit 0) when the global checksums are equal,
otherwise `DIFFER` (exit 1) with, per differing table, the row counts and the first `--limit` differing rows from
`SnapshotDiff` (`-` only in the baseline, `+` only in the candidate, `~` changed). Tables present on one side only
are reported as such. The snapshot refuses to run in production mode outside `indexer_bench/allowed_hosts`: it reads
whole index tables.

Adobe Commerce with content staging (`row_id`) is not covered: this installation is Open Source and the version
filter of staged entities cannot be verified here (SPEC 5.6).

## Comparing

```bash
bin/magento indexer-bench:compare -i catalog_product_price --baseline=core --candidate=boost [--threshold=10] [--strict] \
    [--format=table|markdown]
bin/magento indexer-bench:compare -i catalog_product_price --baseline=12 --candidate=19
```

A reference made only of digits is a run ID; anything else is a label and stands for the median of its comparable
runs (successful, not warm-up, not profiled). A single run is refused when it failed, is a warm-up or was profiled.

```
Indexer: catalog_product_price   Baseline: core (median of 5 runs)   Candidate: boost (median of 5 runs)
Fingerprint: OK (same catalog, database variables and versions)
| Metric         | Baseline | Candidate | Δ      | Verdict      |
| wall_time_s    | 2 431.2  | 612.8     | -74.8% | ✅ improved   |
| sql_queries    | n/a      | 3 114     | —      | n/a          |
Output: MATCH (8 412 330 rows)
No regression over 10%
```

- Every metric is a cost: above `+threshold` it is a regression, below `-threshold` an improvement, otherwise
  `same`. From 0 to more than 0 is a regression (`+∞`). `index_rows` is `same` or `⚠ changed`, never judged.
  `n/a` on either side is not judged.
- Fingerprint differences (`FingerprintComparator`) are listed; different work (full against partial, or another
  sample size or seed) is warned about.
- `Output` compares the snapshots of both labels (`MATCH`/`DIFFER`), or says why it could not.
- `--format=markdown` prints the same report as Markdown (title, fingerprint, metrics table, output verdict), ready
  for `docs/benchmarks/<indexer>-<date>.md`.
- With `--strict` the command exits with 1 on any regression or when the output differs.

## History

```bash
bin/magento indexer-bench:history -i catalog_product_price [--limit=20] [--metric=wall_time_s]
```

Lists the last runs of the indexer, whatever their status (warm-up and profiled runs are marked), with the chosen
metric, then one line per label: comparable runs, median, min, max and the trend of the median from the previous
label of the same kind, full or partial reindex (`↑` up, `↓` down, `=` same; every metric is a cost, so `↓` is better). Label medians are taken over every
comparable run of the label, as `compare` does, not only the listed ones. The unit suffix of the metric may be left
out (`--metric=wall_time`).

```
| Label | Runs | Median  | Min     | Max     | Trend    |
| core  | 5    | 2 431.2 | 2 398.0 | 2 502.7 | —        |
| boost | 5    | 612.8   | 601.1   | 640.2   | ↓ -74.8% |
```

## Exporting

```bash
bin/magento indexer-bench:export [--run=19] [--format=markdown|json] [--output=docs/benchmarks/price-2026-11-01.md]
```

Exports one run, the last stored one when `--run` is left out. `markdown` (the default) gives a section ready to
paste under `docs/benchmarks/`: the run, its metrics in report order and a summary of the environment. `json` holds
the whole run with raw values and the full fingerprint. `--output` writes the file instead of printing it.

## Admin page

*System > Tools > Indexer Benchmarks* (ACL `Orangecat_IndexerBench::view`, title *Indexer Benchmarks*). Read-only:
every action answers GET only and nothing starts a run. It shows the runs stored in **this installation's
database**: runs made in development or staging with `bin/magento indexer-bench:run` appear there; runs made with
`bin/bench-magento` live in the copy that script points at and are not shown.

- **Overview** (`indexerbench/run/index`): one section per indexer with its labels (comparable runs, median, min,
  max and trend of `wall_time_s`, as `history`) and its last 20 runs.
- **Run** (`indexerbench/run/view/id/<run>`): mode, kind, repeat, status with its error, notes, every metric in report
  order and the environment summary; *Download Markdown report* gives the `export` Markdown as a file
  (`indexerbench/run/export/id/<run>`).
- **Compare** (`indexerbench/compare/index`): indexer, baseline and candidate (label or run ID, labels suggested) and
  threshold; metrics with their verdicts, environment differences, a warning when the two did different work, the
  output verdict from the snapshots with the tables that differ, and the regressions, as `compare`. Differing rows
  stay on the command line (`snapshot --diff`).

On a small catalog a reindex takes under a second and its time varies by ±30 % between runs: read `wall_time_s`
verdicts there as noise and measure speed on a large catalog.

## Safeguards

Every measurement starts with `Safeguards::check()`:

- **Production.** With `MAGE_MODE=production` the benchmark refuses to run unless **both** the host name and the
  database name are listed in `app/etc/env.php`. A staging host pointed at the live database stays refused.

  ```php
  'indexer_bench' => [
      'allowed_hosts' => ['staging-web1', 'magento_staging'],
  ],
  ```

- **Index cron.** Jobs of the `index` cron group whose `cron_schedule` row is `running` are reported. A row left
  `running` by a dead cron process is reported too: clean it up or ignore the warning knowingly.
- **Pending changelog.** In "Update by Schedule" mode, versions of the measured indexer's changelog not yet applied
  are reported (the index cron would apply them during the measurement). A missing changelog table is reported, not
  fatal.
- **Traffic.** `Safeguards::TRAFFIC_WARNING` is printed before measuring: `SHOW GLOBAL STATUS` counters include every
  connection to the database server.

Activity problems are warnings; with `--strict` any of them aborts the run.

Each `run` invalidates the indexer like `indexer:reset`, and the core then invalidates the indexers that depend on it
(for example `catalogsearch_fulltext` after `catalog_product_price`). The measured indexer is rebuilt by the run;
the dependent ones wait for `indexer_reindex_all_invalid` (cron) or `bin/magento indexer:reindex`.

## Decisions

| ADR | Subject |
|---|---|
| [0001](docs/decisions/0001-metrics-in-parallel-runs-and-on-mariadb.md) | SQL metrics `n/a` in parallel runs; MariaDB counters |
| [0002](docs/decisions/0002-run-series-median-and-threads.md) | One row per repeat, derived median, `--threads` |
| [0003](docs/decisions/0003-compare-verdicts.md) | Verdicts of `compare` |
| [0004](docs/decisions/0004-profiled-runs.md) | `--profile` with mage-os/module-profiler |
| [0005](docs/decisions/0005-canonical-dump-keys.md) | Canonical key, surrogate IDs and nullable key columns of the dump |
| [0006](docs/decisions/0006-separate-benchmark-database.md) | Separate benchmark database and `MAGENTO_DC__OVERRIDE` |
| [0007](docs/decisions/0007-staging-and-inventory-limits.md) | Adobe Commerce staging and MSI inventory |

## Known limits

- Measure without traffic and without cron: the server counters include every connection.
- Small counters move a lot in percent: `slow_queries` going from 14 to 15 is +7.1 %, so at the default 10 %
  threshold a difference of two would already be a regression. Read them as counts.
- Partial runs given with `--ids` store the number of IDs only, not the IDs (ADR-0003).
- Labels made only of digits cannot be compared by label (ADR-0003).
- Measured on Magento Open Source 2.4.9, PHP 8.5, MariaDB 10.11 with serial reindexes; parallel runs
  (`MAGE_INDEXER_THREADS_COUNT > 1`) are covered by unit tests only.

## Related packages

| Repository | Package | Purpose |
|---|---|---|
| [m2_indexer-boost](https://github.com/olivertar/m2_indexer-boost) | `orangecat/module-indexer-boost` | doctor, passive monitor, faster full reindexes (production) — [documentation](https://github.com/olivertar/m2_indexer-boost/tree/main/docs) |
| [m2_indexer-bench](https://github.com/olivertar/m2_indexer-bench) | `orangecat/module-indexer-bench` | benchmark (development and staging only) |
| [m2_indexer-boost-mago](https://github.com/olivertar/m2_indexer-boost-mago) | `orangecat/module-indexer-boost-mago` | Mago add-on for Indexer Boost |
| [m2_indexer-bench-mago](https://github.com/olivertar/m2_indexer-bench-mago) | `orangecat/module-indexer-bench-mago` | Mago add-on for the benchmark |

## Licence

MIT, see [LICENSE](LICENSE).
