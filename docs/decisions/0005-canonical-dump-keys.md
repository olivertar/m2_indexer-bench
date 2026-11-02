# ADR-0005: Keys of the canonical dump

- Status: accepted
- Date: 2026-11-01

## Context

SPEC 5.6 asks for a dump that walks the live index tables by ranges of the primary key, ordered by the adapter's
columns, with an MD5 per table and a global one, so a core and an optimized indexer can be compared exactly.

Two facts of the core tables break a literal reading:

- `catalogrule_product.rule_product_id` and `catalogrule_product_price.rule_product_price_id` are auto-increment
  primary keys. They number rows in insert order, so two correct reindexes give different values.
- Some unique keys the core declares contain nullable columns (`catalogrule_product.customer_group_id`).

## Decision

- The dump is ordered and paginated by the adapter's canonical key, not by the primary key: keyset pagination
  `(a > ?) OR (a = ? AND b > ?)` over batches of 10 000 rows, never `OFFSET` and never the whole table in memory.
- The canonical key must cover a unique index of the table; otherwise the dump is refused, since rows could be
  skipped or repeated between batches.
- Auto-increment columns outside the canonical key are surrogate IDs and are left out of the dump.
- Nullable key columns are accepted while they hold no `NULL`, checked before dumping; a `NULL` makes the dump fail
  with the column named, because `NULL` breaks both uniqueness and the keyset comparison.
- Each row is serialized key columns first, the others by name, tab-separated, `\N` for `NULL`, with `\\`, `\t`,
  `\n` and `\r` escaped, values as the server returns them. The global MD5 is taken over the sorted
  `table, rows, md5` lines.

## Consequences

- An optimized indexer that changes surrogate IDs still gives `MATCH`; one that changes any indexed value does not.
- A future core version that writes `NULL` into a nullable key column makes the dump of that table fail loudly
  instead of comparing wrongly.
- Values are compared as stored: `10.000000` and `10` in columns of different types would differ. Both sides of a
  comparison use the same schema, as optimized indexers must not change it (SPEC 2).
