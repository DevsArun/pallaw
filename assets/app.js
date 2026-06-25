/* ============================================================
   Tunnl AI — admin panel SPA (dark + cyan)
   Auth lock · Dashboard · Question Builder · Solution Builder · Settings
   ============================================================ */

const API = {
  auth: 'api/auth.php',
  health: 'api/health.php',
  generate: 'api/generate.php',
  solve: 'api/solve.php',
  save: 'api/save.php',
  jobs: 'api/jobs.php',
  del: 'api/delete.php',
  settings: 'api/settings.php',
  download: (id) => `api/download.php?id=${encodeURIComponent(id)}`,
};

const CHUNK_GEN = 5;     // questions per request — small so each request fits one minute's limit
const CHUNK_SOLVE = 5;   // rows solved per request
const CHUNK_DELAY = 600; // ms between chunks
const RATE_RE = /rate limit|tokens per minute|try again in|TPM/i;

const $ = (id) => document.getElementById(id);
const esc = (s) => String(s ?? '').replace(/[&<>]/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[m]));
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const normQ = (s) => String(s ?? '').toLowerCase().replace(/\s+/g, ' ').trim();

let initialized = false;

/* ---------- toast ---------- */
let toastTimer;
function toast(msg, type = 'ok') {
  const el = $('toast');
  const bg = type === 'err' ? 'bg-rose-600' : type === 'load' ? 'bg-slate-800' : 'bg-emerald-600';
  el.innerHTML = `<div class="pointer-events-auto flex max-w-full items-center gap-2 rounded-xl ${bg} px-4 py-2.5 text-sm font-medium text-white shadow-lg ring-1 ring-white/10">${type === 'load' ? '<span class="spinner shrink-0"></span>' : ''}<span class="min-w-0">${esc(msg)}</span></div>`;
  el.style.opacity = '1';
  clearTimeout(toastTimer);
  if (type !== 'load') toastTimer = setTimeout(() => (el.style.opacity = '0'), 2800);
}
function setStatus(id, msg, type = '') {
  const el = $(id);
  const colors = { err: 'text-rose-400', ok: 'text-emerald-400', load: 'text-cyan-400', '': 'text-slate-400' };
  el.className = 'min-h-[18px] text-sm flex items-center gap-2 ' + (colors[type] || colors['']);
  el.innerHTML = type === 'load' ? `<span class="spinner spinner-dark"></span><span>${esc(msg)}</span>` : esc(msg);
}

/* ---------- progress bar ---------- */
function showProgress(prefix, done, total, label) {
  $(prefix + 'Prog').classList.remove('hidden');
  const pct = total ? Math.round((done / total) * 100) : 0;
  $(prefix + 'ProgFill').style.width = pct + '%';
  $(prefix + 'ProgText').textContent = label || `${done} / ${total}`;
}
function hideProgress(prefix) { $(prefix + 'Prog').classList.add('hidden'); }

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
function detectDelim(text) {
  const line = (text.split(/\r?\n/).find((l) => l.trim() !== '') || '');
  const cands = [',', '\t', ';', '|']; let best = ',', bestN = -1;
  for (const d of cands) { const n = line.split(d).length - 1; if (n > bestN) { bestN = n; best = d; } }
  return best;
}
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
        rows = XLSX.utils.sheet_to_json(ws, { header: 1, defval: '', raw: false, blankrows: false }).map((r) => r.map((c) => String(c ?? '')));
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
  let hi = rows.findIndex((r) => nonEmpty(r) >= 2);
  if (hi < 0) hi = 0;
  const seen = {};
  const hdrs = rows[hi].map((h, i) => {
    let name = String(h ?? '').trim();
    if (name === '') name = 'column_' + (i + 1);
    if (seen[name] === undefined) { seen[name] = 1; return name; }
    seen[name] += 1; return name + '_' + seen[name];
  });
  const objs = rows.slice(hi + 1).map((r) => { const o = {}; hdrs.forEach((h, i) => (o[h] = String(r[i] ?? '').trim())); return o; });
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
  try { const r = await fetch(`${API.auth}?action=check`); const d = await r.json(); return !!d.authed; } catch { return false; }
}
function showLogin() {
  $('appShell').classList.add('hidden');
  $('loginScreen').classList.remove('hidden'); $('loginScreen').classList.add('flex');
  setTimeout(() => $('loginUser').focus(), 50);
}
function showApp() {
  $('loginScreen').classList.add('hidden'); $('loginScreen').classList.remove('flex');
  $('appShell').classList.remove('hidden');
  if (!initialized) { wireApp(); initialized = true; }
  startClock(); refreshHealth(); route();
}
async function doLogin(e) {
  e.preventDefault();
  const username = $('loginUser').value.trim(); const password = $('loginPass').value;
  $('loginStatus').className = 'mt-3 min-h-[18px] text-center text-sm text-cyan-400';
  $('loginStatus').innerHTML = '<span class="spinner spinner-dark inline-block align-middle"></span> Signing in…';
  $('loginBtn').disabled = true;
  try {
    const r = await fetch(API.auth, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ username, password, action: 'login' }) });
    const d = await r.json();
    if (!r.ok || !d.authed) throw new Error(d.error || 'Login failed');
    $('loginPass').value = ''; $('loginStatus').textContent = ''; showApp();
  } catch (err) {
    $('loginStatus').className = 'mt-3 min-h-[18px] text-center text-sm text-rose-400';
    $('loginStatus').textContent = err.message;
  } finally { $('loginBtn').disabled = false; }
}
async function doLogout() { try { await fetch(`${API.auth}?action=logout`); } catch {} showLogin(); }
async function api(url, opts) {
  const r = await fetch(url, opts);
  if (r.status === 401) { showLogin(); throw new Error('Session expired. Please sign in again.'); }
  return r;
}
function parseWait(msg) {
  const t = String(msg || '');
  // Prefer an explicit "Xh Ym Zs" style wait if present.
  const hm = t.match(/(?:about\s+)?(?:(\d+)\s*h)?\s*(?:(\d+)\s*m)?\s*(?:([\d.]+)\s*s)?/i);
  if (hm && (hm[1] || hm[2] || hm[3])) {
    const secs = (parseFloat(hm[1] || 0) * 3600) + (parseFloat(hm[2] || 0) * 60) + parseFloat(hm[3] || 0);
    if (secs > 0) return Math.min(90, Math.max(5, Math.ceil(secs)));
  }
  const m = t.match(/about\s+(\d+)\s*s|in\s+([\d.]+)\s*s/i);
  const n = m ? parseFloat(m[1] || m[2]) : 0;
  return Math.min(90, Math.max(5, Math.ceil(n))) || 20;
}
// Seconds to wait before the next retry — trust the server's numeric hint first.
function waitSeconds(data) {
  const ra = data && Number(data.retryAfter);
  if (ra && isFinite(ra) && ra > 0) return Math.min(90, Math.max(5, Math.ceil(ra)));
  return parseWait(data && data.error);
}
const isRateLimited = (resp, data) => resp.status === 429 || RATE_RE.test((data && data.error) || '');
// A limit waiting cannot fix (per-day quota) — server sets retryable:false.
const isWaitFixable = (data) => !(data && data.retryable === false);
const MAX_RL_RETRIES = 6; // give up after this many consecutive rate limits (no progress)

// Friendly names for the toast/status when the backend auto-switches models.
const MODEL_LABELS = {
  'llama-3.3-70b-versatile': 'Llama 3.3 70B',
  'llama-3.1-8b-instant': 'Llama 3.1 8B',
  'openai/gpt-oss-120b': 'GPT-OSS 120B',
  'openai/gpt-oss-20b': 'GPT-OSS 20B',
};
const modelLabel = (m) => MODEL_LABELS[m] || m || 'auto';
// Keep the dropdown <select> in sync when the backend switches models.
function syncModelSelect(selectId, model) {
  const sel = $(selectId);
  if (sel && model && [...sel.options].some((o) => o.value === model)) sel.value = model;
}

/* ---------- clock ---------- */
let clockTimer;
function startClock() {
  clearInterval(clockTimer);
  const tick = () => {
    const d = new Date();
    const date = d.toLocaleDateString('en-GB', { weekday: 'short', day: '2-digit', month: 'short' });
    const time = d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    $('clock').textContent = `${date} · ${time}`;
  };
  tick(); clockTimer = setInterval(tick, 1000); $('clock').classList.remove('hidden');
}

/* ---------- health badges ---------- */
async function refreshHealth() { try { const r = await api(API.health); const h = await r.json(); updateBadges(h.hasKey, h.db); } catch {} }
function updateBadges(hasKey, db) {
  $('keyStatus').innerHTML = hasKey
    ? '<span class="h-1.5 w-1.5 rounded-full bg-emerald-400"></span><span class="text-emerald-400">Groq ready</span>'
    : '<span class="h-1.5 w-1.5 rounded-full bg-rose-400"></span><span class="text-rose-400">No API key</span>';
  $('dbStatus').innerHTML = db
    ? '<span class="h-1.5 w-1.5 rounded-full bg-emerald-400"></span><span class="text-emerald-400">MySQL connected</span>'
    : '<span class="h-1.5 w-1.5 rounded-full bg-slate-600"></span><span class="text-slate-500">No database</span>';
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
function openSidebar() { $('sidebar').classList.remove('-translate-x-full'); $('backdrop').classList.remove('hidden'); }
function closeSidebar() { if (window.innerWidth < 1024) { $('sidebar').classList.add('-translate-x-full'); $('backdrop').classList.add('hidden'); } }

/* ============================================================
   GENERATE (chunked, unique, progress)
   ============================================================ */
const gen = { samples: [], diverseSamples: [], typeCount: 0, previewHdrs: [], qcol: '', source: '', headers: [], results: [], running: false };

// Find the column holding the question text (by name, else longest-text column).
function findQuestionCol(hdrs, objs) {
  const byName = hdrs.find((h) => /quest|ques|problem/i.test(h));
  if (byName) return byName;
  let best = hdrs[0], bestLen = -1;
  for (const h of hdrs) {
    let len = 0; const n = Math.min(objs.length, 20);
    for (let i = 0; i < n; i++) len += String(objs[i][h] || '').length;
    const avg = n ? len / n : 0;
    if (avg > bestLen) { bestLen = avg; best = h; }
  }
  return best;
}
// Signature that ignores numbers, so "series: 1,2,3" and "series: 5,6,7" group together.
function qSignature(t) {
  return String(t || '').toLowerCase().replace(/\d+(\.\d+)?/g, '#').replace(/[^a-z#? ]+/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 50);
}
// Pick representatives covering EVERY distinct question type in the file.
function buildDiverseSamples(objs, qcol, cap = 14) {
  const groups = new Map();
  for (const o of objs) {
    const sig = qSignature(o[qcol]);
    if (!groups.has(sig)) groups.set(sig, []);
    groups.get(sig).push(o);
  }
  const keys = [...groups.keys()];
  const picked = [];
  let round = 0;
  while (picked.length < cap) {
    let added = false;
    for (const k of keys) { const arr = groups.get(k); if (round < arr.length) { picked.push(arr[round]); added = true; if (picked.length >= cap) break; } }
    if (!added) break; round++;
  }
  return { picked, typeCount: keys.length };
}

function gHandleFile(file) {
  if (!file) return;
  gen.source = file.name;
  readTableFile(file, (rows, err) => {
    if (err) return setStatus('gStatus', err, 'err');
    const { hdrs, objs } = rowsToObjects(rows || []);
    if (!hdrs.length) return setStatus('gStatus', 'That file looks empty.', 'err');
    gen.previewHdrs = hdrs; gen.samples = objs;
    gen.qcol = findQuestionCol(hdrs, objs);
    const { picked, typeCount } = buildDiverseSamples(objs, gen.qcol, 14);
    gen.diverseSamples = picked; gen.typeCount = typeCount;
    $('gFileLabel').innerHTML = `<span class="font-semibold text-slate-100">${esc(file.name)}</span> · ${objs.length} rows`;
    $('gRowCount').textContent = `${objs.length} rows · ${typeCount} type${typeCount === 1 ? '' : 's'}`;
    $('gChips').innerHTML = hdrs.map((h) => `<span class="rounded-full border border-[#232e3f] bg-[#141b27] px-2.5 py-1 text-xs font-medium text-slate-300">${esc(h)}</span>`).join('');
    renderTable($('gPreviewTable'), hdrs, objs, 5);
    $('gPreview').classList.remove('hidden');
    setStatus('gStatus', `Detected ${typeCount} question type${typeCount === 1 ? '' : 's'} — output will mix across all of them.`, '');
    gRefresh();
  });
}
function gRefresh() { $('gRun').disabled = gen.running || !gen.samples.length; }

async function gRun() {
  if (gen.running) return;
  if (!gen.samples.length) return setStatus('gStatus', 'Upload a sample file first.', 'err');
  const topic = $('gTopic').value.trim();
  const target = Math.max(1, Math.min(100, parseInt($('gCount').value, 10) || 5));
  let model = $('gModel').value; const extra = $('gExtra').value.trim();

  gen.running = true; gen.results = []; gen.headers = [];
  const seen = new Set();
  let stall = 0, stoppedMsg = '', consecRL = 0;
  const startTs = Date.now(); const MAX_MS = 35 * 60 * 1000;
  $('gResultCard').classList.add('hidden');
  $('gRun').disabled = true;
  showProgress('g', 0, target, `Starting…`);
  setStatus('gStatus', 'Working… you can watch the progress bar.', 'load');

  try {
    while (gen.results.length < target) {
      if (Date.now() - startTs > MAX_MS) { stoppedMsg = `Stopped after a long run at ${gen.results.length}/${target} (saved).`; break; }
      const want = Math.min(CHUNK_GEN, target - gen.results.length);
      const qcolGuess = gen.headers.includes('question_text') ? 'question_text' : (gen.headers[0] || 'question_text');
      // Keep the avoid list SMALL (recent only, truncated) so requests stay cheap.
      const avoid = gen.results.slice(-12).map((r) => String(r[qcolGuess] || '').slice(0, 90)).filter(Boolean);
      let resp;
      try {
        resp = await api(API.generate, {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ samples: (gen.diverseSamples.length ? gen.diverseSamples : gen.samples).slice(0, 14), topic, count: want, model, extra, avoid }),
        });
      } catch (e) { setStatus('gStatus', e.message, 'err'); break; }

      const data = await resp.json().catch(() => ({}));
      if (isRateLimited(resp, data)) {
        consecRL++;
        // A per-DAY quota (or multi-hour wait) can't be fixed by waiting — stop now.
        if (!isWaitFixable(data)) {
          stoppedMsg = (data.error || 'Groq daily quota reached.') + ` Generated ${gen.results.length}/${target} (saved).`;
          break;
        }
        // Waiting clearly isn't helping after several tries in a row — stop.
        if (consecRL > MAX_RL_RETRIES) {
          stoppedMsg = `Groq keeps rate-limiting after ${MAX_RL_RETRIES} retries. Generated ${gen.results.length}/${target} (saved). Try later, use a lighter model, or a paid Groq key.`;
          break;
        }
        const suggested = waitSeconds(data);
        // If quick retries keep failing, wait a FULL minute so the per-minute limit resets.
        const w = consecRL >= 2 ? Math.max(suggested, 60) : suggested;
        for (let s = w; s > 0; s--) { showProgress('g', gen.results.length, target, `Rate limit — waiting ${s}s (${gen.results.length}/${target} done)`); await sleep(1000); }
        continue; // keep retrying recoverable per-minute limits
      }
      consecRL = 0;
      if (!resp.ok) { stoppedMsg = data.error || 'Generation failed'; break; }

      // Backend may have auto-switched to a model that still has quota — adopt it
      // for the remaining chunks so we don't keep hitting the throttled one.
      if (data.model && data.model !== model) {
        toast(`Auto-switched to ${modelLabel(data.model)} (previous model was limited)`);
        setStatus('gStatus', `Switched to ${modelLabel(data.model)} — continuing…`, 'load');
        model = data.model; syncModelSelect('gModel', model);
      }

      if (Array.isArray(data.columns) && data.columns.length) gen.headers = data.columns;
      const qcol = gen.headers.includes('question_text') ? 'question_text' : (gen.headers[0] || 'question_text');
      let added = 0;
      for (const q of (data.questions || [])) {
        const key = normQ(q[qcol]);
        if (!key || seen.has(key)) continue;
        seen.add(key); gen.results.push(q); added++;
        if (gen.results.length >= target) break;
      }
      stall = added === 0 ? stall + 1 : 0;
      showProgress('g', gen.results.length, target, `${gen.results.length} / ${target} unique`);
      renderGenResults();
      if (stall >= 4) { stoppedMsg = `The AI ran out of fresh variations at ${gen.results.length}.`; break; }
      if (gen.results.length < target) await sleep(CHUNK_DELAY);
    }

    if (gen.results.length) {
      renderGenResults();
      await saveBatch('generate', topic, gen.source, gen.headers, gen.results);
      toast(`Generated ${gen.results.length} questions`);
    }
    if (gen.results.length >= target) setStatus('gStatus', `Done — ${gen.results.length} unique questions generated & saved.`, 'ok');
    else setStatus('gStatus', stoppedMsg || `Stopped at ${gen.results.length}/${target}.`, gen.results.length ? 'ok' : 'err');
  } finally {
    hideProgress('g'); gen.running = false; gRefresh();
  }
}
function renderGenResults() {
  $('gResultCard').classList.remove('hidden');
  $('gResultCount').textContent = `${gen.results.length}`;
  renderTable($('gResultTable'), gen.headers, gen.results);
}

/* ============================================================
   SOLVE (chunked, progress) — output = canonical schema + solution
   ============================================================ */
const sol = { rows: [], previewHdrs: [], source: '', headers: [], results: [], running: false };

function sHandleFile(file) {
  if (!file) return;
  sol.source = file.name;
  readTableFile(file, (rows, err) => {
    if (err) return setStatus('sStatus', err, 'err');
    const { hdrs, objs } = rowsToObjects(rows || []);
    if (!hdrs.length) return setStatus('sStatus', 'That file looks empty.', 'err');
    sol.previewHdrs = hdrs; sol.rows = objs;
    $('sFileLabel').innerHTML = `<span class="font-semibold text-slate-100">${esc(file.name)}</span> · ${objs.length} rows`;
    $('sRowCount').textContent = `${objs.length} questions`;
    $('sChips').innerHTML = hdrs.map((h) => `<span class="rounded-full border border-[#232e3f] bg-[#141b27] px-2.5 py-1 text-xs font-medium text-slate-300">${esc(h)}</span>`).join('');
    renderTable($('sPreviewTable'), hdrs, objs, 5);
    $('sPreview').classList.remove('hidden');
    setStatus('sStatus', '');
    sRefresh();
  });
}
function sRefresh() { $('sRun').disabled = sol.running || !sol.rows.length; }

async function sRun() {
  if (sol.running) return;
  if (!sol.rows.length) return setStatus('sStatus', 'Upload a file first.', 'err');
  let model = $('sModel').value; const extra = $('sExtra').value.trim();
  const total = sol.rows.length;

  sol.running = true; sol.results = []; sol.headers = [];
  const startTs = Date.now(); const MAX_MS = 35 * 60 * 1000;
  let stoppedMsg = '', consecRL = 0;
  $('sResultCard').classList.add('hidden');
  $('sRun').disabled = true;
  showProgress('s', 0, total, 'Starting…');
  setStatus('sStatus', 'Solving in batches… on the free tier this can take a few minutes for big files. Keep this tab open.', 'load');

  try {
    let i = 0;
    while (i < total) {
      if (Date.now() - startTs > MAX_MS) { stoppedMsg = `Stopped after a long run at ${sol.results.length}/${total} (saved). Click "Add solutions" again to continue.`; break; }
      const slice = sol.rows.slice(i, i + CHUNK_SOLVE);
      let resp;
      try {
        resp = await api(API.solve, {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ rows: slice, model, extra }),
        });
      } catch (e) { stoppedMsg = e.message; break; }

      const data = await resp.json().catch(() => ({}));
      if (isRateLimited(resp, data)) {
        consecRL++;
        // A per-DAY quota (or multi-hour wait) can't be fixed by waiting — stop now.
        if (!isWaitFixable(data)) {
          stoppedMsg = (data.error || 'Groq daily quota reached.') + ` Solved ${sol.results.length}/${total} (saved).`;
          break;
        }
        // Waiting clearly isn't helping after several tries in a row — stop.
        if (consecRL > MAX_RL_RETRIES) {
          stoppedMsg = `Groq keeps rate-limiting after ${MAX_RL_RETRIES} retries. Solved ${sol.results.length}/${total} (saved). Click "Add solutions" again later to continue, use a lighter model, or a paid Groq key.`;
          break;
        }
        const suggested = waitSeconds(data);
        // If quick retries keep failing, wait a FULL minute so the per-minute limit resets.
        const w = consecRL >= 2 ? Math.max(suggested, 60) : suggested;
        for (let s = w; s > 0; s--) { showProgress('s', sol.results.length, total, `Rate limit — waiting ${s}s (${sol.results.length}/${total} done)`); await sleep(1000); }
        continue; // keep retrying recoverable per-minute limits
      }
      consecRL = 0;
      if (!resp.ok) { stoppedMsg = data.error || 'Solving failed'; break; }

      // Backend may have auto-switched to a model that still has quota — adopt it
      // for the remaining chunks so we don't keep hitting the throttled one.
      if (data.model && data.model !== model) {
        toast(`Auto-switched to ${modelLabel(data.model)} (previous model was limited)`);
        setStatus('sStatus', `Switched to ${modelLabel(data.model)} — continuing…`, 'load');
        model = data.model; syncModelSelect('sModel', model);
      }

      if (Array.isArray(data.columns) && data.columns.length) sol.headers = data.columns;
      sol.results = sol.results.concat(data.rows || []);
      i += slice.length;
      showProgress('s', sol.results.length, total, `${sol.results.length} / ${total} solved`);
      renderSolveResults();
      if (i < total) await sleep(CHUNK_DELAY);
    }

    if (sol.results.length) {
      renderSolveResults();
      await saveBatch('solve', '', sol.source, sol.headers, sol.results);
      toast(`Solved ${sol.results.length} questions`);
    }
    if (sol.results.length >= total) setStatus('sStatus', `Done — all ${sol.results.length} questions solved & saved.`, 'ok');
    else setStatus('sStatus', stoppedMsg || `Stopped at ${sol.results.length}/${total}.`, sol.results.length ? 'ok' : 'err');
  } finally {
    hideProgress('s'); sol.running = false; sRefresh();
  }
}
function renderSolveResults() {
  $('sResultCard').classList.remove('hidden');
  $('sResultCount').textContent = `${sol.results.length}`;
  renderTable($('sResultTable'), sol.headers, sol.results);
}

/* ---------- save a finished batch ---------- */
async function saveBatch(type, topic, source, columns, rows) {
  try {
    await api(API.save, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ type, topic, source, columns, rows }) });
  } catch { /* best effort — export still works */ }
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
    if (!data.db) { wrap.innerHTML = `<div class="p-8 text-center text-sm text-slate-500">No database connected. Configure MySQL in <code class="text-cyan-400">api/config.php</code> to track and re-download your batches.</div>`; return; }
    const jobs = data.jobs || [];
    if (!jobs.length) { wrap.innerHTML = `<div class="p-8 text-center text-sm text-slate-500">No batches yet. Head to <a href="#generate" class="font-medium text-cyan-400 underline">Question Builder</a> to create some.</div>`; return; }
    wrap.innerHTML = `<div class="nice-scroll overflow-x-auto"><table class="w-full text-sm">
      <thead><tr class="text-left text-xs uppercase tracking-wide text-slate-500"><th class="px-3 py-2 font-semibold">Type</th><th class="px-3 py-2 font-semibold">Topic / file</th><th class="px-3 py-2 font-semibold">Rows</th><th class="px-3 py-2 font-semibold">When</th><th class="px-3 py-2 font-semibold text-right">Actions</th></tr></thead>
      <tbody>${jobs.map(jobRow).join('')}</tbody></table></div>`;
    wrap.querySelectorAll('.job-del').forEach((b) => b.addEventListener('click', () => deleteJob(b.dataset.id, b.dataset.name)));
  } catch {}
}
function jobRow(j) {
  const badge = j.type === 'generate'
    ? '<span class="rounded-full bg-blue-500/15 px-2 py-0.5 text-xs font-semibold text-blue-400">Generated</span>'
    : '<span class="rounded-full bg-cyan-500/15 px-2 py-0.5 text-xs font-semibold text-cyan-400">Solved</span>';
  const name = esc(j.topic || j.source || '—');
  return `<tr class="border-t border-[#1b2433]">
    <td class="px-3 py-2.5">${badge}</td>
    <td class="px-3 py-2.5 font-medium text-slate-200">${name}</td>
    <td class="px-3 py-2.5 text-slate-400">${j.count}</td>
    <td class="px-3 py-2.5 text-xs text-slate-500">${esc(j.created_at || '')}</td>
    <td class="px-3 py-2.5"><div class="flex justify-end gap-1.5">
      <a href="${API.download(j.id)}" class="btn-ghost inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-semibold"><svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3"/></svg>CSV</a>
      <button data-id="${j.id}" data-name="${name}" class="job-del inline-flex items-center justify-center rounded-lg border border-[#232e3f] px-2 py-1.5 text-rose-400 transition hover:bg-rose-500/10 hover:border-rose-500/40" title="Delete"><svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg></button>
    </div></td></tr>`;
}
async function deleteJob(id, name) {
  if (!window.confirm(`Delete "${name}"? This cannot be undone.`)) return;
  try {
    const resp = await api(API.del, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id: Number(id) }) });
    const data = await resp.json();
    if (!resp.ok) throw new Error(data.error || 'Delete failed');
    toast('Deleted'); loadJobs();
  } catch (e) { toast(e.message, 'err'); }
}

/* ============================================================
   SETTINGS
   ============================================================ */
async function loadSettings() {
  try {
    const resp = await api(API.settings); const data = await resp.json(); const s = data.settings || {};
    $('setKey').placeholder = s.groq_api_key || 'gsk_...'; $('setKey').value = '';
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
    toast('Settings saved'); updateBadges(data.hasKey, data.db); loadSettings();
  } catch (e) { setStatus('setStatus', e.message, 'err'); toast(e.message, 'err'); }
}

/* ============================================================
   WIRE
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
  $('gRun').addEventListener('click', gRun);
  $('gExport').addEventListener('click', () => { if (gen.results.length) downloadCSV((($('gTopic').value.trim() || 'questions').replace(/[^a-z0-9]+/gi, '_')) + '.csv', buildCSV(gen.headers, gen.results)); });
  $('gClear').addEventListener('click', () => { gen.results = []; $('gResultCard').classList.add('hidden'); setStatus('gStatus', 'Cleared.', ''); });

  wireDrop('sDrop', 'sFile', sHandleFile);
  $('sRun').addEventListener('click', sRun);
  $('sExport').addEventListener('click', () => { if (sol.results.length) downloadCSV(((sol.source || 'solved').replace(/\.[^.]+$/i, '').replace(/[^a-z0-9]+/gi, '_')) + '_solved.csv', buildCSV(sol.headers, sol.results)); });
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

/* ---------- boot ---------- */
async function boot() {
  $('loginForm').addEventListener('submit', doLogin);
  const authed = await checkAuth();
  if (authed) showApp(); else showLogin();
}
boot();
