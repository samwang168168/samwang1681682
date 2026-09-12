<?php

declare(strict_types=1);

/**
 * 驗證送出去的 wire payload 長相是否正確 —— 不需要 API key、不花錢。
 *
 * 跑法：php tools/verify_layout.php
 *
 * 它會對著本機 mock server 送請求，攔下真正的 HTTP body，然後斷言：
 *   1. 系統提示詞 + 公司知識庫在 system，各自帶 cache_control
 *   2. 客戶專屬資料在第一個 user 訊息開頭，帶 cache_control
 *   3. 客人的問題在最後，不帶 cache_control
 *   4. 斷點總數不超過 4 個
 *   5. 決定性序列化：同一筆資料鍵序不同，產生的 bytes 必須一致
 *   6. Gemini 轉接層的角色、型別、參數對應正確
 */

require __DIR__ . '/../vendor/autoload.php';

use Anthropic\Client;
use App\Claude\ClaudeCustomerService;
use App\Claude\GeminiCompat;
use App\Claude\Prompt;

$captureFile = sys_get_temp_dir() . '/claude-capture.json';
$port = 8731;

$serverProcess = proc_open(
    sprintf(
        'CAPTURE_FILE=%s php -S 127.0.0.1:%d %s',
        escapeshellarg($captureFile),
        $port,
        escapeshellarg(__DIR__ . '/mock/server.php'),
    ),
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $pipes,
);

// 等 server 起來
for ($i = 0; $i < 50; $i++) {
    $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
    if ($conn !== false) {
        fclose($conn);
        break;
    }
    usleep(100_000);
}

$failures = 0;
$checks = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures, $checks;
    $checks++;
    if ($ok) {
        echo "  \033[32m✓\033[0m {$label}\n";

        return;
    }
    $failures++;
    echo "  \033[31m✗\033[0m {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function capture(string $file): array
{
    $raw = file_get_contents($file);

    return json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
}

function countBreakpoints(array $payload): int
{
    $count = 0;

    foreach ($payload['system'] ?? [] as $block) {
        if (isset($block['cache_control'])) {
            $count++;
        }
    }

    foreach ($payload['messages'] ?? [] as $message) {
        if (!is_array($message['content'] ?? null)) {
            continue;
        }
        foreach ($message['content'] as $block) {
            if (is_array($block) && isset($block['cache_control'])) {
                $count++;
            }
        }
    }

    return $count;
}

try {
    $client = new Client(apiKey: 'sk-ant-mock', baseUrl: "http://127.0.0.1:{$port}");

    // ---------------------------------------------------------------
    echo "\n[1] 原生三層分層\n";

    $systemPrompt = str_repeat('你是一位專業客服人員，回答必須依據公司政策。', 40);
    $companyKnowledge = ['退貨政策' => str_repeat('七天內未拆封可退。', 200), '運費' => '滿千免運'];

    $service = new ClaudeCustomerService(
        client: $client,
        systemPrompt: $systemPrompt,
        companyKnowledge: $companyKnowledge,
        model: 'claude-opus-5',
        maxTokens: 1024,
        effort: 'medium',
    );

    $reply = $service->ask(
        customerId: 'CUST-001',
        question: '我上週買的東西可以退嗎？',
        customerData: ['會員等級' => '金卡', '最近訂單' => 'ORD-9912'],
    );

    $payload = capture($captureFile);

    check('system 有兩塊（系統提示詞 + 公司知識庫）', count($payload['system'] ?? []) === 2,
        '實際 ' . count($payload['system'] ?? []));
    check('system[0] 帶 cache_control', isset($payload['system'][0]['cache_control']));
    check('system[1] 帶 cache_control', isset($payload['system'][1]['cache_control']));
    check('公司知識庫在 system 層（不在 messages）',
        str_contains($payload['system'][1]['text'] ?? '', '七天內未拆封可退'));

    $firstUser = $payload['messages'][0]['content'] ?? [];
    check('客戶資料在第一個 user 訊息開頭', str_contains($firstUser[0]['text'] ?? '', '金卡'));
    check('客戶資料帶 cache_control', isset($firstUser[0]['cache_control']));
    check('客人問題排在最後', ($firstUser[1]['text'] ?? '') === '我上週買的東西可以退嗎？');
    check('客人問題不帶 cache_control', !isset($firstUser[1]['cache_control']),
        '問題打斷點會讓每次請求都寫一份沒人讀的快取');
    check('斷點數 <= 4', countBreakpoints($payload) <= 4, '實際 ' . countBreakpoints($payload));
    check('effort 送出為 medium', ($payload['output_config']['effort'] ?? null) === 'medium');
    check('maxTokens 轉成 wire 的 max_tokens', ($payload['max_tokens'] ?? null) === 1024);
    check('usage 有被解析出來', $reply->cacheReadTokens === 51200, (string) $reply->cacheReadTokens);

    // ---------------------------------------------------------------
    echo "\n[2] 決定性序列化（鍵序不影響 bytes）\n";

    $a = ['b' => 1, 'a' => ['y' => 2, 'x' => 3], 'list' => [3, 1, 2]];
    $b = ['a' => ['x' => 3, 'y' => 2], 'list' => [3, 1, 2], 'b' => 1];
    check('鍵序不同但輸出相同', Prompt::canonicalJson($a) === Prompt::canonicalJson($b));
    check('list 順序被保留（不應排序）',
        str_contains(Prompt::canonicalJson(['l' => [3, 1, 2]]), "3,\n        1,\n        2")
        || preg_match('/3.*1.*2/s', Prompt::canonicalJson(['l' => [3, 1, 2]])) === 1);
    check('中文不被轉義成 \\uXXXX', str_contains(Prompt::canonicalJson(['k' => '退貨']), '退貨'));

    // ---------------------------------------------------------------
    echo "\n[3] 多輪對話：前綴只往後長\n";

    $history = [
        ['role' => 'user', 'content' => '第一個問題'],
        ['role' => 'assistant', 'content' => '第一個回答'],
    ];

    $service->ask(
        customerId: 'CUST-001',
        question: '追問',
        customerData: ['會員等級' => '金卡'],
        history: $history,
    );

    $payload = capture($captureFile);
    check('客戶資料接在 messages[0] 最前面',
        str_contains($payload['messages'][0]['content'][0]['text'] ?? '', '金卡'));
    check('歷史第一輪的原始內容被保留',
        str_contains($payload['messages'][0]['content'][1]['text'] ?? '', '第一個問題'));
    check('最後一則是新問題', str_contains(
        $payload['messages'][count($payload['messages']) - 1]['content'][0]['text'] ?? '', '追問'));
    check('斷點數 <= 4', countBreakpoints($payload) <= 4, '實際 ' . countBreakpoints($payload));

    // ---------------------------------------------------------------
    echo "\n[4] 單輪營運指令走 system role 通道\n";

    $service->ask(
        customerId: 'CUST-002',
        question: '運費多少？',
        customerData: ['會員等級' => '普卡'],
        turnInstruction: '用 40 字以內回答。',
    );

    $payload = capture($captureFile);
    $last = $payload['messages'][count($payload['messages']) - 1];
    check('指令以 system role 附在 messages 最後', ($last['role'] ?? '') === 'system');
    check('頂層 system 沒有被改動（快取前綴完好）',
        str_contains($payload['system'][0]['text'] ?? '', '你是一位專業客服人員'));

    // ---------------------------------------------------------------
    echo "\n[5] Gemini 相容轉接層\n";

    $geminiRequest = [
        'systemInstruction' => ['parts' => [['text' => $systemPrompt]]],
        'contents' => [
            ['role' => 'user', 'parts' => [
                ['text' => str_repeat('公司知識庫內容。', 300)],
                ['text' => '客戶 A 的訂單資料'],
                ['text' => '請問可以退貨嗎？'],
            ]],
            ['role' => 'model', 'parts' => [['text' => '可以。']]],
            ['role' => 'user', 'parts' => [['text' => '那運費誰付？']]],
        ],
        'generationConfig' => [
            'maxOutputTokens' => 2048,
            'temperature' => 0.7,
            'topP' => 0.9,
            'stopSequences' => ['###'],
        ],
        'safetySettings' => [['category' => 'HARM_CATEGORY_HARASSMENT', 'threshold' => 'BLOCK_NONE']],
        'cachedContent' => 'projects/x/cachedContents/123',
        'tools' => [['functionDeclarations' => [
            ['name' => 'zebra_tool', 'description' => 'z', 'parameters' => [
                'type' => 'OBJECT',
                'properties' => ['q' => ['type' => 'STRING']],
                'required' => ['q'],
            ]],
            ['name' => 'alpha_tool', 'description' => 'a', 'parameters' => ['type' => 'OBJECT', 'properties' => []]],
        ]]],
    ];

    $translation = GeminiCompat::translate($geminiRequest, [
        'model' => 'claude-opus-5',
        'promoteToSystem' => [0],     // 公司知識庫 -> system 層（跨客戶快取）
        'customerDataParts' => [1],   // 客戶資料 -> 自己的斷點
        'effort' => 'medium',
    ]);

    $p = $translation->params;

    check('systemInstruction -> system', str_contains($p['system'][0]['text'] ?? '', '你是一位專業客服人員'));
    check('公司知識庫被提到 system 層', str_contains($p['system'][1]['text'] ?? '', '公司知識庫內容'));
    check('斷點在 system 最後一塊', isset($p['system'][1]['cacheControl'])
        && !isset($p['system'][0]['cacheControl']));
    check('role model -> assistant', ($p['messages'][1]['role'] ?? '') === 'assistant');
    check('客戶資料 part 拿到斷點', isset($p['messages'][0]['content'][0]['cacheControl'])
        && str_contains($p['messages'][0]['content'][0]['text'] ?? '', '客戶 A'));
    check('問題 part 沒有斷點', !isset($p['messages'][0]['content'][1]['cacheControl']));
    check('maxOutputTokens -> maxTokens', ($p['maxTokens'] ?? null) === 2048);
    check('temperature 被丟棄（Opus 5 會 400）', !array_key_exists('temperature', $p));
    check('topP 被丟棄', !array_key_exists('topP', $p));
    check('stopSequences 保留', ($p['stopSequences'] ?? null) === ['###']);
    check('schema 型別轉小寫', ($p['tools'][0]['inputSchema']['type'] ?? null) === 'object');
    check('巢狀 schema 型別也轉小寫',
        ($p['tools'][1]['inputSchema']['properties']['q']['type'] ?? null) === 'string');
    check('tools 照名稱排序（順序變動會毀掉整個快取）',
        array_column($p['tools'], 'name') === ['alpha_tool', 'zebra_tool'],
        implode(',', array_column($p['tools'], 'name')));

    $warningText = implode(' | ', $translation->warnings);
    check('temperature 有進 warnings', str_contains($warningText, 'temperature'));
    check('safetySettings 有進 warnings', str_contains($warningText, 'safetySettings'));
    check('cachedContent 有進 warnings', str_contains($warningText, 'cachedContent'));

    // 轉出來的參數要真的能被 SDK 接受
    $reply = GeminiCompat::send($client, $geminiRequest, [
        'promoteToSystem' => [0],
        'customerDataParts' => [1],
        'effort' => 'medium',
    ]);
    $payload = capture($captureFile);
    check('轉接層的參數能被 SDK 實際送出', ($payload['model'] ?? null) === 'claude-opus-5');
    check('wire 上 cache_control 是 snake_case', isset($payload['system'][1]['cache_control']));
    check('wire 上 input_schema 是 snake_case', isset($payload['tools'][0]['input_schema']));
    check('斷點數 <= 4', countBreakpoints($payload) <= 4, '實際 ' . countBreakpoints($payload));

    // ---------------------------------------------------------------
    echo "\n[6] 前綴變動偵測\n";

    $changed = [];
    $guarded = new ClaudeCustomerService(
        client: $client,
        // ★ 反面示範：系統提示詞裡插了動態日期
        systemPrompt: $systemPrompt,
        companyKnowledge: $companyKnowledge,
        onPrefixChanged: function (string $old, string $new, string $msg) use (&$changed): void {
            $changed[] = [$old, $new];
        },
    );

    $guarded->ask(customerId: 'C1', question: 'q1', customerData: ['a' => 1]);
    check('前綴穩定時不觸發警告', $changed === []);

    $stats = $guarded->cacheStats();
    check('cacheStats 有累計', $stats['requests'] === 1 && $stats['cacheReadTokens'] === 51200);
} finally {
    if (isset($serverProcess) && is_resource($serverProcess)) {
        proc_terminate($serverProcess);
        proc_close($serverProcess);
    }
}

echo "\n" . str_repeat('─', 60) . "\n";
if ($failures === 0) {
    echo "\033[32m全部通過：{$checks} 項檢查\033[0m\n";
    exit(0);
}

echo "\033[31m{$failures} / {$checks} 項失敗\033[0m\n";
exit(1);
