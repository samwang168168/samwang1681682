# Meta Graph API / Messenger 速查

Base：`https://graph.facebook.com/v23.0/`
認證：query 參數 `access_token=<PAGE_ACCESS_TOKEN>`,或 header `Authorization: Bearer <token>`。

**版本一定要寫死在 URL 裡。** 省略版本會走 Meta 的預設版本,升級時無聲改行為。

## Webhook 驗證（GET）

Meta 在你儲存回呼網址時發一次,之後每次改設定也會再發：

```
GET /webhook/messenger?hub.mode=subscribe
                      &hub.verify_token=<你在後台填的字串>
                      &hub.challenge=1158201444
```

要求：`hub.verify_token` 比對成功 → **回 200,body 是 `hub.challenge` 的原始值,
content-type 純文字**。

在 n8n：Webhook node(GET, responseMode `responseNode`)→ IF 比對 token →
Respond to Webhook(`respondWith: "text"`, `responseBody: "={{ $json.query['hub.challenge'] }}"`)。

❌ 常見錯誤：回 `{"challenge":"1158201444"}`、回 `"1158201444"`(帶引號)、
把 `responseMode` 留在 `lastNode` 導致回傳整個 item 的 JSON。三種都會驗證失敗。

## Webhook 簽章（POST）

每個 POST 帶：

```
X-Hub-Signature-256: sha256=<hex>
```

`hex` = HMAC-SHA256(App Secret, **raw request body 的位元組**)。

比對時用 `crypto.timingSafeEqual`,不要用 `===`。實作見
`../scripts/verify-signature.js`。

舊的 `X-Hub-Signature`(SHA-1)已淘汰,不要用。

## Send API

```
POST /{page-id}/messages        （或 /me/messages,用 token 推斷粉專）
```

### 純文字
```json
{ "recipient": { "id": "<PSID>" },
  "messaging_type": "RESPONSE",
  "message": { "text": "內容（上限 2000 字元）" } }
```

### sender_action（打字中／已讀）
```json
{ "recipient": { "id": "<PSID>" }, "sender_action": "typing_on" }
```
可填 `mark_seen` / `typing_on` / `typing_off`。**與 `message` 互斥**,要分開送。

### Quick Replies（最多 13 個）
```json
{ "recipient": { "id": "<PSID>" },
  "messaging_type": "RESPONSE",
  "message": {
    "text": "需要什麼協助？",
    "quick_replies": [
      { "content_type": "text", "title": "查訂單", "payload": "ORDER" },
      { "content_type": "text", "title": "退貨", "payload": "RETURN" }
    ] } }
```
`title` 上限 20 字元,`payload` 上限 1000 字元。
使用者點選後,webhook 進來的是 `message.quick_reply.payload`。

### Button Template（最多 3 個按鈕）
```json
{ "recipient": { "id": "<PSID>" },
  "messaging_type": "RESPONSE",
  "message": { "attachment": { "type": "template", "payload": {
    "template_type": "button",
    "text": "訂單 ORD-99120",
    "buttons": [
      { "type": "postback", "title": "確認退貨", "payload": "RETURN:ORD-99120" },
      { "type": "web_url",  "title": "查看明細", "url": "https://example.com/o/99120" }
    ] } } } }
```
`postback` 按下後 webhook 收到的是 `postback.payload`(不是 `message`)。

### 圖片 / 檔案
```json
{ "recipient": { "id": "<PSID>" },
  "messaging_type": "RESPONSE",
  "message": { "attachment": { "type": "image",
    "payload": { "url": "https://example.com/a.jpg", "is_reusable": true } } } }
```
`type` 可填 `image` / `audio` / `video` / `file`。
`is_reusable: true` 會回傳 `attachment_id`,之後重送同一張圖用 id 就好,省頻寬。

## messaging_type

| 值 | 什麼時候 |
|---|---|
| `RESPONSE` | 回應使用者的訊息（24 小時視窗內） |
| `UPDATE` | 主動發起、非推廣性質（一樣受 24 小時限制） |
| `MESSAGE_TAG` | 超過 24 小時,必須同時帶 `tag` |

```json
{ "recipient": { "id": "<PSID>" },
  "messaging_type": "MESSAGE_TAG",
  "tag": "POST_PURCHASE_UPDATE",
  "message": { "text": "您的訂單 ORD-99120 已出貨。" } }
```

可用的 tag：`CONFIRMED_EVENT_UPDATE`、`POST_PURCHASE_UPDATE`、`ACCOUNT_UPDATE`、
`HUMAN_AGENT`（7 天,需額外權限）。
**行銷/推廣訊息不在任何 tag 的許可範圍**,濫用會被停權。

## 其他常用端點

| 用途 | 端點 |
|---|---|
| 查使用者名字 | `GET /{PSID}?fields=first_name,last_name,profile_pic` |
| 發貼文 | `POST /{page-id}/feed` `{message, link}` |
| 回覆留言（公開） | `POST /{comment-id}/comments` `{message}` |
| 回覆留言（私訊） | `POST /{comment-id}/private_replies` `{message}` — **每則留言僅限一次** |
| 隱藏留言 | `POST /{comment-id}` `{is_hidden: true}` |
| 讀留言 | `GET /{post-id}/comments?fields=from,message,created_time` |
| 換長期 token | `GET /oauth/access_token?grant_type=fb_exchange_token&...` |
| 查 token 狀態 | `GET /debug_token?input_token=<token>&access_token=<app-token>` |
| 訂閱粉專 webhook | `POST /{page-id}/subscribed_apps` `{subscribed_fields: "messages,messaging_postbacks,feed"}` |

除錯第一站是 `/debug_token` —— 它會告訴你這個 token 是哪一種、屬於誰、
有哪些 scope、什麼時候過期。「權限不足」有一半是 token 拿錯種。

## Webhook 欄位訂閱

| 欄位 | 進來的位置 | 內容 |
|---|---|---|
| `messages` | `entry[].messaging[]` | 使用者傳來的訊息 |
| `messaging_postbacks` | `entry[].messaging[]` | 按鈕 postback |
| `message_echoes` | `entry[].messaging[]` | 你自己送出的回音（`is_echo: true`） |
| `messaging_optins` | `entry[].messaging[]` | Send-to-Messenger 外掛 |
| `feed` | `entry[].changes[]` | 貼文、留言、按讚 |

⚠️ `messaging` 和 `changes` 是**兩個不同的結構**,同一個 webhook URL 兩種都會進來。
處理時要先看哪個 key 存在,不要假設。

## 錯誤碼

| code | 意思 | 處理 |
|---|---|---|
| 10 | 超出允許的訊息視窗 | 超過 24 小時,改用 message tag 或別的通道 |
| 100 | 參數錯誤 | 多半是 PSID 錯、或 `messaging_type` 沒帶 |
| 190 | token 失效／過期 | 換長期 Page Access Token |
| 200 | 權限不足 | token 種類錯,或缺 `pages_messaging` |
| 551 | 使用者無法接收訊息 | 對方封鎖或停用,**終局錯誤,從名單移除** |
| 613 | 超過呼叫頻率上限 | 退避後重試 |
| 10903 | 已對這則留言私訊過 | `private_replies` 每則留言限一次 |
| 2018108 | 使用者不可用 | 同 551 |

錯誤回應長這樣：

```json
{ "error": { "message": "...", "type": "OAuthException", "code": 10,
             "error_subcode": 2018278, "fbtrace_id": "A1b2C3" } }
```

回報問題給 Meta 時附上 `fbtrace_id`。

## 頻率限制

Messenger 的限制是**每個粉專**,不是每個 app：

- 大致是 `200 × 粉專的互動使用者數 / 24 小時`(滾動計算)。
- 超過會拿到 code 613,或 header `X-Business-Use-Case-Usage` 裡的
  `call_count` 逼近 100。

`X-Business-Use-Case-Usage` 是 JSON,值得在 HTTP 節點開 `fullResponse` 讀出來監控：

```json
{"<page-id>":[{"type":"messenger","call_count":33,"total_cputime":12,
               "total_time":25,"estimated_time_to_regain_access":0}]}
```

`estimated_time_to_regain_access` 的單位是**分鐘**,不是秒。
