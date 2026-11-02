<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Test\Unit\Model\Run;

use Orangecat\IndexerBench\Model\Run\RunRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RunRequestTest extends TestCase
{
    #[DataProvider('invalidProvider')]
    public function testInvalidRequestIsRejected(
        string $label,
        string $mode,
        int $repeat,
        bool $warmup,
        string $error
    ): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($error);
        new RunRequest('catalog_product_price', $label, $mode, $repeat, $warmup);
    }

    public function testRequestWithIdsIsPartialAndWithoutIsFull(): void
    {
        $partial = new RunRequest('catalog_product_price', 'core', ids: [5, 9], seed: 42);
        $full = new RunRequest('catalog_product_price', 'core');

        $this->assertSame(['partial', [5, 9], 42], [$partial->getKind(), $partial->ids, $partial->seed]);
        $this->assertSame(['full', null], [$full->getKind(), $full->ids]);
    }

    public function testPartialRequestWithoutIdsIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A partial run needs at least one ID');
        new RunRequest('catalog_product_price', 'core', ids: []);
    }

    /**
     * @return array<string,array{string,string,int,bool,string}>
     */
    public static function invalidProvider(): array
    {
        return [
            'empty label' => ['', 'baseline', 1, false, 'A label is required'],
            'mode' => ['core', 'boost', 1, false, 'Mode must be baseline or candidate, got "boost"'],
            'repeat' => ['core', 'baseline', 0, false, '--repeat must be at least 1'],
            'warm-up alone' => ['core', 'baseline', 1, true, '--warmup needs --repeat=2 or more'],
        ];
    }
}
