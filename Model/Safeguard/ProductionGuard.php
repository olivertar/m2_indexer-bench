<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Model\Safeguard;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Orangecat\IndexerBench\Model\Fingerprint\HostInfo;

/**
 * Refuses to benchmark a production installation that is not explicitly allowed in env.php
 */
class ProductionGuard
{
    /**
     * env.php list of host names and database names a production-mode benchmark may run on
     */
    public const ALLOWED_HOSTS_PATH = 'indexer_bench/allowed_hosts';

    /**
     * env.php path of the database name of the default connection
     */
    private const DATABASE_PATH = 'db/connection/default/dbname';

    /**
     * @param DeploymentConfig $deploymentConfig
     * @param HostInfo $hostInfo
     */
    public function __construct(
        private readonly DeploymentConfig $deploymentConfig,
        private readonly HostInfo $hostInfo
    ) {
    }

    /**
     * Throw unless benchmarking this installation is allowed
     *
     * @return void
     * @throws LocalizedException
     */
    public function assertAllowed(): void
    {
        if ($this->deploymentConfig->get(State::PARAM_MODE) !== State::MODE_PRODUCTION) {
            return;
        }
        $hostname = $this->hostInfo->getHostname();
        $database = (string)$this->deploymentConfig->get(self::DATABASE_PATH);
        $allowed = (array)($this->deploymentConfig->get(self::ALLOWED_HOSTS_PATH) ?? []);
        // Both must be listed: a staging host pointed at the live database is still production
        if (in_array($hostname, $allowed, true) && in_array($database, $allowed, true)) {
            return;
        }
        throw new LocalizedException(__(
            'MAGE_MODE is production and host "%1" / database "%2" are not both in %3 of app/etc/env.php. '
            . 'The benchmark resets and rebuilds indexes: run it on a copy, never on the live store.',
            $hostname,
            $database,
            self::ALLOWED_HOSTS_PATH
        ));
    }
}
