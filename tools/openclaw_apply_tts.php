<?php

declare(strict_types=1);

/**
 * 把「main agent 回答時附上 mp3 語音」的設定，深層合併進 OpenClaw 的設定檔。
 *
 * 跑法：
 *   php tools/openclaw_apply_tts.php --dry-run          # 只印合併後的結果，不寫檔
 *   php tools/openclaw_apply_tts.php                    # 寫入，寫之前自動備份
 *   php tools/openclaw_apply_tts.php --provider=openai  # 改用 OpenAI TTS
 *   php tools/openclaw_apply_tts.php --agent=support --config=/path/openclaw.json
 *
 * 為什麼不直接覆蓋檔案：openclaw.json 裡面還有 channels、models、bindings，
 * 整份蓋掉等於把整台 gateway 的設定清空。這支只動 tts 與 agents.entries.<agent>.tts
 * 兩條路徑，其餘原封不動。
 */

$options = getopt('', ['config::', 'agent::', 'provider::', 'dry-run', 'help']);

if (isset($options['help'])) {
    fwrite(STDOUT, "用法：php tools/openclaw_apply_tts.php [--config=路徑] [--agent=main] [--provider=microsoft|openai] [--dry-run]\n");
    exit(0);
}

$agent = (string) ($options['agent'] ?? 'main');
$provider = (string) ($options['provider'] ?? 'microsoft');
$dryRun = array_key_exists('dry-run', $options);

if (!in_array($provider, ['microsoft', 'openai'], true)) {
    fwrite(STDERR, "--provider 只支援 microsoft 或 openai（其他 provider 請照 openclaw/README.md 手動併）\n");
    exit(2);
}

$configPath = (string) ($options['config'] ?? defaultConfigPath());

/**
 * 只有 microsoft 的 outputFormat 與 openai 的 responseFormat 能把輸出釘在 mp3。
 * 兩者都不設的話，OpenClaw 會依頻道能力自己選（語音訊息頻道會選 Opus）。
 */
$voiceBlock = $provider === 'microsoft'
    ? [
        'enabled' => true,
        'speakerVoice' => 'zh-CN-XiaoxiaoNeural',
        'lang' => 'zh-CN',
        'outputFormat' => 'audio-24khz-48kbitrate-mono-mp3',
        'rate' => '+0%',
        'pitch' => '+0%',
    ]
    : [
        'apiKey' => '${OPENAI_API_KEY}',
        'model' => 'gpt-4o-mini-tts',
        'speakerVoice' => 'coral',
        'responseFormat' => 'mp3',
    ];

// 共用層：provider 與輸出格式。這層刻意不寫 auto，免得把其他 agent 一起打開。
$patch = [
    'tts' => [
        'mode' => 'final',
        'provider' => $provider,
        'maxTextLength' => 4096,
        'providers' => [$provider => $voiceBlock],
    ],
    // 指定 agent 那層：只有它自動發語音。deep-merge 時後寫的這層會蓋過共用層。
    'agents' => [
        'entries' => [
            $agent => [
                'tts' => [
                    'auto' => 'always',
                    'mode' => 'final',
                    'provider' => $provider,
                    'providers' => [$provider => $voiceBlock],
                ],
            ],
        ],
    ],
];

$existing = readConfig($configPath);
$merged = deepMerge($existing, $patch);

$json = json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ($json === false) {
    fwrite(STDERR, "合併後的設定無法序列化成 JSON：" . json_last_error_msg() . "\n");
    exit(1);
}
$json .= "\n";

if ($dryRun) {
    fwrite(STDOUT, $json);
    fwrite(STDERR, "\n（--dry-run：沒有寫入 {$configPath}）\n");
    exit(0);
}

$dir = dirname($configPath);
if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
    fwrite(STDERR, "建不出目錄：{$dir}\n");
    exit(1);
}

if (is_file($configPath)) {
    $backup = $configPath . '.bak-' . date('Ymd-His');
    if (!copy($configPath, $backup)) {
        fwrite(STDERR, "備份失敗，為了安全不寫入：{$configPath}\n");
        exit(1);
    }
    fwrite(STDOUT, "已備份原設定：{$backup}\n");
}

if (file_put_contents($configPath, $json) === false) {
    fwrite(STDERR, "寫入失敗：{$configPath}\n");
    exit(1);
}

fwrite(STDOUT, <<<TXT
已寫入：{$configPath}
  tts.provider = {$provider}
  agents.entries.{$agent}.tts.auto = always（每則最終回覆都附語音，格式 mp3）

接著做：
  1. 重新載入 gateway：openclaw gateway restart（或 openclaw doctor 檢查設定）
  2. 在聊天室輸入 /tts status 確認 provider 與 auto 狀態
  3. /tts audio 測試一下語音 —— 應該收到 mp3

TXT);

/**
 * 讀 OpenClaw 設定檔。檔案不存在就從空設定開始；解析不了就停手，
 * 因為猜錯格式再覆蓋回去 = 弄壞人家的 gateway。
 *
 * @return array<string, mixed>
 */
function readConfig(string $path): array
{
    if (!is_file($path)) {
        fwrite(STDOUT, "找不到 {$path}，會建立一份新的設定檔。\n");

        return [];
    }

    $raw = file_get_contents($path);
    if ($raw === false) {
        fwrite(STDERR, "讀不到 {$path}\n");
        exit(1);
    }

    if (trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        fwrite(STDERR, <<<TXT
{$path} 不是這支腳本能安全處理的 JSON（可能含有 JSON5 註解或尾逗號）。
沒有動它。請改用 openclaw/tts-main.json5 手動把兩個區塊併進去，
或先跑 `openclaw doctor --fix` 把設定正規化後再試一次。

TXT);
        exit(1);
    }

    return $decoded;
}

/**
 * 深層合併：關聯陣列逐層併，純列表（例如 providers 的 args）整個換掉。
 * 列表用「換掉」而不是「接起來」是刻意的 —— 接起來會產生重複的參數。
 *
 * @param array<string, mixed> $base
 * @param array<string, mixed> $patch
 * @return array<string, mixed>
 */
function deepMerge(array $base, array $patch): array
{
    foreach ($patch as $key => $value) {
        if (is_array($value) && isset($base[$key]) && is_array($base[$key]) && !isList($value) && !isList($base[$key])) {
            $base[$key] = deepMerge($base[$key], $value);
            continue;
        }

        $base[$key] = $value;
    }

    return $base;
}

/**
 * @param array<mixed> $value
 */
function isList(array $value): bool
{
    return $value === [] || array_keys($value) === range(0, count($value) - 1);
}

function defaultConfigPath(): string
{
    $home = getenv('HOME') ?: getenv('USERPROFILE');
    if (!$home) {
        fwrite(STDERR, "抓不到家目錄，請用 --config= 指定 openclaw.json 的路徑\n");
        exit(2);
    }

    return rtrim($home, '/\\') . '/.openclaw/openclaw.json';
}
