<?php
/**
 * Authentication — the lock for the whole panel.
 *
 * Used two ways:
 *   1. Included by protected endpoints, which then call require_auth().
 *   2. Hit directly as an endpoint:
 *        GET  /api/auth.php?action=check   -> { authed: bool, user }
 *        POST /api/auth.php  {username,password,action:"login"} -> { ok }
 *        GET/POST /api/auth.php?action=logout -> { ok }
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

// Harden the session cookie a little.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/** True if the current session is logged in. */
function is_authed(): bool
{
    return !empty($_SESSION['pallaw_auth']);
}

/** Guard a protected endpoint. Sends 401 and stops if not logged in. */
function require_auth(): void
{
    if (!is_authed()) {
        json_response(401, ['error' => 'Not authenticated', 'authed' => false]);
    }
}

/** Verify credentials using constant-time comparison. */
function verify_credentials(string $user, string $pass): bool
{
    $okUser = hash_equals(ADMIN_USERNAME, $user);
    $okPass = hash_equals(ADMIN_PASSWORD, $pass);
    return $okUser && $okPass;
}

/* ---- Direct endpoint behaviour (only when called as auth.php itself) ---- */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    $action = $_GET['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'];

    if ($action === 'check') {
        json_response(200, ['authed' => is_authed(), 'user' => is_authed() ? ADMIN_USERNAME : null]);
    }

    if ($action === 'logout') {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        json_response(200, ['ok' => true, 'authed' => false]);
    }

    if ($method === 'POST') {
        $in   = read_json_body();
        $user = trim((string) ($in['username'] ?? ''));
        $pass = (string) ($in['password'] ?? '');

        // Tiny brute-force speed bump.
        usleep(250000);

        if ($user === '' || $pass === '') {
            json_response(400, ['error' => 'Enter username and password.']);
        }
        if (!verify_credentials($user, $pass)) {
            json_response(401, ['error' => 'Invalid username or password.', 'authed' => false]);
        }

        session_regenerate_id(true);
        $_SESSION['pallaw_auth'] = true;
        $_SESSION['pallaw_user'] = ADMIN_USERNAME;
        json_response(200, ['ok' => true, 'authed' => true, 'user' => ADMIN_USERNAME]);
    }

    json_response(405, ['error' => 'Method not allowed']);
}
