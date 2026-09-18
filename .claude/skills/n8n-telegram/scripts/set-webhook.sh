#!/usr/bin/env bash
#
# Telegram webhook 的手動管理。用 Telegram Trigger 節點時 n8n 會自己處理,
# 這支是給「用 Webhook node 自己接」或要除錯時用的。
#
#   export TELEGRAM_BOT_TOKEN=123456789:AA...
#   ./set-webhook.sh info
#   ./set-webhook.sh set https://n8n.example.com/webhook/telegram
#   ./set-webhook.sh delete
#
set -euo pipefail

TOKEN="${TELEGRAM_BOT_TOKEN:-}"
if [[ -z "$TOKEN" ]]; then
  echo "請先設定 TELEGRAM_BOT_TOKEN" >&2
  exit 1
fi

API="https://api.telegram.org/bot${TOKEN}"

# 有 jq 就美化,沒有就原樣印出
pretty() { if command -v jq >/dev/null 2>&1; then jq .; else cat; fi; }

case "${1:-info}" in
  info)
    # 除錯第一站：看 url 對不對、pending_update_count 有沒有累積、last_error_message
    curl -sS "${API}/getWebhookInfo" | pretty
    ;;

  me)
    # 驗證 token 是否有效
    curl -sS "${API}/getMe" | pretty
    ;;

  set)
    URL="${2:-}"
    if [[ -z "$URL" ]]; then
      echo "用法：$0 set <https://.../webhook/telegram>" >&2
      exit 1
    fi
    if [[ "$URL" != https://* ]]; then
      echo "Telegram 只接受 HTTPS 的 webhook URL" >&2
      exit 1
    fi

    # 沒給 secret 就生一個。Telegram 會在每次請求帶
    # X-Telegram-Bot-Api-Secret-Token,Webhook node 那端要比對它。
    SECRET="${TELEGRAM_WEBHOOK_SECRET:-$(openssl rand -hex 32)}"

    curl -sS -X POST "${API}/setWebhook" \
      --data-urlencode "url=${URL}" \
      --data-urlencode "secret_token=${SECRET}" \
      --data-urlencode 'allowed_updates=["message","callback_query"]' \
      --data-urlencode "drop_pending_updates=true" | pretty

    echo
    echo "secret_token（存進 n8n 環境變數 TELEGRAM_WEBHOOK_SECRET,workflow 要比對它）："
    echo "  ${SECRET}"
    echo
    echo "提醒：一個 token 同時只能有一個 webhook —— 這次設定會覆蓋掉先前那台機器的。"
    ;;

  delete)
    curl -sS -X POST "${API}/deleteWebhook" \
      --data-urlencode "drop_pending_updates=true" | pretty
    echo "已刪除。現在可以改用 getUpdates（long polling）在本機開發。"
    ;;

  *)
    echo "用法：$0 {info|me|set <url>|delete}" >&2
    exit 1
    ;;
esac
