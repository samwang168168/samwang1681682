---
name: telegram-bot
description: Telegram Bot API 串接研究 —— 建 bot、webhook 與 long polling、收發訊息、inline 鍵盤與 callback、群組與 privacy mode、檔案收發、廣播推播、4096 字切段、MarkdownV2 跳脫、429 retry_after 與限流、錯誤碼判讀。當要做 Telegram bot、Telegram 通知推播、把 Telegram 接到客服或 AI 後端，或遇到訊息沒進來、送不出去、被限流時使用。
homepage: https://github.com/samwang168168/samwang1681682
---

# Telegram Bot API 串接

Telegram 的 API 比 Meta 好對付很多：不用審核、不用管權限、註冊完就能用。
但它有幾個**不報錯的陷阱**，踩到只會覺得「怎麼有時候怪怪的」。

參考實作：本專案的 `src/Channel/TelegramChannel.php`（PHP，零第三方套件）。
平台速查：`references/telegram-bot-api.md`。

## 三十秒建 bot

1. Telegram 裡找 [@BotFather](https://t.me/BotFather) → `/newbot` → 拿到 token
   （長相：`123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw`）。
2. 設 webhook：

```bash
curl -X POST "https://api.telegram.org/bot$TOKEN/setWebhook" \
  --data-urlencode "url=https://你的網域/webhook/telegram" \
  --data-urlencode "secret_token=$(openssl rand -hex 32)" \
  --data-urlencode 'allowed_updates=["message"]' \
  --data-urlencode "drop_pending_updates=true"
```

3. 驗證：`curl "https://api.telegram.org/bot$TOKEN/getWebhookInfo"`

⚠️ **一個 token 同時只能有一個 webhook。** 開發機和正式機用同一個 token，
後設定的那台會把前一台的 webhook **靜靜搶走** —— 不會有任何錯誤訊息。
開發請用另一個 bot。

⚠️ `drop_pending_updates=true` 很重要。不加的話，bot 一上線會被積壓的
幾百則舊訊息灌爆。

## 進來的資料長什麼樣

```json
{
  "update_id": 123456789,
  "message": {
    "message_id": 42,
    "from": { "id": 987654321, "is_bot": false, "first_name": "小明",
              "username": "ming", "language_code": "zh-hant" },
    "chat": { "id": 987654321, "first_name": "小明", "type": "private" },
    "date": 1718000000,
    "text": "可以退貨嗎？"
  }
}
```

四件事：

1. **`chat.id` 才是回訊息要用的 ID，不是 `from.id`。**
   私訊時兩者相同，所以這個 bug 在開發時完全測不出來 ——
   一到群組，`chat.id` 是負數的群組 ID，用 `from.id` 就會把群組的回覆
   私訊給發話者。這是 Telegram 整合最常見的單一錯誤。
2. **不是每個 update 都有 `message.text`。** 貼圖、照片、位置、
   加入群組通知都會進來，沒先過濾就讀 `text` 會拿到 `undefined` / `null`。
3. **`update_id` 是去重的 key** —— 遞增且全 bot 唯一。
4. **一個 update 只會有一個主體 key**：`message` / `edited_message` /
   `callback_query` / `channel_post` / `my_chat_member` / `inline_query`。
   不會同時出現，判斷時要逐一檢查。

### 群組的 privacy mode

Bot 預設在群組裡是 **privacy mode**，只收得到 `/指令` 和 @提及。
要收全部訊息得跟 BotFather 說 `/setprivacy` → Disable，**然後把 bot 移出再加回群組**
（設定不會套用到既有的群組成員身分）。

「私訊有反應、群組沒有」= 這一條。

## 送訊息

```
POST https://api.telegram.org/bot<TOKEN>/sendMessage
{ "chat_id": 987654321, "text": "..." }
```

### 三條一定會撞到的限制

| 限制 | 數值 | 沒處理會怎樣 |
|---|---|---|
| 單則訊息長度 | **4096 字元**（字元數，不是 bytes） | 400，訊息整則掉 |
| 同一個 chat | 約 **1 則 / 秒** | 429 |
| 全域 | 約 **30 則 / 秒** | 429 |
| 群組 / 頻道 | 約 **20 則 / 分鐘** | 429 |

LLM 的回覆很容易超過 4096，**切段是必要的，不是優化**。
切點優先序：段落 → 換行 → 句尾 → 空白 → 硬切，
並且要以字元（不是 byte）為單位，否則中文和 emoji 會被切成半個。

限流數值是官方標示的近似值，**不要卡在上限跑**，留兩成餘裕。

### parse_mode：能不用就不用

**最保險的做法是完全不設 `parse_mode`，純文字送出。**
格式跑掉總比訊息不見好。

一定要格式的話用 **HTML**，只需跳脫三個字元：

```php
$safe = str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
```

支援 `<b> <i> <u> <s> <code> <pre> <a href> <blockquote> <tg-spoiler>`。

**不要用 MarkdownV2 送 LLM 產生的內容。** 它要跳脫 18 個字元：

```
_ * [ ] ( ) ~ ` > # + - = | { } . !
```

漏一個就整則 400。任何句號、日期 `2024-01-01`、清單的 `- ` 都會炸，
而 LLM 的輸出幾乎一定會含到。

### 打字中

```
POST /sendChatAction  { "chat_id": ..., "action": "typing" }
```

LLM 要想五秒，使用者會以為 bot 死了。**放在呼叫後端之前**，不是之後。
`typing` 狀態只持續 5 秒，更久要重送。

## 錯誤碼要分成三類處理

跟 Meta 一樣，這是最常做錯的地方：

| 類別 | 狀態碼 | 怎麼做 |
|---|---|---|
| **限流** | 429 | 讀 body 的 `parameters.retry_after`，**照它說的秒數等**再送同一則 |
| **終局失敗** | 403（`bot was blocked by the user` / `bot was kicked`）、400 | 立刻放棄。403 要**從推播名單移除** |
| **暫時性** | 5xx、連線斷 | 指數退避後重試 |

429 的回應長這樣 —— 等待秒數在 **body 裡**，不是 `Retry-After` header：

```json
{ "ok": false, "error_code": 429,
  "description": "Too Many Requests: retry after 12",
  "parameters": { "retry_after": 12 } }
```

★ **對方叫你等 30 秒，你等 2 秒，只會被繼續擋**，嚴重時 bot 會被暫時封鎖。
用固定間隔重試（很多框架的預設行為）在這裡是錯的。

★ **403 跟 429 混在同一個重試邏輯裡**，會讓每次廣播都把配額燒在
同一批封鎖名單上，而真正該重送的反而被擠掉。

## Inline 按鈕與 callback

```json
{ "inline_keyboard": [
    [ { "text": "✅ 是", "callback_data": "yes:ORD-99120" },
      { "text": "❌ 否", "callback_data": "no:ORD-99120" } ] ] }
```

- `callback_data` 上限 **64 bytes**。放不下就存 id，細節查 DB。
- 使用者按下後進來的是帶 `callback_query` 的 update（**不是** `message`）——
  `setWebhook` 的 `allowed_updates` 要加上 `callback_query`。
- **一定要回 `answerCallbackQuery`**，否則使用者的按鈕會一直轉圈。

## 檔案

- **收**：update 裡只有 `file_id`。先 `getFile` 拿 `file_path`，
  再下載 `https://api.telegram.org/file/bot<TOKEN>/<file_path>`。
  ⚠️ 這個 URL **含 token**，不要記進 log。下載上限 20 MB。
- **送**：`sendPhoto` / `sendDocument` 可以直接給公開 URL，
  Telegram 會自己去抓，不用先上傳。上限 photo 10 MB、document 50 MB。

## Webhook vs getUpdates

**兩者不能同時開。** 設了 webhook 之後 `getUpdates` 會回 409。

| | Webhook | getUpdates（long polling） |
|---|---|---|
| 需要公開 HTTPS | 是 | 否 |
| 延遲 | 最低 | 一個 polling 週期 |
| 本機開發 | 要 ngrok 之類的通道 | 可直接用 |

本機想用 polling 就先 `deleteWebhook`。

### secret_token 一定要設

Telegram 會在每個請求帶 `X-Telegram-Bot-Api-Secret-Token`。
不驗它的話，**任何知道你網址的人都能偽造訊息**。

```php
if (!hash_equals($this->webhookSecret, $request->header('x-telegram-bot-api-secret-token') ?? '')) {
    throw new ChannelException('X-Telegram-Bot-Api-Secret-Token 不符');
}
```

用 `hash_equals`（定值時間比較），不要用 `===`。

## webhook 一定會重送

Telegram 沒收到 200 就會重送同一個 `update_id`。
**這是正常狀態，不是例外** —— 少了去重，使用者收到兩次回覆，而你付兩次 API 錢。

去重的 `add()` 必須是**原子的**。「先查有沒有、再寫入」中間有空窗，
並行的 webhook 就會處理兩次。單機用檔案鎖，多機用 Redis `SET key NX EX 3600`。

另外，**慢的事情要放在回應之後做**：先回 200，再去問 LLM、查 DB，
最後主動呼叫 `sendMessage`。把回覆塞在 webhook 的 response body 裡
（Telegram 支援這種寫法）一逾時整則訊息就掉了，不值得。

## 症狀對照表

| 症狀 | 原因 |
|---|---|
| 完全沒有請求進來 | 服務沒上線；或 token 被另一台機器搶走 webhook。查 `getWebhookInfo` |
| 私訊有反應、群組沒有 | privacy mode 還開著（BotFather `/setprivacy`，之後要重新加入群組） |
| 群組的回覆跑到個人私訊 | 用了 `from.id` 而不是 `chat.id` |
| 400 `chat not found` | chat_id 錯，或使用者從未跟 bot 對話過 |
| 400 `can't parse entities` | MarkdownV2 沒跳脫乾淨 → 改 HTML 或拿掉 parse_mode |
| 400 `MESSAGE_TOO_LONG` | 超過 4096，要切段 |
| 403 `bot was blocked by the user` | 使用者封鎖，**從名單移除，不要重試** |
| 同一則訊息回兩次 | 沒回 200 導致重送 → 用 `update_id` 去重 |
| 429 越來越頻繁 | 沒照 `retry_after` 等 |

除錯第一站永遠是：

```bash
curl "https://api.telegram.org/bot$TOKEN/getWebhookInfo"
```

看 `url` 對不對、`pending_update_count` 有沒有一直累積、
`last_error_date` / `last_error_message` 說了什麼。

## 怎麼測而不花錢、不連外網

把 HTTP 出口抽成介面，測試時換成假的，**斷言真正送出去的 wire payload**：

```php
$transport = new FakeTransport();
(new TelegramChannel('123:ABC', $transport))->send('555', '您好');

assertSame('https://api.telegram.org/bot123:ABC/sendMessage', $transport->lastRequest()['url']);
assertSame(['chat_id' => '555', 'text' => '您好'], $transport->lastBody());
```

模擬 429 → 等待 → 重試 → 成功：

```php
$transport->queue(
    new Response(429, '{"parameters":{"retry_after":7}}'),
    new Response(200, '{"ok":true}'),
);
// 斷言它真的等了 7 秒（注入假的 sleeper 來記錄），而不是自己決定的 2 秒
```

本專案的 `tools/test_channels.php` 有 53 項這樣的測試，不需要任何憑證。

## 平台速查

`references/telegram-bot-api.md` —— 常用 method 與參數、Update 物件的所有型別、
硬限制數值表、完整錯誤碼、`setWebhook` 全部參數。
