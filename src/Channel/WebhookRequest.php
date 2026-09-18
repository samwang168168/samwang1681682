<?php

declare(strict_types=1);

namespace App\Claude\Channel;

/**
 * 進來的 HTTP 請求。
 *
 * ★ rawBody 是**原始字串**，不是解析過的陣列。這一點是整個通道層最關鍵的設計：
 *   Meta 的 X-Hub-Signature-256 是對原始位元組算 HMAC 的，
 *   只要你先 json_decode 再 json_encode 回去，空白、鍵序、Unicode escape
 *   任何一點不同簽章就對不上 —— 而且是「有中文就壞、純英文就好」這種時好時壞的症狀。
 */
final class WebhookRequest
{
    /**
     * @param array<string, string> $headers 鍵一律小寫
     * @param array<string, string> $query
     */
    public function __construct(
        public readonly string $method,
        public readonly string $rawBody,
        public readonly array $headers = [],
        public readonly array $query = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        $headers = [];

        if (function_exists('getallheaders')) {
            foreach ((array) getallheaders() as $name => $value) {
                $headers[strtolower((string) $name)] = (string) $value;
            }
        }

        // php -S 與部分 SAPI 沒有 getallheaders()，從 $_SERVER 補齊
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with((string) $key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr((string) $key, 5)));
                $headers[$name] ??= (string) $value;
            }
        }

        /** @var array<string, string> $query */
        $query = array_map(static fn ($v): string => is_array($v) ? '' : (string) $v, $_GET);

        return new self(
            method: strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            rawBody: (string) (file_get_contents('php://input') ?: ''),
            headers: $headers,
            query: $query,
        );
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function query(string $name): ?string
    {
        return $this->query[$name] ?? null;
    }

    /** @return array<mixed> */
    public function json(): array
    {
        $decoded = json_decode($this->rawBody, true);

        return is_array($decoded) ? $decoded : [];
    }
}
