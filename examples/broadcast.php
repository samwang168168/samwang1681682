<?php

declare(strict_types=1);

/**
 * 分批廣播 —— 節流、退避、以及最重要的「把三種失敗分開處理」。
 *
 *   php examples/broadcast.php --dry-run                    # 不連外網，看會送出什麼
 *   TELEGRAM_BOT_TOKEN=123:AA... php examples/broadcast.php  # 真的送
 *
 * 廣播最常見的錯誤是把所有失敗一視同仁地重試。實際上：
 *
 *   403 使用者封鎖了 bot  → 重試一百次就是失敗一百次。要從名單移除
 *   429 限流              → 等平台指定的秒數再送同一則，訊息不能丟
 *   5xx 對方暫時有問題    → 退避後重試
 *
 * 混在一起的後果是每次廣播都把配額燒在同一批封鎖名單上，
 * 而真正該重送的那些反而被擠掉。
 */

// vendor 裝了就用它，沒裝也能跑（這支不需要 Anthropic SDK）
if (is_file(__DIR__ . '/../vendor/autoload.php')) {
    require __DIR__ . '/../vendor/autoload.php';
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'App\\Claude\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $file = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    });
}

use App\Claude\Channel\TelegramChannel;
use App\Claude\Flow\Flow;
use App\Claude\Flow\Steps\Map;
use App\Claude\Flow\Steps\SendMessages;
use App\Claude\Flow\Steps\SplitText;
use App\Claude\Http\CurlTransport;
use App\Claude\Http\FakeTransport;
use App\Claude\Http\Response;

$dryRun = in_array('--dry-run', $argv, true);

// ── 推播名單。正式環境從 DB 讀出來。 ──────────────────────────
$recipients = [
    ['conversationId' => '111111111', 'name' => '範例使用者 A'],
    ['conversationId' => '222222222', 'name' => '範例使用者 B'],
    ['conversationId' => '333333333', 'name' => '範例使用者 C'],
];

$text = '【山姆商行】週年慶開跑，全站滿 1000 免運。';

// ── 通道 ────────────────────────────────────────────────────
if ($dryRun) {
    // 第二位使用者模擬「已封鎖」、第三位模擬「限流」，示範三種結果的差異
    $transport = (new FakeTransport())->queue(
        new Response(200, '{"ok":true}'),
        new Response(403, '{"description":"Forbidden: bot was blocked by the user"}'),
        new Response(429, '{"parameters":{"retry_after":3}}'),
        new Response(200, '{"ok":true}'),
    );
    $channel = new TelegramChannel('0:DRY-RUN', $transport);
} else {
    $token = getenv('TELEGRAM_BOT_TOKEN') ?: '';
    if ($token === '') {
        fwrite(STDERR, "請設定 TELEGRAM_BOT_TOKEN，或加上 --dry-run\n");
        exit(1);
    }
    $transport = new CurlTransport();
    $channel = new TelegramChannel($token, $transport);
}

// ── flow ────────────────────────────────────────────────────
$flow = (new Flow('廣播', static fn (string $m) => fwrite(STDERR, $m . "\n")))
    ->step(new Map('套上文案', static fn (array $r): array => array_merge($r, ['text' => $text])))
    ->step(new SplitText($channel->textLimit()))
    ->step(new SendMessages(
        $channel,
        // Telegram 全域上限約 30 則/秒，同一個 chat 約 1 則/秒。
        // 留兩成餘裕，不要卡在上限跑。
        messagesPerSecond: $dryRun ? 0.0 : 20.0,
        maxAttempts: 3,
        sleeper: $dryRun ? static fn (float $s) => print("  （模擬等待 {$s} 秒）\n") : null,
    ));

$results = $flow->run($recipients);

// ── 依結果分三類。這才是重點。 ───────────────────────────────
$sent = [];
$blocked = [];   // ★ 要從推播名單移除，否則每次廣播都白打一次
$retry = [];     // 限流或暫時性失敗，之後另跑一次

foreach ($results as $result) {
    match ($result['outcome']) {
        'Sent'             => $sent[] = $result,
        'PermanentFailure' => $blocked[] = $result,
        default            => $retry[] = $result,
    };
}

echo "\n" . $flow->traceSummary() . "\n\n";
printf("已送出：%d　已封鎖：%d　待重送：%d\n", count($sent), count($blocked), count($retry));

if ($blocked !== []) {
    echo "\n【要從推播名單移除】重試永遠不會成功：\n";
    foreach ($blocked as $item) {
        printf("  %s (%s) — %s\n", $item['conversationId'], $item['name'] ?? '', $item['error']);
    }
}

if ($retry !== []) {
    echo "\n【待重送】限流解除後另跑一次：\n";
    foreach ($retry as $item) {
        printf("  %s (%s) — 試了 %d 次：%s\n", $item['conversationId'], $item['name'] ?? '', $item['attempts'], $item['error']);
    }
}

exit($blocked === [] && $retry === [] ? 0 : 1);
