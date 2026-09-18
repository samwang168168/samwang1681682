# Telegram Bot API 速查

Base URL：`https://api.telegram.org/bot<TOKEN>/<METHOD>`
參數可走 query string 或 JSON body,回應一律是 `{"ok": true, "result": ...}`。

## 常用 method

| Method | 必填 | 常用選填 |
|---|---|---|
| `sendMessage` | `chat_id`, `text` | `parse_mode`, `reply_markup`, `reply_parameters`, `link_preview_options`, `disable_notification` |
| `sendChatAction` | `chat_id`, `action` | `action`: `typing` / `upload_photo` / `upload_document`（持續 5 秒） |
| `sendPhoto` | `chat_id`, `photo` | `caption`（上限 1024 字）, `parse_mode` |
| `sendDocument` | `chat_id`, `document` | `caption`, `thumbnail` |
| `editMessageText` | `chat_id`, `message_id`, `text` | 編輯已送出的訊息,做「思考中…→ 正式答案」很好用 |
| `deleteMessage` | `chat_id`, `message_id` | 只能刪 48 小時內的 |
| `answerCallbackQuery` | `callback_query_id` | `text`, `show_alert`。**按鈕按下後必回** |
| `getFile` | `file_id` | 回 `file_path`,再去 `/file/bot<TOKEN>/<file_path>` 下載 |
| `setWebhook` | `url` | `secret_token`, `allowed_updates`, `drop_pending_updates`, `max_connections` |
| `getWebhookInfo` | — | 查目前 webhook 狀態與 `last_error_message`,除錯第一站 |
| `deleteWebhook` | — | `drop_pending_updates` |
| `getMe` | — | 驗證 token 是否有效 |

## Update 物件：可能出現哪些 key

一個 update **只會有其中一個**主體 key,不會同時出現：

| key | 什麼時候 |
|---|---|
| `message` | 一般訊息（文字、圖、貼圖、位置、加入/離開群組通知…） |
| `edited_message` | 使用者編輯了舊訊息 |
| `callback_query` | 按下 inline 按鈕 |
| `channel_post` | 頻道貼文 |
| `my_chat_member` | bot 被加入/踢出群組、被封鎖 |
| `inline_query` | 在別的聊天室打 `@yourbot ...` |

Telegram Trigger 節點的 `updates` 欄位要勾對,預設只有 `message`。
勾 `*` 收全部,但流量與雜訊都會變多。

### message 裡的內容型別

`text` 只是其中一種,判斷順序建議：

```js
const m = $json.message ?? {};
const kind =
  m.text      ? 'text'
: m.photo     ? 'photo'      // 陣列,最後一個是最大解析度
: m.document  ? 'document'
: m.voice     ? 'voice'
: m.sticker   ? 'sticker'
: m.location  ? 'location'
: 'other';
```

## 硬限制數值

| 項目 | 上限 |
|---|---|
| 訊息文字 | 4096 字元 |
| caption（圖/檔的說明） | 1024 字元 |
| `callback_data` | 64 bytes |
| inline 鍵盤按鈕 | 每列 8 個,總共 100 個 |
| 下載檔案 | 20 MB |
| 上傳 photo | 10 MB |
| 上傳 document | 50 MB |
| 同一 chat 送訊息 | 約 1 則 / 秒 |
| 全域 | 約 30 則 / 秒 |
| 群組 / 頻道 | 約 20 則 / 分鐘 |

限流數值是 Telegram 文件標示的「近似值」,官方保留調整空間。
**不要卡在上限跑**,留 20% 餘裕。

## 錯誤碼

| code | description 關鍵字 | 處理 |
|---|---|---|
| 400 | `chat not found` | chat_id 錯,或使用者從未跟 bot 對話過 |
| 400 | `MESSAGE_TOO_LONG` | 超過 4096,要切段 |
| 400 | `can't parse entities` | parse_mode 的標記壞掉,拿掉 parse_mode 重送 |
| 400 | `message is not modified` | `editMessageText` 內容跟原本一樣,可忽略 |
| 401 | `Unauthorized` | token 錯或被 BotFather 撤銷 |
| 403 | `bot was blocked by the user` | 使用者封鎖,**從名單移除,不要重試** |
| 403 | `bot was kicked from the group` | 同上 |
| 429 | `Too Many Requests: retry after N` | 讀 `parameters.retry_after`,等滿再送 |

**403 是終局錯誤,不要重試。** 把它跟 429 混在同一個重試邏輯裡,
會讓每次廣播都在同一批封鎖名單上白白燒掉配額。

## parse_mode

### HTML（建議）
支援：`<b> <strong> <i> <em> <u> <s> <code> <pre> <a href="..."> <blockquote> <tg-spoiler>`
只需跳脫 `&` `<` `>`。標籤沒閉合會 400。

### MarkdownV2
必須跳脫（在**所有**位置,包括連結文字裡）：

```
_ * [ ] ( ) ~ ` > # + - = | { } . !
```

`.` 和 `-` 特別容易漏 —— 任何句號、日期 `2024-01-01`、清單的 `- ` 都會炸。

### Markdown（舊版）
已被 Telegram 標為 legacy,新專案不要用。

## setWebhook 參數

```bash
curl -X POST "https://api.telegram.org/bot$TOKEN/setWebhook" \
  -d "url=https://你的網域/channel_server.php/telegram" \
  -d "secret_token=$(openssl rand -hex 32)" \
  -d "allowed_updates=[\"message\",\"callback_query\"]" \
  -d "drop_pending_updates=true"
```

- `secret_token` —— Telegram 會在每個請求帶 `X-Telegram-Bot-Api-Secret-Token` header。
  **一定要驗**，否則任何人知道你的 URL 就能偽造訊息。
  把同一組字串傳給 `new TelegramChannel(..., webhookSecret: '...')`，
  `verify()` 會用 `hash_equals` 比對。
- `drop_pending_updates=true` —— 換 URL 時丟掉積壓的舊訊息。
  不加的話,bot 一上線會被幾百則舊訊息灌爆。
- `max_connections` —— 同時開幾條連線過來，預設 40。你的 php-fpm worker 數量比這個少的話要調小。

webhook 沒反應時第一件事：

```bash
curl "https://api.telegram.org/bot$TOKEN/getWebhookInfo"
```

看 `url` 對不對、`pending_update_count` 有沒有一直累積、
`last_error_date` / `last_error_message` 說了什麼。

## Webhook vs getUpdates

| | Webhook | getUpdates（long polling） |
|---|---|---|
| 需要公開 HTTPS | 是 | 否 |
| 本專案支援 | `TelegramChannel` + `WebhookHandler` | 要自己寫一個 CLI 迴圈打 `getUpdates` |
| 延遲 | 最低 | 一個 polling 週期 |
| 本機開發 | 要 ngrok 之類的通道 | 可直接用 |

**兩者不能同時開。** 設了 webhook 之後 `getUpdates` 會回 409。
本機開發想用 polling 的話先 `deleteWebhook`。
