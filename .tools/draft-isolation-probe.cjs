#!/usr/bin/env node
/* 跨表单草稿隔离探针：公告框起草的内容不得串到「编辑帖子」「编辑评论」。
 * 用法：OWL_LOGIN=user:pass THREAD_ID=1 REPLY_ID=1 node draft-isolation-probe.cjs <baseUrl>
 */
'use strict';
const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');
const os = require('os');

const BASE = (process.argv[2] || 'http://127.0.0.1:8099').replace(/\/$/, '');
const PORT = 9335;
const THREAD_ID = process.env.THREAD_ID || '1';
const REPLY_ID = process.env.REPLY_ID || '1';

function findChrome() {
  const cands = [
    'C:/Program Files/Google/Chrome/Application/chrome.exe',
    'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
    'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
    'C:/Program Files/Microsoft/Edge/Application/msedge.exe',
  ];
  for (const c of cands) { if (fs.existsSync(c)) return c; }
  throw new Error('找不到 Chrome/Edge');
}
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
function ok(cond, label, detail) {
  console.log((cond ? '  PASS ' : '  FAIL ') + label + (detail !== undefined ? '  -> ' + detail : ''));
  return !!cond;
}

async function main() {
  const exe = findChrome();
  const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'cdp-iso-'));
  const chrome = spawn(exe, [
    '--headless=new', `--remote-debugging-port=${PORT}`,
    `--user-data-dir=${profile}`, '--no-first-run', '--no-default-browser-check',
    '--ignore-certificate-errors', '--window-size=1400,1000', 'about:blank',
  ], { stdio: 'ignore' });

  let failures = 0;
  try {
    let target = null;
    for (let i = 0; i < 50; i++) {
      await sleep(200);
      try {
        const list = await (await fetch(`http://127.0.0.1:${PORT}/json/list`)).json();
        target = list.find((t) => t.type === 'page');
        if (target) break;
      } catch (e) {}
    }
    if (!target) throw new Error('CDP 未就绪');

    const ws = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise((res, rej) => { ws.onopen = res; ws.onerror = rej; });
    let id = 0;
    const pending = new Map();
    ws.onmessage = (ev) => {
      const m = JSON.parse(ev.data);
      if (m.id && pending.has(m.id)) { pending.get(m.id)(m); pending.delete(m.id); }
    };
    const send = (method, params = {}) => new Promise((res) => {
      const mid = ++id; pending.set(mid, res);
      ws.send(JSON.stringify({ id: mid, method, params }));
    });
    const evalJS = async (expr) => {
      const r = await send('Runtime.evaluate', { expression: expr, returnByValue: true, userGesture: true, awaitPromise: true });
      if (r.result?.exceptionDetails) throw new Error('JS ERR: ' + JSON.stringify(r.result.exceptionDetails.text || r.result.exceptionDetails));
      return r.result?.result?.value;
    };
    const waitReady = async () => {
      for (let i = 0; i < 40; i++) {
        await sleep(250);
        if ((await evalJS('document.readyState')) === 'complete') { await sleep(500); return true; }
      }
      return false;
    };
    const goto = async (url) => { await send('Page.navigate', { url }); await waitReady(); };

    await send('Page.enable');
    await send('Network.enable');

    // 登录必须等到真正离开 login 页，否则会在旧页面上就返回（会话未建立 → 后续被踢回登录）
    const waitLogin = async () => {
      for (let i = 0; i < 40; i++) {
        await sleep(250);
        const st = await evalJS('document.readyState + "|" + location.pathname + "|" + location.search');
        if (String(st).startsWith('complete|') && !String(st).includes('login')) return true;
      }
      return false;
    };

    console.log('[1] 登录');
    const [user, pass] = (process.env.OWL_LOGIN || '').split(':');
    await goto(BASE + '/index.php?a=login');
    await sleep(700);
    const lr = await evalJS(`
      (function(){
        var f=document.querySelector('form'); if(!f) return 'NO_FORM';
        var q=document.querySelector('.captcha-q');
        var ans=q?(q.textContent.match(/(\\d+)\\s*\\+\\s*(\\d+)/)||[]).slice(1).reduce(function(a,b){return +a + +b;},0):'';
        f.querySelector('[name=login]').value=${JSON.stringify(user)};
        f.querySelector('[name=pass]').value=${JSON.stringify(pass)};
        var c=f.querySelector('[name=_captcha]'); if(c) c.value=ans;
        f.submit(); return 'SUBMIT ans=' + ans;
      })()`);
    console.log('  login submit:', lr);
    const logged = await waitLogin();
    if (!ok(logged, '登录成功并离开登录页')) {
      failures++;
      console.log('  at:', await evalJS('location.href'));
      console.log('  page:', await evalJS('document.body.innerText.replace(/\\s+/g," ").slice(0,300)'));
      console.log('  flash:', await evalJS('(document.querySelector(".flash,.error,.alert,form p")||{}).textContent||"(none)"'));
    }

    // [2] 在公告框起草一段独有内容
    const MARK = 'ISO-' + Date.now();
    console.log('[2] 公告框起草：' + MARK);
    await goto(BASE + '/index.php?a=admin&tab=notices');
    console.log('  url:', await evalJS('location.href'));
    console.log('  editor count:', await evalJS(`document.querySelectorAll('[data-editor]').length`));
    console.log('  textarea present:', await evalJS(`!!document.querySelector('[data-editor] textarea')`));
    await evalJS(`
      (function(){
        var a=document.querySelector('[data-editor] textarea');
        a.value='公告草稿 ${MARK}';
        a.dispatchEvent(new Event('input',{bubbles:true}));
        return 'typed';
      })()`);
    await sleep(700);
    const keys = await evalJS(`JSON.stringify(Object.keys(window.localStorage))`);
    console.log('  localStorage keys:', keys);

    // [3] 打开编辑帖子：不得出现公告草稿
    console.log('[3] 打开编辑帖子页，检查是否串稿');
    await goto(BASE + '/index.php?a=thread_edit&id=' + THREAD_ID);
    const edKey = await evalJS(`(document.querySelector('[data-editor]')||{}).getAttribute ? document.querySelector('[data-editor]').getAttribute('data-draft-key') : 'NO_EDITOR'`);
    console.log('  edit-thread draftKey =', JSON.stringify(edKey));
    if (!ok(edKey === 'edit-thread-' + THREAD_ID, '编辑帖使用独立 key', JSON.stringify(edKey))) failures++;
    const edVal = await evalJS(`(function(){var a=document.querySelector('[data-editor] textarea'); return a?a.value:'NO_AREA';})()`);
    const leaked = typeof edVal === 'string' && edVal.indexOf(MARK) >= 0;
    if (!ok(!leaked, '编辑帖编辑框未串入公告草稿', JSON.stringify(String(edVal).slice(0, 60)))) failures++;

    // [4] 打开编辑评论：同样不得串稿
    console.log('[4] 打开编辑评论页，检查是否串稿');
    await goto(BASE + '/index.php?a=reply_edit&id=' + REPLY_ID);
    const rpKey = await evalJS(`(function(){var e=document.querySelector('[data-editor]'); return e?e.getAttribute('data-draft-key'):'NO_EDITOR';})()`);
    console.log('  edit-reply draftKey =', JSON.stringify(rpKey));
    if (!ok(rpKey === 'edit-reply-' + REPLY_ID, '编辑评论使用独立 key', JSON.stringify(rpKey))) failures++;
    const rpVal = await evalJS(`(function(){var a=document.querySelector('[data-editor] textarea'); return a?a.value:'NO_AREA';})()`);
    const leaked2 = typeof rpVal === 'string' && rpVal.indexOf(MARK) >= 0;
    if (!ok(!leaked2, '编辑评论编辑框未串入公告草稿', JSON.stringify(String(rpVal).slice(0, 60)))) failures++;

    // [5] 反向：回到公告页，公告自己的草稿应仍在
    console.log('[5] 回到公告页，草稿应独立保留');
    await goto(BASE + '/index.php?a=admin&tab=notices');
    const backVal = await evalJS(`document.querySelector('[data-editor] textarea').value`);
    if (!ok(String(backVal).indexOf(MARK) >= 0, '公告草稿独立保留', JSON.stringify(String(backVal).slice(0, 60)))) failures++;

    ws.close();
  } finally {
    chrome.kill();
    await sleep(200);
    try { fs.rmSync(profile, { recursive: true, force: true }); } catch (e) {}
  }
  console.log('\n==== ' + (failures ? failures + ' 项失败' : '全部通过') + ' ====');
  process.exit(failures ? 1 : 0);
}
main().catch((e) => { console.error('PROBE FAIL:', e.message); process.exit(1); });
