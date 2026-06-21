<?php
/**
 * GET /api/jobs.php            -> list recent jobs (+ counts) for the dashboard
 * GET /api/jobs.php?id=12      -> a single job with all its rows
 * GET /api/jobs.php?type=solve -> filter by type
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$db = get_db();
if ($db === null) {
    json_response(200, ['db' => false, 'jobs' => [], 'stats' => null]);
}

$id   = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$type = isset($_GET['type']) ? (string) $_GET['type'] : '';

try {
    if ($id > 0) {
        $jStmt = $db->prepare('SELECT * FROM jobs WHERE id = :id');
        $jStmt->execute([':id' => $id]);
        $job = $jStmt->fetch();
        if (!$job) {
            json_response(404, ['error' => 'Job not found']);
        }
        $rStmt = $db->prepare('SELECT data_json FROM job_rows WHERE job_id = :id ORDER BY id ASC');
        $rStmt->execute([':id' => $id]);
        $rows = array_map(fn ($r) => json_decode($r['data_json'], true), $rStmt->fetchAll());

        json_response(200, [
            'db'   => true,
            'job'  => [
                'id'         => (int) $job['id'],
                'type'       => $job['type'],
                'topic'      => $job['topic'],
                'columns'    => json_decode($job['columns_json'], true),
                'model'      => $job['model'],
                'count'      => (int) $job['row_count'],
                'source'     => $job['source_file'],
                'created_at' => $job['created_at'],
            ],
            'rows' => $rows,
        ]);
    }

    $sql = 'SELECT id, type, topic, source_file, model, row_count, created_at FROM jobs';
    $params = [];
    if ($type === 'generate' || $type === 'solve') {
        $sql .= ' WHERE type = :t';
        $params[':t'] = $type;
    }
    $sql .= ' ORDER BY id DESC LIMIT 100';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    $jobs = array_map(fn ($r) => [
        'id'         => (int) $r['id'],
        'type'       => $r['type'],
        'topic'      => $r['topic'],
        'source'     => $r['source_file'],
        'model'      => $r['model'],
        'count'      => (int) $r['row_count'],
        'created_at' => $r['created_at'],
    ], $stmt->fetchAll());

    // Dashboard stats
    $stats = $db->query(
        'SELECT
            COUNT(*) AS jobs,
            COALESCE(SUM(row_count),0) AS questions,
            COALESCE(SUM(type="generate"),0) AS generated_jobs,
            COALESCE(SUM(type="solve"),0) AS solved_jobs,
            COALESCE(SUM(CASE WHEN type="generate" THEN row_count ELSE 0 END),0) AS generated_questions,
            COALESCE(SUM(CASE WHEN type="solve" THEN row_count ELSE 0 END),0) AS solved_questions
         FROM jobs'
    )->fetch();

    json_response(200, [
        'db'    => true,
        'jobs'  => $jobs,
        'stats' => [
            'jobs'                => (int) $stats['jobs'],
            'questions'           => (int) $stats['questions'],
            'generated_jobs'      => (int) $stats['generated_jobs'],
            'solved_jobs'         => (int) $stats['solved_jobs'],
            'generated_questions' => (int) $stats['generated_questions'],
            'solved_questions'    => (int) $stats['solved_questions'],
        ],
    ]);
} catch (Throwable $e) {
    json_response(500, ['error' => $e->getMessage()]);
}
