# ADR-0006: Separate benchmark database

- Status: accepted
- Date: 2026-11-01

## Context

Every run invalidates and rebuilds indexes, and `generate-fixtures` adds tens of thousands of entities. Neither may
touch the database the store or the team works with. SPEC 5.2 guards production (`allowed_hosts`) but not a shared
development database, and the validation of SPEC 5.8 needs a bigger catalog than the development one.

## Decision

- Measure on a copy (`magento_bench`) of the database, used by the same code base through
  `bin/bench-magento`, which sets `MAGENTO_DC__OVERRIDE` with the `db` and `cache` sections of `app/etc/env.php`:
  another database name, another cache id prefix and other Redis/Valkey databases.
- `MAGENTO_DC__OVERRIDE` replaces whole top-level sections. The flat `MAGENTO_DC_DB__CONNECTION__DEFAULT__DBNAME`
  variable is not used: `DeploymentConfig::get()` only applies flat variables to the exact path, and the connection
  factory reads `db/connection/default` as one array, so the copy would not be used.
- The copy gets its own OpenSearch index prefix in `core_config_data`, so a full reindex or the fixture generator
  does not overwrite the search indexes of the original.
- Before measuring, a value only the copy has must come back through the script
  (`config:show catalog/search/opensearch_index_prefix`).
- `allowed_hosts` needs both the host name and the database name: a staging host pointed at the live database is
  still production.

## Consequences

- One code base serves both databases; generated code, `var/` and the snapshots are shared, so snapshot labels must
  not be reused across databases.
- The application lock (`lock/provider = db`) and the monitor run log live in each database, so a benchmark does
  not lock or log in the original one.
- A cache flush through the script flushes the Redis/Valkey databases of the copy only.
