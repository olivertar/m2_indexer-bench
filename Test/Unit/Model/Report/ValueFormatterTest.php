<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Test\Unit\Model\Report;

use Orangecat\IndexerBench\Model\Report\ValueFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ValueFormatterTest extends TestCase
{
    #[DataProvider('valueProvider')]
    public function testValueHasThousandsSeparatorAndNoTrailingZeros(?float $value, string $expected): void
    {
        $this->assertSame($expected, (new ValueFormatter())->value($value));
    }

    public function testDeltaIsSignedWithOneDecimalOrADashWhenNotAvailable(): void
    {
        $formatter = new ValueFormatter();

        $this->assertSame(['-74.8%', '+25.0%', '+0.0%', '—'], [
            $formatter->delta(-74.8123),
            $formatter->delta(25.0),
            $formatter->delta(0.0),
            $formatter->delta(null),
        ]);
    }

    /**
     * @return array<string,array{float|null,string}>
     */
    public static function valueProvider(): array
    {
        return [
            'thousands' => [2431.2, '2 431.2'],
            'integer' => [8412330.0, '8 412 330'],
            'three decimals' => [0.0125, '0.013'],
            'zero' => [0.0, '0'],
            'not available' => [null, 'n/a'],
        ];
    }
}
