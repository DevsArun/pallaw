<?php
/**
 * GET  /api/settings.php  -> current Groq settings (key masked)
 * POST /api/settings.php  -> save Groq key/model, report status
 *
 * Database is configured in api/config.php, NOT here.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

require_auth();

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
    // Never overwrite the key with a masked/blank value echoed back by the UI.
    if (isset($in['groq_api_key']) && (trim($in['groq_api_key']) === '' || str_contains($in['groq_api_key'], '•'))) {
        unset($in['groq_api_key']);
    }
    if (!save_settings($in)) {
        json_response(500, ['error' => 'Could not write settings file. Check write permissions on the api/ folder.']);
    }
}

json_response(200, [
    'settings' => [
        'groq_api_key' => mask((string) setting('groq_api_key')),
        'groq_model'   => (string) setting('groq_model'),
    ],
    'hasKey' => (string) setting('groq_api_key') !== '',
    'db'     => get_db() !== null,
]);
