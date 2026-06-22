<?php
/**
 * POST /api/generate.php  — compute only (saving is done by save.php).
 * Body: { samples:[], topic, count, model, extra, avoid:[] }
 * Returns: { questions:[], columns:[] }  (columns = canonical sample_questions.csv schema)
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/groq.php';

require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(405, ['error' => 'Method not allowed. Use POST.']);
}

$in      = read_json_body();
$samples = isset($in['samples']) && is_array($in['samples']) ? $in['samples'] : [];
$topic   = trim((string) ($in['topic'] ?? ''));
$count   = max(1, min(MAX_ROWS, (int) ($in['count'] ?? 5)));
$model   = trim((string) ($in['model'] ?? ''));
$extra   = trim((string) ($in['extra'] ?? ''));
$avoid   = isset($in['avoid']) && is_array($in['avoid']) ? array_values(array_filter(array_map('strval', $in['avoid']))) : [];

if ($topic === '' && empty($samples)) {
    json_response(400, ['error' => 'Add a topic, or upload samples so the AI can infer one.']);
}

$columns = canonical_columns();

try {
    $questions = groq_generate($columns, $samples, $topic, $count, $extra, $model, $avoid);
} catch (Throwable $e) {
    $status = $e->getCode() === 429 ? 429 : 502;
    json_response($status, ['error' => $e->getMessage()]);
}

json_response(200, [
    'questions' => $questions,
    'columns'   => $columns,
]);
