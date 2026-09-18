---
name: n8n-telegram
description: 用 n8n 串 Telegram Bot API —— 建 bot、接收訊息 webhook、送出回覆、inline 鍵盤與 callback、圖片與檔案、群組行為、廣播推播、4096 字切段、MarkdownV2 跳脫、429 retry_after 與限流。當使用者要做 Telegram bot、Telegram 通知/推播、把 Telegram 接到客服或 AI 後端、或 Telegram 訊息沒進來/送不出去時使用。
---

# n8n × Telegram Bot API

共用的 webhook / 重試 / 去重觀念在 `n8n-api-integration` skill,這裡只講 Telegram 專屬的部分。

## 30 秒建 bot

1. Telegram 裡找 [@BotFather](https://t.me/BotFather) → `/newbot` → 拿到 token
   （長相：`123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw`）。
2. n8n → Credentials → New → **Telegram API** → 貼上 token。
3. 拉一個 **Telegram Trigger** 節點,選好憑證,勾 `message`。
4. 按 Active。n8n 會自動幫你呼叫 `setWebhook`。

⚠️ **一個 token 同時只能有一個 webhook。** 開發機和正式機用同一個 token,
後啟用的那台會把前一台的 webhook 搶走 —— 而且不會有任何錯誤訊息。
開發請用另一個 bot。

## 進來的資料長什麼樣

Telegram Trigger 的輸出就是原始的 Update 物件：

```json
{
  "update_id": 123456789,
  "message": {
    "message_id": 42,
    "from": { "id": 987654321, "is_bot": false, "first_name": "小明", "username": "ming", "language_code": "zh-hant" },
    "chat": { "id": 987654321, "first_name": "小明", "type": "private" },
    "date": 1718000000,
    "text": "可以退貨嗎？"
  }
}
```

要記住的四件事：

- **`chat.id` 才是回訊息要用的 ID**,不是 `from.id`。私訊時兩者相同,
  群組裡 `chat.id` 是負數的群組 ID —— 用錯就會把群組的回覆私訊給發話者。
- **不是每個 update 都有 `message.text`**。貼圖、照片、加入群組通知都會進來,
  沒先過濾就直接讀 `text` 會拿到 `undefined`。
- **`update_id` 是去重的 key**,遞增且全 bot 唯一。
- Bot 預設在群組裡是 **privacy mode**,只收得到 `/指令` 和 @提及。
  要收全部訊息得跟 BotFather 說 `/setprivacy` → Disable。

## 送訊息

用 **Telegram** 節點（`resource: message`, `operation: sendMessage`）即可。
要自訂 API 沒開放在節點上的欄位時,改用 HTTP Request 打：

```
POST https://api.telegram.org/bot<TOKEN>/sendMessage
{ "chat_id": 987654321, "text": "...", "parse_mode": "HTML" }
```

### 三條一定會踩到的限制

| 限制 | 數值 | 沒處理會怎樣 |
|---|---|---|
| 單則訊息長度 | **4096 字元**（UTF-8 字元數,不是 bytes） | 400 `MESSAGE_TOO_LONG`,訊息整則掉 |
| 同一個 chat 的頻率 | 約 **1 則 / 秒** | 429 |
| 全域頻率 | 約 **30 則 / 秒** | 429 |
| 群組 | 約 **20 則 / 分鐘** | 429 |

LLM 回覆很容易超過 4096。切段程式在
`scripts/split-message.js`,直接貼進 Code node（`runOnceForEachItem`）。
它會優先在段落、再來句子、最後才硬切,不會把字切一半。

### parse_mode 的選擇

**能用 HTML 就用 HTML。** MarkdownV2 要跳脫 18 個字元
（`_*[]()~`>#+-=|{}.!`）,漏一個就整則 400,而 LLM 產生的文字幾乎一定會含到。

HTML 只需要處理三個字元,而且只支援
`<b> <i> <u> <s> <code> <pre> <a href> <blockquote> <tg-spoiler>`：

```js
const esc = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
```

要用 MarkdownV2 的話,跳脫函式在 `scripts/escape-markdown-v2.js`。

最保險的做法：**完全不設 `parse_mode`**,純文字送出。格式跑掉總比訊息不見好。

### 429 的正確處理

Telegram 會直接告訴你要等幾秒：

```json
{ "ok": false, "error_code": 429, "description": "Too Many Requests: retry after 12",
  "parameters": { "retry_after": 12 } }
```

HTTP Request 節點開 `neverError` + `fullResponse`,讀 `body.parameters.retry_after`,
接 Wait 節點等完再繞回去重送。**不要用固定 2 秒重試**,對方叫你等 30 秒你等 2 秒,
只會被繼續擋,嚴重時 bot 會被暫時封鎖。

廣播的完整做法見 `workflows/telegram-broadcast.json`。

## 打字中提示

LLM 要想個五秒,使用者會以為 bot 死了。先送一個 typing：

```
Telegram 節點 → operation: sendChatAction, chatId: ..., action: typing
```

`typing` 狀態只持續 **5 秒**,更久要重送。放在呼叫後端之前,不要放在之後。

## Inline 按鈕與 callback

送出時帶 `reply_markup`：

```json
{
  "inline_keyboard": [
    [ { "text": "✅ 是", "callback_data": "yes:ORD-99120" },
      { "text": "❌ 否", "callback_data": "no:ORD-99120" } ]
  ]
}
```

- `callback_data` 上限 **64 bytes**。放不下就存 id,細節查 DB。
- 使用者按下後,會進來一個帶 `callback_query` 的 update（不是 `message`）——
  Telegram Trigger 要另外勾 `callback_query`。
- **一定要回 `answerCallbackQuery`**,不然使用者的按鈕會一直轉圈：
  ```
  POST https://api.telegram.org/bot<TOKEN>/answerCallbackQuery
  { "callback_query_id": "...", "text": "已收到" }
  ```

## 檔案

- 收：update 裡是 `file_id`,要先 `getFile` 拿 `file_path`,
  再下載 `https://api.telegram.org/file/bot<TOKEN>/<file_path>`。
  n8n 的 Telegram 節點 `resource: file, operation: get` 兩步一起做掉。
  **下載 URL 含 token,不要記進 log。**
- 送：`sendPhoto` / `sendDocument`,可以直接給公開 URL,Telegram 會自己去抓,
  不用先上傳。上限：photo 10 MB、document 50 MB。

## 常見症狀對照

| 症狀 | 原因 |
|---|---|
| 完全沒有 execution 紀錄 | workflow 沒 Active;或 token 被另一台機器搶走 webhook |
| 私訊有反應、群組沒有 | privacy mode 還開著（BotFather `/setprivacy`） |
| 400 `chat not found` | 用了 `from.id` 而不是 `chat.id`;或使用者沒先跟 bot 說過話 |
| 400 `can't parse entities` | MarkdownV2 沒跳脫乾淨 → 換 HTML 或拿掉 parse_mode |
| 403 `bot was blocked by the user` | 使用者封鎖了 bot。**要把他從推播名單移除**,否則每次廣播都白打一次 |
| 同一則訊息回兩次 | 沒回 200 導致 Telegram 重送 → 用 `update_id` 去重 |
| 429 越來越頻繁 | 沒照 `retry_after` 等 |

## 現成的 workflow

匯入前先跑 `node ../n8n-api-integration/scripts/lint-workflow.mjs <檔案>`。

| 檔案 | 用途 |
|---|---|
| `workflows/telegram-customer-service.json` | 收訊息 → 去重 → typing → 呼叫客服後端 → 切段 → 回覆 |
| `workflows/telegram-broadcast.json` | 分批廣播,含 429 退避與封鎖名單回報 |

兩個都預期後端是本專案的 `examples/n8n_webhook.php`（回 `{"reply": "..."}`）。
換成自己的後端只要改 HTTP Request 節點的 URL 與取值路徑。

## 相關檔案

- `references/bot-api.md` —— 常用 method、欄位、限制數值速查
- `scripts/split-message.js` —— 4096 字切段（貼進 Code node）
- `scripts/escape-markdown-v2.js` —— MarkdownV2 跳脫
- `scripts/set-webhook.sh` —— 手動設定 / 查詢 / 刪除 webhook
