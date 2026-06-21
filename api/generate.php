<?php
/**
 * POST /api/generate.php
 *
 * Body JSON:
 *   {
 *     "headers": ["id","question","answer"],
 *     "samples": [ { ... }, ... ],
 *     "topic":   "Quadratic Equations",
 *     "count":   5,
 *     "model":   "llama-3.3-70b-versatile",
 *     "extra":   "Class 10 level",
 *     "source":  "sample.csv",
 *     "save":    true
 *   }
 *
 * Returns: { "questions": [...], "set_id": 12|null, "saved": true|false }
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/groq.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(405, ['error' => 'Method not allowed. Use POST.']);
}

$input   = read_json_body();
$headers = isset($input['headers']) && is_array($input['headers']) ? array_values($input['headers']) : [];
$samples = isset($input['samples']) && is_array($input['samples']) ? $input['samples'] : [];
$topic   = trim((string) ($input['topic'] ?? ''));
$count   = (int) ($input['count'] ?? 5);
$model   = trim((string) ($input['model'] ?? ''));
$extra   = trim((string) ($input['extra'] ?? ''));
$source  = trim((string) ($input['source'] ?? ''));
$save    = (bool) ($input['save'] ?? true);

$count = max(1, min(MAX_QUESTIONS, $count));

if (empty($headers)) {
    json_response(400, ['error' => 'No CSV columns provided. Upload a sample CSV first.']);
}
if ($topic === '') {
    json_response(400, ['error' => 'Please enter a topic.']);
}

// Only send a handful of samples for style reference.
$samples = array_slice($samples, 0, 8);

try {
    $questions = groq_generate($headers, $samples, $topic, $count, $extra, $model);
} catch (Throwable $e) {
    json_response(502, ['error' => $e->getMessage()]);
}

if (empty($questions)) {
    json_response(200, ['questions' => [], 'set_id' => null, 'saved' => false]);
}

$setId = null;
$saved = false;

if ($save) {
    $db = get_db();
    if ($db !== null) {
        try {
            $db->beginTransaction();

            $stmt = $db->prepare(
                'INSERT INTO question_sets (topic, columns_json, model, source_file, question_count)
                 VALUES (:topic, :cols, :model, :source, :cnt)'
            );
            $stmt->execute([
                ':topic'  => $topic,
                ':cols'   => json_encode($headers, JSON_UNESCAPED_UNICODE),
                ':model'  => $model !== '' ? $model : GROQ_DEFAULT_MODEL,
                ':source' => $source !== '' ? $source : null,
                ':cnt'    => count($questions),
            ]);
            $setId = (int) $db->lastInsertId();

            $qStmt = $db->prepare('INSERT INTO questions (set_id, data_json) VALUES (:sid, :data)');
            foreach ($questions as $q) {
                $qStmt->execute([
                    ':sid'  => $setId,
                    ':data' => json_encode($q, JSON_UNESCAPED_UNICODE),
                ]);
            }

            $db->commit();
            $saved = true;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            // Saving is best-effort; generation still succeeds.
            $setId = null;
            $saved = false;
        }
    }
}

json_response(200, [
    'questions' => $questions,
    'set_id'    => $setId,
    'saved'     => $saved,
]);
