<?php

declare(strict_types=1);

namespace App\Claude;

/**
 * 一次 Gemini -> Claude 的轉換結果。
 *
 * warnings 一定要看：Gemini 有幾個參數在 Claude（特別是 Opus 5）上沒有對應，
 * 轉接層會把它們丟掉並記在這裡。悄悄吞掉會讓行為在遷移後默默改變。
 */
final class GeminiTranslation
{
    /**
     * @param array<string, mixed> $params   可以直接 spread 進 $client->messages->create(...)
     * @param list<string>         $warnings 被丟棄或需要人工處理的參數
     */
    public function __construct(
        public readonly array $params,
        public readonly array $warnings = [],
    ) {
    }

    public function hasWarnings(): bool
    {
        return $this->warnings !== [];
    }
}
