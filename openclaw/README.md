# OpenClaw：fb / tg 兩個研究用 agent

把 Telegram 與 Facebook 的整合知識拆成兩個獨立的 skill，
分別掛在兩個 OpenClaw agent 的工作區底下。

| agent id | 顯示名稱 | 工作區 | skill |
|---|---|---|---|
| `fb` | fb skill研究 | `~/.openclaw/workspace-fb` | `fb-messenger` |
| `tg` | telegram skill研究 | `~/.openclaw/workspace-tg` | `telegram-bot` |

## 安裝

```bash
./openclaw/install.sh --dry-run   # 先看它會做什麼
./openclaw/install.sh             # 實際執行
```

腳本做三件事：

1. `openclaw agents add <id> --workspace <dir>` 建立 agent
2. `openclaw config set agents.entries.<id>.name "<顯示名稱>"` 設定顯示名稱
3. 把 skill 複製到 `<workspace>/skills/`

要換工作區位置就設 `OPENCLAW_WORKSPACE_ROOT`（預設 `~/.openclaw`）。

裝完確認：

```bash
openclaw agents list
openclaw skills list --agent fb
openclaw skills list --agent tg
```

### 不想用腳本

`openclaw.agents.json5` 是可以合併進 `~/.openclaw/openclaw.json`(JSON5) 的片段，
**但它只是片段，不要整份覆蓋過去**。然後手動複製 skill：

```bash
mkdir -p ~/.openclaw/workspace-fb/skills ~/.openclaw/workspace-tg/skills
cp -R openclaw/agents/fb/skills/fb-messenger  ~/.openclaw/workspace-fb/skills/
cp -R openclaw/agents/tg/skills/telegram-bot  ~/.openclaw/workspace-tg/skills/
```

## 為什麼 skill 放在工作區

OpenClaw 讀 skill 的優先序（高到低）：

```
<workspace>/skills            ← 放這裡，只有該 agent 讀得到
<workspace>/.agents/skills
~/.agents/skills
<state-dir>/skills            ← ~/.openclaw/skills，全域，所有 agent 共用
bundled                       ← 隨安裝附的
```

放在工作區才有「分開研究」的意義 —— fb agent 只看得到 Messenger 的知識，
tg agent 只看得到 Telegram 的，彼此不互相干擾。
同名 skill 出現在多個位置時，高優先序的會蓋掉低的。

## 目錄

```
openclaw/
├── install.sh                  建立 agent + 複製 skill（支援 --dry-run）
├── openclaw.agents.json5       手動合併用的設定片段
└── agents/
    ├── fb/skills/fb-messenger/
    │   ├── SKILL.md            Meta 整合要點：握手、驗簽、24 小時視窗、錯誤分類
    │   └── references/messenger-api.md    平台速查
    └── tg/skills/telegram-bot/
        ├── SKILL.md            Telegram 整合要點：chat.id、限流、切段、429
        └── references/telegram-bot-api.md 平台速查
```

兩個 SKILL.md 的參考實作都是本專案的 `src/Channel/`（PHP，零第三方套件）。
程式碼那邊的說明在 `.claude/skills/messaging-channels/`。

## 版本注意

這些檔案是照 OpenClaw 官方文件寫的：設定檔 `~/.openclaw/openclaw.json`（JSON5）、
`agents.entries` 以 agent id 為 key、skill 是「含 `SKILL.md` 的目錄，
frontmatter 至少要有 `name` 與 `description`」。

OpenClaw 更新頻繁，指令或欄位若對不上，以 `openclaw --help` 與
[官方文件](https://docs.openclaw.ai/gateway/config-agents) 為準。
