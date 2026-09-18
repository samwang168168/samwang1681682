<?php

declare(strict_types=1);

namespace App\Claude\Flow\Steps;

use App\Claude\Flow\Step;
use App\Claude\Store\Store;

/**
 * 丟掉看過的 item。
 *
 * **這不是防呆，是必要的。** webhook 一定會重送：
 *   - Telegram 沒收到 200 就重送同一個 update_id
 *   - Meta 沒在 20 秒內收到 200 就重送，連續失敗還會直接停用你的 webhook
 *
 * 少了這一步，使用者會收到兩次一樣的回覆，而你付兩次 API 錢。
 */
final class Dedupe implements Step
{
    /** @param callable(array<string, mixed>): (string|int|null) $keyOf 回 null 代表這筆無法去重，直接放行 */
    public function __construct(
        private readonly Store $store,
        private readonly mixed $keyOf,
        private readonly string $prefix = 'seen:',
        private readonly int $ttlSeconds = 86400,
        private readonly string $name = '去重',
    ) {
    }

    public function __invoke(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            $key = ($this->keyOf)($item);

            if ($key === null || $key === '') {
                $out[] = $item;
                continue;
            }

            // add() 是原子的：回 false 代表別的請求已經先搶到了
            if ($this->store->add($this->prefix . $key, $this->ttlSeconds)) {
                $out[] = $item;
            }
        }

        return $out;
    }

    public function name(): string
    {
        return $this->name;
    }
}
