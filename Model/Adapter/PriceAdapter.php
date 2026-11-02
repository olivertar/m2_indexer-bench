<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Model\Adapter;

use Magento\Catalog\Model\Indexer\Product\Price\DimensionCollectionFactory;
use Magento\Catalog\Model\Indexer\Product\Price\TableMaintainer;
use Orangecat\IndexerBench\Api\IndexerAdapterInterface;
use Orangecat\IndexerBoost\Model\Doctor\Db\ReadOnlyConnection;

/**
 * catalog_product_price: the price tables of the active dimensions mode, as the core names them, plus the tier price
 * and website date tables the indexer also writes
 */
class PriceAdapter implements IndexerAdapterInterface
{
    private const PRICE_ORDER = ['entity_id', 'customer_group_id', 'website_id'];

    /**
     * Tables written next to the dimension tables, without prefix => canonical order
     */
    private const OTHER_TABLES = [
        'catalog_product_index_tier_price' => self::PRICE_ORDER,
        'catalog_product_index_website' => ['website_id'],
    ];

    /**
     * @var array<string,string[]>|null physical table => canonical order
     */
    private ?array $orders = null;

    /**
     * @param DimensionCollectionFactory $dimensionCollectionFactory
     * @param TableMaintainer $tableMaintainer
     * @param ReadOnlyConnection $connection
     * @param ProductIdUniverse $universe
     */
    public function __construct(
        private readonly DimensionCollectionFactory $dimensionCollectionFactory,
        private readonly TableMaintainer $tableMaintainer,
        private readonly ReadOnlyConnection $connection,
        private readonly ProductIdUniverse $universe
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getIndexerId(): string
    {
        return 'catalog_product_price';
    }

    /**
     * @inheritdoc
     */
    public function getIndexTables(): array
    {
        return array_keys($this->orders());
    }

    /**
     * @inheritdoc
     */
    public function getCanonicalOrder(string $table): array
    {
        return $this->orders()[$table] ?? throw new \InvalidArgumentException(
            sprintf('%s is not an index table of %s', $table, $this->getIndexerId())
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
        return 'catalog_product_price';
    }

    /**
     * Canonical order by physical table, resolved once
     *
     * @return array<string,string[]>
     */
    private function orders(): array
    {
        if ($this->orders === null) {
            $this->orders = [];
            // Current dimensions mode (indexer/catalog_product_price/dimensions_mode), as the indexer iterates it
            foreach ($this->dimensionCollectionFactory->create() as $dimensions) {
                $this->orders[$this->tableMaintainer->getMainTableByDimensions($dimensions)] = self::PRICE_ORDER;
            }
            foreach (self::OTHER_TABLES as $table => $order) {
                $this->orders[$this->connection->getTableName($table)] = $order;
            }
        }
        return $this->orders;
    }
}
