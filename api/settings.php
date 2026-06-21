<?php
/**
 * GET  /api/settings.php  -> current settings (API key + DB pass masked)
 * POST /api/settings.php  -> save settings, then report key/DB status
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

function mask(string $v): string
{
    if ($v === '') {
        return '';
    }
    $len = strlen($v);
    if ($len <= 6) {
        return str_repeat('•', $len);
    }
    return substr($v, 0, 4) . str_repeat('•', max(4, $len - 8)) . substr($v, -4);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = read_json_body();
    // Don't overwrite secrets with masked/empty values coming back from the UI.
    foreach (['groq_api_key', 'db_pass'] as $secret) {
        if (isset($in[$secret]) && (trim($in[$secret]) === '' || str_contains($in[$secret], '•'))) {
            unset($in[$secret]);
        }
    }

    if (!save_settings($in)) {
        json_response(500, ['error' => 'Could not write settings file. Check write permissions on the api/ folder.']);
    }
}

// Reset the cached PDO by reloading in a fresh process is not possible mid-request,
// so just attempt a connection with current settings for status reporting.
$dbOk = false;
try {
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', setting('db_host'), setting('db_port'), setting('db_name'));
    $test = new PDO($dsn, setting('db_user'), setting('db_pass'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    ensure_schema($test);
    $dbOk = true;
} catch (Throwable $e) {
    $dbOk = false;
}

json_response(200, [
    'settings' => [
        'groq_api_key' => mask((string) setting('groq_api_key')),
        'groq_model'   => (string) setting('groq_model'),
        'db_host'      => (string) setting('db_host'),
        'db_port'      => (string) setting('db_port'),
        'db_name'      => (string) setting('db_name'),
        'db_user'      => (string) setting('db_user'),
        'db_pass'      => mask((string) setting('db_pass')),
    ],
    'hasKey' => (string) setting('groq_api_key') !== '',
    'db'     => $dbOk,
]);
