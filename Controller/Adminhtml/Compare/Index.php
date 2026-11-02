<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Controller\Adminhtml\Compare;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use Orangecat\IndexerBench\Controller\Adminhtml\Run\Index as RunIndex;

/**
 * Baseline against candidate for one indexer, as indexer-bench:compare; read-only
 */
class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = RunIndex::ADMIN_RESOURCE;

    /**
     * @param Context $context
     * @param PageFactory $pageFactory
     */
    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory
    ) {
        parent::__construct($context);
    }

    /**
     * Render the page
     *
     * @return Page
     */
    public function execute(): Page
    {
        /** @var Page $page */
        $page = $this->pageFactory->create();
        $page->setActiveMenu(RunIndex::ADMIN_RESOURCE);
        $page->getConfig()->getTitle()->prepend((string)__('Compare Benchmarks'));
        return $page;
    }
}
