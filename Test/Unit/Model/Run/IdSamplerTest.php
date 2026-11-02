<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Test\Unit\Model\Run;

use Orangecat\IndexerBench\Model\Run\IdSampler;
use PHPUnit\Framework\TestCase;

class IdSamplerTest extends TestCase
{
    public function testSameSeedGivesTheSameSortedSubsetWhateverTheUniverseOrder(): void
    {
        $universe = range(1, 1000);
        $sampler = new IdSampler();

        $sample = $sampler->sample($universe, 25, 42);

        $this->assertCount(25, $sample);
        $this->assertSame($sample, array_values(array_unique($sample)));
        $this->assertSame([], array_diff($sample, $universe));
        $sorted = $sample;
        sort($sorted);
        $this->assertSame($sorted, $sample);
        $this->assertSame($sample, $sampler->sample(array_reverse($universe), 25, 42));
        $this->assertNotSame($sample, $sampler->sample($universe, 25, 43));
    }

    public function testSampleIsFixedAcrossPhpVersions(): void
    {
        // Baseline and candidate may run on different PHP versions: they must still reindex the same IDs
        $this->assertSame(
            [61, 69, 95, 166, 216, 543, 586, 619, 817, 943],
            (new IdSampler())->sample(range(1, 1000), 10, 42)
        );
    }

    public function testSampleLargerThanTheUniverseIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot sample 5 IDs out of 3 entities');
        (new IdSampler())->sample([1, 2, 3], 5, 42);
    }
}
