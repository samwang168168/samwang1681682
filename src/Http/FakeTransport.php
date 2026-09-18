<?php

declare(strict_types=1);

namespace App\Claude\Http;

/**
 * 測試用：回預先排好的回應，並把送出去的請求全部錄下來。
 *
 * 通訊軟體整合最容易錯的地方是「送出去的 payload 少一個欄位」，
 * 而那種錯只有看真正的 wire payload 才抓得到。
 */
final class FakeTransport implements Transport
{
    /** @var list<array{method: string, url: string, headers: array<string,string>, body: ?string}> */
    public array $requests = [];

    /** @var list<Response> */
    private array $queue = [];

    private Response $default;

    public function __construct(?Response $default = null)
    {
        $this->default = $default ?? new Response(200, '{"ok":true}');
    }

    /** 依序回傳排進去的回應；用完之後一律回 default。 */
    public function queue(Response ...$responses): self
    {
        foreach ($responses as $response) {
            $this->queue[] = $response;
        }

        return $this;
    }

    public function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        int $timeoutSeconds = 20,
    ): Response {
        $this->requests[] = compact('method', 'url', 'headers', 'body');

        return array_shift($this->queue) ?? $this->default;
    }

    /** @return array{method: string, url: string, headers: array<string,string>, body: ?string} */
    public function lastRequest(): array
    {
        if ($this->requests === []) {
            throw new \RuntimeException('還沒有任何請求');
        }

        return $this->requests[count($this->requests) - 1];
    }

    /** @return array<mixed> 最後一次請求的 body（JSON 解析後） */
    public function lastBody(): array
    {
        $decoded = json_decode($this->lastRequest()['body'] ?? '', true);

        return is_array($decoded) ? $decoded : [];
    }

    public function count(): int
    {
        return count($this->requests);
    }
}
