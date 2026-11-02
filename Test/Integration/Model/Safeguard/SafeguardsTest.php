<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Test\Integration\Model\Safeguard;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use Orangecat\IndexerBench\Model\Safeguard\Safeguards;
use PHPUnit\Framework\TestCase;

class SafeguardsTest extends TestCase
{
    #[DbIsolation(true)]
    public function testRunningIndexCronJobIsReportedAndAbortsInStrictMode(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $resource = $objectManager->get(ResourceConnection::class);
        $resource->getConnection()->insert($resource->getTableName('cron_schedule'), [
            'job_code' => 'indexer_update_all_views',
            'status' => 'running',
            'created_at' => '2026-10-31 10:00:00',
            'scheduled_at' => '2026-10-31 10:00:00',
            'executed_at' => '2026-10-31 10:00:05',
        ]);
        $safeguards = $objectManager->create(Safeguards::class);

        $this->assertContains(
            'Index cron job running: indexer_update_all_views (since 2026-10-31 10:00:05 UTC)',
            $safeguards->check('catalog_product_price', false)
        );
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Not measuring catalog_product_price (--strict)');
        $safeguards->check('catalog_product_price', true);
    }
}
