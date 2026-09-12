<?php

declare(strict_types=1);

/**
 * 對真實 API 驗證快取是否真的在運作（會花一點錢，約兩次請求）。
 *
 * 跑法：ANTHROPIC_API_KEY=sk-ant-... php tools/verify_cache.php
 *
 * 原理：連送兩次「前綴完全相同、只有問題不同」的請求。
 *   第一次 —— 應該看到 cache_creation（寫入）
 *   第二次 —— 應該看到 cache_read > 0（命中）
 *
 * 第二次如果 cache_read 還是 0，就代表前綴裡有東西在變。這個檢查值得放進
 * CI 或上線前的 smoke test：快取失效是無聲的，只有帳單會告訴你。
 */

require __DIR__ . '/../vendor/autoload.php';

use Anthropic\Client;
use App\Claude\ClaudeCustomerService;

$apiKey = getenv('ANTHROPIC_API_KEY');
if (!$apiKey) {
    fwrite(STDERR, "需要設定 ANTHROPIC_API_KEY\n");
    exit(2);
}

// 前綴要夠長才會被快取：Opus 5 的最小可快取長度是 512 token。
// 太短的話 API 不會報錯，只是安靜地不快取。
$systemPrompt = str_repeat('你是專業客服人員，必須依據公司政策回答，不得臆測。', 60);
$companyKnowledge = ['政策' => str_repeat('到貨七天內未拆封可全額退貨。', 300)];

$service = new ClaudeCustomerService(
    client: new Client(apiKey: $apiKey),
    systemPrompt: $systemPrompt,
    companyKnowledge: $companyKnowledge,
    model: 'claude-opus-5',
    maxTokens: 256,
    effort: 'low',
);

$customerData = ['會員等級' => '金卡', '訂單' => 'ORD-1'];

echo "第 1 次請求（預期：寫入快取）\n";
$first = $service->ask('CUST-X', '可以退貨嗎？', $customerData);
echo '  ', $first->usageSummary(), "\n";

echo "\n第 2 次請求（前綴相同，只換問題 —— 預期：命中快取）\n";
$second = $service->ask('CUST-X', '運費怎麼算？', $customerData);
echo '  ', $second->usageSummary(), "\n";

echo "\n", str_repeat('─', 60), "\n";

if ($second->cacheReadTokens > 0) {
    printf(
        "\033[32m快取正常運作\033[0m：第 2 次有 %d 個 token 走快取（%.1f%% 的 prompt），\n"
        . "相當於省下 %.0f 個 input token。\n",
        $second->cacheReadTokens,
        $second->cacheHitRatio() * 100,
        $second->savedInputTokensEquivalent(),
    );
    exit(0);
}

fwrite(STDERR, <<<'MSG'
[31m快取沒有命中[0m —— 第 2 次請求的 cache_read_input_tokens 是 0。

請依序檢查：
  1. systemPrompt / companyKnowledge 裡有沒有每次都變的值（日期、時間戳、UUID、customer_id）
  2. 結構化資料有沒有走 Prompt::canonicalJson（鍵序不固定會產生不同 bytes）
  3. tools 列表有沒有固定排序、有沒有因客戶而異
  4. 前綴長度有沒有超過模型的最小可快取長度（Opus 5 是 512 token）
  5. 兩次請求有沒有跑在不同的 workspace（快取不跨 workspace 共用）

MSG);
exit(1);
