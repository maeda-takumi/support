<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

const GEMINI_API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models';
const GEMINI_MODEL = 'gemma-3-27b-it';
const PER_PAGE = 20;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'update_review') {
    handleUpdateReview();
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

    return trim(extractGeminiText($data));
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
        $stmt = $pdo->prepare('SELECT answer_1, answer_2 FROM curriculum_answer WHERE ca_id = :ca_id LIMIT 1');
        $stmt->execute(['ca_id' => $caId]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            throw new RuntimeException('対象データが見つかりません。');
        }

        $answer1 = trim((string)($row['answer_1'] ?? ''));
        $answer2 = trim((string)($row['answer_2'] ?? ''));

        if ($answer1 === '' || $answer2 === '') {
            throw new RuntimeException('answer_1 または answer_2 が空のため更新できません。');
        }

        if (containsUrl($answer1) || containsUrl($answer2)) {
            throw new RuntimeException('URLを含むため更新対象外です。');
        }

        $review = generateReview($answer1, $answer2, $apiKey);
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
        <button type="button" class="close-btn js-close-modal">閉じる</button>
    </div>
</div>

<script src="./curriculum_answers_list.js?v=<?= time(); ?>"></script>
</body>
</html>
