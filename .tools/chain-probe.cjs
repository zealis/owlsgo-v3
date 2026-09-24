#!/usr/bin/env node
/* 量 body/html/wrap/admin 链条宽度 */
'use strict';
const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');
const os = require('os');
const URL_ = process.argv[2];
const PORT = 9335;
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
function findChrome() {
  for (const c of ['C:/Program Files/Google/Chrome/Application/chrome.exe','C:/Program Files (x86)/Google/Chrome/Application/chrome.exe','C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe','C:/Program Files/Microsoft/Edge/Application/msedge.exe']) if (fs.existsSync(c)) return c;
  throw new Error('no chrome');
}
async function main() {
  const chrome = spawn(findChrome(), ['--headless=new','--remote-debugging-port='+PORT,'--user-data-dir='+fs.mkdtempSync(path.join(os.tmpdir(),'cdp-ch-')),'--no-first-run','--ignore-certificate-errors','--window-size=500,1000','about:blank'],{stdio:'ignore'});
  try {
    let target=null;
    for(let i=0;i<50;i++){await sleep(200);try{const l=await(await fetch('http://127.0.0.1:'+PORT+'/json/list')).json();target=l.find(t=>t.type==='page');if(target)break;}catch(e){}}
    const ws=new WebSocket(target.webSocketDebuggerUrl);
    await new Promise((res,rej)=>{ws.onopen=res;ws.onerror=rej;});
    let id=0;const pending=new Map();
    ws.onmessage=(ev)=>{const m=JSON.parse(ev.data);if(m.id&&pending.has(m.id)){pending.get(m.id)(m);pending.delete(m.id);}};
    const send=(method,params={})=>new Promise((res)=>{const mid=++id;pending.set(mid,res);ws.send(JSON.stringify({id:mid,method,params}));});
    await send('Page.enable');
    const origin=new URL(URL_).origin;
    if(process.env.OWL_LOGIN){
      const [u,p]=process.env.OWL_LOGIN.split(':');
      await send('Page.navigate',{url:origin+'/index.php?a=login'});
      await sleep(800);
      await send('Runtime.evaluate',{expression:`(function(){var f=document.querySelector('form');var q=document.querySelector('.captcha-q');var ans=q?(q.textContent.match(/(\\d+)\\s*\\+\\s*(\\d+)/)||[]).slice(1).reduce(function(a,b){return +a + +b;},0):'';f.querySelector('[name=login]').value=${JSON.stringify(u)};f.querySelector('[name=pass]').value=${JSON.stringify(p)};var c=f.querySelector('[name=_captcha]');if(c)c.value=ans;f.submit();return 1;})()`,returnByValue:true,userGesture:true});
      await sleep(2000);
    }
    await send('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true});
    await send('Page.navigate',{url:URL_});
    for(let i=0;i<40;i++){await sleep(250);const r=await send('Runtime.evaluate',{expression:'document.readyState',returnByValue:true});if(r.result?.result?.value==='complete')break;}
    await sleep(300);
    const m=await send('Runtime.evaluate',{expression:`
      (function(){
        function w(sel){var el=document.querySelector(sel);if(!el)return '-';var r=el.getBoundingClientRect();var cs=getComputedStyle(el);return sel+': w='+Math.round(r.width)+' display='+cs.display+(cs.flexDirection!='row'?' '+cs.flexDirection:'')+(cs.flexWrap&&cs.flexWrap!='nowrap'?' '+cs.flexWrap:'');}
        var de=document.documentElement;
        return JSON.stringify({
          innerWidth:window.innerWidth,
          htmlScrollW:de.scrollWidth, bodyScrollW:document.body.scrollWidth,
          chain:[w('html'),w('body'),w('.wrap'),w('.admin'),w('.admin-main'),w('.tabbar'),w('.bulk-bar'),w('.bulk-bar select'),w('.bulk-bar input:not([type=checkbox])'),w('.panel-head')]
        },null,1);
      })()`,returnByValue:true});
    console.log(m.result.result.value);
    ws.close();
  } finally { chrome.kill(); await sleep(150); }
}
main().catch(e=>{console.error('FAIL:',e.message);process.exit(1);});
