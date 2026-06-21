<?php
/**
 * Central configuration.
 *
 * For local dev you can either edit the defaults below or, preferably,
 * set environment variables (e.g. in your Apache/Nginx vhost or a .env
 * loaded by your process manager).
 */

declare(strict_types=1);

/* ---------------- Database (MySQL) ---------------- */
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'pallaw');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');

/* ---------------- Groq API ---------------- */
define('GROQ_API_KEY', getenv('GROQ_API_KEY') ?: '');
define('GROQ_URL', 'https://api.groq.com/openai/v1/chat/completions');
define('GROQ_DEFAULT_MODEL', getenv('GROQ_MODEL') ?: 'llama-3.3-70b-versatile');

/* ---------------- App ---------------- */
// Max questions allowed per single request.
define('MAX_QUESTIONS', 50);

/**
 * Send a JSON response and stop.
 */
function json_response(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Read and decode a JSON request body.
 */
function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}
