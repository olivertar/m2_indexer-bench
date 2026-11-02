<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Test\Unit\Model\Safeguard;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Exception\LocalizedException;
use Orangecat\IndexerBench\Model\Fingerprint\HostInfo;
use Orangecat\IndexerBench\Model\Safeguard\ProductionGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProductionGuardTest extends TestCase
{
    public function testOutsideProductionModeAnyHostIsAllowed(): void
    {
        $guard = $this->guard(['MAGE_MODE' => 'developer', 'db/connection/default/dbname' => 'magento'], 'web1');

        $guard->assertAllowed();

        $this->addToAssertionCount(1);
    }

    public function testProductionModeWithoutAllowListIsRefused(): void
    {
        $guard = $this->guard(['MAGE_MODE' => 'production', 'db/connection/default/dbname' => 'magento'], 'web1');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage(
            'MAGE_MODE is production and host "web1" / database "magento" are not both in indexer_bench/allowed_hosts'
        );
        $guard->assertAllowed();
    }

    /**
     * @param string[] $allowed
     */
    #[DataProvider('allowListProvider')]
    public function testProductionModeNeedsBothHostAndDatabaseAllowed(array $allowed, bool $expectedAllowed): void
    {
        $guard = $this->guard([
            'MAGE_MODE' => 'production',
            'db/connection/default/dbname' => 'magento_staging',
            'indexer_bench/allowed_hosts' => $allowed,
        ], 'staging-web1');

        if (!$expectedAllowed) {
            $this->expectException(LocalizedException::class);
        }
        $guard->assertAllowed();
        $this->addToAssertionCount(1);
    }

    /**
     * @return array<string,array{string[],bool}>
     */
    public static function allowListProvider(): array
    {
        return [
            'both' => [['staging-web1', 'magento_staging'], true],
            'host only' => [['staging-web1'], false],
            'database only' => [['magento_staging'], false],
        ];
    }

    /**
     * Guard over a deployment config made of path => value pairs
     *
     * @param array<string,mixed> $config
     * @param string $hostname
     * @return ProductionGuard
     */
    private function guard(array $config, string $hostname): ProductionGuard
    {
        $deploymentConfig = $this->createStub(DeploymentConfig::class);
        $deploymentConfig->method('get')->willReturnCallback(
            static fn (string $path): mixed => $config[$path] ?? null
        );
        $hostInfo = $this->createStub(HostInfo::class);
        $hostInfo->method('getHostname')->willReturn($hostname);
        return new ProductionGuard($deploymentConfig, $hostInfo);
    }
}
