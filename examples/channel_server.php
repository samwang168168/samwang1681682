<?php

declare(strict_types=1);

/**
 * 通訊軟體通道的正式入口。
 *
 * Telegram 與 Messenger 各一個網址，共用同一條 flow、同一份快取分層。
 *
 * 跑法：
 *   ANTHROPIC_API_KEY=sk-ant-... \
 *   TELEGRAM_BOT_TOKEN=123:AA... TELEGRAM_WEBHOOK_SECRET=$(openssl rand -hex 32) \
 *   PAGE_ACCESS_TOKEN=EAA... META_APP_SECRET=... META_VERIFY_TOKEN=... \
 *     php -S 0.0.0.0:8080 -t examples
 *
 * 平台後台填的 webhook 網址：
 *   Telegram   https://你的網域/channel_server.php/telegram
 *   Messenger  https://你的網域/channel_server.php/messenger
 *
 * ★ 正式環境請放在 php-fpm 後面。php -S 是單執行緒的，
 *   一個請求在等 Claude 回應時會擋住其他所有請求。
 */

require __DIR__ . '/../vendor/autoload.php';

use Anthropic\Client;
use App\Claude\Channel\Channel;
use App\Claude\Channel\MessengerChannel;
use App\Claude\Channel\TelegramChannel;
use App\Claude\Channel\WebhookHandler;
use App\Claude\Channel\WebhookRequest;
use App\Claude\Channel\WebhookResponse;
use App\Claude\ClaudeCustomerService;
use App\Claude\Flow\Flow;
use App\Claude\Flow\Steps\Call;
use App\Claude\Flow\Steps\Dedupe;
use App\Claude\Flow\Steps\Map;
use App\Claude\Flow\Steps\SendMessages;
use App\Claude\Flow\Steps\SplitText;
use App\Claude\Http\CurlTransport;
use App\Claude\Store\FileStore;

$log = static fn (string $message): bool => error_log($message);

// ── 共用元件 ────────────────────────────────────────────────
$transport = new CurlTransport();

// 去重狀態要跨 process 共用 —— php-fpm 每個請求都是新的 process，
// 用 MemoryStore 等於完全沒有去重。多台機器請換成 Redis 版本。
$store = new FileStore(sys_get_temp_dir() . '/channel-store.json');

// ── 第 1、2 層：凍結的兩層。★ 絕對不要放日期、customerId、通道名稱。 ──
//    放進去 = 每個客戶各自持有一份快取，跨客戶完全無法共用。
$systemPrompt = <<<'PROMPT'
你是「山姆商行」的客服人員。請遵守：
- 只依據下方提供的公司資料回答，資料裡沒有的就說需要轉專人處理。
- 語氣親切但精簡，台灣繁體中文。
- 這是通訊軟體通道，回覆請控制在三百字以內，不要用 Markdown 表格。
- 涉及退款金額時，一律請客人確認訂單編號。
PROMPT;

// 正式環境從 DB / CMS 讀，開機載入一次即可。內容一變快取就要重建。
$companyKnowledge = [
    '退貨政策' => '商品到貨七天內，未拆封可全額退貨；已拆封需酌收 15% 整備費。',
    '運費規則' => '單筆滿 1000 元免運，未滿收 80 元。離島加收 150 元。',
    '出貨時間' => '平日下午三點前下單當日出貨，假日順延至下一個工作日。',
    '付款方式' => ['信用卡', 'ATM 轉帳', '貨到付款（限本島）'],
];

$service = new ClaudeCustomerService(
    client: new Client(apiKey: getenv('ANTHROPIC_API_KEY') ?: ''),
    systemPrompt: $systemPrompt,
    companyKnowledge: $companyKnowledge,
    model: 'claude-opus-5',
    maxTokens: 1024,
    effort: 'medium',
    onUsage: static function ($reply) use ($log): void {
        // 連續好幾次 cacheRead 都是 0 就代表有隱形失效點，值得掛成監控告警
        $log('[claude] ' . $reply->usageSummary());
    },
);

// ── 一條通道的 flow。Telegram 與 Messenger 共用，差別只在 Channel 實作。 ──
$buildFlow = static function (Channel $channel) use ($service, $store, $log): Flow {
    return (new Flow($channel->name() . ' 客服', $log))
        // 1. 去重。webhook 一定會重送 —— 少了這步使用者收到兩次回覆，你付兩次錢。
        ->step(new Dedupe($store, static fn (array $i): string => $channel->name() . ':' . $i['messageId']))

        // 2. 旁支：先讓對方看到「正在輸入…」。失敗不影響主線。
        ->tap(new Call('打字中', static function (array $items) use ($channel): array {
            foreach ($items as $item) {
                $channel->typing((string) $item['conversationId']);
            }

            return $items;
        }))

        // 3. 問 Claude。失敗重試兩次，仍失敗就放行 —— 由下一步給 fallback 文案，
        //    使用者不會完全沒有下文。
        ->step(
            new Map('問 Claude', static function (array $item) use ($service, $channel): array {
                $reply = $service->ask(
                    // 通道內的 ID，要自己對應回會員系統
                    customerId: strtoupper($channel->name()) . '-' . $item['userId'],
                    question: (string) $item['text'],
                    customerData: lookupCustomerData((string) $item['userId']),
                    // 通道別走 turnInstruction（system message 通道），不會動到已快取的前綴 ——
                    // 所以 Telegram 與 Messenger 讀的是同一份公司知識庫快取。
                    turnInstruction: '本次來源通道：' . $channel->name() . '。',
                );

                return array_merge($item, [
                    'question' => $item['text'],
                    'text'     => $reply->text,
                    // continueOnError 放行時 items 是原封不動的，沒有這個旗標
                    // 就分不出「Claude 回了空字串」和「這一步整個失敗了」
                    'answered' => true,
                ]);
            }),
            retries: 2,
            delayMs: 500,
            continueOnError: true,
        )
        ->step(new Map('沒有回覆就給 fallback', static function (array $item): array {
            $answered = ($item['answered'] ?? false) === true && trim((string) $item['text']) !== '';

            return $answered
                ? $item
                : array_merge($item, ['text' => '抱歉，系統忙線中，請稍後再試一次。']);
        }))

        // 4. 切段。Telegram 4096、Messenger 2000 —— 超過整則會 400，使用者什麼都收不到。
        ->step(new SplitText($channel->textLimit()))

        // 5. 送出。內建節流與「照平台指定秒數」的退避。
        ->step(new SendMessages($channel, messagesPerSecond: 1.0, maxAttempts: 3));
};

// ── 路由 ────────────────────────────────────────────────────
$request = WebhookRequest::fromGlobals();
$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';

$channel = match (true) {
    str_ends_with($path, '/telegram') => new TelegramChannel(
        botToken: getenv('TELEGRAM_BOT_TOKEN') ?: '',
        transport: $transport,
        // 有設就會驗 X-Telegram-Bot-Api-Secret-Token。
        // 沒有它，任何知道你網址的人都能偽造訊息。
        webhookSecret: getenv('TELEGRAM_WEBHOOK_SECRET') ?: null,
    ),
    str_ends_with($path, '/messenger') => new MessengerChannel(
        pageAccessToken: getenv('PAGE_ACCESS_TOKEN') ?: '',
        appSecret: getenv('META_APP_SECRET') ?: '',
        verifyToken: getenv('META_VERIFY_TOKEN') ?: '',
        transport: $transport,
    ),
    default => null,
};

if ($channel === null) {
    (new WebhookResponse(404, 'unknown channel'))->emit();
    exit;
}

$handler = new WebhookHandler($channel, $log);

$response = $handler->handle($request, static function (array $messages) use ($buildFlow, $channel, $log): void {
    $flow = $buildFlow($channel);
    $flow->run(array_map(static fn ($message): array => $message->toItem(), $messages));

    // 等同 n8n 的 Executions 畫面，但是純資料 —— 可以直接拿去做 CI 斷言
    $log($flow->traceSummary());
});

// deferProcessing 預設是 true：回應在處理開始前就已經送出去了，
// 這裡再 emit 一次會是 no-op（headers_sent() 為真）。
if (!headers_sent()) {
    $response->emit();
}

/**
 * 查該客戶的專屬資料。示範用，正式環境換成你的 DB 查詢。
 *
 * 查不到就回空陣列 —— Claude 會照 systemPrompt 的指示說需要轉專人。
 */
function lookupCustomerData(string $channelUserId): array
{
    return [
        '通道識別碼' => $channelUserId,
        '會員等級'   => '一般會員',
        '最近訂單'   => [],
    ];
}
