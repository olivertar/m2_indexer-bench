<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Model\Snapshot;

/**
 * Canonical text form of index rows: one line per row, tab-separated, key columns first, then the others by name
 */
class RowSerializer
{
    public const NULL = '\N';

    /**
     * Escapes keeping one row per line and NULL distinct from the text "\N"
     */
    private const ESCAPES = ['\\' => '\\\\', "\t" => '\t', "\n" => '\n', "\r" => '\r'];

    /**
     * Header line naming the columns in serialization order
     *
     * @param string[] $columns
     * @param string[] $keyColumns
     * @return string
     */
    public function header(array $columns, array $keyColumns): string
    {
        return '#' . implode("\t", $this->columnOrder($columns, $keyColumns)) . "\n";
    }

    /**
     * One row as a line
     *
     * @param array<string,mixed> $row
     * @param string[] $keyColumns
     * @return string
     */
    public function serialize(array $row, array $keyColumns): string
    {
        $ordered = [];
        foreach ($this->columnOrder(array_keys($row), $keyColumns) as $column) {
            $ordered[] = $row[$column];
        }
        return $this->line($ordered);
    }

    /**
     * One row whose values are already in serialization order
     *
     * @param array<int|string,mixed> $values
     * @return string
     */
    public function line(array $values): string
    {
        $escaped = [];
        foreach ($values as $value) {
            $escaped[] = $value === null ? self::NULL : strtr((string)$value, self::ESCAPES);
        }
        return implode("\t", $escaped) . "\n";
    }

    /**
     * Key columns in their order, then the remaining columns sorted by name
     *
     * @param string[] $columns
     * @param string[] $keyColumns
     * @return string[]
     */
    public function columnOrder(array $columns, array $keyColumns): array
    {
        $others = array_values(array_diff($columns, $keyColumns));
        sort($others, SORT_STRING);
        return array_merge($keyColumns, $others);
    }
}
