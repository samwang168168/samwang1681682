<?php

declare(strict_types=1);

/**
 * 通道層與 flow 引擎的自我測試。
 *
 *   php tools/test_channels.php
 *
 * 不花錢、不連外網、不需要 composer —— 全部走 FakeTransport，
 * 並且斷言「真正送出去的 wire payload」而不只是回傳值。
 * 通訊軟體整合最常見的錯是「送出去的 body 少一個欄位」，
 * 那種錯只有看 wire payload 才抓得到。
 */

// ── 極簡 PSR-4 autoloader（避開 composer 依賴） ──────────────
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\Claude\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = __DIR__ . '/../src/' . $relative . '.php';
    if (is_file($file)) {
        require $file;
    }
});

use App\Claude\Channel\ChannelException;
use App\Claude\Channel\MessengerChannel;
use App\Claude\Channel\SendOutcome;
use App\Claude\Channel\TelegramChannel;
use App\Claude\Channel\WebhookHandler;
use App\Claude\Channel\WebhookRequest;
use App\Claude\Flow\Flow;
use App\Claude\Flow\StepFailed;
use App\Claude\Flow\Steps\Call;
use App\Claude\Flow\Steps\Dedupe;
use App\Claude\Flow\Steps\Filter;
use App\Claude\Flow\Steps\Map;
use App\Claude\Flow\Steps\SendMessages;
use App\Claude\Flow\Steps\SplitText;
use App\Claude\Http\FakeTransport;
use App\Claude\Http\Response;
use App\Claude\Store\FileStore;
use App\Claude\Store\MemoryStore;

// ── 迷你測試框架 ────────────────────────────────────────────
$passed = 0;
$failed = 0;
$currentGroup = '';

function group(string $name): void
{
    global $currentGroup;
    $currentGroup = $name;
    echo "\n\033[1m{$name}\033[0m\n";
}

function test(string $name, callable $fn): void
{
    global $passed, $failed;
    try {
        $fn();
        $passed++;
        echo "  ✅ {$name}\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ❌ {$name}\n     {$e->getMessage()}\n";
    }
}

function assertThat(bool $condition, string $message = '斷言失敗'): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertSame(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            ($message !== '' ? $message . ' — ' : '')
            . '預期 ' . var_export($expected, true) . '，實得 ' . var_export($actual, true)
        );
    }
}

function assertThrows(string $expectedClass, callable $fn, string $message = ''): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        if ($e instanceof $expectedClass) {
            return;
        }
        throw new RuntimeException("預期丟 {$expectedClass}，實得 " . $e::class . '：' . $e->getMessage());
    }
    throw new RuntimeException($message !== '' ? $message : "預期丟 {$expectedClass}，但什麼都沒丟");
}

/** 一個永遠成功的 transport，不需要真的連線。 */
function okTransport(array $body = ['ok' => true]): FakeTransport
{
    return new FakeTransport(new Response(200, (string) json_encode($body)));
}

// ════════════════════════════════════════════════════════════
group('Flow 引擎');

test('step 依序執行，items 數量跟著變', function (): void {
    $flow = (new Flow('t'))
        ->step(new Map('加一', static fn (array $i): array => ['n' => $i['n'] + 1]))
        ->step(new Filter('留偶數', static fn (array $i): bool => $i['n'] % 2 === 0));

    $out = $flow->run([['n' => 1], ['n' => 2], ['n' => 3]]);

    assertSame([['n' => 2], ['n' => 4]], $out);
    assertSame(2, count($flow->trace()));
    assertSame(3, $flow->trace()[1]['in']);
    assertSame(2, $flow->trace()[1]['out']);
});

test('失敗會重試，成功後繼續', function (): void {
    $attempts = 0;
    $flow = (new Flow('t'))->step(
        new Call('不穩定的一步', static function (array $items) use (&$attempts): array {
            $attempts++;
            if ($attempts < 3) {
                throw new RuntimeException('暫時失敗');
            }

            return $items;
        }),
        retries: 3,
        delayMs: 1,
    );

    $out = $flow->run([['n' => 1]]);

    assertSame(3, $attempts, '應該試三次');
    assertSame(1, count($out));
    assertSame(3, $flow->trace()[0]['attempts']);
});

test('重試用盡會丟 StepFailed', function (): void {
    $flow = (new Flow('t'))->step(
        new Call('壞掉的一步', static fn (): array => throw new RuntimeException('壞了')),
        retries: 1,
        delayMs: 1,
    );

    assertThrows(StepFailed::class, static fn () => $flow->run([['n' => 1]]));
});

test('continueOnError 讓原本的 items 繼續往下走', function (): void {
    $reached = false;
    $flow = (new Flow('t'))
        ->step(
            new Call('會壞', static fn (): array => throw new RuntimeException('壞了')),
            continueOnError: true,
        )
        ->step(new Call('下一步', static function (array $items) use (&$reached): array {
            $reached = true;

            return $items;
        }));

    $out = $flow->run([['n' => 1]]);

    assertThat($reached, '後面的 step 應該還是要跑到');
    assertSame([['n' => 1]], $out, 'items 應該維持原樣');
    assertThat($flow->trace()[0]['error'] !== null, 'trace 要記下錯誤');
});

test('tap 是旁支：不改資料、失敗不中斷', function (): void {
    $sideEffect = 0;
    $flow = (new Flow('t'))
        ->tap(new Call('副作用', static function (array $items) use (&$sideEffect): array {
            $sideEffect++;

            return []; // 回空的也不該影響主線
        }))
        ->tap(new Call('會爆的副作用', static fn (): array => throw new RuntimeException('爆了')))
        ->step(new Call('主線', static fn (array $items): array => $items));

    $out = $flow->run([['n' => 1], ['n' => 2]]);

    assertSame(1, $sideEffect);
    assertSame(2, count($out), 'tap 不該改變主線的 items');
});

test('沒有 item 時不呼叫 step（省一次 API 錢）', function (): void {
    $called = false;
    $flow = (new Flow('t'))->step(new Call('不該被呼叫', static function (array $i) use (&$called): array {
        $called = true;

        return $i;
    }));

    assertSame([], $flow->run([]));
    assertThat(!$called, 'items 是空的時候不該呼叫 step');
});

test('traceSummary 可讀', function (): void {
    $flow = (new Flow('客服'))->step(new Filter('過濾', static fn (): bool => true));
    $flow->run([['n' => 1]]);

    assertThat(str_contains($flow->traceSummary(), '[客服]'));
    assertThat(str_contains($flow->traceSummary(), '過濾 1→1'));
});

// ════════════════════════════════════════════════════════════
group('Steps');

test('Dedupe 擋掉重複的 messageId', function (): void {
    $store = new MemoryStore();
    $step = new Dedupe($store, static fn (array $i): string => (string) $i['messageId']);

    $first = $step([['messageId' => 'a'], ['messageId' => 'b'], ['messageId' => 'a']]);
    assertSame(2, count($first), '同一批裡的重複也要擋掉');

    $second = $step([['messageId' => 'a'], ['messageId' => 'c']]);
    assertSame(1, count($second), '跨批次仍記得看過 a');
    assertSame('c', $second[0]['messageId']);
});

test('Dedupe 遇到沒有 key 的 item 直接放行', function (): void {
    $step = new Dedupe(new MemoryStore(), static fn (array $i): ?string => $i['messageId'] ?? null);
    assertSame(2, count($step([['x' => 1], ['x' => 2]])));
});

test('SplitText：短訊息原樣、空字串消失', function (): void {
    assertSame(['嗨'], SplitText::split('嗨', 100));
    assertSame([], SplitText::split('   ', 100));
});

test('SplitText：優先切在段落邊界', function (): void {
    $text = str_repeat('甲', 60) . "\n\n" . str_repeat('乙', 60);
    $chunks = SplitText::split($text, 100);

    assertSame(2, count($chunks));
    assertSame(str_repeat('甲', 60), $chunks[0], '應該切在段落邊界，而不是第 100 字');
    assertSame(str_repeat('乙', 60), $chunks[1]);
});

test('SplitText：沒有切點就硬切，且每段都在上限內', function (): void {
    $chunks = SplitText::split(str_repeat('a', 250), 100);

    assertSame(3, count($chunks));
    foreach ($chunks as $chunk) {
        assertThat(mb_strlen($chunk) <= 100, '每段都不能超過上限');
    }
});

test('SplitText：中文與 emoji 不會被切成半個', function (): void {
    $text = str_repeat('😀', 250);
    $chunks = SplitText::split($text, 100);

    assertSame($text, implode('', $chunks), '內容不能遺失或損壞');
    foreach ($chunks as $chunk) {
        assertThat(mb_strlen($chunk) <= 100);
        assertThat(!str_contains($chunk, "\u{FFFD}"), '不該出現替代字元');
    }
});

test('SplitText 步驟會展開成多筆並標上 part', function (): void {
    $step = new SplitText(100);
    $out = $step([['conversationId' => '1', 'text' => str_repeat('a', 250)]]);

    assertSame(3, count($out));
    assertSame([1, 2, 3], array_column($out, 'part'));
    assertSame(3, $out[0]['partCount']);
    assertSame('1', $out[2]['conversationId'], '其他欄位要保留');
    assertSame(str_repeat('a', 250), implode('', array_column($out, 'text')), '合回去要等於原文');
    // ★ 原本的 text 必須被切出來的那一段蓋掉。用 $item + [...] 寫會保留舊值（左值優先），
    //   那是這裡刻意用 array_merge 的原因。
    assertThat(mb_strlen($out[0]['text']) === 100, '第一段應該是切出來的內容，不是原本的整串');
});

// ════════════════════════════════════════════════════════════
group('Store');

test('MemoryStore：add 第一次 true，第二次 false', function (): void {
    $store = new MemoryStore();
    assertThat($store->add('k'));
    assertThat(!$store->add('k'));
});

test('MemoryStore：TTL 到期後可以再 add', function (): void {
    $store = new MemoryStore();
    $store->set('k', true, 0);
    assertThat($store->add('k'), 'TTL 為 0 應立即過期');
});

test('FileStore：跨實例（模擬跨 process）仍然擋得住', function (): void {
    $path = sys_get_temp_dir() . '/cs-store-' . bin2hex(random_bytes(6)) . '.json';

    try {
        assertThat((new FileStore($path))->add('mid-1'), '第一個 process 應該搶到');
        assertThat(!(new FileStore($path))->add('mid-1'), '第二個 process 應該被擋下');
        assertThat((new FileStore($path))->add('mid-2'), '不同 key 不受影響');
    } finally {
        @unlink($path);
    }
});

test('FileStore：存取任意值並可移除', function (): void {
    $path = sys_get_temp_dir() . '/cs-store-' . bin2hex(random_bytes(6)) . '.json';

    try {
        $store = new FileStore($path);
        $store->set('last', ['at' => 123]);
        assertSame(['at' => 123], (new FileStore($path))->get('last'));

        $store->forget('last');
        assertSame(null, (new FileStore($path))->get('last'));
    } finally {
        @unlink($path);
    }
});

// ════════════════════════════════════════════════════════════
group('TelegramChannel');

$telegramUpdate = static fn (array $message): string => (string) json_encode([
    'update_id' => 777,
    'message' => $message,
]);

test('parse 用 chat.id 而不是 from.id', function () use ($telegramUpdate): void {
    $channel = new TelegramChannel('123:ABC', okTransport());
    // 群組：chat.id 是負數的群組 ID，from.id 是發話者 —— 用錯會把回覆私訊給個人
    $request = new WebhookRequest('POST', $telegramUpdate([
        'message_id' => 1,
        'date' => 1718000000,
        'chat' => ['id' => -1001234567890, 'type' => 'supergroup'],
        'from' => ['id' => 987654321, 'username' => 'ming'],
        'text' => '可以退貨嗎？',
    ]));

    $messages = $channel->parse($request);

    assertSame(1, count($messages));
    assertSame('-1001234567890', $messages[0]->conversationId, '必須是 chat.id');
    assertSame('987654321', $messages[0]->userId);
    assertSame('777', $messages[0]->messageId, '去重要用 update_id');
    assertSame('可以退貨嗎？', $messages[0]->text);
});

test('parse 濾掉沒有文字的 update', function () use ($telegramUpdate): void {
    $channel = new TelegramChannel('123:ABC', okTransport());

    // 貼圖
    assertSame([], $channel->parse(new WebhookRequest('POST', $telegramUpdate([
        'chat' => ['id' => 1], 'sticker' => ['file_id' => 'x'],
    ]))));

    // 空字串
    assertSame([], $channel->parse(new WebhookRequest('POST', $telegramUpdate([
        'chat' => ['id' => 1], 'text' => '   ',
    ]))));

    // callback_query（不是 message）
    assertSame([], $channel->parse(new WebhookRequest('POST', (string) json_encode([
        'update_id' => 1, 'callback_query' => ['id' => 'q1', 'data' => 'yes'],
    ]))));

    // 完全壞掉的 body
    assertSame([], $channel->parse(new WebhookRequest('POST', 'not json')));
});

test('verify 比對 secret token', function (): void {
    $channel = new TelegramChannel('123:ABC', okTransport(), 'my-secret');

    $channel->verify(new WebhookRequest('POST', '{}', ['x-telegram-bot-api-secret-token' => 'my-secret']));

    assertThrows(ChannelException::class, static fn () => $channel->verify(
        new WebhookRequest('POST', '{}', ['x-telegram-bot-api-secret-token' => 'wrong'])
    ));
    assertThrows(ChannelException::class, static fn () => $channel->verify(new WebhookRequest('POST', '{}')));

    // 沒設 secret 就不驗
    (new TelegramChannel('123:ABC', okTransport()))->verify(new WebhookRequest('POST', '{}'));
});

test('send 的 wire payload 正確', function (): void {
    $transport = okTransport();
    $channel = new TelegramChannel('123:ABC', $transport);

    $result = $channel->send('555', '您好');

    assertThat($result->isSent());
    assertSame('POST', $transport->lastRequest()['method']);
    assertSame('https://api.telegram.org/bot123:ABC/sendMessage', $transport->lastRequest()['url']);
    assertSame(['chat_id' => '555', 'text' => '您好'], $transport->lastBody());
    // 不設 parse_mode 是刻意的：LLM 的輸出最容易在 MarkdownV2 上 400
    assertThat(!array_key_exists('parse_mode', $transport->lastBody()));
});

test('typing 走 sendChatAction', function (): void {
    $transport = okTransport();
    (new TelegramChannel('123:ABC', $transport))->typing('555');

    assertThat(str_ends_with($transport->lastRequest()['url'], '/sendChatAction'));
    assertSame('typing', $transport->lastBody()['action']);
});

test('429 → RateLimited，並讀出 retry_after', function (): void {
    $transport = new FakeTransport();
    $transport->queue(new Response(429, (string) json_encode([
        'ok' => false, 'error_code' => 429,
        'description' => 'Too Many Requests: retry after 12',
        'parameters' => ['retry_after' => 12],
    ])));

    $result = (new TelegramChannel('123:ABC', $transport))->send('555', 'x');

    assertSame(SendOutcome::RateLimited, $result->outcome);
    assertSame(12, $result->retryAfterSeconds, '要照 Telegram 給的秒數，不是自己決定');
    assertThat($result->shouldRetry());
});

test('403（被封鎖）→ PermanentFailure，不該重試', function (): void {
    $transport = new FakeTransport();
    $transport->queue(new Response(403, (string) json_encode([
        'ok' => false, 'error_code' => 403,
        'description' => 'Forbidden: bot was blocked by the user',
    ])));

    $result = (new TelegramChannel('123:ABC', $transport))->send('555', 'x');

    assertSame(SendOutcome::PermanentFailure, $result->outcome);
    assertThat(!$result->shouldRetry(), '封鎖是終局錯誤，重試一百次也是失敗一百次');
});

test('5xx 與連線失敗 → TemporaryFailure', function (): void {
    $t1 = (new FakeTransport())->queue(new Response(500, 'oops'));
    assertSame(SendOutcome::TemporaryFailure, (new TelegramChannel('123:ABC', $t1))->send('5', 'x')->outcome);

    $t2 = (new FakeTransport())->queue(new Response(0, '', [], 'connection timed out'));
    assertSame(SendOutcome::TemporaryFailure, (new TelegramChannel('123:ABC', $t2))->send('5', 'x')->outcome);
});

test('空 token 直接拒絕建構', function (): void {
    assertThrows(ChannelException::class, static fn () => new TelegramChannel('', okTransport()));
});

// ════════════════════════════════════════════════════════════
group('MessengerChannel');

$APP_SECRET = 'app-secret';
$sign = static fn (string $body): string => 'sha256=' . hash_hmac('sha256', $body, 'app-secret');
$messenger = static fn (FakeTransport $t): MessengerChannel
    => new MessengerChannel('PAGE-TOKEN', 'app-secret', 'verify-me', $t);

test('challenge：正確的 verify_token 回傳原始 hub.challenge（純文字）', function () use ($messenger): void {
    $response = $messenger(okTransport())->challenge(new WebhookRequest('GET', '', [], [
        'hub.mode' => 'subscribe',
        'hub.verify_token' => 'verify-me',
        'hub.challenge' => '1158201444',
    ]));

    assertThat($response !== null);
    assertSame(200, $response->status);
    // ★ 必須原樣。回 {"challenge":"..."} 或加引號都會驗證失敗
    assertSame('1158201444', $response->body);
    assertThat(str_starts_with($response->contentType, 'text/plain'));
});

test('challenge：verify_token 不符回 403', function () use ($messenger): void {
    $response = $messenger(okTransport())->challenge(new WebhookRequest('GET', '', [], [
        'hub.mode' => 'subscribe', 'hub.verify_token' => 'wrong', 'hub.challenge' => 'x',
    ]));

    assertSame(403, $response?->status);
});

test('challenge：一般的 POST 不是握手，回 null', function () use ($messenger): void {
    assertSame(null, $messenger(okTransport())->challenge(new WebhookRequest('POST', '{}')));
});

test('verify：正確簽章過、竄改與缺漏擋下', function () use ($messenger, $sign): void {
    $body = (string) json_encode(['object' => 'page', 'entry' => []]);
    $channel = $messenger(okTransport());

    $channel->verify(new WebhookRequest('POST', $body, ['x-hub-signature-256' => $sign($body)]));

    // body 被改了一個位元組
    assertThrows(ChannelException::class, static fn () => $channel->verify(
        new WebhookRequest('POST', $body . ' ', ['x-hub-signature-256' => $sign($body)])
    ));
    // 沒有 header
    assertThrows(ChannelException::class, static fn () => $channel->verify(new WebhookRequest('POST', $body)));
    // 格式不對
    assertThrows(ChannelException::class, static fn () => $channel->verify(
        new WebhookRequest('POST', $body, ['x-hub-signature-256' => 'sha1=abc'])
    ));
});

test('verify：中文內容也要過（證明是對原始位元組算的）', function () use ($messenger, $sign): void {
    // 這一條正是「不能先 decode 再 encode 回去」的理由：
    // 重新序列化後的 bytes 跟原文不同，簽章就對不上。
    $body = (string) json_encode(['object' => 'page', 'text' => '可以退貨嗎？'], JSON_UNESCAPED_UNICODE);
    $messenger(okTransport())->verify(new WebhookRequest('POST', $body, ['x-hub-signature-256' => $sign($body)]));

    $escaped = (string) json_encode(['object' => 'page', 'text' => '可以退貨嗎？']); // \uXXXX 形式
    assertThat($escaped !== $body, '兩種序列化的 bytes 確實不同');
    assertThrows(ChannelException::class, static fn () => $messenger(okTransport())->verify(
        new WebhookRequest('POST', $escaped, ['x-hub-signature-256' => $sign($body)])
    ));
});

test('parse：攤平多個 entry 與多則訊息', function () use ($messenger): void {
    $body = (string) json_encode([
        'object' => 'page',
        'entry' => [
            ['id' => 'P1', 'messaging' => [
                ['sender' => ['id' => 'A'], 'timestamp' => 1718000000000, 'message' => ['mid' => 'm1', 'text' => '第一則']],
                ['sender' => ['id' => 'B'], 'timestamp' => 1718000000000, 'message' => ['mid' => 'm2', 'text' => '第二則']],
            ]],
            ['id' => 'P1', 'messaging' => [
                ['sender' => ['id' => 'C'], 'timestamp' => 1718000000000, 'message' => ['mid' => 'm3', 'text' => '第三則']],
            ]],
        ],
    ]);

    $messages = $messenger(okTransport())->parse(new WebhookRequest('POST', $body));

    assertSame(3, count($messages), '只讀 entry[0].messaging[0] 會漏訊息');
    assertSame(['A', 'B', 'C'], array_map(static fn ($m) => $m->conversationId, $messages));
    assertSame('m1', $messages[0]->messageId);
    assertSame(1718000000, $messages[0]->timestamp, 'Meta 的 timestamp 是毫秒，要換算成秒');
});

test('parse：濾掉 is_echo、已讀/送達回報、無文字', function () use ($messenger): void {
    $body = (string) json_encode([
        'object' => 'page',
        'entry' => [['id' => 'P1', 'messaging' => [
            // ★ 自己送出去的回音。不濾掉 bot 會跟自己無限對話
            ['sender' => ['id' => 'P1'], 'message' => ['mid' => 'e1', 'text' => '回音', 'is_echo' => true]],
            ['sender' => ['id' => 'A'], 'read' => ['watermark' => 1]],
            ['sender' => ['id' => 'B'], 'delivery' => ['watermark' => 1]],
            ['sender' => ['id' => 'C'], 'message' => ['mid' => 'x', 'text' => '  ']],
            ['sender' => ['id' => 'D'], 'postback' => ['payload' => 'YES']],
            ['sender' => ['id' => 'E'], 'message' => ['mid' => 'ok', 'text' => '真的訊息']],
        ]]],
    ]);

    $messages = $messenger(okTransport())->parse(new WebhookRequest('POST', $body));

    assertSame(1, count($messages));
    assertSame('E', $messages[0]->conversationId);
});

test('parse：Instagram 的 payload 不處理', function () use ($messenger): void {
    $body = (string) json_encode(['object' => 'instagram', 'entry' => [
        ['id' => 'IG', 'messaging' => [['sender' => ['id' => 'A'], 'message' => ['mid' => 'm', 'text' => 'hi']]]],
    ]]);

    assertSame([], $messenger(okTransport())->parse(new WebhookRequest('POST', $body)));
});

test('send 的 wire payload 正確（含必填的 messaging_type）', function () use ($messenger): void {
    $transport = okTransport(['message_id' => 'mid.1']);
    $messenger($transport)->send('PSID-1', '您好');

    $url = $transport->lastRequest()['url'];
    assertThat(str_contains($url, '/v23.0/me/messages'), 'Graph API 版本要寫死在 URL 裡');
    assertThat(str_contains($url, 'access_token=PAGE-TOKEN'));

    assertSame([
        'recipient' => ['id' => 'PSID-1'],
        'messaging_type' => 'RESPONSE',
        'message' => ['text' => '您好'],
    ], $transport->lastBody());
});

test('typing 用 sender_action，且與 message 互斥', function () use ($messenger): void {
    $transport = okTransport();
    $messenger($transport)->typing('PSID-1');

    assertSame('typing_on', $transport->lastBody()['sender_action']);
    assertThat(!array_key_exists('message', $transport->lastBody()), 'sender_action 不能跟 message 同時送');
});

test('sendTagged 走 MESSAGE_TAG（超過 24 小時視窗的合法出口）', function () use ($messenger): void {
    $transport = okTransport();
    $messenger($transport)->sendTagged('PSID-1', '您的訂單已出貨', 'POST_PURCHASE_UPDATE');

    assertSame('MESSAGE_TAG', $transport->lastBody()['messaging_type']);
    assertSame('POST_PURCHASE_UPDATE', $transport->lastBody()['tag']);
});

test('錯誤碼 10（超過 24 小時視窗）→ PermanentFailure', function () use ($messenger): void {
    $transport = (new FakeTransport())->queue(new Response(400, (string) json_encode([
        'error' => ['message' => 'This message is sent outside of allowed window.', 'code' => 10],
    ])));

    $result = $messenger($transport)->send('PSID-1', 'x');

    assertSame(SendOutcome::PermanentFailure, $result->outcome);
    assertThat(!$result->shouldRetry());
    assertThat(str_contains($result->error, '#10'));
});

test('錯誤碼 613 → RateLimited，並把分鐘換算成秒', function () use ($messenger): void {
    $transport = (new FakeTransport())->queue(new Response(
        400,
        (string) json_encode(['error' => ['message' => 'Calls to this api have exceeded the rate limit.', 'code' => 613]]),
        ['x-business-use-case-usage' => (string) json_encode([
            'PAGE1' => [['type' => 'messenger', 'call_count' => 100, 'estimated_time_to_regain_access' => 3]],
        ])],
    ));

    $result = $messenger($transport)->send('PSID-1', 'x');

    assertSame(SendOutcome::RateLimited, $result->outcome);
    assertSame(180, $result->retryAfterSeconds, 'estimated_time_to_regain_access 的單位是分鐘');
});

test('5xx → TemporaryFailure', function () use ($messenger): void {
    $transport = (new FakeTransport())->queue(new Response(503, 'unavailable'));
    assertSame(SendOutcome::TemporaryFailure, $messenger($transport)->send('P', 'x')->outcome);
});

// ════════════════════════════════════════════════════════════
group('SendMessages 步驟');

test('限流時照平台指定的秒數等，然後重送', function (): void {
    $transport = (new FakeTransport())->queue(
        new Response(429, (string) json_encode(['parameters' => ['retry_after' => 7]])),
        new Response(200, '{"ok":true}'),
    );
    $slept = [];

    $step = new SendMessages(
        new TelegramChannel('123:ABC', $transport),
        messagesPerSecond: 0.0,            // 關掉節流，只看退避
        maxAttempts: 3,
        sleeper: static function (float $s) use (&$slept): void { $slept[] = $s; },
    );

    $out = $step([['conversationId' => '5', 'text' => 'hi']]);

    assertSame([7.0], $slept, '要等 7 秒，不是自己決定的 2 秒');
    assertSame(2, $out[0]['attempts']);
    assertSame('Sent', $out[0]['outcome']);
    assertSame(2, $transport->count());
});

test('永久失敗立刻放棄，不浪費配額', function (): void {
    $transport = new FakeTransport(new Response(403, (string) json_encode([
        'description' => 'Forbidden: bot was blocked by the user',
    ])));
    $slept = [];

    $step = new SendMessages(
        new TelegramChannel('123:ABC', $transport),
        messagesPerSecond: 0.0,
        maxAttempts: 5,
        sleeper: static function (float $s) use (&$slept): void { $slept[] = $s; },
    );

    $out = $step([['conversationId' => '5', 'text' => 'hi']]);

    assertSame(1, $transport->count(), '封鎖的人不該被重試五次');
    assertSame([], $slept);
    assertSame('PermanentFailure', $out[0]['outcome']);
});

test('重試用盡後仍然回報結果，不丟例外', function (): void {
    $transport = new FakeTransport(new Response(500, 'boom'));
    $step = new SendMessages(
        new TelegramChannel('123:ABC', $transport),
        messagesPerSecond: 0.0,
        maxAttempts: 2,
        sleeper: static fn (float $s) => null,
    );

    $out = $step([['conversationId' => '5', 'text' => 'hi']]);

    assertSame(2, $out[0]['attempts']);
    assertSame('TemporaryFailure', $out[0]['outcome']);
});

test('節流在多則之間插入間隔', function (): void {
    $transport = okTransport();
    $slept = [];

    $step = new SendMessages(
        new TelegramChannel('123:ABC', $transport),
        messagesPerSecond: 1.0,
        sleeper: static function (float $s) use (&$slept): void { $slept[] = $s; },
    );

    $step([
        ['conversationId' => '1', 'text' => 'a'],
        ['conversationId' => '2', 'text' => 'b'],
        ['conversationId' => '3', 'text' => 'c'],
    ]);

    assertSame(2, count($slept), '第一則不等，之後每則都要等');
    assertThat($slept[0] > 0.9 && $slept[0] <= 1.0, '間隔應該接近 1 秒，實得 ' . $slept[0]);
});

test('缺欄位的 item 被跳過', function (): void {
    $transport = okTransport();
    $step = new SendMessages(new TelegramChannel('123:ABC', $transport), messagesPerSecond: 0.0);

    $out = $step([['conversationId' => '', 'text' => 'x'], ['conversationId' => '1', 'text' => '']]);

    assertSame([], $out);
    assertSame(0, $transport->count());
});

// ════════════════════════════════════════════════════════════
group('WebhookHandler');

test('握手請求直接回 challenge，不進處理流程', function () use ($messenger): void {
    $called = false;
    $handler = new WebhookHandler($messenger(okTransport()));

    $response = $handler->handle(
        new WebhookRequest('GET', '', [], [
            'hub.mode' => 'subscribe', 'hub.verify_token' => 'verify-me', 'hub.challenge' => 'abc',
        ]),
        static function () use (&$called): void { $called = true; },
        deferProcessing: false,
    );

    assertSame('abc', $response->body);
    assertThat(!$called);
});

test('簽章錯 → 401，處理流程不執行', function () use ($messenger): void {
    $called = false;
    $handler = new WebhookHandler($messenger(okTransport()));

    $response = $handler->handle(
        new WebhookRequest('POST', '{"object":"page"}', ['x-hub-signature-256' => 'sha256=deadbeef']),
        static function () use (&$called): void { $called = true; },
        deferProcessing: false,
    );

    assertSame(401, $response->status);
    assertThat(!$called, '驗不過就不該有「繼續往下試試看」');
});

test('合法請求會把解析後的訊息交給處理流程', function () use ($messenger, $sign): void {
    $body = (string) json_encode([
        'object' => 'page',
        'entry' => [['id' => 'P1', 'messaging' => [
            ['sender' => ['id' => 'A'], 'message' => ['mid' => 'm1', 'text' => '你好']],
        ]]],
    ]);

    $received = [];
    $response = (new WebhookHandler($messenger(okTransport())))->handle(
        new WebhookRequest('POST', $body, ['x-hub-signature-256' => $sign($body)]),
        static function (array $messages) use (&$received): void { $received = $messages; },
        deferProcessing: false,
    );

    assertSame(200, $response->status);
    assertSame(1, count($received));
    assertSame('你好', $received[0]->text);
});

test('處理流程爆炸時仍然回 200（否則平台會不斷重送）', function () use ($messenger, $sign): void {
    $body = (string) json_encode([
        'object' => 'page',
        'entry' => [['id' => 'P1', 'messaging' => [
            ['sender' => ['id' => 'A'], 'message' => ['mid' => 'm1', 'text' => '你好']],
        ]]],
    ]);

    $logged = [];
    $response = (new WebhookHandler($messenger(okTransport()), static function (string $m) use (&$logged): void {
        $logged[] = $m;
    }))->handle(
        new WebhookRequest('POST', $body, ['x-hub-signature-256' => $sign($body)]),
        static fn () => throw new RuntimeException('後端掛了'),
        deferProcessing: false,
    );

    assertSame(200, $response->status);
    assertThat(count($logged) === 1 && str_contains($logged[0], '後端掛了'), '失敗要進 log');
});

test('非 POST 回 405', function (): void {
    $handler = new WebhookHandler(new TelegramChannel('123:ABC', okTransport()));
    $response = $handler->handle(new WebhookRequest('PUT', ''), static fn () => null, deferProcessing: false);

    assertSame(405, $response->status);
});

// ════════════════════════════════════════════════════════════
group('端到端：一條完整的客服 flow');

test('收訊息 → 去重 → 打字中 → 產生回覆 → 切段 → 送出', function (): void {
    $transport = okTransport();
    $channel = new TelegramChannel('123:ABC', $transport);
    $store = new MemoryStore();

    $longReply = str_repeat('這是一段很長的回覆。', 600); // 遠超過 4096

    $flow = (new Flow('telegram 客服'))
        ->step(new Dedupe($store, static fn (array $i): string => (string) $i['messageId']))
        ->tap(new Call('打字中', static function (array $items) use ($channel): array {
            foreach ($items as $item) {
                $channel->typing((string) $item['conversationId']);
            }

            return $items;
        }))
        // ★ 這裡一定要 array_merge。寫成 $i + ['text' => ...] 的話左值優先，
        //   text 會留著使用者原本的問題，回覆根本沒進去 —— 而且不會有任何錯誤訊息。
        ->step(new Map('產生回覆', static fn (array $i): array => array_merge($i, ['text' => $longReply])))
        ->step(new SplitText($channel->textLimit()))
        ->step(new SendMessages($channel, messagesPerSecond: 0.0, sleeper: static fn () => null));

    $update = new WebhookRequest('POST', (string) json_encode([
        'update_id' => 42,
        'message' => [
            'message_id' => 1, 'date' => 1718000000,
            'chat' => ['id' => 555, 'type' => 'private'],
            'from' => ['id' => 555, 'username' => 'ming'],
            'text' => '可以退貨嗎？',
        ],
    ]));

    $items = array_map(static fn ($m) => $m->toItem(), $channel->parse($update));
    $out = $flow->run($items);

    assertThat(count($out) >= 2, '超過 4096 的回覆要切成多則');
    assertThat(array_reduce($out, static fn ($c, $i) => $c && $i['outcome'] === 'Sent', true), '每一則都要送成功');

    // wire payload：1 次 typing + N 次 sendMessage
    $sendCalls = array_values(array_filter(
        $transport->requests,
        static fn (array $r): bool => str_ends_with($r['url'], '/sendMessage'),
    ));
    assertSame(count($out), count($sendCalls));
    foreach ($sendCalls as $call) {
        $body = json_decode((string) $call['body'], true);
        assertSame('555', $body['chat_id']);
        assertThat(mb_strlen($body['text']) <= 4096, '送出去的每一則都必須在上限內');
    }

    // 同一個 update 再來一次（Telegram 沒收到 200 就會重送）
    $secondRun = $flow->run(array_map(static fn ($m) => $m->toItem(), $channel->parse($update)));
    assertSame([], $secondRun, '重送的 update 應該被去重擋掉');
});

test('後端掛掉時使用者仍會收到 fallback 訊息', function (): void {
    // examples/channel_server.php 用的就是這個組合：問 Claude 那一步設 continueOnError，
    // 失敗時 items 原封不動往下走，再由 fallback 那一步補上文案。
    // 沒有 answered 旗標的話，這裡分不出「回了空字串」和「整步失敗」。
    $transport = okTransport();
    $channel = new TelegramChannel('123:ABC', $transport);

    $flow = (new Flow('壞掉的後端'))
        ->step(
            new Map('問 Claude', static fn (array $i): array => throw new RuntimeException('後端 502')),
            retries: 1,
            delayMs: 1,
            continueOnError: true,
        )
        ->step(new Map('fallback', static function (array $item): array {
            $answered = ($item['answered'] ?? false) === true && trim((string) $item['text']) !== '';

            return $answered ? $item : array_merge($item, ['text' => '抱歉，系統忙線中，請稍後再試一次。']);
        }))
        ->step(new SplitText($channel->textLimit()))
        ->step(new SendMessages($channel, messagesPerSecond: 0.0, sleeper: static fn () => null));

    $out = $flow->run([['conversationId' => '555', 'messageId' => '1', 'text' => '可以退貨嗎？']]);

    assertSame(1, count($out));
    assertSame('Sent', $out[0]['outcome']);
    assertSame('抱歉，系統忙線中，請稍後再試一次。', $transport->lastBody()['text'], '使用者不能完全沒有下文');
    assertSame(2, $flow->trace()[0]['attempts'], '應該有重試過');
    assertThat($flow->trace()[0]['error'] !== null, 'trace 要留下失敗紀錄');
});

// ════════════════════════════════════════════════════════════
echo "\n" . str_repeat('─', 50) . "\n";
echo $failed === 0
    ? "\033[32m全部通過：{$passed} 項\033[0m\n"
    : "\033[31m通過 {$passed} 項，失敗 {$failed} 項\033[0m\n";

exit($failed === 0 ? 0 : 1);
