---
name: n8n-api-integration
description: n8n 串接外部 API 的共用地基 —— webhook 接收與回應、credential 管理、重試與退避、冪等去重、workflow JSON 手寫格式、失敗除錯。當使用者要在 n8n 建 workflow、接 webhook、串 REST API、匯入/手寫 workflow JSON，或遇到 n8n 執行失敗、重複觸發、憑證錯誤時使用。Telegram 與 Facebook 的細節在 n8n-telegram / n8n-facebook skill。
---

# n8n API 串接地基

這支 skill 管的是「所有 n8n 外部串接都會踩到的那幾件事」。平台專屬的欄位與限制
交給 `n8n-telegram` 和 `n8n-facebook`。

## 先決定：Trigger node 還是 Webhook node

| | Trigger node（Telegram Trigger、Facebook Trigger…） | Webhook node |
|---|---|---|
| 註冊 webhook | n8n 自動幫你呼叫平台的 setWebhook | 你自己去平台後台填 URL |
| 簽章驗證 | 拿不到 raw body，**無法**自己驗 HMAC | 可以開 Raw Body 自己驗 |
| 回應內容 | 固定 200 空白 | 可自訂 status / header / body |
| 適用 | Telegram、快速原型 | Meta（要驗 `X-Hub-Signature-256`）、要回 challenge、要自訂回應 |

**判斷法則**：平台要求你「驗簽章」或「回傳特定 body」就用 Webhook node，其餘用 Trigger node。
Meta 兩件都要（GET 回 `hub.challenge`、POST 驗 HMAC），所以 Facebook 一律走 Webhook node。

## Webhook node 的四個必設項

```json
{
  "httpMethod": "POST",
  "path": "messenger",
  "responseMode": "responseNode",
  "options": { "rawBody": true }
}
```

1. **`path`** —— 最終 URL 是 `{N8N_HOST}/webhook/{path}`（production）與
   `{N8N_HOST}/webhook-test/{path}`（測試）。**兩個 URL 不一樣**，這是最常見的
   「在 n8n 裡按 Execute 有反應、上線後沒反應」原因：測試 URL 只在你按下
   *Listen for test event* 後活一次，workflow 沒 **Active** 的話 production URL 是 404。
2. **`responseMode`**
   - `onReceived` —— 立刻回 200,後面慢慢跑。平台有逾時限制時用這個。
   - `lastNode` —— 等 workflow 跑完，回最後一個節點的資料。
   - `responseNode` —— 由 `Respond to Webhook` 節點決定 status/body。要回 challenge 或自訂錯誤碼時用。
3. **`rawBody`** —— 要驗 HMAC 簽章就**必須**開。原因見下方。
4. **HTTP Method** —— 一個 Webhook node 只吃一個 method。GET 驗證 + POST 收訊息 = 兩個節點、同一個 path。

### rawBody：驗簽章唯一正確的做法

HMAC 是對**原始位元組**算的。n8n 預設會把 body 解析成 JSON 物件，
之後你再 `JSON.stringify()` 回去，空白、鍵序、Unicode escape 都可能跟原文不同 ——
簽章就對不起來，而且是**時好時壞**，最難查的那種。

開了 `rawBody` 之後，body 以 base64 binary 進來：

```js
// Code node，mode: Run Once for All Items
const item = $input.first();
const raw = Buffer.from(item.binary.data.data, 'base64');   // ← 原始位元組
const signature = $('Webhook').first().json.headers['x-hub-signature-256'];

const crypto = require('crypto');
const expected = 'sha256=' + crypto.createHmac('sha256', $env.META_APP_SECRET)
  .update(raw)
  .digest('hex');

// 一定要用 timingSafeEqual，不要用 ===
const ok = signature
  && signature.length === expected.length
  && crypto.timingSafeEqual(Buffer.from(signature), Buffer.from(expected));

if (!ok) throw new Error('簽章驗證失敗');

return [{ json: JSON.parse(raw.toString('utf8')) }];
```

`require('crypto')` 需要在 n8n 設定 `NODES_EXCLUDE` 沒擋掉、且
`NODE_FUNCTION_ALLOW_BUILTIN=crypto`（self-host 時）。n8n Cloud 預設允許。

## Credential 不要寫在節點裡

| 放哪 | 用途 | 匯出 workflow JSON 時 |
|---|---|---|
| Credential（n8n 的憑證管理） | API key、token | 只留 id + name，**值不會匯出** |
| `$env.XXX` | 非憑證的設定值（後端 URL、app secret） | 不會匯出 |
| 節點參數寫死 | ❌ 絕對不要 | 明文進 git |

self-host 要讓 Code node 讀得到 `$env`，得設 `N8N_BLOCK_ENV_ACCESS_IN_NODE=false`。
n8n Cloud 讀不到任意環境變數,改用 Credential 或 workflow static data。

**匯入別人的 workflow JSON 後,每個有 credential 的節點都要重新選一次憑證** ——
JSON 裡的 credential id 是對方實例的,在你這邊不存在。

## 重試、退避、冪等

### 節點層的重試
每個節點的 Settings 裡有：

```json
{ "retryOnFail": true, "maxTries": 3, "waitBetweenTries": 2000 }
```

這是**固定間隔**重試,對付瞬時 5xx 夠用。但**不會讀 `Retry-After`** ——
遇到 429 要自己處理（見下）。

### 429：一律照對方給的秒數等
所有主流 API 被限流時都會告訴你要等多久,位置各不同：

| 平台 | 等待秒數在哪 |
|---|---|
| Telegram | body 的 `parameters.retry_after` |
| Meta Graph API | header `X-Business-Use-Case-Usage` 的 `estimated_time_to_regain_access`（分鐘） |
| 一般 REST | header `Retry-After` |

HTTP Request 節點設 `onError: "continueRegularOutput"`,讓錯誤變成資料往下流,
再用 IF 判斷 status,接 `Wait` 節點（`resume: "timeInterval"`）等完再繞回去重送。
**不要用 Code node 裡的忙碌迴圈等**,那會佔住 worker。

### 冪等：webhook 一定會重送
Telegram 沒收到 200 會重送同一個 `update_id`；Meta 沒在 20 秒內收到 200 會重送,
連續失敗還會直接停用你的 webhook。所以**同一則訊息被送兩次是正常狀態,不是例外**。

去重的 key：

| 平台 | 唯一 key |
|---|---|
| Telegram | `update_id`（全 bot 唯一、遞增） |
| Messenger | `entry[].messaging[].message.mid` |
| 一般 | 對方給的 event id,沒有就 hash(sender + timestamp + 內容) |

最小做法是用 workflow static data 存最近看過的 id：

```js
// Code node
const staticData = $getWorkflowStaticData('global');
staticData.seen = staticData.seen || [];

const out = [];
for (const item of $input.all()) {
  const id = String(item.json.update_id);
  if (staticData.seen.includes(id)) continue;   // 重送,丟掉
  staticData.seen.push(id);
  out.push(item);
}
staticData.seen = staticData.seen.slice(-500);  // 別無限長大
return out;
```

⚠️ static data 只在 workflow 執行結束後才寫回,而且**多 worker 的 queue mode 下不共享**。
正式環境流量大就改用 Redis `SET key NX EX 3600`,或後端資料庫的 unique index。

### 慢處理一律「先回 200,再做事」
平台的逾時遠比 LLM 的回應時間短（Meta 20 秒、Telegram 約 60 秒）。
如果後面要呼叫 Claude、查 DB、跑好幾個 API：

```
Webhook (responseMode: onReceived)  →  去重  →  慢慢做  →  主動呼叫平台的 send API 回訊息
```

而不是把回覆塞在 webhook 的 response body 裡。Telegram 支援後者（在回應裡直接
帶 `method: "sendMessage"`）,但一逾時就整個訊息掉了,不值得。

## 手寫 / 修改 workflow JSON

格式與逐欄位說明在 `references/workflow-json.md`。修改完**一定要先驗證再匯入**：

```bash
node .claude/skills/n8n-api-integration/scripts/lint-workflow.mjs path/to/workflow.json
```

會檢查 JSON 合法性、節點名稱唯一、`connections` 指到的節點都存在、
webhook path 有沒有撞名、以及有沒有把 token 寫死在參數裡。

## 除錯順序

照這個順序查,九成的問題在前三步就解決：

1. **Executions 分頁** —— 有沒有執行紀錄？
   - 完全沒有 → webhook 根本沒進來。查 workflow 是不是 **Active**、
     用的是不是 production URL、平台後台的 URL 有沒有填錯。
   - 有但紅色 → 點進去看是哪個節點爆,看該節點的 Input/Output 分頁。
2. **節點的 Output 分頁** —— 資料長得跟你以為的一樣嗎？
   平台常把 payload 包一層陣列（Meta 是 `entry[].messaging[]`）,
   expression 少一層 index 就會拿到 `undefined`。
3. **Expression 預覽** —— 在欄位裡打 `{{ }}` 時下方會即時顯示求值結果。
   顯示 `[undefined]` 就是路徑錯了,不要等執行才發現。
4. **Settings → Error Workflow** —— 指到一個接 `Error Trigger` 的 workflow,
   把失敗推到 Slack / email。沒設這個,半夜掛掉不會有人知道。
5. **手動重放** —— 在失敗的 execution 上按 *Retry*,或複製它的 JSON 到
   Webhook 節點的 pin data,不必叫對方重發一次訊息就能重現。

## 相關檔案

- `references/workflow-json.md` —— workflow JSON 結構、常用節點的參數長相
- `scripts/lint-workflow.mjs` —— 匯入前驗證
