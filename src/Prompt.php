<?php

declare(strict_types=1);

namespace App\Claude;

/**
 * 前綴穩定性工具。
 *
 * Claude 的 prompt caching 是「位元組層級的前綴比對」：前綴裡任何一個 byte 變了，
 * 它後面所有斷點的快取全部失效。所以把資料轉成字串的方式必須是決定性的 ——
 * 同樣的資料每次都要產生一模一樣的 bytes。
 */
final class Prompt
{
    /**
     * 決定性 JSON 序列化。
     *
     * 這是最常見的無聲快取殺手：PHP 的 json_encode 會照 array 的插入順序輸出，
     * 所以同一筆客戶資料從 DB / Redis / API 來的鍵順序不同，就會產生不同的
     * bytes，快取永遠不命中 —— 而且完全不會報錯。遞迴 ksort 把它釘死。
     *
     * JSON_UNESCAPED_UNICODE 也是必要的：不加的話中文會被轉成 \uXXXX，
     * 不只浪費 token，跨 PHP 版本的轉義行為也可能有差異。
     */
    public static function canonicalJson(mixed $value): string
    {
        $json = json_encode(
            self::sortKeysRecursive($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
        );

        return $json;
    }

    private static function sortKeysRecursive(mixed $value): mixed
    {
        if ($value instanceof \JsonSerializable) {
            $value = $value->jsonSerialize();
        }

        if (is_object($value)) {
            $value = get_object_vars($value);
        }

        if (!is_array($value)) {
            return $value;
        }

        $isList = array_is_list($value);

        $sorted = [];
        foreach ($value as $key => $item) {
            $sorted[$key] = self::sortKeysRecursive($item);
        }

        // list 的順序是資料本身的一部分，不能排序；只排 map 的鍵。
        if (!$isList) {
            ksort($sorted, SORT_STRING);
        }

        return $sorted;
    }

    /**
     * 把結構化資料轉成穩定的文字區塊。
     *
     * 字串原樣通過（呼叫端已經自己組好），array 走 canonicalJson。
     */
    public static function asText(string|array $data): string
    {
        return is_string($data) ? $data : self::canonicalJson($data);
    }

    public static function fingerprint(string $text): string
    {
        return substr(hash('sha256', $text), 0, 12);
    }
}
