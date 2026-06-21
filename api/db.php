<?php
/**
 * MySQL connection via PDO + lightweight schema bootstrap.
 *
 * get_db()        -> shared PDO instance or null if unreachable.
 * ensure_schema() -> creates tables if missing (best-effort).
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
        ensure_schema($pdo);
    } catch (Throwable $e) {
        $pdo = null;
    }

    return $pdo;
}

/**
 * Create the tables if they do not exist yet. Safe to call repeatedly.
 */
function ensure_schema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS jobs (
            id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            type          ENUM("generate","solve") NOT NULL,
            topic         VARCHAR(255) DEFAULT NULL,
            source_file   VARCHAR(255) DEFAULT NULL,
            columns_json  JSON NOT NULL,
            model         VARCHAR(120) DEFAULT NULL,
            row_count     INT UNSIGNED NOT NULL DEFAULT 0,
            created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_type (type),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS job_rows (
            id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            job_id     BIGINT UNSIGNED NOT NULL,
            data_json  JSON NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_job_rows_job FOREIGN KEY (job_id)
                REFERENCES jobs(id) ON DELETE CASCADE,
            INDEX idx_job (job_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

/**
 * Save a completed job and its rows. Returns the job id or null.
 *
 * @param string[]                       $columns
 * @param array<int,array<string,string>> $rows
 */
function save_job(string $type, ?string $topic, ?string $source, array $columns, string $model, array $rows): ?int
{
    $db = get_db();
    if ($db === null) {
        return null;
    }
    try {
        $db->beginTransaction();

        $stmt = $db->prepare(
            'INSERT INTO jobs (type, topic, source_file, columns_json, model, row_count)
             VALUES (:type, :topic, :source, :cols, :model, :cnt)'
        );
        $stmt->execute([
            ':type'   => $type,
            ':topic'  => $topic !== '' ? $topic : null,
            ':source' => $source !== '' ? $source : null,
            ':cols'   => json_encode($columns, JSON_UNESCAPED_UNICODE),
            ':model'  => $model,
            ':cnt'    => count($rows),
        ]);
        $jobId = (int) $db->lastInsertId();

        $rowStmt = $db->prepare('INSERT INTO job_rows (job_id, data_json) VALUES (:jid, :data)');
        foreach ($rows as $r) {
            $rowStmt->execute([
                ':jid'  => $jobId,
                ':data' => json_encode($r, JSON_UNESCAPED_UNICODE),
            ]);
        }

        $db->commit();
        return $jobId;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return null;
    }
}
