<?php

declare(strict_types=1);

namespace App\Claude;

use Anthropic\Messages\Message;

/**
 * 一次客服回覆，附帶該次請求的快取計費明細。
 *
 * 快取是「無聲」失效的 —— 不會拋錯，只是帳單變貴。所以每次回覆都把
 * usage 帶出來，讓呼叫端可以記到 log / metrics，而不是等對帳單才發現。
 */
final class Reply
{
    public function __construct(
        public readonly string $text,
        /** 完整計價的 token（未命中快取的部分，通常只有客人的問題） */
        public readonly int $inputTokens,
        /** 寫入快取的 token，計價約 1.25x */
        public readonly int $cacheWriteTokens,
        /** 從快取讀出的 token，計價約 0.1x —— 這個數字愈大愈好 */
        public readonly int $cacheReadTokens,
        public readonly int $outputTokens,
        public readonly string $stopReason,
        public readonly Message $raw,
    ) {
    }

    public static function fromMessage(Message $message): self
    {
        $text = '';
        foreach ($message->content as $block) {
            // thinking 開啟時 ThinkingBlock 會排在 TextBlock 前面，
            // 直接取 content[0]->text 會炸，一定要逐塊判斷型別。
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }

        $usage = $message->usage;

        return new self(
            text: $text,
            inputTokens: $usage->inputTokens ?? 0,
            cacheWriteTokens: $usage->cacheCreationInputTokens ?? 0,
            cacheReadTokens: $usage->cacheReadInputTokens ?? 0,
            outputTokens: $usage->outputTokens ?? 0,
            stopReason: $message->stopReason ?? 'end_turn',
            raw: $message,
        );
    }

    /**
     * 這次請求的完整 prompt 大小。
     *
     * 注意 inputTokens 只是「沒命中快取的殘餘」，不是總量 ——
     * 看單一欄位會嚴重低估 prompt 實際有多大。
     */
    public function totalPromptTokens(): int
    {
        return $this->inputTokens + $this->cacheWriteTokens + $this->cacheReadTokens;
    }

    /**
     * 命中率：這次 prompt 有多少比例是用 0.1x 價錢買到的。
     */
    public function cacheHitRatio(): float
    {
        $total = $this->totalPromptTokens();

        return $total > 0 ? $this->cacheReadTokens / $total : 0.0;
    }

    /**
     * 相對於「完全不用快取」省下多少等效 input token。
     * 命中的部分只付 0.1x，寫入的部分多付 0.25x。
     */
    public function savedInputTokensEquivalent(): float
    {
        return ($this->cacheReadTokens * 0.9) - ($this->cacheWriteTokens * 0.25);
    }

    public function usageSummary(): string
    {
        return sprintf(
            'prompt=%d (未快取 %d / 讀取 %d / 寫入 %d) 輸出=%d 命中率=%.1f%%',
            $this->totalPromptTokens(),
            $this->inputTokens,
            $this->cacheReadTokens,
            $this->cacheWriteTokens,
            $this->outputTokens,
            $this->cacheHitRatio() * 100,
        );
    }
}
