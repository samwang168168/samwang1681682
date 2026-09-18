<?php

declare(strict_types=1);

namespace App\Claude\Channel;

use App\Claude\Http\Response;

/** 送一則訊息的結果。分類的意義見 SendOutcome。 */
final class SendResult
{
    public function __construct(
        public readonly SendOutcome $outcome,
        public readonly string $conversationId,
        public readonly ?int $retryAfterSeconds = null,
        public readonly string $error = '',
        public readonly ?Response $response = null,
    ) {
    }

    public function isSent(): bool
    {
        return $this->outcome === SendOutcome::Sent;
    }

    /** 值得再試一次嗎？永久性失敗一律回 false。 */
    public function shouldRetry(): bool
    {
        return $this->outcome === SendOutcome::RateLimited
            || $this->outcome === SendOutcome::TemporaryFailure;
    }
}
