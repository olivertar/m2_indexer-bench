<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Api;

/**
 * What the benchmark needs to know about one indexer
 *
 * @api
 */
interface IndexerAdapterInterface
{
    /**
     * Indexer ID, e.g. catalog_product_price
     *
     * @return string
     */
    public function getIndexerId(): string;

    /**
     * Live index tables to dump, with the table prefix and the active dimensions resolved
     *
     * Replica, temporary and outdated tables are left out.
     *
     * @return string[]
     */
    public function getIndexTables(): array;

    /**
     * Columns giving the canonical row order of a table returned by getIndexTables()
     *
     * @param string $table
     * @return string[]
     */
    public function getCanonicalOrder(string $table): array;

    /**
     * Entity IDs partial reindexes sample from
     *
     * @return int[]
     */
    public function getEntityIdUniverse(): array;

    /**
     * Indexer whose reindexList() runs the partial path, null when the indexer has none
     *
     * E.g. catalog_product_category for the product side of catalog_category_product.
     *
     * @return string|null
     */
    public function getPartialIndexerId(): ?string;
}
