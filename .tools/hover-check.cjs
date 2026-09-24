'use strict';
const { spawn } = require('child_process');
const fs = require('fs'); const path = require('path'); const os = require('os');
const URL_ = process.argv[2];
const PORT = 9337;
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
function findChrome() {
  for (const c of ['C:/Program Files/Google/Chrome/Application/chrome.exe','C:/Program Files (x86)/Google/Chrome/Application/chrome.exe','C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe','C:/Program Files/Microsoft/Edge/Application/msedge.exe']) if (fs.existsSync(c)) return c;
  throw new Error('no chrome');
}
async function main() {
  const chrome = spawn(findChrome(), ['--headless=new','--remote-debugging-port='+PORT,'--user-data-dir='+fs.mkdtempSync(path.join(os.tmpdir(),'cdp-hv-')),'--no-first-run','--window-size=1000,900','about:blank'],{stdio:'ignore'});
  try {
    let target=null;
    for(let i=0;i<50;i++){await sleep(200);try{const l=await(await fetch('http://127.0.0.1:'+PORT+'/json/list')).json();target=l.find(t=>t.type==='page');if(target)break;}catch(e){}}
    const ws=new WebSocket(target.webSocketDebuggerUrl);
    await new Promise((res,rej)=>{ws.onopen=res;ws.onerror=rej;});
    let id=0;const pending=new Map();
    ws.onmessage=(ev)=>{const m=JSON.parse(ev.data);if(m.id&&pending.has(m.id)){pending.get(m.id)(m);pending.delete(m.id);}};
    const send=(method,params={})=>new Promise((res)=>{const mid=++id;pending.set(mid,res);ws.send(JSON.stringify({id:mid,method,params}));});
    await send('Page.enable');
    await send('Page.navigate',{url:URL_});
    for(let i=0;i<40;i++){await sleep(250);const r=await send('Runtime.evaluate',{expression:'document.readyState',returnByValue:true});if(r.result?.result?.value==='complete')break;}
    await send('CSS.enable');
    const r=await send('Runtime.evaluate',{expression:`
      (function(){
        var sels=['.main-nav a','.thread-item-title a','.thread-item-meta a','.thread-item-forum','.brand','.tab','.btn','.pager-item','.user-link','.forum-tag','.stat a'];
        var out=[];
        sels.forEach(function(s){
          var el=document.querySelector(s);
          if(!el){out.push(s+' : 元素不存在');return;}
          var base=getComputedStyle(el).textDecorationLine;
          // 伪造 hover：用 matches(':hover') 不可控，改用直接读取 CSS 规则
          out.push(s+' : 常态='+base);
        });
        return out.join('\\n');
      })()`,returnByValue:true});
    console.log(r.result.result.value);
    // 直接在页面里扫全部样式表，找任何 hover 下划线声明
    const scan=await send('Runtime.evaluate',{expression:`
      (function(){
        var hits=[];
        for(var i=0;i<document.styleSheets.length;i++){
          var rules; try{rules=document.styleSheets[i].cssRules;}catch(e){continue;}
          for(var j=0;j<rules.length;j++){
            var t=rules[j].cssText||'';
            if(t.indexOf(':hover')>=0 && /text-decoration[^;]*underline/.test(t)) hits.push(t.slice(0,120));
          }
        }
        return hits.length? hits.join('\\n---\\n') : 'NONE: 全站无 hover 下划线声明';
      })()`,returnByValue:true});
    console.log('--- hover 下划线扫描 ---');
    console.log(scan.result.result.value);
    ws.close();
  } finally { chrome.kill(); await sleep(150); }
}
main().catch(e=>{console.error('FAIL:',e.message);process.exit(1);});
