<?php
/**
 * GET /api/download.php?id=12
 * Streams a saved job as a CSV file, using the job's stored columns so the
 * exported structure exactly matches what was uploaded/generated.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

require_auth();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    json_response(400, ['error' => 'Missing job id.']);
}

$db = get_db();
if ($db === null) {
    json_response(503, ['error' => 'Database not connected.']);
}

try {
    $jStmt = $db->prepare('SELECT * FROM jobs WHERE id = :id');
    $jStmt->execute([':id' => $id]);
    $job = $jStmt->fetch();
    if (!$job) {
        json_response(404, ['error' => 'Job not found.']);
    }

    $columns = json_decode($job['columns_json'], true) ?: [];

    $rStmt = $db->prepare('SELECT data_json FROM job_rows WHERE job_id = :id ORDER BY id ASC');
    $rStmt->execute([':id' => $id]);

    $base = $job['type'] . '_' . ($job['topic'] ?: 'questions');
    $name = preg_replace('/[^a-z0-9]+/i', '_', $base) . '_' . $id . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');

    // UTF-8 BOM so Excel reads it correctly.
    echo "\xEF\xBB\xBF";

    $out = fopen('php://output', 'w');
    fputcsv($out, $columns);
    foreach ($rStmt->fetchAll() as $r) {
        $data = json_decode($r['data_json'], true) ?: [];
        $line = [];
        foreach ($columns as $c) {
            $line[] = $data[$c] ?? '';
        }
        fputcsv($out, $line);
    }
    fclose($out);
    exit;
} catch (Throwable $e) {
    json_response(500, ['error' => $e->getMessage()]);
}
