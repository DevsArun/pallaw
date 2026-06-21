/* ============================================================
   Pallaw — dashboard SPA
   Views: dashboard · generate · solve · settings
   ============================================================ */

const API = {
  health: 'api/health.php',
  generate: 'api/generate.php',
  solve: 'api/solve.php',
  jobs: 'api/jobs.php',
  settings: 'api/settings.php',
  download: (id) => `api/download.php?id=${encodeURIComponent(id)}`,
};

const $ = (id) => document.getElementById(id);
const esc = (s) => String(s ?? '').replace(/[&<>]/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[m]));

/* ---------- toast ---------- */
let toastTimer;
function toast(msg, type = 'ok') {
  const el = $('toast');
  const bg = type === 'err' ? 'bg-rose-600' : type === 'load' ? 'bg-ink-900' : 'bg-emerald-600';
  el.innerHTML = `<div class="pointer-events-auto flex items-center gap-2 rounded-xl ${bg} px-4 py-2.5 text-sm font-medium text-white shadow-glow">${type === 'load' ? '<span class="spinner"></span>' : ''}<span>${esc(msg)}</span></div>`;
  el.style.opacity = '1';
  clearTimeout(toastTimer);
  if (type !== 'load') toastTimer = setTimeout(() => (el.style.opacity = '0'), 2600);
}

function setStatus(id, msg, type = '') {
  const el = $(id);
  const colors = { err: 'text-rose-600', ok: 'text-emerald-600', load: 'text-violet-600', '': 'text-ink-500' };
  el.className = 'min-h-[18px] text-sm flex items-center gap-2 ' + (colors[type] || colors['']);
  el.innerHTML = type === 'load' ? `<span class="spinner spinner-dark"></span><span>${esc(msg)}</span>` : esc(msg);
}

/* ---------- CSV utils ---------- */
function parseCSV(text) {
  const rows = []; let row = []; let field = ''; let q = false;
  text = text.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
  for (let i = 0; i < text.length; i++) {
    const c = text[i];
    if (q) {
      if (c === '"') { if (text[i + 1] === '"') { field += '"'; i++; } else q = false; }
      else field += c;
    } else {
      if (c === '"') q = true;
      else if (c === ',') { row.push(field); field = ''; }
      else if (c === '\n') { row.push(field); rows.push(row); row = []; field = ''; }
      else field += c;
    }
  }
  if (field.length || row.length) { row.push(field); rows.push(row); }
  return rows.filter((r) => r.some((v) => String(v).trim() !== ''));
}
function rowsToObjects(rows) {
  if (!rows.length) return { hdrs: [], objs: [] };
  const hdrs = rows[0].map((h) => h.trim());
  const objs = rows.slice(1).map((r) => { const o = {}; hdrs.forEach((h, i) => (o[h] = (r[i] ?? '').trim())); return o; });
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
  let h = '<thead><tr class="bg-ink-100/70 text-left text-xs uppercase tracking-wide text-ink-500">' +
    cols.map((c) => `<th class="whitespace-nowrap px-3 py-2.5 font-semibold">${esc(c)}</th>`).join('') + '</tr></thead><tbody>';
  for (const r of data) h += '<tr class="border-t border-ink-200 align-top">' + cols.map((c) => `<td class="px-3 py-2.5 text-ink-700 max-w-[340px]">${esc(r[c])}</td>`).join('') + '</tr>';
  el.innerHTML = h + '</tbody>';
}

/* ---------- router ---------- */
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

/* ---------- sidebar (mobile) ---------- */
function openSidebar() { $('sidebar').classList.remove('-translate-x-full'); $('backdrop').classList.remove('hidden'); }
function closeSidebar() { if (window.innerWidth < 1024) { $('sidebar').classList.add('-translate-x-full'); $('backdrop').classList.add('hidden'); } }

/* ============================================================
   GENERATE view
   ============================================================ */
const gen = { headers: [], samples: [], source: '', results: [] };

function gHandleFile(file) {
  if (!file) return;
  gen.source = file.name;
  const r = new FileReader();
  r.onload = (e) => {
    const { hdrs, objs } = rowsToObjects(parseCSV(e.target.result));
    if (!hdrs.length) return setStatus('gStatus', 'That CSV looks empty.', 'err');
    gen.headers = hdrs; gen.samples = objs;
    $('gFileLabel').innerHTML = `<span class="font-semibold text-ink-900">${esc(file.name)}</span> · ${objs.length} rows`;
    $('gRowCount').textContent = `${objs.length} samples`;
    $('gChips').innerHTML = hdrs.map((h) => `<span class="rounded-full border border-ink-200 bg-ink-100 px-2.5 py-1 text-xs font-medium text-ink-700">${esc(h)}</span>`).join('');
    renderTable($('gPreviewTable'), hdrs, objs, 5);
    $('gPreview').classList.remove('hidden');
    gRefresh();
  };
  r.readAsText(file);
}
function gRefresh() { $('gRun').disabled = !(gen.headers.length && $('gTopic').value.trim()); }

async function gRun() {
  const topic = $('gTopic').value.trim();
  if (!gen.headers.length) return setStatus('gStatus', 'Upload a sample CSV first.', 'err');
  if (!topic) return setStatus('gStatus', 'Enter a topic.', 'err');
  const count = parseInt($('gCount').value, 10) || 5;
  $('gRun').disabled = true;
  setStatus('gStatus', `Generating ${count} questions…`, 'load');
  try {
    const resp = await fetch(API.generate, {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ headers: gen.headers, samples: gen.samples.slice(0, 8), topic, count, model: $('gModel').value, extra: $('gExtra').value.trim(), source: gen.source }),
    });
    const data = await resp.json();
    if (!resp.ok) throw new Error(data.error || 'Generation failed');
    const qs = data.questions || [];
    if (!qs.length) throw new Error('No questions generated. Try again.');
    gen.results = gen.results.concat(qs);
    $('gResultCard').classList.remove('hidden');
    $('gResultCount').textContent = `${gen.results.length}`;
    renderTable($('gResultTable'), gen.headers, gen.results);
    setStatus('gStatus', `Done — ${qs.length} new${data.saved ? ', saved to history' : ''}. Total ${gen.results.length}.`, 'ok');
    toast(`Generated ${qs.length} questions`);
  } catch (e) { setStatus('gStatus', e.message, 'err'); toast(e.message, 'err'); }
  finally { gRefresh(); }
}

/* ============================================================
   SOLVE view
   ============================================================ */
const sol = { headers: [], rows: [], source: '', results: [] };
const TARGET_HINTS = ['explanation', 'solution', 'answer', 'correct_option', 'correct', 'ans', 'working', 'steps'];

function sHandleFile(file) {
  if (!file) return;
  sol.source = file.name;
  const r = new FileReader();
  r.onload = (e) => {
    const { hdrs, objs } = rowsToObjects(parseCSV(e.target.result));
    if (!hdrs.length) return setStatus('sStatus', 'That CSV looks empty.', 'err');
    sol.headers = hdrs; sol.rows = objs;
    $('sFileLabel').innerHTML = `<span class="font-semibold text-ink-900">${esc(file.name)}</span> · ${objs.length} rows`;
    $('sRowCount').textContent = `${objs.length} questions`;
    $('sChips').innerHTML = hdrs.map((h) => `<span class="rounded-full border border-ink-200 bg-ink-100 px-2.5 py-1 text-xs font-medium text-ink-700">${esc(h)}</span>`).join('');
    renderTable($('sPreviewTable'), hdrs, objs, 5);
    $('sPreview').classList.remove('hidden');
    // build target checkboxes; auto-check likely solution columns
    $('sTargets').className = 'space-y-1.5 rounded-xl border border-ink-200 bg-white p-2.5';
    $('sTargets').innerHTML = hdrs.map((h) => {
      const auto = TARGET_HINTS.some((t) => h.toLowerCase().includes(t));
      return `<label class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm hover:bg-ink-50"><input type="checkbox" class="s-target h-4 w-4 rounded border-ink-300 text-sky-600 focus:ring-sky-200" value="${esc(h)}" ${auto ? 'checked' : ''}/><span class="text-ink-700">${esc(h)}</span></label>`;
    }).join('');
    $('sTargets').querySelectorAll('.s-target').forEach((c) => c.addEventListener('change', sRefresh));
    sRefresh();
  };
  r.readAsText(file);
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
    const resp = await fetch(API.solve, {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ headers: sol.headers, rows: sol.rows, targets, model: $('sModel').value, extra: $('sExtra').value.trim(), source: sol.source }),
    });
    const data = await resp.json();
    if (!resp.ok) throw new Error(data.error || 'Solving failed');
    sol.results = data.rows || [];
    $('sResultCard').classList.remove('hidden');
    $('sResultCount').textContent = `${sol.results.length}`;
    renderTable($('sResultTable'), sol.headers, sol.results);
    setStatus('sStatus', `Done — solutions added to ${sol.results.length} questions${data.saved ? ', saved to history' : ''}.`, 'ok');
    toast(`Solved ${sol.results.length} questions`);
  } catch (e) { setStatus('sStatus', e.message, 'err'); toast(e.message, 'err'); }
  finally { sRefresh(); }
}

/* ============================================================
   DASHBOARD view
   ============================================================ */
let jobFilter = '';
function statCard(label, value, accent, svg) {
  return `<div class="rounded-2xl border border-ink-200 bg-white p-4 shadow-card">
    <div class="mb-2 flex h-9 w-9 items-center justify-center rounded-lg ${accent}">${svg}</div>
    <div class="text-2xl font-bold tracking-tight">${value}</div>
    <div class="text-xs font-medium text-ink-500">${label}</div></div>`;
}
async function loadJobs() {
  try {
    const url = jobFilter ? `${API.jobs}?type=${jobFilter}` : API.jobs;
    const resp = await fetch(url);
    const data = await resp.json();

    const stats = data.stats || { questions: 0, generated_questions: 0, solved_questions: 0, jobs: 0 };
    $('statCards').innerHTML =
      statCard('Total questions', stats.questions, 'bg-violet-50 text-violet-600', '<svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>') +
      statCard('Generated', stats.generated_questions, 'bg-blue-50 text-blue-600', '<svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M13 3l-1.5 4.5L7 9l4.5 1.5L13 15l1.5-4.5L19 9l-4.5-1.5L13 3z"/></svg>') +
      statCard('Solved', stats.solved_questions, 'bg-sky-50 text-sky-600', '<svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>') +
      statCard('Batches', stats.jobs, 'bg-emerald-50 text-emerald-600', '<svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M4 7c0 1.657 3.582 3 8 3s8-1.343 8-3-3.582-3-8-3-8 1.343-8 3zM4 7v10c0 1.657 3.582 3 8 3s8-1.343 8-3V7"/></svg>');

    const wrap = $('jobsWrap');
    if (!data.db) {
      wrap.innerHTML = `<div class="p-8 text-center text-sm text-ink-400">No database connected. Add MySQL details in <a href="#settings" class="font-medium text-violet-600 underline">Settings</a> to track and re-download your batches. Generation &amp; export still work without it.</div>`;
      return;
    }
    const jobs = data.jobs || [];
    if (!jobs.length) { wrap.innerHTML = `<div class="p-8 text-center text-sm text-ink-400">No batches yet. Head to <a href="#generate" class="font-medium text-violet-600 underline">Question Builder</a> to create some.</div>`; return; }

    wrap.innerHTML = `<div class="nice-scroll overflow-x-auto"><table class="w-full text-sm">
      <thead><tr class="text-left text-xs uppercase tracking-wide text-ink-400"><th class="px-3 py-2 font-semibold">Type</th><th class="px-3 py-2 font-semibold">Topic / file</th><th class="px-3 py-2 font-semibold">Rows</th><th class="px-3 py-2 font-semibold">Model</th><th class="px-3 py-2 font-semibold">When</th><th class="px-3 py-2 font-semibold text-right">Action</th></tr></thead>
      <tbody>${jobs.map(jobRow).join('')}</tbody></table></div>`;
  } catch { /* best effort */ }
}
function jobRow(j) {
  const badge = j.type === 'generate'
    ? '<span class="rounded-full bg-blue-50 px-2 py-0.5 text-xs font-semibold text-blue-700">Generated</span>'
    : '<span class="rounded-full bg-sky-50 px-2 py-0.5 text-xs font-semibold text-sky-700">Solved</span>';
  const name = esc(j.topic || j.source || '—');
  return `<tr class="border-t border-ink-200">
    <td class="px-3 py-2.5">${badge}</td>
    <td class="px-3 py-2.5 font-medium text-ink-800">${name}</td>
    <td class="px-3 py-2.5 text-ink-600">${j.count}</td>
    <td class="px-3 py-2.5 text-xs text-ink-500">${esc(j.model || '')}</td>
    <td class="px-3 py-2.5 text-xs text-ink-500">${esc(j.created_at || '')}</td>
    <td class="px-3 py-2.5 text-right"><a href="${API.download(j.id)}" class="inline-flex items-center gap-1.5 rounded-lg border border-ink-200 px-2.5 py-1.5 text-xs font-semibold text-ink-700 hover:bg-ink-100"><svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3"/></svg>CSV</a></td></tr>`;
}

/* ============================================================
   SETTINGS view
   ============================================================ */
async function loadSettings() {
  try {
    const resp = await fetch(API.settings);
    const data = await resp.json();
    const s = data.settings || {};
    $('setKey').placeholder = s.groq_api_key || 'gsk_...';
    $('setKey').value = '';
    $('setModel').value = s.groq_model || 'llama-3.3-70b-versatile';
    $('setDbHost').value = s.db_host || '';
    $('setDbPort').value = s.db_port || '';
    $('setDbName').value = s.db_name || '';
    $('setDbUser').value = s.db_user || '';
    $('setDbPass').value = '';
    $('setDbPass').placeholder = s.db_pass || '';
    updateBadges(data.hasKey, data.db);
  } catch { /* ignore */ }
}
async function saveSettings() {
  setStatus('setStatus', 'Saving…', 'load');
  const payload = {
    groq_model: $('setModel').value,
    db_host: $('setDbHost').value.trim(),
    db_port: $('setDbPort').value.trim(),
    db_name: $('setDbName').value.trim(),
    db_user: $('setDbUser').value.trim(),
  };
  if ($('setKey').value.trim()) payload.groq_api_key = $('setKey').value.trim();
  if ($('setDbPass').value.trim()) payload.db_pass = $('setDbPass').value.trim();
  try {
    const resp = await fetch(API.settings, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
    const data = await resp.json();
    if (!resp.ok) throw new Error(data.error || 'Could not save');
    setStatus('setStatus', `Saved. Groq: ${data.hasKey ? 'ready' : 'no key'} · DB: ${data.db ? 'connected' : 'not connected'}.`, 'ok');
    toast('Settings saved');
    updateBadges(data.hasKey, data.db);
    loadSettings();
  } catch (e) { setStatus('setStatus', e.message, 'err'); toast(e.message, 'err'); }
}

/* ---------- header badges ---------- */
function updateBadges(hasKey, db) {
  $('keyStatus').innerHTML = hasKey
    ? '<span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span><span class="text-emerald-700">Groq ready</span>'
    : '<span class="h-1.5 w-1.5 rounded-full bg-rose-500"></span><span class="text-rose-600">No API key</span>';
  $('dbStatus').innerHTML = db
    ? '<span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span><span class="text-emerald-700">MySQL connected</span>'
    : '<span class="h-1.5 w-1.5 rounded-full bg-ink-300"></span><span class="text-ink-500">No database</span>';
}

/* ============================================================
   INIT
   ============================================================ */
function init() {
  // health for badges
  fetch(API.health).then((r) => r.json()).then((h) => updateBadges(h.hasKey, h.db)).catch(() => {});

  window.addEventListener('hashchange', route);
  route();

  $('menuBtn').addEventListener('click', openSidebar);
  $('backdrop').addEventListener('click', closeSidebar);

  // Generate wiring
  wireDrop('gDrop', 'gFile', gHandleFile, 'violet');
  $('gTopic').addEventListener('input', gRefresh);
  $('gRun').addEventListener('click', gRun);
  $('gExport').addEventListener('click', () => {
    if (!gen.results.length) return;
    downloadCSV((($('gTopic').value.trim() || 'questions').replace(/[^a-z0-9]+/gi, '_')) + '.csv', buildCSV(gen.headers, gen.results));
  });
  $('gClear').addEventListener('click', () => { gen.results = []; $('gResultCard').classList.add('hidden'); setStatus('gStatus', 'Cleared.', ''); });

  // Solve wiring
  wireDrop('sDrop', 'sFile', sHandleFile, 'sky');
  $('sRun').addEventListener('click', sRun);
  $('sExport').addEventListener('click', () => {
    if (!sol.results.length) return;
    downloadCSV(((sol.source || 'solved').replace(/\.csv$/i, '').replace(/[^a-z0-9]+/gi, '_')) + '_solved.csv', buildCSV(sol.headers, sol.results));
  });
  $('sClear').addEventListener('click', () => { sol.results = []; $('sResultCard').classList.add('hidden'); setStatus('sStatus', 'Cleared.', ''); });

  // Dashboard wiring
  $('refreshJobs').addEventListener('click', loadJobs);
  document.querySelectorAll('.job-filter').forEach((b) => b.addEventListener('click', () => {
    jobFilter = b.dataset.filter;
    document.querySelectorAll('.job-filter').forEach((x) => x.classList.remove('bg-ink-900', 'text-white', 'border-ink-900'));
    b.classList.add('bg-ink-900', 'text-white', 'border-ink-900');
    loadJobs();
  }));

  // Settings wiring
  $('setSave').addEventListener('click', saveSettings);
}

function wireDrop(dropId, inputId, handler, color) {
  const drop = $(dropId), input = $(inputId);
  input.addEventListener('change', (e) => handler(e.target.files[0]));
  ['dragover', 'dragenter'].forEach((ev) => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.add(`border-${color}-400`, `bg-${color}-50/40`); }));
  ['dragleave', 'drop'].forEach((ev) => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.remove(`border-${color}-400`, `bg-${color}-50/40`); }));
  drop.addEventListener('drop', (e) => { const f = e.dataTransfer.files[0]; if (f) handler(f); });
}

init();
