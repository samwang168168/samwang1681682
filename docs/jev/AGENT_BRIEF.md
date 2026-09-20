# Jev Agent Brief — 貼給你的 Agent，讓它 30 秒上手

> 用法：新開一個 session，第一句話貼這整份檔案（或 `@docs/jev/AGENT_BRIEF.md`），
> 然後直接說你要它幫你做什麼實驗。這份刻意寫成「模型讀的」而不是「人讀的」：
> 短、全是硬約束、沒有廢話。快照 2026-09-20，規格會變。

## 你正在使用的模型

Jev（TypeSafe，System One 模型）。它**不生成文字**。它讀一個 state，回答你事先定義答案空間的問題。

- Endpoint：`POST https://api.typesafe.ai/v1/systemone`，`Authorization: Bearer $TYPESAFE_API_KEY`
- 模型別名 `jev-latest`（目前 `jev-1.13.0`）；純文字；32K context
- 價格：輸入 $0.042/1M tokens，輸出免費；限流 250k tok/s、1200 req/min
- **沒有 self-host、沒有開放權重**。要本機跑只有社群的 `jeff`（GLiFormer 400M，準確率明顯較低）

## 唯一的三種問題型別

```python
from typesafe_sdk import Choice, Noul, Score, TypeSafeClient

with TypeSafeClient() as client:            # 讀 TYPESAFE_API_KEY
    r = client.system_one(
        state={"ticket": "...", "account_tier": "business"},   # str | dict | list[str]
        questions={
            "intent": Choice(                                   # ≤255 個選項
                instructions="What is the customer's main request?",
                criteria={"refund": "想要退錢", "technical_help": "程式或整合壞掉",
                          "other": "以上都不明確符合"},          # 永遠留一個 other
            ),
            "is_urgent": Noul(instructions="有沒有明確表達時間壓力？"),   # → 0..1
            "frustration": Score(                                # 2–10 級，有序
                instructions="客戶有多不爽？",
                criteria=["平靜", "有點在意但客氣", "非常生氣"],
            ),
        },
    )

r.answers["intent"].choice          # 選中的 key
r.answers["intent"].probabilities   # 每個選項的機率
r.answers["intent"].confidence      # 0..1，來自分佈形狀
r.answers["is_urgent"].noul         # 0..1 機率（0.5 = 不確定，不是「中等」）
r.answers["frustration"].score      # 機率加權，可能落在級距之間
```

JS/TS：`@typesafe-ai/sdk`，`client.systemOne({ state, questions: { x: choice(...), y: noul(...), z: score(...) } })`。

## 硬規則（違反就會得到錯誤結論）

1. **答案一定在清單內是型別保證；選對不是。** 不要把 confidence 當正確率。
2. **知識必須在 state 裡。** 需要模型自身知識的題目，它會高信心答錯。先檢索、再提問。
3. **一題一個判斷。** 複合問題（「是不是退款而且很急」）會污染分佈。
4. **選項清單可能不完整時一定加 `other`/`unknown`/`review`。**
5. **一次請求問完所有可能用到的題（speculative fan-out）**，用不到的答案在程式碼裡忽略——
   比分多次呼叫便宜也快。題目之間獨立評估。
6. **門檻、組合、副作用寫在程式碼裡**，不要要求模型「順便決定要不要執行」。
7. 存**整個機率分佈**與回應中的版本化模型字串，之後校準要用。
8. 429/529 用 SDK 預設 retry 或 exponential backoff。
9. 不要叫它解釋、寫字、寫 code、看圖——它做不到，這不是 prompt 技巧問題。

## 它擅長 / 不擅長

| 擅長（答案在 state 裡） | 不擅長（需要 state 外的知識） |
|---|---|
| 分類、意圖、路由 | 事實問答、常識題、時事 |
| 兩段文字相關 / 支持 / 矛盾 | 需要多步推理才能得到的結論 |
| 候選項內容都給齊的挑選（例：畫面上該點哪個元素） | 答案空間列不出來的問題 |
| 相關性打分、re-rank、guardrail 訊號 | 反諷、需要文化背景的暗示（較弱） |

## 這個 repo 裡的東西

- `docs/jev/README.md` — 完整筆記、規格速查、pattern、來源
- `docs/jev/experiments/calibration_ab.py` — A/B 實驗腳本：同一批題目在「有給背景 / 沒給背景」
  兩種條件下量準確率、平均信心與 ECE（校準誤差）。用來重現「無背景 0.90 答錯 → 有背景 0.97 答對」。

## 建議的下一批實驗（挑一個開始，不要一次全做）

1. **知識 vs 閱讀**：同一批題目 ±背景段落，比較 accuracy 與 ECE → 量化「它是 reader 不是 knowledge base」。
2. **fan-out 干擾**：同一題單獨問 vs 混在 20 題裡問，答案與分佈會不會漂移。
3. **選項措辭敏感度**：criteria 只給 key vs 給一句描述，準確率差多少。
4. **`other` 逃生門**：把正確答案從選項拿掉，看它是老實選 `other` 還是硬選一個（以及 confidence 多高）。
5. **門檻校準**：在自己的標註資料上畫 confidence-vs-accuracy 曲線，訂出「自動執行 / 轉人工」兩條線。
