<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Test\Integration\Model\Fingerprint;

use Magento\Framework\App\ProductMetadataInterface;
use Magento\TestFramework\Helper\Bootstrap;
use Orangecat\IndexerBench\Model\Fingerprint\EnvironmentFingerprint;
use Orangecat\IndexerBench\Model\Fingerprint\FingerprintComparator;
use PHPUnit\Framework\TestCase;

class EnvironmentFingerprintTest extends TestCase
{
    public function testFingerprintOfTheTestInstallationIsCompleteAndStable(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $fingerprint = $objectManager->create(EnvironmentFingerprint::class)->collect();

        $this->assertSame(
            $objectManager->get(ProductMetadataInterface::class)->getVersion(),
            $fingerprint['magento']['version']
        );
        $this->assertContains($fingerprint['database']['engine'], ['MySQL', 'MariaDB']);
        $this->assertArrayHasKey('tmp_table_size', $fingerprint['database']['variables']);
        $this->assertIsInt($fingerprint['catalog']['products']);
        $this->assertContains('Orangecat_IndexerBench', $fingerprint['modules']);
        $this->assertContains('Orangecat_IndexerBoost', $fingerprint['modules']);
        $this->assertNotSame('', $fingerprint['hostname']);
        $this->assertNotFalse(json_encode($fingerprint, JSON_THROW_ON_ERROR));
        $this->assertSame(
            [],
            $objectManager->create(FingerprintComparator::class)->differences(
                $fingerprint,
                $objectManager->create(EnvironmentFingerprint::class)->collect()
            )
        );
    }
}
