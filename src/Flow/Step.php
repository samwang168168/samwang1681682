<?php

declare(strict_types=1);

namespace App\Claude\Flow;

/**
 * 一個節點。
 *
 * 契約跟 n8n 的節點一樣：吃一疊 item，吐一疊 item。數量可以變
 * （過濾會變少、切段會變多），這正是它好組合的原因。
 *
 * item 一律是 array<string, mixed> —— 不用 DTO 是刻意的：
 * 中間步驟常常要加欄位，用固定型別的物件反而每加一個欄位就要改一個類別。
 */
interface Step
{
    /**
     * @param  list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    public function __invoke(array $items): array;

    /** 顯示在 trace 裡的名稱。 */
    public function name(): string;
}
