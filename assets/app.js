/* ============================================================
   Pallaw — frontend logic
   - CSV parse + preview
   - call PHP backend (/api/generate.php)
   - render results + export CSV
   - load history from MySQL (/api/history.php)
   ============================================================ */

const API = {
  health: 'api/health.php',
  generate: 'api/generate.php',
  history: 'api/history.php',
};

// ---------- State ----------
let headers = [];
let sampleRows = [];
let generated = [];
let sourceName = '';

// ---------- DOM helpers ----------
const $ = (id) => document.getElementById(id);
const esc = (s) =>
  String(s ?? '').replace(/[&<>]/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[m]));

function setStatus(msg, type = '') {
  const el = $('status');
  const colors = {
    err: 'text-rose-600',
    ok: 'text-emerald-600',
    load: 'text-violet-600',
    '': 'text-ink-500',
  };
  el.className = 'min-h-[18px] text-sm flex items-center gap-2 ' + (colors[type] || colors['']);
  el.innerHTML = type === 'load' ? `<span class="spinner"></span><span>${esc(msg)}</span>` : esc(msg);
}

// ---------- CSV parser (handles quotes, commas, newlines) ----------
function parseCSV(text) {
  const rows = [];
  let row = [];
  let field = '';
  let inQuotes = false;
  text = text.replace(/\r\n/g, '\n').replace(/\r/g, '\n');

  for (let i = 0; i < text.length; i++) {
    const c = text[i];
    if (inQuotes) {
      if (c === '"') {
        if (text[i + 1] === '"') {
          field += '"';
          i++;
        } else inQuotes = false;
      } else field += c;
    } else {
      if (c === '"') inQuotes = true;
      else if (c === ',') {
        row.push(field);
        field = '';
      } else if (c === '\n') {
        row.push(field);
        rows.push(row);
        row = [];
        field = '';
      } else field += c;
    }
  }
  if (field.length > 0 || row.length > 0) {
    row.push(field);
    rows.push(row);
  }
  return rows.filter((r) => r.some((v) => String(v).trim() !== ''));
}

function rowsToObjects(rows) {
  if (!rows.length) return { hdrs: [], objs: [] };
  const hdrs = rows[0].map((h) => h.trim());
  const objs = rows.slice(1).map((r) => {
    const o = {};
    hdrs.forEach((h, i) => (o[h] = (r[i] ?? '').trim()));
    return o;
  });
  return { hdrs, objs };
}

// ---------- CSV builder ----------
function escapeCSV(v) {
  const s = String(v ?? '');
  return /[",\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
}
function buildCSV(cols, rows) {
  const lines = [cols.map(escapeCSV).join(',')];
  for (const r of rows) lines.push(cols.map((c) => escapeCSV(r[c])).join(','));
  return lines.join('\n');
}
function downloadCSV(filename, csv) {
  const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  URL.revokeObjectURL(url);
}

// ---------- Table render ----------
function renderTable(tableEl, cols, rows, limit) {
  const data = limit ? rows.slice(0, limit) : rows;
  let html =
    '<thead><tr class="bg-ink-100/70 text-left text-xs uppercase tracking-wide text-ink-500">' +
    cols.map((c) => `<th class="whitespace-nowrap px-3 py-2.5 font-semibold">${esc(c)}</th>`).join('') +
    '</tr></thead><tbody>';
  for (const r of data) {
    html +=
      '<tr class="border-t border-black/5 align-top">' +
      cols
        .map(
          (c) =>
            `<td class="px-3 py-2.5 text-ink-700 max-w-[360px]">${esc(r[c])}</td>`
        )
        .join('') +
      '</tr>';
  }
  html += '</tbody>';
  tableEl.innerHTML = html;
}

// ---------- File handling ----------
function handleFile(file) {
  if (!file) return;
  sourceName = file.name;
  const reader = new FileReader();
  reader.onload = (e) => {
    const { hdrs, objs } = rowsToObjects(parseCSV(e.target.result));
    if (!hdrs.length) {
      setStatus('That CSV looks empty or could not be read.', 'err');
      return;
    }
    headers = hdrs;
    sampleRows = objs;

    $('fileLabel').innerHTML = `<span class="font-semibold text-ink-900">${esc(file.name)}</span> · ${objs.length} rows`;
    $('rowCount').textContent = `${objs.length} sample rows`;
    $('headerChips').innerHTML = hdrs
      .map(
        (h) =>
          `<span class="rounded-full border border-black/5 bg-ink-100 px-2.5 py-1 text-xs font-medium text-ink-700">${esc(h)}</span>`
      )
      .join('');
    renderTable($('previewTable'), hdrs, objs, 5);
    $('previewWrap').classList.remove('hidden');
    refreshGenerateBtn();
    setStatus('');
  };
  reader.readAsText(file);
}

function refreshGenerateBtn() {
  $('generateBtn').disabled = !(headers.length && $('topic').value.trim());
}

// ---------- Generate ----------
async function generate() {
  const topic = $('topic').value.trim();
  const count = parseInt($('count').value, 10) || 5;
  const model = $('model').value;
  const extra = $('extra').value.trim();

  if (!headers.length) return setStatus('Upload a sample CSV first.', 'err');
  if (!topic) return setStatus('Please enter a topic.', 'err');

  $('generateBtn').disabled = true;
  setStatus(`Generating ${count} questions with AI…`, 'load');

  try {
    const resp = await fetch(API.generate, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        headers,
        samples: sampleRows.slice(0, 8),
        topic,
        count,
        model,
        extra,
        source: sourceName,
        save: true,
      }),
    });
    const data = await resp.json();
    if (!resp.ok) throw new Error(data.error || 'Generation failed');

    const qs = data.questions || [];
    if (!qs.length) throw new Error('No questions were generated. Try again.');

    generated = generated.concat(qs);
    renderResults();

    const savedNote = data.saved ? ' · saved to history' : '';
    setStatus(`✓ ${qs.length} new questions generated. Total: ${generated.length}${savedNote}.`, 'ok');
    if (data.saved) loadHistory();
  } catch (e) {
    setStatus('Error: ' + e.message, 'err');
  } finally {
    refreshGenerateBtn();
  }
}

function renderResults() {
  $('resultsCard').classList.remove('hidden');
  $('resultCount').textContent = `${generated.length} questions`;
  renderTable($('resultsTable'), headers, generated);
  $('resultsCard').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

// ---------- History ----------
async function loadHistory() {
  try {
    const resp = await fetch(API.history);
    const data = await resp.json();
    const list = $('historyList');
    const empty = $('historyEmpty');

    if (!data.db) {
      empty.textContent = 'No database connected — history is disabled. Generation & export still work.';
      empty.classList.remove('hidden');
      list.innerHTML = '';
      return;
    }
    const sets = data.sets || [];
    if (!sets.length) {
      empty.textContent = 'No saved sets yet. Generated batches will appear here.';
      empty.classList.remove('hidden');
      list.innerHTML = '';
      return;
    }
    empty.classList.add('hidden');
    list.innerHTML = sets
      .map(
        (s) => `
        <li>
          <button data-id="${s.id}" class="history-item w-full rounded-xl border border-black/5 bg-ink-100/40 px-3.5 py-3 text-left transition hover:border-violet-300 hover:bg-violet-50/40">
            <div class="flex items-center justify-between gap-2">
              <span class="truncate text-sm font-semibold text-ink-900">${esc(s.topic)}</span>
              <span class="shrink-0 rounded-full bg-white px-2 py-0.5 text-[11px] font-medium text-ink-500">${s.count} Q</span>
            </div>
            <div class="mt-0.5 truncate text-xs text-ink-400">${esc(s.model || '')} · ${esc(s.created_at || '')}</div>
          </button>
        </li>`
      )
      .join('');

    list.querySelectorAll('.history-item').forEach((btn) =>
      btn.addEventListener('click', () => loadSet(btn.dataset.id))
    );
  } catch {
    /* history is best-effort */
  }
}

async function loadSet(id) {
  setStatus('Loading saved set…', 'load');
  try {
    const resp = await fetch(`${API.history}?id=${encodeURIComponent(id)}`);
    const data = await resp.json();
    if (!resp.ok) throw new Error(data.error || 'Could not load set');

    headers = data.set.columns || [];
    generated = data.questions || [];
    $('topic').value = data.set.topic || '';
    renderResults();
    setStatus(`Loaded "${data.set.topic}" — ${generated.length} questions.`, 'ok');
  } catch (e) {
    setStatus('Error: ' + e.message, 'err');
  }
}

// ---------- Init ----------
function init() {
  fetch(API.health)
    .then((r) => r.json())
    .then((h) => {
      const key = $('keyStatus');
      if (h.hasKey) {
        key.innerHTML = `<span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>Groq ready`;
        key.classList.add('text-emerald-700');
      } else {
        key.innerHTML = `<span class="h-1.5 w-1.5 rounded-full bg-rose-500"></span>Set GROQ_API_KEY`;
        key.classList.add('text-rose-600');
      }
      const db = $('dbStatus');
      db.classList.remove('hidden');
      db.innerHTML = h.db
        ? `<span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>MySQL connected`
        : `<span class="h-1.5 w-1.5 rounded-full bg-ink-300"></span>No DB`;
    })
    .catch(() => {});

  loadHistory();

  const fileInput = $('fileInput');
  const dropZone = $('dropZone');
  fileInput.addEventListener('change', (e) => handleFile(e.target.files[0]));

  ['dragover', 'dragenter'].forEach((ev) =>
    dropZone.addEventListener(ev, (e) => {
      e.preventDefault();
      dropZone.classList.add('border-violet-400', 'bg-violet-50/40');
    })
  );
  ['dragleave', 'drop'].forEach((ev) =>
    dropZone.addEventListener(ev, (e) => {
      e.preventDefault();
      dropZone.classList.remove('border-violet-400', 'bg-violet-50/40');
    })
  );
  dropZone.addEventListener('drop', (e) => {
    const f = e.dataTransfer.files[0];
    if (f) handleFile(f);
  });

  $('topic').addEventListener('input', refreshGenerateBtn);
  $('generateBtn').addEventListener('click', generate);
  $('refreshHistory').addEventListener('click', loadHistory);

  $('exportBtn').addEventListener('click', () => {
    if (!generated.length) return;
    const csv = buildCSV(headers, generated);
    const topic = ($('topic').value.trim() || 'questions').replace(/[^a-z0-9]+/gi, '_');
    downloadCSV(`${topic}_questions.csv`, csv);
  });

  $('clearBtn').addEventListener('click', () => {
    generated = [];
    $('resultsCard').classList.add('hidden');
    setStatus('Results cleared.', '');
  });
}

init();
