#!/usr/bin/env node
/**
 * n8n workflow JSON 匯入前的靜態檢查。
 *
 *   node lint-workflow.mjs <workflow.json> [更多檔案...]
 *
 * 檢查項目：JSON 合法性、必要欄位、節點名稱/ID 唯一、connections 指向存在的節點、
 * webhook path 撞名、以及有沒有把 token 寫死在參數裡。
 *
 * exit code 0 = 全過（可能有 warning）；1 = 有 error；2 = 用法錯誤。
 */

import { readFileSync } from 'node:fs';
import { basename } from 'node:path';

const REQUIRED_TOP = ['name', 'nodes', 'connections'];

// 看起來像憑證的字串。Telegram token、Meta 的長 token、sk- 開頭的 key。
const SECRET_PATTERNS = [
  [/\b\d{8,10}:[A-Za-z0-9_-]{35}\b/, 'Telegram bot token'],
  [/\bEAA[A-Za-z0-9]{20,}/, 'Meta access token'],
  [/\bsk-[A-Za-z0-9_-]{20,}/, 'API key (sk-…)'],
  [/\bghp_[A-Za-z0-9]{20,}/, 'GitHub token'],
];

function lint(file) {
  const errors = [];
  const warnings = [];
  let wf;

  try {
    wf = JSON.parse(readFileSync(file, 'utf8'));
  } catch (e) {
    return { errors: [`JSON 解析失敗：${e.message}`], warnings: [] };
  }

  for (const key of REQUIRED_TOP) {
    if (!(key in wf)) errors.push(`缺少頂層欄位 "${key}"`);
  }
  if (!Array.isArray(wf.nodes)) {
    errors.push('"nodes" 必須是陣列');
    return { errors, warnings };
  }
  if (wf.nodes.length === 0) errors.push('"nodes" 是空的');

  if (wf.settings?.executionOrder !== 'v1') {
    warnings.push('settings.executionOrder 不是 "v1"；多分支時執行順序不保證');
  }

  // ── 節點層 ──────────────────────────────────────────────
  const names = new Map();
  const ids = new Map();
  const webhookPaths = new Map();

  wf.nodes.forEach((node, i) => {
    const where = `nodes[${i}]${node.name ? ` ("${node.name}")` : ''}`;

    for (const key of ['id', 'name', 'type', 'typeVersion', 'position']) {
      if (node[key] === undefined) errors.push(`${where}：缺少 "${key}"`);
    }
    if (!Array.isArray(node.position) || node.position.length !== 2) {
      errors.push(`${where}："position" 必須是 [x, y]`);
    }
    if (node.name !== undefined) {
      if (names.has(node.name)) errors.push(`${where}：節點名稱 "${node.name}" 與 nodes[${names.get(node.name)}] 重複`);
      else names.set(node.name, i);
    }
    if (node.id !== undefined) {
      if (ids.has(node.id)) errors.push(`${where}：節點 id "${node.id}" 與 nodes[${ids.get(node.id)}] 重複`);
      else ids.set(node.id, i);
    }

    // webhook 專屬
    if (node.type === 'n8n-nodes-base.webhook') {
      if (!node.webhookId) errors.push(`${where}：webhook 節點需要頂層 "webhookId"`);
      const path = node.parameters?.path;
      if (!path) {
        errors.push(`${where}：webhook 節點缺少 parameters.path`);
      } else {
        const key = `${(node.parameters.httpMethod || 'GET').toUpperCase()} ${path}`;
        if (webhookPaths.has(key)) {
          errors.push(`${where}：webhook "${key}" 與 "${webhookPaths.get(key)}" 撞名；同 path 只能有一個同 method 的節點`);
        } else {
          webhookPaths.set(key, node.name);
        }
      }
      if (node.parameters?.responseMode === 'responseNode') {
        const hasResponder = wf.nodes.some((n) => n.type === 'n8n-nodes-base.respondToWebhook');
        if (!hasResponder) {
          errors.push(`${where}：responseMode 是 "responseNode",但 workflow 裡沒有 Respond to Webhook 節點（請求會掛到逾時）`);
        }
      }
    }

    // 寫死的憑證
    const serialized = JSON.stringify(node.parameters ?? {});
    for (const [pattern, label] of SECRET_PATTERNS) {
      if (pattern.test(serialized)) {
        errors.push(`${where}：參數裡疑似寫死了 ${label},請改用 credential 或 $env`);
      }
    }

    // expression 少了開頭的 =
    for (const [key, value] of Object.entries(node.parameters ?? {})) {
      if (typeof value === 'string' && value.includes('{{') && !value.startsWith('=')) {
        warnings.push(`${where}：參數 "${key}" 含 {{ }} 但開頭沒有 "=",n8n 會當成純文字`);
      }
    }
  });

  // ── connections ────────────────────────────────────────
  for (const [source, outputs] of Object.entries(wf.connections ?? {})) {
    if (!names.has(source)) {
      errors.push(`connections："${source}" 不是任何節點的名稱`);
    }
    for (const [type, branches] of Object.entries(outputs ?? {})) {
      if (!Array.isArray(branches)) {
        errors.push(`connections["${source}"]["${type}"] 必須是陣列的陣列`);
        continue;
      }
      branches.forEach((branch, bi) => {
        if (!Array.isArray(branch)) {
          errors.push(`connections["${source}"]["${type}"][${bi}] 必須是陣列`);
          return;
        }
        for (const conn of branch) {
          if (!names.has(conn?.node)) {
            errors.push(`connections["${source}"] 輸出 ${bi} 指向不存在的節點 "${conn?.node}"`);
          }
        }
      });
    }
  }

  // 孤島節點（沒有人連進來、自己也沒連出去,且不是 trigger）
  const hasIncoming = new Set();
  for (const outputs of Object.values(wf.connections ?? {})) {
    for (const branches of Object.values(outputs ?? {})) {
      for (const branch of branches ?? []) {
        for (const conn of branch ?? []) hasIncoming.add(conn?.node);
      }
    }
  }
  for (const node of wf.nodes) {
    const isTrigger = /trigger|webhook/i.test(node.type ?? '');
    if (!isTrigger && !hasIncoming.has(node.name) && !wf.connections?.[node.name]) {
      warnings.push(`節點 "${node.name}" 沒有任何連線,永遠不會執行`);
    }
  }

  return { errors, warnings };
}

const files = process.argv.slice(2);
if (files.length === 0) {
  console.error('用法：node lint-workflow.mjs <workflow.json> [更多檔案...]');
  process.exit(2);
}

let failed = false;
for (const file of files) {
  const { errors, warnings } = lint(file);
  const label = basename(file);
  if (errors.length === 0 && warnings.length === 0) {
    console.log(`✅ ${label}`);
    continue;
  }
  console.log(`${errors.length ? '❌' : '⚠️ '} ${label}`);
  for (const e of errors) console.log(`   error   ${e}`);
  for (const w of warnings) console.log(`   warning ${w}`);
  if (errors.length) failed = true;
}

process.exit(failed ? 1 : 0);
