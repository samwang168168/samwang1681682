<?php

declare(strict_types=1);

namespace App\Claude\Channel;

/**
 * 正規化之後的一則訊息。
 *
 * Telegram 的 chat.id 與 Messenger 的 PSID 在這裡都叫 conversationId ——
 * 上層流程因此完全不必知道訊息是從哪個平台來的。
 */
final class InboundMessage
{
    /** @param array<mixed> $raw 原始 payload，需要平台專屬欄位時再挖 */
    public function __construct(
        public readonly string $channel,
        /** 回訊息要用的 ID。Telegram 是 chat.id（★ 不是 from.id），Messenger 是 PSID。 */
        public readonly string $conversationId,
        public readonly string $text,
        /** 去重用的唯一 ID。Telegram 是 update_id，Messenger 是 message.mid。 */
        public readonly string $messageId,
        public readonly ?string $userId = null,
        public readonly string $userName = '',
        public readonly int $timestamp = 0,
        public readonly array $raw = [],
    ) {
    }

    /**
     * 攤平成 flow 在用的 item。
     *
     * @return array<string, mixed>
     */
    public function toItem(): array
    {
        return [
            'channel'        => $this->channel,
            'conversationId' => $this->conversationId,
            'text'           => $this->text,
            'messageId'      => $this->messageId,
            'userId'         => $this->userId,
            'userName'       => $this->userName,
            'timestamp'      => $this->timestamp,
        ];
    }
}
