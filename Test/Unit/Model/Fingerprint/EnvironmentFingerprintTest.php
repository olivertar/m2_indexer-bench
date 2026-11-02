<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Test\Unit\Model\Fingerprint;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\Module\ModuleListInterface;
use Orangecat\IndexerBench\Model\Fingerprint\EnvironmentFingerprint;
use Orangecat\IndexerBench\Model\Fingerprint\GitCommitReader;
use Orangecat\IndexerBench\Model\Fingerprint\HostInfo;
use Orangecat\IndexerBoost\Model\Doctor\Catalog\CatalogSize;
use Orangecat\IndexerBoost\Model\Doctor\Catalog\CatalogSizeProvider;
use Orangecat\IndexerBoost\Model\Doctor\Db\DbServer;
use Orangecat\IndexerBoost\Model\Doctor\Db\DbServerProvider;
use PHPUnit\Framework\TestCase;

class EnvironmentFingerprintTest extends TestCase
{
    public function testCollectsEverythingThatCanChangeAMeasurement(): void
    {
        $metadata = $this->createStub(ProductMetadataInterface::class);
        $metadata->method('getVersion')->willReturn('2.4.9');
        $metadata->method('getEdition')->willReturn('Community');
        $dbServer = $this->createStub(DbServerProvider::class);
        $dbServer->method('get')->willReturn(new DbServer('10.11.19-MariaDB', [
            'innodb_buffer_pool_size' => '134217728',
            'tmp_table_size' => '16777216',
            'max_heap_table_size' => '16777216',
            'optimizer_use_condition_selectivity' => '4',
            'in_predicate_conversion_threshold' => '1000',
            'wait_timeout' => '28800',
        ], 1000));
        $catalog = $this->createStub(CatalogSizeProvider::class);
        $catalog->method('get')->willReturn(
            new CatalogSize(['simple' => 2000, 'configurable' => 40], 30, 1, 1, 4, 1, 1)
        );
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnMap([
            ['indexer/catalog_product_price/dimensions_mode', 'default', null, 'website'],
            ['indexer_boost', 'default', null, [
                'monitor' => ['enabled' => '1', 'retention_days' => '90'],
                'catalog_product_price' => ['enabled' => '1', 'fallback_on_error' => '1'],
            ]],
        ]);
        $deploymentConfig = $this->createStub(DeploymentConfig::class);
        $deploymentConfig->method('get')->willReturnMap([
            ['indexer/batch_size', null, ['catalog_product_price' => ['default' => 2000]]],
        ]);
        $modules = $this->createStub(ModuleListInterface::class);
        $modules->method('getNames')->willReturn(
            ['Magento_Catalog', 'Orangecat_IndexerBoost', 'Orangecat_IndexerBench']
        );
        $git = $this->createStub(GitCommitReader::class);
        $git->method('getCommit')->willReturn('3f6c2a9e1d4b8c7a6f5e4d3c2b1a09f8e7d6c5b4');
        $host = $this->createStub(HostInfo::class);
        $host->method('getHostname')->willReturn('staging-1');
        $host->method('getThreadsCount')->willReturn(4);

        $fingerprint = (new EnvironmentFingerprint(
            $metadata,
            $dbServer,
            $catalog,
            $scopeConfig,
            $deploymentConfig,
            $modules,
            $git,
            $host
        ))->collect();

        $this->assertSame(
            [
                'magento' => ['version' => '2.4.9', 'edition' => 'Community'],
                'php' => PHP_VERSION,
                'database' => [
                    'engine' => 'MariaDB',
                    'version' => '10.11.19-MariaDB',
                    'variables' => [
                        'innodb_buffer_pool_size' => '134217728',
                        'tmp_table_size' => '16777216',
                        'max_heap_table_size' => '16777216',
                        'in_predicate_conversion_threshold' => '1000',
                        'optimizer_use_condition_selectivity' => '4',
                    ],
                ],
                'catalog' => [
                    'products' => 2040,
                    'products_by_type' => ['simple' => 2000, 'configurable' => 40],
                    'categories' => 30,
                    'stores' => 1,
                    'websites' => 1,
                    'customer_groups' => 4,
                    'catalog_rules' => 1,
                    'active_catalog_rules' => 1,
                ],
                'price_dimensions_mode' => 'website',
                'threads' => 4,
                'batch_size' => ['catalog_product_price' => ['default' => 2000]],
                'modules' => ['Orangecat_IndexerBench', 'Orangecat_IndexerBoost'],
                'indexer_boost_flags' => ['catalog_product_price' => '1', 'monitor' => '1'],
                'git_commit' => '3f6c2a9e1d4b8c7a6f5e4d3c2b1a09f8e7d6c5b4',
                'hostname' => 'staging-1',
            ],
            $fingerprint
        );
    }
}
