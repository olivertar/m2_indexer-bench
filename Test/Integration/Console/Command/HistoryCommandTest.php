<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Test\Integration\Console\Command;

use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use Orangecat\IndexerBench\Console\Command\HistoryCommand;
use Orangecat\IndexerBench\Model\ResourceModel\BenchRun;
use Orangecat\IndexerBench\Model\Run\RunRecord;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class HistoryCommandTest extends TestCase
{
    #[DbIsolation(true)]
    public function testLabelMediansAndTheirTrendComeFromStoredRuns(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $storage = $objectManager->create(BenchRun::class);
        $save = static fn (string $label, bool $warmup, float $wallTime): int => $storage->saveRun(
            new RunRecord(
                'catalog_product_price',
                $label,
                $label === 'integration-core' ? 'baseline' : 'candidate',
                'full',
                0,
                $warmup,
                new \DateTimeImmutable('2026-10-31 10:00:00'),
                new \DateTimeImmutable('2026-10-31 10:01:00'),
                null,
                []
            ),
            ['wall_time_s' => $wallTime]
        );
        $save('integration-core', true, 90.0);
        $save('integration-core', false, 10.0);
        $save('integration-core', false, 30.0);
        $save('integration-boost', false, 5.0);
        $tester = new CommandTester($objectManager->create(HistoryCommand::class));

        $exitCode = $tester->execute(['-i' => 'catalog_product_price', '--metric' => 'wall_time']);

        $display = $tester->getDisplay();
        $this->assertSame(Command::SUCCESS, $exitCode, $display);
        $this->assertMatchesRegularExpression('/integration-core\s+\|.*\|\s+ok \(warm-up\)\s+\|\s+90/', $display);
        $this->assertMatchesRegularExpression(
            '/integration-core\s+\|\s+2\s+\|\s+20\s+\|\s+10\s+\|\s+30\s+\|\s+—/u',
            $display
        );
        $this->assertMatchesRegularExpression('/integration-boost\s+\|\s+1\s+\|\s+5\s+\|.*\|\s+↓ -75\.0%/u', $display);
    }
}
