<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Test\Unit\Model\Run;

use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\ObjectManager\ConfigLoaderInterface;
use Magento\Framework\ObjectManagerInterface;
use Orangecat\IndexerBench\Model\Run\IndexerArea;
use PHPUnit\Framework\TestCase;

class IndexerAreaTest extends TestCase
{
    public function testAdminhtmlAreaAndItsDiConfigAreLoadedAsBinMagentoIndexerReindexDoes(): void
    {
        $state = $this->createMock(State::class);
        $state->method('getAreaCode')->willThrowException(new LocalizedException(__('Area code is not set')));
        $state->expects($this->once())->method('setAreaCode')->with('adminhtml');
        $configLoader = $this->createMock(ConfigLoaderInterface::class);
        $configLoader->expects($this->once())->method('load')->with('adminhtml')->willReturn(['preferences' => []]);
        $objectManager = $this->createMock(ObjectManagerInterface::class);
        $objectManager->expects($this->once())->method('configure')->with(['preferences' => []]);

        (new IndexerArea($state, $objectManager, $configLoader))->ensure();
    }

    public function testAnAreaAlreadySetIsKept(): void
    {
        $state = $this->createMock(State::class);
        $state->method('getAreaCode')->willReturn('crontab');
        $state->expects($this->never())->method('setAreaCode');
        $objectManager = $this->createMock(ObjectManagerInterface::class);
        $objectManager->expects($this->never())->method('configure');

        (new IndexerArea($state, $objectManager, $this->createStub(ConfigLoaderInterface::class)))->ensure();
    }
}
