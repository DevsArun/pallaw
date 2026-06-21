<?php
/**
 * GET /api/history.php             -> list recent question sets
 * GET /api/history.php?id=12       -> full set with all questions
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$db = get_db();
if ($db === null) {
    json_response(200, ['db' => false, 'sets' => []]);
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

try {
    if ($id > 0) {
        $setStmt = $db->prepare('SELECT * FROM question_sets WHERE id = :id');
        $setStmt->execute([':id' => $id]);
        $set = $setStmt->fetch();

        if (!$set) {
            json_response(404, ['error' => 'Set not found']);
        }

        $qStmt = $db->prepare('SELECT data_json FROM questions WHERE set_id = :id ORDER BY id ASC');
        $qStmt->execute([':id' => $id]);

        $questions = [];
        foreach ($qStmt->fetchAll() as $row) {
            $questions[] = json_decode($row['data_json'], true);
        }

        json_response(200, [
            'db'        => true,
            'set'       => [
                'id'        => (int) $set['id'],
                'topic'     => $set['topic'],
                'columns'   => json_decode($set['columns_json'], true),
                'model'     => $set['model'],
                'count'     => (int) $set['question_count'],
                'created_at'=> $set['created_at'],
            ],
            'questions' => $questions,
        ]);
    }

    $rows = $db->query(
        'SELECT id, topic, model, question_count, created_at
         FROM question_sets ORDER BY id DESC LIMIT 50'
    )->fetchAll();

    $sets = array_map(fn ($r) => [
        'id'         => (int) $r['id'],
        'topic'      => $r['topic'],
        'model'      => $r['model'],
        'count'      => (int) $r['question_count'],
        'created_at' => $r['created_at'],
    ], $rows);

    json_response(200, ['db' => true, 'sets' => $sets]);
} catch (Throwable $e) {
    json_response(500, ['error' => $e->getMessage()]);
}
