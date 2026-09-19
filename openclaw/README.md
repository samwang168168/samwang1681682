# OpenClaw main agent：mp3 語音回覆 + Opus 5 模型

兩件事：

1. main 這隻 agent 每次回答，除了原本的文字，**再附一段 mp3 語音**一起送出
2. main 的模型指定為 **`anthropic/claude-opus-5`**

兩者都不用寫外掛，OpenClaw 內建（Auto-TTS + per-agent model）。要動的設定路徑：

| 設定路徑 | 作用 |
|---|---|
| `tts.*` | 共用的語音 provider、金鑰、**輸出格式（mp3）** |
| `agents.entries.main.tts.auto = "always"` | 只讓 main 每則回覆都自動發語音 |
| `agents.entries.main.model` | main 用的模型：`anthropic/claude-opus-5` |
| `agents.entries.main.thinkingDefault` | `high`（Opus 5 的預設 effort） |

語音分兩層是刻意的：金鑰和格式放共用層，其他 agent 不會被一起打開。
per-agent 的區塊會 deep-merge 蓋在共用層上，後寫的贏。模型同理 ——
只覆寫 main，`agents.defaults.model` 和其他 agent 不動。

---

## 套用方式

### 方式 A：用腳本合併（建議）

```bash
php tools/openclaw_apply_agent.php --dry-run   # 先看合併後長什麼樣，不寫檔
php tools/openclaw_apply_agent.php             # 確認沒問題再寫入（會自動備份）
```

預設改的是 `~/.openclaw/openclaw.json`，只碰 `tts` 和
`agents.entries.main` 底下的 `tts` / `model` / `thinkingDefault`，
`channels`、`bindings`、`agents.defaults` 原封不動。寫入前會先存一份
`openclaw.json.bak-<時間>`。

常用參數：

```bash
--config=/path/to/openclaw.json        # 設定檔不在預設位置
--agent=support                        # 要套用的 agent id（預設 main）
--provider=openai                      # 改用 OpenAI TTS（預設 microsoft）
--model=anthropic/claude-sonnet-5      # 改用別的模型（預設 anthropic/claude-opus-5）
--skip-model                           # 只改語音，不動模型
```

如果你的設定檔是帶註解的 JSON5，腳本會拒絕改寫（解析不了就不亂動），
這時候先跑 `openclaw doctor --fix` 正規化，或改用方式 B。

### 方式 B：手動合併

把 [`main-agent.json5`](./main-agent.json5) 裡的兩個區塊貼進 `~/.openclaw/openclaw.json`。
**不要整份覆蓋**，那會把 gateway 其他設定洗掉。

### 套用後

```bash
openclaw gateway restart                    # 重新載入設定
openclaw doctor                             # 順手確認設定沒問題
openclaw models list --provider anthropic   # 確認 claude-opus-5 在可用清單裡
```

在聊天室裡：

- `/tts status` —— 看目前的 provider、voice、auto 狀態
- `/tts audio 測試一下語音` —— 送一則一次性語音，確認真的收到 mp3
- `/model` —— 確認 main 掛的是 `anthropic/claude-opus-5`

---

## 預設選 Microsoft 的理由

| | Microsoft（預設） | OpenAI |
|---|---|---|
| 金鑰 | 不用 | `OPENAI_API_KEY` |
| mp3 | `outputFormat: audio-24khz-48kbitrate-mono-mp3` | `responseFormat: "mp3"` |
| 中文 | `zh-CN-XiaoxiaoNeural`，中文客服語氣自然 | `gpt-4o-mini-tts`，可用 `instructions` 調語氣 |
| 風險 | 走 Edge 公開語音服務，**沒有 SLA/配額保證** | 照 API 計費，穩定 |

客服正式上線建議切 OpenAI 或 ElevenLabs；Microsoft 適合先跑起來看效果。
切換只要 `--provider=openai`，或手動把這塊貼進 `tts.providers`：

```json5
{
  tts: {
    provider: "openai",
    providers: {
      openai: {
        apiKey: "${OPENAI_API_KEY}",
        model: "gpt-4o-mini-tts",
        speakerVoice: "coral",
        responseFormat: "mp3", // 不寫的話會依頻道自動選格式，語音訊息頻道會變成 Opus
      },
    },
  },
}
```

ElevenLabs / xAI 也都支援 mp3（`ELEVENLABS_API_KEY` / `XAI_API_KEY`），
格式欄位分別是自動選格式與 `responseFormat: "mp3"`。

---

## 模型：anthropic/claude-opus-5

`agents.entries.main.model` 寫成字串就是**嚴格指定 primary、不做 fallback**。
要容錯就改成物件形式：

```json5
{ model: { primary: "anthropic/claude-opus-5", fallbacks: ["anthropic/claude-sonnet-5"] } }
```

需要先有 Anthropic 認證，二選一：

- **API key**：`openclaw onboard --anthropic-api-key "$ANTHROPIC_API_KEY"`
- **沿用 Claude CLI 登入**：本機 `claude auth login` 過即可，模型 ref 改用
  `claude-cli/claude-opus-5`（`--model=claude-cli/claude-opus-5`）

幾個實務上會碰到的點：

- Opus 5 預設 **adaptive thinking + `high` effort**；想省 token 就 `/think low`，
  想更用力就 `/think xhigh|max`，或在 `models["anthropic/claude-opus-5"].params.thinking` 固定。
- 1M context / 128K output，計價 `$5 / $25`（每百萬 token in/out）——
  客服流量大的話，**這個 repo 的三層 prompt caching 就是用來壓這塊成本的**。
- 別寫成 `opus`：那是滾動別名，OpenClaw 升級後會自動指到更新一代的 Opus。
  要釘死就寫完整版本號。
- 產標題之類的雜事 OpenClaw 會走 `utilityModel`（Anthropic 沒設時預設 `claude-haiku-4-5`），
  不會用 Opus 5 去做，這是好事，不用改。

---

## 幾件先知道比較好的事

**1. mp3 不是每個頻道都保證是 mp3。**
OpenClaw 的語音輸出是「看頻道能力」決定的：Telegram / WhatsApp / Feishu / Matrix
這類有原生語音訊息的頻道，會偏好 Opus；WhatsApp、Feishu 甚至會用 `ffmpeg`
把 mp3 轉成 Ogg/Opus 再以語音訊息送出（所以這些頻道要裝 `ffmpeg`）。
明確寫死 `outputFormat` / `responseFormat` 之後：

- 一般頻道（含檔案附件形式）→ **mp3**
- Telegram → mp3 可直接當語音訊息送（`sendVoice` 吃 OGG/MP3/M4A）
- WhatsApp / Feishu → 仍會轉成 Opus 的原生語音訊息，這是頻道層行為，不是設定錯

**2. 文字不會被語音取代。** `mode: "final"` 下文字照送、語音當附件跟著。
Telegram 會把文字放進語音訊息的 caption，超出長度的部分再補一則文字訊息。

**3. 太長的回答會先被摘要再念。** 超過 `maxTextLength`（預設 4096 字元）時，
OpenClaw 會先用 `summaryModel`（沒設就用 `agents.defaults.model.primary`）摘要，
摘要不可用時改成截斷，然後才合成語音 —— 也就是**長回答的語音內容可能跟文字不完全一樣**。
客服場景如果在意這點，就把回答長度控制在上限內。

**4. 這些情況本來就不會發語音**（OpenClaw 內建跳過規則）：
回覆已含結構化媒體、少於 10 個字、整則幾乎都是程式碼區塊、以及 slash command 的回覆。

---

## 參考

- 設定與 provider 清單：<https://docs.openclaw.ai/tools/tts/configuration>
- 每個欄位的型別與預設值：<https://docs.openclaw.ai/tools/tts/field-reference>
- 輸出格式與 Auto-TTS 行為：<https://docs.openclaw.ai/tools/tts/output>
- per-agent 覆寫（含 model / thinkingDefault）：<https://docs.openclaw.ai/gateway/config-agents/entries-and-multi-agent>
- Anthropic provider 認證與模型預設：<https://docs.openclaw.ai/providers/anthropic>
