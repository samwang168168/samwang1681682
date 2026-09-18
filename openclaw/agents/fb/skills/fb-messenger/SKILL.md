---
name: fb-messenger
description: Facebook / Meta 串接研究 —— Messenger 收發訊息、webhook 的 hub.challenge 握手與 X-Hub-Signature-256 驗簽、24 小時訊息視窗與 message tag、粉專貼文與留言、Instagram DM、Graph API 版本與權限、錯誤碼判讀。當要做 FB Messenger 聊天機器人、粉專自動回覆、把 Facebook 接到客服或 AI 後端，或遇到 webhook 驗證失敗、訊息送不出去、被限流時使用。
homepage: https://github.com/samwang168168/samwang1681682
---

# Facebook / Meta 串接

Meta 的坑比其他平台多一個數量級，而且大多**不在程式碼裡，在後台設定與審核**。
先把「前置作業」做完再開始寫程式，否則會一直在除錯一個根本沒權限的呼叫。

參考實作：本專案的 `src/Channel/MessengerChannel.php`（PHP，零第三方套件）。
平台速查：`references/messenger-api.md`。

## 前置作業（沒做完後面都是白工）

1. **建立 Meta App** —— developers.facebook.com → 建立應用程式 → 類型選「商業」。
2. **加入 Messenger 產品**，綁定粉絲專頁，產生 **Page Access Token**。
   ⚠️ Page Access Token 跟 User Access Token **不是同一個東西**，
   Send API 只吃前者。分不清楚時用 `GET /debug_token` 查。
3. **記下 App Secret**（應用程式設定 → 基本資料）—— 驗簽要用。
4. **設定 Webhook** —— 回呼網址填你的 production URL，驗證權杖自己編一組字串，
   訂閱欄位：`messages`、`messaging_postbacks`（留言自動回覆另需 `feed`）。
5. **權限** —— `pages_messaging`、`pages_manage_metadata`、`pages_read_engagement`。
   開發模式下只有 App 的管理員/測試人員能觸發；要對一般使用者開放**必須送 App Review**。

> 「我自己測都好好的，別人傳訊息就沒反應」—— 幾乎一定是第 5 條。

## Webhook 有兩個階段，兩個都會卡

### 階段一：GET 握手

Meta 在你儲存回呼網址時發一次 GET：

```
GET /webhook?hub.mode=subscribe&hub.verify_token=<你填的字串>&hub.challenge=1158201444
```

**必須把 `hub.challenge` 原樣、以純文字回傳。**

❌ 回 `{"challenge":"1158201444"}`、回 `"1158201444"`（帶引號）、
把回應包成 JSON —— 三種都會驗證失敗，而且後台只顯示一句很沒幫助的錯誤。

```php
// MessengerChannel::challenge()
if ($request->method !== 'GET' || $request->query('hub.mode') === null) {
    return null;                       // 不是握手，照一般流程走
}
if ($request->query('hub.mode') !== 'subscribe'
    || !hash_equals($this->verifyToken, (string) $request->query('hub.verify_token'))) {
    return new WebhookResponse(403, 'forbidden');
}
return WebhookResponse::ok((string) $request->query('hub.challenge'));   // ← 原樣純文字
```

### 階段二：POST 驗簽

每個 POST 帶 `X-Hub-Signature-256: sha256=<hex>`，
`hex` = HMAC-SHA256(App Secret, **原始請求 body 的位元組**)。

```php
$expected = 'sha256=' . hash_hmac('sha256', $request->rawBody, $this->appSecret);
if (!hash_equals($expected, $signature)) {
    throw new ChannelException('X-Hub-Signature-256 驗證失敗');
}
```

★ **一定要對原始位元組算。** 先 `json_decode` 再 `json_encode` 回去的字串算出來的
HMAC 對不上 —— 而且症狀是「有中文就壞、純英文就好」這種時好時壞的，最難查。
原因是 `json_encode` 預設把中文轉成 `\uXXXX`，bytes 跟 Meta 送來的原文不同。

所以接收端的請求物件**必須保留 raw body**。任何會先幫你解析 JSON 的框架或
自動化平台，在這裡都是陷阱。

比對用定值時間比較（PHP 的 `hash_equals`），不要用 `===`。

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

1. **一定要攤平 `entry[].messaging[]`。** Meta 可以在一個請求裡塞多個 entry、
   每個 entry 多則訊息。只讀 `entry[0].messaging[0]` 在流量一大就會漏訊息。
2. **`sender.id` 是 PSID**（Page-Scoped ID）—— 同一個人在不同粉專的 PSID 不同，
   也不是他的 Facebook UID。要拿名字得另外打 `GET /{PSID}?fields=name`。
3. **`message.is_echo === true` 是自己送出去的回音。**
   不過濾掉會變成 bot 跟自己無限對話 —— Messenger bot 最經典的意外。
4. **`message.mid` 是去重的 key。** Meta 沒在 **20 秒**內收到 200 就會重送，
   連續失敗還會**直接停用你的 webhook**。

其他常見型別：`postback`（按鈕）、`message.quick_reply`、`message.attachments`、
`read` / `delivery`（已讀與送達回報，量很大，通常直接丟掉）。

`object` 是 `"instagram"` 時代表是 IG 的事件，結構不同，要分開處理。

## 送訊息

```
POST https://graph.facebook.com/v23.0/me/messages?access_token=<PAGE_ACCESS_TOKEN>

{ "recipient": { "id": "<PSID>" },
  "messaging_type": "RESPONSE",
  "message": { "text": "七天內未拆封可全額退貨。" } }
```

- **`messaging_type` 是必填。** 漏了直接 400 —— 而且這是從回傳值看不出來的那種錯，
  要看真正送出去的 wire payload 才抓得到。
- 文字上限 **2000 字元**，超過整則 400。LLM 的回覆很容易超過，要先切段。
- **Graph API 版本要寫死在 URL 裡**（例 `v23.0`）。不寫版本會走預設版本，
  Meta 一升級你的整合就可能無聲改行為。每個版本約兩年後停用。

### 24 小時訊息視窗 —— Meta 最常見的政策擋點

使用者傳訊息給你之後，你有 **24 小時**可以自由回覆。超過之後再送
`messaging_type: RESPONSE` 會被擋（錯誤碼 **10**）。

超過視窗只能用 message tag，而且每個 tag 有嚴格的使用情境：

| tag | 用途 | 條件 |
|---|---|---|
| `HUMAN_AGENT` | 真人客服接手回覆 | 7 天內；需 Human Agent 權限 |
| `CONFIRMED_EVENT_UPDATE` | 使用者已報名的活動提醒 | |
| `POST_PURCHASE_UPDATE` | 訂單/出貨狀態更新 | |
| `ACCOUNT_UPDATE` | 帳號狀態變更 | |

**行銷訊息不在任何 tag 的許可範圍內**，濫用會被停權。
想推廣要走付費的 Sponsored Messages 或 Recurring Notifications。

設計時要把「使用者最後一次發話時間」記下來，超過 24 小時就走不同路徑
（轉 email / 轉真人 / 不送），而不是送出去等它失敗。

### 打字中與已讀

```json
{ "recipient": { "id": "<PSID>" }, "sender_action": "typing_on" }
```

`sender_action` 可填 `mark_seen` / `typing_on` / `typing_off`。
**與 `message` 互斥**，要分兩次請求。`typing_on` 約 20 秒後自動消失。

## 錯誤碼要分成三類處理

這是最常被做錯的地方 —— 把所有失敗一視同仁地重試，
結果每次都把配額燒在同一批永遠不會成功的對象上。

| 類別 | 錯誤碼 | 怎麼做 |
|---|---|---|
| **終局失敗** | 10（超過視窗）、190（token 失效）、200（權限不足）、551 / 2018108（使用者無法接收） | 立刻放棄。**從名單移除**，重試一百次就是失敗一百次 |
| **限流** | 613、4、HTTP 429 | 等 `X-Business-Use-Case-Usage` 的 `estimated_time_to_regain_access`（★ 單位是**分鐘**）再送同一則 |
| **暫時性** | 5xx、連線斷 | 指數退避後重試 |

參考實作把這三類做成 enum（`SendOutcome`），逼呼叫端面對差別，
而不是回一個 bool 讓人隨手忽略。

## 粉專貼文與留言

| 動作 | 端點 |
|---|---|
| 發貼文 | `POST /{page-id}/feed` `{message, link}` |
| 公開回覆留言 | `POST /{comment-id}/comments` `{message}` |
| 私訊回覆留言 | `POST /{comment-id}/private_replies` `{message}` |
| 隱藏留言 | `POST /{comment-id}` `{is_hidden: true}` |

留言事件要訂閱 **`feed`** 欄位，進來的是 `entry[].changes[]`（**不是** `messaging[]`）：

```json
{ "field": "feed",
  "value": { "item": "comment", "verb": "add", "comment_id": "...",
             "post_id": "...", "from": { "id": "...", "name": "..." },
             "message": "請問有現貨嗎？" } }
```

- **一定要過濾掉粉專自己的留言**（`value.from.id === entry.id`），否則自動回覆會遞迴。
- `verb` 有 `add` / `edited` / `remove`，通常只處理 `add`。
- `private_replies` **每則留言只能用一次**，第二次回錯誤碼 10903。

⚠️ `messaging` 和 `changes` 是**兩個不同的結構**，同一個 webhook URL 兩種都會進來。
處理時要先看哪個 key 存在，不要假設。

## Instagram

商業帳號的 DM 與留言走同一套 Graph API，差別：

- webhook 的 `object` 是 `"instagram"` 而不是 `"page"`
- 端點用 `/{ig-user-id}/messages`，權限要 `instagram_manage_messages`
- 沒有 `private_replies`，留言只能公開回或用 DM

## 症狀對照表

| 症狀 | 原因 |
|---|---|
| 後台「驗證權杖」一直失敗 | GET 沒把 `hub.challenge` 原樣純文字回傳；或服務還沒上線 |
| 簽章驗證時好時壞 | 用重新序列化的 JSON 算 HMAC，不是原始位元組 |
| 自己測有反應、別人沒有 | App 還在開發模式，沒過 App Review |
| bot 跟自己無限對話 | 沒過濾 `message.is_echo` |
| `#10 ... outside of allowed window` | 超過 24 小時視窗，要用 message tag |
| `#200 permissions` | 用成 User Access Token，或缺 `pages_messaging` |
| `#190 token expired` | 用了短期 token，要換長期 Page Access Token |
| webhook 突然全部不進來 | 連續回非 200 被 Meta 停用，回後台重新訂閱 |
| 同一則訊息處理兩次 | 20 秒內沒回 200 導致重送 → 用 `mid` 去重，並「先回 200 再做事」 |

## 怎麼測而不花錢、不連外網

把 HTTP 出口抽成介面，測試時換成假的，然後**斷言真正送出去的 wire payload**：

```php
$transport = new FakeTransport();
$channel = new MessengerChannel('PAGE-TOKEN', 'app-secret', 'verify-me', $transport);
$channel->send('PSID-1', '您好');

assertSame([
    'recipient' => ['id' => 'PSID-1'],
    'messaging_type' => 'RESPONSE',        // ← 漏掉這個從回傳值看不出來
    'message' => ['text' => '您好'],
], $transport->lastBody());
```

`FakeTransport::queue()` 可以排一串回應，模擬「429 → 等待 → 重試 → 成功」這種序列。
本專案的 `tools/test_channels.php` 有 53 項這樣的測試，不需要任何憑證。

## 平台速查

`references/messenger-api.md` —— 端點、訊息模板（quick replies、button template、
attachment）、`messaging_type` 與 tag、完整錯誤碼表、頻率限制的計算方式。
