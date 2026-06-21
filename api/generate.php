<?php
/**
 * POST /api/generate.php
 * Body: { headers:[], samples:[], topic, count, model, extra, source }
 * Returns: { questions:[], job_id, saved }
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
$samples = isset($in['samples']) && is_array($in['samples']) ? $in['samples'] : [];
$topic   = trim((string) ($in['topic'] ?? ''));
$count   = max(1, min(MAX_ROWS, (int) ($in['count'] ?? 5)));
$model   = trim((string) ($in['model'] ?? ''));
$extra   = trim((string) ($in['extra'] ?? ''));
$source  = trim((string) ($in['source'] ?? ''));

if (empty($columns)) {
    json_response(400, ['error' => 'No CSV columns provided. Upload a sample CSV first.']);
}
if ($topic === '') {
    json_response(400, ['error' => 'Please enter a topic.']);
}

try {
    $questions = groq_generate($columns, array_slice($samples, 0, 8), $topic, $count, $extra, $model);
} catch (Throwable $e) {
    json_response(502, ['error' => $e->getMessage()]);
}

if (empty($questions)) {
    json_response(200, ['questions' => [], 'job_id' => null, 'saved' => false]);
}

$jobId = save_job('generate', $topic, $source, $columns, $model !== '' ? $model : (string) setting('groq_model'), $questions);

json_response(200, [
    'questions' => $questions,
    'job_id'    => $jobId,
    'saved'     => $jobId !== null,
]);
