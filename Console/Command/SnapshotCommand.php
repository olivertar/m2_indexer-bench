<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Console\Command;

use Magento\Framework\Exception\LocalizedException;
use Orangecat\IndexerBench\Model\Safeguard\ProductionGuard;
use Orangecat\IndexerBench\Model\Snapshot\DiffLine;
use Orangecat\IndexerBench\Model\Snapshot\SnapshotComparer;
use Orangecat\IndexerBench\Model\Snapshot\SnapshotTaker;
use Orangecat\IndexerBench\Model\Snapshot\TableComparison;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Canonical snapshot of the index tables of an indexer, optionally compared with another label
 */
class SnapshotCommand extends Command
{
    private const NAME = 'indexer-bench:snapshot';
    private const OPTION_INDEXER = 'indexer';
    private const OPTION_LABEL = 'label';
    private const OPTION_DIFF = 'diff';
    private const OPTION_LIMIT = 'limit';

    /**
     * Characters of a dump line shown
     */
    private const MAX_LINE_WIDTH = 300;

    /**
     * @param ProductionGuard $productionGuard
     * @param SnapshotTaker $taker
     * @param SnapshotComparer $comparer
     */
    public function __construct(
        private readonly ProductionGuard $productionGuard,
        private readonly SnapshotTaker $taker,
        private readonly SnapshotComparer $comparer
    ) {
        parent::__construct();
    }

    /**
     * @inheritdoc
     */
    protected function configure(): void
    {
        $this->setName(self::NAME)
            ->setDescription('Dump the index tables of an indexer in canonical order and checksum them')
            ->addOption(self::OPTION_INDEXER, 'i', InputOption::VALUE_REQUIRED, 'Indexer ID')
            ->addOption(self::OPTION_LABEL, 'l', InputOption::VALUE_REQUIRED, 'Label of the snapshot')
            ->addOption(
                self::OPTION_DIFF,
                null,
                InputOption::VALUE_REQUIRED,
                'Compare with the snapshot of this label (the baseline): MATCH or DIFFER'
            )
            ->addOption(self::OPTION_LIMIT, null, InputOption::VALUE_REQUIRED, 'Differing rows shown per table', '10');
        parent::configure();
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            // Dumps read whole index tables: not on a live store
            $this->productionGuard->assertAllowed();
            return $this->snapshot($input, $output);
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::INVALID;
        } catch (LocalizedException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }

    /**
     * Take the snapshot, print it and compare it when asked
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int exit code
     * @throws \InvalidArgumentException for a bad label, an unknown indexer or a label without snapshot
     * @throws \Magento\Framework\Exception\FileSystemException
     */
    private function snapshot(InputInterface $input, OutputInterface $output): int
    {
        $indexerId = (string)$input->getOption(self::OPTION_INDEXER);
        $label = (string)$input->getOption(self::OPTION_LABEL);
        $result = $this->taker->take($indexerId, $label);

        $output->writeln(sprintf(
            'Snapshot "%s" of %s: %d %s, %d rows, md5 %s',
            $label,
            $indexerId,
            count($result->dumps),
            count($result->dumps) === 1 ? 'table' : 'tables',
            $result->rows(),
            $result->globalMd5
        ));
        $table = new Table($output);
        $table->setHeaders(['Table', 'Rows', 'MD5', 'Dump']);
        foreach ($result->dumps as $dump) {
            $table->addRow([$dump->table, $dump->rows, $dump->md5, 'var/' . $dump->filePath]);
        }
        $table->render();

        $diffLabel = $input->getOption(self::OPTION_DIFF);
        if ($diffLabel === null) {
            return Command::SUCCESS;
        }
        $comparison = $this->comparer->compare(
            $indexerId,
            (string)$diffLabel,
            $label,
            (int)$input->getOption(self::OPTION_LIMIT)
        );
        if ($comparison->matches()) {
            $output->writeln(sprintf(
                '<info>Output vs "%s": %s (%d rows)</info>',
                $diffLabel,
                $comparison->verdict(),
                $comparison->candidateRows()
            ));
            return Command::SUCCESS;
        }
        $output->writeln(sprintf('<error>Output vs "%s": %s</error>', $diffLabel, $comparison->verdict()));
        foreach ($comparison->tables as $tableComparison) {
            if (!$tableComparison->matches) {
                $this->printTable($output, $tableComparison);
            }
        }
        return Command::FAILURE;
    }

    /**
     * Print a differing table and its first differing rows
     *
     * @param OutputInterface $output
     * @param TableComparison $comparison
     * @return void
     */
    private function printTable(OutputInterface $output, TableComparison $comparison): void
    {
        $output->writeln(sprintf(
            '%s: %s -> %s rows%s',
            $comparison->table,
            $comparison->baselineRows ?? '-',
            $comparison->candidateRows ?? '-',
            $comparison->note === null ? '' : ' (' . $comparison->note . ')'
        ));
        foreach ($comparison->differences as $line) {
            $lines = match ($line->type) {
                DiffLine::MISSING => ['  - ' . $line->baseline],
                DiffLine::EXTRA => ['  + ' . $line->candidate],
                DiffLine::CHANGED => ['  ~ ' . $line->key, '    - ' . $line->baseline, '    + ' . $line->candidate],
                default => ['  columns', '    - ' . $line->baseline, '    + ' . $line->candidate],
            };
            foreach ($lines as $text) {
                // Row data, not console markup
                $output->writeln(mb_strimwidth($text, 0, self::MAX_LINE_WIDTH, '...'), OutputInterface::OUTPUT_RAW);
            }
        }
    }
}
