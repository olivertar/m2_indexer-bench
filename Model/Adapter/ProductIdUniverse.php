<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Model\Adapter;

use Orangecat\IndexerBoost\Model\Doctor\Db\ReadOnlyConnection;

/**
 * Every product entity ID, the universe partial reindexes of product indexers sample from
 */
class ProductIdUniverse
{
    /**
     * @param ReadOnlyConnection $connection
     */
    public function __construct(
        private readonly ReadOnlyConnection $connection
    ) {
    }

    /**
     * Product IDs in ascending order
     *
     * @return int[]
     */
    public function getIds(): array
    {
        // DISTINCT: Adobe Commerce staging keeps one row per version (row_id) of each entity_id
        return array_values(array_unique(array_map('intval', $this->connection->fetchCol(sprintf(
            'SELECT DISTINCT entity_id FROM %s ORDER BY entity_id',
            $this->connection->getTableName('catalog_product_entity')
        )))));
    }
}
