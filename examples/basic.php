<?php

declare(strict_types=1);

/**
 * 原生三層寫法 —— 遷移完成後的目標樣貌。
 *
 * 跑法：ANTHROPIC_API_KEY=sk-ant-... php examples/basic.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Anthropic\Client;
use App\Claude\ClaudeCustomerService;

$client = new Client(apiKey: getenv('ANTHROPIC_API_KEY'));

// ── 第 1 層：系統提示詞。凍結的，process 啟動後就不再變。 ─────────────
//    ★ 不要在這裡插日期、客戶名字、session id —— 那會讓所有客戶的共用快取失效。
$systemPrompt = <<<'PROMPT'
你是「山姆商行」的客服人員。請遵守：
- 只依據下方提供的公司資料回答，資料裡沒有的就說需要轉專人處理。
- 語氣親切但精簡，台灣繁體中文。
- 涉及退款金額時，一律請客人確認訂單編號。
PROMPT;

// ── 第 2 層：公司共用知識庫。所有客戶讀同一份快取 —— 省最多錢的一層。 ──
//    正式環境從 DB / CMS 讀出來，開機時載入一次即可。
$companyKnowledge = [
    '退貨政策' => '商品到貨七天內，未拆封可全額退貨；已拆封需酌收 15% 整備費。',
    '運費規則' => '單筆滿 1000 元免運，未滿收 80 元。離島加收 150 元。',
    '出貨時間' => '平日下午三點前下單當日出貨，假日順延至下一個工作日。',
    '付款方式' => ['信用卡', 'ATM 轉帳', '貨到付款（限本島）'],
];

$service = new ClaudeCustomerService(
    client: $client,
    systemPrompt: $systemPrompt,
    companyKnowledge: $companyKnowledge,
    model: 'claude-opus-5',
    maxTokens: 1024,
    // 客服這種高流量路由，medium 通常就夠。必須固定在這裡：
    // 每次請求換 effort 會讓 messages 快取失效。
    effort: 'medium',
    onUsage: static function ($reply): void {
        error_log('[claude] ' . $reply->usageSummary());
    },
);

// ── 第 3、4 層：每次請求才變的部分 ───────────────────────────────────
$reply = $service->ask(
    customerId: 'CUST-88123',
    question: '我上週五買的鞋子穿了一次不合腳，可以退嗎？運費要我出嗎？',
    customerData: [
        '會員等級' => '金卡',
        '註冊日期' => '2023-04-11',
        '最近訂單' => [
            ['訂單編號' => 'ORD-99120', '商品' => '慢跑鞋 US9', '金額' => 2680, '狀態' => '已送達'],
        ],
    ],
);

echo $reply->text, "\n\n";
echo '── ', $reply->usageSummary(), "\n";
printf("相當於省下 %.0f 個 input token\n", $reply->savedInputTokensEquivalent());
