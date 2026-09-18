<?php

declare(strict_types=1);

namespace App\Claude\Channel;

use Throwable;

/**
 * webhook 的入口：握手 → 驗簽 → 解析 → 先回 200 → 才開始做事。
 *
 * 「先回 200 再做事」不是效能優化，是**正確性要求**：
 * Meta 只等 20 秒，逾時會重送、連續失敗會直接停用你的 webhook；
 * 而問 Claude 一題可能就要好幾秒。把慢的事情放在回應之後做，
 * 平台那端永遠是準時的 200。
 */
final class WebhookHandler
{
    /** @param (callable(string): void)|null $logger */
    public function __construct(
        private readonly Channel $channel,
        private readonly mixed $logger = null,
    ) {
    }

    /**
     * @param  callable(list<InboundMessage>): void $process 收到訊息後要做的事（跑 flow）
     * @param  bool $deferProcessing true = 先把回應送出去再處理（正式環境用）
     */
    public function handle(WebhookRequest $request, callable $process, bool $deferProcessing = true): WebhookResponse
    {
        // 1. 平台的回呼網址驗證握手（Meta 的 GET hub.challenge）
        $challenge = $this->channel->challenge($request);
        if ($challenge !== null) {
            return $challenge;
        }

        if ($request->method !== 'POST') {
            return new WebhookResponse(405, 'method not allowed');
        }

        // 2. 驗簽。驗不過就到此為止 —— 沒有「繼續往下試試看」這個選項。
        try {
            $this->channel->verify($request);
        } catch (ChannelException $e) {
            $this->log('驗證失敗：' . $e->getMessage());

            return new WebhookResponse(401, 'unauthorized');
        }

        // 3. 解析
        try {
            $messages = $this->channel->parse($request);
        } catch (Throwable $e) {
            $this->log('解析失敗：' . $e->getMessage());

            // ★ 這裡仍然回 200。回 5xx 的話平台會不斷重送同一份壞掉的 payload，
            //   Meta 甚至會因為連續失敗而停用整個 webhook。
            return WebhookResponse::ok();
        }

        if ($messages === []) {
            return WebhookResponse::ok();
        }

        $response = WebhookResponse::ok();

        if ($deferProcessing) {
            $this->respondNow($response);
        }

        try {
            $process($messages);
        } catch (Throwable $e) {
            // 已經回過 200 了，這裡只能記 log。這正是為什麼 flow 自己要有
            // fallback 訊息 —— 否則使用者會完全沒有下文。
            $this->log('處理失敗：' . $e::class . ': ' . $e->getMessage());
        }

        return $deferProcessing ? new WebhookResponse(200, '', 'text/plain; charset=utf-8') : $response;
    }

    /**
     * 把回應送出去並切斷連線，之後的處理平台就不會等了。
     *
     * php-fpm 有 fastcgi_finish_request；php -S 之類的 SAPI 沒有，
     * 那裡就退化成「照常回應，只是連線還開著」。
     */
    private function respondNow(WebhookResponse $response): void
    {
        if (headers_sent()) {
            return;
        }

        $response->emit();

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();

            return;
        }

        if (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();

            return;
        }

        // 沒有對應函式時至少把 buffer 吐出去
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        flush();
    }

    private function log(string $message): void
    {
        if (is_callable($this->logger)) {
            ($this->logger)("[{$this->channel->name()}] {$message}");
        }
    }
}
