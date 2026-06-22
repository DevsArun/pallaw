<?php
/**
 * POST /api/generate.php
 * Body: { headers:[], samples:[], topic, count, model, extra, source }
 * Returns: { questions:[], job_id, saved }
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/groq.php';

require_auth();

/* ---- column helpers (ensure a solution column + fresh numbering) ---- */
function pallaw_norm(string $s): string
{
    return preg_replace('/[\s._\-#]/', '', strtolower($s));
}
function pallaw_has_explanation(array $cols): bool
{
    foreach ($cols as $c) {
        $n = pallaw_norm((string) $c);
        foreach (['explanation', 'solution', 'working', 'step', 'reason', 'soln'] as $h) {
            if (strpos($n, $h) !== false) {
                return true;
            }
        }
    }
    return false;
}
function pallaw_id_column(array $cols): ?string
{
    $ids = ['pk', 'id', 'sno', 'srno', 'serial', 'serialno', 'sl', 'slno', 'no', 'number', 'qno', 'rollno', 'roll', 'index', 'idx', 'sr'];
    foreach ($cols as $c) {
        if (in_array(pallaw_norm((string) $c), $ids, true)) {
            return $c;
        }
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(405, ['error' => 'Method not allowed. Use POST.']);
}

$in      = read_json_body();
$columns = isset($in['headers']) && is_array($in['headers']) ? array_values($in['headers']) : [];
$samples = isset($in['samples']) && is_array($in['samples']) ? $in['samples'] : [];
$topic   = trim((string) ($in['topic'] ?? ''));
$count   = max(1, min(MAX_ROWS, (int) ($in['count'] ?? 5)));
$model   = trim((string) ($in['model'] ?? ''));
$extra   = trim((string) ($in['extra'] ?? ''));
$source  = trim((string) ($in['source'] ?? ''));

if (empty($columns)) {
    json_response(400, ['error' => 'No CSV columns provided. Upload a sample file first.']);
}
// Topic is OPTIONAL — when empty the AI infers it from the samples.
if ($topic === '' && empty($samples)) {
    json_response(400, ['error' => 'Add a topic, or upload samples so the AI can infer one.']);
}

// Always make sure the output has a solution/explanation column.
if (!pallaw_has_explanation($columns)) {
    $columns[] = 'Explanation';
}

try {
    $questions = groq_generate($columns, array_slice($samples, 0, 8), $topic, $count, $extra, $model);
} catch (Throwable $e) {
    json_response(502, ['error' => $e->getMessage()]);
}

if (empty($questions)) {
    json_response(200, ['questions' => [], 'columns' => $columns, 'job_id' => null, 'saved' => false]);
}

// Fresh, sequential numbering (1..N) for any id/serial column.
$idCol = pallaw_id_column($columns);
if ($idCol !== null) {
    $n = 1;
    foreach ($questions as &$q) {
        $q[$idCol] = (string) $n++;
    }
    unset($q);
}

$jobId = save_job('generate', $topic, $source, $columns, $model !== '' ? $model : (string) setting('groq_model'), $questions);

json_response(200, [
    'questions' => $questions,
    'columns'   => $columns,
    'job_id'    => $jobId,
    'saved'     => $jobId !== null,
]);
