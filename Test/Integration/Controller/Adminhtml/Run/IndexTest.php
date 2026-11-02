<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Test\Integration\Controller\Adminhtml\Run;

use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Request;
use Magento\TestFramework\Response;
use Magento\TestFramework\TestCase\AbstractBackendController;
use Orangecat\IndexerBench\Model\ResourceModel\BenchRun;
use Orangecat\IndexerBench\Model\Run\RunRecord;

/**
 * System > Tools > Indexer Benchmarks; ACL access and denial are tested by the parent class
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
    protected $uri = 'backend/indexerbench/run/index';

    /**
     * @var string
     */
    protected $httpMethod = HttpRequest::METHOD_GET;

    #[DbIsolation(true)]
    public function testPageListsTheIndexersLabelsAndRunsOfThisDatabase(): void
    {
        $runId = $this->_objectManager->create(BenchRun::class)->saveRun(
            new RunRecord(
                'catalog_product_price',
                'zz-page-label',
                'baseline',
                'full',
                0,
                false,
                new \DateTimeImmutable('2026-11-02 10:00:00'),
                new \DateTimeImmutable('2026-11-02 10:00:42'),
                null,
                [],
                null
            ),
            ['wall_time_s' => 41.7]
        );

        $this->request()->setMethod(HttpRequest::METHOD_GET);
        $this->dispatch($this->uri);

        $body = $this->response()->getBody();
        $this->assertSame(200, $this->response()->getHttpResponseCode());
        $this->assertStringContainsString('Indexer Benchmarks', $body);
        $this->assertStringContainsString('catalog_product_price', $body);
        $this->assertStringContainsString('zz-page-label', $body);
        $this->assertStringContainsString('41.7', $body);
        $this->assertStringContainsString('indexerbench/run/view/id/' . $runId, $body);
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
