# Jev（TypeSafe）研究筆記

> 快照日期：**2026-09-20**。官方站 `docs.typesafe.ai` 在本次研究的網路環境被 egress proxy 擋住，
> 因此下列事實以社群整理、第三方 SDK 相容實作與公開 benchmark 為主要來源（文末列出）。
> 價格、限流、模型別名都可能隨時改動，**以官方 console / docs 為準**。

---

## TL;DR：它到底是什麼東西

Jev 是 TypeSafe 的第一個「System One 模型」：**它不生成文字**。你給它一段 state（字串或 JSON），
再給它一組**答案空間由你事先定義**的問題，它回傳型別化的答案 + 機率分佈 + confidence。

```text
text / JSON state + typed questions → constrained answers + probabilities → 你的程式碼分支
```

三個 primitive：

| 型別 | 你定義什麼 | 它回什麼 | 適用 |
|---|---|---|---|
| `Choice` | 一組互斥選項（最多 255 個），每個可附說明 | 選中的 `choice`、每個選項的機率、`confidence` | 意圖、部門、文件類型、要呼叫哪個 tool、點畫面上哪個元素 |
| `Score` | 一條有序的 rubric（2–10 級），每級寫清楚意思 | `score`（機率加權，可能落在級距之間）、`legend`、機率、`confidence` | 嚴重度、相關性、品質、急迫度 |
| `Noul` | 一句敘述 | `noul`：0–1 的機率 | 「這段話有沒有支持這個 claim」「這是不是 prompt injection」 |

`Noul` 接近 0.5 **代表不確定，不是「中等分數」**——要中等分數請用 `Score`。

### 那個「型別上不可能」的保證，到底保證了什麼

它保證**答案一定落在你列的清單內**——不是機率低，是結構上不可能吐出清單外的東西，
也不會有 JSON 格式跑掉、多一個你沒定義的分類這種事。

它**不保證**選中的那一個是對的。calibration 描述的是「一群預測」的統計性質，
不是任何單一答案的正確性。官方文件自己也這樣寫。

### 為什麼「餵背景資料」會讓它從自信答錯變成自信答對

跟你實測到的現象一致（同一題：無背景 0.90 信心答錯 → 給背景段落後 0.97 信心答對）。

它強在「答案就寫在你餵進去的文字裡」的任務：分類、判斷兩段文字關不關聯、抓語意層矛盾。
它弱在「需要它自己具備、而你沒給的知識」，而且**弱得很有自信**——因為 confidence 是從機率分佈
的形狀推出來的（分佈很尖 = 高 confidence），跟「它有沒有這個知識」無關。

**實務結論：把 Jev 當成 reader，不要當成 knowledge base。** 知識要嘛寫進 state，
要嘛先用檢索補進來。

---

## 兩個常被問的問題

### Q1：是什麼東西都可以嗎？

**不可以。** 它只能做「從你給的答案空間裡挑一個 / 打個分 / 給一個 0–1 機率」的窄判斷。

它不是、也做不到：

- 不是 chatbot、不寫文案、不寫程式、**不解釋自己為什麼這樣選**
- 不回傳任意字串——答案空間永遠來自你定義的問題
- 目前**只吃文字**（字串、JSON 物件、字串陣列），不吃圖片 / 音訊 / 影片
- 不能取代授權檢查、決定性驗證、政策執行與人工覆核
- 需要外部知識的題目會出包，且信心值不會警告你

需要自由文字時的正確用法是**搭配**：讓 Jev 做路由 / 檢索過濾 / 驗證 / 守門，
再讓 LLM 在你程式碼劃定的邊界內去寫。

### Q2：安裝在主機嗎？

**不行。Jev 是封閉的 managed API，沒有開放權重，沒有 self-host / VPC / on-prem 路徑，沒有東西可以下載。**

```text
POST https://api.typesafe.ai/v1/systemone
Authorization: Bearer $TYPESAFE_API_KEY
```

官方 SDK：`pip install typesafe-sdk` / `npm install @typesafe-ai/sdk`，
都會讀環境變數 `TYPESAFE_API_KEY`，預設模型別名 `jev-latest`。發文當下仍是 early access（console 排隊發 key）。

**如果你真的需要跑在自己機器上**，社群有一個 drop-in 替代品 `jeff`：
用 GLiFormer（400M，encoder-based）實作同一套 wire format，把 `TYPESAFE_BASE_URL` 指過去就能用官方 SDK。
代價是準確率掉很多：

| | jeff（自架 L4） | jev（官方 API） |
|---|---:|---:|
| AG News 主題分類準確率 | 75.5% | 90.5% |
| JevBench v1.2.2 總分（18 名中） | 66.9（#9） | 75.3（#2） |
| JevBench Intelligence | 63.9 | 90.4 |
| 每 100 萬次單問題請求成本 | ~$2.6 | ~$15.6 |
| 序列 p50 延遲 | 151 ms | 129 ms |

也就是說：**自架省的是錢，付出的是智力**，而且 jeff 在 reasoning-heavy（反諷、閱讀理解、judge/hard tier）掉最兇。
你要的「語義層矛盾偵測」剛好落在它掉最兇的那一區，所以拿 jeff 做 PoC 會低估 Jev。

---

## 規格速查（社群快照，會變）

| 項目 | 值 |
|---|---|
| Endpoint | `POST https://api.typesafe.ai/v1/systemone` |
| 模型別名 / 版本 | `jev-latest` / `jev-1.13.0` |
| 輸入 | 字串、JSON 物件、或字串陣列（32K context） |
| 價格 | 輸入 `$0.042 / 1M` tokens；**輸出免費**（本來就沒有輸出 token） |
| 限流 | 250,000 tokens/s、1,200 requests/min（官方說會動態調整） |
| 延遲 | 官方宣稱 70–500 ms end-to-end；第三方實測 p50 ≈ 129 ms |
| 模態 | 純文字 |

一個請求可以同時問很多題，**題目之間各自獨立評估**——這就是下面 fan-out pattern 的基礎。

---

## 值得記下來的用法模式

| Pattern | 形狀 | 什麼時候好用 |
|---|---|---|
| Atomic questions | 一題只問一個判斷 | 可檢查、可追溯的流程邏輯 |
| **Speculative fan-out** | 把「可能會用到」的問題一次全問完，程式碼再忽略用不到的答案 | 客服分流、瀏覽器/UI 操作、agent 路由 |
| Confidence-gated routing | 把「答案」跟「信心」當兩個獨立的軸 | 自動化 + 人工 fallback |
| Composite scoring | 多個 Score 正規化後用明確權重合成 | 排序、風險評分 |
| Intent routing | 決定走死 code、走專用 LLM、還是走人 | 控成本控延遲 |
| Two-stage dependency | 第一題的答案會改變下一輪的 state 或選項時才發第二次 | 階層式分類 |

核心設計原則一句話：**問題只描述判斷；組合、門檻、副作用全部留在程式碼裡。**

你提到的「瀏覽器自動化裡畫面上這些元素該點哪個」正是 fan-out + Choice 的教科書案例：
候選元素雖多但內容都在 state 裡給齊了，一次問完，程式碼拿 choice + confidence 決定點不點。

### 可靠性 checklist（照著做能少踩雷）

- 選項清單可能不完整時，**一定**加 `other` / `unknown` / `review`
- 門檻按「動作風險」分別設定，不要全系統共用一個 threshold
- 低 confidence 當成第一級分支處理（問清楚 / fallback / 轉人工），不是當成失敗
- state schema、問題 instructions、criteria、政策程式碼要**一起版本化**
- log 回應裡的**版本化模型字串**，不要只記你送出去的別名
- 存整個機率分佈，不要只存贏的那個 label——之後校準門檻要用
- 429 / 529 用 SDK 預設 retry 或自己做 exponential backoff
- 副作用一律做成「檢查 → 核可 → 執行」的第二步

---

## 為什麼它可能重塑 Multi-Agent 的配置（我的看法）

現在 agent 系統裡那一層「類狀態機的語義理解判斷模組」，通常是**規則狀態機 + 一顆反應快的小模型**拼出來的：
死板、而且還是不夠聰明。Jev 直接把這一層變成一個有型別保證、100ms 級、單次成本近乎可忽略的元件。

連帶會鬆動的幾件事：

1. **「要不要叫大模型」這個決定本身變得很便宜**——路由層可以問 20 個問題還比一次 LLM 呼叫便宜。
2. **guardrail 從 prompt 裡搬出來**變成程式碼可分支的訊號（hazard Noul + harm Score）。
3. **RAG 的 passage 過濾**可以逐段打分（相關性 / 是否支持 / 是否矛盾 / injection 風險），只把過門檻的證據餵給生成模型。
4. **AGI 想像的部件化**：一個真的能走到那裡的架構，可能是多種專精部件的組合——
   快而窄的判斷器、慢而廣的生成器、決定性的執行器——而不是一顆什麼都幹的模型。

反過來提醒自己的地方：**這一切的前提是答案空間能事先列舉。** 列不出來的問題，Jev 一題都幫不上。

---

## 來源

- [Jeff：自架 drop-in 替代品（API schema、benchmark、部署）](https://github.com/logan-markewich/jeff)
- [awesome-jev-by-typesafe：社群整理的規格速查與 pattern 集](https://github.com/24601/awesome-jev-by-typesafe)
- [JevBench](https://github.com/fstandhartinger/jevbench)
- [Jev | AI/ML API Documentation](https://docs.aimlapi.com/api-references/decision-models/typesafe/jev)
- [Jev (typesafe) · Cloudflare AI docs](https://developers.cloudflare.com/ai/models/typesafe/jev/)
- [MarkTechPost：TypeSafe AI Releases Jev](https://www.marktechpost.com/2026/09/19/typesafe-ai-releases-jev/)
- [Jev AI Reality Check: Can You Run TypeSafe's Model Locally?](https://www.modemguides.com/blogs/ai-news/jev-typesafe-reality-check-run-locally)
- 官方（本次環境無法連線，列出備查）：<https://docs.typesafe.ai/api>、<https://console.typesafe.ai/>
