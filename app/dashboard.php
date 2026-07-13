<?php require __DIR__ . '/config.php'; check_auth(); ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Outlook 邮箱助手</title>
<link rel="icon" href="static/image/favicon/favicon.svg">
<style>
:root {
  --bg:#f4f6fb; --card:#fff; --line:#e8edf5; --text:#0f172a; --muted:#64748b;
  --main:#2563eb; --main2:#1d4ed8; --ok:#16a34a; --bad:#dc2626; --warn:#d97706;
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif}
nav{position:sticky;top:0;z-index:50;display:flex;justify-content:space-between;align-items:center;gap:12px;padding:12px 20px;background:rgba(255,255,255,.85);backdrop-filter:blur(12px);border-bottom:1px solid var(--line)}
.brand{font-weight:800;font-size:18px}
.brand span{color:var(--main)}
.nav-actions{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.search{border:1px solid var(--line);background:#f8fafc;border-radius:10px;padding:8px 12px;width:220px;outline:none}
.btn{border:1px solid var(--line);background:#fff;border-radius:10px;padding:8px 12px;font-size:13px;cursor:pointer;font-weight:600}
.btn:hover{background:#f8fafc}
.btn-main{background:var(--main);border-color:var(--main);color:#fff}
.btn-main:hover{background:var(--main2)}
.btn-ok{background:#ecfdf5;border-color:#bbf7d0;color:#166534}
.btn-bad{background:#fef2f2;border-color:#fecaca;color:#991b1b}
.btn:disabled{opacity:.55;cursor:not-allowed}
.wrap{max-width:1280px;margin:0 auto;padding:20px}
.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:18px}
.stat{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:16px}
.stat .n{font-size:28px;font-weight:800}
.stat .l{color:var(--muted);font-size:12px;margin-top:4px}
.toolbar{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px;align-items:center}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:14px}
.card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:16px;cursor:pointer;position:relative;transition:.2s}
.card:hover{transform:translateY(-2px);box-shadow:0 12px 30px rgba(15,23,42,.06)}
.card.selected{outline:2px solid var(--main);background:#eff6ff}
.selbox{width:16px;height:16px;accent-color:var(--main);cursor:pointer}
.bulkbar{display:none;position:sticky;bottom:16px;z-index:30;max-width:1280px;margin:0 auto;padding:12px 16px;background:#0f172a;color:#e2e8f0;border-radius:14px;box-shadow:0 12px 40px rgba(0,0,0,.25);align-items:center;gap:10px;flex-wrap:wrap}
.bulkbar.show{display:flex}

.card h3{margin:0 0 8px;font-size:15px;word-break:break-all}
.card p{margin:0;color:var(--muted);font-size:13px;min-height:18px}
.badge{display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:700;border-radius:999px;padding:3px 8px}
.badge-live{background:#dcfce7;color:#166534}
.badge-dead{background:#fee2e2;color:#991b1b}
.badge-unknown{background:#f1f5f9;color:#475569}
.card-top{display:flex;justify-content:space-between;gap:8px;align-items:flex-start;margin-bottom:8px}
.card-actions{display:flex;gap:6px}
.icon-btn{width:30px;height:30px;border-radius:8px;border:1px solid var(--line);background:#f8fafc;cursor:pointer}
.meta{margin-top:12px;font-size:11px;color:#94a3b8;font-family:ui-monospace,Consolas,monospace}
.empty{grid-column:1/-1;text-align:center;padding:80px 20px;color:var(--muted)}
.modal{display:none;position:fixed;inset:0;background:rgba(15,23,42,.35);backdrop-filter:blur(6px);z-index:100;align-items:center;justify-content:center;padding:16px}
.modal.show{display:flex}
.modal-box{background:#fff;border-radius:18px;width:min(1100px,96vw);max-height:90vh;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 30px 80px rgba(0,0,0,.2)}
.modal-sm{width:min(520px,96vw)}
.modal-lg{width:min(920px,96vw);max-height:92vh}
.modal-hd{display:flex;justify-content:space-between;align-items:center;padding:16px 18px;border-bottom:1px solid var(--line)}
.modal-bd{padding:18px;overflow:auto}
.settings-tabs{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:14px}
.settings-tab{border:1px solid var(--line);background:#f8fafc;border-radius:999px;padding:6px 12px;font-size:12px;font-weight:700;cursor:pointer}
.settings-tab.active{background:var(--main);border-color:var(--main);color:#fff}
.settings-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px 14px}
.settings-grid .full{grid-column:1/-1}
.field-hint{font-size:11px;color:#94a3b8;margin:-6px 0 8px}
.switch-row{display:flex;align-items:center;gap:8px;font-weight:600;font-size:13px;margin-bottom:8px}
@media (max-width:800px){.settings-grid{grid-template-columns:1fr}}
.modal-ft{padding:14px 18px;border-top:1px solid var(--line);display:flex;gap:8px;justify-content:flex-end}
label{display:block;font-size:12px;color:var(--muted);font-weight:700;margin-bottom:6px}
input,textarea,select{width:100%;border:1px solid #dbe3ef;border-radius:10px;padding:10px 12px;font-size:14px;margin-bottom:12px;outline:none;font-family:inherit}
input:focus,textarea:focus{border-color:var(--main);box-shadow:0 0 0 3px rgba(37,99,235,.12)}
.mail-layout{display:grid;grid-template-columns:320px 1fr;min-height:70vh}
.mail-list{border-right:1px solid var(--line);overflow:auto;background:#fafbff}
.mail-item{padding:12px 14px;border-bottom:1px solid var(--line);cursor:pointer}
.mail-item:hover,.mail-item.active{background:#fff}
.mail-item .s{font-weight:700;font-size:13px;margin-bottom:4px}
.mail-item .f{font-size:12px;color:var(--muted)}
.mail-item .codes{margin-top:6px;display:flex;flex-wrap:wrap;gap:4px}
.chip{background:#eff6ff;color:#1d4ed8;border-radius:999px;padding:2px 8px;font-size:11px;font-weight:700;cursor:pointer}
.chip-green{background:#ecfdf5;color:#047857}
.mail-view{position:relative;overflow:auto}
.progress{height:8px;background:#e2e8f0;border-radius:999px;overflow:hidden;margin:10px 0}
.progress>i{display:block;height:100%;width:0;background:linear-gradient(90deg,#2563eb,#22c55e);transition:width .2s}
.log{background:#0b1220;color:#cbd5e1;border-radius:12px;padding:12px;font-size:12px;max-height:220px;overflow:auto;font-family:ui-monospace,Consolas,monospace;white-space:pre-wrap}
.toast{position:fixed;right:16px;bottom:16px;background:#0f172a;color:#fff;padding:12px 14px;border-radius:12px;z-index:200;display:none;max-width:360px;font-size:13px}
@media (max-width:800px){
  .stats{grid-template-columns:repeat(2,1fr)}
  .mail-layout{grid-template-columns:1fr}
  .search{width:140px}
}
</style>
</head>
<body>
<nav>
  <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
    <div class="brand">Outlook<span>助手</span></div>
    <input class="search" id="searchInput" placeholder="搜索邮箱/备注" oninput="doSearch()">
  </div>
  <div class="nav-actions">
    <button class="btn btn-main" onclick="openAdd()">+ 添加</button>
    <button class="btn" onclick="openImport()">批量导入</button>
    <button class="btn btn-ok" id="btnCheckAll" onclick="checkAll()">批量验活</button>
    <button class="btn" onclick="location.href='api.php?action=export_txt&type=live'">导出Live</button>
    <button class="btn" onclick="location.href='api.php?action=export_txt&type=dead'">导出Dead</button>
    <button class="btn" onclick="location.href='api.php?action=export'">导出JSON</button>
    <button class="btn" onclick="location.href='get_token.php'" title="设备码授权拿 Token">拿Token</button>
    <button class="btn" onclick="openSettings()">设置</button>
    <button class="btn btn-bad" onclick="location.href='api.php?action=logout'">退出</button>
  </div>
</nav>

<div id="jobBar" style="display:none;background:#0f172a;color:#e2e8f0;padding:10px 20px;position:sticky;top:64px;z-index:40">
  <div style="max-width:1280px;margin:0 auto;display:flex;gap:12px;align-items:center;flex-wrap:wrap">
    <strong id="jobBarTitle">验活任务</strong>
    <span id="jobBarText" style="font-size:13px;opacity:.9"></span>
    <div class="progress" style="flex:1;min-width:120px;margin:0"><i id="jobBarProg"></i></div>
    <button class="btn" style="background:#1e293b;color:#fff;border-color:#334155" onclick="openCheckPanel()">查看</button>
    <button class="btn btn-bad" onclick="abortCheck()">停止</button>
  </div>
</div>
<div class="wrap">
  <div class="stats">
    <div class="stat"><div class="n" id="stTotal">0</div><div class="l">全部账号</div></div>
    <div class="stat"><div class="n" id="stLive" style="color:var(--ok)">0</div><div class="l">Live 存活</div></div>
    <div class="stat"><div class="n" id="stDead" style="color:var(--bad)">0</div><div class="l">Dead 失效</div></div>
    <div class="stat"><div class="n" id="stUnk" style="color:var(--warn)">0</div><div class="l">未检测</div></div>
  </div>

  <div class="toolbar">
    <button class="btn" onclick="filterStatus('all')">全部</button>
    <button class="btn" onclick="filterStatus('live')">仅 Live</button>
    <button class="btn" onclick="filterStatus('dead')">仅 Dead</button>
    <button class="btn" onclick="filterStatus('unknown')">仅未检测</button>
    <button class="btn" onclick="selectAllFiltered(true)">全选当前</button>
    <button class="btn" onclick="selectAllFiltered(false)">取消选择</button>
    <button class="btn" onclick="invertSelection()">反选</button>
    <button class="btn btn-bad" onclick="deleteDead()">清理 Dead</button>
    <button class="btn btn-bad" onclick="deleteByScope('live')">删除全部 Live</button>
    <button class="btn btn-bad" onclick="deleteByScope('all')">删除全部账号</button>
    <span id="listCount" style="color:var(--muted);font-size:12px;margin-left:auto">点击卡片查看邮件</span>
  </div>

  <div class="bulkbar" id="bulkBar">
    <strong id="bulkCount">已选 0</strong>
    <button class="btn btn-bad" onclick="deleteSelected()">删除选中</button>
    <button class="btn" onclick="remarkSelected('set')">设置备注</button>
    <button class="btn" onclick="remarkSelected('append')">追加备注</button>
    <button class="btn" onclick="remarkSelected('clear')">清空备注</button>
    <button class="btn" onclick="checkSelected()">验活选中</button>
    <button class="btn" onclick="selectAllFiltered(false)">取消选择</button>
  </div>

  <div id="grid" class="grid"></div>
</div>

<!-- form modal -->
<div class="modal" id="formModal">
  <div class="modal-box modal-sm" id="formModalBox">
    <div class="modal-hd"><strong id="modalTitle">标题</strong><button class="btn" onclick="closeModals()">关闭</button></div>
    <div class="modal-bd" id="modalForm"></div>
    <div class="modal-ft">
      <button class="btn" onclick="closeModals()">取消</button>
      <button class="btn btn-main" id="submitBtn">确认</button>
    </div>
  </div>
</div>

<!-- check progress -->
<div class="modal" id="checkModal">
  <div class="modal-box modal-sm">
    <div class="modal-hd">
      <strong id="checkTitle">批量验活</strong>
      <button class="btn" id="checkXBtn" onclick="hideCheckModal()" title="关闭面板（任务继续）">×</button>
    </div>
    <div class="modal-bd">
      <div id="checkSummary">准备中…</div>
      <div class="progress"><i id="checkBar"></i></div>
      <div class="log" id="checkLog"></div>
    </div>
    <div class="modal-ft">
      <button class="btn btn-bad" id="checkAbortBtn" onclick="abortCheck()">停止任务</button>
      <button class="btn" id="checkHideBtn" onclick="hideCheckModal()">后台运行</button>
      <button class="btn btn-main" id="checkCloseBtn" onclick="finishCheck()">关闭</button>
    </div>
  </div>
</div>

<!-- mail modal -->
<div class="modal" id="mailModal">
  <div class="modal-box">
    <div class="modal-hd">
      <div>
        <strong id="mailTitle">邮件</strong>
        <div style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap">
          <button class="btn" onclick="loadMails('INBOX')">收件箱</button>
          <button class="btn" onclick="loadMails('Junk')">垃圾箱</button>
          <button class="btn btn-main" onclick="loadMails('all')">收件箱+垃圾箱</button>
          <button class="btn" onclick="checkOneCurrent()">重新验活</button>
        </div>
      </div>
      <button class="btn" onclick="closeModals()">关闭</button>
    </div>
    <div class="mail-layout">
      <div class="mail-list" id="mailList"></div>
      <div class="mail-view" id="mailContent">
        <div style="height:100%;display:flex;align-items:center;justify-content:center;color:#94a3b8">选择左侧邮件</div>
      </div>
    </div>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>
let allAccounts = [];
let filter = 'all';
let currentMailId = null;
let mailCache = [];
let selectedIds = new Set();
let checkAbort = false;
let checkRunning = false;
let currentJobId = null;
let jobPollTimer = null;
let runtimeSettings = {};
let settingsSchema = {};
let settingsGroups = {};
let settingsTab = 'proxy';

function toast(msg){
  const el = document.getElementById('toast');
  el.textContent = msg;
  el.style.display = 'block';
  setTimeout(()=>el.style.display='none', 2600);
}

function badge(status){
  if(status==='live') return '<span class="badge badge-live">LIVE</span>';
  if(status==='dead') return '<span class="badge badge-dead">DEAD</span>';
  return '<span class="badge badge-unknown">未检测</span>';
}

async function loadList(){
  const res = await fetch('api.php?action=list', {headers:{'X-Requested-With':'XMLHttpRequest'}});
  const data = await res.json();
  allAccounts = data.data || [];
  renderStats();
  doSearch();
}

function renderStats(){
  const total = allAccounts.length;
  const live = allAccounts.filter(a=>a.status==='live').length;
  const dead = allAccounts.filter(a=>a.status==='dead').length;
  const unk = total - live - dead;
  document.getElementById('stTotal').textContent = total;
  document.getElementById('stLive').textContent = live;
  document.getElementById('stDead').textContent = dead;
  document.getElementById('stUnk').textContent = unk;
}

function filteredList(){
  const kw = document.getElementById('searchInput').value.toLowerCase().trim();
  return allAccounts.filter(a=>{
    if(filter==='live' && a.status!=='live') return false;
    if(filter==='dead' && a.status!=='dead') return false;
    if(filter==='unknown' && (a.status==='live'||a.status==='dead')) return false;
    if(!kw) return true;
    return (a.email||'').toLowerCase().includes(kw) || (a.remark||'').toLowerCase().includes(kw);
  });
}

function doSearch(){ renderGrid(filteredList()); }
function filterStatus(s){ filter=s; doSearch(); }

function renderGrid(data){
  const grid = document.getElementById('grid');
  if(!data.length){
    grid.innerHTML = `<div class="empty"><div style="font-size:40px;margin-bottom:10px">📥</div>还没有账号，点击「添加」或「批量导入」</div>`;
    return;
  }
  const countEl = document.getElementById('listCount');
  if(countEl) countEl.textContent = `显示 ${data.length} / 总 ${allAccounts.length} · 已选 ${selectedIds.size}`;
  grid.innerHTML = data.map(i=>{
    const sid = +i.id;
    const checked = selectedIds.has(sid) ? 'checked' : '';
    const selCls = selectedIds.has(sid) ? ' selected' : '';
    return `
    <div class="card${selCls}" onclick="viewMail(${sid}, ${JSON.stringify(String(i.email||''))})">
      <div class="card-top">
        <div style="display:flex;align-items:center;gap:8px">
          <input class="selbox" type="checkbox" ${checked} onclick="event.stopPropagation();toggleSelect(${sid}, this.checked)" title="选择">
          ${badge(i.status||'unknown')}
        </div>
        <div class="card-actions">
          <button class="icon-btn" title="验活" onclick="event.stopPropagation();checkOne(${sid})">✓</button>
          <button class="icon-btn" title="编辑" onclick="event.stopPropagation();openEdit(${sid})">✎</button>
          <button class="icon-btn" title="删除" onclick="event.stopPropagation();delAcc(${sid}, ${JSON.stringify(String(i.email||''))})">🗑</button>
        </div>
      </div>
      <h3>${esc(i.email)}</h3>
      <p>${esc(i.remark||'无备注')}</p>
      <div class="meta">#${sid} · ${esc(i.last_check_at||'未检测')}${i.last_error? ' · '+esc(String(i.last_error).slice(0,40)):''}</div>
    </div>`;
  }).join('');
  updateBulkBar();
}

function esc(s){
  return String(s??'').replace(/[&<>"']/g, c=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));
}

function closeModals(){
  document.querySelectorAll('.modal').forEach(m=>m.classList.remove('show'));
}

function showForm(title, html, onSubmit, opts={}){
  document.getElementById('modalTitle').textContent = title;
  document.getElementById('modalForm').innerHTML = html;
  const box = document.getElementById('formModalBox');
  if(box){
    box.classList.remove('modal-sm','modal-lg');
    box.classList.add(opts.large ? 'modal-lg' : 'modal-sm');
  }
  document.getElementById('formModal').classList.add('show');
  const btn = document.getElementById('submitBtn');
  btn.style.display = opts.hideSubmit ? 'none' : '';
  btn.onclick = async ()=>{
    btn.disabled = true; btn.textContent='提交中...';
    try { await onSubmit(); if(!opts.keepOpen){ closeModals(); await loadList(); toast('完成'); } }
    catch(e){ toast(e.message||'失败'); }
    finally { btn.disabled=false; btn.textContent='确认'; }
  };
}

function openAdd(){
  showForm('添加账号', `
    <label>凭证（一行）</label>
    <textarea id="raw_data" rows="4" placeholder="邮箱----密码----ClientID----RefreshToken"></textarea>
    <label>备注</label>
    <input id="remark" placeholder="可选">
  `, async ()=>{
    const fd = new FormData();
    fd.append('raw_data', document.getElementById('raw_data').value);
    fd.append('remark', document.getElementById('remark').value);
    const r = await (await fetch('api.php?action=add',{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}})).json();
    if(!r.ok) throw new Error(r.error||'添加失败');
  });
}

function openImport(){
  showForm('批量导入', `
    <label>粘贴多行凭证（每行一个）</label>
    <textarea id="import_text" rows="12" placeholder="email----pass----client_id----refresh_token\nemail----pass----client_id----refresh_token"></textarea>
    <div style="font-size:12px;color:#64748b;margin-bottom:8px">也支持上传 JSON/TXT：
      <input type="file" id="importFile" accept=".json,.txt" style="margin-top:8px">
    </div>
  `, async ()=>{
    const file = document.getElementById('importFile').files[0];
    if(file){
      const fd = new FormData(); fd.append('file', file);
      const r = await (await fetch('api.php?action=import',{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}})).json();
      if(!r.ok) throw new Error(r.error||'导入失败');
      toast('导入 '+ (r.added||0) +' 条');
      return;
    }
    const fd = new FormData();
    fd.append('text', document.getElementById('import_text').value);
    const r = await (await fetch('api.php?action=import_text',{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}})).json();
    if(!r.ok) throw new Error(r.error||'导入失败');
    toast(`新增${r.added} 更新${r.updated} 失败${r.failed}`);
  });
}

function openEdit(id){
  const d = allAccounts.find(a=>+a.id===+id);
  if(!d) return;
  showForm('编辑账号', `
    <label>邮箱</label><input id="e_mail" value="${esc(d.email)}">
    <label>密码</label><input id="e_pass" value="${esc(d.password||'')}">
    <label>Client ID</label><input id="e_cid" value="${esc(d.client_id||'')}">
    <label>Refresh Token</label><textarea id="e_token" rows="4">${esc(d.refresh_token||'')}</textarea>
    <label>备注</label><input id="e_rem" value="${esc(d.remark||'')}">
  `, async ()=>{
    const fd = new FormData();
    fd.set('id', id);
    fd.set('email', document.getElementById('e_mail').value);
    fd.set('password', document.getElementById('e_pass').value);
    fd.set('client_id', document.getElementById('e_cid').value);
    fd.set('refresh_token', document.getElementById('e_token').value);
    fd.set('remark', document.getElementById('e_rem').value);
    const r = await (await fetch('api.php?action=update_account',{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}})).json();
    if(!r.ok) throw new Error(r.error||'更新失败');
  });
}

async function loadRuntimeSettings(){
  try{
    const r = await (await fetch('api.php?action=get_settings',{headers:{'X-Requested-With':'XMLHttpRequest'}})).json();
    runtimeSettings = r.data || {};
    settingsSchema = (runtimeSettings._meta && runtimeSettings._meta.schema) || {};
    settingsGroups = (runtimeSettings._meta && runtimeSettings._meta.groups) || {};
  }catch(e){ console.warn(e); }
}

function settingsFieldHtml(key, meta, val){
  const id = 'cfg_'+key;
  const label = meta.label || key;
  const hint = meta.hint ? `<div class="field-hint">${esc(meta.hint)}</div>` : '';
  const type = meta.type || 'string';
  if(type === 'bool'){
    const on = (val==='1' || val===true || val==='true');
    return `<div class="full"><label class="switch-row"><input type="checkbox" id="${id}" ${on?'checked':''} style="width:auto;margin:0"> ${esc(label)}</label>${hint}</div>`;
  }
  if(type === 'select'){
    const opts = meta.options || {};
    const options = Object.keys(opts).map(k=>`<option value="${esc(k)}" ${String(val)===String(k)?'selected':''}>${esc(opts[k])}</option>`).join('');
    return `<div><label>${esc(label)}</label><select id="${id}">${options}</select>${hint}</div>`;
  }
  if(type === 'text'){
    return `<div class="full"><label>${esc(label)}</label><textarea id="${id}" rows="3">${esc(val||'')}</textarea>${hint}</div>`;
  }
  if(type === 'password'){
    return `<div><label>${esc(label)}</label><input type="password" id="${id}" value="${esc(val||'')}" autocomplete="new-password">${hint}</div>`;
  }
  if(type === 'int'){
    const min = meta.min!=null?` min="${meta.min}"`:'';
    const max = meta.max!=null?` max="${meta.max}"`:'';
    return `<div><label>${esc(label)}</label><input type="number" id="${id}" value="${esc(val||meta.default||'0')}"${min}${max}>${hint}</div>`;
  }
  return `<div class="${(String(val||'').length>40)?'full':''}"><label>${esc(label)}</label><input id="${id}" value="${esc(val||'')}" >${hint}</div>`;
}

function harvestVisibleSettings(){
  // 只把当前 DOM 里存在的字段写回 runtimeSettings，避免切 tab 丢值
  Object.keys(settingsSchema).forEach(key=>{
    const el = document.getElementById('cfg_'+key);
    if(!el) return;
    const meta = settingsSchema[key] || {};
    if(meta.type === 'bool') runtimeSettings[key] = el.checked ? '1' : '0';
    else runtimeSettings[key] = el.value;
  });
  const u = document.getElementById('cfg_admin_user');
  const pw = document.getElementById('cfg_admin_pass');
  if(u) runtimeSettings.admin_user = u.value;
  if(pw) runtimeSettings._admin_pass_tmp = pw.value;
}

function collectSettingsFromForm(){
  harvestVisibleSettings();
  const payload = {};
  Object.keys(settingsSchema).forEach(key=>{
    if(runtimeSettings[key] !== undefined) payload[key] = runtimeSettings[key];
  });
  if(runtimeSettings.admin_user) payload.admin_user = runtimeSettings.admin_user;
  if(runtimeSettings._admin_pass_tmp) payload.admin_pass = runtimeSettings._admin_pass_tmp;
  return payload;
}

function renderSettingsTab(group){
  // 切页前先收割当前页
  harvestVisibleSettings();
  settingsTab = group;
  const tabs = document.getElementById('settingsTabs');
  if(tabs){
    tabs.querySelectorAll('.settings-tab').forEach(el=>{
      el.classList.toggle('active', el.dataset.g === group);
    });
  }
  const body = document.getElementById('settingsBody');
  if(!body) return;
  if(group === 'account'){
    body.innerHTML = `
      <div class="settings-grid">
        <div><label>管理员用户名</label><input id="cfg_admin_user" value="${esc(runtimeSettings.admin_user||'admin')}"></div>
        <div><label>新密码（不改留空）</label><input type="password" id="cfg_admin_pass" value="${esc(runtimeSettings._admin_pass_tmp||'')}" autocomplete="new-password"></div>
        <div class="full field-hint">账号安全与登录相关；业务参数在其它页签。</div>
      </div>`;
    return;
  }
  const keys = Object.keys(settingsSchema).filter(k => (settingsSchema[k].group||'basic') === group);
  if(!keys.length){
    body.innerHTML = '<div class="field-hint">该分组暂无配置项</div>';
    return;
  }
  body.innerHTML = `<div class="settings-grid">${keys.map(k=>settingsFieldHtml(k, settingsSchema[k], runtimeSettings[k])).join('')}</div>`;
  if(group === 'proxy'){
    body.innerHTML += `
      <div style="margin-top:12px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <button type="button" class="btn" onclick="testProxy()">测试代理连通</button>
        <div id="proxyTestResult" style="font-size:12px;color:#64748b"></div>
      </div>`;
  }
}

async function openSettings(){
  await loadRuntimeSettings();
  const groupOrder = ['account','basic','proxy','check','network','oauth','mail','extract','tg'];
  const labels = Object.assign({account:'账号安全'}, settingsGroups||{});
  const tabs = groupOrder.filter(g => g==='account' || Object.keys(settingsSchema).some(k => (settingsSchema[k].group||'basic')===g))
    .map(g=>`<button type="button" class="settings-tab ${g==='proxy'?'active':''}" data-g="${g}" onclick="renderSettingsTab('${g}')">${esc(labels[g]||g)}</button>`).join('');

  showForm('系统设置（全部可配）', `
    <div class="settings-tabs" id="settingsTabs">${tabs}</div>
    <div id="settingsBody"></div>
    <div class="field-hint" style="margin-top:10px">保存后立即生效。验活并发在「验活」页签；代理在「代理」页签。</div>
  `, async ()=>{
    const payload = collectSettingsFromForm();
    const res = await (await fetch('api.php?action=save_settings',{
      method:'POST',
      headers:{'X-Requested-With':'XMLHttpRequest','Content-Type':'application/json'},
      body: JSON.stringify(payload)
    })).json();
    if(!res.ok) throw new Error(res.error||'保存失败');
    runtimeSettings = res.values || payload;
    await loadRuntimeSettings();
  }, {large:true});
  renderSettingsTab(settingsTab || 'proxy');
}

async function testProxy(){
  const box = document.getElementById('proxyTestResult');
  if(box) box.textContent = '保存并测试中…';
  try{
    const payload = collectSettingsFromForm();
    await fetch('api.php?action=save_settings',{
      method:'POST',
      headers:{'X-Requested-With':'XMLHttpRequest','Content-Type':'application/json'},
      body: JSON.stringify(payload)
    });
    const r = await (await fetch('api.php?action=test_proxy',{headers:{'X-Requested-With':'XMLHttpRequest'}})).json();
    if(!r.ok){
      if(box) box.innerHTML = '<span style="color:#dc2626">失败: '+esc(r.error||'unknown')+'</span>';
      toast('代理失败');
      return;
    }
    const ms = r.ms_ok ? '微软可达' : ('微软不通: '+(r.ms_error||''));
    if(box) box.innerHTML = '<span style="color:#16a34a">OK 出口IP: <b>'+esc(r.ip||'?')+'</b> · '+esc(ms)+'</span>';
    toast('代理可用 · IP '+ (r.ip||''));
    await loadRuntimeSettings();
  }catch(e){
    if(box) box.innerHTML = '<span style="color:#dc2626">测试异常</span>';
  }
}


function toggleSelect(id, on){
  id = +id;
  if(on) selectedIds.add(id); else selectedIds.delete(id);
  updateBulkBar();
  // soft refresh selection style without full re-fetch
  doSearch();
}
function selectAllFiltered(on){
  const list = filteredList();
  if(on) list.forEach(a=>selectedIds.add(+a.id));
  else list.forEach(a=>selectedIds.delete(+a.id));
  doSearch();
}
function invertSelection(){
  const list = filteredList();
  list.forEach(a=>{
    const id=+a.id;
    if(selectedIds.has(id)) selectedIds.delete(id); else selectedIds.add(id);
  });
  doSearch();
}
function selectedIdList(){ return Array.from(selectedIds); }
function updateBulkBar(){
  const bar = document.getElementById('bulkBar');
  const n = selectedIds.size;
  if(bar){
    bar.classList.toggle('show', n>0);
    const c = document.getElementById('bulkCount');
    if(c) c.textContent = '已选 ' + n;
  }
  const countEl = document.getElementById('listCount');
  if(countEl){
    const shown = filteredList().length;
    countEl.textContent = `显示 ${shown} / 总 ${allAccounts.length} · 已选 ${n}`;
  }
}
async function deleteSelected(){
  const ids = selectedIdList();
  if(!ids.length){ toast('未选择账号'); return; }
  if(!confirm('确定删除选中的 '+ids.length+' 个账号？不可恢复')) return;
  const r = await (await fetch('api.php?action=delete_batch',{
    method:'POST', headers:{'X-Requested-With':'XMLHttpRequest','Content-Type':'application/json'},
    body: JSON.stringify({scope:'selected', ids})
  })).json();
  if(!r.ok){ toast(r.error||'删除失败'); return; }
  ids.forEach(id=>selectedIds.delete(+id));
  toast('已删除 '+ (r.deleted||ids.length));
  await loadList();
}
async function deleteByScope(scope){
  const labels = {all:'全部账号', live:'全部 Live', dead:'全部 Dead', unknown:'全部未检测'};
  const label = labels[scope] || scope;
  if(!confirm('确定删除【'+label+'】？此操作不可恢复！')) return;
  if(scope==='all' && !confirm('再次确认：删除数据库中的全部邮箱账号？')) return;
  const r = await (await fetch('api.php?action=delete_batch',{
    method:'POST', headers:{'X-Requested-With':'XMLHttpRequest','Content-Type':'application/json'},
    body: JSON.stringify({scope})
  })).json();
  if(!r.ok){ toast(r.error||'删除失败'); return; }
  selectedIds.clear();
  toast('已删除 '+ (r.deleted||0) + '（'+label+'）');
  await loadList();
}
async function remarkSelected(mode){
  const ids = selectedIdList();
  if(!ids.length){ toast('未选择账号'); return; }
  let remark = '';
  if(mode !== 'clear'){
    remark = prompt(mode==='append' ? '追加备注内容：' : '设置备注为：', '') || '';
    if(mode!=='clear' && remark===''){ toast('备注为空，已取消'); return; }
  } else {
    if(!confirm('清空选中 '+ids.length+' 个账号的备注？')) return;
  }
  const r = await (await fetch('api.php?action=remark_batch',{
    method:'POST', headers:{'X-Requested-With':'XMLHttpRequest','Content-Type':'application/json'},
    body: JSON.stringify({scope:'selected', ids, mode, remark})
  })).json();
  if(!r.ok){ toast(r.error||'备注失败'); return; }
  toast('备注已更新 '+ (r.updated||ids.length));
  await loadList();
}
async function checkSelected(){
  const ids = selectedIdList();
  if(!ids.length){ toast('未选择账号'); return; }
  if(!confirm('对选中的 '+ids.length+' 个账号启动后台验活？')) return;
  await loadRuntimeSettings();
  let concurrency = parseInt(runtimeSettings.check_concurrency || '20', 10) || 20;
  openCheckPanel();
  setCheckUiRunning(true, '提交选中账号验活…');
  document.getElementById('checkLog').textContent = `验活选中 ${ids.length} 个 · 并发=${concurrency}\n`;
  try{
    const r = await (await fetch('api.php?action=job_start_check',{
      method:'POST', headers:{'X-Requested-With':'XMLHttpRequest','Content-Type':'application/json'},
      body: JSON.stringify({ids, concurrency, scope:'ids'})
    })).json();
    if(!r.ok){
      if(r.job && r.job.id){ currentJobId=r.job.id; checkRunning=true; startJobPolling(r.job.id); toast(r.error||'已有任务'); return; }
      setCheckUiRunning(false, r.error||'启动失败'); toast(r.error||'启动失败'); return;
    }
    currentJobId = r.job_id || (r.job && r.job.id);
    checkRunning = true;
    startJobPolling(currentJobId);
    toast('选中账号验活已启动');
  }catch(e){ setCheckUiRunning(false,'启动异常'); toast(e.message||'异常'); }
}


async function delAcc(id, email){
  if(!confirm('确定删除 '+email+' ?')) return;
  const fd = new FormData(); fd.append('id', id);
  await fetch('api.php?action=delete',{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}});
  loadList();
}

async function deleteDead(){
  const n = allAccounts.filter(a=>a.status==='dead').length;
  if(!n){ toast('没有 Dead 账号'); return; }
  if(!confirm('清理 '+n+' 个 Dead 账号？')) return;
  await deleteByScope('dead');
}

async function checkOne(id){
  toast('验活中 #'+id);
  const r = await (await fetch('api.php?action=check_one&id='+id,{headers:{'X-Requested-With':'XMLHttpRequest'}})).json();
  if(!r.ok){ toast(r.error||'失败'); return; }
  toast((r.result.email||'') + ' => ' + r.result.status.toUpperCase());
  await loadList();
}

async function checkOneCurrent(){
  if(currentMailId) await checkOne(currentMailId);
}

async function checkAll(){
  if(checkRunning && currentJobId){
    openCheckPanel();
    toast('任务进行中，已打开进度面板');
    return;
  }
  await loadRuntimeSettings();
  const list = filteredList();
  if(!list.length){ toast('没有可检测账号'); return; }

  let concurrency = parseInt(runtimeSettings.check_concurrency || '20', 10);
  if(!Number.isFinite(concurrency) || concurrency < 1) concurrency = 1;
  if(concurrency > 50) concurrency = 50;

  const ids = list.map(a => +a.id);
  if(!confirm('将对当前列表 '+ids.length+' 个账号启动后台验活？\n并发='+concurrency+'\n（受顶部筛选/搜索影响）')) return;
  openCheckPanel();
  setCheckUiRunning(true, `提交后台任务 · 分片并发=${concurrency}`);
  document.getElementById('checkLog').textContent = `准备启动后台 worker · 账号=${ids.length} · 并发=${concurrency}\n`;

  try{
    const r = await (await fetch('api.php?action=job_start_check',{
      method:'POST',
      headers:{'X-Requested-With':'XMLHttpRequest','Content-Type':'application/json'},
      body: JSON.stringify({ids, concurrency, scope:'ids'})
    })).json();
    if(!r.ok){
      if(r.job && r.job.id){
        currentJobId = r.job.id;
        checkRunning = true;
        document.getElementById('checkLog').textContent += `已有任务 ${r.job.id}，接入监控\n`;
        startJobPolling(r.job.id);
        toast(r.error || '已有任务运行中');
        return;
      }
      setCheckUiRunning(false, r.error||'启动失败');
      toast(r.error||'启动失败');
      return;
    }
    currentJobId = r.job_id || (r.job && r.job.id);
    checkRunning = true;
    document.getElementById('checkLog').textContent += `任务已启动 job=${currentJobId}\n后端独立进程运行，关闭本窗口不影响验活\n`;
    startJobPolling(currentJobId);
    toast('后台验活已启动');
  }catch(e){
    setCheckUiRunning(false, '启动异常');
    toast(e.message||'启动异常');
  }
}

function openCheckPanel(){
  document.getElementById('checkModal').classList.add('show');
}

function hideCheckModal(){
  document.getElementById('checkModal').classList.remove('show');
  toast(currentJobId ? '已转后台，任务继续跑' : '已关闭');
}

function setCheckUiRunning(running, summary){
  const closeBtn = document.getElementById('checkCloseBtn');
  const abortBtn = document.getElementById('checkAbortBtn');
  if(running){
    document.getElementById('checkTitle').textContent = '后台验活运行中';
    abortBtn.disabled = false;
    abortBtn.textContent = '停止任务';
    closeBtn.disabled = false;
    closeBtn.textContent = '关闭面板';
  } else {
    document.getElementById('checkTitle').textContent = '验活任务';
    abortBtn.disabled = true;
    closeBtn.disabled = false;
    closeBtn.textContent = '关闭';
  }
  if(summary) document.getElementById('checkSummary').textContent = summary;
}

function startJobPolling(jobId){
  stopJobPolling();
  currentJobId = jobId;
  checkRunning = true;
  const tick = async ()=>{
    try{
      const r = await (await fetch('api.php?action=job_status&job_id='+encodeURIComponent(jobId),{
        headers:{'X-Requested-With':'XMLHttpRequest'}
      })).json();
      if(!r.ok || !r.job){
        document.getElementById('checkSummary').textContent = '任务状态读取失败';
        return;
      }
      renderJobStatus(r.job);
      const st = r.job.status;
      if(st==='done' || st==='cancelled' || st==='error'){
        stopJobPolling();
        checkRunning = false;
        setCheckUiRunning(false, r.job.message || st);
        await loadList();
        if(st==='done') toast(`验活完成 Live ${r.job.live} / Dead ${r.job.dead}`);
        else if(st==='cancelled') toast('任务已停止');
        else toast('任务失败: '+(r.job.error||''));
      }
    }catch(e){}
  };
  tick();
  jobPollTimer = setInterval(tick, 800);
}

function stopJobPolling(){
  if(jobPollTimer){
    clearInterval(jobPollTimer);
    jobPollTimer = null;
  }
}

function renderJobStatus(job){
  const jb = document.getElementById('jobBar');
  if(jb){
    const active = ['queued','running','cancelling'].includes(job.status);
    jb.style.display = 'block';
    if(['done','cancelled','error'].includes(job.status)){
      setTimeout(()=>{ if(jb && !checkRunning) jb.style.display='none'; }, 8000);
    }
    document.getElementById('jobBarTitle').textContent =
      job.status==='running'?'验活中': job.status==='cancelling'?'停止中': job.status==='done'?'验活完成': job.status==='cancelled'?'已停止': '验活';
    document.getElementById('jobBarText').textContent =
      `${job.done||0}/${job.total||0} · Live ${job.live||0} · Dead ${job.dead||0} · ${job.speed||0}/s`;
    document.getElementById('jobBarProg').style.width = Math.min(100, job.percent||0)+'%';
  }
  const bar = document.getElementById('checkBar');
  const log = document.getElementById('checkLog');
  const pct = job.percent || 0;
  bar.style.width = Math.min(100, pct) + '%';
  const speed = job.speed || 0;
  const elapsed = ((job.elapsed_ms||0)/1000).toFixed(1);
  document.getElementById('checkSummary').textContent =
    `进度 ${job.done||0}/${job.total||0} · Live ${job.live||0} · Dead ${job.dead||0} · 未知 ${job.unknown||0} · 并发 ${job.concurrency||0} · ${speed}/s · ${elapsed}s · ${job.status}`;
  document.getElementById('checkTitle').textContent =
    job.status==='running' ? '后台验活运行中' :
    job.status==='cancelling' ? '正在停止…' :
    job.status==='done' ? '验活完成' :
    job.status==='cancelled' ? '验活已停止' :
    job.status==='error' ? '验活失败' : '验活任务';

  const lines = (job.log || []).join('\n');
  const header = `job=${job.id} · 引擎=backend-worker+curl_multi · 关闭面板不影响任务\n`;
  log.textContent = header + lines;
  log.scrollTop = log.scrollHeight;
  setCheckUiRunning(['queued','running','cancelling'].includes(job.status), null);
}

async function abortCheck(){
  if(!currentJobId){
    hideCheckModal();
    return;
  }
  document.getElementById('checkSummary').textContent = '正在发送停止指令…';
  document.getElementById('checkAbortBtn').disabled = true;
  try{
    const r = await (await fetch('api.php?action=job_cancel',{
      method:'POST',
      headers:{'X-Requested-With':'XMLHttpRequest','Content-Type':'application/json'},
      body: JSON.stringify({job_id: currentJobId})
    })).json();
    if(r.job) renderJobStatus(r.job);
    toast('已请求停止');
  }catch(e){
    toast('停止请求失败');
    document.getElementById('checkAbortBtn').disabled = false;
  }
}

function finishCheck(){
  hideCheckModal();
  if(!checkRunning){
    stopJobPolling();
  }
  loadList();
}

async function resumeActiveJob(){
  try{
    const r = await (await fetch('api.php?action=job_status',{headers:{'X-Requested-With':'XMLHttpRequest'}})).json();
    if(r.ok && r.active && r.job){
      currentJobId = r.job.id;
      checkRunning = true;
      startJobPolling(currentJobId);
    }
  }catch(e){}
}

async function viewMail(id, email){
  currentMailId = id;
  document.getElementById('mailTitle').textContent = email;
  document.getElementById('mailModal').classList.add('show');
  const folder = runtimeSettings.mail_default_folder || 'all';
  await loadMails(folder);
}

async function loadMails(folder){
  if(!currentMailId) return;
  document.getElementById('mailList').innerHTML = '<div style="padding:30px;color:#94a3b8;text-align:center">同步中…</div>';
  document.getElementById('mailContent').innerHTML = '<div style="height:100%;display:flex;align-items:center;justify-content:center;color:#94a3b8">加载中</div>';
  try{
    const limit = parseInt(runtimeSettings.mail_default_limit||'20',10) || 20;
    const r = await (await fetch(`api.php?action=get_mails&id=${currentMailId}&folder=${folder}&limit=${limit}`,{headers:{'X-Requested-With':'XMLHttpRequest'}})).json();
    if(!r.ok){
      document.getElementById('mailList').innerHTML = `<div style="padding:20px;color:#dc2626">${esc(r.error||'失败')}</div>`;
      return;
    }
    const messages = r.value || [];
    if(!messages.length){
      document.getElementById('mailList').innerHTML = '<div style="padding:30px;text-align:center;color:#94a3b8">暂无邮件</div>';
      return;
    }
    mailCache = messages;
    document.getElementById('mailList').innerHTML = messages.map((m,idx)=>{
      const codes = (m.codes||[]).slice(0,3).map(c=>`<span class="chip" onclick="event.stopPropagation();copyText(${JSON.stringify(String(c))})">${esc(c)}</span>`).join('');
      return `<div class="mail-item" onclick="showMsg(this,${idx})">
        <div class="s">${esc(m.subject||'(无主题)')}</div>
        <div class="f">${esc(m.from||'')} · ${esc(m.folder||'')} · ${esc(m.date||'')}</div>
        ${codes?`<div class="codes">${codes}</div>`:''}
      </div>`;
    }).join('');
  }catch(e){
    document.getElementById('mailList').innerHTML = '<div style="padding:20px;color:red">网络错误</div>';
  }
}

function showMsg(el, idx){
  document.querySelectorAll('.mail-item').forEach(i=>i.classList.remove('active'));
  el.classList.add('active');
  const m = (typeof idx === 'number') ? (mailCache[idx] || {}) : {};
  // legacy payload string ignored for safety
  const codes = (m.codes||[]).map(c=>`<span class="chip" onclick="copyText(${JSON.stringify(String(c))})">${esc(c)}</span>`).join(' ');
  const links = (m.links||[]).slice(0,5).map(u=>{
    const safe = String(u);
    return `<div style="margin:4px 0"><a class="chip chip-green" href="${esc(safe)}" target="_blank" rel="noopener noreferrer">打开链接</a> <span class="chip" onclick="copyText(${JSON.stringify(safe)})">复制</span><div style="font-size:11px;color:#64748b;word-break:break-all;margin-top:2px">${esc(safe)}</div></div>`;
  }).join('');
  let body = String(m.body || '')
    .replace(/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/gi,'')
    .replace(/\son\w+\s*=\s*("[^"]*"|'[^']*'|[^\s>]+)/gi,'')
    .replace(/javascript:/gi,'');
  const latestCode = (m.codes&&m.codes[0]) ? m.codes[0] : '';
  document.getElementById('mailContent').innerHTML = `
    <div style="padding:14px 16px;border-bottom:1px solid #e8edf5">
      <div style="font-weight:800;font-size:16px;margin-bottom:6px">${esc(m.subject||'')}</div>
      <div style="font-size:12px;color:#64748b;margin-bottom:10px">${esc(m.from||'')} · ${esc(m.date||'')}</div>
      ${latestCode?`<div style="margin-bottom:8px"><button class="btn btn-main" onclick="copyText(${JSON.stringify(String(latestCode))})">复制验证码 ${esc(latestCode)}</button></div>`:''}
      ${codes?`<div style="margin-bottom:8px"><b>验证码：</b> ${codes}</div>`:''}
      ${links?`<div style="margin-bottom:8px"><b>链接：</b>${links}</div>`:''}
    </div>
    <iframe id="mailIframe" sandbox="allow-popups allow-popups-to-escape-sandbox" style="width:100%;height:calc(70vh - 120px);border:0"></iframe>`;
  document.getElementById('mailIframe').srcdoc = body || '<p style="color:#999;padding:16px">无正文</p>';
}

function copyTextfunction copyText(t){
  navigator.clipboard.writeText(t).then(()=>toast('已复制: '+t)).catch(()=>{
    const ta=document.createElement('textarea'); ta.value=t; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); ta.remove(); toast('已复制: '+t);
  });
}

loadRuntimeSettings().then(()=>loadList()).then(()=>resumeActiveJob());
</script>
</body>
</html>
