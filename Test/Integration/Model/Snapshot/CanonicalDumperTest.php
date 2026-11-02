<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Test\Integration\Model\Snapshot;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use Orangecat\IndexerBench\Model\Snapshot\CanonicalDumper;
use Orangecat\IndexerBench\Model\Snapshot\DiffLine;
use Orangecat\IndexerBench\Model\Snapshot\SnapshotDiff;
use PHPUnit\Framework\TestCase;

/**
 * Canonical dump of small fixture tables on the real database server (DDL commits, so no transaction isolation)
 */
#[DbIsolation(false)]
class CanonicalDumperTest extends TestCase
{
    private const BASELINE = 'zz_bench_dump_baseline';
    private const CANDIDATE = 'zz_bench_dump_candidate';
    private const NO_KEY = 'zz_bench_dump_no_key';
    private const KEY = ['entity_id', 'store_id'];

    /**
     * Fixture rows: NULLs, a tab, a backslash, multibyte text and keys that sort differently as text and as numbers
     */
    private const ROWS = [
        [10, 0, 'ten', '10.5000'],
        [2, 1, null, null],
        [2, 0, "tab\there", '0.0000'],
        [1, 0, 'back\\slash', '1.0000'],
        [11, 0, 'ñandú €', '99.9900'],
        [3, 0, '', '-3.2500'],
        [1, 1, 'one/one', '1.0001'],
    ];

    /**
     * @var AdapterInterface
     */
    private AdapterInterface $connection;

    /**
     * @var string[]
     */
    private array $files = [];

    protected function setUp(): void
    {
        $this->connection = Bootstrap::getObjectManager()->get(ResourceConnection::class)->getConnection();
        foreach ([self::BASELINE, self::CANDIDATE] as $table) {
            $this->connection->query(sprintf(
                'CREATE TABLE %s (entity_id INT UNSIGNED NOT NULL, store_id SMALLINT UNSIGNED NOT NULL, '
                . 'value VARCHAR(64) NULL, price DECIMAL(12,4) NULL, PRIMARY KEY (entity_id, store_id)) '
                . 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
                $table
            ));
        }
        $this->connection->query(
            'CREATE TABLE ' . self::NO_KEY . ' (entity_id INT UNSIGNED NOT NULL, store_id SMALLINT UNSIGNED NOT NULL)'
        );
        $this->insert(self::BASELINE, self::ROWS);
        // Same rows, inserted in another order
        $this->insert(self::CANDIDATE, array_reverse(self::ROWS));
    }

    protected function tearDown(): void
    {
        foreach ([self::BASELINE, self::CANDIDATE, self::NO_KEY] as $table) {
            $this->connection->query('DROP TABLE IF EXISTS ' . $table);
        }
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testSameRowsInAnotherPhysicalOrderGiveTheSameBytesAcrossBatches(): void
    {
        $baseline = $this->dumper()->dump(self::BASELINE, self::KEY, $this->path('baseline'));
        $candidate = $this->dumper()->dump(self::CANDIDATE, self::KEY, $this->path('candidate'));

        $this->assertSame(7, $baseline->rows);
        $this->assertSame($baseline->md5, $candidate->md5);
        $this->assertSame(
            "#entity_id\tstore_id\tprice\tvalue\n"
            . "1\t0\t1.0000\tback\\\\slash\n"
            . "1\t1\t1.0001\tone/one\n"
            . "2\t0\t0.0000\ttab\\there\n"
            . "2\t1\t\\N\t\\N\n"
            . "3\t0\t-3.2500\t\n"
            . "10\t0\t10.5000\tten\n"
            . "11\t0\t99.9900\tñandú €\n",
            (string)file_get_contents((string)$baseline->filePath)
        );
    }

    public function testAChangedValueChangesTheChecksumAndShowsInTheDiff(): void
    {
        $this->connection->update(self::CANDIDATE, ['price' => '10.5001'], ['entity_id = ?' => 10]);
        $this->connection->delete(self::CANDIDATE, ['entity_id = ?' => 3]);

        $baseline = $this->dumper()->dump(self::BASELINE, self::KEY, $this->path('baseline'));
        $candidate = $this->dumper()->dump(self::CANDIDATE, self::KEY, $this->path('candidate'));
        $differences = Bootstrap::getObjectManager()->create(SnapshotDiff::class)->firstDifferences(
            (string)$baseline->filePath,
            (string)$candidate->filePath,
            count(self::KEY),
            10
        );

        $this->assertNotSame($baseline->md5, $candidate->md5);
        $this->assertSame(
            [[DiffLine::MISSING, "3\t0"], [DiffLine::CHANGED, "10\t0"]],
            array_map(static fn (DiffLine $line): array => [$line->type, $line->key], $differences)
        );
    }

    public function testTableWithoutAUniqueKeyIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not cover a unique key');

        $this->dumper()->dump(self::NO_KEY, self::KEY);
    }

    /**
     * Dumper reading 3 rows per query, so the fixture spans several batches
     *
     * @return CanonicalDumper
     */
    private function dumper(): CanonicalDumper
    {
        return Bootstrap::getObjectManager()->create(CanonicalDumper::class, ['batchSize' => 3]);
    }

    /**
     * Dump file in the var/ directory of the test installation
     *
     * @param string $name
     * @return string
     */
    private function path(string $name): string
    {
        $path = Bootstrap::getObjectManager()->get(DirectoryList::class)->getPath(DirectoryList::VAR_DIR)
            . '/indexer-bench-test/' . $name . '.tsv';
        $this->files[] = $path;
        return $path;
    }

    /**
     * @param string $table
     * @param array<int,array{int,int,string|null,string|null}> $rows
     * @return void
     */
    private function insert(string $table, array $rows): void
    {
        foreach ($rows as [$entityId, $storeId, $value, $price]) {
            $this->connection->insert(
                $table,
                ['entity_id' => $entityId, 'store_id' => $storeId, 'value' => $value, 'price' => $price]
            );
        }
    }
}
