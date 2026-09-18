<?php

declare(strict_types=1);

namespace App\Claude\Flow\Steps;

use App\Claude\Flow\Step;

/**
 * 把超長文字切成多筆 item。
 *
 * Telegram 上限 4096 字元、Messenger 2000 —— LLM 的回覆很容易超過，
 * 而超過的下場是整則訊息 400，使用者什麼都收不到。
 *
 * 切點優先序：段落 → 換行 → 句尾 → 空白 → 硬切。
 * 全程用 mb_* 以字元為單位，中文與 emoji 不會被切成半個。
 */
final class SplitText implements Step
{
    public function __construct(
        private readonly int $limit,
        private readonly string $field = 'text',
        private readonly string $name = '切段',
    ) {
    }

    public function __invoke(array $items): array
    {
        $out = [];

        foreach ($items as $item) {
            $chunks = self::split((string) ($item[$this->field] ?? ''), $this->limit);
            $total = count($chunks);

            foreach ($chunks as $index => $chunk) {
                // array_merge 右邊優先，所以原本的 text 會被切出來的那段蓋掉
                $out[] = array_merge($item, [
                    $this->field => $chunk,
                    'part'       => $index + 1,
                    'partCount'  => $total,
                ]);
            }
        }

        return $out;
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * 純函式，方便單獨測試與在 flow 外重用。
     *
     * @return list<string>
     */
    public static function split(string $text, int $limit): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }
        if (mb_strlen($text) <= $limit) {
            return [$text];
        }

        $chunks = [];
        $rest = $text;

        while (mb_strlen($rest) > $limit) {
            $window = mb_substr($rest, 0, $limit);
            $cut = 0;

            // 規則有優先序：先試段落，再換行，再句尾，最後才是任意空白。
            // 同一層級裡有多個候選字元時取最後面的那個（切得愈滿愈好）。
            // minRatio 是「切點不能太靠前」的門檻 —— 否則會切出一堆超短訊息。
            foreach ([[["\n\n"], 0.3], [["\n"], 0.3], [['。', '！', '？', '. '], 0.5], [[' '], 0.7]] as [$needles, $minRatio]) {
                foreach ($needles as $needle) {
                    $position = mb_strrpos($window, $needle);
                    if ($position !== false) {
                        $candidate = $position + mb_strlen($needle);
                        if ($candidate > $limit * $minRatio) {
                            $cut = max($cut, $candidate);
                        }
                    }
                }
                if ($cut > 0) {
                    break; // 這一層級找到了就不再往下找，維持優先序
                }
            }

            if ($cut <= 0) {
                $cut = $limit; // 沒有任何可用切點（例如一長串無空白字元）→ 硬切
            }

            $chunk = rtrim(mb_substr($rest, 0, $cut));
            if ($chunk !== '') {
                $chunks[] = $chunk;
            }
            $rest = ltrim(mb_substr($rest, $cut));
        }

        $rest = rtrim($rest);
        if ($rest !== '') {
            $chunks[] = $rest;
        }

        return $chunks;
    }
}
