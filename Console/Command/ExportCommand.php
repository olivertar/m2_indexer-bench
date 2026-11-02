<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Console\Command;

use Magento\Framework\Filesystem\Driver\File;
use Orangecat\IndexerBench\Model\Export\RunExporter;
use Orangecat\IndexerBench\Model\Run\RunReader;
use Orangecat\IndexerBench\Model\Run\StoredRun;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Export a stored run as JSON or as Markdown for docs/benchmarks/ (SPEC 5.2)
 */
class ExportCommand extends Command
{
    private const NAME = 'indexer-bench:export';
    private const OPTION_RUN = 'run';
    private const OPTION_FORMAT = 'format';
    private const OPTION_OUTPUT = 'output';
    private const FORMAT_JSON = 'json';
    private const FORMAT_MARKDOWN = 'markdown';

    /**
     * @param RunReader $runReader
     * @param RunExporter $exporter
     * @param File $file
     */
    public function __construct(
        private readonly RunReader $runReader,
        private readonly RunExporter $exporter,
        private readonly File $file
    ) {
        parent::__construct();
    }

    /**
     * @inheritdoc
     */
    protected function configure(): void
    {
        $this->setName(self::NAME)
            ->setDescription('Export a stored run as JSON or as Markdown ready for docs/benchmarks/')
            ->addOption(self::OPTION_RUN, null, InputOption::VALUE_REQUIRED, 'Run ID; the last run when left out')
            ->addOption(
                self::OPTION_FORMAT,
                null,
                InputOption::VALUE_REQUIRED,
                'Output format: json|markdown',
                self::FORMAT_MARKDOWN
            )
            ->addOption(self::OPTION_OUTPUT, null, InputOption::VALUE_REQUIRED, 'File to write instead of printing');
        parent::configure();
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $format = $this->format($input);
            $run = $this->selectRun($input);
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::INVALID;
        }
        if ($run === null) {
            $output->writeln('<error>No run stored yet: bin/magento indexer-bench:run measures one</error>');
            return Command::FAILURE;
        }
        $document = $format === self::FORMAT_JSON ? $this->exporter->json($run) : $this->exporter->markdown($run);

        $outputFile = (string)$input->getOption(self::OPTION_OUTPUT);
        if ($outputFile !== '') {
            $this->file->filePutContents($outputFile, $document);
            $output->writeln(sprintf('Run #%d exported to %s', $run->runId, $outputFile));
        } else {
            $output->write($document, false, OutputInterface::OUTPUT_RAW);
        }
        return Command::SUCCESS;
    }

    /**
     * Format option
     *
     * @param InputInterface $input
     * @return string
     * @throws \InvalidArgumentException when unknown
     */
    private function format(InputInterface $input): string
    {
        $format = (string)$input->getOption(self::OPTION_FORMAT);
        if (!in_array($format, [self::FORMAT_JSON, self::FORMAT_MARKDOWN], true)) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown format %s: %s|%s',
                $format,
                self::FORMAT_JSON,
                self::FORMAT_MARKDOWN
            ));
        }
        return $format;
    }

    /**
     * Run to export: the given one, or the last one when none is given
     *
     * @param InputInterface $input
     * @return StoredRun|null null when no run is stored at all
     * @throws \InvalidArgumentException when the given run is not a stored run ID
     */
    private function selectRun(InputInterface $input): ?StoredRun
    {
        $runId = $input->getOption(self::OPTION_RUN);
        if ($runId === null) {
            return $this->runReader->getLatestRun();
        }
        if (!ctype_digit((string)$runId)) {
            throw new \InvalidArgumentException('--run must be a run ID');
        }
        return $this->runReader->getRun((int)$runId)
            ?? throw new \InvalidArgumentException(sprintf('No run #%d stored', (int)$runId));
    }
}
