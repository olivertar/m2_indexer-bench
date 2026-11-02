# ADR-0007: Adobe Commerce staging and MSI inventory

- Status: accepted
- Date: 2026-11-01

## Context

SPEC 5.6 asks the adapters to apply the same version filter as the core on Adobe Commerce with staging, and SPEC 5.1
lists an `inventory` adapter when MSI is active. The module was built and tested on Magento Open Source 2.4.9 with
MSI and only the default stock.

## Decision

- **Staging.** The dumped index tables are keyed by `entity_id` on both editions: the core indexers resolve the
  staging version when they read, and write flat rows. The dump therefore applies no version filter. The only read of
  an entity table is the sample universe of partial runs, which takes `DISTINCT entity_id` from
  `catalog_product_entity`, because staging keeps one row per version (`row_id`). Nothing else is claimed for
  Commerce: it was not available to test.
- **Inventory.** The `inventory` adapter dumps the `inventory_stock_<id>` base tables, found by the MSI naming so MSI
  stays optional. With only the default stock, `inventory_stock_1` is a view over `cataloginventory_stock_status`
  that the inventory indexer does not write: the adapter has nothing to dump, and the commands warn about it. Measure
  `cataloginventory_stock` for the default stock instead.

## Consequences

- Before relying on the benchmark on Commerce, take two baselines there and check `MATCH` (SPEC 5.8), with staged
  updates scheduled so that more than one version exists.
- A store with additional stocks gets real inventory dumps; this path is covered only by a unit test of the table
  naming, not by a measured run.
