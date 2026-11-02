<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Model\Report;

/**
 * How metric values are shown in reports
 */
class ValueFormatter
{
    /**
     * Value with a space as thousands separator and no trailing zeros, n/a when not available
     *
     * @param float|null $value
     * @return string
     */
    public function value(?float $value): string
    {
        return $value === null ? 'n/a' : rtrim(rtrim(number_format($value, 3, '.', ' '), '0'), '.');
    }

    /**
     * Change in % with its sign and one decimal, — when not available
     *
     * @param float|null $deltaPct
     * @return string
     */
    public function delta(?float $deltaPct): string
    {
        return $deltaPct === null ? '—' : sprintf('%+.1f%%', $deltaPct);
    }
}
