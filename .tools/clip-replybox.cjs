'use strict';
const { spawn } = require('child_process');
const fs=require('fs'),path=require('path'),os=require('os');
const URL_=process.argv[2]; const OUT=process.argv[3]||'clip.png'; const PORT=9343;
const sleep=(ms)=>new Promise(r=>setTimeout(r,ms));
function findChrome(){for(const c of ['C:/Program Files/Google/Chrome/Application/chrome.exe','C:/Program Files (x86)/Google/Chrome/Application/chrome.exe','C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe','C:/Program Files/Microsoft/Edge/Application/msedge.exe'])if(fs.existsSync(c))return c;throw new Error('no chrome');}
async function main(){
  const chrome=spawn(findChrome(),['--headless=new','--remote-debugging-port='+PORT,'--user-data-dir='+fs.mkdtempSync(path.join(os.tmpdir(),'cdp-cr-')),'--no-first-run','--window-size=1100,900','about:blank'],{stdio:'ignore'});
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
    await sleep(300);
    const rect=await send('Runtime.evaluate',{expression:"(function(){var el=document.querySelector(process.env.SEL||'#reply-box');if(!el)return null;el.scrollIntoView({block:'center'});var r=el.getBoundingClientRect();return JSON.stringify({x:r.x+window.scrollX,y:r.y+window.scrollY,w:r.width,h:r.height});})()".replace('process.env.SEL',JSON.stringify(process.env.SEL||'#reply-box')),returnByValue:true});
    if(!rect.result.result.value){console.log('NO ELEMENT');return;}
    const c=JSON.parse(rect.result.result.value);
    await sleep(250);
    const shot=await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:true,clip:{x:Math.max(0,c.x-6),y:Math.max(0,c.y-6),width:c.w+12,height:Math.min(c.h+12,520),scale:2}});
    fs.writeFileSync(OUT,Buffer.from(shot.result.data,'base64'));
    console.log('SHOT -> '+OUT+'  rect='+JSON.stringify(c));
    ws.close();
  } finally { chrome.kill(); await sleep(150); }
}
main().catch(e=>{console.error('FAIL:',e.message);process.exit(1);});
