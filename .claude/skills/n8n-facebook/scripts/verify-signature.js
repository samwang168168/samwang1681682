/**
 * 驗證 Meta webhook 的 X-Hub-Signature-256。
 *
 * 在 n8n：
 *   1. Webhook node → Options → Raw Body 打開（**必要**,見下）
 *   2. 接一個 Code node（mode: Run Once for All Items）,貼上本檔內容
 *      再加上最下面那段 n8n 專用的程式碼
 *
 * 為什麼一定要 raw body：
 *   HMAC 是對原始位元組算的。n8n 預設把 body 解析成 JSON 物件,你再
 *   JSON.stringify() 回去時,空白、鍵序、Unicode escape 都可能跟 Meta 送來的
 *   原文不同 —— 簽章就對不上,而且是「有中文就壞、純英文就好」這種時好時壞的
 *   症狀,最難查。
 */

const crypto = require('crypto');

/**
 * @param {Buffer|string} rawBody   原始請求 body（位元組）
 * @param {string} signatureHeader  X-Hub-Signature-256 的值,格式 "sha256=<hex>"
 * @param {string} appSecret        Meta App Secret
 * @returns {boolean}
 */
function verifyMetaSignature(rawBody, signatureHeader, appSecret) {
  if (!signatureHeader || !appSecret) return false;
  if (!signatureHeader.startsWith('sha256=')) return false;

  const body = Buffer.isBuffer(rawBody) ? rawBody : Buffer.from(String(rawBody), 'utf8');

  const expected =
    'sha256=' + crypto.createHmac('sha256', appSecret).update(body).digest('hex');

  const a = Buffer.from(signatureHeader);
  const b = Buffer.from(expected);

  // 長度不同時 timingSafeEqual 會直接 throw,所以要先擋掉
  if (a.length !== b.length) return false;

  // ★ 不要用 ===。字串比較會提早回傳,洩漏「前幾個字元對了」的時間差。
  return crypto.timingSafeEqual(a, b);
}

/**
 * 把 Meta 的 payload 攤平成一則一則的訊息。
 *
 * Meta 可以在一個請求裡塞多個 entry、每個 entry 多則訊息 ——
 * 只讀 entry[0].messaging[0] 在流量一大就會漏訊息。
 */
function flattenMessagingEvents(payload) {
  const out = [];
  for (const entry of payload?.entry ?? []) {
    for (const event of entry.messaging ?? []) {
      out.push({ pageId: entry.id, ...event });
    }
  }
  return out;
}

/* ─────────────────────────────────────────────────────────────
   n8n Code node（mode: Run Once for All Items）：

   const item = $input.first();

   // Raw Body 開啟後,body 以 base64 binary 放在 item.binary.data.data
   const raw = Buffer.from(item.binary.data.data, 'base64');
   const signature = item.json.headers['x-hub-signature-256'];

   if (!verifyMetaSignature(raw, signature, $env.META_APP_SECRET)) {
     throw new Error('X-Hub-Signature-256 驗證失敗');
   }

   const payload = JSON.parse(raw.toString('utf8'));
   return flattenMessagingEvents(payload).map((e) => ({ json: e }));

   self-host 需要 NODE_FUNCTION_ALLOW_BUILTIN=crypto 才 require 得到 crypto。
   ───────────────────────────────────────────────────────────── */

// ── 自我測試：node verify-signature.js ───────────────────────
if (typeof require !== 'undefined' && require.main === module) {
  const assert = require('node:assert');

  const secret = 'test-app-secret';
  const body = Buffer.from(JSON.stringify({ object: 'page', entry: [{ id: '1', messaging: [] }] }), 'utf8');
  const sign = (b, s = secret) =>
    'sha256=' + crypto.createHmac('sha256', s).update(b).digest('hex');

  const cases = [
    ['正確簽章通過', () => assert.strictEqual(verifyMetaSignature(body, sign(body), secret), true)],
    ['錯誤 secret 不通過', () => assert.strictEqual(verifyMetaSignature(body, sign(body, 'wrong'), secret), false)],
    ['body 被竄改不通過', () => {
      const sig = sign(body);
      assert.strictEqual(verifyMetaSignature(Buffer.from(body.toString() + ' '), sig, secret), false);
    }],
    ['缺 sha256= 前綴不通過', () => assert.strictEqual(verifyMetaSignature(body, sign(body).slice(7), secret), false)],
    ['長度不同不會 throw', () => assert.strictEqual(verifyMetaSignature(body, 'sha256=abc', secret), false)],
    ['空簽章不通過', () => {
      assert.strictEqual(verifyMetaSignature(body, '', secret), false);
      assert.strictEqual(verifyMetaSignature(body, undefined, secret), false);
    }],
    ['字串 body 與 Buffer 結果一致', () => {
      assert.strictEqual(verifyMetaSignature(body.toString('utf8'), sign(body), secret), true);
    }],
    ['中文內容：重新序列化會失效,原始位元組才對', () => {
      const zh = Buffer.from(JSON.stringify({ text: '可以退貨嗎？' }), 'utf8');
      const sig = sign(zh);
      assert.strictEqual(verifyMetaSignature(zh, sig, secret), true);
      // n8n 解析後再 stringify,鍵序或 escape 一變就對不上 —— 這就是要開 rawBody 的原因
      const reserialized = Buffer.from(JSON.stringify({ text: '可以退貨嗎？', extra: 1 }), 'utf8');
      assert.strictEqual(verifyMetaSignature(reserialized, sig, secret), false);
    }],
    ['攤平多個 entry 與多則訊息', () => {
      const payload = {
        entry: [
          { id: 'P1', messaging: [{ sender: { id: 'A' } }, { sender: { id: 'B' } }] },
          { id: 'P2', messaging: [{ sender: { id: 'C' } }] },
        ],
      };
      const flat = flattenMessagingEvents(payload);
      assert.strictEqual(flat.length, 3);
      assert.deepStrictEqual(flat.map((e) => e.sender.id), ['A', 'B', 'C']);
      assert.strictEqual(flat[2].pageId, 'P2');
    }],
    ['空 payload 不爆炸', () => {
      assert.deepStrictEqual(flattenMessagingEvents(undefined), []);
      assert.deepStrictEqual(flattenMessagingEvents({ entry: [{ id: '1' }] }), []);
    }],
  ];

  let failed = 0;
  for (const [name, fn] of cases) {
    try { fn(); console.log(`✅ ${name}`); }
    catch (e) { console.log(`❌ ${name}: ${e.message}`); failed++; }
  }
  process.exit(failed ? 1 : 0);
}

if (typeof module !== 'undefined') module.exports = { verifyMetaSignature, flattenMessagingEvents };
