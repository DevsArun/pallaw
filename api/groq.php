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
    return eval_expr_string($expr);
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

    if ($isApprox) {
        // Approximation: just pick the closest existing option (don't rewrite values).
        if ($bestDiff > max(5.0, abs($ans) * 0.06)) { return; } // our reading likely mismatches
        if (array_key_exists('correct_option', $row)) { $row['correct_option'] = $best; }
        if (array_key_exists('explanation', $row)) { $row['explanation'] = 'Approximate value ≈ ' . $ansStr . '.'; }
        return;
    }

    // Exact arithmetic: make sure the correct value is actually present.
    if ($bestDiff > max(100.0, abs($ans) * 0.03)) { return; } // eval likely unrelated to this question
    if ($bestDiff > 0 && $bestCol !== null) { $row[$bestCol] = $ansStr; } // place exact answer into nearest option
    if (array_key_exists('correct_option', $row)) { $row['correct_option'] = $best; }
    if (array_key_exists('explanation', $row)) { $row['explanation'] = trim(preg_replace('/\?/', $ansStr, $q, 1)); }
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
- Write the "explanation" SHORT: 1-2 lines with only the key steps — clear enough to understand, never a long paragraph. Never leave it blank.
- Set "difficulty" to one of: easy, medium, hard.
{$avoidLine}{$extraLine}
Output ONLY this JSON object (no markdown, no commentary):
{ "questions": [ { {$keysShape} } ] }
PROMPT;

    // Short explanations -> fewer tokens -> fewer rate limits.
    $maxTokens = (int) min(6000, max(900, $count * 190 + 500));
    $parsed    = groq_chat($system, $user, $model, $maxTokens, 0.6);
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
- Keep the question text and all options EXACTLY as in the source (map them into question_text and option_a..option_d).
- In option_a..option_d put ONLY the value — strip any leading "(A)", "(a)", "A." labels. The letter belongs only in correct_option.
- Determine the correct answer and set correct_option to just its LETTER (A, B, C or D).
- Write the "explanation" SHORT: 1-2 lines with only the key steps — clear but concise. Never leave it blank.
- Set "difficulty" to easy, medium or hard (infer it if the source has none).
- Return ONE output object per input row, keeping "_index" so they can be matched. Do not invent new questions.
{$extraLine}
Output ONLY this JSON object:
{ "rows": [ { "_index": 0, {$keysShape} } ] }
PROMPT;

    $maxTokens = (int) min(6000, max(900, count($rows) * 190 + 500));
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
            $val = isset($src[$c]) ? cell_value($src[$c]) : '';
            $out[$c] = canonicalize_cell($c, $val);
        }
        verify_arithmetic($out); // fix wrong answers/explanations
        $result[] = $out;
    }
    return $result;
}
