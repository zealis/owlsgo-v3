#!/usr/bin/env node
/* 后台横向溢出探针：小视口逐页量 scrollWidth，揪出溢出元素。
 * 用法：OWL_LOGIN="user:pass" node overflow-probe.cjs <url1> <url2> ...
 */
'use strict';
const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');
const os = require('os');

const URLS = process.argv.slice(2);
const PORT = 9334;
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

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

async function main() {
  const exe = findChrome();
  const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'cdp-ovf-'));
  const chrome = spawn(exe, [
    '--headless=new', `--remote-debugging-port=${PORT}`,
    `--user-data-dir=${profile}`, '--no-first-run', '--no-default-browser-check',
    '--ignore-certificate-errors', '--window-size=500,1000', 'about:blank',
  ], { stdio: 'ignore' });

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

    await send('Page.enable');
    // 自登录（首个 URL 的 origin）
    const origin = new URL(URLS[0]).origin;
    if (process.env.OWL_LOGIN) {
      const [user, pass] = process.env.OWL_LOGIN.split(':');
      await send('Page.navigate', { url: origin + '/index.php?a=login' });
      await sleep(800);
      await send('Runtime.evaluate', { expression: `
        (function(){
          var f=document.querySelector('form');
          var q=document.querySelector('.captcha-q');
          var ans=q?(q.textContent.match(/(\\d+)\\s*\\+\\s*(\\d+)/)||[]).slice(1).reduce(function(a,b){return +a + +b;},0):'';
          f.querySelector('[name=login]').value=${JSON.stringify(user)};
          f.querySelector('[name=pass]').value=${JSON.stringify(pass)};
          var c=f.querySelector('[name=_captcha]'); if(c) c.value=ans;
          f.submit(); return 1;
        })()`, returnByValue: true, userGesture: true });
      await sleep(1500);
      for (let i = 0; i < 20; i++) {
        await sleep(250);
        const r = await send('Runtime.evaluate', { expression: 'document.readyState+"|"+location.search', returnByValue: true });
        if (r.result?.result?.value === 'complete|') break;
      }
    }
    await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });

    for (const u of URLS) {
      await send('Page.navigate', { url: u });
      for (let i = 0; i < 40; i++) {
        await sleep(250);
        const r = await send('Runtime.evaluate', { expression: 'document.readyState', returnByValue: true });
        if (r.result?.result?.value === 'complete') break;
      }
      await sleep(300);
      const m = await send('Runtime.evaluate', { expression: `
        (function(){
          var de=document.documentElement;
          var vw=window.innerWidth, sw=de.scrollWidth;
          var BASE=390;
          var out={vw:vw, sw:sw, bad:[], title:document.title.slice(0,40)};
          if(sw>BASE+1){
            var all=document.querySelectorAll('*');
            for(var i=0;i<all.length;i++){
              var el=all[i], r=el.getBoundingClientRect();
              if(r.width>BASE+1 && r.right>BASE+1){
                out.bad.push({tag:el.tagName.toLowerCase(), cls:(el.className+'').slice(0,40), w:Math.round(r.width), right:Math.round(r.right)});
              }
            }
            out.bad.sort(function(a,b){return b.w-a.w;});
            out.bad=out.bad.slice(0,6);
          }
          return JSON.stringify(out);
        })()`, returnByValue: true });
      const o = JSON.parse(m.result.result.value);
      const flag = o.sw > o.vw + 1 ? '❌溢出' : '✅';
      console.log(`${flag} [${o.title}] vw=${o.vw} sw=${o.sw}`);
      for (const b of o.bad) console.log(`    -> <${b.tag} class="${b.cls}"> w=${b.w} right=${b.right}`);
    }
    ws.close();
  } finally {
    chrome.kill();
    await sleep(150);
    try { fs.rmSync(profile, { recursive: true, force: true }); } catch (e) {}
  }
}

main().catch((e) => { console.error('PROBE FAIL:', e.message); process.exit(1); });
