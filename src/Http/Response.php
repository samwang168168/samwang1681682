<?php

declare(strict_types=1);

namespace App\Claude\Http;

/**
 * 一次 HTTP 呼叫的結果。
 *
 * 刻意不在非 2xx 時丟例外 —— 4xx/5xx 對通訊軟體來說是「要分類處理的資料」，
 * 不是「程式壞了」。403(使用者封鎖) 跟 429(限流) 的後續處理完全相反，
 * 把它們一起丟成例外就沒辦法分開。
 */
final class Response
{
    /** @param array<string, string> $headers 鍵一律小寫 */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers = [],
        /** 連線層級的錯誤（DNS、逾時）。有值代表根本沒送達對方。 */
        public readonly ?string $transportError = null,
    ) {
    }

    public function isOk(): bool
    {
        return $this->transportError === null && $this->status >= 200 && $this->status < 300;
    }

    /** @return array<mixed> 解析不出來就回空陣列，不丟例外。 */
    public function json(): array
    {
        $decoded = json_decode($this->body, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** 值得重試的：連線失敗、5xx、429。 */
    public function isRetryable(): bool
    {
        return $this->transportError !== null
            || $this->status === 429
            || $this->status >= 500;
    }

    public function describe(): string
    {
        if ($this->transportError !== null) {
            return "transport error: {$this->transportError}";
        }

        return "HTTP {$this->status}: " . mb_substr($this->body, 0, 300);
    }
}
