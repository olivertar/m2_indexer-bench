<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Test\Unit\Console\Command;

use Magento\Framework\Exception\LocalizedException;
use Orangecat\IndexerBench\Console\Command\SnapshotCommand;
use Orangecat\IndexerBench\Model\Safeguard\ProductionGuard;
use Orangecat\IndexerBench\Model\Snapshot\DiffLine;
use Orangecat\IndexerBench\Model\Snapshot\SnapshotComparer;
use Orangecat\IndexerBench\Model\Snapshot\SnapshotComparison;
use Orangecat\IndexerBench\Model\Snapshot\SnapshotResult;
use Orangecat\IndexerBench\Model\Snapshot\SnapshotTaker;
use Orangecat\IndexerBench\Model\Snapshot\TableComparison;
use Orangecat\IndexerBench\Model\Snapshot\TableDump;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class SnapshotCommandTest extends TestCase
{
    public function testSnapshotIsTakenAndItsTablesListed(): void
    {
        $taker = $this->createMock(SnapshotTaker::class);
        $taker->expects($this->once())->method('take')->with('catalog_product_price', 'core')->willReturn(
            new SnapshotResult([
                new TableDump('pfx_price', 8412, str_repeat('a', 32), 'indexer-bench/p/core/pfx_price.tsv'),
                new TableDump('pfx_tier', 3, str_repeat('b', 32), 'indexer-bench/p/core/pfx_tier.tsv'),
            ], str_repeat('c', 32))
        );
        $comparer = $this->createMock(SnapshotComparer::class);
        $comparer->expects($this->never())->method('compare');
        $tester = new CommandTester(
            new SnapshotCommand($this->createStub(ProductionGuard::class), $taker, $comparer)
        );

        $exitCode = $tester->execute(['-i' => 'catalog_product_price', '-l' => 'core']);

        $display = $tester->getDisplay();
        $this->assertSame(Command::SUCCESS, $exitCode, $display);
        $this->assertStringContainsString(
            'Snapshot "core" of catalog_product_price: 2 tables, 8415 rows, md5 ' . str_repeat('c', 32),
            $display
        );
        $this->assertMatchesRegularExpression(
            '/pfx_price\s+\|\s+8412\s+\|\s+a{32}\s+\|\s+var\/indexer-bench/',
            $display
        );
    }

    public function testDiffAgainstAnotherLabelMatches(): void
    {
        $tester = $this->tester(new SnapshotComparison('core', 'boost', true, 8415, []));

        $exitCode = $tester->execute(['-i' => 'catalog_product_price', '-l' => 'boost', '--diff' => 'core']);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Output vs "core": MATCH (8415 rows)', $tester->getDisplay());
    }

    public function testDiffListsDifferingTablesAndRowsAndFails(): void
    {
        $tester = $this->tester(new SnapshotComparison('core', 'boost', false, 8415, [
            'pfx_new' => new TableComparison('pfx_new', null, 4, false, [], 'only in boost'),
            'pfx_price' => new TableComparison('pfx_price', 8412, 8412, false, [
                new DiffLine(DiffLine::CHANGED, "5\t1", "5\t1\t10.00", "5\t1\t9.00"),
                new DiffLine(DiffLine::MISSING, "7\t1", "7\t1\t3.00", null),
                new DiffLine(DiffLine::EXTRA, "8\t1", null, "8\t1\t4.00"),
            ]),
            'pfx_tier' => new TableComparison('pfx_tier', 3, 3, true),
        ]));

        $exitCode = $tester->execute(['-i' => 'catalog_product_price', '-l' => 'boost', '--diff' => 'core']);

        $display = $tester->getDisplay();
        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('Output vs "core": DIFFER', $display);
        $this->assertStringContainsString('pfx_new: - -> 4 rows (only in boost)', $display);
        $this->assertStringContainsString('pfx_price: 8412 -> 8412 rows', $display);
        $this->assertStringContainsString("  ~ 5\t1\n    - 5\t1\t10.00\n    + 5\t1\t9.00", $display);
        $this->assertStringContainsString("  - 7\t1\t3.00", $display);
        $this->assertStringContainsString("  + 8\t1\t4.00", $display);
        $this->assertStringNotContainsString('pfx_tier:', $display);
    }

    public function testRefusedProductionTakesNoSnapshot(): void
    {
        $guard = $this->createStub(ProductionGuard::class);
        $guard->method('assertAllowed')->willThrowException(new LocalizedException(__('MAGE_MODE is production')));
        $taker = $this->createMock(SnapshotTaker::class);
        $taker->expects($this->never())->method('take');
        $tester = new CommandTester(new SnapshotCommand($guard, $taker, $this->createStub(SnapshotComparer::class)));

        $exitCode = $tester->execute(['-i' => 'catalog_product_price', '-l' => 'core']);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('MAGE_MODE is production', $tester->getDisplay());
    }

    #[DataProvider('invalidProvider')]
    public function testInvalidInputIsReported(bool $failOnTake, string $error): void
    {
        $exception = new \InvalidArgumentException($error);
        $taker = $this->createStub(SnapshotTaker::class);
        if ($failOnTake) {
            $taker->method('take')->willThrowException($exception);
        } else {
            $taker->method('take')->willReturn(new SnapshotResult([], str_repeat('c', 32)));
        }
        $comparer = $this->createStub(SnapshotComparer::class);
        $comparer->method('compare')->willThrowException($exception);
        $tester = new CommandTester(
            new SnapshotCommand($this->createStub(ProductionGuard::class), $taker, $comparer)
        );

        $exitCode = $tester->execute(['-i' => 'catalog_product_price', '-l' => 'boost', '--diff' => 'core']);

        $this->assertSame(Command::INVALID, $exitCode);
        $this->assertStringContainsString($error, $tester->getDisplay());
    }

    /**
     * @return array<string,array{bool,string}>
     */
    public static function invalidProvider(): array
    {
        return [
            'label' => [true, 'Label must be letters, digits'],
            'no baseline snapshot' => [false, 'No snapshot of catalog_product_price labelled "core"'],
        ];
    }

    /**
     * Command whose comparer returns this comparison
     *
     * @param SnapshotComparison $comparison
     * @return CommandTester
     */
    private function tester(SnapshotComparison $comparison): CommandTester
    {
        $taker = $this->createStub(SnapshotTaker::class);
        $taker->method('take')->willReturn(new SnapshotResult([], str_repeat('c', 32)));
        $comparer = $this->createMock(SnapshotComparer::class);
        $comparer->expects($this->once())->method('compare')->with('catalog_product_price', 'core', 'boost', 10)
            ->willReturn($comparison);
        return new CommandTester(new SnapshotCommand($this->createStub(ProductionGuard::class), $taker, $comparer));
    }
}
