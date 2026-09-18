<?php

declare(strict_types=1);

namespace App\Claude\Store;

use RuntimeException;

/**
 * 單機、跨 process 的 store。用 flock 保證 add() 的原子性。
 *
 * 適合：單台機器的 php-fpm。
 * 不適合：多台機器。那時把這個類別換成 Redis 版本（SET key NX EX ttl），
 *        介面一樣，其他程式碼一行都不用改。
 */
final class FileStore implements Store
{
    public function __construct(private readonly string $path)
    {
        $dir = dirname($this->path);
        if (!is_dir($dir) && !@mkdir($dir, 0o770, true) && !is_dir($dir)) {
            throw new RuntimeException("無法建立目錄：{$dir}");
        }
    }

    public function add(string $key, int $ttlSeconds = 86400): bool
    {
        // 讀-改-寫全部包在同一個獨佔鎖裡，中間沒有空窗。
        return $this->transaction(function (array &$data) use ($key, $ttlSeconds): bool {
            if (isset($data[$key]) && $data[$key]['expiresAt'] > time()) {
                return false;
            }

            $data[$key] = ['value' => true, 'expiresAt' => time() + $ttlSeconds];

            return true;
        });
    }

    public function get(string $key): mixed
    {
        return $this->transaction(static function (array &$data) use ($key): mixed {
            $entry = $data[$key] ?? null;

            return ($entry !== null && $entry['expiresAt'] > time()) ? $entry['value'] : null;
        });
    }

    public function set(string $key, mixed $value, int $ttlSeconds = 86400): void
    {
        $this->transaction(static function (array &$data) use ($key, $value, $ttlSeconds): null {
            $data[$key] = ['value' => $value, 'expiresAt' => time() + $ttlSeconds];

            return null;
        });
    }

    public function forget(string $key): void
    {
        $this->transaction(static function (array &$data) use ($key): null {
            unset($data[$key]);

            return null;
        });
    }

    /**
     * 開檔 → 獨佔鎖 → 讀 → 交給 callback 改 → 寫回 → 解鎖。
     *
     * @template T
     * @param callable(array<string, array{value: mixed, expiresAt: int}> &): T $mutate
     * @return T
     */
    private function transaction(callable $mutate): mixed
    {
        $handle = @fopen($this->path, 'c+');
        if ($handle === false) {
            throw new RuntimeException("無法開啟 store 檔案：{$this->path}");
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException("無法取得檔案鎖：{$this->path}");
            }

            $size = (int) (fstat($handle)['size'] ?? 0);
            $raw = $size > 0 ? (string) fread($handle, $size) : '';
            $decoded = json_decode($raw, true);
            $data = is_array($decoded) ? $decoded : [];

            $before = $data;
            $result = $mutate($data);

            if ($data !== $before) {
                $this->purge($data);
                rewind($handle);
                ftruncate($handle, 0);
                fwrite($handle, json_encode($data, JSON_UNESCAPED_UNICODE));
                fflush($handle);
            }

            return $result;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @param array<string, array{value: mixed, expiresAt: int}> $data */
    private function purge(array &$data): void
    {
        $now = time();
        foreach ($data as $key => $entry) {
            if (!is_array($entry) || ($entry['expiresAt'] ?? 0) <= $now) {
                unset($data[$key]);
            }
        }
    }
}
