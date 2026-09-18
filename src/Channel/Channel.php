<?php

declare(strict_types=1);

namespace App\Claude\Channel;

/**
 * 一個通訊軟體通道。
 *
 * 這是整套東西的核心介面：上層的 flow 只認得這五個動作，
 * 要新增 LINE / WhatsApp / Discord 就是再實作一個類別，流程完全不用改。
 */
interface Channel
{
    /** 'telegram' | 'messenger' | … */
    public function name(): string;

    /**
     * 平台的「回呼網址驗證」握手（Meta 的 GET hub.challenge）。
     *
     * @return WebhookResponse|null null = 這個請求不是驗證握手，照一般流程處理
     */
    public function challenge(WebhookRequest $request): ?WebhookResponse;

    /**
     * 驗證這個請求真的來自平台。
     *
     * 失敗一律丟 ChannelException —— 驗不過的請求不該有「繼續往下試試看」這個選項。
     *
     * @throws ChannelException
     */
    public function verify(WebhookRequest $request): void;

    /**
     * 把平台的 payload 攤平成一則一則的訊息。
     *
     * 已經幫你濾掉：非文字訊息、自己送出的回音、已讀/送達回報。
     *
     * @return list<InboundMessage>
     */
    public function parse(WebhookRequest $request): array;

    /** 單則訊息的字元上限。Telegram 4096、Messenger 2000。 */
    public function textLimit(): int;

    public function send(string $conversationId, string $text): SendResult;

    /** 「對方正在輸入…」。LLM 要想幾秒時先送這個，否則使用者會以為 bot 死了。 */
    public function typing(string $conversationId): SendResult;
}
