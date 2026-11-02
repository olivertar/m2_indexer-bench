<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Test\Integration\Console\Command;

use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use Orangecat\IndexerBench\Console\Command\ExportCommand;
use Orangecat\IndexerBench\Model\Fingerprint\EnvironmentFingerprint;
use Orangecat\IndexerBench\Model\ResourceModel\BenchRun;
use Orangecat\IndexerBench\Model\Run\RunRecord;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class ExportCommandTest extends TestCase
{
    #[DbIsolation(true)]
    public function testStoredRunIsExportedAsMarkdownAndJson(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $fingerprint = $objectManager->create(EnvironmentFingerprint::class)->collect();
        $runId = $objectManager->create(BenchRun::class)->saveRun(
            new RunRecord(
                'catalog_product_price',
                'integration-boost',
                'candidate',
                'full',
                0,
                false,
                new \DateTimeImmutable('2026-10-31 10:00:00'),
                new \DateTimeImmutable('2026-10-31 10:01:00'),
                null,
                $fingerprint,
                'export check'
            ),
            ['wall_time_s' => 612.8, 'sql_queries' => null]
        );
        $tester = new CommandTester($objectManager->create(ExportCommand::class));

        $tester->execute(['--run' => (string)$runId]);
        $markdown = $tester->getDisplay();
        $exitCode = $tester->execute(['--format' => 'json']);
        $json = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString(
            sprintf("## catalog_product_price · integration-boost · run #%d\n", $runId),
            $markdown
        );
        $this->assertStringContainsString("| wall_time_s | 612.8 |\n| sql_queries | n/a |\n", $markdown);
        $this->assertStringContainsString('- Magento ' . $fingerprint['magento']['version'], $markdown);
        $this->assertStringContainsString('Notes: export check', $markdown);
        $this->assertSame($runId, $json['run_id']);
        $this->assertSame(['wall_time_s' => 612.8, 'sql_queries' => null], $json['metrics']);
        $this->assertSame($fingerprint, $json['fingerprint']);
    }
}
