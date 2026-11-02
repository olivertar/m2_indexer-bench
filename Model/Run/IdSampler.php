<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Model\Run;

use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Reproducible sample of entity IDs: the same universe, size and seed always give the same IDs
 */
class IdSampler
{
    /**
     * Sorted sample of distinct IDs
     *
     * A partial Fisher-Yates shuffle over the sorted universe, driven by Randomizer::getInt() on Mt19937, whose
     * output is fixed for a seed (unlike shuffle() or pickArrayKeys(), whose algorithm may change).
     *
     * @param int[] $universe
     * @param int $count
     * @param int $seed
     * @return int[]
     * @throws \InvalidArgumentException when the universe is smaller than the sample
     */
    public function sample(array $universe, int $count, int $seed): array
    {
        $ids = array_values(array_unique(array_map('intval', $universe)));
        sort($ids);
        $total = count($ids);
        if ($count > $total) {
            throw new \InvalidArgumentException(sprintf('Cannot sample %d IDs out of %d entities', $count, $total));
        }
        $randomizer = new Randomizer(new Mt19937($seed));
        for ($i = 0; $i < $count; $i++) {
            $pick = $randomizer->getInt($i, $total - 1);
            [$ids[$i], $ids[$pick]] = [$ids[$pick], $ids[$i]];
        }
        $sample = array_slice($ids, 0, $count);
        sort($sample);
        return $sample;
    }
}
