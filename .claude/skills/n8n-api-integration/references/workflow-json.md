# n8n workflow JSON 結構

手寫或改 workflow JSON 時的對照表。n8n 1.x。

## 頂層

```json
{
  "name": "工作流程名稱",
  "nodes": [ ... ],
  "connections": { ... },
  "settings": { "executionOrder": "v1" },
  "pinData": {}
}
```

- `settings.executionOrder` 一定要 `"v1"`。舊的 `v0` 在多分支時的執行順序不保證,
  會出現「看起來隨機」的行為。
- `active`、`id`、`versionId`、`meta` 這些欄位可以不寫,匯入時 n8n 會自己補。
  **匯入不會自動啟用 workflow**,要手動按 Active。

## 節點

```json
{
  "parameters": { },
  "id": "a1b2c3d4-0000-4000-8000-000000000001",
  "name": "顯示名稱",
  "type": "n8n-nodes-base.httpRequest",
  "typeVersion": 4.2,
  "position": [460, 300],
  "credentials": { "telegramApi": { "id": "1", "name": "Telegram account" } },
  "onError": "continueRegularOutput",
  "retryOnFail": true,
  "maxTries": 3,
  "waitBetweenTries": 2000
}
```

- `id` 必須是 UUID 格式且在同一個 workflow 內唯一。
- `name` 是 expression 裡 `$('節點名稱')` 用的 key,**改名字會讓引用它的
  expression 全部失效**,n8n 不會幫你改。
- `position` 只影響畫面排版,`[x, y]`,間距抓 220 左右比較好看。
- `typeVersion` 寫低於實際安裝版本沒關係（n8n 會用舊行為跑）,寫高於就會壞。
  不確定就在 UI 拉一個同類節點、匯出來看它寫幾。
- `onError` 可填 `stopWorkflow`(預設) / `continueRegularOutput` / `continueErrorOutput`。

## connections

key 是**來源節點的 name**,不是 id：

```json
{
  "Webhook": {
    "main": [
      [ { "node": "驗證簽章", "type": "main", "index": 0 } ]
    ]
  },
  "IF 是文字訊息": {
    "main": [
      [ { "node": "true 分支", "type": "main", "index": 0 } ],
      [ { "node": "false 分支", "type": "main", "index": 0 } ]
    ]
  }
}
```

外層陣列 = 來源節點的第幾個輸出（IF 是 `[true, false]`,Switch 依規則數量）。
內層陣列 = 這個輸出接到的多個目標,可以接好幾個。

## 常用節點的參數長相

### Webhook (`n8n-nodes-base.webhook`, typeVersion 2)
```json
{
  "httpMethod": "POST",
  "path": "messenger",
  "responseMode": "responseNode",
  "options": { "rawBody": true }
}
```
同一節點另需頂層 `"webhookId": "<uuid>"`。

### Respond to Webhook (`n8n-nodes-base.respondToWebhook`, typeVersion 1)
```json
{
  "respondWith": "text",
  "responseBody": "={{ $json.query['hub.challenge'] }}",
  "options": { "responseCode": 200 }
}
```
`respondWith` 可填 `text` / `json` / `noData` / `binary` / `allIncomingItems`。

### HTTP Request (`n8n-nodes-base.httpRequest`, typeVersion 4.2)
```json
{
  "method": "POST",
  "url": "https://graph.facebook.com/v23.0/me/messages",
  "sendQuery": true,
  "queryParameters": { "parameters": [ { "name": "access_token", "value": "={{ $env.PAGE_ACCESS_TOKEN }}" } ] },
  "sendBody": true,
  "specifyBody": "json",
  "jsonBody": "={{ JSON.stringify({ recipient: { id: $json.psid }, message: { text: $json.reply } }) }}",
  "options": { "timeout": 20000, "response": { "response": { "neverError": true, "fullResponse": true } } }
}
```
`neverError: true` + `fullResponse: true` 會把 4xx/5xx 當正常資料往下流,
並附上 `statusCode` 與 `headers` —— 要讀 `Retry-After` 或 `retry_after` 時必開。

### Code (`n8n-nodes-base.code`, typeVersion 2)
```json
{ "mode": "runOnceForAllItems", "jsCode": "return $input.all();" }
```
- `runOnceForAllItems`（預設）：`$input.all()` 拿全部,回傳 `[{json:{...}}, ...]`。
- `runOnceForEachItem`：用 `$input.item`,回傳單一 `{json:{...}}`。
- 回傳值**一定**要是 `{json: ...}` 包起來的,直接回裸物件會報錯。

### IF (`n8n-nodes-base.if`, typeVersion 2)
```json
{
  "conditions": {
    "options": { "caseSensitive": true, "leftValue": "", "typeValidation": "strict", "version": 2 },
    "conditions": [
      {
        "id": "cond-1",
        "leftValue": "={{ $json.message.text }}",
        "rightValue": "",
        "operator": { "type": "string", "operation": "exists", "singleValue": true }
      }
    ],
    "combinator": "and"
  },
  "options": {}
}
```
`typeValidation: "strict"` 下,拿 `undefined` 去比字串會**報錯而不是判 false**。
輸入可能缺欄位時,改 `"loose"`,或先用 Code node 正規化。

### Split In Batches / Loop Over Items (`n8n-nodes-base.splitInBatches`, typeVersion 3)
```json
{ "batchSize": 20, "options": {} }
```
輸出 0 = `done`（跑完全部之後）、輸出 1 = `loop`（每一批）。
**順序跟直覺相反**,接錯線會變成一批都沒送就跳到結尾。

### Wait (`n8n-nodes-base.wait`, typeVersion 1.1)
```json
{ "amount": 1, "unit": "seconds" }
```

### Telegram Trigger (`n8n-nodes-base.telegramTrigger`, typeVersion 1.1)
```json
{ "updates": ["message"], "additionalFields": {} }
```

### Telegram (`n8n-nodes-base.telegram`, typeVersion 1.2)
```json
{
  "chatId": "={{ $json.chatId }}",
  "text": "={{ $json.text }}",
  "additionalFields": { "parse_mode": "HTML" }
}
```
`resource` 預設 `message`、`operation` 預設 `sendMessage`,用預設值時可以省略。

## Expression 語法

- `={{ ... }}` —— 值以 `=` 開頭才會被當成 expression,少打這個等號是最常見的錯。
- `$json` 當前 item、`$binary` 當前 item 的 binary。
- `$('節點名稱').first().json.x` —— 引用其他節點的輸出。
- `$('節點名稱').item.json.x` —— 引用**對應同一筆 item** 的輸出（有配對關係時用這個）。
- `$env.NAME` 環境變數、`$now` Luxon DateTime、`$execution.id`。
- JSON body 用 `"={{ JSON.stringify({...}) }}"` 一次組好,比在 UI 裡拉 key-value 好維護,
  也不會被 n8n 的型別推斷弄成字串 `"123"`。

## 匯入後的檢查清單

1. 每個有 `credentials` 的節點 → 重新選一次憑證（JSON 裡的 id 是別人實例的）。
2. Webhook 節點 → 複製 **Production URL**,填回平台後台。
3. `$env.*` 用到的變數 → 在 n8n 環境裡設好。
4. 按 **Active**（匯入不會自動啟用）。
5. 先用測試 URL 打一筆假資料,確認 Executions 有紀錄再上線。
