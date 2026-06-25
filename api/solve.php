<?php
/**
 * POST /api/solve.php  — compute only (saving is done by save.php).
 * Takes uploaded question rows (any layout) and returns them in the canonical
 * schema with a step-by-step solution filled in.
 * Body: { rows:[], model, extra }
 * Returns: { rows:[], columns:[] }
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/groq.php';

require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(405, ['error' => 'Method not allowed. Use POST.']);
}

$in    = read_json_body();
$rows  = isset($in['rows']) && is_array($in['rows']) ? $in['rows'] : [];
$model = trim((string) ($in['model'] ?? ''));
$extra = trim((string) ($in['extra'] ?? ''));

if (empty($rows)) {
    json_response(400, ['error' => 'No question rows found in the uploaded file.']);
}
if (count($rows) > MAX_ROWS) {
    json_response(400, ['error' => 'Too many rows in one request. Keep each batch under ' . MAX_ROWS . '.']);
}

$columns = canonical_columns();
$usedModel = '';

try {
    $filled = groq_solve($columns, $rows, $extra, $model, $usedModel);
} catch (GroqRateLimitException $e) {
    json_response(429, [
        'error'      => $e->getMessage(),
        'retryable'  => $e->retryable,
        'retryAfter' => $e->retryAfter,
    ]);
} catch (Throwable $e) {
    $status = $e->getCode() === 429 ? 429 : 502;
    json_response($status, ['error' => $e->getMessage()]);
}

json_response(200, [
    'rows'    => $filled,
    'columns' => $columns,
    'model'   => $usedModel,
]);
