<?php
/**
 * POST /api/solve.php
 * Add explanation/solution (and/or correct answer) to existing question rows.
 * Body: { headers:[], rows:[], targets:[], model, extra, source }
 * Returns: { rows:[], job_id, saved }
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/groq.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(405, ['error' => 'Method not allowed. Use POST.']);
}

$in      = read_json_body();
$columns = isset($in['headers']) && is_array($in['headers']) ? array_values($in['headers']) : [];
$rows    = isset($in['rows']) && is_array($in['rows']) ? $in['rows'] : [];
$targets = isset($in['targets']) && is_array($in['targets']) ? array_values($in['targets']) : [];
$model   = trim((string) ($in['model'] ?? ''));
$extra   = trim((string) ($in['extra'] ?? ''));
$source  = trim((string) ($in['source'] ?? ''));

if (empty($columns)) {
    json_response(400, ['error' => 'No CSV columns provided. Upload a CSV first.']);
}
if (empty($rows)) {
    json_response(400, ['error' => 'No question rows found in the uploaded file.']);
}
// Only keep targets that actually exist as columns.
$targets = array_values(array_intersect($targets, $columns));
if (empty($targets)) {
    json_response(400, ['error' => 'Pick at least one column to fill (e.g. explanation).']);
}
if (count($rows) > MAX_ROWS) {
    json_response(400, ['error' => 'Too many rows. Please keep it under ' . MAX_ROWS . ' per file for now.']);
}

try {
    $filled = groq_solve($columns, $rows, $targets, $extra, $model);
} catch (Throwable $e) {
    json_response(502, ['error' => $e->getMessage()]);
}

$jobId = save_job('solve', null, $source, $columns, $model !== '' ? $model : (string) setting('groq_model'), $filled);

json_response(200, [
    'rows'   => $filled,
    'job_id' => $jobId,
    'saved'  => $jobId !== null,
]);
