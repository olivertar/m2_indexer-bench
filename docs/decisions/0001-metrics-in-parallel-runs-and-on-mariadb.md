# ADR-0001: SQL metrics in parallel runs and server counters on MariaDB

- Status: accepted
- Date: 2026-10-31

## Context

SPEC 5.3 takes `sql_queries`, `sql_time_s` and `slow_queries` from a plugin on
`Magento\Framework\DB\Adapter\Pdo\Mysql::query()`, and row metrics from `Innodb_rows_*` in `SHOW GLOBAL STATUS`.

- With `MAGE_INDEXER_THREADS_COUNT > 1` the indexer forks workers (`pcntl_fork()`); each counts in its own copy of
  the counter, which is lost when it exits. SPEC asks to choose between per-PID files aggregated by the parent (a) and
  marking the plugin metrics as not available (b).
- MariaDB (10.11 here) has no `Innodb_rows_read/inserted/updated/deleted`; MySQL has them. `Handler_*` exist on both.

## Decision

- Option (b): in a parallel run `sql_queries`, `sql_time_s` and `slow_queries` are stored as `NULL` (n/a). Server
  counters, which include every connection and so every worker, are kept.
- A metric whose status variables the server lacks is `NULL`: `rows_*` on MariaDB. Four metrics are added, available
  on both servers: `handler_read` (sum of `Handler_read_*`), `handler_write`, `handler_update`, `handler_delete`.
  They count storage engine calls, temporary tables included, so they are compared run against run on the same
  server, never read as rows of the index tables.
- `QueryCounter` is inactive by default: outside a measurement the plugin costs one boolean check per statement.
  Statements of the collector itself (status reads, row counts) run outside the counting window.

## Consequences

- Parallel runs compare on wall time, memory of the parent and server counters only.
- A comparison between a MySQL and a MariaDB run is flagged by the fingerprint (server version) anyway.
- Per-PID aggregation (a) can be added later without schema changes: the metrics are already nullable.
