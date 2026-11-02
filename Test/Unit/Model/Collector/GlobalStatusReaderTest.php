<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Test\Unit\Model\Collector;

use Orangecat\IndexerBench\Model\Collector\GlobalStatusReader;
use Orangecat\IndexerBoost\Model\Doctor\Db\ReadOnlyConnection;
use Orangecat\IndexerBoost\Test\Unit\Fake\WriteGuardAdapterTrait;
use PHPUnit\Framework\TestCase;

class GlobalStatusReaderTest extends TestCase
{
    use WriteGuardAdapterTrait;

    public function testReadsTheServerCountersTheMetricsUse(): void
    {
        $queries = [];
        $connection = new ReadOnlyConnection($this->createResourceConnection($this->createWriteGuardAdapter(
            static function (string $sql, string $method) use (&$queries): array {
                $queries[] = [$method, $sql];
                return ['Innodb_rows_read' => '1500', 'Created_tmp_disk_tables' => '3'];
            }
        )));

        $status = (new GlobalStatusReader($connection))->read();

        $this->assertSame(['Innodb_rows_read' => 1500.0, 'Created_tmp_disk_tables' => 3.0], $status);
        $this->assertSame('fetchPairs', $queries[0][0]);
        $this->assertSame(
            "SHOW GLOBAL STATUS WHERE Variable_name IN ('Innodb_rows_read', 'Innodb_rows_inserted', "
            . "'Innodb_rows_updated', 'Innodb_rows_deleted', 'Created_tmp_tables', 'Created_tmp_disk_tables', "
            . "'Innodb_row_lock_waits', 'Innodb_row_lock_time', 'Handler_read_rnd_next', 'Handler_read_first', "
            . "'Handler_read_key', 'Handler_read_last', 'Handler_read_next', 'Handler_read_prev', 'Handler_read_rnd', "
            . "'Handler_write', 'Handler_update', 'Handler_delete')",
            $queries[0][1]
        );
    }
}
