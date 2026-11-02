<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Test\Unit\Model\Export;

use Orangecat\IndexerBench\Model\Export\RunExporter;
use Orangecat\IndexerBench\Model\Report\MetricOrder;
use Orangecat\IndexerBench\Model\Report\ValueFormatter;
use Orangecat\IndexerBench\Model\Run\StoredRun;
use PHPUnit\Framework\TestCase;

class RunExporterTest extends TestCase
{
    public function testMarkdownIsReadyToPasteUnderDocsBenchmarks(): void
    {
        $markdown = $this->exporter()->markdown($this->storedRun());

        $this->assertSame(<<<'MD'
            ## catalog_product_price · boost · run #19

            | Started (UTC) | Mode | Kind | Repeat | Status |
            |---|---|---|---:|---|
            | 2026-10-31 10:00:00 | candidate | partial (500 IDs, seed 42) | 2 | ok |

            | Metric | Value |
            |---|---:|
            | wall_time_s | 2 431.2 |
            | peak_memory_mb | 412 |
            | sql_queries | n/a |
            | index_rows | 8 412 330 |

            Environment:
            - Magento 2.4.9 Community, PHP 8.5.12, MariaDB 10.11.19
            - 2 040 products, 4 threads
            - git a1b2c3d, host bench-01

            Notes: price \| dimensions on
            MD . "\n", $markdown);
    }

    public function testMarkdownLeavesOutWhatTheRunDoesNotHave(): void
    {
        $run = new StoredRun(
            7,
            'catalog_category_product',
            'core',
            'baseline',
            'full',
            0,
            true,
            false,
            'failed',
            '2026-10-31 09:00:00',
            null,
            []
        );

        $markdown = $this->exporter()->markdown($run);

        $this->assertStringContainsString(
            '| 2026-10-31 09:00:00 | baseline | full | 0 | failed (warm-up) |',
            $markdown
        );
        $this->assertStringEndsWith("| Metric | Value |\n|---|---:|\n", $markdown);
    }

    public function testJsonHoldsTheWholeRunWithRawValues(): void
    {
        $data = json_decode($this->exporter()->json($this->storedRun()), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(19, $data['run_id']);
        $this->assertSame(['partial', 500, 42, 2, false, false, 'ok'], [
            $data['kind'], $data['sample_size'], $data['seed'], $data['repeat_index'], $data['warmup'],
            $data['profiled'], $data['status'],
        ]);
        $this->assertSame(
            ['wall_time_s' => 2431.2, 'peak_memory_mb' => 412, 'sql_queries' => null, 'index_rows' => 8412330],
            $data['metrics']
        );
        $this->assertSame('bench-01', $data['fingerprint']['hostname']);
        $this->assertSame('price | dimensions on', $data['notes']);
    }

    /**
     * Exporter with the real formatting
     *
     * @return RunExporter
     */
    private function exporter(): RunExporter
    {
        return new RunExporter(new MetricOrder(), new ValueFormatter());
    }

    /**
     * Partial candidate run with a fingerprint
     *
     * @return StoredRun
     */
    private function storedRun(): StoredRun
    {
        return new StoredRun(
            19,
            'catalog_product_price',
            'boost',
            'candidate',
            'partial',
            2,
            false,
            false,
            'ok',
            '2026-10-31 10:00:00',
            [
                'magento' => ['version' => '2.4.9', 'edition' => 'Community'],
                'php' => '8.5.12',
                'database' => ['engine' => 'MariaDB', 'version' => '10.11.19', 'variables' => []],
                'catalog' => ['products' => 2040],
                'threads' => 4,
                'git_commit' => 'a1b2c3d',
                'hostname' => 'bench-01',
            ],
            ['index_rows' => 8412330.0, 'peak_memory_mb' => 412.0, 'sql_queries' => null, 'wall_time_s' => 2431.2],
            'price | dimensions on',
            500,
            42
        );
    }
}
