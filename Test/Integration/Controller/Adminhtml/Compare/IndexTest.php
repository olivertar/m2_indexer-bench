<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Test\Integration\Controller\Adminhtml\Compare;

use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Request;
use Magento\TestFramework\Response;
use Magento\TestFramework\TestCase\AbstractBackendController;
use Orangecat\IndexerBench\Model\ResourceModel\BenchRun;
use Orangecat\IndexerBench\Model\Run\RunRecord;

/**
 * Baseline against candidate in the admin; ACL access and denial are tested by the parent class
 */
class IndexTest extends AbstractBackendController
{
    /**
     * @var string
     */
    protected $resource = 'Orangecat_IndexerBench::view';

    /**
     * @var string
     */
    protected $uri = 'backend/indexerbench/compare/index';

    /**
     * @var string
     */
    protected $httpMethod = HttpRequest::METHOD_GET;

    #[DbIsolation(true)]
    public function testComparesTwoLabelsWithVerdictsAndSaysWhyOutputWasNotCompared(): void
    {
        foreach (['zz-core' => 40.0, 'zz-boost' => 30.0] as $label => $wallTime) {
            $this->_objectManager->create(BenchRun::class)->saveRun(
                new RunRecord(
                    'catalog_product_price',
                    $label,
                    'baseline',
                    'full',
                    0,
                    false,
                    new \DateTimeImmutable('2026-11-02 10:00:00'),
                    new \DateTimeImmutable('2026-11-02 10:01:00'),
                    null,
                    [],
                    null
                ),
                ['wall_time_s' => $wallTime]
            );
        }

        $this->request()->setMethod(HttpRequest::METHOD_GET);
        $this->request()->setParams([
            'indexer' => 'catalog_product_price',
            'baseline' => 'zz-core',
            'candidate' => 'zz-boost',
        ]);
        $this->dispatch($this->uri);

        $body = $this->response()->getBody();
        $this->assertStringContainsString('zz-core (median of 1 run)', $body);
        $this->assertStringContainsString('-25.0%', $body);
        $this->assertStringContainsString('improved', $body);
        $this->assertStringContainsString('not compared', $body);
    }

    /**
     * Request of the test application
     *
     * @return Request
     */
    private function request(): Request
    {
        $request = $this->getRequest();
        $this->assertInstanceOf(Request::class, $request);
        return $request;
    }

    /**
     * Response of the test application
     *
     * @return Response
     */
    private function response(): Response
    {
        $response = $this->getResponse();
        $this->assertInstanceOf(Response::class, $response);
        return $response;
    }
}
