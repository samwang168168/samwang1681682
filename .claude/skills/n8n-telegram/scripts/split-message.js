/**
 * 把超過 4096 字元的文字切成多則 Telegram 訊息。
 *
 * 切點優先序：段落（\n\n）→ 換行 → 句尾（。！？.!?）→ 空白 → 硬切。
 * 不會把 emoji 或中文字切成一半（用 Array.from 以 code point 為單位）。
 *
 * 在 n8n：Code node,mode = Run Once for Each Item,貼上本檔內容,
 * 然後接最下面那段 n8n 專用的程式碼。
 */

const TELEGRAM_LIMIT = 4096;

function splitMessage(text, limit = TELEGRAM_LIMIT) {
  const chars = Array.from(String(text ?? ''));
  if (chars.length === 0) return [];
  if (chars.length <= limit) return [chars.join('')];

  const chunks = [];
  let rest = chars;

  while (rest.length > limit) {
    const window = rest.slice(0, limit);
    const windowText = window.join('');

    // 從後往前找最好的切點。索引是「切在這個位置之後」。
    let cut = -1;
    for (const [pattern, minRatio] of [
      [/\n\n/g, 0.3],           // 段落
      [/\n/g, 0.3],             // 換行
      [/[。！？!?](?=\s|$)/g, 0.5], // 句尾（中英文）
      [/[.](?=\s)/g, 0.5],      // 英文句點後接空白
      [/\s/g, 0.7],             // 任何空白
    ]) {
      let last = -1;
      for (const m of windowText.matchAll(pattern)) {
        last = m.index + m[0].length;
      }
      // 切點太靠前的話寧可往下一個規則找,否則會產生一堆超短訊息
      if (last > limit * minRatio) {
        cut = Array.from(windowText.slice(0, last)).length;
        break;
      }
    }

    if (cut <= 0) cut = limit; // 沒有任何可用切點（例如一長串無空白字元）→ 硬切

    chunks.push(rest.slice(0, cut).join('').trimEnd());
    rest = rest.slice(cut);
    // 切完後開頭的空白沒有意義
    while (rest.length && /\s/.test(rest[0])) rest = rest.slice(1);
  }

  if (rest.length) chunks.push(rest.join('').trimEnd());
  return chunks.filter((c) => c.length > 0);
}

/* ─────────────────────────────────────────────────────────────
   n8n Code node 版本（mode: Run Once for Each Item）

   把上面的 splitMessage 連同下面這段一起貼進去：

   const text = $input.item.json.reply ?? '';
   const chatId = $input.item.json.chatId;

   return splitMessage(text).map((chunk, i) => ({
     json: { chatId, text: chunk, part: i + 1 },
   }));

   ⚠️ Run Once for Each Item 模式下回傳陣列會展開成多個 item,
      後面接 Telegram 節點就會逐則送出。但 n8n **不保證**送出順序,
      訊息量多時要在中間插 Wait 節點（1 秒）確保順序與不超速。
   ───────────────────────────────────────────────────────────── */

// ── 自我測試：node split-message.js ──────────────────────────
if (typeof require !== 'undefined' && require.main === module) {
  const assert = require('node:assert');
  const cases = [
    ['短訊息原樣回傳', 'hello', 100, (r) => assert.deepStrictEqual(r, ['hello'])],
    ['空字串回空陣列', '', 100, (r) => assert.deepStrictEqual(r, [])],
    [
      '在段落切開',
      '第一段內容'.repeat(10) + '\n\n' + '第二段內容'.repeat(10),
      60,
      (r) => {
        assert.ok(r.length >= 2, '應該切成多則');
        assert.ok(r.every((c) => Array.from(c).length <= 60), '每則都不超過上限');
      },
    ],
    [
      '無空白長字串硬切',
      'a'.repeat(250),
      100,
      (r) => {
        assert.strictEqual(r.length, 3);
        assert.ok(r.every((c) => c.length <= 100));
      },
    ],
    [
      'emoji 不被切成半個',
      '😀'.repeat(200),
      100,
      (r) => {
        assert.ok(r.every((c) => !c.includes('�')));
        assert.strictEqual(r.join(''), '😀'.repeat(200));
        assert.ok(r.every((c) => Array.from(c).length <= 100));
      },
    ],
    [
      '不遺漏內容',
      Array.from({ length: 300 }, (_, i) => `第 ${i} 行`).join('\n'),
      500,
      (r) => {
        const rejoined = r.join('\n');
        assert.ok(rejoined.includes('第 0 行') && rejoined.includes('第 299 行'));
      },
    ],
    [
      '預設上限是 4096',
      'x'.repeat(5000),
      undefined,
      (r) => {
        assert.strictEqual(r.length, 2);
        assert.strictEqual(Array.from(r[0]).length, 4096);
      },
    ],
  ];

  let failed = 0;
  for (const [name, input, limit, check] of cases) {
    try {
      check(limit === undefined ? splitMessage(input) : splitMessage(input, limit));
      console.log(`✅ ${name}`);
    } catch (e) {
      console.log(`❌ ${name}: ${e.message}`);
      failed++;
    }
  }
  process.exit(failed ? 1 : 0);
}

if (typeof module !== 'undefined') module.exports = { splitMessage, TELEGRAM_LIMIT };
