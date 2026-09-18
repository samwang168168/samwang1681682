---
name: n8n-facebook
description: 用 n8n 串 Facebook / Meta —— Messenger 收發訊息、webhook 驗證(hub.challenge)與 X-Hub-Signature-256 簽章、24 小時訊息視窗與 message tag、粉專貼文與留言自動回覆、Instagram DM、Graph API 版本與權限。當使用者要做 FB Messenger 聊天機器人、粉專自動回覆、把 Facebook 接到客服或 AI 後端,或 Meta webhook 驗證失敗/訊息送不出去時使用。
---

# n8n × Facebook / Meta

共用的 webhook / 重試 / 去重觀念在 `n8n-api-integration` skill,這裡只講 Meta 專屬的部分。

**Meta 的坑比 Telegram 多一個數量級**,而且大多不在程式碼裡,在後台設定與審核。
先把下面「前置作業」做完再開始拉 workflow,否則會一直在除錯一個根本沒權限的呼叫。

## 前置作業（沒做完後面都是白工）

1. **建立 Meta App** —— developers.facebook.com → 建立應用程式 → 類型選「商業」。
2. **加入 Messenger 產品**,綁定你的粉絲專頁,產生 **Page Access Token**。
   ⚠️ Page Access Token 跟 User Access Token **不是同一個東西**,
   Messenger 的 Send API 只吃前者。
3. **記下 App Secret**（應用程式設定 → 基本資料）—— 驗簽章要用。
4. **設定 Webhook** —— 回呼網址填 n8n 的 production URL,驗證權杖自己編一組字串,
   然後訂閱欄位：`messages`、`messaging_postbacks`（留言自動回覆另需 `feed`）。
5. **權限** —— `pages_messaging`、`pages_manage_metadata`、`pages_read_engagement`。
   開發模式下只有 App 的管理員/測試人員能觸發;要對一般使用者開放**必須送 App Review**。
   「我自己測都好好的,別人傳訊息就沒反應」幾乎一定是這一條。

## 為什麼不用 Facebook Trigger 節點

Meta 的 webhook 有兩個硬性要求,Trigger 節點都做不到：

- **GET 驗證**：Meta 會先發一個 GET,帶 `hub.mode` / `hub.verify_token` / `hub.challenge`,
  你要把 `hub.challenge` **原樣以純文字回傳**。回 JSON 或多包一層都會驗證失敗。
- **POST 驗簽**：每個 POST 帶 `X-Hub-Signature-256`,要用 App Secret 對 **raw body**
  算 HMAC-SHA256 比對。Trigger 節點拿不到 raw body。

所以 Facebook 一律用兩個 **Webhook node**（同一個 path,一個 GET 一個 POST)。
`workflows/messenger-customer-service.json` 就是這個結構。

### 驗簽為什麼一定要 raw body

n8n 預設會把 body 解析成 JSON 物件。你再 `JSON.stringify()` 回去時,
空白、鍵序、Unicode escape 都可能跟 Meta 送來的原文不同 —— 簽章就對不上,
而且是**時好時壞**(取決於內容裡有沒有中文或特殊字元),最難查的那種。

Webhook node → Options → **Raw Body** 打開,body 會以 base64 binary 進來,
再照 `scripts/verify-signature.js` 的寫法解出原始位元組計算。

## 進來的資料長什麼樣

```json
{
  "object": "page",
  "entry": [{
    "id": "<PAGE_ID>",
    "time": 1718000000000,
    "messaging": [{
      "sender":    { "id": "<PSID>" },
      "recipient": { "id": "<PAGE_ID>" },
      "timestamp": 1718000000000,
      "message": { "mid": "m_abc123", "text": "可以退貨嗎？" }
    }]
  }]
}
```

四件事：

- **一定要攤平 `entry[].messaging[]`**。Meta 可以在一個請求裡塞多個 entry、
  每個 entry 多則訊息。只讀 `entry[0].messaging[0]` 在流量一大就會漏訊息。
- **`sender.id` 是 PSID**(Page-Scoped ID),同一個人在不同粉專的 PSID 不同,
  也不是他的 Facebook UID。要拿名字得另外打 `GET /{PSID}?fields=name`。
- **`message.is_echo === true` 是你自己送出去的訊息回音**。
  不過濾掉會變成 bot 跟自己無限對話 —— 這是 Messenger bot 最經典的意外。
- **`message.mid` 是去重的 key**。Meta 沒在 20 秒內收到 200 就會重送,
  連續失敗還會直接**停用你的 webhook**。

其他常見型別：`postback`(按鈕)、`message.quick_reply`、`message.attachments`、
`read` / `delivery`(已讀與送達回報,量很大,通常直接丟掉)。

## 送訊息

```
POST https://graph.facebook.com/v23.0/me/messages?access_token=<PAGE_ACCESS_TOKEN>

{ "recipient": { "id": "<PSID>" },
  "messaging_type": "RESPONSE",
  "message": { "text": "七天內未拆封可全額退貨。" } }
```

- **`messaging_type` 是必填**。回應使用者用 `RESPONSE`;主動推播用 `UPDATE`;
  超過 24 小時視窗用 `MESSAGE_TAG` 並附 `tag`。
- 文字上限 **2000 字元**,超過直接 400。切段程式見下。
- **Graph API 版本要寫死在 URL 裡**(例 `v23.0`)。不寫版本會走預設版本,
  Meta 一升級你的整合就可能無聲改行為。每個版本大約兩年後停用,
  升級前對照官方 changelog。

### 24 小時訊息視窗 —— Meta 最常見的政策擋點

使用者傳訊息給你之後,你有 **24 小時**可以自由回覆。超過之後再送
`messaging_type: RESPONSE` 會被擋（錯誤碼 10,`#10 This message is sent outside of allowed window`）。

超過視窗只能用 message tag,而且**每個 tag 有嚴格的使用情境,濫用會被停權**：

| tag | 用途 | 額外條件 |
|---|---|---|
| `HUMAN_AGENT` | 真人客服接手回覆 | 7 天內;需 Human Agent 權限 |
| `CONFIRMED_EVENT_UPDATE` | 使用者已報名的活動提醒 | |
| `POST_PURCHASE_UPDATE` | 訂單/出貨狀態更新 | |
| `ACCOUNT_UPDATE` | 帳號狀態變更 | |

**行銷訊息不在任何 tag 的許可範圍內。** 想推廣要走付費的
Sponsored Messages 或 Recurring Notifications。

所以設計時要把「使用者最後一次發話時間」記下來,超過 24 小時就走不同的路徑
（轉 email / 轉真人 / 不送）,而不是送出去等它失敗。

### 打字中與已讀

```json
{ "recipient": { "id": "<PSID>" }, "sender_action": "typing_on" }
```
`sender_action` 可填 `mark_seen` / `typing_on` / `typing_off`。
和 `message` 互斥,要分兩次請求。`typing_on` 約 20 秒後自動消失。

## 粉專貼文與留言

| 動作 | 端點 |
|---|---|
| 發貼文 | `POST /{page-id}/feed` `{ message, link }` |
| 公開回覆留言 | `POST /{comment-id}/comments` `{ message }` |
| 私訊回覆留言 | `POST /{comment-id}/private_replies` `{ message }` |
| 隱藏留言 | `POST /{comment-id}` `{ is_hidden: true }` |
| 讀取貼文留言 | `GET /{post-id}/comments?fields=from,message,created_time` |

留言事件要在 webhook 訂閱 **`feed`** 欄位,進來的是
`entry[].changes[]`（**不是** `messaging[]`）：

```json
{ "field": "feed",
  "value": { "item": "comment", "verb": "add", "comment_id": "...",
             "post_id": "...", "from": { "id": "...", "name": "..." },
             "message": "請問有現貨嗎？" } }
```

- **一定要過濾掉粉專自己的留言**(`value.from.id === PAGE_ID`),否則自動回覆會遞迴。
- `verb` 有 `add` / `edited` / `remove`,只處理 `add`。
- `private_replies` **每則留言只能用一次**,第二次會回錯誤碼 10903。

`workflows/facebook-comment-autoreply.json` 是完整的範例。

## Instagram

Instagram 商業帳號的 DM 與留言走同一套 Graph API,差別：

- webhook 的 `object` 是 `"instagram"` 而不是 `"page"`。
- 端點用 `/{ig-user-id}/messages`,權限要 `instagram_manage_messages`。
- 沒有 `private_replies`,留言只能公開回或用 DM。

判斷來源時**一定要看 `object` 欄位**,不要假設都是 page。

## 常見症狀對照

| 症狀 | 原因 |
|---|---|
| 後台「驗證權杖」一直失敗 | GET 沒把 `hub.challenge` **原樣純文字**回傳(回成 JSON 也算錯);或 workflow 沒 Active、填了測試 URL |
| 簽章驗證時好時壞 | 沒開 rawBody,用重新序列化的 JSON 算 HMAC |
| 自己測有反應、別人沒有 | App 還在開發模式,沒過 App Review |
| bot 跟自己無限對話 | 沒過濾 `message.is_echo` |
| 錯誤 `#10 ... outside of allowed window` | 超過 24 小時視窗,要用 message tag |
| 錯誤 `#200 permissions` | Page Access Token 權限不足,或用成 User Access Token |
| 錯誤 `#190 token expired` | 用了短期 token,要換長期 Page Access Token |
| webhook 突然全部不進來 | 連續回非 200 被 Meta 停用,回後台重新訂閱 |
| 同一則訊息處理兩次 | 20 秒內沒回 200 導致重送 → 用 `mid` 去重 + `responseMode: onReceived` |

## 現成的 workflow

匯入前先跑 `node ../n8n-api-integration/scripts/lint-workflow.mjs <檔案>`。

| 檔案 | 用途 |
|---|---|
| `workflows/messenger-customer-service.json` | GET 驗證 + POST 驗簽 → 攤平去重 → typing → 呼叫客服後端 → 切段 → 回覆 |
| `workflows/facebook-comment-autoreply.json` | 粉專留言 → 過濾自己 → 公開回覆 + 私訊 |

兩個都預期後端是本專案的 `examples/n8n_webhook.php`（回 `{"reply": "..."}`）。

## 相關檔案

- `references/messenger-api.md` —— 端點、欄位、錯誤碼、訊息模板速查
- `scripts/verify-signature.js` —— `X-Hub-Signature-256` 驗證（貼進 Code node）
