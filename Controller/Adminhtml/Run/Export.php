<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Controller\Adminhtml\Run;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\Result\Redirect;
use Orangecat\IndexerBench\Model\Export\RunExporter;
use Orangecat\IndexerBench\Model\Run\RunReader;

/**
 * Markdown report of one run (parameter "id"), as indexer-bench:export, downloaded as a file
 */
class Export extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = Index::ADMIN_RESOURCE;

    /**
     * @param Context $context
     * @param RunReader $runReader
     * @param RunExporter $exporter
     * @param RawFactory $rawFactory
     */
    public function __construct(
        Context $context,
        private readonly RunReader $runReader,
        private readonly RunExporter $exporter,
        private readonly RawFactory $rawFactory
    ) {
        parent::__construct($context);
    }

    /**
     * Send the report, or go back to the list when the run does not exist
     *
     * @return Raw|Redirect
     */
    public function execute(): Raw|Redirect
    {
        $id = (string)$this->getRequest()->getParam('id');
        $run = ctype_digit($id) ? $this->runReader->getRun((int)$id) : null;
        if ($run === null) {
            $this->messageManager->addErrorMessage(__('Benchmark run not found.'));
            /** @var Redirect $redirect */
            $redirect = $this->resultRedirectFactory->create();
            return $redirect->setPath('indexerbench/run/index');
        }
        /** @var Raw $result */
        $result = $this->rawFactory->create();
        $result->setHeader('Content-Type', 'text/markdown; charset=utf-8', true);
        $result->setHeader(
            'Content-Disposition',
            sprintf('attachment; filename="%s-run-%d.md"', $run->indexerId, $run->runId),
            true
        );
        return $result->setContents($this->exporter->markdown($run));
    }
}
