<?php

declare(strict_types=1);

/**
 * 遷移第一步：接上相容層，呼叫端幾乎不用改。
 *
 * 跑法：ANTHROPIC_API_KEY=sk-ant-... php examples/migrate_from_gemini.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Anthropic\Client;
use App\Claude\GeminiCompat;

$client = new Client(apiKey: getenv('ANTHROPIC_API_KEY'));

// ─────────────────────────────────────────────────────────────────────
// 這一段完全是你們現在的程式碼，一個字都不用動。
// ─────────────────────────────────────────────────────────────────────
$systemPrompt = '你是「山姆商行」的客服人員，只依據下方公司資料回答，語氣親切精簡。';

$companyKnowledge = implode("\n", [
    '退貨政策：到貨七天內未拆封可全額退貨；已拆封酌收 15% 整備費。',
    '運費規則：單筆滿 1000 免運，未滿收 80。離島加收 150。',
    '出貨時間：平日下午三點前下單當日出貨。',
]);

$customerData = json_encode([
    '會員等級' => '金卡',
    '最近訂單' => [['訂單編號' => 'ORD-99120', '商品' => '慢跑鞋', '金額' => 2680]],
], JSON_UNESCAPED_UNICODE);

$question = '鞋子穿了一次不合腳，可以退嗎？';

$geminiRequest = [
    'systemInstruction' => ['parts' => [['text' => $systemPrompt]]],
    'contents' => [
        ['role' => 'user', 'parts' => [
            ['text' => $companyKnowledge],   // part 0：公司共用
            ['text' => $customerData],       // part 1：客戶專屬
            ['text' => $question],           // part 2：這次的問題
        ]],
    ],
    'generationConfig' => [
        'maxOutputTokens' => 1024,
        'temperature' => 0.7,   // -> 會被丟棄並警告（Opus 5 送了會 400）
    ],
];

// ─────────────────────────────────────────────────────────────────────
// 改的只有這裡：原本呼叫 Gemini 的那一行，換成轉接層。
//
// 兩個索引是關鍵，指的都是「原始 payload 裡 contents[0].parts 的位置」：
//   promoteToSystem   把公司共用知識提到 system 層 -> 所有客戶共用同一份快取
//   customerDataParts 客戶專屬資料原地打斷點       -> 該客戶命中
//   剩下沒指定的（問題）不打斷點，因為每次都不同。
// ─────────────────────────────────────────────────────────────────────
$reply = GeminiCompat::send($client, $geminiRequest, [
    'model' => 'claude-opus-5',
    'promoteToSystem' => [0],
    'customerDataParts' => [1],
    'effort' => 'medium',
]);

echo $reply->text, "\n\n";
echo '── ', $reply->usageSummary(), "\n";

// 想先看轉出來長怎樣、不送出，用 translate()：
$translation = GeminiCompat::translate($geminiRequest, [
    'promoteToSystem' => [0],
    'customerDataParts' => [1],
]);

echo "\n轉換警告：\n";
foreach ($translation->warnings as $warning) {
    echo "  - {$warning}\n";
}
