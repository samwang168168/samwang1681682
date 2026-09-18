<?php

declare(strict_types=1);

namespace App\Claude\Store;

/**
 * 只活在單一 process 裡。測試用，或 CLI 一次性批次用。
 *
 * ★ 不要在 php-fpm 底下拿它去做 webhook 去重 —— 每個請求都是新的 process，
 *   等於完全沒有去重。要用 FileStore 或自己接 Redis。
 */
final class MemoryStore implements Store
{
    /** @var array<string, array{value: mixed, expiresAt: int}> */
    private array $data = [];

    public function add(string $key, int $ttlSeconds = 86400): bool
    {
        $this->purge();

        if (isset($this->data[$key])) {
            return false;
        }

        $this->set($key, true, $ttlSeconds);

        return true;
    }

    public function get(string $key): mixed
    {
        $this->purge();

        return $this->data[$key]['value'] ?? null;
    }

    public function set(string $key, mixed $value, int $ttlSeconds = 86400): void
    {
        $this->data[$key] = ['value' => $value, 'expiresAt' => time() + $ttlSeconds];
    }

    public function forget(string $key): void
    {
        unset($this->data[$key]);
    }

    private function purge(): void
    {
        $now = time();
        foreach ($this->data as $key => $entry) {
            if ($entry['expiresAt'] <= $now) {
                unset($this->data[$key]);
            }
        }
    }
}
