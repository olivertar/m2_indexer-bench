<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Model\Fingerprint;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\Module\ModuleListInterface;
use Orangecat\IndexerBoost\Model\Doctor\Catalog\CatalogSizeProvider;
use Orangecat\IndexerBoost\Model\Doctor\Check\MysqlVarsCheck;
use Orangecat\IndexerBoost\Model\Doctor\Db\DbServerProvider;
use Orangecat\IndexerBoost\Model\Doctor\DoctorContext;

/**
 * Environment a run was measured in (SPEC 5.4), stored with every run
 */
class EnvironmentFingerprint
{
    /**
     * Server variables recorded: the MYSQL_VARS check list plus the MariaDB optimizer settings
     */
    public const DB_VARIABLES = [
        ...MysqlVarsCheck::VARIABLES,
        'in_predicate_conversion_threshold',
        'optimizer_use_condition_selectivity',
    ];

    private const DIMENSIONS_MODE_PATH = 'indexer/catalog_product_price/dimensions_mode';

    private const MODULE_PREFIX = 'Orangecat_IndexerB';

    /**
     * @param ProductMetadataInterface $productMetadata
     * @param DbServerProvider $dbServerProvider
     * @param CatalogSizeProvider $catalogSizeProvider
     * @param ScopeConfigInterface $scopeConfig
     * @param DeploymentConfig $deploymentConfig
     * @param ModuleListInterface $moduleList
     * @param GitCommitReader $gitCommitReader
     * @param HostInfo $hostInfo
     */
    public function __construct(
        private readonly ProductMetadataInterface $productMetadata,
        private readonly DbServerProvider $dbServerProvider,
        private readonly CatalogSizeProvider $catalogSizeProvider,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly DeploymentConfig $deploymentConfig,
        private readonly ModuleListInterface $moduleList,
        private readonly GitCommitReader $gitCommitReader,
        private readonly HostInfo $hostInfo
    ) {
    }

    /**
     * Collect the fingerprint
     *
     * @return array<string,mixed>
     */
    public function collect(): array
    {
        $context = new DoctorContext();
        $server = $this->dbServerProvider->get($context);
        $variables = [];
        foreach (self::DB_VARIABLES as $name) {
            $value = $server->getVariable($name);
            if ($value !== null) {
                $variables[$name] = $value;
            }
        }
        $modules = array_values(array_filter(
            $this->moduleList->getNames(),
            static fn (string $module): bool => str_starts_with($module, self::MODULE_PREFIX)
        ));
        sort($modules);

        return [
            'magento' => [
                'version' => $this->productMetadata->getVersion(),
                'edition' => $this->productMetadata->getEdition(),
            ],
            'php' => PHP_VERSION,
            'database' => [
                'engine' => $server->getEngine(),
                'version' => $server->getVersion(),
                'variables' => $variables,
            ],
            'catalog' => $this->catalogSizeProvider->get($context)->toArray(),
            'price_dimensions_mode' => (string)($this->scopeConfig->getValue(self::DIMENSIONS_MODE_PATH) ?: 'none'),
            'threads' => $this->hostInfo->getThreadsCount(),
            'batch_size' => $this->deploymentConfig->get('indexer/batch_size'),
            'modules' => $modules,
            'indexer_boost_flags' => $this->boostFlags(),
            'git_commit' => $this->gitCommitReader->getCommit(),
            'hostname' => $this->hostInfo->getHostname(),
        ];
    }

    /**
     * Flags indexer_boost/<group>/enabled, by group
     *
     * @return array<string,string>
     */
    private function boostFlags(): array
    {
        $flags = [];
        $config = $this->scopeConfig->getValue('indexer_boost');
        foreach (is_array($config) ? $config : [] as $group => $values) {
            if (is_array($values) && isset($values['enabled'])) {
                $flags[(string)$group] = (string)$values['enabled'];
            }
        }
        ksort($flags);
        return $flags;
    }
}
