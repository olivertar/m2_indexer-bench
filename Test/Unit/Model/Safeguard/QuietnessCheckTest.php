<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Test\Unit\Model\Safeguard;

use Magento\Cron\Model\ConfigInterface as CronConfig;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\Indexer\IndexerInterfaceFactory;
use Magento\Framework\Mview\View\ChangelogInterface;
use Magento\Framework\Mview\View\ChangelogTableNotExistsException;
use Magento\Framework\Phrase;
use Magento\Framework\Mview\View\StateInterface;
use Magento\Framework\Mview\ViewInterface;
use Orangecat\IndexerBench\Model\Safeguard\QuietnessCheck;
use Orangecat\IndexerBoost\Model\Doctor\Db\ReadOnlyConnection;
use Orangecat\IndexerBoost\Test\Unit\Fake\WriteGuardAdapterTrait;
use PHPUnit\Framework\TestCase;

class QuietnessCheckTest extends TestCase
{
    use WriteGuardAdapterTrait;

    public function testRunningJobsOfTheIndexCronGroupAreReported(): void
    {
        $binds = [];
        $connection = new ReadOnlyConnection($this->createResourceConnection($this->createWriteGuardAdapter(
            function (string $sql, string $method, array $bind) use (&$binds): array {
                $binds[] = $bind;
                $this->assertStringContainsString('FROM pfx_cron_schedule', $sql);
                $this->assertStringContainsString("status = 'running'", $sql);
                return ['indexer_update_all_views' => '2026-10-31 10:00:00'];
            }
        )));
        $cronConfig = $this->createStub(CronConfig::class);
        $cronConfig->method('getJobs')->willReturn([
            'default' => ['catalogrule_apply_all' => []],
            'index' => ['indexer_reindex_all_invalid' => [], 'indexer_update_all_views' => []],
        ]);

        $check = new QuietnessCheck($connection, $cronConfig, $this->indexerFactory(false));

        $this->assertSame(
            ['Index cron job running: indexer_update_all_views (since 2026-10-31 10:00:00 UTC)'],
            $check->findProblems('catalog_product_price')
        );
        $this->assertSame([['indexer_reindex_all_invalid', 'indexer_update_all_views']], $binds);
    }

    public function testPendingChangelogEntriesOfTheMeasuredIndexerAreReported(): void
    {
        $check = new QuietnessCheck(
            $this->quietConnection(),
            $this->createStub(CronConfig::class),
            $this->indexerFactory(true, 100, 130)
        );

        $this->assertSame(
            ['Changelog catalog_product_price_cl has 30 versions pending: the index cron would apply them while '
                . 'measuring'],
            $check->findProblems('catalog_product_price')
        );
    }

    public function testNoProblemWhenTheChangelogIsCaughtUp(): void
    {
        $check = new QuietnessCheck(
            $this->quietConnection(),
            $this->createStub(CronConfig::class),
            $this->indexerFactory(true, 130, 130)
        );

        $this->assertSame([], $check->findProblems('catalog_product_price'));
    }

    public function testMissingChangelogTableIsReportedInsteadOfFailing(): void
    {
        $check = new QuietnessCheck(
            $this->quietConnection(),
            $this->createStub(CronConfig::class),
            $this->indexerFactory(
                true,
                130,
                new ChangelogTableNotExistsException(new Phrase('Table catalog_product_price_cl does not exist'))
            )
        );

        $this->assertSame(
            ['Changelog catalog_product_price_cl is missing although the indexer is "Update by Schedule": '
                . 'Table catalog_product_price_cl does not exist'],
            $check->findProblems('catalog_product_price')
        );
    }

    /**
     * Connection that must not be queried
     *
     * @return ReadOnlyConnection
     */
    private function quietConnection(): ReadOnlyConnection
    {
        return new ReadOnlyConnection($this->createResourceConnection($this->createWriteGuardAdapter(
            function (string $sql): never {
                $this->fail('Unexpected query: ' . $sql);
            }
        )));
    }

    /**
     * Factory of an indexer whose view is in "Update by Schedule" mode or not
     *
     * @param bool $scheduled
     * @param int $stateVersion
     * @param int|\Exception $changelogVersion version, or what reading it throws
     * @return IndexerInterfaceFactory
     */
    private function indexerFactory(
        bool $scheduled,
        int $stateVersion = 0,
        int|\Exception $changelogVersion = 0
    ): IndexerInterfaceFactory {
        $changelog = $this->createStub(ChangelogInterface::class);
        $changelog->method('getName')->willReturn('catalog_product_price_cl');
        if ($changelogVersion instanceof \Exception) {
            $changelog->method('getVersion')->willThrowException($changelogVersion);
        } else {
            $changelog->method('getVersion')->willReturn($changelogVersion);
        }
        $view = $this->createStub(ViewInterface::class);
        $view->method('isEnabled')->willReturn($scheduled);
        $view->method('getId')->willReturn('catalog_product_price');
        $view->method('getState')->willReturn(
            $this->createConfiguredStub(StateInterface::class, [
                'getVersionId' => $stateVersion,
            ])
        );
        $view->method('getChangelog')->willReturn($changelog);
        $indexer = $this->createStub(IndexerInterface::class);
        $indexer->method('load')->willReturnSelf();
        $indexer->method('getView')->willReturn($view);
        $factory = $this->createStub(IndexerInterfaceFactory::class);
        $factory->method('create')->willReturn($indexer);
        return $factory;
    }
}
