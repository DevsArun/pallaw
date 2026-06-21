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

/** Low-level chat call returning the decoded JSON object from the model. */
function groq_chat(string $systemPrompt, string $userPrompt, string $model): array
{
    $key = (string) setting('groq_api_key');
    if ($key === '') {
        throw new RuntimeException('Groq API key is not set. Open Settings and add your key.');
    }

    $body = [
        'model'           => $model !== '' ? $model : (string) setting('groq_model'),
        'temperature'     => 0.6,
        'response_format' => ['type' => 'json_object'],
        'messages'        => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt],
        ],
    ];

    $ch = curl_init(GROQ_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $key,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT        => 120,
    ]);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException('Could not reach Groq API: ' . $curlErr);
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        throw new RuntimeException("Groq API error ({$httpCode}): " . $response);
    }

    $data    = json_decode($response, true);
    $content = $data['choices'][0]['message']['content'] ?? '{}';

    $parsed = json_decode($content, true);
    if (!is_array($parsed) && preg_match('/\{[\s\S]*\}/', (string) $content, $m)) {
        $parsed = json_decode($m[0], true);
    }
    return is_array($parsed) ? $parsed : [];
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
   PART 1 — Generate new questions
   ============================================================ */

/**
 * @param string[]              $columns
 * @param array<int,array>      $samples
 * @return array<int,array<string,string>>
 */
function groq_generate(array $columns, array $samples, string $topic, int $count, string $extra, string $model): array
{
    $columnList = implode(', ', $columns);

    $sampleLines = [];
    foreach ($samples as $i => $row) {
        $obj = [];
        foreach ($columns as $c) {
            $obj[$c] = $row[$c] ?? '';
        }
        $sampleLines[] = 'Sample ' . ($i + 1) . ': ' . json_encode($obj, JSON_UNESCAPED_UNICODE);
    }
    $sampleText = implode("\n", $sampleLines);
    $keysShape  = implode(', ', array_map(fn ($c) => '"' . $c . '": "..."', $columns));
    $extraLine  = $extra !== '' ? "- Extra instructions from the user: {$extra}\n" : '';

    $system = 'You are an expert math teacher who writes exam-ready multiple-choice questions. '
        . 'You ALWAYS include a clear, correct, step-by-step explanation for every question. '
        . 'You return strictly valid JSON only.';

    $user = <<<PROMPT
You are given sample questions from a CSV file. Study their columns, style, difficulty and how each field is filled.

CSV columns (use EXACTLY these keys, same spelling and case): {$columnList}

Samples:
{$sampleText}

TASK:
- Generate exactly {$count} brand-new ORIGINAL math questions about: "{$topic}".
- Match the samples' format and difficulty distribution.
- Every item MUST contain every column key listed above.
- If there are option columns (e.g. option_a..option_d), fill all of them with plausible choices.
- If there is a correct-answer column, put the RIGHT answer (matching the option label format used in the samples, e.g. "B").
- ALWAYS fill the explanation/solution column with a correct, concise step-by-step working. Do the math carefully and double-check it.
- If there is a difficulty column, use the same vocabulary as the samples (e.g. easy/medium/hard).
- Do NOT copy the samples; make new questions.
{$extraLine}
Return ONLY valid JSON in this shape (no markdown, no commentary):
{ "questions": [ { {$keysShape} } ] }
PROMPT;

    $parsed    = groq_chat($system, $user, $model);
    $questions = $parsed['questions'] ?? ($parsed['data'] ?? []);
    if (!is_array($questions)) {
        $questions = [];
    }
    return normalize_rows($questions, $columns);
}

/* ============================================================
   PART 2 — Add explanation / solution to existing questions
   ============================================================ */

/**
 * Fill one or more "target" columns (e.g. explanation, correct_option) for
 * each existing row, keeping every other column exactly as provided.
 *
 * @param string[]              $columns      all CSV columns (preserved)
 * @param array<int,array>      $rows         existing question rows
 * @param string[]              $targets      columns the AI should (re)fill
 * @return array<int,array<string,string>>
 */
function groq_solve(array $columns, array $rows, array $targets, string $extra, string $model): array
{
    $columnList = implode(', ', $columns);
    $targetList = implode(', ', $targets);

    // Send rows indexed so we can map answers back reliably.
    $indexed = [];
    foreach (array_values($rows) as $i => $row) {
        $obj = ['_index' => $i];
        foreach ($columns as $c) {
            $obj[$c] = $row[$c] ?? '';
        }
        $indexed[] = $obj;
    }
    $rowsJson    = json_encode($indexed, JSON_UNESCAPED_UNICODE);
    $extraLine   = $extra !== '' ? "- Extra instructions from the user: {$extra}\n" : '';
    $targetShape = implode(', ', array_map(fn ($t) => '"' . $t . '": "..."', $targets));

    $system = 'You are a meticulous math teacher. For each question you produce a correct, '
        . 'clear, step-by-step solution. You never change the original question or options. '
        . 'You return strictly valid JSON only.';

    $user = <<<PROMPT
You are given existing CSV question rows. Each row has an "_index" plus these columns: {$columnList}

Rows:
{$rowsJson}

TASK:
- For EVERY row, fill in ONLY these column(s): {$targetList}.
- Keep "_index" unchanged so answers can be matched.
- Do NOT modify any other column — leave the question text, options and difficulty exactly as given.
- Solve the math correctly. If a target column is the correct-answer/option, set the RIGHT value in the same label format used by the row's options (e.g. "C").
- If a target column is an explanation/solution, write a concise, correct step-by-step working.
- Be clean and consistent. No placeholders, no "N/A", no errors.
{$extraLine}
Return ONLY valid JSON in this shape:
{ "rows": [ { "_index": 0, {$targetShape} } ] }
PROMPT;

    $parsed   = groq_chat($system, $user, $model);
    $filled   = $parsed['rows'] ?? ($parsed['questions'] ?? ($parsed['data'] ?? []));
    if (!is_array($filled)) {
        $filled = [];
    }

    // Map filled targets back onto the original rows by _index.
    $byIndex = [];
    foreach ($filled as $f) {
        if (is_array($f) && isset($f['_index'])) {
            $byIndex[(int) $f['_index']] = $f;
        }
    }

    $result = [];
    foreach (array_values($rows) as $i => $row) {
        $out = [];
        foreach ($columns as $c) {
            $out[$c] = isset($row[$c]) ? cell_value($row[$c]) : '';
        }
        if (isset($byIndex[$i])) {
            foreach ($targets as $t) {
                if (isset($byIndex[$i][$t]) && $byIndex[$i][$t] !== '') {
                    $out[$t] = cell_value($byIndex[$i][$t]);
                }
            }
        }
        $result[] = $out;
    }

    return $result;
}
