// 备用推送：github.com:443 直连被断但 api.github.com 可达时，经 Git Data API 推送
// 用法：node .tools/push-via-api.cjs "提交信息"
// 前提：本地已 git commit；脚本把工作区全部跟踪文件经 API 建树提交并快进 origin/main
const { spawnSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const ROOT = __dirname + '/..';
const OWNER = 'zealis';
const REPO = 'owlsgo-v3';
const API = `https://api.github.com/repos/${OWNER}/${REPO}/git`;
const MESSAGE = process.argv[2] || 'update';

const cred = spawnSync('git', ['credential', 'fill'], { input: 'protocol=https\nhost=github.com\n', encoding: 'utf8' });
const token = (cred.stdout.match(/^password=(.+)$/m) || [])[1];
if (!token) { console.error('no token'); process.exit(1); }

async function api(url, body, method = 'POST') {
  const res = await fetch(url, { method, headers: { Authorization: `token ${token}`, Accept: 'application/vnd.github+json', 'Content-Type': 'application/json' }, body: body ? JSON.stringify(body) : undefined });
  const data = await res.json();
  if (!res.ok) throw new Error(`${res.status}: ${JSON.stringify(data).slice(0, 200)}`);
  return data;
}

function sh(cmd) { return spawnSync(cmd, { shell: true, cwd: ROOT, encoding: 'utf8' }).stdout.trim(); }

(async () => {
  // 远端 main 当前提交
  const ref = await api(`${API}/refs/heads/main`, null, 'GET');
  const parent = ref.object.sha;
  console.log('remote main =', parent);
  // 本地跟踪文件
  const files = sh('git ls-files').split('\n').filter(Boolean);
  const tree = [];
  for (const rel of files) {
    const content = fs.readFileSync(path.join(ROOT, rel));
    const blob = await api(`${API}/blobs`, { content: content.toString('base64'), encoding: 'base64' });
    tree.push({ path: rel, mode: '100644', type: 'blob', sha: blob.sha });
  }
  const t = await api(`${API}/trees`, { tree });
  const c = await api(`${API}/commits`, { message: MESSAGE, tree: t.sha, parents: [parent] });
  await api(`${API}/refs/heads/main`, { sha: c.sha, force: false }, 'PATCH');
  console.log('pushed via API:', c.sha, `(${files.length} files)`);
  console.log('提示：联网恢复后执行 git fetch origin && git reset --hard origin/main 对齐本地 SHA');
})().catch(e => { console.error('ERR', e.message); process.exit(1); });
