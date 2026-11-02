<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Model\Collector;

use Orangecat\IndexerBoost\Model\Doctor\Db\ReadOnlyConnection;

/**
 * Server-wide status counters (MySQL and MariaDB); they include every connection, forked indexer workers too
 */
class GlobalStatusReader
{
    public const VARIABLES = [
        'Innodb_rows_read',
        'Innodb_rows_inserted',
        'Innodb_rows_updated',
        'Innodb_rows_deleted',
        'Created_tmp_tables',
        'Created_tmp_disk_tables',
        'Innodb_row_lock_waits',
        'Innodb_row_lock_time',
        'Handler_read_rnd_next',
        'Handler_read_first',
        'Handler_read_key',
        'Handler_read_last',
        'Handler_read_next',
        'Handler_read_prev',
        'Handler_read_rnd',
        'Handler_write',
        'Handler_update',
        'Handler_delete',
    ];

    /**
     * @param ReadOnlyConnection $connection
     */
    public function __construct(
        private readonly ReadOnlyConnection $connection
    ) {
    }

    /**
     * Current values; a variable the server does not have is missing
     *
     * @return array<string,float>
     */
    public function read(): array
    {
        $pairs = $this->connection->fetchPairs(sprintf(
            'SHOW GLOBAL STATUS WHERE Variable_name IN (%s)',
            implode(', ', array_map(static fn (string $name): string => "'" . $name . "'", self::VARIABLES))
        ));
        $values = [];
        foreach ($pairs as $name => $value) {
            $values[(string)$name] = (float)$value;
        }
        return $values;
    }
}
