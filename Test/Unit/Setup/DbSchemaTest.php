<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Test\Unit\Setup;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Benchmark tables follow SPEC 5.5 and the declarative schema whitelist
 */
class DbSchemaTest extends TestCase
{
    private const ETC = __DIR__ . '/../../../etc';

    /**
     * @return array<string,array{string,string[]}>
     */
    public static function tableProvider(): array
    {
        return [
            'run' => ['orangecat_indexer_bench_run', [
                'run_id', 'indexer_id', 'label', 'mode', 'kind', 'sample_size', 'seed', 'repeat_index',
                'started_at', 'finished_at', 'status', 'error', 'fingerprint', 'notes',
            ]],
            'metric' => ['orangecat_indexer_bench_metric', ['run_id', 'metric_code', 'value']],
            'snapshot' => ['orangecat_indexer_bench_snapshot', [
                'snapshot_id', 'indexer_id', 'label', 'table_name', 'row_count', 'md5', 'file_path', 'created_at',
            ]],
        ];
    }

    /**
     * @param string $table
     * @param string[] $columns
     */
    #[DataProvider('tableProvider')]
    public function testTableHasTheSpecColumnsAndIsWhitelisted(string $table, array $columns): void
    {
        $declared = array_map('strval', $this->schema()->xpath("//table[@name='{$table}']/column/@name") ?: []);
        $whitelist = $this->whitelist()[$table]['column'] ?? [];

        $this->assertSame([], array_values(array_diff($columns, $declared)), 'missing columns in ' . $table);
        $this->assertEqualsCanonicalizing($declared, array_keys($whitelist), 'whitelist of ' . $table);
    }

    public function testMetricRowsBelongToARun(): void
    {
        $primary = $this->schema()->xpath(
            "//table[@name='orangecat_indexer_bench_metric']/constraint[@xsi:type='primary']/column/@name"
        ) ?: [];
        $foreign = $this->schema()->xpath(
            "//table[@name='orangecat_indexer_bench_metric']/constraint[@xsi:type='foreign']"
        ) ?: [];

        $this->assertSame(['run_id', 'metric_code'], array_map('strval', $primary));
        $this->assertCount(1, $foreign);
        $this->assertSame(
            ['run_id', 'orangecat_indexer_bench_run', 'run_id', 'CASCADE'],
            [
                (string)$foreign[0]['column'],
                (string)$foreign[0]['referenceTable'],
                (string)$foreign[0]['referenceColumn'],
                (string)$foreign[0]['onDelete'],
            ]
        );
    }

    /**
     * @return \SimpleXMLElement
     */
    private function schema(): \SimpleXMLElement
    {
        $xml = simplexml_load_file(self::ETC . '/db_schema.xml');
        $this->assertNotFalse($xml);
        $xml->registerXPathNamespace('xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        return $xml;
    }

    /**
     * @return array<string,array<string,array<string,bool>>>
     */
    private function whitelist(): array
    {
        return json_decode((string)file_get_contents(self::ETC . '/db_schema_whitelist.json'), true);
    }
}
