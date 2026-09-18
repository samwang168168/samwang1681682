<?php

declare(strict_types=1);

namespace App\Claude\Http;

/**
 * 最小的 HTTP 出口。
 *
 * 抽成介面只有一個理由：測試時要能在不連外網的情況下，斷言「真正送出去的
 * payload 長什麼樣」。FakeTransport 就是幹這件事的。
 */
interface Transport
{
    /**
     * @param array<string, string> $headers
     * @param string|null           $body    已序列化的 request body
     */
    public function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        int $timeoutSeconds = 20,
    ): Response;
}
