/* ============================================================
   Pallaw — admin panel SPA (dark + cyan)
   Auth lock · Dashboard · Question Builder · Solution Builder · Settings
   ============================================================ */

const API = {
  auth: 'api/auth.php',
  health: 'api/health.php',
  generate: 'api/generate.php',
  solve: 'api/solve.php',
  jobs: 'api/jobs.php',
  settings: 'api/settings.php',
  download: (id) => `api/download.php?id=${encodeURIComponent(id)}`,
};

const $ = (id) => document.getElementById(id);
const esc = (s) => String(s ?? '').replace(/[&<>]/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[m]));

let initialized = false;

/* ---------- toast ---------- */
let toastTimer;
function toast(msg, type = 'ok') {
  const el = $('toast');
  const bg = type === 'err' ? 'bg-rose-600' : type === 'load' ? 'bg-slate-800' : 'bg-emerald-600';
  el.innerHTML = `<div class="pointer-events-auto flex items-center gap-2 rounded-xl ${bg} px-4 py-2.5 text-sm font-medium text-white shadow-lg ring-1 ring-white/10">${type === 'load' ? '<span class="spinner"></span>' : ''}<span>${esc(msg)}</span></div>`;
  el.style.opacity = '1';
  clearTimeout(toastTimer);
  if (type !== 'load') toastTimer = setTimeout(() => (el.style.opacity = '0'), 2600);
}

function setStatus(id, msg, type = '') {
  const el = $(id);
  const colors = { err: 'text-rose-400', ok: 'text-emerald-400', load: 'text-cyan-400', '': 'text-slate-400' };
  el.className = 'min-h-[18px] text-sm flex items-center gap-2 ' + (colors[type] || colors['']);
  el.innerHTML = type === 'load' ? `<span class="spinner spinner-dark"></span><span>${esc(msg)}</span>` : esc(msg);
}

/* ---------- CSV / table utils ---------- */
function parseDelimited(text, delim) {
  const rows = []; let row = []; let field = ''; let q = false;
  text = text.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
  for (let i = 0; i < text.length; i++) {
    const c = text[i];
    if (q) {
      if (c === '"') { if (text[i + 1] === '"') { field += '"'; i++; } else q = false; }
      else field += c;
    } else {
      if (c === '"') q = true;
      else if (c === delim) { row.push(field); field = ''; }
      else if (c === '\n') { row.push(field); rows.push(row); row = []; field = ''; }
      else field += c;
    }
  }
  if (field.length || row.length) { row.push(field); rows.push(row); }
  return rows.filter((r) => r.some((v) => String(v).trim() !== ''));
}
// Guess the delimiter (comma / tab / semicolon / pipe) from the first real line.
function detectDelim(text) {
  const line = (text.split(/\r?\n/).find((l) => l.trim() !== '') || '');
  const cands = [',', '\t', ';', '|'];
  let best = ',', bestN = -1;
  for (const d of cands) {
    const n = line.split(d).length - 1;
    if (n > bestN) { bestN = n; best = d; }
  }
  return best;
}
/**
 * Universal reader: accepts CSV / TSV / TXT and Excel (XLSX/XLS/XLSB/ODS).
 * Always returns rows as an array of string arrays. Output elsewhere stays CSV.
 */
function readTableFile(file, cb) {
  const name = (file.name || '').toLowerCase();
  const isExcel = /\.(xlsx|xls|xlsb|ods)$/.test(name);
  const reader = new FileReader();
  reader.onerror = () => cb(null, 'Could not read the file.');
  reader.onload = (e) => {
    try {
      let rows;
      if (isExcel) {
        if (typeof XLSX === 'undefined') { cb(null, 'Excel parser failed to load (check your internet connection).'); return; }
        const wb = XLSX.read(new Uint8Array(e.target.result), { type: 'array' });
        const ws = wb.Sheets[wb.SheetNames[0]];
        if (!ws) { cb(null, 'No sheet found in that workbook.'); return; }
        rows = XLSX.utils.sheet_to_json(ws, { header: 1, defval: '', raw: false, blankrows: false })
          .map((r) => r.map((c) => String(c ?? '')));
      } else {
        const text = e.target.result;
        rows = parseDelimited(text, detectDelim(text));
      }
      rows = rows.filter((r) => r.some((v) => String(v).trim() !== ''));
      cb(rows, null);
    } catch (err) { cb(null, 'Could not parse the file: ' + err.message); }
  };
  if (isExcel) reader.readAsArrayBuffer(file); else reader.readAsText(file);
}
function rowsToObjects(rows) {
  if (!rows.length) return { hdrs: [], objs: [] };
  const nonEmpty = (r) => r.filter((c) => String(c).trim() !== '').length;
  // Pick the header row: first row with 2+ non-empty cells (skips title/blank rows).
  let hi = rows.findIndex((r) => nonEmpty(r) >= 2);
  if (hi < 0) hi = 0;
  const rawHdr = rows[hi];
  // Build clean, unique header names (empty -> column_N, dupes -> name_2 ...).
  const seen = {};
  const hdrs = rawHdr.map((h, i) => {
    let name = String(h ?? '').trim();
    if (name === '') name = 'column_' + (i + 1);
    if (seen[name] === undefined) { seen[name] = 1; return name; }
    seen[name] += 1;
    return name + '_' + seen[name];
  });
  const objs = rows.slice(hi + 1).map((r) => {
    const o = {};
    hdrs.forEach((h, i) => (o[h] = String(r[i] ?? '').trim()));
    return o;
  });
  return { hdrs, objs };
}
function escapeCSV(v) { const s = String(v ?? ''); return /[",\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s; }
function buildCSV(cols, rows) { return [cols.map(escapeCSV).join(','), ...rows.map((r) => cols.map((c) => escapeCSV(r[c])).join(','))].join('\n'); }
function downloadCSV(name, csv) {
  const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob); const a = document.createElement('a');
  a.href = url; a.download = name; document.body.appendChild(a); a.click(); document.body.removeChild(a); URL.revokeObjectURL(url);
}
function renderTable(el, cols, rows, limit) {
  const data = limit ? rows.slice(0, limit) : rows;
  let h = '<thead><tr class="text-left text-xs uppercase tracking-wide text-slate-500">' +
    cols.map((c) => `<th class="whitespace-nowrap px-3 py-2.5 font-semibold">${esc(c)}</th>`).join('') + '</tr></thead><tbody>';
  for (const r of data) h += '<tr class="border-t border-[#1b2433] align-top">' + cols.map((c) => `<td class="px-3 py-2.5 text-slate-300 max-w-[340px]">${esc(r[c])}</td>`).join('') + '</tr>';
  el.innerHTML = h + '</tbody>';
}

/* ============================================================
   AUTH
   ============================================================ */
async function checkAuth() {
  try { const r = await fetch(`${API.auth}?action=check`); const d = await r.json(); return !!d.authed; }
  catch { return false; }
}
function showLogin() {
  $('appShell').classList.add('hidden');
  $('loginScreen').classList.remove('hidden');
  $('loginScreen').classList.add('flex');
  setTimeout(() => $('loginUser').focus(), 50);
}
function showApp() {
  $('loginScreen').classList.add('hidden');
  $('loginScreen').classList.remove('flex');
  $('appShell').classList.remove('hidden');
  if (!initialized) { wireApp(); initialized = true; }
  startClock();
  refreshHealth();
  route();
}
async function doLogin(e) {
  e.preventDefault();
  const username = $('loginUser').value.trim();
  const password = $('loginPass').value;
  $('loginStatus').className = 'mt-3 min-h-[18px] text-center text-sm text-cyan-400';
  $('loginStatus').innerHTML = '<span class="spinner spinner-dark inline-block align-middle"></span> Signing in…';
  $('loginBtn').disabled = true;
  try {
    const r = await fetch(API.auth, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ username, password, action: 'login' }) });
    const d = await r.json();
    if (!r.ok || !d.authed) throw new Error(d.error || 'Login failed');
    $('loginPass').value = '';
    $('loginStatus').textContent = '';
    showApp();
  } catch (err) {
    $('loginStatus').className = 'mt-3 min-h-[18px] text-center text-sm text-rose-400';
    $('loginStatus').textContent = err.message;
  } finally { $('loginBtn').disabled = false; }
}
async function doLogout() {
  try { await fetch(`${API.auth}?action=logout`); } catch {}
  showLogin();
}

/* If any protected call returns 401, bounce to login. */
async function api(url, opts) {
  const r = await fetch(url, opts);
  if (r.status === 401) { showLogin(); throw new Error('Session expired. Please sign in again.'); }
  return r;
}

/* ---------- clock ---------- */
let clockTimer;
function startClock() {
  clearInterval(clockTimer);
  const tick = () => {
    const d = new Date();
    const opts = { weekday: 'short', day: '2-digit', month: 'short' };
    const date = d.toLocaleDateString('en-GB', opts);
    const time = d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    $('clock').textContent = `${date} · ${time}`;
  };
  tick(); clockTimer = setInterval(tick, 1000);
  $('clock').classList.remove('hidden');
}

/* ---------- health badges ---------- */
async function refreshHealth() {
  try { const r = await api(API.health); const h = await r.json(); updateBadges(h.hasKey, h.db); } catch {}
}
function updateBadges(hasKey, db) {
  $('keyStatus').innerHTML = hasKey
    ? '<span class="h-1.5 w-1.5 rounded-full bg-emerald-400"></span><span class="text-emerald-400">Groq ready</span>'
    : '<span class="h-1.5 w-1.5 rounded-full bg-rose-400"></span><span class="text-rose-400">No API key</span>';
  $('dbStatus').innerHTML = db
    ? '<span class="h-1.5 w-1.5 rounded-full bg-emerald-400"></span><span class="text-emerald-400">MySQL connected</span>'
    : '<span class="h-1.5 w-1.5 rounded-full bg-slate-600"></span><span class="text-slate-500">No database</span>';
}

/* ============================================================
   ROUTER
   ============================================================ */
const TITLES = { dashboard: 'Dashboard', generate: 'Question Builder', solve: 'Solution Builder', settings: 'Settings' };
function route() {
  let view = (location.hash || '#dashboard').slice(1);
  if (!TITLES[view]) view = 'dashboard';
  document.querySelectorAll('[data-view]').forEach((s) => s.classList.toggle('view-active', s.dataset.view === view));
  document.querySelectorAll('[data-nav]').forEach((n) => n.classList.toggle('active', n.dataset.nav === view));
  $('pageTitle').textContent = TITLES[view];
  closeSidebar();
  if (view === 'dashboard') loadJobs();
  if (view === 'settings') loadSettings();
  window.scrollTo(0, 0);
}
function openSidebar() { $('sidebar').classList.remove('-translate-x-full'); $('backdrop').classList.remove('hidden'); }
function closeSidebar() { if (window.innerWidth < 1024) { $('sidebar').classList.add('-translate-x-full'); $('backdrop').classList.add('hidden'); } }

/* ============================================================
   GENERATE
   ============================================================ */
const gen = { headers: [], samples: [], source: '', results: [] };

// Detect/ensure the solution column + fresh numbering column.
const EXPL_HINTS = ['explanation', 'solution', 'working', 'step', 'reason', 'soln'];
const ID_NAMES = ['pk', 'id', 'sno', 'srno', 'serial', 'serialno', 'sl', 'slno', 'no', 'number', 'qno', 'rollno', 'roll', 'index', 'idx', 'sr'];
const normName = (s) => String(s || '').toLowerCase().replace(/[\s._\-#]/g, '');
const hasExplanationCol = (cols) => cols.some((c) => EXPL_HINTS.some((h) => normName(c).includes(h)));
const findIdCol = (cols) => cols.find((c) => ID_NAMES.includes(normName(c))) || null;
function augmentColumns(cols) {
  const out = cols.slice();
  if (!hasExplanationCol(out)) out.push('Explanation'); // add a solution column if missing
  return out;
}
// Renumber the id/serial column 1..N across all current results.
function renumberGen() {
  const idCol = findIdCol(gen.headers);
  if (idCol) gen.results.forEach((r, i) => (r[idCol] = String(i + 1)));
}

function gHandleFile(file) {
  if (!file) return;
  gen.source = file.name;
  readTableFile(file, (rows, err) => {
    if (err) return setStatus('gStatus', err, 'err');
    const { hdrs, objs } = rowsToObjects(rows || []);
    if (!hdrs.length) return setStatus('gStatus', 'That file looks empty.', 'err');
    gen.headers = augmentColumns(hdrs); // ensure an Explanation column exists
    gen.samples = objs;
    const added = gen.headers.length > hdrs.length;
    $('gFileLabel').innerHTML = `<span class="font-semibold text-slate-100">${esc(file.name)}</span> · ${objs.length} rows`;
    $('gRowCount').textContent = `${objs.length} samples`;
    $('gChips').innerHTML = gen.headers.map((h) => {
      const isNew = added && h === 'Explanation';
      const cls = isNew
        ? 'rounded-full border border-cyan-400/40 bg-cyan-500/10 px-2.5 py-1 text-xs font-medium text-cyan-300'
        : 'rounded-full border border-[#232e3f] bg-[#141b27] px-2.5 py-1 text-xs font-medium text-slate-300';
      return `<span class="${cls}">${esc(h)}${isNew ? ' (added)' : ''}</span>`;
    }).join('');
    renderTable($('gPreviewTable'), gen.headers, objs, 5);
    $('gPreview').classList.remove('hidden');
    if (added) setStatus('gStatus', 'No solution column found — an "Explanation" column will be added & filled.', '');
    else setStatus('gStatus', '');
    gRefresh();
  });
}
// Topic optional: enabled as soon as a CSV is loaded.
function gRefresh() { $('gRun').disabled = !gen.headers.length; }
async function gRun() {
  if (!gen.headers.length) return setStatus('gStatus', 'Upload a sample file first.', 'err');
  const topic = $('gTopic').value.trim();
  const count = parseInt($('gCount').value, 10) || 5;
  $('gRun').disabled = true;
  setStatus('gStatus', topic ? `Generating ${count} questions…` : `Analyzing samples & generating ${count} questions…`, 'load');
  try {
    const resp = await api(API.generate, {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ headers: gen.headers, samples: gen.samples.slice(0, 8), topic, count, model: $('gModel').value, extra: $('gExtra').value.trim(), source: gen.source }),
    });
    const data = await resp.json();
    if (!resp.ok) throw new Error(data.error || 'Generation failed');
    const qs = data.questions || [];
    if (!qs.length) throw new Error('No questions generated. Try again.');
    if (Array.isArray(data.columns) && data.columns.length) gen.headers = data.columns; // stay in sync
    gen.results = gen.results.concat(qs);
    renumberGen(); // fresh continuous 1..N numbering
    $('gResultCard').classList.remove('hidden');
    $('gResultCount').textContent = `${gen.results.length}`;
    renderTable($('gResultTable'), gen.headers, gen.results);
    setStatus('gStatus', `Done — ${qs.length} new${data.saved ? ', saved' : ''}. Total ${gen.results.length}.`, 'ok');
    toast(`Generated ${qs.length} questions`);
  } catch (e) { setStatus('gStatus', e.message, 'err'); toast(e.message, 'err'); }
  finally { gRefresh(); }
}

/* ============================================================
   SOLVE
   ============================================================ */
const sol = { headers: [], rows: [], source: '', results: [] };
const TARGET_HINTS = ['explanation', 'solution', 'answer', 'correct_option', 'correct', 'ans', 'working', 'steps'];
function sHandleFile(file) {
  if (!file) return;
  sol.source = file.name;
  readTableFile(file, (rows, err) => {
    if (err) return setStatus('sStatus', err, 'err');
    const { hdrs, objs } = rowsToObjects(rows || []);
    if (!hdrs.length) return setStatus('sStatus', 'That file looks empty.', 'err');
    sol.headers = hdrs; sol.rows = objs;
    $('sFileLabel').innerHTML = `<span class="font-semibold text-slate-100">${esc(file.name)}</span> · ${objs.length} rows`;
    $('sRowCount').textContent = `${objs.length} questions`;
    $('sChips').innerHTML = hdrs.map((h) => `<span class="rounded-full border border-[#232e3f] bg-[#141b27] px-2.5 py-1 text-xs font-medium text-slate-300">${esc(h)}</span>`).join('');
    renderTable($('sPreviewTable'), hdrs, objs, 5);
    $('sPreview').classList.remove('hidden');
    $('sTargets').className = 'space-y-1.5 rounded-xl border border-[#232e3f] bg-[#0c111a] p-2.5';
    $('sTargets').innerHTML = hdrs.map((h) => {
      const auto = TARGET_HINTS.some((t) => h.toLowerCase().includes(t));
      return `<label class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm hover:bg-[#141b27]"><input type="checkbox" class="s-target h-4 w-4 rounded border-[#2a3547] bg-[#0c111a] text-cyan-500 focus:ring-cyan-500/30" value="${esc(h)}" ${auto ? 'checked' : ''}/><span class="text-slate-300">${esc(h)}</span></label>`;
    }).join('');
    $('sTargets').querySelectorAll('.s-target').forEach((c) => c.addEventListener('change', sRefresh));
    sRefresh();
  });
}
function sSelectedTargets() { return [...document.querySelectorAll('.s-target:checked')].map((c) => c.value); }
function sRefresh() { $('sRun').disabled = !(sol.headers.length && sSelectedTargets().length); }
async function sRun() {
  const targets = sSelectedTargets();
  if (!sol.headers.length) return setStatus('sStatus', 'Upload a CSV first.', 'err');
  if (!targets.length) return setStatus('sStatus', 'Pick at least one column to fill.', 'err');
  $('sRun').disabled = true;
  setStatus('sStatus', `Adding solutions to ${sol.rows.length} questions…`, 'load');
  try {
    const resp = await api(API.solve, {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ headers: sol.headers, rows: sol.rows, targets, model: $('sModel').value, extra: $('sExtra').value.trim(), source: sol.source }),
    });
    const data = await resp.json();
    if (!resp.ok) throw new Error(data.error || 'Solving failed');
    sol.results = data.rows || [];
    $('sResultCard').classList.remove('hidden');
    $('sResultCount').textContent = `${sol.results.length}`;
    renderTable($('sResultTable'), sol.headers, sol.results);
    setStatus('sStatus', `Done — solutions added to ${sol.results.length}${data.saved ? ', saved' : ''}.`, 'ok');
    toast(`Solved ${sol.results.length} questions`);
  } catch (e) { setStatus('sStatus', e.message, 'err'); toast(e.message, 'err'); }
  finally { sRefresh(); }
}

/* ============================================================
   DASHBOARD
   ============================================================ */
let jobFilter = '';
function statCard(label, value, corner, svg) {
  return `<div class="card corner ${corner} p-4">
    <div class="mb-2 flex h-9 w-9 items-center justify-center rounded-lg bg-[#0c111a] ring-1 ring-[#232e3f]">${svg}</div>
    <div class="text-2xl font-bold tracking-tight text-white">${value}</div>
    <div class="text-xs font-medium text-slate-500">${label}</div></div>`;
}
async function loadJobs() {
  try {
    const url = jobFilter ? `${API.jobs}?type=${jobFilter}` : API.jobs;
    const resp = await api(url);
    const data = await resp.json();
    const s = data.stats || { questions: 0, generated_questions: 0, solved_questions: 0, jobs: 0 };
    $('statCards').innerHTML =
      statCard('Total questions', s.questions, 'corner-cyan', '<svg class="h-5 w-5 text-cyan-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2"/></svg>') +
      statCard('Generated', s.generated_questions, 'corner-blue', '<svg class="h-5 w-5 text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M13 3l-1.5 4.5L7 9l4.5 1.5L13 15l1.5-4.5L19 9l-4.5-1.5L13 3z"/></svg>') +
      statCard('Solved', s.solved_questions, 'corner-green', '<svg class="h-5 w-5 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>') +
      statCard('Batches', s.jobs, 'corner-amber', '<svg class="h-5 w-5 text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M4 7c0 1.657 3.582 3 8 3s8-1.343 8-3-3.582-3-8-3-8 1.343-8 3zM4 7v10c0 1.657 3.582 3 8 3s8-1.343 8-3V7"/></svg>');

    const wrap = $('jobsWrap');
    if (!data.db) { wrap.innerHTML = `<div class="p-8 text-center text-sm text-slate-500">No database connected. Configure MySQL in <code class="text-cyan-400">api/config.php</code> to track and re-download your batches. Generation &amp; export still work without it.</div>`; return; }
    const jobs = data.jobs || [];
    if (!jobs.length) { wrap.innerHTML = `<div class="p-8 text-center text-sm text-slate-500">No batches yet. Head to <a href="#generate" class="font-medium text-cyan-400 underline">Question Builder</a> to create some.</div>`; return; }
    wrap.innerHTML = `<div class="nice-scroll overflow-x-auto"><table class="w-full text-sm">
      <thead><tr class="text-left text-xs uppercase tracking-wide text-slate-500"><th class="px-3 py-2 font-semibold">Type</th><th class="px-3 py-2 font-semibold">Topic / file</th><th class="px-3 py-2 font-semibold">Rows</th><th class="px-3 py-2 font-semibold">Model</th><th class="px-3 py-2 font-semibold">When</th><th class="px-3 py-2 font-semibold text-right">Action</th></tr></thead>
      <tbody>${jobs.map(jobRow).join('')}</tbody></table></div>`;
  } catch {}
}
function jobRow(j) {
  const badge = j.type === 'generate'
    ? '<span class="rounded-full bg-blue-500/15 px-2 py-0.5 text-xs font-semibold text-blue-400">Generated</span>'
    : '<span class="rounded-full bg-cyan-500/15 px-2 py-0.5 text-xs font-semibold text-cyan-400">Solved</span>';
  return `<tr class="border-t border-[#1b2433]">
    <td class="px-3 py-2.5">${badge}</td>
    <td class="px-3 py-2.5 font-medium text-slate-200">${esc(j.topic || j.source || '—')}</td>
    <td class="px-3 py-2.5 text-slate-400">${j.count}</td>
    <td class="px-3 py-2.5 text-xs text-slate-500">${esc(j.model || '')}</td>
    <td class="px-3 py-2.5 text-xs text-slate-500">${esc(j.created_at || '')}</td>
    <td class="px-3 py-2.5 text-right"><a href="${API.download(j.id)}" class="btn-ghost inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-semibold"><svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3"/></svg>CSV</a></td></tr>`;
}

/* ============================================================
   SETTINGS (Groq only)
   ============================================================ */
async function loadSettings() {
  try {
    const resp = await api(API.settings);
    const data = await resp.json();
    const s = data.settings || {};
    $('setKey').placeholder = s.groq_api_key || 'gsk_...';
    $('setKey').value = '';
    $('setModel').value = s.groq_model || 'llama-3.3-70b-versatile';
    updateBadges(data.hasKey, data.db);
  } catch {}
}
async function saveSettings() {
  setStatus('setStatus', 'Saving…', 'load');
  const payload = { groq_model: $('setModel').value };
  if ($('setKey').value.trim()) payload.groq_api_key = $('setKey').value.trim();
  try {
    const resp = await api(API.settings, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
    const data = await resp.json();
    if (!resp.ok) throw new Error(data.error || 'Could not save');
    setStatus('setStatus', `Saved. Groq: ${data.hasKey ? 'ready' : 'no key'} · DB: ${data.db ? 'connected' : 'not connected'}.`, 'ok');
    toast('Settings saved');
    updateBadges(data.hasKey, data.db);
    loadSettings();
  } catch (e) { setStatus('setStatus', e.message, 'err'); toast(e.message, 'err'); }
}

/* ============================================================
   WIRE APP (once, after first login)
   ============================================================ */
function wireDrop(dropId, inputId, handler) {
  const drop = $(dropId), input = $(inputId);
  input.addEventListener('change', (e) => handler(e.target.files[0]));
  ['dragover', 'dragenter'].forEach((ev) => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.add('border-cyan-400/60', 'bg-cyan-500/5'); }));
  ['dragleave', 'drop'].forEach((ev) => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.remove('border-cyan-400/60', 'bg-cyan-500/5'); }));
  drop.addEventListener('drop', (e) => { const f = e.dataTransfer.files[0]; if (f) handler(f); });
}
function wireApp() {
  window.addEventListener('hashchange', route);
  $('menuBtn').addEventListener('click', openSidebar);
  $('backdrop').addEventListener('click', closeSidebar);
  $('logoutBtn').addEventListener('click', doLogout);

  wireDrop('gDrop', 'gFile', gHandleFile);
  $('gTopic').addEventListener('input', gRefresh);
  $('gRun').addEventListener('click', gRun);
  $('gExport').addEventListener('click', () => { if (gen.results.length) downloadCSV((($('gTopic').value.trim() || 'questions').replace(/[^a-z0-9]+/gi, '_')) + '.csv', buildCSV(gen.headers, gen.results)); });
  $('gClear').addEventListener('click', () => { gen.results = []; $('gResultCard').classList.add('hidden'); setStatus('gStatus', 'Cleared.', ''); });

  wireDrop('sDrop', 'sFile', sHandleFile);
  $('sRun').addEventListener('click', sRun);
  $('sExport').addEventListener('click', () => { if (sol.results.length) downloadCSV(((sol.source || 'solved').replace(/\.csv$/i, '').replace(/[^a-z0-9]+/gi, '_')) + '_solved.csv', buildCSV(sol.headers, sol.results)); });
  $('sClear').addEventListener('click', () => { sol.results = []; $('sResultCard').classList.add('hidden'); setStatus('sStatus', 'Cleared.', ''); });

  $('refreshJobs').addEventListener('click', loadJobs);
  document.querySelectorAll('.job-filter').forEach((b) => b.addEventListener('click', () => {
    jobFilter = b.dataset.filter;
    document.querySelectorAll('.job-filter').forEach((x) => x.classList.remove('bg-cyan-400', 'text-[#06222a]', 'border-cyan-400'));
    b.classList.add('bg-cyan-400', 'text-[#06222a]', 'border-cyan-400');
    loadJobs();
  }));

  $('setSave').addEventListener('click', saveSettings);
}

/* ============================================================
   BOOT
   ============================================================ */
async function boot() {
  $('loginForm').addEventListener('submit', doLogin);
  const authed = await checkAuth();
  if (authed) showApp(); else showLogin();
}
boot();
