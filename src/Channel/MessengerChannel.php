<?php

declare(strict_types=1);

namespace App\Claude\Channel;

use App\Claude\Http\Response;
use App\Claude\Http\Transport;

/**
 * Facebook Messenger（Meta Graph API）。
 *
 * 比 Telegram 麻煩一個數量級，而且麻煩的地方多半不在程式碼裡：
 *
 *   - 回呼網址要先過 GET hub.challenge 握手
 *   - 每個 POST 都要驗 X-Hub-Signature-256（對**原始位元組**算 HMAC）
 *   - 24 小時訊息視窗：使用者發話後 24 小時內才能自由回覆
 *   - App 沒過 Review 的話，只有管理員/測試人員傳訊息才會進來
 */
final class MessengerChannel implements Channel
{
    private const TEXT_LIMIT = 2000;

    public function __construct(
        private readonly string $pageAccessToken,
        private readonly string $appSecret,
        private readonly string $verifyToken,
        private readonly Transport $transport,
        /** 版本要寫死。不指定會走 Meta 的預設版本，升級時無聲改行為。 */
        private readonly string $graphVersion = 'v23.0',
    ) {
        if ($this->pageAccessToken === '' || $this->appSecret === '') {
            throw new ChannelException('Messenger 需要 Page Access Token 與 App Secret');
        }
    }

    public function name(): string
    {
        return 'messenger';
    }

    /**
     * Meta 在你儲存回呼網址時會發一次 GET，帶 hub.mode / hub.verify_token / hub.challenge。
     *
     * ★ 必須把 hub.challenge **原樣、純文字**回傳。
     *   回 JSON、加引號、多包一層都會驗證失敗 —— 這是設定階段卡最久的一關。
     */
    public function challenge(WebhookRequest $request): ?WebhookResponse
    {
        if ($request->method !== 'GET' || $request->query('hub.mode') === null) {
            return null;
        }

        if ($request->query('hub.mode') !== 'subscribe'
            || !hash_equals($this->verifyToken, (string) $request->query('hub.verify_token'))) {
            return new WebhookResponse(403, 'forbidden');
        }

        return WebhookResponse::ok((string) $request->query('hub.challenge'));
    }

    public function verify(WebhookRequest $request): void
    {
        $signature = $request->header('x-hub-signature-256');
        if ($signature === null || !str_starts_with($signature, 'sha256=')) {
            throw new ChannelException('缺少 X-Hub-Signature-256');
        }

        // ★ 對原始位元組算。先 decode 再 encode 回去的字串算出來的 HMAC 對不上，
        //   而且症狀是「有中文就壞、純英文就好」這種時好時壞，最難查。
        $expected = 'sha256=' . hash_hmac('sha256', $request->rawBody, $this->appSecret);

        if (!hash_equals($expected, $signature)) {
            throw new ChannelException('X-Hub-Signature-256 驗證失敗');
        }
    }

    public function parse(WebhookRequest $request): array
    {
        $payload = $request->json();

        // Instagram 的 webhook 也會進到同一個網址，object 會是 "instagram"
        if (($payload['object'] ?? null) !== 'page') {
            return [];
        }

        $messages = [];

        // ★ 一定要攤平。Meta 可以在一個請求裡塞多個 entry、每個 entry 多則訊息，
        //   只讀 entry[0].messaging[0] 在流量一大就會漏訊息。
        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            foreach ((array) ($entry['messaging'] ?? []) as $event) {
                if (!is_array($event)) {
                    continue;
                }

                // 已讀與送達回報的量很大，而且沒有處理價值
                if (isset($event['read']) || isset($event['delivery'])) {
                    continue;
                }

                $message = $event['message'] ?? null;
                if (!is_array($message)) {
                    continue; // postback、optin 等等
                }

                // ★ is_echo 是自己送出去的訊息回音。不濾掉 bot 會跟自己無限對話 ——
                //   這是 Messenger bot 最經典的意外。
                if (($message['is_echo'] ?? false) === true) {
                    continue;
                }

                $text = $message['text'] ?? null;
                if (!is_string($text) || trim($text) === '') {
                    continue;
                }

                $psid = $event['sender']['id'] ?? null;
                if ($psid === null) {
                    continue;
                }

                $messages[] = new InboundMessage(
                    channel: $this->name(),
                    // PSID 是粉專範圍內的 ID，同一個人在不同粉專不同，也不是他的 Facebook UID
                    conversationId: (string) $psid,
                    text: trim($text),
                    messageId: (string) ($message['mid'] ?? ''),
                    userId: (string) $psid,
                    userName: '',
                    timestamp: (int) (((int) ($event['timestamp'] ?? 0)) / 1000),
                    raw: $event,
                );
            }
        }

        return $messages;
    }

    public function textLimit(): int
    {
        return self::TEXT_LIMIT;
    }

    public function send(string $conversationId, string $text): SendResult
    {
        return $this->classify($conversationId, $this->call([
            'recipient' => ['id' => $conversationId],
            // messaging_type 是必填。超過 24 小時視窗要改成 MESSAGE_TAG 並附 tag。
            'messaging_type' => 'RESPONSE',
            'message' => ['text' => $text],
        ]));
    }

    public function typing(string $conversationId): SendResult
    {
        // sender_action 與 message 互斥，必須分兩次請求
        return $this->classify($conversationId, $this->call([
            'recipient'     => ['id' => $conversationId],
            'sender_action' => 'typing_on',
        ]));
    }

    /**
     * 超過 24 小時視窗時的合法出口。
     *
     * tag 只能用在對應的情境（POST_PURCHASE_UPDATE 出貨通知、
     * CONFIRMED_EVENT_UPDATE 活動提醒、ACCOUNT_UPDATE 帳號變更、
     * HUMAN_AGENT 真人客服 7 天內）。
     * ★ 行銷訊息不在任何 tag 的許可範圍，濫用會被停權。
     */
    public function sendTagged(string $conversationId, string $text, string $tag): SendResult
    {
        return $this->classify($conversationId, $this->call([
            'recipient'      => ['id' => $conversationId],
            'messaging_type' => 'MESSAGE_TAG',
            'tag'            => $tag,
            'message'        => ['text' => $text],
        ]));
    }

    /** @param array<string, mixed> $payload */
    private function call(array $payload): Response
    {
        $url = sprintf(
            'https://graph.facebook.com/%s/me/messages?access_token=%s',
            $this->graphVersion,
            urlencode($this->pageAccessToken),
        );

        return $this->transport->request(
            'POST',
            $url,
            ['Content-Type' => 'application/json'],
            (string) json_encode($payload, JSON_UNESCAPED_UNICODE),
        );
    }

    private function classify(string $conversationId, Response $response): SendResult
    {
        if ($response->isOk()) {
            return new SendResult(SendOutcome::Sent, $conversationId, response: $response);
        }

        $error = $response->json()['error'] ?? [];
        $code = (int) ($error['code'] ?? 0);
        $description = (string) ($error['message'] ?? $response->describe());

        // 613 / 4 = 超過呼叫頻率。等待秒數在 X-Business-Use-Case-Usage 裡，
        // 而且 estimated_time_to_regain_access 的單位是**分鐘**，不是秒。
        if ($code === 613 || $code === 4 || $response->status === 429) {
            return new SendResult(
                SendOutcome::RateLimited,
                $conversationId,
                retryAfterSeconds: $this->retryAfterFromUsageHeader($response) ?? 60,
                error: $description,
                response: $response,
            );
        }

        // 終局錯誤，重試永遠不會成功：
        //   10      超過 24 小時訊息視窗
        //   551 / 2018108  使用者無法接收訊息（封鎖、停用）
        //   200     權限不足（token 種類錯或缺 pages_messaging）
        //   190     token 失效
        if (in_array($code, [10, 190, 200, 551, 2018108], true)) {
            return new SendResult(SendOutcome::PermanentFailure, $conversationId, error: "#{$code} {$description}", response: $response);
        }

        if ($response->status >= 400 && $response->status < 500) {
            return new SendResult(SendOutcome::PermanentFailure, $conversationId, error: "#{$code} {$description}", response: $response);
        }

        return new SendResult(SendOutcome::TemporaryFailure, $conversationId, error: $description, response: $response);
    }

    /** X-Business-Use-Case-Usage 的 estimated_time_to_regain_access 是分鐘，換成秒。 */
    private function retryAfterFromUsageHeader(Response $response): ?int
    {
        $raw = $response->header('x-business-use-case-usage');
        if ($raw === null) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        foreach ($decoded as $entries) {
            foreach ((array) $entries as $entry) {
                $minutes = (int) ($entry['estimated_time_to_regain_access'] ?? 0);
                if ($minutes > 0) {
                    return $minutes * 60;
                }
            }
        }

        return null;
    }
}
