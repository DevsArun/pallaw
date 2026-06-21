<?php
/**
 * MySQL connection via PDO.
 *
 * get_db() returns a shared PDO instance, or null if the database is
 * unreachable. The app still works for generation/export without a DB;
 * the DB is only used to persist history.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function get_db(): ?PDO
{
    static $pdo = null;
    static $tried = false;

    if ($tried) {
        return $pdo;
    }
    $tried = true;

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        DB_HOST,
        DB_PORT,
        DB_NAME
    );

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (Throwable $e) {
        $pdo = null;
    }

    return $pdo;
}
