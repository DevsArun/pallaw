<?php
/** GET /api/health.php — quick status for the UI header. */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

json_response(200, [
    'ok'     => true,
    'hasKey' => (string) setting('groq_api_key') !== '',
    'model'  => (string) setting('groq_model'),
    'db'     => get_db() !== null,
]);
