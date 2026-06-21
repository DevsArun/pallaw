<?php
/**
 * Health/config check used by the UI to warn about missing setup.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$db = get_db();

json_response(200, [
    'ok'      => true,
    'hasKey'  => GROQ_API_KEY !== '',
    'model'   => GROQ_DEFAULT_MODEL,
    'db'      => $db !== null,
]);
