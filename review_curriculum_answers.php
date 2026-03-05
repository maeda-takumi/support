<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/prompt_template_service.php';

const GEMINI_API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models';
const GEMINI_TEXT_MODEL = 'gemma-3-27b-it';
const GEMINI_MEDIA_MODEL = 'gemini-2.5-flash';
const GEMINI_INITIAL_MAX_OUTPUT_TOKENS = 4096;
const GEMINI_RETRY_MAX_OUTPUT_TOKENS = 8192;
const MEDIA_DOWNLOAD_TIMEOUT = 60;
const MEDIA_DOWNLOAD_MAX_BYTES = 15 * 1024 * 1024;
const MEDIA_MAX_URLS = 4;

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
    $skippedEmpty = 0;
    $errors = 0;

    foreach ($targets as $target) {
        $answerId = (string)$target['answer_id'];
        $answer1 = (string)($target['answer_1'] ?? '');
        $answer2 = (string)($target['answer_2'] ?? '');
        $curriculumId = (string)($target['curriculum_id'] ?? '');

        if (trim($answer1) === '') {
            $skippedEmpty++;
            continue;
        }

        try {
            $review = generateReview($pdo, $curriculumId, $answer1, $answer2, $apiKey);
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
            '処理終了: updated=%d, skipped_empty=%d, errors=%d',
            $updated,
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
    $sql = "SELECT answer_id, curriculum_id, answer_1, answer_2 FROM curriculum_answer WHERE (review IS NULL OR TRIM(review) = '') ORDER BY answer_date ASC";
    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll();

    return is_array($rows) ? $rows : [];
}

function containsUrl(string $text): bool
{
    return preg_match('/https?:\/\/|www\./iu', $text) === 1;
}

function generateReview(PDO $pdo, string $curriculumId, string $answer1, string $answer2, string $apiKey): string
{
    $mediaUrls = extractMediaUrls([$answer1, $answer2]);
    if ($mediaUrls !== []) {
        return generateReviewWithMediaUrls($pdo, $curriculumId, $answer1, $answer2, $mediaUrls, $apiKey);
    }

    return generateTextReview($pdo, $curriculumId, $answer1, $answer2, $apiKey);
}

function generateTextReview(PDO $pdo, string $curriculumId, string $answer1, string $answer2, string $apiKey): string
{
    $url = sprintf(
        '%s/%s:generateContent?key=%s',
        GEMINI_API_BASE,
        rawurlencode(GEMINI_TEXT_MODEL),
        rawurlencode($apiKey)
    );

    $prompt = buildReviewPrompt($pdo, $curriculumId, $answer1, $answer2);

    $parts = [
        ['text' => $prompt],
    ];

    return requestGeminiReviewText($url, $parts);
}

/**
 * @param array<int, string> $mediaUrls
 */
function generateReviewWithMediaUrls(PDO $pdo, string $curriculumId, string $answer1, string $answer2, array $mediaUrls, string $apiKey): string
{
    $url = sprintf(
        '%s/%s:generateContent?key=%s',
        GEMINI_API_BASE,
        rawurlencode(GEMINI_MEDIA_MODEL),
        rawurlencode($apiKey)
    );

    $prompt = buildReviewPrompt($pdo, $curriculumId, $answer1, $answer2);
    $parts = [
        ['text' => $prompt],
    ];

    foreach ($mediaUrls as $mediaUrl) {
        $media = downloadMediaForGemini($mediaUrl);
        $parts[] = [
            'inlineData' => [
                'mimeType' => $media['mime_type'],
                'data' => base64_encode($media['data']),
            ],
        ];
    }

    return requestGeminiReviewText($url, $parts);
}

/**
 * @param array<int, array<string, mixed>> $parts
 */
function requestGeminiReviewText(string $url, array $parts): string
{
    $maxOutputTokens = GEMINI_INITIAL_MAX_OUTPUT_TOKENS;

    for ($attempt = 0; $attempt < 2; $attempt++) {
        $payload = [
            'contents' => [
                [
                    'parts' => $parts,
                ],
            ],
            'generationConfig' => [
                'temperature' => 0.4,
                'topP' => 0.9,
                'maxOutputTokens' => $maxOutputTokens,
            ],
        ];

        $response = httpPostJson($url, $payload);
        $data = json_decode($response['body'], true);

        if (!is_array($data)) {
            throw new RuntimeException('GeminiレスポンスのJSON解析に失敗しました');
        }

        $finishReason = ensureGeminiResponseIsAcceptable($data);

        $text = trim(extractGeminiText($data));
        if ($text !== '' && ($finishReason === null || $finishReason === 'STOP')) {
            return $text;
        }

        if ($attempt === 0 && ($finishReason === 'MAX_TOKENS' || $text === '')) {
            $maxOutputTokens = GEMINI_RETRY_MAX_OUTPUT_TOKENS;
            continue;
        }

        if ($finishReason === 'MAX_TOKENS') {
            throw new RuntimeException('Gemini応答が最大トークン数で途中終了しました');
        }
    }

    throw new RuntimeException('Geminiの応答からreviewテキストを取得できませんでした');
}

/**
 * @param array<string, mixed> $data
 */
function ensureGeminiResponseIsAcceptable(array $data): ?string
{
    $blockReason = $data['promptFeedback']['blockReason'] ?? null;
    if (is_string($blockReason) && $blockReason !== '') {
        throw new RuntimeException('Gemini応答がブロックされました: ' . $blockReason);
    }

    $finishReason = getGeminiFinishReason($data);
    if ($finishReason === null || $finishReason === 'STOP') {
        return $finishReason;
    }

    if ($finishReason !== 'MAX_TOKENS') {
        throw new RuntimeException('Gemini応答が途中終了しました: ' . $finishReason);
    }

    return $finishReason;
}

/**
 * @param array<string, mixed> $data
 */
function getGeminiFinishReason(array $data): ?string
{
    $candidates = $data['candidates'] ?? null;
    if (!is_array($candidates) || $candidates === []) {
        return null;
    }

    foreach ($candidates as $candidate) {
        if (!is_array($candidate)) {
            continue;
        }

        $finishReason = $candidate['finishReason'] ?? null;
        if (is_string($finishReason) && $finishReason !== '') {
            return $finishReason;
        }
    }

    return null;
}

/**
 * @param array<int, string> $texts
 * @return array<int, string>
 */
function extractMediaUrls(array $texts): array
{
    $urls = [];
    foreach ($texts as $text) {
        if (!is_string($text) || trim($text) === '') {
            continue;
        }

        preg_match_all("/https?:\\/\\/[^\\s\"'<>]+/iu", $text, $matches);
        foreach ($matches[0] ?? [] as $rawUrl) {
            $url = rtrim((string)$rawUrl, '.,);:!?]');
            if ($url === '' || !isAllowedMediaUrl($url)) {
                continue;
            }
            $urls[$url] = $url;
            if (count($urls) >= MEDIA_MAX_URLS) {
                break 2;
            }
        }
    }

    return array_values($urls);
}

function isAllowedMediaUrl(string $url): bool
{
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return false;
    }

    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $host = (string)($parts['host'] ?? '');
    if (($scheme !== 'http' && $scheme !== 'https') || $host === '') {
        return false;
    }

    if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
        return false;
    }

    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        return isPublicIp($host);
    }

    $resolvedIps = gethostbynamel($host);
    if (!is_array($resolvedIps) || $resolvedIps === []) {
        return false;
    }

    foreach ($resolvedIps as $ip) {
        if (!isPublicIp($ip)) {
            return false;
        }
    }

    return true;
}

function isPublicIp(string $ip): bool
{
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
}

/**
 * @return array{mime_type:string, data:string}
 */
function downloadMediaForGemini(string $url): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('cURL初期化に失敗しました');
    }

    $data = '';
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, MEDIA_DOWNLOAD_TIMEOUT);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
    curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
    curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: image/*,video/*']);
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, static function ($curl, string $chunk) use (&$data): int {
        $newSize = strlen($data) + strlen($chunk);
        if ($newSize > MEDIA_DOWNLOAD_MAX_BYTES) {
            return 0;
        }
        $data .= $chunk;
        return strlen($chunk);
    });

    $ok = curl_exec($ch);
    $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $errorMessage = curl_error($ch);
    curl_close($ch);

    if ($ok === false) {
        throw new RuntimeException('メディアURLの取得に失敗しました: ' . $errorMessage);
    }

    if ($statusCode >= 400) {
        throw new RuntimeException(sprintf('メディアURL取得でHTTPエラー status=%d url=%s', $statusCode, $url));
    }

    if ($data === '') {
        throw new RuntimeException('メディアURLからデータを取得できませんでした: ' . $url);
    }

    if (strlen($data) > MEDIA_DOWNLOAD_MAX_BYTES) {
        throw new RuntimeException('メディアサイズが上限を超えています: ' . $url);
    }

    $mimeType = normalizeMimeType($contentType);
    if ($mimeType === null || !isSupportedMediaMime($mimeType)) {
        throw new RuntimeException('非対応のメディア形式です: ' . ($contentType !== '' ? $contentType : 'unknown'));
    }

    return [
        'mime_type' => $mimeType,
        'data' => $data,
    ];
}

function normalizeMimeType(string $contentType): ?string
{
    $mimeType = trim(strtolower(explode(';', $contentType)[0] ?? ''));
    return $mimeType !== '' ? $mimeType : null;
}

function isSupportedMediaMime(string $mimeType): bool
{
    return str_starts_with($mimeType, 'image/') || str_starts_with($mimeType, 'video/');
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
