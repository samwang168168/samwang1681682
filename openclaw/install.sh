#!/usr/bin/env bash
#
# 在 OpenClaw 建立 fb / tg 兩個 agent，並把對應的 skill 放進各自的工作區。
#
#   ./openclaw/install.sh --dry-run    # 只印出會執行的指令，不動任何東西
#   ./openclaw/install.sh              # 真的執行
#
# 做了三件事：
#   1. openclaw agents add <id> --workspace <dir>      建立 agent
#   2. openclaw config set agents.entries.<id>.name    設定顯示名稱（可含中文與空白）
#   3. 把 skill 複製到 <workspace>/skills/             —— 這是最高優先序的 skill 來源
#
# skill 放在工作區 = 只有該 agent 讀得到。要兩個 agent 都讀得到的話，
# 改放 ~/.openclaw/skills（全域），但那樣就失去分開研究的意義了。
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WORKSPACE_ROOT="${OPENCLAW_WORKSPACE_ROOT:-$HOME/.openclaw}"
DRY_RUN=0

for arg in "$@"; do
  case "$arg" in
    --dry-run) DRY_RUN=1 ;;
    -h|--help) sed -n '2,20p' "${BASH_SOURCE[0]}"; exit 0 ;;
    *) echo "未知參數：$arg" >&2; exit 2 ;;
  esac
done

# 印出可直接複製貼上的指令（含引號），再視情況執行
run() {
  local shown=()
  local arg
  for arg in "$@"; do
    if [[ "$arg" == *[[:space:]]* ]]; then
      shown+=("\"$arg\"")
    else
      shown+=("$arg")
    fi
  done

  printf '  \033[2m$\033[0m %s\n' "${shown[*]}"

  if [[ $DRY_RUN -eq 0 ]]; then
    "$@"
  fi
}

if [[ $DRY_RUN -eq 0 ]] && ! command -v openclaw >/dev/null 2>&1; then
  cat >&2 <<'MSG'
找不到 openclaw 指令。

請在有安裝 OpenClaw 的機器上執行這支腳本，或先加上 --dry-run 看它會做什麼。
若 openclaw 裝在別的路徑，把它加進 PATH 再跑一次。
MSG
  exit 1
fi

# id    顯示名稱            skill 目錄名
AGENTS=(
  "fb|fb skill研究|fb-messenger"
  "tg|telegram skill研究|telegram-bot"
)

for entry in "${AGENTS[@]}"; do
  IFS='|' read -r id display_name skill_name <<<"$entry"

  workspace="${WORKSPACE_ROOT}/workspace-${id}"
  source_skill="${SCRIPT_DIR}/agents/${id}/skills/${skill_name}"

  if [[ ! -d "$source_skill" ]]; then
    echo "找不到 skill 來源：${source_skill}" >&2
    exit 1
  fi

  printf '\n\033[1m[%s] %s\033[0m\n' "$id" "$display_name"

  # 1. 建立 agent。已經存在的話 openclaw 會自己報錯，不要當成失敗。
  if [[ $DRY_RUN -eq 1 ]]; then
    run openclaw agents add "$id" --workspace "$workspace"
  else
    printf '  \033[2m$\033[0m openclaw agents add %s --workspace %s\n' "$id" "$workspace"
    if ! openclaw agents add "$id" --workspace "$workspace"; then
      echo "  （agent「${id}」可能已存在，略過建立，繼續設定）"
    fi
  fi

  # 2. 顯示名稱。agent id 只能用小寫英數與連字號，
  #    但 name 是顯示用的標籤，可以有空白與中文。
  run openclaw config set "agents.entries.${id}.name" "$display_name"

  # 3. skill 放進工作區。<workspace>/skills 是最高優先序的來源，
  #    同名 skill 會蓋掉全域與內建的版本。
  run mkdir -p "${workspace}/skills"
  run rm -rf "${workspace}/skills/${skill_name}"
  run cp -R "$source_skill" "${workspace}/skills/${skill_name}"
done

printf '\n'
if [[ $DRY_RUN -eq 1 ]]; then
  echo "以上是 --dry-run，什麼都沒做。拿掉 --dry-run 即可實際執行。"
else
  cat <<'MSG'
完成。接下來：

  openclaw agents list                 確認兩個 agent 都在
  openclaw skills list --agent fb      確認 fb-messenger 有被讀到
  openclaw skills list --agent tg      確認 telegram-bot 有被讀到

skill 有改動時重跑這支腳本即可（會覆蓋工作區裡的舊版本）。
MSG
fi
