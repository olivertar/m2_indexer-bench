<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Test\Integration\Console\Command;

use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use Orangecat\IndexerBench\Console\Command\CompareCommand;
use Orangecat\IndexerBench\Model\Fingerprint\EnvironmentFingerprint;
use Orangecat\IndexerBench\Model\ResourceModel\BenchRun;
use Orangecat\IndexerBench\Model\Run\RunRecord;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class CompareCommandTest extends TestCase
{
    #[DbIsolation(true)]
    public function testMedianOfABaselineLabelAgainstACandidateRunFlagsTheRegression(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $storage = $objectManager->create(BenchRun::class);
        $fingerprint = $objectManager->create(EnvironmentFingerprint::class)->collect();
        $save = static fn (string $label, int $repeat, float $wallTime): int => $storage->saveRun(
            new RunRecord(
                'catalog_product_price',
                $label,
                $label === 'integration-core' ? 'baseline' : 'candidate',
                'full',
                $repeat,
                false,
                new \DateTimeImmutable('2026-10-31 10:00:00'),
                new \DateTimeImmutable('2026-10-31 10:01:00'),
                null,
                $fingerprint
            ),
            ['wall_time_s' => $wallTime, 'index_rows' => 100.0]
        );
        $save('integration-core', 0, 10.0);
        $save('integration-core', 1, 30.0);
        $save('integration-core', 2, 20.0);
        $candidate = $save('integration-boost', 0, 25.0);
        $tester = new CommandTester($objectManager->create(CompareCommand::class));

        $exitCode = $tester->execute([
            '-i' => 'catalog_product_price',
            '--baseline' => 'integration-core',
            '--candidate' => (string)$candidate,
            '--strict' => true,
        ]);

        $display = $tester->getDisplay();
        $this->assertSame(Command::FAILURE, $exitCode, $display);
        $this->assertStringContainsString('Baseline: integration-core (median of 3 runs)', $display);
        $this->assertStringContainsString('Fingerprint: OK', $display);
        $this->assertMatchesRegularExpression(
            '/wall_time_s\s+\|\s+20\s+\|\s+25\s+\|\s+\+25\.0%\s+\|\s+❌ regression/u',
            $display
        );
        $this->assertStringContainsString('Output: not compared (No snapshot of catalog_product_price', $display);
        $this->assertStringContainsString('Regressions over 10%: wall_time_s', $display);
    }
}
