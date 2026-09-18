<?php

declare(strict_types=1);

namespace App\Claude\Store;

/**
 * 跨請求的小狀態。去重、限流的時間戳、24 小時視窗的最後發話時間都用它。
 *
 * 核心是 add()：它必須是**原子的**。webhook 會並行進來，
 * 「先 has() 再 set()」中間有空窗，同一則訊息就會被處理兩次。
 */
interface Store
{
    /**
     * 只有在 key 尚未存在時寫入。
     *
     * @return bool true = 這次是第一次看到（可以處理）；false = 已存在（重複，丟掉）
     */
    public function add(string $key, int $ttlSeconds = 86400): bool;

    public function get(string $key): mixed;

    public function set(string $key, mixed $value, int $ttlSeconds = 86400): void;

    public function forget(string $key): void;
}
