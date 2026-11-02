<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Test\Unit\Console\Command;

use Orangecat\IndexerBench\Api\IndexerAdapterInterface;
use Orangecat\IndexerBench\Console\Command\RunPartialCommand;
use Orangecat\IndexerBench\Model\Adapter\AdapterPool;
use Orangecat\IndexerBench\Model\Fingerprint\HostInfo;
use Orangecat\IndexerBench\Model\Profile\RunProfiler;
use Orangecat\IndexerBench\Model\Run\IdSampler;
use Orangecat\IndexerBench\Model\Run\Median;
use Orangecat\IndexerBench\Model\Run\RunOutcome;
use Orangecat\IndexerBench\Model\Run\RunRequest;
use Orangecat\IndexerBench\Model\Run\SeriesRunner;
use Orangecat\IndexerBench\Model\Safeguard\Safeguards;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class RunPartialCommandTest extends TestCase
{
    /**
     * @var RunRequest[] requests the runner received
     */
    private array $requests = [];

    public function testSampleOfTheAdapterUniverseIsMeasuredWithItsSeed(): void
    {
        $tester = new CommandTester($this->command());

        $exitCode = $tester->execute([
            '-i' => 'catalog_product_price',
            '-l' => 'core-partial',
            '-c' => '10',
            '--repeat' => '2',
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
        $request = $this->requests[0];
        $this->assertSame(
            ['core-partial', 2, [61, 69, 95, 166, 216, 543, 586, 619, 817, 943], 42],
            [$request->label, $request->repeat, $request->ids, $request->seed]
        );
        $this->assertStringContainsString('10 IDs sampled with seed 42', $tester->getDisplay());
        $this->assertStringContainsString('Measure without traffic', $tester->getDisplay());
        $this->assertMatchesRegularExpression('/wall_time_s\s+\|\s+2\s/', $tester->getDisplay());
    }

    public function testExplicitIdsAreMeasuredWithoutSeed(): void
    {
        $tester = new CommandTester($this->command());

        $exitCode = $tester->execute(['-i' => 'catalog_product_price', '-l' => 'core', '--ids' => '9, 3,9,5']);

        $this->assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
        $this->assertSame([[3, 5, 9], null], [$this->requests[0]->ids, $this->requests[0]->seed]);
        $this->assertStringContainsString('3 IDs given', $tester->getDisplay());
    }

    public function testIndexerWithoutPartialPathIsRejectedWithThatReason(): void
    {
        $tester = new CommandTester($this->command(null));

        $exitCode = $tester->execute(['-i' => 'catalog_product_price', '-l' => 'core', '-c' => '1']);

        $this->assertSame(Command::INVALID, $exitCode);
        $this->assertStringContainsString(
            'Indexer catalog_product_price has no partial reindex path to measure',
            $tester->getDisplay()
        );
    }

    /**
     * @param array<string,string> $input
     */
    #[DataProvider('invalidInputProvider')]
    public function testInvalidSampleIsRejectedBeforeMeasuring(array $input, string $error): void
    {
        $tester = new CommandTester($this->command());

        $exitCode = $tester->execute(['-i' => 'catalog_product_price', '-l' => 'core'] + $input);

        $this->assertSame(Command::INVALID, $exitCode);
        $this->assertStringContainsString($error, $tester->getDisplay());
        $this->assertSame([], $this->requests);
    }

    /**
     * @return array<string,array{array<string,string>,string}>
     */
    public static function invalidInputProvider(): array
    {
        return [
            'neither' => [[], 'Give -c/--count or --ids'],
            'both' => [['-c' => '2', '--ids' => '1,2'], 'Give -c/--count or --ids, not both'],
            'count' => [['-c' => '0'], '--count must be at least 1'],
            'too many' => [['-c' => '1001'], 'Cannot sample 1001 IDs out of 1000 entities'],
            'not a number' => [['--ids' => '1,abc'], 'Not an entity ID: "abc"'],
            'unknown' => [['--ids' => '5,1001,2000'], 'Unknown entity IDs: 1001, 2000'],
        ];
    }

    /**
     * Command over fakes; the adapter samples from IDs 1 to 1000
     *
     * @param string|null $partialIndexerId partial path of the adapter
     * @return RunPartialCommand
     */
    private function command(?string $partialIndexerId = 'catalog_product_price'): RunPartialCommand
    {
        $safeguards = $this->createStub(Safeguards::class);
        $safeguards->method('check')->willReturn([]);
        $runner = $this->createStub(SeriesRunner::class);
        $runner->method('run')->willReturnCallback(function (RunRequest $request): array {
            $this->requests[] = $request;
            return [new RunOutcome(101, 0, false, ['wall_time_s' => 2.0], null)];
        });
        $adapter = $this->createStub(IndexerAdapterInterface::class);
        $adapter->method('getEntityIdUniverse')->willReturn($partialIndexerId === null ? [] : range(1, 1000));
        $adapter->method('getPartialIndexerId')->willReturn($partialIndexerId);
        $pool = new AdapterPool(['catalog_product_price' => $adapter]);

        return new RunPartialCommand(
            $safeguards,
            $runner,
            $this->createStub(HostInfo::class),
            $pool,
            new Median(),
            $this->createStub(RunProfiler::class),
            new IdSampler()
        );
    }
}
