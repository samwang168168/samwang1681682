<?php

declare(strict_types=1);

/**
 * n8n → 客服後端的橋接端點。
 *
 * .claude/skills/ 底下的四個 n8n workflow 都打這支。它做三件事：
 * 收下 n8n 送來的 JSON、丟給 ClaudeCustomerService、回一個 {"reply": "..."}。
 *
 * 跑法（本機）：
 *   ANTHROPIC_API_KEY=sk-ant-... CS_SHARED_SECRET=隨便一組長字串 \
 *     php -S 0.0.0.0:8080 -t examples
 *
 * n8n 那邊設定：
 *   CS_BACKEND_URL    = http://host.docker.internal:8080   （n8n 跑在 docker 時）
 *   CS_SHARED_SECRET  = 與這裡相同的字串
 *
 * 請求：
 *   POST /n8n_webhook.php
 *   X-Shared-Secret: <CS_SHARED_SECRET>
 *   { "channel": "telegram", "customerId": "TG-987654321",
 *     "question": "可以退貨嗎？", "meta": { ... } }
 *
 * 回應：
 *   200 { "reply": "...", "usage": { ... } }
 *   4xx/5xx { "error": "..." }   ← workflow 會改送 fallback 訊息給使用者
 */

require __DIR__ . '/../vendor/autoload.php';

use Anthropic\Client;
use App\Claude\ClaudeCustomerService;

header('Content-Type: application/json; charset=utf-8');

/** 統一的錯誤出口。訊息只給概況,細節進 error_log,不要外洩到通訊軟體上。 */
function fail(int $status, string $message, ?string $detail = null): never
{
    http_response_code($status);
    if ($detail !== null) {
        error_log("[n8n_webhook] {$message}: {$detail}");
    }
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── 1. 只收 POST ──────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    fail(405, 'method not allowed');
}

// ── 2. 驗共用密鑰 ─────────────────────────────────────────────
//     這支端點會被 Telegram / Meta 的內容間接餵資料,一定要擋住外部直接呼叫。
$expectedSecret = getenv('CS_SHARED_SECRET') ?: '';
$providedSecret = $_SERVER['HTTP_X_SHARED_SECRET'] ?? '';

if ($expectedSecret === '') {
    fail(500, 'server misconfigured', 'CS_SHARED_SECRET 沒設');
}
// hash_equals 是定值時間比較,不要用 ===
if (!hash_equals($expectedSecret, $providedSecret)) {
    fail(401, 'unauthorized');
}

// ── 3. 解析輸入 ───────────────────────────────────────────────
$body = file_get_contents('php://input') ?: '';
$input = json_decode($body, true);

if (!is_array($input)) {
    fail(400, 'invalid json');
}

$channel    = (string) ($input['channel'] ?? 'unknown');
$customerId = (string) ($input['customerId'] ?? '');
$question   = trim((string) ($input['question'] ?? ''));

if ($customerId === '' || $question === '') {
    fail(400, 'customerId 與 question 為必填');
}

// 通訊軟體的訊息長度上限遠比人會打的長,超過的多半是貼上整份文件或洗版
if (mb_strlen($question) > 4000) {
    $question = mb_substr($question, 0, 4000);
}

// ── 4. 凍結的兩層。★ 這裡絕對不要放日期、customerId、channel。 ─────
//     放進去 = 每個客戶各自持有一份快取,跨客戶完全無法共用。
$systemPrompt = <<<'PROMPT'
你是「山姆商行」的客服人員。請遵守：
- 只依據下方提供的公司資料回答，資料裡沒有的就說需要轉專人處理。
- 語氣親切但精簡，台灣繁體中文。
- 這是通訊軟體通道，回覆請控制在三百字以內，不要用 Markdown 表格。
- 涉及退款金額時，一律請客人確認訂單編號。
PROMPT;

// 正式環境從 DB / CMS 讀,開機載入一次即可。內容一變快取就要重建,不要每次請求組。
$companyKnowledge = [
    '退貨政策' => '商品到貨七天內，未拆封可全額退貨；已拆封需酌收 15% 整備費。',
    '運費規則' => '單筆滿 1000 元免運，未滿收 80 元。離島加收 150 元。',
    '出貨時間' => '平日下午三點前下單當日出貨，假日順延至下一個工作日。',
    '付款方式' => ['信用卡', 'ATM 轉帳', '貨到付款（限本島）'],
];

// ── 5. 問 Claude ──────────────────────────────────────────────
$apiKey = getenv('ANTHROPIC_API_KEY') ?: '';
if ($apiKey === '') {
    fail(500, 'server misconfigured', 'ANTHROPIC_API_KEY 沒設');
}

try {
    $service = new ClaudeCustomerService(
        client: new Client(apiKey: $apiKey),
        systemPrompt: $systemPrompt,
        companyKnowledge: $companyKnowledge,
        model: 'claude-opus-5',
        maxTokens: 1024,
        effort: 'medium',
        onUsage: static function ($reply) use ($channel): void {
            // 連續好幾次 cache_read 都是 0 就代表有隱形失效點,值得掛成監控告警
            error_log("[n8n_webhook][{$channel}] " . $reply->usageSummary());
        },
    );

    // channel 走 turnInstruction（system message 通道）,不會動到已快取的前綴
    $reply = $service->ask(
        customerId: $customerId,
        question: $question,
        customerData: lookupCustomerData($customerId),
        turnInstruction: "本次來源通道：{$channel}。",
    );
} catch (Throwable $e) {
    // 對外只回概況。workflow 收到非 2xx 會改送 fallback 訊息,使用者不會沒下文。
    fail(502, 'upstream error', $e::class . ': ' . $e->getMessage());
}

echo json_encode([
    'reply' => $reply->text,
    'usage' => [
        'cacheRead'  => $reply->cacheReadTokens,
        'cacheWrite' => $reply->cacheWriteTokens,
        'input'      => $reply->inputTokens,
        'output'     => $reply->outputTokens,
        'hitRatio'   => round($reply->cacheHitRatio(), 3),
    ],
], JSON_UNESCAPED_UNICODE);

/**
 * 查該客戶的專屬資料。這裡是示範,正式環境換成你的 DB 查詢。
 *
 * customerId 的長相是 "TG-<telegram user id>" 或 "FB-<PSID>" ——
 * 通訊軟體給的是通道內的 ID,要自己對應回會員系統。查不到就回空陣列,
 * Claude 會照 systemPrompt 的指示說需要轉專人。
 */
function lookupCustomerData(string $customerId): array
{
    return [
        '通道識別碼' => $customerId,
        '會員等級'   => '一般會員',
        '最近訂單'   => [],
    ];
}
