<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Test\Unit\Model\Safeguard;

use Magento\Framework\Exception\LocalizedException;
use Orangecat\IndexerBench\Model\Safeguard\ProductionGuard;
use Orangecat\IndexerBench\Model\Safeguard\QuietnessCheck;
use Orangecat\IndexerBench\Model\Safeguard\Safeguards;
use PHPUnit\Framework\TestCase;

class SafeguardsTest extends TestCase
{
    public function testStrictModeAbortsOnAnyProblem(): void
    {
        $safeguards = new Safeguards(
            $this->createStub(ProductionGuard::class),
            $this->quietness(['Index cron job running: indexer_update_all_views (since 2026-10-31 10:00:00 UTC)'])
        );

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage(
            'Not measuring catalog_product_price (--strict): Index cron job running: indexer_update_all_views'
        );
        $safeguards->check('catalog_product_price', true);
    }

    public function testWithoutStrictTheProblemsAreReturnedAsWarnings(): void
    {
        $problems = ['Changelog catalog_product_price_cl has 30 versions pending'];
        $safeguards = new Safeguards($this->createStub(ProductionGuard::class), $this->quietness($problems));

        $this->assertSame($problems, $safeguards->check('catalog_product_price', false));
    }

    public function testRefusedProductionAbortsBeforeLookingAtActivity(): void
    {
        $guard = $this->createStub(ProductionGuard::class);
        $guard->method('assertAllowed')->willThrowException(new LocalizedException(__('MAGE_MODE is production')));
        $quietness = $this->createMock(QuietnessCheck::class);
        $quietness->expects($this->never())->method('findProblems');

        $this->expectExceptionMessage('MAGE_MODE is production');
        (new Safeguards($guard, $quietness))->check('catalog_product_price', false);
    }

    /**
     * Quietness check finding these problems
     *
     * @param string[] $problems
     * @return QuietnessCheck
     */
    private function quietness(array $problems): QuietnessCheck
    {
        $quietness = $this->createStub(QuietnessCheck::class);
        $quietness->method('findProblems')->willReturn($problems);
        return $quietness;
    }
}
