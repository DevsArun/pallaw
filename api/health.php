<?php
/** GET /api/health.php — status for the UI (requires auth). */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

require_auth();

json_response(200, [
    'ok'     => true,
    'hasKey' => (string) setting('groq_api_key') !== '',
    'model'  => (string) setting('groq_model'),
    'db'     => get_db() !== null,
]);
