<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/prompt_template_service.php';

const GEMINI_API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models';
const GEMINI_TEXT_MODEL = 'gemma-3-27b-it';
const GEMINI_MEDIA_MODEL = 'gemini-3.1-flash-lite-preview';
const GEMINI_INITIAL_MAX_OUTPUT_TOKENS = 4096;
const GEMINI_RETRY_MAX_OUTPUT_TOKENS = 8192;
const MEDIA_DOWNLOAD_TIMEOUT = 60;
const MEDIA_DOWNLOAD_MAX_BYTES = 100 * 1024 * 1024;
const MEDIA_MAX_URLS = 4;
const PER_PAGE = 20;


$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$requestAction = (string)($_POST['action'] ?? $_GET['action'] ?? '');

if ($requestMethod === 'POST' && $requestAction === 'update_review') {
    handleUpdateReview();
    exit;
}
if ($requestMethod === 'POST' && $requestAction === 'update_prompt_template') {
    handleUpdatePromptTemplate();
    exit;
}

if ($requestMethod === 'GET' && $requestAction === 'list_prompt_templates') {
    handleListPromptTemplates();
    exit;
}

$page = max(1, (int)($_GET['page'] ?? 1));
$keyword = trim((string)($_GET['keyword'] ?? ''));
$offset = ($page - 1) * PER_PAGE;
$errorMessage = '';
$rows = [];
$totalRows = 0;
$totalPages = 1;

try {
    $pdo = createPdo();
    $totalRows = fetchTotalCount($pdo, $keyword);
    $totalPages = max(1, (int)ceil($totalRows / PER_PAGE));

    if ($page > $totalPages) {
        $page = $totalPages;
        $offset = ($page - 1) * PER_PAGE;
    }

    $rows = fetchAnswers($pdo, PER_PAGE, $offset, $keyword);
} catch (Throwable $e) {
    $errorMessage = 'データの取得に失敗しました: ' . $e->getMessage();
}

function createPdo(): PDO
{
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);

    return new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function fetchTotalCount(PDO $pdo, string $keyword = ''): int
{
    $sql = 'SELECT COUNT(*) FROM curriculum_answer ca LEFT JOIN curriculum c ON c.id = ca.curriculum_id';
    $params = [];

    if ($keyword !== '') {
        $sql .= " WHERE ca.line_name LIKE :kw OR ca.display_name LIKE :kw OR ca.answer_1 LIKE :kw OR ca.answer_2 LIKE :kw OR ca.q1 LIKE :kw OR ca.q2 LIKE :kw OR ca.q3 LIKE :kw OR ca.mail_address LIKE :kw OR ca.review LIKE :kw OR c.curriculum_name LIKE :kw";
        $params['kw'] = '%' . $keyword . '%';
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

/**
 * @return array<int, array<string, mixed>>
 */
function fetchAnswers(PDO $pdo, int $limit, int $offset, string $keyword = ''): array
{
    $sql = <<<SQL
SELECT
    ca.ca_id,
    ca.curriculum_id,
    c.curriculum_name,
    ca.answer_date,
    ca.line_name,
    ca.display_name,
    ca.answer_1,
    ca.answer_2,
    ca.q1,
    ca.q2,
    ca.q3,
    ca.mail_address,
    ca.review
FROM curriculum_answer ca
LEFT JOIN curriculum c ON c.id = ca.curriculum_id
SQL;

    $params = [];
    if ($keyword !== '') {
        $sql .= <<<SQL
 WHERE
    ca.line_name LIKE :kw OR
    ca.display_name LIKE :kw OR
    ca.answer_1 LIKE :kw OR
    ca.answer_2 LIKE :kw OR
    ca.q1 LIKE :kw OR
    ca.q2 LIKE :kw OR
    ca.q3 LIKE :kw OR
    ca.mail_address LIKE :kw OR
    ca.review LIKE :kw OR
    c.curriculum_name LIKE :kw
SQL;
        $params['kw'] = '%' . $keyword . '%';
    }

    $sql .= <<<SQL
 ORDER BY ca.answer_date DESC
LIMIT :limit OFFSET :offset
SQL;

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue(':' . $key, $value, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

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

function updateReview(PDO $pdo, int $caId, string $review): void
{
    $stmt = $pdo->prepare('UPDATE curriculum_answer SET review = :review WHERE ca_id = :ca_id');
    $stmt->execute([
        'review' => $review,
        'ca_id' => $caId,
    ]);
}

function handleUpdateReview(): void
{
    header('Content-Type: application/json; charset=UTF-8');

    try {
        $caId = (int)($_POST['ca_id'] ?? 0);
        if ($caId <= 0) {
            throw new InvalidArgumentException('ca_id が不正です。');
        }

        $apiKey = defined('GEMINI_API_KEY') ? (string)GEMINI_API_KEY : '';
        if ($apiKey === '') {
            throw new RuntimeException('GEMINI_API_KEY が設定されていません。');
        }

        $pdo = createPdo();
        $stmt = $pdo->prepare('SELECT curriculum_id, answer_1, answer_2 FROM curriculum_answer WHERE ca_id = :ca_id LIMIT 1');
        $stmt->execute(['ca_id' => $caId]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            throw new RuntimeException('対象データが見つかりません。');
        }

        $answer1 = trim((string)($row['answer_1'] ?? ''));
        $answer2 = trim((string)($row['answer_2'] ?? ''));
        $curriculumId = trim((string)($row['curriculum_id'] ?? ''));

        if ($answer1 === '') {
            throw new RuntimeException('answer_1 が空のため更新できません。');
        }


        $review = generateReview($pdo, $curriculumId, $answer1, $answer2, $apiKey);
        if ($review === '') {
            throw new RuntimeException('総評の生成に失敗しました。');
        }

        updateReview($pdo, $caId, $review);

        echo json_encode([
            'ok' => true,
            'ca_id' => $caId,
            'review' => $review,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'message' => $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

function fetchPromptTemplates(PDO $pdo): array
{
    $sql = <<<SQL
SELECT
    p.id,
    p.curriculum_id,
    c.curriculum_name,
    p.version,
    p.template_body,
    p.status,
    p.updated_at
FROM curriculum_prompt_template p
LEFT JOIN curriculum c ON c.id = p.curriculum_id
ORDER BY p.curriculum_id ASC, p.version DESC, p.updated_at DESC
SQL;

    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll();
    return is_array($rows) ? $rows : [];
}

function handleListPromptTemplates(): void
{
    header('Content-Type: application/json; charset=UTF-8');

    try {
        $pdo = createPdo();
        $rows = fetchPromptTemplates($pdo);

        echo json_encode([
            'ok' => true,
            'templates' => $rows,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'message' => $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

function updatePromptTemplate(PDO $pdo, int $templateId, string $templateBody): void
{
    validatePromptTemplate($templateBody);

    $stmt = $pdo->prepare(
        'UPDATE curriculum_prompt_template '
        . 'SET template_body = :template_body, updated_by = :updated_by '
        . 'WHERE id = :id'
    );
    $stmt->execute([
        'template_body' => $templateBody,
        'updated_by' => 'curriculum_answers_list.php',
        'id' => $templateId,
    ]);

    if ($stmt->rowCount() < 1) {
        throw new RuntimeException('更新対象が見つかりません。');
    }
}

function handleUpdatePromptTemplate(): void
{
    header('Content-Type: application/json; charset=UTF-8');

    try {
        $templateId = (int)($_POST['template_id'] ?? 0);
        $templateBody = trim((string)($_POST['template_body'] ?? ''));

        if ($templateId <= 0) {
            throw new InvalidArgumentException('template_id が不正です。');
        }
        if ($templateBody === '') {
            throw new InvalidArgumentException('template_body は必須です。');
        }

        $pdo = createPdo();
        updatePromptTemplate($pdo, $templateId, $templateBody);

        echo json_encode([
            'ok' => true,
            'template_id' => $templateId,
            'template_body' => $templateBody,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'message' => $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function listValue(array $row, string $key): string
{
    return trim((string)($row[$key] ?? ''));
}

function pageUrl(int $targetPage, string $keyword): string
{
    $params = ['page' => $targetPage];
    if ($keyword !== '') {
        $params['keyword'] = $keyword;
    }

    return '?' . http_build_query($params);
}
?><!doctype html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>curriculum_answer 一覧</title>
    <link rel="icon" type="image/png" href="./img/icon.png">
    <link rel="apple-touch-icon" href="./img/icon.png">
    <link rel="stylesheet" href="./curriculum_answers_list.css?v=<?= time(); ?>">
</head>
<body>
<div class="page">
    <header class="header">
        <h1>curriculum_answer 一覧</h1>
        <p>総件数: <?= number_format($totalRows); ?>件 / <?= $page; ?>ページ目</p>
        <form method="get" class="search-form">
            <label>
                キーワード検索
                <input type="text" name="keyword" value="<?= h($keyword); ?>" placeholder="名前・提出物・総評・カリキュラム名">
            </label>
            <input type="hidden" name="page" value="1">
            <button type="submit">検索</button>
            <button type="button" id="openPromptTemplateModal">プロンプト編集</button>
            <?php if ($keyword !== ''): ?>
                <a href="?" class="clear-link">クリア</a>
            <?php endif; ?>
        </form>
    </header>

    <?php if ($errorMessage !== ''): ?>
        <div class="error-box"><?= h($errorMessage); ?></div>
    <?php else: ?>
        <div class="list-wrap">
            <?php if ($rows === []): ?>
                <p class="empty">データがありません。</p>
            <?php else: ?>
                <ul class="answer-list">
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $caId = (int)($row['ca_id'] ?? 0);
                        $review = listValue($row, 'review');
                        $items = [
                            '回答日' => listValue($row, 'answer_date'),
                            'カリキュラム名' => listValue($row, 'curriculum_name'),
                            'LINE名' => listValue($row, 'line_name'),
                            'システム表示名' => listValue($row, 'display_name'),
                            '提出物1' => listValue($row, 'answer_1'),
                            '提出物2' => listValue($row, 'answer_2'),
                            '総評' => $review !== '' ? $review : '（未設定）',
                        ];
                        ?>
                        <li class="answer-card" data-ca-id="<?= $caId; ?>">
                            <dl class="answer-items">
                                <?php foreach ($items as $label => $value): ?>
                                    <div class="answer-item">
                                        <dt><?= h($label); ?></dt>
                                        <dd>
                                            <button
                                                type="button"
                                                class="value-btn js-open-value"
                                                data-title="<?= h($label); ?>"
                                                data-value="<?= h($value); ?>"
                                            ><?= h($value !== '' ? $value : '（未設定）'); ?></button>
                                        </dd>
                                    </div>
                                <?php endforeach; ?>
                            </dl>
                            <div class="card-actions">
                                <button type="button" class="api-btn js-api-btn" data-ca-id="<?= $caId; ?>">API</button>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <nav class="pagination" aria-label="ページネーション">
            <?php
            $start = max(1, $page - 2);
            $end = min($totalPages, $page + 2);
            ?>

            <?php if ($page > 1): ?>
                <a href="<?= h(pageUrl($page - 1, $keyword)); ?>" class="pager">← 前へ</a>
            <?php endif; ?>

            <?php for ($i = $start; $i <= $end; $i++): ?>
                <a href="<?= h(pageUrl($i, $keyword)); ?>" class="pager <?= $i === $page ? 'is-active' : ''; ?>"><?= $i; ?></a>
            <?php endfor; ?>

            <?php if ($page < $totalPages): ?>
                <a href="<?= h(pageUrl($page + 1, $keyword)); ?>" class="pager">次へ →</a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
</div>

<div class="modal" id="reviewModal" aria-hidden="true">
    <div class="modal__overlay js-close-modal"></div>
    <div class="modal__content" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
        <h2 id="modalTitle">全文表示</h2>
        <pre id="modalReviewText"></pre>
        <div class="modal__actions">
            <button type="button" class="copy-btn js-copy-modal-text">コピー</button>
            <button type="button" class="close-btn js-close-modal">閉じる</button>
        </div>
    </div>
</div>

<div class="modal" id="promptTemplateModal" aria-hidden="true">
    <div class="modal__overlay js-close-prompt-template-modal"></div>
    <div class="modal__content" role="dialog" aria-modal="true" aria-labelledby="promptTemplateModalTitle">
        <h2 id="promptTemplateModalTitle">プロンプトテンプレート編集</h2>
        <div class="prompt-template-edit">
            <label class="prompt-template-label" for="promptTemplateSelect">テンプレート選択</label>
            <select id="promptTemplateSelect"></select>
            <p id="promptTemplateMeta" class="prompt-template-meta"></p>
            <label class="prompt-template-label" for="promptTemplateBody">template_body</label>
            <textarea id="promptTemplateBody" rows="12"></textarea>
            <p id="promptTemplateMessage" class="prompt-template-message"></p>
        </div>
        <div class="modal__actions">
            <button type="button" class="api-btn" id="savePromptTemplateButton">保存</button>
            <button type="button" class="close-btn js-close-prompt-template-modal">閉じる</button>
        </div>
    </div>
</div>
<script src="./curriculum_answers_list.js?v=<?= time(); ?>"></script>
</body>
</html>
