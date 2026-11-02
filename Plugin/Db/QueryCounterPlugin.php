<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Plugin\Db;

use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Orangecat\IndexerBench\Model\Collector\QueryCounter;
use Orangecat\IndexerBoost\Model\Monitor\Clock;

/**
 * Counts and times the SQL statements of this process during a benchmark measurement
 *
 * Mysql::query() is where every statement of the adapter goes, including fetch*() and insertMultiple(), and the
 * interceptor also catches the adapter's own internal calls.
 */
class QueryCounterPlugin
{
    /**
     * @param QueryCounter $counter
     * @param Clock $clock
     */
    public function __construct(
        private readonly QueryCounter $counter,
        private readonly Clock $clock
    ) {
    }

    /**
     * Time the statement while a measurement is active
     *
     * @param Mysql $subject
     * @param callable $proceed
     * @param mixed $sql
     * @param mixed $bind
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundQuery(Mysql $subject, callable $proceed, $sql, $bind = []): mixed
    {
        if (!$this->counter->isActive()) {
            return $proceed($sql, $bind);
        }
        $start = $this->clock->monotonic();
        try {
            return $proceed($sql, $bind);
        } finally {
            $this->counter->record($this->clock->monotonic() - $start);
        }
    }
}
