<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Model\Profile;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\Profiler;
use MageOS\Profiler\Model\Profiler\Driver\Timeline;
use MageOS\Profiler\Model\Profiler\ReportIndex;

/**
 * Magento profiler around one measured reindex, recorded by the mage-os/module-profiler JSON driver
 *
 * Mage-OS activates its drivers in bootstrap.php from MAGE_PROFILER. This attaches the same Timeline driver at
 * run time instead, so only the measured reindex is profiled, not the whole command. Forked workers inherit the
 * driver and write their own report when they exit, so a parallel run gives one report per process.
 */
class RunProfiler
{
    public const MODULE = 'MageOS_Profiler';

    /**
     * @var Timeline|null driver attached by start()
     */
    private ?Timeline $driver = null;

    /**
     * @var string[] reports in the report directory before start()
     */
    private array $reportsBefore = [];

    /**
     * @param ModuleManager $moduleManager
     * @param DirectoryList $directoryList
     * @param File $file
     */
    public function __construct(
        private readonly ModuleManager $moduleManager,
        private readonly DirectoryList $directoryList,
        private readonly File $file
    ) {
    }

    /**
     * Whether mage-os/module-profiler is installed and enabled
     *
     * @return bool
     */
    public function isAvailable(): bool
    {
        return class_exists(Timeline::class) && $this->moduleManager->isEnabled(self::MODULE);
    }

    /**
     * Whether the Magento profiler is already on for this process, e.g. MAGE_PROFILER set when bin/magento started
     *
     * @return bool
     */
    public function isProfilerOn(): bool
    {
        return Profiler::isEnabled();
    }

    /**
     * Attach the JSON driver and turn the profiler on
     *
     * @return void
     * @throws \Magento\Framework\Exception\FileSystemException
     */
    public function start(): void
    {
        $this->reportsBefore = $this->reports();
        $this->driver = new Timeline(['baseDir' => $this->directoryList->getRoot()]);
        Profiler::add($this->driver);
        Profiler::enable();
    }

    /**
     * Turn the profiler off, write the report and drop the driver
     *
     * @return string[] absolute paths of the reports written since start(), one per process
     * @throws \Magento\Framework\Exception\FileSystemException
     */
    public function stop(): array
    {
        if ($this->driver === null) {
            return [];
        }
        Profiler::disable();
        $this->driver->flush();
        // Drops the driver, so a later run without --profile is not recorded
        Profiler::reset();
        $this->driver = null;
        return array_values(array_diff($this->reports(), $this->reportsBefore));
    }

    /**
     * JSON reports in the Mage-OS report directory
     *
     * @return string[]
     * @throws \Magento\Framework\Exception\FileSystemException
     */
    private function reports(): array
    {
        $dir = (new ReportIndex($this->directoryList->getRoot()))->getDirectory();
        if (!$this->file->isDirectory($dir)) {
            return [];
        }
        return array_values(array_filter(
            $this->file->readDirectory($dir),
            static fn (string $path): bool => str_ends_with($path, '.json')
        ));
    }
}
