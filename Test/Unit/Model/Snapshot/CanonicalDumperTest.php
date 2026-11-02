<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Test\Unit\Model\Snapshot;

use Magento\Framework\Filesystem\Driver\File;
use Orangecat\IndexerBench\Model\Snapshot\CanonicalDumper;
use Orangecat\IndexerBench\Model\Snapshot\RowSerializer;
use Orangecat\IndexerBoost\Model\Doctor\Db\ReadOnlyConnection;
use Orangecat\IndexerBoost\Test\Unit\Fake\WriteGuardAdapterTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CanonicalDumperTest extends TestCase
{
    use WriteGuardAdapterTrait;

    /**
     * @var array<int,array<string,mixed>> rows of the fake table, in key order
     */
    private array $rows = [];

    /**
     * @var array<int,array{string,array<int,mixed>}> SELECT statements with their binds
     */
    private array $selects = [];

    /**
     * @var bool whether the fake table has an auto-increment surrogate key column
     */
    private bool $surrogate = false;

    /**
     * @var bool whether website_id, part of the unique key, is declared nullable
     */
    private bool $nullableKey = false;

    /**
     * @var bool whether a row has NULL in website_id
     */
    private bool $nullInKey = false;

    public function testTableIsReadInKeyOrderBatchesAfterTheLastKey(): void
    {
        $this->rows = [
            ['entity_id' => '1', 'website_id' => '1', 'price' => '10.000000'],
            ['entity_id' => '1', 'website_id' => '2', 'price' => null],
            ['entity_id' => '2', 'website_id' => '1', 'price' => '7.500000'],
        ];

        $dump = $this->dumper(2)->dump('pfx_price', ['entity_id', 'website_id']);

        $this->assertSame(
            [
                ['SELECT `entity_id`, `website_id`, `price` FROM `pfx_price` '
                    . 'ORDER BY `entity_id`, `website_id` LIMIT 2', []],
                ['SELECT `entity_id`, `website_id`, `price` FROM `pfx_price` '
                    . 'WHERE (`entity_id` > ?) OR (`entity_id` = ? AND `website_id` > ?) '
                    . 'ORDER BY `entity_id`, `website_id` LIMIT 2', ['1', '1', '2']],
            ],
            $this->selects
        );
        $expected = "#entity_id\twebsite_id\tprice\n1\t1\t10.000000\n1\t2\t\\N\n2\t1\t7.500000\n";
        $this->assertSame(['pfx_price', 3, hash('md5', $expected)], [$dump->table, $dump->rows, $dump->md5]);
        $this->assertNoWriteAttempts();
    }

    public function testSurrogateAutoIncrementColumnOutsideTheKeyIsLeftOut(): void
    {
        $this->surrogate = true;
        $this->rows = [['entity_id' => '1', 'website_id' => '1', 'price' => '10.000000']];

        $dump = $this->dumper(2)->dump('pfx_price', ['entity_id', 'website_id']);

        $this->assertSame(
            'SELECT `entity_id`, `website_id`, `price` FROM `pfx_price` ORDER BY `entity_id`, `website_id` LIMIT 2',
            $this->selects[0][0]
        );
        $this->assertSame(hash('md5', "#entity_id\twebsite_id\tprice\n1\t1\t10.000000\n"), $dump->md5);
    }

    /**
     * @return array<string,array{string[]}>
     */
    public static function invalidKeyProvider(): array
    {
        return [
            'not unique' => [['entity_id']],
            'unknown column' => [['entity_id', 'store_id']],
            'empty' => [[]],
        ];
    }

    /**
     * @param string[] $key
     */
    #[DataProvider('invalidKeyProvider')]
    public function testKeyThatDoesNotIdentifyRowsIsRejectedBeforeReading(array $key): void
    {
        try {
            $this->dumper(2)->dump('pfx_price', $key);
            $this->fail('Invalid key accepted');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Canonical key of pfx_price', $e->getMessage());
        }
        $this->assertSame([], $this->selects);
    }

    public function testNullableKeyColumnIsAcceptedWhileItHoldsNoNull(): void
    {
        // The core declares nullable columns in unique keys, e.g. catalogrule_product.customer_group_id
        $this->nullableKey = true;
        $this->rows = [['entity_id' => '1', 'website_id' => '1', 'price' => '1.000000']];

        $dump = $this->dumper(2)->dump('pfx_price', ['entity_id', 'website_id']);

        $this->assertSame(1, $dump->rows);
        $this->assertContains('SELECT 1 FROM `pfx_price` WHERE `website_id` IS NULL LIMIT 1', $this->writeGuardQueries);
    }

    public function testNullableKeyColumnHoldingANullIsRejected(): void
    {
        $this->nullableKey = true;
        $this->nullInKey = true;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Canonical key of pfx_price has NULL values in website_id');

        $this->dumper(2)->dump('pfx_price', ['entity_id', 'website_id']);
    }

    public function testDumpFileHoldsExactlyTheHashedContent(): void
    {
        $this->rows = [['entity_id' => '1', 'website_id' => '1', 'price' => "a\tb"]];
        $path = sys_get_temp_dir() . '/indexer-bench-test-' . uniqid() . '/price.tsv';

        $dump = $this->dumper(2)->dump('pfx_price', ['entity_id', 'website_id'], $path);

        $contents = (string)file_get_contents($path);
        unlink($path);
        rmdir(dirname($path));
        $this->assertSame("#entity_id\twebsite_id\tprice\n1\t1\ta\\tb\n", $contents);
        $this->assertSame([hash('md5', $contents), $path], [$dump->md5, $dump->filePath]);
    }

    /**
     * Dumper over the fake table of this test
     *
     * @param int $batchSize
     * @return CanonicalDumper
     */
    private function dumper(int $batchSize): CanonicalDumper
    {
        $respond = function (string $sql, string $method, array $bind) use ($batchSize): array {
            if (str_starts_with($sql, 'SHOW COLUMNS')) {
                $columns = [
                    ['Field' => 'entity_id', 'Null' => 'NO', 'Extra' => ''],
                    ['Field' => 'website_id', 'Null' => $this->nullableKey ? 'YES' : 'NO', 'Extra' => ''],
                    ['Field' => 'price', 'Null' => 'YES', 'Extra' => ''],
                ];
                if ($this->surrogate) {
                    array_unshift($columns, ['Field' => 'price_id', 'Null' => 'NO', 'Extra' => 'auto_increment']);
                }
                return $columns;
            }
            if (str_starts_with($sql, 'SHOW INDEX')) {
                return [
                    ['Key_name' => 'PRIMARY', 'Non_unique' => '0', 'Column_name' => 'entity_id'],
                    ['Key_name' => 'PRIMARY', 'Non_unique' => '0', 'Column_name' => 'website_id'],
                ];
            }
            if (str_contains($sql, 'IS NULL')) {
                return $this->nullInKey ? ['1'] : [];
            }
            $this->selects[] = [$sql, $bind];
            $offset = count($this->selects) === 1 ? 0 : 2;
            return array_slice($this->rows, $offset, $batchSize);
        };
        $adapter = $this->createWriteGuardAdapter($respond);

        return new CanonicalDumper(
            new ReadOnlyConnection($this->createResourceConnection($adapter)),
            new RowSerializer(),
            new File(),
            $batchSize
        );
    }
}
