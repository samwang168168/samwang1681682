<?php

declare(strict_types=1);

namespace App\Claude\Channel;

/** 要回給平台的回應。 */
final class WebhookResponse
{
    public function __construct(
        public readonly int $status,
        public readonly string $body = '',
        public readonly string $contentType = 'text/plain; charset=utf-8',
    ) {
    }

    public static function ok(string $body = ''): self
    {
        return new self(200, $body);
    }

    public static function json(array $data, int $status = 200): self
    {
        return new self(
            $status,
            (string) json_encode($data, JSON_UNESCAPED_UNICODE),
            'application/json; charset=utf-8',
        );
    }

    /** 直接送到 output。回傳前不要有任何其他輸出。 */
    public function emit(): void
    {
        http_response_code($this->status);
        header('Content-Type: ' . $this->contentType);
        echo $this->body;
    }
}
