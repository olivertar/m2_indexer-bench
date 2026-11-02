<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Model\Fingerprint;

/**
 * Differences between two fingerprints that make a comparison of their runs unreliable
 *
 * Modules, Indexer Boost flags and the git commit are left out: a candidate is expected to change them.
 */
class FingerprintComparator
{
    /**
     * Sections compared, in report order
     */
    private const SECTIONS = [
        'magento', 'php', 'database', 'catalog', 'price_dimensions_mode', 'threads', 'batch_size', 'hostname',
    ];

    private const NONE = '(none)';

    /**
     * Differences as "path: baseline -> candidate"
     *
     * @param array<string,mixed> $baseline
     * @param array<string,mixed> $candidate
     * @return string[]
     */
    public function differences(array $baseline, array $candidate): array
    {
        if (!$baseline || !$candidate) {
            return ['fingerprint missing on one of the runs'];
        }
        $differences = [];
        foreach (self::SECTIONS as $section) {
            $left = $this->flatten($baseline[$section] ?? null, $section);
            $right = $this->flatten($candidate[$section] ?? null, $section);
            foreach (array_keys($left + $right) as $path) {
                $before = $left[$path] ?? self::NONE;
                $after = $right[$path] ?? self::NONE;
                if ($before !== $after) {
                    $differences[] = sprintf('%s: %s -> %s', $path, $before, $after);
                }
            }
        }
        return $differences;
    }

    /**
     * Leaf values by dotted path, as text
     *
     * @param mixed $value
     * @param string $path
     * @return array<string,string>
     */
    private function flatten(mixed $value, string $path): array
    {
        if (!is_array($value)) {
            return $value === null ? [] : [$path => is_bool($value) ? var_export($value, true) : (string)$value];
        }
        $leaves = [];
        foreach ($value as $key => $child) {
            $leaves += $this->flatten($child, $path . '.' . $key);
        }
        return $leaves;
    }
}
