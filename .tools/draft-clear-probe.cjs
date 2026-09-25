#!/usr/bin/env node
/* 公告草稿清除探针：验证「提交后编辑框内容与 localStorage 草稿都被清空」。
 * 用法：OWL_LOGIN=user:pass node draft-clear-probe.cjs <baseUrl>
 * 依赖：无（Node >=22 自带 WebSocket/fetch）
 */
'use strict';
const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');
const os = require('os');

const BASE = (process.argv[2] || 'http://127.0.0.1:8099').replace(/\/$/, '');
const PORT = 9334;

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
  const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'cdp-draft-'));
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
      const mid = ++id;
      pending.set(mid, res);
      ws.send(JSON.stringify({ id: mid, method, params }));
    });
    const evalJS = async (expr) => {
      const r = await send('Runtime.evaluate', { expression: expr, returnByValue: true, userGesture: true, awaitPromise: true });
      if (r.result?.exceptionDetails) throw new Error('JS ERR: ' + JSON.stringify(r.result.exceptionDetails.text || r.result.exceptionDetails));
      return r.result?.result?.value;
    };
    const waitReady = async (wantSearch) => {
      for (let i = 0; i < 40; i++) {
        await sleep(250);
        const v = await evalJS('document.readyState');
        if (v === 'complete') {
          if (wantSearch === undefined) return true;
          const s = await evalJS('location.search');
          if (s === wantSearch) return true;
        }
      }
      return false;
    };

    await send('Page.enable');
    await send('Network.enable');

    // ---- 1. 登录 ----
    console.log('[1] 登录');
    const [user, pass] = (process.env.OWL_LOGIN || '').split(':');
    await send('Page.navigate', { url: BASE + '/index.php?a=login' });
    await sleep(900);
    const loginRes = await evalJS(`
      (function(){
        var f=document.querySelector('form'); if(!f) return 'NO_FORM';
        var q=document.querySelector('.captcha-q');
        var ans=q?(q.textContent.match(/(\\d+)\\s*\\+\\s*(\\d+)/)||[]).slice(1).reduce(function(a,b){return +a + +b;},0):'';
        f.querySelector('[name=login]').value=${JSON.stringify(user)};
        f.querySelector('[name=pass]').value=${JSON.stringify(pass)};
        var c=f.querySelector('[name=_captcha]'); if(c) c.value=ans;
        f.submit(); return 'SUBMIT ans='+ans;
      })()`);
    console.log('  login submit:', loginRes);
    const loggedIn = await waitReady('');
    if (!ok(loggedIn, '登录并跳回首页')) failures++;
    const who = await evalJS('document.body.innerText.replace(/\\s+/g," ").slice(0,120)');
    console.log('  page head:', who);

    // ---- 2. 打开公告页 ----
    console.log('[2] 打开公告管理页');
    await send('Page.navigate', { url: BASE + '/index.php?a=admin&tab=notices' });
    await waitReady();
    await sleep(400);
    const hasEditor = await evalJS(`!!document.querySelector('[data-editor][data-draft-key]')`);
    if (!ok(hasEditor, '公告编辑器存在')) failures++;
    // 与 index.js 的 draftKey() 同款算法（空串会回落到 'default'），否则会算出错误的 key
    const draftKey = await evalJS(`
      (function(){
        var e=document.querySelector('[data-editor][data-draft-key]');
        return 'owlsgo-draft-' + (e.getAttribute('data-draft-key') || 'default');
      })()`);
    console.log('  storage key =', JSON.stringify(draftKey));

    // ---- 3. 填内容（含标题），触发 input 让 JS 落草稿 ----
    console.log('[3] 填入标题+内容，验证草稿落盘');
    const MARK = 'DRAFT-PROBE-' + Date.now();
    await evalJS(`
      (function(){
        var f=document.querySelector('form');
        var t=f.querySelector('[name=title]'); t.value='测试公告 ${MARK}';
        t.dispatchEvent(new Event('input',{bubbles:true}));
        var a=document.querySelector('[data-editor][data-draft-key] textarea');
        a.value='内容残留验证 ${MARK}';
        a.dispatchEvent(new Event('input',{bubbles:true}));
        return 'filled';
      })()`);
    await sleep(700); // 等 400ms debounce 写入 localStorage
    const lsKey = draftKey;
    const lsBefore = await evalJS(`window.localStorage.getItem(${JSON.stringify(lsKey)})`);
    if (!ok(!!lsBefore && String(lsBefore).indexOf(MARK) >= 0, 'localStorage 草稿已写入', JSON.stringify(lsBefore).slice(0, 90))) failures++;

    // ---- 4. 提交表单 ----
    console.log('[4] 提交公告表单');
    await evalJS(`document.querySelector('form').submit()`);
    const back = await waitReady();
    await sleep(600);
    const urlNow = await evalJS('location.href');
    console.log('  now at:', urlNow);
    const backToNotices = /tab=notices/.test(urlNow);
    if (!ok(backToNotices, '提交后重定向回公告页')) failures++;

    // ---- 5. 断言：编辑框内容为空 + 草稿被清 + done 标记 ----
    console.log('[5] 提交后断言');
    const areaVal = await evalJS(`document.querySelector('[data-editor][data-draft-key] textarea').value`);
    if (!ok(!areaVal, '编辑框内容已清空', JSON.stringify(areaVal).slice(0, 60))) failures++;

    const lsAfter = await evalJS(`window.localStorage.getItem(${JSON.stringify(lsKey)})`);
    if (!ok(lsAfter === null, 'localStorage 草稿已移除', JSON.stringify(lsAfter) )) failures++;

    const doneFlag = await evalJS(`window.sessionStorage.getItem(${JSON.stringify(lsKey + '-done')})`);
    if (!ok(doneFlag === '1', '已提交标记已写入 sessionStorage', JSON.stringify(doneFlag))) failures++;

    // ---- 6. 对照：刷新（同会话重访）不应恢复旧草稿 ----
    console.log('[6] 刷新页面（对照组）');
    await send('Page.navigate', { url: BASE + '/index.php?a=admin&tab=notices' });
    await waitReady();
    await sleep(700);
    const areaVal2 = await evalJS(`document.querySelector('[data-editor][data-draft-key] textarea').value`);
    if (!ok(!areaVal2, '刷新后编辑框仍为空', JSON.stringify(areaVal2).slice(0, 60))) failures++;

    // ---- 7. 新编辑器场景（无标记时草稿恢复仍正常）----
    console.log('[7] 反向验证：新起草草稿能被恢复');
    await evalJS(`
      (function(){
        var a=document.querySelector('[data-editor][data-draft-key] textarea');
        a.value='新草稿ABC-${MARK}';
        a.dispatchEvent(new Event('input',{bubbles:true}));
      })()`);
    await sleep(500);
    await send('Page.navigate', { url: BASE + '/index.php?a=admin&tab=notices' });
    await waitReady();
    await sleep(800);
    const areaVal3 = await evalJS(`document.querySelector('[data-editor][data-draft-key] textarea').value`);
    if (!ok(areaVal3 === '新草稿ABC-' + MARK, '未提交的草稿仍能正常恢复', JSON.stringify(areaVal3).slice(0, 70))) failures++;

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
