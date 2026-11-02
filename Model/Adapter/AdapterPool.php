<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Model\Adapter;

use Orangecat\IndexerBench\Api\IndexerAdapterInterface;

/**
 * Benchmark adapters by indexer ID, declared in di.xml
 */
class AdapterPool
{
    /**
     * @param array<string,IndexerAdapterInterface> $adapters
     */
    public function __construct(
        private readonly array $adapters = []
    ) {
    }

    /**
     * Adapter of an indexer
     *
     * @param string $indexerId
     * @return IndexerAdapterInterface
     * @throws \InvalidArgumentException when the indexer is not supported
     */
    public function get(string $indexerId): IndexerAdapterInterface
    {
        return $this->adapters[$indexerId] ?? throw new \InvalidArgumentException(sprintf(
            'No benchmark adapter for "%s". Supported: %s',
            $indexerId,
            implode(', ', $this->getIndexerIds())
        ));
    }

    /**
     * Supported indexer IDs
     *
     * @return string[]
     */
    public function getIndexerIds(): array
    {
        return array_keys($this->adapters);
    }
}
