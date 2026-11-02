<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Model\Safeguard;

use Magento\Cron\Model\ConfigInterface as CronConfig;
use Magento\Framework\Indexer\IndexerInterfaceFactory;
use Magento\Framework\Mview\View\ChangelogTableNotExistsException;
use Orangecat\IndexerBoost\Model\Doctor\Db\ReadOnlyConnection;

/**
 * Activity that would run during a measurement and distort it: index cron jobs and pending changelog entries
 */
class QuietnessCheck
{
    /**
     * Cron group of indexer_reindex_all_invalid, indexer_update_all_views and indexer_clean_all_changelogs
     */
    private const INDEX_CRON_GROUP = 'index';

    /**
     * @param ReadOnlyConnection $connection
     * @param CronConfig $cronConfig
     * @param IndexerInterfaceFactory $indexerFactory
     */
    public function __construct(
        private readonly ReadOnlyConnection $connection,
        private readonly CronConfig $cronConfig,
        private readonly IndexerInterfaceFactory $indexerFactory
    ) {
    }

    /**
     * Problems found before measuring an indexer, empty when the installation is quiet
     *
     * @param string $indexerId
     * @return string[]
     */
    public function findProblems(string $indexerId): array
    {
        return array_merge($this->findRunningIndexCronJobs(), $this->findPendingChangelog($indexerId));
    }

    /**
     * Changelog versions of the indexer not yet applied, only in "Update by Schedule" mode
     *
     * @param string $indexerId
     * @return string[]
     */
    private function findPendingChangelog(string $indexerId): array
    {
        $view = $this->indexerFactory->create()->load($indexerId)->getView();
        if (!$view->isEnabled()) {
            return [];
        }
        $changelog = $view->getChangelog();
        try {
            $pending = (int)$changelog->getVersion() - (int)$view->getState()->getVersionId();
        } catch (ChangelogTableNotExistsException $e) {
            return [sprintf(
                'Changelog %s is missing although the indexer is "Update by Schedule": %s',
                $changelog->getName(),
                $e->getMessage()
            )];
        }
        if ($pending <= 0) {
            return [];
        }
        return [sprintf(
            'Changelog %s has %d versions pending: the index cron would apply them while measuring',
            $changelog->getName(),
            $pending
        )];
    }

    /**
     * Index cron jobs whose cron_schedule row is "running"
     *
     * @return string[]
     */
    private function findRunningIndexCronJobs(): array
    {
        $jobs = array_keys($this->cronConfig->getJobs()[self::INDEX_CRON_GROUP] ?? []);
        if (!$jobs) {
            return [];
        }
        $running = $this->connection->fetchPairs(
            sprintf(
                "SELECT job_code, MAX(executed_at) FROM %s WHERE status = 'running' AND job_code IN (%s) "
                . 'GROUP BY job_code',
                $this->connection->getTableName('cron_schedule'),
                implode(', ', array_fill(0, count($jobs), '?'))
            ),
            $jobs
        );
        $problems = [];
        foreach ($running as $job => $since) {
            $problems[] = sprintf('Index cron job running: %s (since %s UTC)', $job, $since);
        }
        return $problems;
    }
}
