<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Test\Unit\Model\Collector;

use Orangecat\IndexerBench\Model\Collector\QueryCounter;
use PHPUnit\Framework\TestCase;

class QueryCounterTest extends TestCase
{
    public function testCountsOnlyWhileActiveAndFlagsSlowQueries(): void
    {
        $counter = new QueryCounter();
        $counter->record(5.0);

        $counter->start(1.0);
        $counter->record(0.25);
        $counter->record(1.0);
        $counter->record(2.5);
        $counter->stop();
        $counter->record(9.0);

        $this->assertFalse($counter->isActive());
        $this->assertSame([3, 3.75, 2], [$counter->getQueries(), $counter->getSeconds(), $counter->getSlowQueries()]);
    }

    public function testStartResetsTheCounts(): void
    {
        $counter = new QueryCounter();
        $counter->start(1.0);
        $counter->record(3.0);

        $counter->start(1.0);

        $this->assertTrue($counter->isActive());
        $this->assertSame([0, 0.0, 0], [$counter->getQueries(), $counter->getSeconds(), $counter->getSlowQueries()]);
    }
}
