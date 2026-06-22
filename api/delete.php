<?php
/**
 * POST /api/delete.php  — delete a saved batch (and its rows, via cascade).
 * Body: { id }
 * Returns: { ok }
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(405, ['error' => 'Method not allowed. Use POST.']);
}

$in = read_json_body();
$id = (int) ($in['id'] ?? 0);
if ($id <= 0) {
    json_response(400, ['error' => 'Missing job id.']);
}

$db = get_db();
if ($db === null) {
    json_response(503, ['error' => 'Database not connected.']);
}

try {
    $stmt = $db->prepare('DELETE FROM jobs WHERE id = :id');
    $stmt->execute([':id' => $id]);
    json_response(200, ['ok' => true, 'deleted' => $stmt->rowCount()]);
} catch (Throwable $e) {
    json_response(500, ['error' => $e->getMessage()]);
}
