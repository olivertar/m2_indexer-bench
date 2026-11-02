<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Test\Unit\Model\Fingerprint;

use Orangecat\IndexerBench\Model\Fingerprint\FingerprintComparator;
use PHPUnit\Framework\TestCase;

class FingerprintComparatorTest extends TestCase
{
    private const BASELINE = [
        'magento' => ['version' => '2.4.9', 'edition' => 'Community'],
        'php' => '8.5.12',
        'database' => ['engine' => 'MariaDB', 'version' => '10.11.19', 'variables' => ['tmp_table_size' => '16777216']],
        'catalog' => ['products' => 2040, 'categories' => 30],
        'price_dimensions_mode' => 'none',
        'threads' => null,
        'batch_size' => null,
        'modules' => ['Orangecat_IndexerBoost'],
        'indexer_boost_flags' => ['catalog_product_price' => '0'],
        'git_commit' => 'aaa',
        'hostname' => 'staging-1',
    ];

    public function testWhatTheCandidateIsExpectedToChangeDoesNotInvalidateTheComparison(): void
    {
        $candidate = array_replace(self::BASELINE, [
            'modules' => ['Orangecat_IndexerBench', 'Orangecat_IndexerBoost'],
            'indexer_boost_flags' => ['catalog_product_price' => '1'],
            'git_commit' => 'bbb',
        ]);

        $this->assertSame([], (new FingerprintComparator())->differences(self::BASELINE, $candidate));
    }

    public function testCatalogServerAndSettingDifferencesAreListed(): void
    {
        $candidate = array_replace_recursive(self::BASELINE, [
            'catalog' => ['products' => 24040],
            'database' => ['variables' => ['tmp_table_size' => '134217728', 'max_heap_table_size' => '134217728']],
            'threads' => 4,
        ]);

        $this->assertSame(
            [
                'database.variables.tmp_table_size: 16777216 -> 134217728',
                'database.variables.max_heap_table_size: (none) -> 134217728',
                'catalog.products: 2040 -> 24040',
                'threads: (none) -> 4',
            ],
            (new FingerprintComparator())->differences(self::BASELINE, $candidate)
        );
    }

    public function testRunsWithoutFingerprintCannotBeChecked(): void
    {
        $this->assertSame(
            ['fingerprint missing on one of the runs'],
            (new FingerprintComparator())->differences([], self::BASELINE)
        );
    }
}
