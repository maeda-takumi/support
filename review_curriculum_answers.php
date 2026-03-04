<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

const GEMINI_API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models';
const GEMINI_MODEL = 'gemma-3-27b-it';

function main(): int
{
    logMessage('INFO', '処理開始: curriculum_answer review 生成');

    try {
        $pdo = createPdo();
    } catch (Throwable $e) {
        logMessage('ERROR', 'DB接続に失敗: ' . $e->getMessage());
        return 1;
    }

    $apiKey = defined('GEMINI_API_KEY') ? (string)GEMINI_API_KEY : '';
    if ($apiKey === '') {
        logMessage('ERROR', 'GEMINI_API_KEY が設定されていません');
        return 1;
    }

    try {
        $targets = fetchReviewTargets($pdo);
    } catch (Throwable $e) {
        logMessage('ERROR', '対象データ取得に失敗: ' . $e->getMessage());
        return 1;
    }

    logMessage('INFO', '処理対象件数: ' . count($targets));

    $updated = 0;
    $skippedUrl = 0;
    $skippedEmpty = 0;
    $errors = 0;

    foreach ($targets as $target) {
        $answerId = (string)$target['answer_id'];
        $answer1 = (string)($target['answer_1'] ?? '');
        $answer2 = (string)($target['answer_2'] ?? '');

        if (trim($answer1) === '' || trim($answer2) === '') {
            $skippedEmpty++;
            continue;
        }

        if (containsUrl($answer1) || containsUrl($answer2)) {
            $skippedUrl++;
            continue;
        }

        try {
            $review = generateReview($answer1, $answer2, $apiKey);
            if ($review === '') {
                throw new RuntimeException('Geminiの応答からreviewテキストを取得できませんでした');
            }

            updateReview($pdo, $answerId, $review);
            $updated++;
        } catch (Throwable $e) {
            $errors++;
            logMessage('ERROR', sprintf('review生成失敗 answer_id=%s (%s)', $answerId, $e->getMessage()));
        }
    }

    logMessage(
        'INFO',
        sprintf(
            '処理終了: updated=%d, skipped_url=%d, skipped_empty=%d, errors=%d',
            $updated,
            $skippedUrl,
            $skippedEmpty,
            $errors
        )
    );

    return 0;
}

function createPdo(): PDO
{
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);

    return new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

/**
 * @return array<int, array<string, mixed>>
 */
function fetchReviewTargets(PDO $pdo): array
{
    $sql = "SELECT answer_id, answer_1, answer_2 FROM curriculum_answer WHERE (review IS NULL OR TRIM(review) = '') ORDER BY answer_date ASC";
    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll();

    return is_array($rows) ? $rows : [];
}

function containsUrl(string $text): bool
{
    return preg_match('/https?:\/\/|www\./iu', $text) === 1;
}

function generateReview(string $answer1, string $answer2, string $apiKey): string
{
    $url = sprintf(
        '%s/%s:generateContent?key=%s',
        GEMINI_API_BASE,
        rawurlencode(GEMINI_MODEL),
        rawurlencode($apiKey)
    );

    $prompt = "あなたは学習カリキュラムのメンターです。受講者の answer_1 と answer_2 を読み、努力を認めつつ改善点を具体的に示す日本語の総評を120〜220文字で1つ作成してください。箇条書きは禁止です。\n\n"
        . "answer_1:\n{$answer1}\n\n"
        . "answer_2:\n{$answer2}";

    $payload = [
        'contents' => [
            [
                'parts' => [
                    ['text' => $prompt],
                ],
            ],
        ],
        'generationConfig' => [
            'temperature' => 0.4,
            'topP' => 0.9,
            'maxOutputTokens' => 300,
        ],
    ];

    $response = httpPostJson($url, $payload);
    $data = json_decode($response['body'], true);

    if (!is_array($data)) {
        throw new RuntimeException('GeminiレスポンスのJSON解析に失敗しました');
    }

    $text = extractGeminiText($data);
    return trim($text);
}

/**
 * @param array<string, mixed> $data
 */
function extractGeminiText(array $data): string
{
    $candidates = $data['candidates'] ?? null;
    if (!is_array($candidates) || $candidates === []) {
        return '';
    }

    foreach ($candidates as $candidate) {
        if (!is_array($candidate)) {
            continue;
        }

        $parts = $candidate['content']['parts'] ?? null;
        if (!is_array($parts)) {
            continue;
        }

        $texts = [];
        foreach ($parts as $part) {
            if (!is_array($part)) {
                continue;
            }
            $text = $part['text'] ?? null;
            if (is_string($text) && trim($text) !== '') {
                $texts[] = trim($text);
            }
        }

        if ($texts !== []) {
            return implode("\n", $texts);
        }
    }

    return '';
}

function updateReview(PDO $pdo, string $answerId, string $review): void
{
    $stmt = $pdo->prepare('UPDATE curriculum_answer SET review=:review WHERE answer_id=:answer_id');
    $stmt->execute([
        'review' => $review,
        'answer_id' => $answerId,
    ]);
}

/**
 * @param array<string, mixed> $json
 * @return array{status:int, body:string}
 */
function httpPostJson(string $url, array $json): array
{
    return httpRequest(
        'POST',
        $url,
        ['Content-Type: application/json'],
        json_encode($json, JSON_THROW_ON_ERROR)
    );
}

/**
 * @param array<int, string> $headers
 * @return array{status:int, body:string}
 */
function httpRequest(string $method, string $url, array $headers = [], ?string $body = null): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('cURL初期化に失敗しました');
    }

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);

    if ($headers !== []) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $responseBody = curl_exec($ch);
    $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errorMessage = curl_error($ch);
    curl_close($ch);

    if ($responseBody === false) {
        throw new RuntimeException('HTTP通信に失敗しました: ' . $errorMessage);
    }

    if ($statusCode >= 400) {
        throw new RuntimeException(sprintf('HTTPエラー status=%d body=%s', $statusCode, $responseBody));
    }

    return [
        'status' => $statusCode,
        'body' => $responseBody,
    ];
}

function logMessage(string $level, string $message): void
{
    static $output = null;

    if (!is_resource($output)) {
        $output = defined('STDOUT') ? STDOUT : fopen('php://output', 'wb');
    }

    $time = date('Y-m-d H:i:s');
    if (is_resource($output)) {
        fwrite($output, sprintf("[%s] [%s] %s\n", $time, $level, $message));
    }
}

exit(main());
