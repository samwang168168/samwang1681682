<?php

declare(strict_types=1);

namespace App\Claude\Flow\Steps;

use App\Claude\Flow\Step;

/**
 * 留下符合條件的 item。
 *
 * 通訊軟體整合有一半的 bug 是「沒有過濾」：Telegram 的貼圖沒有 text、
 * Messenger 會把你自己送出的訊息當 echo 再送回來（不濾掉 bot 會跟自己無限對話）。
 */
final class Filter implements Step
{
    /** @param callable(array<string, mixed>): bool $predicate */
    public function __construct(
        private readonly string $name,
        private readonly mixed $predicate,
    ) {
    }

    public function __invoke(array $items): array
    {
        return array_values(array_filter($items, $this->predicate));
    }

    public function name(): string
    {
        return $this->name;
    }
}
