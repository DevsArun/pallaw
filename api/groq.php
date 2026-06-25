<?php
/**
 * Groq API integration.
 *
 *  - groq_generate(): create NEW questions matching a sample's columns.
 *  - groq_solve():     fill explanation/solution (and answer) for EXISTING rows.
 *
 * Both return rows keyed strictly by the provided columns, so the CSV
 * structure the user uploaded is always preserved on export.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * Rate-limit failure that carries enough context for the caller to decide
 * whether waiting is worthwhile.
 *
 *  - $retryAfter : suggested wait in seconds (0 if unknown)
 *  - $retryable  : false when the limit is a per-DAY quota (waiting a minute
 *                  cannot fix it) — the UI should stop instead of looping.
 */
class GroqRateLimitException extends RuntimeException
{
    public float $retryAfter;
    public bool  $retryable;

    public function __construct(string $message, float $retryAfter = 0.0, bool $retryable = true)
    {
        parent::__construct($message, 429);
        $this->retryAfter = $retryAfter;
        $this->retryable  = $retryable;
    }
}

/** Try hard to extract a JSON object/array from raw model text. */
function extract_json(string $content): ?array
{
    $content = trim($content);
    if ($content === '') {
        return null;
    }

    // 1) Straight decode.
    $p = json_decode($content, true);
    if (is_array($p)) {
        return $p;
    }

    // 2) Strip ```json ... ``` / ``` ... ``` fences.
    if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $content, $m)) {
        $p = json_decode(trim($m[1]), true);
        if (is_array($p)) {
            return $p;
        }
    }

    // 3) Grab the outermost { ... } or [ ... ] block.
    foreach ([['{', '}'], ['[', ']']] as $pair) {
        $start = strpos($content, $pair[0]);
        $end   = strrpos($content, $pair[1]);
        if ($start !== false && $end !== false && $end > $start) {
            $candidate = substr($content, $start, $end - $start + 1);
            $p = json_decode($candidate, true);
            if (is_array($p)) {
                return $p;
            }
        }
    }

    return null;
}

/** Perform one raw Groq request. Returns [httpCode, decodedBody|null, rawString, curlError]. */
function groq_request(array $body, string $key): array
{
    $ch = curl_init(GROQ_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $key,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT        => 150,
    ]);
    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    $decoded = $response === false ? null : json_decode($response, true);
    return [$httpCode, is_array($decoded) ? $decoded : null, (string) $response, $curlErr];
}

/** Is this response a rate-limit (TPM/RPM) rejection? */
function groq_is_rate_limited(int $code, ?array $body): bool
{
    if ($code === 429) {
        return true;
    }
    return ($body['error']['code'] ?? '') === 'rate_limit_exceeded';
}

/**
 * Pull the suggested wait (seconds) out of a rate-limit message. Handles the
 * various formats Groq uses: "2.5s", "49s", "7m30s", "1h2m3.45s".
 */
function groq_retry_after(?array $body): float
{
    $msg = $body['error']['message'] ?? '';
    if (!is_string($msg) || !preg_match('/try again in\s+([0-9hms.\s]+)/i', $msg, $m)) {
        return 0.0;
    }
    $t = strtolower(trim($m[1]));
    $seconds = 0.0; $matched = false;
    if (preg_match('/([\d.]+)\s*h/', $t, $h)) { $seconds += (float) $h[1] * 3600; $matched = true; }
    if (preg_match('/([\d.]+)\s*m/', $t, $mm)) { $seconds += (float) $mm[1] * 60; $matched = true; }
    if (preg_match('/([\d.]+)\s*s/', $t, $s)) { $seconds += (float) $s[1]; $matched = true; }
    if (!$matched && preg_match('/^[\d.]+$/', $t)) { $seconds = (float) $t; $matched = true; }
    return $matched ? $seconds : 0.0;
}

/**
 * Is this a per-DAY quota (TPD/RPD)? Those cannot be cleared by waiting a
 * minute, so the UI must stop instead of retrying in a loop.
 */
function groq_is_daily_limit(?array $body): bool
{
    $msg = strtolower((string) ($body['error']['message'] ?? ''));
    if ($msg === '') {
        return false;
    }
    if (preg_match('/per day|\bTPD\b|\bRPD\b|tokens? per day|requests? per day/i', $msg)) {
        return true;
    }
    // A multi-hour "try again" wait is effectively a daily quota too.
    return groq_retry_after($body) > 600;
}

/**
 * Is the model itself the problem (decommissioned / renamed / not found)?
 * If so, switching to another model is the right move — not waiting.
 */
function groq_model_unavailable(int $code, ?array $body): bool
{
    $codeStr = strtolower((string) ($body['error']['code'] ?? ''));
    $msg     = strtolower((string) ($body['error']['message'] ?? ''));
    if (in_array($codeStr, ['model_not_found', 'model_decommissioned'], true)) {
        return true;
    }
    if ($code === 404) {
        return true;
    }
    return (bool) preg_match('/decommission|deprecated|does not exist|not found|no longer (?:available|supported)/i', $msg);
}

/** Format a seconds value as a human wait ("about 45s" / "about 1h 2m"). */
function groq_format_wait(float $seconds): string
{
    if ($seconds <= 0) {
        return 'a bit';
    }
    if ($seconds < 90) {
        return 'about ' . (int) ceil($seconds) . 's';
    }
    $mins = (int) round($seconds / 60);
    if ($mins < 60) {
        return 'about ' . $mins . 'm';
    }
    $h = intdiv($mins, 60); $r = $mins % 60;
    return 'about ' . $h . 'h' . ($r ? ' ' . $r . 'm' : '');
}

/** Friendly message for a rate-limit failure. */
function groq_rate_limit_message(?array $body): string
{
    $wait = groq_retry_after($body);
    if (groq_is_daily_limit($body)) {
        return 'Groq daily free-tier quota is exhausted (limit per day reached), so waiting will not help right now'
            . ($wait > 0 ? ' — it resets in ' . groq_format_wait($wait) . '.' : '.')
            . ' Add a paid Groq API key in Settings, switch to a lighter model like "Llama 3.1 8B (instant)", or try again later.';
    }
    return 'Groq rate limit reached (free tier allows a limited number of tokens per minute). '
        . 'Please wait ' . groq_format_wait($wait) . ' and try again, or reduce the count, or switch to a lighter model '
        . 'like "Llama 3.1 8B (instant)" in the settings dropdown.';
}

/**
 * Resilient chat call with AUTOMATIC MODEL SWITCHING.
 *
 * Tries the user's chosen model first; if it is rate-limited or unavailable,
 * it automatically moves on to the next model in GROQ_MODELS (each model has
 * its own quota). Only when EVERY model is exhausted does it report failure.
 *
 * @param int          $maxTokens  completion-token budget (keeps us under TPM limits)
 * @param string|null  $usedModel  (out) the model that actually produced the answer
 */
function groq_chat(string $systemPrompt, string $userPrompt, string $model, int $maxTokens = 3000, float $temperature = 0.4, ?string &$usedModel = null, array $models = []): array
{
    $key = (string) setting('groq_api_key');
    if ($key === '') {
        throw new RuntimeException('Groq API key is not set. Open Settings and add your key.');
    }

    // The caller (frontend rotation) may hand us an explicit ordered list of
    // models to try. Otherwise build the default chain from the preferred model.
    $chain = [];
    foreach ($models as $m) {
        $m = is_string($m) ? trim($m) : '';
        if ($m !== '' && in_array($m, GROQ_MODELS, true) && !in_array($m, $chain, true)) {
            $chain[] = $m;
        }
    }
    if (empty($chain)) {
        $preferred = $model !== '' ? $model : (string) setting('groq_model');
        $chain     = groq_model_chain($preferred);
    }
    $maxTokens = max(800, min(8000, $maxTokens));

    $lastRate = null;   // remember the last rate-limit so we can report it if all fail
    $lastErr  = null;   // remember the last hard error similarly

    foreach ($chain as $candidate) {
        try {
            $result = groq_chat_once($systemPrompt, $userPrompt, $candidate, $maxTokens, $temperature, $key);
            $usedModel = $candidate;
            return $result;
        } catch (GroqRateLimitException $e) {
            // This model is throttled — keep it in mind, try the next model.
            $lastRate = $e;
            continue;
        } catch (RuntimeException $e) {
            if ($e->getCode() === 404) {
                // Model gone/renamed — silently try the next one.
                $lastErr = $e;
                continue;
            }
            throw $e; // a genuine content failure: switching models won't help
        }
    }

    // Every model failed. Prefer reporting the rate-limit (most common cause).
    if ($lastRate !== null) {
        $allDaily = !$lastRate->retryable;
        $msg = $allDaily
            ? 'All available Groq models have hit their free-tier DAILY limit, so waiting will not help right now'
                . ($lastRate->retryAfter > 0 ? ' — the earliest one resets in ' . groq_format_wait($lastRate->retryAfter) . '.' : '.')
                . ' Add a paid Groq API key in Settings, or try again later.'
            : 'All available Groq models are rate-limited right now. Please wait '
                . groq_format_wait($lastRate->retryAfter) . ' and it will resume automatically.';
        throw new GroqRateLimitException($msg, $lastRate->retryAfter, $lastRate->retryable);
    }
    if ($lastErr !== null) {
        throw $lastErr;
    }
    throw new RuntimeException('No Groq model is configured to call.');
}

/**
 * One model attempt:
 *   - Strict JSON mode first; salvages partials and retries in plain mode.
 *   - On a rate limit it throws a GroqRateLimitException immediately (no waiting)
 *     so the caller can switch to another model right away.
 *   - On an unavailable model it throws RuntimeException(code 404) to switch.
 */
function groq_chat_once(string $systemPrompt, string $userPrompt, string $model, int $maxTokens, float $temperature, string $key): array
{
    $messages = [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => $userPrompt],
    ];

    $strictBody = [
        'model'           => $model,
        'temperature'     => $temperature,
        'max_tokens'      => $maxTokens,
        'response_format' => ['type' => 'json_object'],
        'messages'        => $messages,
    ];

    // --- Attempt 1: strict JSON mode ---
    [$code, $body, $raw, $err] = groq_request($strictBody, $key);

    if ($code >= 200 && $code < 300) {
        $parsed = extract_json($body['choices'][0]['message']['content'] ?? '');
        if ($parsed !== null) {
            return $parsed;
        }
    } elseif (groq_model_unavailable($code, $body)) {
        throw new RuntimeException('Model "' . $model . '" is unavailable: ' . ($body['error']['message'] ?? 'not found'), 404);
    } elseif (groq_is_rate_limited($code, $body)) {
        // Don't wait here — let groq_chat switch models. If every model is
        // throttled, the FRONTEND waits and retries the whole batch.
        throw new GroqRateLimitException(groq_rate_limit_message($body), groq_retry_after($body), !groq_is_daily_limit($body));
    } elseif ($body !== null) {
        // Groq sometimes returns a usable partial in failed_generation.
        $failed = $body['error']['failed_generation'] ?? '';
        if (is_string($failed) && $failed !== '') {
            $parsed = extract_json($failed);
            if ($parsed !== null) {
                return $parsed;
            }
        }
    } elseif ($raw === '') {
        throw new RuntimeException('Could not reach Groq API: ' . ($err ?: 'no response'));
    }

    // --- Attempt 2: plain mode (only for non-rate-limit failures) ---
    $messages2 = $messages;
    $messages2[] = [
        'role'    => 'system',
        'content' => 'Respond with ONLY one raw JSON object. No markdown, no code fences, no commentary, no extra keys.',
    ];
    [$code2, $body2, $raw2, $err2] = groq_request([
        'model'       => $model,
        'temperature' => 0.3,
        'max_tokens'  => $maxTokens,
        'messages'    => $messages2,
    ], $key);

    if ($code2 >= 200 && $code2 < 300) {
        $parsed = extract_json($body2['choices'][0]['message']['content'] ?? '');
        if ($parsed !== null) {
            return $parsed;
        }
    } elseif (groq_model_unavailable($code2, $body2)) {
        throw new RuntimeException('Model "' . $model . '" is unavailable: ' . ($body2['error']['message'] ?? 'not found'), 404);
    } elseif (groq_is_rate_limited($code2, $body2)) {
        throw new GroqRateLimitException(groq_rate_limit_message($body2), groq_retry_after($body2), !groq_is_daily_limit($body2));
    }

    // Build a helpful error.
    $detail = '';
    if ($body !== null && isset($body['error']['message'])) {
        $detail = $body['error']['message'];
    } elseif ($body2 !== null && isset($body2['error']['message'])) {
        $detail = $body2['error']['message'];
    } elseif ($raw !== '') {
        $detail = substr($raw, 0, 300);
    }
    throw new RuntimeException('The AI could not produce a valid result for this file. Try a smaller batch or a different model.' . ($detail ? ' (' . $detail . ')' : ''));
}

/** Coerce a model value into a clean string for a CSV cell. */
function cell_value($v): string
{
    if (is_scalar($v)) {
        return (string) $v;
    }
    return $v === null ? '' : json_encode($v, JSON_UNESCAPED_UNICODE);
}

/** Clean a single canonical cell: strip option-letter prefixes, normalize correct_option. */
function canonicalize_cell(string $col, string $val): string
{
    if (preg_match('/^option_[a-d]$/i', $col)) {
        // "(A) 12" / "a) 12" / "A. 12" / "[b] 5" -> "12" / "5"
        return preg_replace('/^\s*[\(\[]?\s*[A-Da-d]\s*[\)\]\.\:\-]\s*/u', '', $val);
    }
    if (preg_match('/^correct/i', $col)) {
        if (preg_match('/[A-Da-d]/', $val, $m)) {
            return strtoupper($m[0]); // keep only the option letter
        }
    }
    return $val;
}

/** Normalize a list of rows so every row has exactly the given columns. */
function normalize_rows(array $rows, array $columns): array
{
    $clean = [];
    foreach ($rows as $r) {
        if (!is_array($r)) {
            continue;
        }
        $row = [];
        foreach ($columns as $c) {
            $val = isset($r[$c]) ? cell_value($r[$c]) : '';
            $row[$c] = canonicalize_cell($c, $val);
        }
        $clean[] = $row;
    }
    return $clean;
}

/* ============================================================
   Arithmetic verification — LLMs are unreliable at maths, so for
   questions that are plain expressions (e.g. "87 × 94 = ?") we
   compute the answer ourselves and fix correct_option + explanation.
   ============================================================ */

/** Safely evaluate a sanitized arithmetic string (+ - * / and parens). */
function shunting_eval(string $s): ?float
{
    $tokens = []; $i = 0; $n = strlen($s); $prev = null;
    while ($i < $n) {
        $c = $s[$i];
        if (ctype_digit($c) || $c === '.') {
            $num = '';
            while ($i < $n && (ctype_digit($s[$i]) || $s[$i] === '.')) { $num .= $s[$i]; $i++; }
            $tokens[] = ['num', (float) $num]; $prev = 'num'; continue;
        }
        if (strpos('+-*/', $c) !== false) {
            if (($c === '-' || $c === '+') && ($prev === null || $prev === 'op' || $prev === '(')) {
                $tokens[] = ['num', 0.0]; $tokens[] = ['op', $c]; $prev = 'op'; $i++; continue;
            }
            $tokens[] = ['op', $c]; $prev = 'op'; $i++; continue;
        }
        if ($c === '(') { $tokens[] = ['(', '(']; $prev = '('; $i++; continue; }
        if ($c === ')') { $tokens[] = [')', ')']; $prev = ')'; $i++; continue; }
        return null;
    }
    $out = []; $ops = []; $prec = ['+' => 1, '-' => 1, '*' => 2, '/' => 2];
    foreach ($tokens as $t) {
        if ($t[0] === 'num') { $out[] = $t; }
        elseif ($t[0] === 'op') {
            while (!empty($ops) && end($ops)[0] === 'op' && $prec[end($ops)[1]] >= $prec[$t[1]]) { $out[] = array_pop($ops); }
            $ops[] = $t;
        } elseif ($t[0] === '(') { $ops[] = $t; }
        elseif ($t[0] === ')') {
            while (!empty($ops) && end($ops)[0] !== '(') { $out[] = array_pop($ops); }
            if (empty($ops)) { return null; }
            array_pop($ops);
        }
    }
    while (!empty($ops)) { $o = array_pop($ops); if ($o[0] === '(') { return null; } $out[] = $o; }
    $st = [];
    foreach ($out as $t) {
        if ($t[0] === 'num') { $st[] = $t[1]; }
        else {
            if (count($st) < 2) { return null; }
            $b = array_pop($st); $a = array_pop($st);
            switch ($t[1]) {
                case '+': $st[] = $a + $b; break;
                case '-': $st[] = $a - $b; break;
                case '*': $st[] = $a * $b; break;
                case '/': if ($b == 0.0) { return null; } $st[] = $a / $b; break;
            }
        }
    }
    return count($st) === 1 ? $st[0] : null;
}

/** Turn human text into a clean expression and evaluate it. */
function eval_expr_string(string $expr): ?float
{
    $s = $expr;
    $s = preg_replace('/(\d+(?:\.\d+)?)\s*%\s*of\s*/i', '($1/100)*', $s);
    $s = preg_replace('/(\d+(?:\.\d+)?)\s*%/', '($1/100)', $s);
    $s = str_replace(['×', '✕', '⋅', '·'], '*', $s);
    $s = str_replace(['÷'], '/', $s);
    $s = str_replace(['−', '–', '—'], '-', $s);
    $s = preg_replace('/(\d|\))\s*[xX]\s*(\d|\()/', '$1*$2', $s);
    $s = preg_replace('/(\d),(\d{3})\b/', '$1$2', $s);
    $s = str_replace('?', '', (string) $s);
    $s = str_replace(' ', '', (string) $s);
    if ($s === '' || preg_match('/[^0-9.+\-*\/()]/', $s)) { return null; }
    return shunting_eval($s);
}

/** If the question is "EXPR = ?" (or "? = EXPR"), return the numeric answer. */
function eval_question(string $q): ?float
{
    if (strpos($q, '=') === false) { return null; }
    $parts = explode('=', $q);
    if (count($parts) < 2) { return null; }
    $rhs = trim(end($parts));
    if ($rhs === '?') {
        $expr = implode('=', array_slice($parts, 0, -1));
    } elseif (trim($parts[0]) === '?') {
        $expr = implode('=', array_slice($parts, 1));
    } else {
        return null; // anything more complex -> trust the model
    }
    // Strip a prose lead-in ("...in EXPR", "...equation: EXPR") so we eval just the maths.
    if (preg_match('/[a-z]/i', $expr)) {
        $lc = strtolower($expr);
        foreach ([' in ', ':'] as $mk) {
            $p = strrpos($lc, $mk);
            if ($p !== false) { $expr = substr($expr, $p + strlen($mk)); break; }
        }
    }
    return eval_expr_string($expr);
}

/** Build a worked "x% of y + ..." explanation, if the question is that pattern. */
function approx_explanation(string $q): ?string
{
    if (!preg_match_all('/([+\-]?)\s*(\d+(?:\.\d+)?)\s*%\s*of\s*(\d+(?:\.\d+)?)/i', $q, $m, PREG_SET_ORDER)) {
        return null;
    }
    $parts = []; $vals = []; $total = 0.0;
    foreach ($m as $k => $mm) {
        $sign = $mm[1] === '-' ? -1 : 1;
        $term = ($sign * (float) $mm[2] / 100) * (float) $mm[3];
        $op = $k === 0 ? '' : ($sign < 0 ? ' - ' : ' + ');
        $parts[] = $op . round((float) $mm[2]) . '% of ' . round((float) $mm[3]);
        $vals[]  = $op . round(abs($term));
        $total  += $term;
    }
    return implode('', $parts) . ' = ' . implode('', $vals) . ' ≈ ' . round($total);
}

/** Fix correct_option + explanation (and the option value) for verifiable arithmetic questions. */
function verify_arithmetic(array &$row): void
{
    if (!array_key_exists('question_text', $row)) { return; }
    $q   = (string) $row['question_text'];
    $ans = eval_question($q);
    if ($ans === null || !is_finite($ans)) { return; }

    $cols = ['option_a', 'option_b', 'option_c', 'option_d'];
    $letters = ['A', 'B', 'C', 'D'];
    $best = null; $bestDiff = null; $bestCol = null;
    foreach ($cols as $idx => $oc) {
        if (!isset($row[$oc])) { continue; }
        $raw = preg_replace('/[^0-9.\-]/', '', (string) $row[$oc]);
        if ($raw === '' || !is_numeric($raw)) { continue; }
        $v = (float) $raw; $d = abs($v - $ans);
        if ($bestDiff === null || $d < $bestDiff) { $bestDiff = $d; $best = $letters[$idx]; $bestCol = $oc; }
    }
    if ($best === null) { return; }

    $ansStr   = (abs($ans - round($ans)) < 0.005) ? (string) (int) round($ans) : (string) round($ans, 2);
    $isApprox = (bool) preg_match('/approx/i', $q);

    $expl = isset($row['explanation']) ? trim((string) $row['explanation']) : '';
    $ansDigits  = preg_replace('/\D/', '', $ansStr);
    $explHasAns = $ansDigits !== '' && strpos(preg_replace('/\D/', '', $expl), $ansDigits) !== false;

    if ($isApprox) {
        // Approximation: pick the closest existing option (don't rewrite option values).
        if ($bestDiff > max(5.0, abs($ans) * 0.06)) { return; } // our reading likely mismatches
        if (array_key_exists('correct_option', $row)) { $row['correct_option'] = $best; }
        if (array_key_exists('explanation', $row)) {
            if ($expl === '') { $row['explanation'] = approx_explanation($q) ?: ('Approximate value ≈ ' . $ansStr . '.'); }
            elseif (!$explHasAns) { $row['explanation'] = $expl . "\n✅ Answer ≈ " . $ansStr; }
        }
        return;
    }

    // Exact arithmetic: make sure the correct value is actually present.
    if ($bestDiff > max(100.0, abs($ans) * 0.03)) { return; } // eval likely unrelated to this question
    if ($bestDiff > 0 && $bestCol !== null) { $row[$bestCol] = $ansStr; } // place exact answer into nearest option
    if (array_key_exists('correct_option', $row)) { $row['correct_option'] = $best; }
    if (array_key_exists('explanation', $row)) {
        // Keep the model's rich step-by-step text; only fix/ensure the final answer.
        if ($expl === '') { $row['explanation'] = trim(preg_replace('/\?/', $ansStr, $q, 1)); }
        elseif (!$explHasAns) { $row['explanation'] = $expl . "\n✅ Answer = " . $ansStr; }
    }
}

/* ============================================================
   PART 1 — Generate new questions (output = canonical schema)
   ============================================================ */

/**
 * Generate NEW, distinct questions in the canonical output schema, learning
 * the style/topic/difficulty from the uploaded samples (any column layout).
 *
 * @param string[]              $columns   canonical OUTPUT columns
 * @param array<int,array>      $samples   raw uploaded sample rows (any keys)
 * @param string[]              $avoid     question texts already produced (skip dupes)
 * @return array<int,array<string,string>>
 */
function groq_generate(array $columns, array $samples, string $topic, int $count, string $extra, string $model, array $avoid = [], ?string &$usedModel = null, array $models = []): array
{
    $columnList = implode(', ', $columns);
    $keysShape  = implode(', ', array_map(fn ($c) => '"' . $c . '": "..."', $columns));

    // Show representative samples (already curated to cover the distinct types).
    $sampleLines = [];
    foreach (array_slice($samples, 0, 14) as $i => $row) {
        $trim = [];
        foreach ((array) $row as $k => $v) {
            $trim[$k] = mb_substr((string) (is_scalar($v) ? $v : json_encode($v)), 0, 160);
        }
        $sampleLines[] = 'Type ' . ($i + 1) . ': ' . json_encode($trim, JSON_UNESCAPED_UNICODE);
    }
    $sampleText = $sampleLines ? implode("\n", $sampleLines) : '(none provided)';
    $typeCount  = count($sampleLines);

    $extraLine = $extra !== '' ? "- Extra instructions from the user: {$extra}\n" : '';

    if ($topic !== '') {
        $topicLine = "- Generate exactly {$count} brand-new ORIGINAL questions about: \"{$topic}\".";
    } else {
        $topicLine = "- Study the samples, work out their exact topic/sub-topic and difficulty, "
            . "then generate exactly {$count} brand-new ORIGINAL questions on the SAME topic and style.";
    }

    // Anti-duplicate context.
    $avoidLine = '';
    if (!empty($avoid)) {
        $recent = array_slice(array_values($avoid), -60);
        $avoidLine = "- These questions were ALREADY created — do NOT repeat, reuse, or merely change the numbers of any of them:\n"
            . json_encode($recent, JSON_UNESCAPED_UNICODE) . "\n";
    }

    $system = 'You are a senior exam-paper author. You write fresh, varied, exam-ready multiple-choice '
        . 'questions and a SHORT correct solution for each. Every question must be genuinely different — '
        . 'vary the sub-topic, structure, scenario and numbers. You output ONLY a single JSON object '
        . '{"questions":[...]} whose items use EXACTLY the given output keys. No analysis, no extra keys.';

    $user = <<<PROMPT
Below are representative questions showing the {$typeCount} DIFFERENT question type(s) found in the source file (your output may use different column names than these):
{$sampleText}

OUTPUT COLUMNS — each generated question MUST be an object with EXACTLY these keys (same spelling/case):
{$columnList}

TASK:
{$topicLine}
- COVER ALL THE TYPES shown above. Spread the {$count} questions as a BALANCED MIX across every type — do NOT keep repeating just one or two patterns.
- Within each type, vary the numbers, values and scenario a lot so no two questions feel the same.
- Fill option columns (option_a..option_d) with four plausible, distinct choices. Put ONLY the value — do NOT prefix options with "(A)", "(a)", "A.", etc. The letter belongs only in correct_option.
- Set correct_option to just the LETTER of the right choice (A, B, C or D) and make sure it is genuinely correct.
- EXPLANATION (VERY IMPORTANT): teach it so a beginner with NO coaching understands. Follow these rules:
  * Line 1 = the simplest trick/shortcut in a few words (e.g. "Trick: both numbers are near 100, use base-100").
  * Then ONE step per line (separated by "\n"), plugging in the REAL numbers — never skip a step.
  * Use plain, easy words and explain WHY each step works in a few words (like a friendly teacher at the board).
  * Last line = the final answer, e.g. "Answer = 9310".
  Example for "98 × 95 = ?": "Trick: both are close to 100.\n98 = 100-2, 95 = 100-5\nStep 1: cross subtract -> 98-5 = 93 (first digits)\nStep 2: multiply the gaps -> 2x5 = 10 (last two digits)\nJoin -> 9310\nAnswer = 9310". Never a single generic sentence, never blank.
- Set "difficulty" to one of: easy, medium, hard.
{$avoidLine}{$extraLine}
Output ONLY this JSON object (no markdown, no commentary):
{ "questions": [ { {$keysShape} } ] }
PROMPT;

    // Richer explanations but lean enough for free-tier TPM.
    $maxTokens = (int) min(5200, max(1100, $count * 300 + 500));
    $parsed    = groq_chat($system, $user, $model, $maxTokens, 0.5, $usedModel, $models);
    $questions = $parsed['questions'] ?? ($parsed['data'] ?? []);
    if (!is_array($questions)) {
        $questions = [];
    }
    $rows = normalize_rows($questions, $columns);
    foreach ($rows as &$r) { verify_arithmetic($r); } // fix wrong answers/explanations
    unset($r);
    return $rows;
}

/* ============================================================
   PART 2 — Add solutions & reformat to the canonical schema
   ============================================================ */

/**
 * Take uploaded question rows (any column layout) and return them in the
 * canonical schema with a correct step-by-step solution filled in. The
 * question text, options and correct answer are preserved as given.
 *
 * @param string[]         $columns  canonical OUTPUT columns
 * @param array<int,array> $rows     raw uploaded rows (any keys)
 * @return array<int,array<string,string>>
 */
function groq_solve(array $columns, array $rows, string $extra, string $model, ?string &$usedModel = null, array $models = []): array
{
    $columnList = implode(', ', $columns);
    $keysShape  = implode(', ', array_map(fn ($c) => '"' . $c . '": "..."', $columns));

    $indexed = [];
    foreach (array_values($rows) as $i => $row) {
        $trim = ['_index' => $i];
        if (is_array($row)) {
            foreach ($row as $k => $v) {
                $trim[$k] = mb_substr((string) (is_scalar($v) ? $v : json_encode($v)), 0, 200);
            }
        }
        $indexed[] = $trim;
    }
    $rowsJson  = json_encode($indexed, JSON_UNESCAPED_UNICODE);
    $extraLine = $extra !== '' ? "- Extra instructions from the user: {$extra}\n" : '';

    $system = 'You are a meticulous exam solutions author. You keep each question and its options '
        . 'EXACTLY as given, work out the correct answer, and write a clear step-by-step solution. '
        . 'You output ONLY a single JSON object {"rows":[...]} using EXACTLY the given output keys plus "_index".';

    $user = <<<PROMPT
You are given existing question rows (their column names may vary). Each row has an "_index".

Rows:
{$rowsJson}

OUTPUT COLUMNS — convert EVERY row into an object with EXACTLY these keys (plus "_index"):
{$columnList}

TASK:
- Keep the question text and all options EXACTLY as in the source (map them into question_text and option_a..option_d).
- In option_a..option_d put ONLY the value — strip any leading "(A)", "(a)", "A." labels. The letter belongs only in correct_option.
- Determine the correct answer and set correct_option to just its LETTER (A, B, C or D).
- EXPLANATION (VERY IMPORTANT): teach it so a beginner with NO coaching understands. Follow these rules:
  * Line 1 = the simplest trick/shortcut in a few words (e.g. "Trick: numbers near 100 -> use base-100").
  * Then ONE step per line (separated by "\n"), plugging in the REAL numbers — never skip a step.
  * Use plain, easy words and explain WHY each step works in a few words (like a friendly teacher at the board).
  * Last line = the final answer, e.g. "Answer = 9310".
  Example for "98 × 95 = ?": "Trick: both are close to 100.\n98 = 100-2, 95 = 100-5\nStep 1: cross subtract -> 98-5 = 93 (first digits)\nStep 2: multiply the gaps -> 2x5 = 10 (last two digits)\nJoin -> 9310\nAnswer = 9310". Never generic, never blank.
- Set "difficulty" to easy, medium or hard (infer it if the source has none).
- Return ONE output object per input row, keeping "_index" so they can be matched. Do not invent new questions.
{$extraLine}
Output ONLY this JSON object:
{ "rows": [ { "_index": 0, {$keysShape} } ] }
PROMPT;

    $maxTokens = (int) min(5200, max(1100, count($rows) * 300 + 500));
    $parsed    = groq_chat($system, $user, $model, $maxTokens, 0.3, $usedModel, $models);
    $filled    = $parsed['rows'] ?? ($parsed['questions'] ?? ($parsed['data'] ?? []));
    if (!is_array($filled)) {
        $filled = [];
    }

    // Map back by _index, preserving input order and count.
    $byIndex = [];
    foreach ($filled as $f) {
        if (is_array($f) && isset($f['_index'])) {
            $byIndex[(int) $f['_index']] = $f;
        }
    }

    $result = [];
    foreach (array_values($rows) as $i => $row) {
        $src = $byIndex[$i] ?? [];
        $out = [];
        foreach ($columns as $c) {
            $val = isset($src[$c]) ? cell_value($src[$c]) : '';
            $out[$c] = canonicalize_cell($c, $val);
        }
        verify_arithmetic($out); // fix wrong answers/explanations
        $result[] = $out;
    }
    return $result;
}
