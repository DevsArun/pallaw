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

/** Pull the suggested wait (seconds) out of a rate-limit message. */
function groq_retry_after(?array $body): float
{
    $msg = $body['error']['message'] ?? '';
    if (is_string($msg) && preg_match('/try again in ([\d.]+)\s*s/i', $msg, $m)) {
        return (float) $m[1];
    }
    return 0.0;
}

/** Friendly message for a rate-limit failure. */
function groq_rate_limit_message(?array $body): string
{
    $wait = groq_retry_after($body);
    $waitTxt = $wait > 0 ? ('about ' . ceil($wait) . 's') : 'a bit';
    return 'Groq rate limit reached (free tier allows a limited number of tokens per minute). '
        . 'Please wait ' . $waitTxt . ' and try again, or reduce the count, or switch to a lighter model '
        . 'like "Llama 3.1 8B (instant)" in the settings dropdown.';
}

/**
 * Resilient chat call.
 *   - Strict JSON mode first; salvages partials and retries in plain mode.
 *   - Rate limits are handled separately: short waits auto-retry once,
 *     longer ones return a clear message WITHOUT burning more tokens.
 *
 * @param int $maxTokens  completion-token budget (keeps us under TPM limits)
 */
function groq_chat(string $systemPrompt, string $userPrompt, string $model, int $maxTokens = 3000, float $temperature = 0.4): array
{
    $key = (string) setting('groq_api_key');
    if ($key === '') {
        throw new RuntimeException('Groq API key is not set. Open Settings and add your key.');
    }
    $model = $model !== '' ? $model : (string) setting('groq_model');
    $maxTokens = max(800, min(8000, $maxTokens));

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
    } elseif (groq_is_rate_limited($code, $body)) {
        // Do NOT fire more requests on a rate limit. Auto-retry only for short waits.
        $wait = groq_retry_after($body);
        if ($wait > 0 && $wait <= 12) {
            sleep((int) ceil($wait) + 1);
            [$rc, $rb] = groq_request($strictBody, $key);
            if ($rc >= 200 && $rc < 300) {
                $parsed = extract_json($rb['choices'][0]['message']['content'] ?? '');
                if ($parsed !== null) {
                    return $parsed;
                }
            }
            throw new RuntimeException(groq_rate_limit_message($rb ?? $body), 429);
        }
        throw new RuntimeException(groq_rate_limit_message($body), 429);
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
    } elseif (groq_is_rate_limited($code2, $body2)) {
        throw new RuntimeException(groq_rate_limit_message($body2), 429);
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
            $row[$c] = isset($r[$c]) ? cell_value($r[$c]) : '';
        }
        $clean[] = $row;
    }
    return $clean;
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
function groq_generate(array $columns, array $samples, string $topic, int $count, string $extra, string $model, array $avoid = []): array
{
    $columnList = implode(', ', $columns);
    $keysShape  = implode(', ', array_map(fn ($c) => '"' . $c . '": "..."', $columns));

    // Show the samples exactly as uploaded so the model learns the real style.
    $sampleLines = [];
    foreach (array_slice($samples, 0, 6) as $i => $row) {
        $sampleLines[] = 'Sample ' . ($i + 1) . ': ' . json_encode($row, JSON_UNESCAPED_UNICODE);
    }
    $sampleText = $sampleLines ? implode("\n", $sampleLines) : '(none provided)';

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
        . 'questions and a correct step-by-step solution for each. Every question must be genuinely '
        . 'different — vary the numbers, scenarios, sub-topics and phrasing. You output ONLY a single JSON '
        . 'object {"questions":[...]} whose items use EXACTLY the given output keys. No analysis, no extra keys.';

    $user = <<<PROMPT
Here are sample questions (study their topic, difficulty and style — your output may use different column names than these):
{$sampleText}

OUTPUT COLUMNS — each generated question MUST be an object with EXACTLY these keys (same spelling/case):
{$columnList}

TASK:
{$topicLine}
- Make every question DISTINCT and realistic. Do not output the same template repeatedly.
- Fill option columns (option_a..option_d) with four plausible, distinct choices.
- Set correct_option to the right choice's LETTER (A, B, C or D) and make sure it is actually correct.
- ALWAYS write a clear, correct, step-by-step SOLUTION in the "explanation" key. Never leave it blank.
- Set "difficulty" to one of: easy, medium, hard.
{$avoidLine}{$extraLine}
Output ONLY this JSON object (no markdown, no commentary):
{ "questions": [ { {$keysShape} } ] }
PROMPT;

    // ~320 completion tokens per question; higher temperature for variety.
    $maxTokens = (int) min(8000, max(1200, $count * 320 + 600));
    $parsed    = groq_chat($system, $user, $model, $maxTokens, 0.85);
    $questions = $parsed['questions'] ?? ($parsed['data'] ?? []);
    if (!is_array($questions)) {
        $questions = [];
    }
    return normalize_rows($questions, $columns);
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
function groq_solve(array $columns, array $rows, string $extra, string $model): array
{
    $columnList = implode(', ', $columns);
    $keysShape  = implode(', ', array_map(fn ($c) => '"' . $c . '": "..."', $columns));

    $indexed = [];
    foreach (array_values($rows) as $i => $row) {
        $indexed[] = array_merge(['_index' => $i], is_array($row) ? $row : []);
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
- Keep the question text and all options EXACTLY as in the source (just map them into question_text and option_a..option_d).
- Determine the correct answer and set correct_option to its LETTER (A, B, C or D).
- ALWAYS write a clear, correct, step-by-step SOLUTION in the "explanation" key. Never leave it blank.
- Set "difficulty" to easy, medium or hard (infer it if the source has none).
- Return ONE output object per input row, keeping "_index" so they can be matched. Do not invent new questions.
{$extraLine}
Output ONLY this JSON object:
{ "rows": [ { "_index": 0, {$keysShape} } ] }
PROMPT;

    $maxTokens = (int) min(8000, max(1200, count($rows) * 320 + 600));
    $parsed    = groq_chat($system, $user, $model, $maxTokens, 0.3);
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
            $out[$c] = isset($src[$c]) ? cell_value($src[$c]) : '';
        }
        $result[] = $out;
    }
    return $result;
}
