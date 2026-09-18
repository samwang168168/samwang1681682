# 客服系統：Gemini → Claude 遷移

把「每次把系統提示詞 + 公司資料 + 客戶資料 + 問題一次性送進去」的客服架構
從 Gemini 搬到 Claude，並用 prompt caching 把重複送的部分降到 0.1 倍價錢。

**你現在的無狀態做法是對的，不用改。** 每次送完整 payload、不累積對話歷史，
正是 Claude 上最推薦的客服寫法。要換掉的只是「不變的資料每次全額計費」這件事。

---

## 核心：三層快取分層

Claude 的快取是**位元組層級的前綴比對**，渲染順序固定為 `tools` → `system` → `messages`。
把內容照「變動頻率由低到高」排，在每個變動邊界打一個斷點：

| 位置 | 內容 | 變動頻率 | 斷點 | 命中範圍 |
|---|---|---|---|---|
| `system[0]` | 系統提示詞 | 幾乎不變 | ✅ | 全體 |
| `system[1]` | 公司共用知識庫 | 偶爾更新 | ✅ | **全體客戶共用** ★ |
| `messages[0].content[0]` | 客戶專屬資料 | 每個客戶不同 | ✅ | 該客戶 |
| `messages[0].content[1]` | 客人這次的問題 | 每次都不同 | ❌ | — |

**斷點 2 是省最多錢的地方**：公司知識庫獨立成一層，所有客戶讀同一份快取。
只要五分鐘內有任何流量，它就一直是熱的。

問題那一塊**不打斷點**是刻意的 —— 在每次都不同的內容上打斷點，等於每次都付
1.25 倍去寫一份永遠沒人讀的快取，是純虧損。

### 成本

公司資料 50K token、客戶資料 5K、問題 200：

| | 每次請求的 input | 相對成本 |
|---|---|---|
| 現在（全額） | 55.2K | 100% |
| 命中快取後 | 等效 ≈ 5.7K | **約 10%** |

第一個 token 吐出來的時間也會明顯變快。

---

## 安裝

```bash
composer install
```

`guzzlehttp/guzzle` 是必要的 —— Anthropic PHP SDK 需要一個 PSR-18 HTTP client，
沒裝的話 `new Client(...)` 會直接拋 `DiscoveryFailedException`。

---

## 兩步遷移

### 第一步：接上相容層（呼叫端幾乎不用改）

既有的 Gemini payload 直接丟進來：

```php
use App\Claude\GeminiCompat;

$reply = GeminiCompat::send($client, $geminiRequest, [
    'model' => 'claude-opus-5',
    'promoteToSystem'   => [0],  // contents[0].parts[0] 是公司共用知識 → 提到 system 層
    'customerDataParts' => [1],  // contents[0].parts[1] 是客戶專屬資料 → 原地打斷點
    'effort' => 'medium',
]);
```

兩個索引指的都是**原始 payload** 裡 `contents[0].parts` 的位置。
沒指定的 part（問題）不打斷點。

完整範例：`examples/migrate_from_gemini.php`

### 第二步：換成原生三層寫法

```php
use App\Claude\ClaudeCustomerService;

$service = new ClaudeCustomerService(
    client: $client,
    systemPrompt: $systemPrompt,        // 凍結
    companyKnowledge: $companyKnowledge, // 全體共用，array 會走決定性序列化
    effort: 'medium',
);

$reply = $service->ask(
    customerId: 'CUST-88123',
    question: '可以退貨嗎？',
    customerData: ['會員等級' => '金卡', '最近訂單' => [...]],
);
```

完整範例：`examples/basic.php`

---

## API 對照表

| Gemini | Claude | 注意 |
|---|---|---|
| `systemInstruction` | 頂層 `system` | |
| `contents[]` / `parts[]` | `messages[]` / `content[]` | |
| role `"model"` | role `"assistant"` | |
| `maxOutputTokens` | `maxTokens` | **必填**，Claude 沒有預設值 |
| `temperature` / `topP` / `topK` | **已移除** | Opus 5 送出直接 400 ← 最常踩的雷 |
| `stopSequences` | `stopSequences` | |
| `responseMimeType: application/json` | `outputConfig.format` | 需提供 schema |
| `cachedContent`（要預建物件、自己管 TTL） | `cacheControl` inline | 不用預建、不用管生命週期 |
| `tools[].functionDeclarations[]` | `tools[]`，`parameters` → `inputSchema` | 型別要從 `"STRING"` 轉成 `"string"` |
| `safetySettings` | 無對應 | |
| `inlineData` | `image` / `document` block | block 型別要跟 MIME 對上 |

轉接層會自動處理以上全部，丟掉的參數一律記進 `GeminiTranslation::$warnings`，
不會默默吞掉。

### 關於 thinking

Opus 5 的 thinking 預設是開的。**不要用 `thinking: ['type' => 'disabled']` 來省錢** ——
關掉 thinking 有兩個已知的失效模式（模型偶爾會把 tool call 寫進可見文字而不是
`tool_use` block，且可能洩漏 `<thinking>` 標籤）。要控制成本請改用 `effort`，
本專案預設 `medium`。

`effort` 必須**固定在建構子**，不要每次請求換 —— 改 effort 會讓 messages 快取失效。

---

## 三個無聲快取殺手

快取失效**不會報錯**，只有帳單會變貴。這三個是最常見的：

1. **動態值進了 system prompt** —— `"今天是 {$date}"`、客戶名字、session id。
   前綴一變，它後面全部重算，而且每個客戶各自持有一份快取，跨客戶完全無法共用。
   動態的東西一律往後放。
2. **非決定性序列化** —— PHP 的 `json_encode` 照插入順序輸出，同一筆資料從
   DB / Redis 來的鍵序不同就產生不同 bytes。用 `Prompt::canonicalJson()`（遞迴 ksort
   + `JSON_UNESCAPED_UNICODE`）。
3. **tools 列表順序不固定或因客戶而異** —— tools 渲染在位置 0，順序一變整個快取全毀。
   轉接層會照名稱排序。

`ClaudeCustomerService` 內建 `guardPrefix()`，偵測到被快取的 system 層在
process 生命週期內變動就會發出警告。

---

## 驗證

```bash
# 不花錢、不連外網：對本機 mock server 驗證真正送出去的 wire payload
php tools/verify_layout.php

# 花兩次請求：對真實 API 驗證快取確實命中
ANTHROPIC_API_KEY=sk-ant-... php tools/verify_cache.php
```

`verify_layout.php` 會攔下實際的 HTTP body，斷言斷點擺在該擺的位置、
角色與型別對應正確、斷點數沒超過每次請求 4 個的上限。

上線後真正的 ground truth 是 `usage.cache_read_input_tokens`：

```php
$reply->cacheReadTokens;   // 走快取的 token，愈大愈好
$reply->cacheHitRatio();   // 這次 prompt 有多少比例是 0.1 倍價錢買到的
$service->cacheStats();    // 累計，適合掛在 metrics 端點上
```

連續幾次請求都是 0 就代表有隱形失效點。**建議做成常駐監控或 CI 斷言**，
而不是只看一次 —— 最貴的快取故障都是「當初會動，後來某次改動讓它靜靜失效好幾個月」。

注意 `inputTokens` 只是「沒命中快取的殘餘」，不是 prompt 總量。
總量要看 `totalPromptTokens()`。

---

## 通訊軟體通道：Telegram / Facebook

客服後端做好之後，訊息要從哪裡進來？`src/Channel` + `src/Flow` 是**自己實作**的
一套通道層，不依賴 n8n、Zapier 或任何外部自動化平台。
零第三方套件（只用 ext-curl / ext-hash），沒裝 composer 也跑得起來。

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

`Channel` 介面只有五個動作，上層流程完全不必知道訊息從哪個平台來 ——
要加 LINE / WhatsApp / Discord 就是再實作一個類別，flow 一行都不用改。

### 跑起來

```bash
# 兩個通道共用一條 flow
ANTHROPIC_API_KEY=sk-ant-... \
TELEGRAM_BOT_TOKEN=123:AA... TELEGRAM_WEBHOOK_SECRET=$(openssl rand -hex 32) \
PAGE_ACCESS_TOKEN=EAA... META_APP_SECRET=... META_VERIFY_TOKEN=... \
  php -S 0.0.0.0:8080 -t examples

# 廣播（--dry-run 不連外網，示範三種結果的差異）
php examples/broadcast.php --dry-run
```

平台後台填的 webhook 網址：

```
Telegram   https://你的網域/channel_server.php/telegram
Messenger  https://你的網域/channel_server.php/messenger
```

### 快取分層在這裡一樣成立

`systemPrompt` 與 `companyKnowledge` 是凍結的兩層，通道別（telegram / messenger）
走 `turnInstruction`，不會動到已快取的前綴 ——
所以 **Telegram 與 Messenger 的流量讀的是同一份公司知識庫快取**。

### 六個一定會踩到的坑

都已經處理掉了，改動時不要不小心弄壞：

1. **Telegram 要用 `chat.id`，不是 `from.id`** —— 群組裡用錯會把回覆私訊給發話者。
2. **Messenger 的 `is_echo` 一定要濾掉** —— 那是自己送出去的回音，不濾掉 bot 會跟自己無限對話。
3. **驗簽必須對原始位元組算** —— 先 decode 再 encode 回去的 HMAC 對不上，
   而且是「有中文就壞、純英文就好」這種時好時壞的症狀。
4. **webhook 一定會重送** —— Meta 連續收不到 200 還會直接停用你的 webhook。
   去重少了，使用者收到兩次回覆，而你付兩次 API 錢。
5. **403 和 429 的處理完全相反** —— 403 是被封鎖（要從名單移除），429 是限流（要等再送）。
   混在一起會讓每次廣播都把配額燒在同一批封鎖名單上。
6. **先回 200 再做事** —— 平台的逾時遠比 LLM 的回應時間短。

### 驗證

```bash
php tools/test_channels.php
```

53 項，不花錢、不連外網、不需要 composer。它斷言的是**真正送出去的 wire payload**
而不只是回傳值 —— 通訊軟體整合最常見的錯是「body 少一個必填欄位」，
那種錯從回傳值看一切正常，只有看 wire payload 才抓得到。

更多細節在 `.claude/skills/messaging-channels/`。
平台速查與 OpenClaw 的兩個研究用 agent 在 `openclaw/`（見該目錄的 README）。

---

## 什麼時候才需要 RAG / tool use

只有在公司資料超過 200K token、或大部分內容跟多數問題無關時才值得。
代價是多一輪 round trip（延遲 +1~2 秒），而客服對延遲敏感。

**先做 caching，量出數字再決定。**

---

## 檔案

```
src/ClaudeCustomerService.php  三層分層的核心 client
src/GeminiCompat.php           Gemini → Claude 轉接層
src/GeminiTranslation.php      轉換結果（params + warnings）
src/Reply.php                  回覆 + 快取計費明細
src/Prompt.php                 決定性序列化
examples/basic.php             原生三層寫法
examples/migrate_from_gemini.php  相容層遷移
tools/verify_layout.php        wire payload 驗證（免費）
tools/verify_cache.php         真實 API 快取驗證

通訊軟體通道（自建，零第三方套件）
src/Channel/                   平台轉接：Channel 介面 + Telegram / Messenger 實作
src/Flow/                      流程引擎：Flow + Step + 現成的 6 個 step
src/Store/                     跨 process 的小狀態（去重、限流）
src/Http/                      最小 HTTP 出口（FakeTransport 供測試用）
examples/channel_server.php    正式入口：兩個通道共用一條 flow
examples/broadcast.php         分批廣播（--dry-run 可離線試跑）
tools/test_channels.php        53 項自我測試（免費、離線、不需 composer）

.claude/skills/
  messaging-channels/          通道層用法（給 Claude Code 讀）

openclaw/                      OpenClaw 的 fb / tg 兩個研究用 agent
  install.sh                   建立 agent 並把 skill 放進各自工作區
  agents/fb/skills/fb-messenger/    Meta 整合要點 + 平台速查
  agents/tg/skills/telegram-bot/    Telegram 整合要點 + 平台速查
```
