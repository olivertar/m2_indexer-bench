<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Test\Unit\Model\Snapshot;

use Orangecat\IndexerBench\Model\Snapshot\RowSerializer;
use PHPUnit\Framework\TestCase;

class RowSerializerTest extends TestCase
{
    public function testKeyColumnsFirstThenTheOthersByNameWithExplicitNull(): void
    {
        $line = (new RowSerializer())->serialize(
            ['price' => '10.500000', 'website_id' => '1', 'entity_id' => '42', 'min_price' => null],
            ['entity_id', 'website_id']
        );

        $this->assertSame("42\t1\t\\N\t10.500000\n", $line);
    }

    public function testSeparatorsAndBackslashesInValuesAreEscaped(): void
    {
        $line = (new RowSerializer())->serialize(['id' => '1', 'value' => "a\tb\nc\rd\\N"], ['id']);

        $this->assertSame("1\ta\\tb\\nc\\rd\\\\N\n", $line);
    }

    public function testHeaderNamesTheColumnsInTheSameOrder(): void
    {
        $this->assertSame(
            "#entity_id\twebsite_id\tmin_price\tprice\n",
            (new RowSerializer())->header(
                ['price', 'website_id', 'entity_id', 'min_price'],
                ['entity_id', 'website_id']
            )
        );
    }
}
