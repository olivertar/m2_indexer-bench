<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Console\Command;

use Orangecat\IndexerBench\Model\Run\RunRequest;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Measure full reindexes of one indexer and store them as benchmark runs
 */
class RunCommand extends AbstractSeriesCommand
{
    private const NAME = 'indexer-bench:run';
    private const OPTION_WARMUP = 'warmup';
    private const OPTION_SKIP_RESET = 'skip-reset';

    /**
     * @inheritdoc
     */
    protected function configure(): void
    {
        $this->setName(self::NAME)
            ->setDescription('Measure full reindexes of an indexer (development and staging only)')
            ->addSeriesOptions()
            ->addOption(
                self::OPTION_WARMUP,
                null,
                InputOption::VALUE_NONE,
                'Leave the first measurement out of the median'
            )
            ->addOption(
                self::OPTION_SKIP_RESET,
                null,
                InputOption::VALUE_NONE,
                'Reindex without invalidating the indexer first'
            );
        parent::configure();
    }

    /**
     * @inheritdoc
     */
    protected function buildRequest(InputInterface $input, OutputInterface $output): RunRequest
    {
        return new RunRequest(
            (string)$input->getOption(self::OPTION_INDEXER),
            (string)$input->getOption(self::OPTION_LABEL),
            (string)$input->getOption(self::OPTION_MODE),
            (int)$input->getOption(self::OPTION_REPEAT),
            (bool)$input->getOption(self::OPTION_WARMUP),
            (bool)$input->getOption(self::OPTION_SKIP_RESET),
            $this->notes($input),
            profile: $this->profile($input)
        );
    }
}
