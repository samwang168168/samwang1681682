<?php

declare(strict_types=1);

namespace App\Claude\Channel;

use App\Claude\Http\Response;
use App\Claude\Http\Transport;

/**
 * Telegram Bot API。
 *
 * 踩雷點都寫在對應的方法上。最容易錯的三個：
 *   1. 回訊息要用 chat.id，不是 from.id（群組裡兩者不同）
 *   2. 不是每個 update 都有 message.text（貼圖、照片、加入群組通知都會進來）
 *   3. 429 的等待秒數在 body 的 parameters.retry_after，不是 Retry-After header
 */
final class TelegramChannel implements Channel
{
    private const API = 'https://api.telegram.org';

    /** 單則訊息上限，Telegram 官方值。 */
    private const TEXT_LIMIT = 4096;

    /**
     * @param string|null $webhookSecret setWebhook 時設的 secret_token。
     *                                   有設就會驗 X-Telegram-Bot-Api-Secret-Token ——
     *                                   沒有它，任何知道你網址的人都能偽造訊息。
     */
    public function __construct(
        private readonly string $botToken,
        private readonly Transport $transport,
        private readonly ?string $webhookSecret = null,
    ) {
        if ($this->botToken === '') {
            throw new ChannelException('Telegram bot token 是空的');
        }
    }

    public function name(): string
    {
        return 'telegram';
    }

    /** Telegram 沒有 GET 驗證握手這回事，一律回 null。 */
    public function challenge(WebhookRequest $request): ?WebhookResponse
    {
        return null;
    }

    public function verify(WebhookRequest $request): void
    {
        if ($this->webhookSecret === null) {
            return; // 沒設就不驗。正式環境建議一定要設。
        }

        $provided = $request->header('x-telegram-bot-api-secret-token') ?? '';

        // hash_equals 是定值時間比較，不要用 ===
        if (!hash_equals($this->webhookSecret, $provided)) {
            throw new ChannelException('X-Telegram-Bot-Api-Secret-Token 不符');
        }
    }

    public function parse(WebhookRequest $request): array
    {
        $update = $request->json();
        $message = $update['message'] ?? null;

        // callback_query、edited_message、my_chat_member… 都不是這裡要處理的
        if (!is_array($message)) {
            return [];
        }

        $text = $message['text'] ?? null;
        if (!is_string($text) || trim($text) === '') {
            return []; // 貼圖、照片、加入群組通知
        }

        $chat = $message['chat'] ?? [];
        $chatId = $chat['id'] ?? null;
        if ($chatId === null) {
            return [];
        }

        $from = $message['from'] ?? [];

        return [new InboundMessage(
            channel: $this->name(),
            // ★ chat.id，不是 from.id。群組裡 chat.id 是負數的群組 ID，
            //   用錯會把群組的回覆私訊給發話者。
            conversationId: (string) $chatId,
            text: trim($text),
            // update_id 全 bot 唯一且遞增，是去重的正確 key
            messageId: (string) ($update['update_id'] ?? ''),
            userId: isset($from['id']) ? (string) $from['id'] : null,
            userName: (string) ($from['username'] ?? $from['first_name'] ?? ''),
            timestamp: (int) ($message['date'] ?? 0),
            raw: $update,
        )];
    }

    public function textLimit(): int
    {
        return self::TEXT_LIMIT;
    }

    public function send(string $conversationId, string $text): SendResult
    {
        // 不設 parse_mode = 純文字送出。LLM 產生的內容幾乎一定會含到
        // MarkdownV2 的 18 個保留字元，漏跳脫一個就整則 400。
        return $this->classify($conversationId, $this->call('sendMessage', [
            'chat_id' => $conversationId,
            'text'    => $text,
        ]));
    }

    public function typing(string $conversationId): SendResult
    {
        // typing 狀態只持續 5 秒，更久要重送
        return $this->classify($conversationId, $this->call('sendChatAction', [
            'chat_id' => $conversationId,
            'action'  => 'typing',
        ]));
    }

    /** @param array<string, mixed> $payload */
    private function call(string $method, array $payload): Response
    {
        return $this->transport->request(
            'POST',
            self::API . '/bot' . $this->botToken . '/' . $method,
            ['Content-Type' => 'application/json'],
            (string) json_encode($payload, JSON_UNESCAPED_UNICODE),
        );
    }

    private function classify(string $conversationId, Response $response): SendResult
    {
        if ($response->isOk()) {
            return new SendResult(SendOutcome::Sent, $conversationId, response: $response);
        }

        $body = $response->json();
        $description = (string) ($body['description'] ?? $response->describe());

        // 429：Telegram 直接告訴你要等幾秒，在 body 裡而不是 header。
        // 照它說的等 —— 對方叫你等 30 秒你等 2 秒，只會被繼續擋。
        if ($response->status === 429) {
            $retryAfter = $body['parameters']['retry_after'] ?? 30;

            return new SendResult(
                SendOutcome::RateLimited,
                $conversationId,
                retryAfterSeconds: max(1, (int) $retryAfter),
                error: $description,
                response: $response,
            );
        }

        // 403 = 使用者封鎖了 bot / bot 被踢出群組。
        // ★ 終局錯誤。跟 429 混在同一個重試邏輯裡，會讓每次廣播都在
        //   同一批封鎖名單上白白燒掉配額。
        if ($response->status === 403) {
            return new SendResult(SendOutcome::PermanentFailure, $conversationId, error: $description, response: $response);
        }

        // 400 多半是 chat not found、訊息過長、parse_mode 壞掉 —— 重試也不會好
        if ($response->status >= 400 && $response->status < 500) {
            return new SendResult(SendOutcome::PermanentFailure, $conversationId, error: $description, response: $response);
        }

        return new SendResult(SendOutcome::TemporaryFailure, $conversationId, error: $description, response: $response);
    }
}
