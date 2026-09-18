<?php

declare(strict_types=1);

namespace App\Claude\Flow\Steps;

use App\Claude\Channel\Channel;
use App\Claude\Channel\SendOutcome;
use App\Claude\Flow\Step;

/**
 * 把 item 逐則送出去，內建節流與「照平台指定秒數」的退避。
 *
 * 這是整套裡唯一會主動 sleep 的地方，因為限流本來就只能用時間解決。
 * 三類結果的處理完全不同：
 *
 *   Sent             完成
 *   RateLimited      等平台指定的秒數再送同一則（Telegram 給 retry_after）
 *   PermanentFailure 立刻放棄。使用者封鎖了 bot 的話，重試一百次也是一百次失敗，
 *                    只會把配額燒在同一批人身上
 */
final class SendMessages implements Step
{
    private float $lastSentAt = 0.0;

    /**
     * @param float                        $messagesPerSecond 節流上限。Telegram 同一個 chat 約 1 則/秒
     * @param int                          $maxAttempts       含第一次在內的總嘗試次數
     * @param (callable(float): void)|null $sleeper           注入點：測試時換成不真的睡的版本
     */
    public function __construct(
        private readonly Channel $channel,
        private readonly float $messagesPerSecond = 1.0,
        private readonly int $maxAttempts = 3,
        private readonly mixed $sleeper = null,
        private readonly string $textField = 'text',
        private readonly string $name = '送出訊息',
    ) {
    }

    public function __invoke(array $items): array
    {
        $out = [];

        foreach ($items as $item) {
            $conversationId = (string) ($item['conversationId'] ?? '');
            $text = (string) ($item[$this->textField] ?? '');

            if ($conversationId === '' || $text === '') {
                continue;
            }

            $attempts = 0;
            $result = null;

            while ($attempts < $this->maxAttempts) {
                $attempts++;
                $this->throttle();

                $result = $this->channel->send($conversationId, $text);
                $this->lastSentAt = microtime(true);

                if (!$result->shouldRetry()) {
                    break; // 送成功了，或是重試也沒用的終局錯誤
                }

                if ($attempts >= $this->maxAttempts) {
                    break;
                }

                // ★ 平台說等幾秒就等幾秒。自己決定等 2 秒只會被繼續擋。
                //   沒給秒數時退回指數退避。
                $wait = $result->retryAfterSeconds ?? (2 ** ($attempts - 1));
                $this->sleep((float) $wait);
            }

            $out[] = array_merge($item, [
                'outcome'  => $result?->outcome->name ?? SendOutcome::TemporaryFailure->name,
                'attempts' => $attempts,
                'error'    => $result?->error ?? '',
            ]);
        }

        return $out;
    }

    public function name(): string
    {
        return $this->name;
    }

    /** 兩次送出之間至少隔 1/messagesPerSecond 秒。 */
    private function throttle(): void
    {
        if ($this->messagesPerSecond <= 0 || $this->lastSentAt === 0.0) {
            return;
        }

        $minInterval = 1.0 / $this->messagesPerSecond;
        $elapsed = microtime(true) - $this->lastSentAt;

        if ($elapsed < $minInterval) {
            $this->sleep($minInterval - $elapsed);
        }
    }

    private function sleep(float $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }

        if (is_callable($this->sleeper)) {
            ($this->sleeper)($seconds);

            return;
        }

        usleep((int) ($seconds * 1_000_000));
    }
}
