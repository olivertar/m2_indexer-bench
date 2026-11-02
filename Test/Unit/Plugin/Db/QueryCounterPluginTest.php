<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Test\Unit\Plugin\Db;

use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Orangecat\IndexerBench\Model\Collector\QueryCounter;
use Orangecat\IndexerBench\Plugin\Db\QueryCounterPlugin;
use Orangecat\IndexerBoost\Model\Monitor\Clock;
use PHPUnit\Framework\TestCase;

class QueryCounterPluginTest extends TestCase
{
    public function testOutsideAMeasurementTheStatementRunsWithoutTiming(): void
    {
        $clock = $this->createMock(Clock::class);
        $clock->expects($this->never())->method('monotonic');
        $statement = new \stdClass();

        $result = (new QueryCounterPlugin(new QueryCounter(), $clock))->aroundQuery(
            $this->createStub(Mysql::class),
            static fn (string $sql, array $bind): \stdClass => $statement,
            'SELECT 1',
            []
        );

        $this->assertSame($statement, $result);
    }

    public function testDuringAMeasurementEveryStatementIsTimedEvenWhenItFails(): void
    {
        $counter = new QueryCounter();
        $counter->start(1.0);
        $clock = $this->createStub(Clock::class);
        $clock->method('monotonic')->willReturnOnConsecutiveCalls(10.0, 10.5, 20.0, 21.5);
        $plugin = new QueryCounterPlugin($counter, $clock);
        $adapter = $this->createStub(Mysql::class);
        $error = new \Zend_Db_Statement_Exception('Deadlock found');

        $plugin->aroundQuery($adapter, static fn (): \stdClass => new \stdClass(), 'SELECT 1');
        try {
            $plugin->aroundQuery($adapter, static fn (): never => throw $error, 'UPDATE t SET a = 1');
            $this->fail('Exception swallowed');
        } catch (\Zend_Db_Statement_Exception $e) {
            $this->assertSame($error, $e);
        }

        $this->assertSame([2, 2.0, 1], [$counter->getQueries(), $counter->getSeconds(), $counter->getSlowQueries()]);
    }
}
