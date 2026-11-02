<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Test\Integration\Controller\Adminhtml\Run;

use Laminas\Http\Header\HeaderInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Response;
use Magento\TestFramework\TestCase\AbstractBackendController;
use Orangecat\IndexerBench\Model\ResourceModel\BenchRun;
use Orangecat\IndexerBench\Model\Run\RunRecord;

/**
 * One benchmark run and its Markdown download; ACL access and denial are tested by the parent class
 */
class ViewTest extends AbstractBackendController
{
    /**
     * @var string
     */
    protected $resource = 'Orangecat_IndexerBench::view';

    /**
     * @var string
     */
    protected $uri = 'backend/indexerbench/run/view';

    /**
     * @var string
     */
    protected $httpMethod = HttpRequest::METHOD_GET;

    #[DbIsolation(true)]
    public function testRunShowsItsErrorMetricsEnvironmentAndExportLink(): void
    {
        $runId = $this->saveRun();

        $this->dispatch($this->uri . '/id/' . $runId);

        $body = $this->response()->getBody();
        $this->assertStringContainsString('Run #' . $runId, $body);
        $this->assertStringContainsString('Deadlock found', $body);
        $this->assertStringContainsString('wall_time_s', $body);
        $this->assertStringContainsString('1 258.133', $body);
        $this->assertStringContainsString('Magento 2.4.9 Community', $body);
        $this->assertStringContainsString('indexerbench/run/export/id/' . $runId, $body);
    }

    public function testUnknownRunIsReported(): void
    {
        $this->dispatch($this->uri . '/id/999999999');

        $this->assertStringContainsString('Benchmark run not found', $this->response()->getBody());
    }

    #[DbIsolation(true)]
    public function testExportDownloadsTheMarkdownReport(): void
    {
        $runId = $this->saveRun();

        $this->dispatch('backend/indexerbench/run/export/id/' . $runId);

        $response = $this->response();
        $disposition = $response->getHeader('Content-Disposition');
        $this->assertStringStartsWith('## catalog_product_price · zz-view · run #' . $runId, $response->getBody());
        $this->assertInstanceOf(HeaderInterface::class, $disposition);
        $this->assertSame(
            'attachment; filename="catalog_product_price-run-' . $runId . '.md"',
            $disposition->getFieldValue()
        );
    }

    /**
     * Failed run with one metric, a fingerprint and notes
     *
     * @return int
     */
    private function saveRun(): int
    {
        return $this->_objectManager->create(BenchRun::class)->saveRun(
            new RunRecord(
                'catalog_product_price',
                'zz-view',
                'candidate',
                'full',
                0,
                false,
                new \DateTimeImmutable('2026-11-02 10:00:00'),
                new \DateTimeImmutable('2026-11-02 10:21:00'),
                'Deadlock found',
                ['magento' => ['version' => '2.4.9', 'edition' => 'Community']],
                'after toggle'
            ),
            ['wall_time_s' => 1258.133]
        );
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
