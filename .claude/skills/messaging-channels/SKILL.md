---
name: messaging-channels
description: 本專案自建的通訊軟體通道層與 flow 引擎 —— Telegram / Facebook Messenger 的 webhook 接收、簽章驗證、訊息正規化、去重、切段、節流與退避、以及新增一個通道（LINE、WhatsApp、Discord）的做法。當使用者要做聊天機器人、把客服接到通訊軟體、廣播推播、新增或修改通道，或遇到 webhook 沒進來、訊息重複、429 限流、簽章驗證失敗時使用。
---

# 通訊軟體通道層

這是**自己實作**的一套東西，不依賴 n8n、Zapier 或任何外部自動化平台。
零第三方套件（只用 ext-curl / ext-hash），所以不裝 composer 也跑得起來。

```
src/Channel/    平台轉接：webhook 進來、訊息出去
src/Flow/       流程引擎：item 流過一串 step
src/Store/      跨 process 的小狀態（去重、限流）
src/Http/       最小 HTTP 出口（可換成假的來測試）
```

## 三十秒看懂架構

```
webhook 進來
   │
   ▼
WebhookHandler ─── challenge()  平台的網址驗證握手（Meta 的 hub.challenge）
   │           └── verify()     驗簽，失敗就 401 結束
   │           └── parse()      攤平成 InboundMessage[]
   │
   ▼  先回 200，才開始做事（Meta 只等 20 秒）
Flow
   ├── Dedupe        webhook 一定會重送，這步不是防呆是必要
   ├── tap(打字中)    旁支：失敗不影響主線
   ├── Map(問 Claude) 可設重試；失敗放行，由下一步給 fallback
   ├── SplitText     Telegram 4096 / Messenger 2000，超過整則會 400
   └── SendMessages  節流 + 照平台指定秒數退避 + 三類結果分開處理
```

`Channel` 介面只有五個動作，上層流程完全不必知道訊息從哪個平台來。

## 最小可用範例

```php
$channel = new TelegramChannel(
    botToken: getenv('TELEGRAM_BOT_TOKEN'),
    transport: new CurlTransport(),
    webhookSecret: getenv('TELEGRAM_WEBHOOK_SECRET'),  // 強烈建議設
);

$flow = (new Flow('客服'))
    ->step(new Dedupe($store, fn (array $i): string => $i['messageId']))
    ->step(new Map('問 Claude', fn (array $i): array => array_merge($i, [
        'text' => $service->ask(customerId: $i['userId'], question: $i['text'])->text,
    ])))
    ->step(new SplitText($channel->textLimit()))
    ->step(new SendMessages($channel, messagesPerSecond: 1.0));

$response = (new WebhookHandler($channel))->handle(
    WebhookRequest::fromGlobals(),
    fn (array $messages) => $flow->run(array_map(fn ($m) => $m->toItem(), $messages)),
);
```

完整版在 `examples/channel_server.php`（Telegram + Messenger 共用一條 flow）。
廣播在 `examples/broadcast.php`（`--dry-run` 可離線試跑）。

## Flow 引擎

`Step` 的契約：吃一疊 item、吐一疊 item。數量可以變（過濾變少、切段變多），
這是它能任意組合的原因。item 一律是 `array<string, mixed>`，不用 DTO 是刻意的 ——
中間步驟常要加欄位，固定型別的物件每加一個欄位就要改一個類別。

| 方法 | 行為 |
|---|---|
| `step($s, retries: 2, delayMs: 500)` | 失敗時指數退避重試 |
| `step($s, continueOnError: true)` | 重試用盡後放行**原本的** items，flow 不中斷 |
| `tap($s)` | 旁支：跑但輸出丟掉、失敗不中斷。送「打字中」用它 |
| `trace()` / `traceSummary()` | 每步的進出筆數、耗時、重試次數、錯誤 —— 純資料，可做 CI 斷言 |

現成的 step：`Call`（包任意 callable）、`Filter`、`Map`（回 `null` 丟掉該筆）、
`Dedupe`、`SplitText`、`SendMessages`。

### continueOnError 要搭配成功旗標

放行時 items 是**原封不動**的，所以下一步分不出「回了空字串」和「整步失敗」。
成功的那一步要自己留記號：

```php
->step(new Map('問 Claude', fn ($i) => array_merge($i, [
    'text' => $reply->text,
    'answered' => true,          // ← 沒有這個就判斷不出來
])), continueOnError: true)
->step(new Map('fallback', fn ($i) => ($i['answered'] ?? false)
    ? $i
    : array_merge($i, ['text' => '抱歉，系統忙線中，請稍後再試一次。'])))
```

## 六個一定會踩到的坑

這些都已經在程式碼裡處理掉了，改動時不要不小心弄壞：

1. **Telegram 要用 `chat.id`，不是 `from.id`。** 私訊時兩者相同，
   群組裡 `chat.id` 是負數的群組 ID —— 用錯會把群組的回覆私訊給發話者。
2. **Messenger 的 `is_echo` 一定要濾掉。** 那是自己送出去的訊息回音，
   不濾掉 bot 會跟自己無限對話。這是 Messenger bot 最經典的意外。
3. **驗簽必須對原始位元組算。** `WebhookRequest` 保留 `rawBody` 就是為了這件事。
   先 decode 再 encode 回去算出來的 HMAC 對不上，而且症狀是
   「有中文就壞、純英文就好」這種時好時壞，最難查。
4. **webhook 一定會重送。** Telegram 沒收到 200 就重送同一個 `update_id`；
   Meta 沒在 20 秒內收到 200 就重送，連續失敗還會**直接停用你的 webhook**。
   `Dedupe` 不是防呆，是必要的 —— 少了它使用者收到兩次回覆，而你付兩次 API 錢。
5. **403 和 429 的處理完全相反。** 403 是使用者封鎖了 bot，重試一百次就是失敗一百次，
   要從名單移除；429 是限流，要等平台指定的秒數再送同一則。
   混在一起的後果是每次廣播都把配額燒在同一批封鎖名單上。
   `SendOutcome` 分成四類就是為了逼呼叫端面對這個差別。
6. **先回 200，再做事。** 平台的逾時遠比 LLM 的回應時間短。
   `WebhookHandler` 的 `deferProcessing`（預設開）會先把回應送出並
   `fastcgi_finish_request()`，之後才跑 flow。

## 去重要用跨 process 的 store

`MemoryStore` 只活在單一 process 裡。**php-fpm 底下每個請求都是新的 process，
用它等於完全沒有去重。** 單機用 `FileStore`（`flock` 保證 `add()` 原子性），
多台機器就自己實作一個 Redis 版本：

```php
final class RedisStore implements Store
{
    public function add(string $key, int $ttlSeconds = 86400): bool
    {
        return (bool) $this->redis->set($key, 1, ['NX', 'EX' => $ttlSeconds]);
    }
    // …
}
```

介面一樣，其他程式碼一行都不用改。`add()` 必須是**原子的** ——
「先 has() 再 set()」中間有空窗，並行的 webhook 就會處理兩次。

## 新增一個通道（LINE、WhatsApp、Discord…）

實作 `App\Claude\Channel\Channel` 的五個方法，其餘完全不用動：

| 方法 | 要做什麼 |
|---|---|
| `name()` | 通道識別字串 |
| `challenge()` | 平台的網址驗證握手。沒有就回 `null`（Telegram 就是） |
| `verify()` | 驗簽。失敗丟 `ChannelException`，不要回 false |
| `parse()` | 攤平成 `InboundMessage[]`，順手濾掉非文字、回音、系統事件 |
| `send()` / `typing()` | 送出，並把回應分類成四種 `SendOutcome` |

分類的判斷寫在各 channel 的 `classify()` 私有方法裡，照抄改錯誤碼即可。

寫完務必補測試 —— 見下。

## 測試

```bash
php tools/test_channels.php
```

53 項，不花錢、不連外網、不需要 composer。關鍵在於它斷言的是
**真正送出去的 wire payload**，不只是回傳值：

```php
$transport = new FakeTransport();
(new TelegramChannel('123:ABC', $transport))->send('555', '您好');

assertSame('https://api.telegram.org/bot123:ABC/sendMessage', $transport->lastRequest()['url']);
assertSame(['chat_id' => '555', 'text' => '您好'], $transport->lastBody());
```

通訊軟體整合最常見的錯是「送出去的 body 少一個欄位」（例如 Messenger 漏了必填的
`messaging_type`），那種錯只有看 wire payload 才抓得到 —— 從回傳值看一切正常。

`FakeTransport::queue()` 可以排一串回應，用來模擬 429 → 重試 → 成功這種序列。

## 平台細節

兩份平台速查放在 `openclaw/` 底下（同時供 OpenClaw 的 fb / tg agent 使用）：

- `openclaw/agents/tg/skills/telegram-bot/references/telegram-bot-api.md`
  —— method、欄位、限制數值、錯誤碼、`setWebhook` 全部參數
- `openclaw/agents/fb/skills/fb-messenger/references/messenger-api.md`
  —— 端點、訊息模板、24 小時視窗與 message tag、錯誤碼、頻率限制

兩份都是純平台知識，跟本專案的實作無關，可以直接查。
同一層的 `SKILL.md` 是各平台的整合要點（給 OpenClaw agent 讀的版本），
內容與這裡一致，只是拆成兩個平台各自獨立。
