<?php
/**
 * POST /api/save.php  — persist a completed batch as a single job.
 * Body: { type:"generate"|"solve", topic, source, columns:[], rows:[] }
 * Returns: { job_id, saved }
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(405, ['error' => 'Method not allowed. Use POST.']);
}

$in      = read_json_body();
$type    = (string) ($in['type'] ?? '');
$topic   = trim((string) ($in['topic'] ?? ''));
$source  = trim((string) ($in['source'] ?? ''));
$columns = isset($in['columns']) && is_array($in['columns']) ? array_values($in['columns']) : [];
$rows    = isset($in['rows']) && is_array($in['rows']) ? $in['rows'] : [];

if (!in_array($type, ['generate', 'solve'], true)) {
    json_response(400, ['error' => 'Invalid job type.']);
}
if (empty($columns) || empty($rows)) {
    json_response(400, ['error' => 'Nothing to save.']);
}

// Normalize rows to the given columns.
$clean = [];
foreach ($rows as $r) {
    if (!is_array($r)) {
        continue;
    }
    $row = [];
    foreach ($columns as $c) {
        $row[$c] = isset($r[$c]) ? (is_scalar($r[$c]) ? (string) $r[$c] : json_encode($r[$c])) : '';
    }
    $clean[] = $row;
}

$model = (string) setting('groq_model');
$jobId = save_job($type, $topic, $source, $columns, $model, $clean);

json_response(200, ['job_id' => $jobId, 'saved' => $jobId !== null]);
