<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Model\Adapter;

use Magento\Catalog\Model\Indexer\Category\Product\TableMaintainer;
use Magento\Store\Model\StoreManagerInterface;
use Orangecat\IndexerBench\Api\IndexerAdapterInterface;

/**
 * catalog_category_product: one live table per store view; the base catalog_category_product_index stays empty
 */
class CategoryProductAdapter implements IndexerAdapterInterface
{
    private const ORDER = ['category_id', 'product_id', 'store_id'];

    /**
     * @var string[]|null
     */
    private ?array $tables = null;

    /**
     * @param StoreManagerInterface $storeManager
     * @param TableMaintainer $tableMaintainer
     * @param ProductIdUniverse $universe
     */
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly TableMaintainer $tableMaintainer,
        private readonly ProductIdUniverse $universe
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getIndexerId(): string
    {
        return 'catalog_category_product';
    }

    /**
     * @inheritdoc
     */
    public function getIndexTables(): array
    {
        if ($this->tables === null) {
            $this->tables = [];
            foreach ($this->storeManager->getStores(false) as $store) {
                $this->tables[] = $this->tableMaintainer->getMainTable((int)$store->getId());
            }
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
        return self::ORDER;
    }

    /**
     * @inheritdoc
     */
    public function getEntityIdUniverse(): array
    {
        return $this->universe->getIds();
    }

    /**
     * Product-side partial path: products saved or assigned to categories
     *
     * @return string|null
     */
    public function getPartialIndexerId(): ?string
    {
        return 'catalog_product_category';
    }
}
