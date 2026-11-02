<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Model\Run;

use Magento\Backend\App\Area\FrontNameResolver;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\ObjectManager\ConfigLoaderInterface;
use Magento\Framework\ObjectManagerInterface;

/**
 * Area a measured reindex runs in: the one bin/magento indexer:reindex uses
 *
 * Core AbstractIndexerCommand sets the adminhtml area and loads its DI configuration before reindexing. Without it
 * some indexers fail (catalogrule_rule: "Area code is not set") and others would run with another set of plugins.
 */
class IndexerArea
{
    /**
     * @param State $state
     * @param ObjectManagerInterface $objectManager
     * @param ConfigLoaderInterface $configLoader
     */
    public function __construct(
        private readonly State $state,
        private readonly ObjectManagerInterface $objectManager,
        private readonly ConfigLoaderInterface $configLoader
    ) {
    }

    /**
     * Set the adminhtml area and its DI configuration, unless the process already has an area
     *
     * @return void
     * @throws LocalizedException
     */
    public function ensure(): void
    {
        if ($this->hasArea()) {
            return;
        }
        $this->state->setAreaCode(FrontNameResolver::AREA_CODE);
        $this->objectManager->configure($this->configLoader->load(FrontNameResolver::AREA_CODE));
    }

    /**
     * Whether an area is already set
     *
     * @return bool
     */
    private function hasArea(): bool
    {
        try {
            $this->state->getAreaCode();
            return true;
        } catch (LocalizedException) {
            return false;
        }
    }
}
