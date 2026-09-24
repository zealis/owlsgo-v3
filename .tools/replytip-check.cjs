'use strict';
const { spawn } = require('child_process');
const fs=require('fs'),path=require('path'),os=require('os');
const URL_=process.argv[2]; const PORT=9341;
const sleep=(ms)=>new Promise(r=>setTimeout(r,ms));
function findChrome(){for(const c of ['C:/Program Files/Google/Chrome/Application/chrome.exe','C:/Program Files (x86)/Google/Chrome/Application/chrome.exe','C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe','C:/Program Files/Microsoft/Edge/Application/msedge.exe'])if(fs.existsSync(c))return c;throw new Error('no chrome');}
async function main(){
  const chrome=spawn(findChrome(),['--headless=new','--remote-debugging-port='+PORT,'--user-data-dir='+fs.mkdtempSync(path.join(os.tmpdir(),'cdp-rt-')),'--no-first-run','--window-size=1000,900','about:blank'],{stdio:'ignore'});
  try{
    let target=null;
    for(let i=0;i<50;i++){await sleep(200);try{const l=await(await fetch('http://127.0.0.1:'+PORT+'/json/list')).json();target=l.find(t=>t.type==='page');if(target)break;}catch(e){}}
    const ws=new WebSocket(target.webSocketDebuggerUrl);
    await new Promise((res,rej)=>{ws.onopen=res;ws.onerror=rej;});
    let id=0;const pending=new Map();
    ws.onmessage=(ev)=>{const m=JSON.parse(ev.data);if(m.id&&pending.has(m.id)){pending.get(m.id)(m);pending.delete(m.id);}};
    const send=(method,params={})=>new Promise((res)=>{const mid=++id;pending.set(mid,res);ws.send(JSON.stringify({id:mid,method,params}));});
    await send('Page.enable');
    if(process.env.OWL_USER){
      const origin=new URL(URL_).origin;
      await send('Page.navigate',{url:origin+'/index.php?a=login'});await sleep(900);
      const expr="(function(){var f=document.querySelector('form');var q=document.querySelector('.captcha-q');var ans=q?(q.textContent.match(/(\\d+)\\s*\\+\\s*(\\d+)/)||[]).slice(1).reduce(function(a,b){return +a + +b;},0):'';f.querySelector('[name=login]').value="+JSON.stringify(process.env.OWL_USER)+";f.querySelector('[name=pass]').value="+JSON.stringify(process.env.OWL_PASS||'')+";var c=f.querySelector('[name=_captcha]');if(c)c.value=ans;f.submit();return 1;})()";
      await send('Runtime.evaluate',{expression:expr,returnByValue:true,userGesture:true});
      await sleep(2200);
    }
    await send('Page.navigate',{url:URL_});
    for(let i=0;i<40;i++){await sleep(250);const r=await send('Runtime.evaluate',{expression:'document.readyState',returnByValue:true});if(r.result?.result?.value==='complete')break;}
    // 1) 默认状态
    const before=await send('Runtime.evaluate',{expression:`(function(){var t=document.querySelector('.reply-tip');if(!t)return 'NO_TIP';var r=t.getBoundingClientRect();return JSON.stringify({hidden:t.hidden,display:getComputedStyle(t).display,w:Math.round(r.width),h:Math.round(r.height)});})()`,returnByValue:true});
    console.log('默认状态 reply-tip:', before.result.result.value);
    // 2) 点某楼“回复”按钮后
    const after=await send('Runtime.evaluate',{expression:`(function(){
      var btn=document.querySelector('[data-reply-to]');
      if(!btn) return 'NO_REPLY_BTN';
      btn.click();
      var t=document.querySelector('.reply-tip');
      var r=t.getBoundingClientRect();
      return JSON.stringify({hidden:t.hidden,display:getComputedStyle(t).display,text:t.textContent.trim(),h:Math.round(r.height)});
    })()`,returnByValue:true,userGesture:true});
    console.log('点击“回复”后 reply-tip:', after.result.result.value);
    ws.close();
  } finally { chrome.kill(); await sleep(150); }
}
main().catch(e=>{console.error('FAIL:',e.message);process.exit(1);});
