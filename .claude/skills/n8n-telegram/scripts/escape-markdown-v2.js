/**
 * Telegram parse_mode 的跳脫工具。
 *
 * 建議優先用 escapeHtml —— 只要處理三個字元,漏掉的機率遠低於 MarkdownV2 的 18 個。
 * 只有在你確實需要 MarkdownV2 的語法時才用 escapeMarkdownV2。
 */

/** HTML parse_mode。支援 <b> <i> <u> <s> <code> <pre> <a> <blockquote> <tg-spoiler>。 */
function escapeHtml(text) {
  return String(text ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;');
}

/**
 * MarkdownV2。把全部 18 個保留字元跳脫掉 —— 結果是「純文字,沒有任何格式」。
 * 適合送使用者產生或 LLM 產生的內容。
 */
const MDV2_RESERVED = /[_*[\]()~`>#+\-=|{}.!\\]/g;

function escapeMarkdownV2(text) {
  return String(text ?? '').replace(MDV2_RESERVED, (c) => '\\' + c);
}

/**
 * 只跳脫 code block 內部。Telegram 規定 `code` 與 ```pre``` 裡面
 * 只需要跳脫 ` 和 \,其他字元照原樣 —— 用全量跳脫反而會把反斜線印出來。
 */
function escapeMarkdownV2Code(text) {
  return String(text ?? '').replace(/[`\\]/g, (c) => '\\' + c);
}

/**
 * 保留你自己加的粗體/斜體,只跳脫其餘部分。
 *
 * 用法：markdownV2Template`訂單 *${id}* 已出貨`
 * —— 樣板裡的 * 是你寫的,會保留；${id} 裡的內容會被跳脫。
 */
function markdownV2Template(strings, ...values) {
  return strings.reduce(
    (acc, s, i) => acc + s + (i < values.length ? escapeMarkdownV2(values[i]) : ''),
    ''
  );
}

/* ─────────────────────────────────────────────────────────────
   n8n Code node（mode: Run Once for Each Item）：

   return {
     json: {
       chatId: $input.item.json.chatId,
       text: escapeHtml($input.item.json.reply),
     },
   };

   Telegram 節點的 Additional Fields → Parse Mode 選 HTML。
   ───────────────────────────────────────────────────────────── */

// ── 自我測試：node escape-markdown-v2.js ─────────────────────
if (typeof require !== 'undefined' && require.main === module) {
  const assert = require('node:assert');
  const cases = [
    ['HTML 跳脫三字元', () => assert.strictEqual(escapeHtml('a & b < c > d'), 'a &amp; b &lt; c &gt; d')],
    ['HTML 先處理 &,不會二次跳脫', () => assert.strictEqual(escapeHtml('&lt;'), '&amp;lt;')],
    ['MDV2 跳脫句點', () => assert.strictEqual(escapeMarkdownV2('結束.'), '結束\\.')],
    ['MDV2 跳脫日期的連字號', () => assert.strictEqual(escapeMarkdownV2('2024-01-01'), '2024\\-01\\-01')],
    [
      'MDV2 涵蓋全部 18 個保留字元',
      () => {
        const all = '_*[]()~`>#+-=|{}.!\\';
        const out = escapeMarkdownV2(all);
        assert.strictEqual(out, Array.from(all).map((c) => '\\' + c).join(''));
      },
    ],
    ['MDV2 不動中文與 emoji', () => assert.strictEqual(escapeMarkdownV2('你好 😀'), '你好 😀')],
    ['code 版只跳脫 ` 與 \\', () => assert.strictEqual(escapeMarkdownV2Code('a.b`c\\d'), 'a.b\\`c\\\\d')],
    [
      'template 保留樣板的星號、跳脫插入值',
      () => assert.strictEqual(markdownV2Template`訂單 *${'ORD-1.2'}* 出貨`, '訂單 *ORD\\-1\\.2* 出貨'),
    ],
    ['null / undefined 回空字串', () => {
      assert.strictEqual(escapeHtml(null), '');
      assert.strictEqual(escapeMarkdownV2(undefined), '');
    }],
  ];

  let failed = 0;
  for (const [name, fn] of cases) {
    try { fn(); console.log(`✅ ${name}`); }
    catch (e) { console.log(`❌ ${name}: ${e.message}`); failed++; }
  }
  process.exit(failed ? 1 : 0);
}

if (typeof module !== 'undefined') {
  module.exports = { escapeHtml, escapeMarkdownV2, escapeMarkdownV2Code, markdownV2Template };
}
