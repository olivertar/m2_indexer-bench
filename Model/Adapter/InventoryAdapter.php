<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Model\Adapter;

use Orangecat\IndexerBench\Api\IndexerAdapterInterface;
use Orangecat\IndexerBoost\Model\Doctor\Db\ReadOnlyConnection;

/**
 * inventory (MSI): the stock index tables inventory_stock_<stock id>
 *
 * The default stock is a view over cataloginventory_stock_status that the indexer does not write, so with a single
 * stock there is nothing to dump. Tables are found by the naming of
 * Magento\InventoryIndexer\Model\StockIndexTableNameResolver instead of the resolver itself, so the adapter does not
 * require MSI to be installed.
 */
class InventoryAdapter implements IndexerAdapterInterface
{
    private const TABLE_PREFIX = 'inventory_stock_';

    /**
     * @var string[]|null
     */
    private ?array $tables = null;

    /**
     * @param ReadOnlyConnection $connection
     */
    public function __construct(
        private readonly ReadOnlyConnection $connection
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getIndexerId(): string
    {
        return 'inventory';
    }

    /**
     * @inheritdoc
     */
    public function getIndexTables(): array
    {
        if ($this->tables === null) {
            $prefix = $this->connection->getTableName(self::TABLE_PREFIX);
            $tables = [];
            foreach ($this->connection->fetchCol(
                'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() '
                . "AND TABLE_TYPE = 'BASE TABLE' AND TABLE_NAME LIKE ?",
                [str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], $prefix) . '%']
            ) as $table) {
                $stockId = substr((string)$table, strlen($prefix));
                if (ctype_digit($stockId)) {
                    $tables[(int)$stockId] = (string)$table;
                }
            }
            ksort($tables);
            $this->tables = array_values($tables);
        }
        return $this->tables;
    }

    /**
     * @inheritdoc
     */
    public function getCanonicalOrder(string $table): array
    {
        if (!in_array($table, $this->getIndexTables(), true)) {
            throw new \InvalidArgumentException(
                sprintf('%s is not an index table of %s', $table, $this->getIndexerId())
            );
        }
        return ['sku'];
    }

    /**
     * No partial path: the inventory indexer reindexes stocks, not entities
     *
     * @return int[]
     */
    public function getEntityIdUniverse(): array
    {
        return [];
    }

    /**
     * @inheritdoc
     */
    public function getPartialIndexerId(): ?string
    {
        return null;
    }
}
