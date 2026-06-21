<?php
/**
 * Groq API integration — builds the prompt and calls the chat completions
 * endpoint, returning normalized question rows keyed by the CSV columns.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * Build the instruction prompt sent to Groq.
 *
 * @param string[]              $headers
 * @param array<int,array>      $samples
 */
function groq_build_prompt(array $headers, array $samples, string $topic, int $count, string $extra): string
{
    $headerList = implode(', ', $headers);

    $sampleLines = [];
    foreach ($samples as $i => $row) {
        $obj = [];
        foreach ($headers as $h) {
            $obj[$h] = $row[$h] ?? '';
        }
        $sampleLines[] = 'Sample ' . ($i + 1) . ': ' . json_encode($obj, JSON_UNESCAPED_UNICODE);
    }
    $sampleText = implode("\n", $sampleLines);

    $keysShape = implode(', ', array_map(fn ($h) => '"' . $h . '": "..."', $headers));

    $extraLine = $extra !== '' ? "- Additional instructions from the user: {$extra}\n" : '';

    return <<<PROMPT
You are an expert math question author. You are given sample questions that come from a CSV file.

CSV columns (use EXACTLY these keys, same spelling and case): {$headerList}

Here are the sample rows so you understand the style, difficulty, structure and how each column is filled:
{$sampleText}

TASK:
- Generate exactly {$count} brand-new questions about the topic: "{$topic}".
- Match the style, format, difficulty level and column structure of the samples.
- Every generated item MUST contain every column key listed above.
- If a column holds the answer/solution, fill it with the CORRECT value. Do all math correctly and double-check it.
- If a column holds options/choices, generate plausible options consistent with the samples' format.
- Do NOT copy the sample questions; create original ones on the requested topic.
{$extraLine}
Return ONLY valid JSON in this exact shape (no markdown, no commentary):
{ "questions": [ { {$keysShape} } ] }
PROMPT;
}

/**
 * Call Groq and return normalized rows.
 *
 * @param string[]         $headers
 * @param array<int,array> $samples
 * @return array<int,array<string,string>>
 * @throws RuntimeException
 */
function groq_generate(array $headers, array $samples, string $topic, int $count, string $extra, string $model): array
{
    if (GROQ_API_KEY === '') {
        throw new RuntimeException('GROQ_API_KEY is not set on the server. Add it to api/config.php or set the GROQ_API_KEY environment variable.');
    }

    $prompt = groq_build_prompt($headers, $samples, $topic, $count, $extra);

    $body = [
        'model'           => $model !== '' ? $model : GROQ_DEFAULT_MODEL,
        'temperature'     => 0.7,
        'response_format' => ['type' => 'json_object'],
        'messages'        => [
            [
                'role'    => 'system',
                'content' => 'You generate high-quality, factually correct math questions and return strictly valid JSON.',
            ],
            ['role' => 'user', 'content' => $prompt],
        ],
    ];

    $ch = curl_init(GROQ_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . GROQ_API_KEY,
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
        throw new RuntimeException('Failed to reach Groq API: ' . $curlErr);
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        throw new RuntimeException("Groq API error ({$httpCode}): " . $response);
    }

    $data    = json_decode($response, true);
    $content = $data['choices'][0]['message']['content'] ?? '{}';

    $parsed = json_decode($content, true);
    if (!is_array($parsed)) {
        // Try to salvage a JSON object from the text.
        if (preg_match('/\{[\s\S]*\}/', (string) $content, $m)) {
            $parsed = json_decode($m[0], true);
        }
    }
    if (!is_array($parsed)) {
        $parsed = ['questions' => []];
    }

    $questions = $parsed['questions'] ?? ($parsed['data'] ?? []);
    if (!is_array($questions)) {
        $questions = [];
    }

    // Normalize: ensure every question has every header key as a string.
    $clean = [];
    foreach ($questions as $q) {
        if (!is_array($q)) {
            continue;
        }
        $row = [];
        foreach ($headers as $h) {
            $row[$h] = isset($q[$h]) ? (is_scalar($q[$h]) ? (string) $q[$h] : json_encode($q[$h])) : '';
        }
        $clean[] = $row;
    }

    return $clean;
}
