#!/usr/bin/env node
/* CDP 截图探针：无外部依赖（Node >=22 自带 WebSocket/fetch）。
 * 用法：node shot.cjs <url> <out.png> [width] [height] [mobile]
 * Cookie：内置 edtest 会话变量 COOKIE_AUTH（可被环境变量覆盖）
 */
'use strict';
const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');
const os = require('os');

const [, , URL_, OUT, W = '1280', H = '900', MOBILE = '0'] = process.argv;
const PORT = 9333; // 别用 9222：WorkBuddy 的 Electron 蹲在那儿
const AUTH = process.env.OWL_AUTH || '';

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
  const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'cdp-probe-'));
  const chrome = spawn(exe, [
    '--headless=new', `--remote-debugging-port=${PORT}`,
    `--user-data-dir=${profile}`, '--no-first-run', '--no-default-browser-check',
    '--ignore-certificate-errors', '--window-size=1400,1000', 'about:blank',
  ], { stdio: 'ignore' });

  try {
    // 等 CDP 端口就绪
    let target = null;
    for (let i = 0; i < 50; i++) {
      await sleep(200);
      try {
        const list = await (await fetch(`http://127.0.0.1:${PORT}/json/list`)).json();
        target = list.find((t) => t.type === 'page');
        if (target) break;
      } catch (e) { /* retry */ }
    }
    if (!target) throw new Error('CDP 未就绪');

    const ws = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise((res, rej) => { ws.onopen = res; ws.onerror = rej; });

    let id = 0;
    const pending = new Map();
    ws.onmessage = (ev) => {
      const m = JSON.parse(ev.data);
      if (m.method === 'Network.requestWillBeSent' && m.params.type === 'Document') {
        console.log('DOC GET', m.params.request.url.slice(0, 80));
        console.log('  Cookie:', (m.params.request.headers.Cookie || '(none)').slice(0, 120));
      }
      if (m.id && pending.has(m.id)) { pending.get(m.id)(m); pending.delete(m.id); }
    };
    const send = (method, params = {}) => new Promise((res) => {
      const mid = ++id;
      pending.set(mid, res);
      ws.send(JSON.stringify({ id: mid, method, params }));
    });

    await send('Page.enable');
    await send('Network.enable');
    // 浏览器自登录：填登录表单（含明文算术验证码）→ 提交 → 服务端自己种 cookie
    if (process.env.OWL_LOGIN) {
      const [user, pass] = process.env.OWL_LOGIN.split(':');
      const origin = new URL(URL_).origin;
      await send('Page.navigate', { url: origin + '/index.php?a=login' });
      await sleep(800);
      const login = await send('Runtime.evaluate', { expression: `
        (function(){
          var f=document.querySelector('form');
          if(!f) return 'NO_FORM';
          var q=document.querySelector('.captcha-q');
          var ans=q?(q.textContent.match(/(\\d+)\\s*\\+\\s*(\\d+)/)||[]).slice(1).reduce(function(a,b){return +a + +b;},0):'';
          f.querySelector('[name=login]').value=${JSON.stringify(user)};
          f.querySelector('[name=pass]').value=${JSON.stringify(pass)};
          var c=f.querySelector('[name=_captcha]'); if(c) c.value=ans;
          f.submit(); return 'SUBMIT ans='+ans+' token='+(!!f.querySelector('[name=_token]'));
        })()`, returnByValue: true, userGesture: true });
      console.log('login:', login.result?.result?.value);
      await sleep(1200);
      for (let i = 0; i < 20; i++) {
        await sleep(250);
        const r = await send('Runtime.evaluate', { expression: 'document.readyState+"|"+location.search', returnByValue: true });
        if (r.result?.result?.value === 'complete|') break; // 登录成功跳回首页（无参数）
      }
    }
      const after = await send('Runtime.evaluate', { expression: 'document.body.innerText.replace(/\\s+/g," ").slice(0,180)', returnByValue: true });
      console.log('after-login:', after.result?.result?.value);
    await send('Emulation.setDeviceMetricsOverride', {
      width: +W, height: +H,
      deviceScaleFactor: process.env.DSF ? +process.env.DSF : 1,
      mobile: MOBILE === '1',
    });
    await send('Page.navigate', { url: URL_ });
    // 等加载：轮询 readyState + 350ms 缓冲（比监听 loadEventFired 稳）
    for (let i = 0; i < 40; i++) {
      await sleep(250);
      const r = await send('Runtime.evaluate', { expression: 'document.readyState', returnByValue: true });
      if (r.result?.result?.value === 'complete') break;
    }
    await sleep(400);

    // 诊断：工具栏每个子元素的几何位置（看垂直错位）
    const dbg = await send('Runtime.evaluate', {
      expression: `(function(){
        var bar=document.querySelector('.editor-bar');
        if(!bar) return 'NO_BAR';
        return JSON.stringify(Array.from(bar.children).map(function(el){
          var r=el.getBoundingClientRect();
          var cs=getComputedStyle(el);
          return {tag:el.tagName.toLowerCase(), cls:el.className, y:+r.y.toFixed(1), h:+r.height.toFixed(1),
                  mt:cs.marginTop, mb:cs.marginBottom, aself:cs.alignSelf, va:cs.verticalAlign, disp:cs.display};
        }));
      })()`,
      returnByValue: true,
    });
    console.log('bar-children:', JSON.stringify(dbg.result?.result?.value, null, 1));
    const cookies = await send('Network.getCookies', { urls: [URL_] });
    console.log('cookies:', JSON.stringify(cookies.result?.cookies?.map((c) => c.name)));

    // CLIP=upload/bar/big：局部放大截图
    let shotParams = { format: 'png' };
    if (process.env.CLIP) {
      if (process.env.CLIP === 'big') {
        await send('Runtime.evaluate', { expression: `
          (function(){
            var svg=document.querySelector('.editor-upload svg')||document.querySelector('.editor-bar svg');
            var big=svg.cloneNode(true);
            big.style.width='72px'; big.style.height='72px';
            big.style.position='fixed'; big.style.left='20px'; big.style.top='20px';
            big.style.background='#fff'; big.id='bigicon';
            document.body.appendChild(big);
          })()`, returnByValue: true });
      }
      const sel = process.env.CLIP === 'upload' ? '.editor-upload'
        : process.env.CLIP === 'bar' ? '.editor-bar' : '#bigicon';
      const bb = await send('Runtime.evaluate', {
        expression: '(function(){var b=document.querySelector(' + JSON.stringify(sel) + ').getBoundingClientRect();return JSON.stringify({x:b.x,y:b.y,width:b.width,height:b.height});})()',
        returnByValue: true,
      });
      const c = JSON.parse(bb.result.result.value);
      shotParams = { format: 'png', clip: { x: Math.max(0, c.x - 4), y: Math.max(0, c.y - 4), width: c.width + 8, height: c.height + 8, scale: 4 } };
    }
    const shot = await send('Page.captureScreenshot', shotParams);
    fs.writeFileSync(OUT, Buffer.from(shot.result.data, 'base64'));
    console.log('SHOT OK ->', OUT);
    ws.close();
  } finally {
    chrome.kill();
    await sleep(150);
    try { fs.rmSync(profile, { recursive: true, force: true }); } catch (e) {}
  }
}

main().catch((e) => { console.error('SHOT FAIL:', e.message); process.exit(1); });
