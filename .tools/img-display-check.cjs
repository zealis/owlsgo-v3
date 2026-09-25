#!/usr/bin/env node
/* 图片展示探针：验证正文图片真的加载渲染（naturalWidth>0，非破图）。
 * 用法：OWL_LOGIN=u:p THREAD=7 node img-display-check.cjs <baseUrl> [out.png]
 */
'use strict';
const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');
const os = require('os');

const BASE = (process.argv[2] || 'http://127.0.0.1:8099').replace(/\/$/, '');
const OUT = process.argv[3] || '';
const PORT = 9336;
const THREAD = process.env.THREAD || '7';

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

async function main() {
  const exe = findChrome();
  const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'cdp-img-'));
  const chrome = spawn(exe, [
    '--headless=new', `--remote-debugging-port=${PORT}`,
    `--user-data-dir=${profile}`, '--no-first-run', '--no-default-browser-check',
    '--ignore-certificate-errors', '--window-size=1280,900', 'about:blank',
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
    let id = 0; const pending = new Map();
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
      if (r.result?.exceptionDetails) throw new Error('JS ERR ' + (r.result.exceptionDetails.text || ''));
      return r.result?.result?.value;
    };

    await send('Page.enable');
    await send('Network.enable');

    // 登录
    const [user, pass] = (process.env.OWL_LOGIN || '').split(':');
    await send('Page.navigate', { url: BASE + '/index.php?a=login' });
    await sleep(900);
    await evalJS(`
      (function(){
        var f=document.querySelector('form'); if(!f) return 'NO_FORM';
        var q=document.querySelector('.captcha-q');
        var ans=q?(q.textContent.match(/(\\d+)\\s*\\+\\s*(\\d+)/)||[]).slice(1).reduce(function(a,b){return +a + +b;},0):'';
        f.querySelector('[name=login]').value=${JSON.stringify(user)};
        f.querySelector('[name=pass]').value=${JSON.stringify(pass)};
        var c=f.querySelector('[name=_captcha]'); if(c) c.value=ans;
        f.submit(); return 'ok';
      })()`);
    for (let i = 0; i < 40; i++) {
      await sleep(250);
      const st = await evalJS('document.readyState + "|" + location.pathname');
      if (String(st).startsWith('complete|') && !String(st).includes('login')) break;
    }

    // 打开帖子页，等图片加载
    await send('Page.navigate', { url: `${BASE}/thread/${THREAD}` });
    for (let i = 0; i < 40; i++) {
      await sleep(250);
      if ((await evalJS('document.readyState')) === 'complete') break;
    }
    await sleep(1500); // 等 lazy-load 的图片真正解码

    const info = await evalJS(`
      (function(){
        var imgs = Array.prototype.slice.call(document.querySelectorAll('.content-image'));
        return JSON.stringify(imgs.map(function(im){
          var r = im.getBoundingClientRect();
          return {
            src: im.getAttribute('src'),
            complete: im.complete,
            naturalWidth: im.naturalWidth,
            naturalHeight: im.naturalHeight,
            displayW: Math.round(r.width),
            displayH: Math.round(r.height),
            cssMaxWidth: getComputedStyle(im).maxWidth
          };
        }));
      })()`);
    const imgs = JSON.parse(info || '[]');
    console.log('正文图片数量:', imgs.length);
    imgs.forEach((im, i) => {
      const good = im.complete && im.naturalWidth > 0 && im.displayW > 0;
      console.log(`  #${i} ${good ? 'PASS' : 'FAIL'} src=${im.src}`);
      console.log(`      complete=${im.complete} natural=${im.naturalWidth}x${im.naturalHeight} 显示=${im.displayW}x${im.displayH} max-width=${im.cssMaxWidth}`);
      if (!good) failures++;
    });
    if (!imgs.length) { console.log('  FAIL 正文没有渲染出任何 .content-image'); failures++; }

    // 残留语法检查
    const leftover = await evalJS(`
      (function(){
        var body = document.body.innerText;
        var m = body.match(/!\\[[^\\]]*\\]/g);
        return m ? m.join(',') : '';
      })()`);
    console.log('残留图片语法:', leftover ? leftover : '(无)');
    if (leftover) failures++;

    if (OUT) {
      const shot = await send('Page.captureScreenshot', { format: 'png' });
      fs.writeFileSync(OUT, Buffer.from(shot.result.data, 'base64'));
      console.log('SHOT ->', OUT);
    }
    ws.close();
  } finally {
    chrome.kill();
    await sleep(200);
    try { fs.rmSync(profile, { recursive: true, force: true }); } catch (e) {}
  }
  console.log(failures ? `\n${failures} 项失败` : '\n图片展示正常');
  process.exit(failures ? 1 : 0);
}
main().catch((e) => { console.error('PROBE FAIL:', e.message); process.exit(1); });
