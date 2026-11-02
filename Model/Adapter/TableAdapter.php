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
 * Adapter for a product indexer writing a fixed set of tables, configured in di.xml
 */
class TableAdapter implements IndexerAdapterInterface
{
    /**
     * @param ReadOnlyConnection $connection
     * @param ProductIdUniverse $universe
     * @param string $indexerId
     * @param array<string,string[]> $tables table name without prefix => canonical order columns
     * @param string|null $partialIndexerId
     */
    public function __construct(
        private readonly ReadOnlyConnection $connection,
        private readonly ProductIdUniverse $universe,
        private readonly string $indexerId,
        private readonly array $tables,
        private readonly ?string $partialIndexerId = null
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getIndexerId(): string
    {
        return $this->indexerId;
    }

    /**
     * @inheritdoc
     */
    public function getIndexTables(): array
    {
        return array_keys($this->physicalOrders());
    }

    /**
     * @inheritdoc
     */
    public function getCanonicalOrder(string $table): array
    {
        return $this->physicalOrders()[$table] ?? throw new \InvalidArgumentException(
            sprintf('%s is not an index table of %s', $table, $this->indexerId)
        );
    }

    /**
     * @inheritdoc
     */
    public function getEntityIdUniverse(): array
    {
        return $this->universe->getIds();
    }

    /**
     * @inheritdoc
     */
    public function getPartialIndexerId(): ?string
    {
        return $this->partialIndexerId;
    }

    /**
     * Canonical order by physical table name
     *
     * @return array<string,string[]>
     */
    private function physicalOrders(): array
    {
        $orders = [];
        foreach ($this->tables as $table => $order) {
            $orders[$this->connection->getTableName($table)] = $order;
        }
        return $orders;
    }
}
