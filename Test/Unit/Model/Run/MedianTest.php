<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Test\Unit\Model\Run;

use Orangecat\IndexerBench\Model\Run\Median;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MedianTest extends TestCase
{
    /**
     * @param array<int,float|null> $values
     */
    #[DataProvider('valuesProvider')]
    public function testMedianOfValuesIgnoresNotAvailable(array $values, ?float $expected): void
    {
        $this->assertSame($expected, (new Median())->of($values));
    }

    public function testMedianPerMetricOverEveryRepeat(): void
    {
        $this->assertSame(
            ['wall_time_s' => 2.0, 'sql_queries' => null, 'index_rows' => 10.0],
            (new Median())->ofMetrics([
                ['wall_time_s' => 1.0, 'sql_queries' => null, 'index_rows' => 10.0],
                ['wall_time_s' => 3.0, 'sql_queries' => null, 'index_rows' => 10.0],
                ['wall_time_s' => 2.0, 'sql_queries' => null],
            ])
        );
    }

    /**
     * @return array<string,array{array<int,float|null>,float|null}>
     */
    public static function valuesProvider(): array
    {
        return [
            'odd' => [[3.0, 1.0, 2.0], 2.0],
            'even' => [[4.0, 1.0, 3.0, 2.0], 2.5],
            'one' => [[7.5], 7.5],
            'with n/a' => [[null, 5.0, 1.0], 3.0],
            'all n/a' => [[null, null], null],
            'empty' => [[], null],
        ];
    }
}
