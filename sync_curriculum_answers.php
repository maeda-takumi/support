<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

const SHEETS_SCOPE = 'https://www.googleapis.com/auth/spreadsheets.readonly';
const TOKEN_URI = 'https://oauth2.googleapis.com/token';
const GOOGLE_API_BASE = 'https://sheets.googleapis.com/v4/spreadsheets';

/**
 * Cron execution entrypoint.
 */
function main(): int
{
    logMessage('INFO', '処理開始: curriculum_answer 同期');

    try {
        $pdo = createPdo();
    } catch (Throwable $e) {
        logMessage('ERROR', 'DB接続に失敗: ' . $e->getMessage());
        return 1;
    }

    try {
        $credentials = loadServiceAccountCredentials();
        $accessToken = getGoogleAccessToken($credentials);
    } catch (Throwable $e) {
        logMessage('ERROR', 'Google認証に失敗: ' . $e->getMessage());
        return 1;
    }

    $curriculums = fetchCurriculums($pdo);
    logMessage('INFO', '処理対象 curriculum 件数: ' . count($curriculums));

    $totalInserted = 0;
    $totalUpdated = 0;
    $totalSkipped = 0;
    $sheetErrors = 0;

    foreach ($curriculums as $curriculum) {
        $curriculumName = (string)$curriculum['curriculum_name'];
        $sheetKey = (string)$curriculum['sheet_key'];
        $sheetName = (string)$curriculum['sheet_name'];

        try {
            $map = json_decode((string)$curriculum['map'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($map) || $map === []) {
                throw new RuntimeException('map が不正です');
            }

            $values = fetchSheetValues($sheetKey, $sheetName, $accessToken);
            $curriculumId = (string)$curriculum['id'];
            [$inserted, $updated, $skipped] = upsertSheetRows($pdo, $values, $map, $curriculumId);

            $totalInserted += $inserted;
            $totalUpdated += $updated;
            $totalSkipped += $skipped;

            logMessage(
                'INFO',
                sprintf(
                    'シート同期成功: %s (insert=%d, update=%d, skip=%d)',
                    $curriculumName,
                    $inserted,
                    $updated,
                    $skipped
                )
            );
        } catch (Throwable $e) {
            $sheetErrors++;
            logMessage(
                'ERROR',
                sprintf('シート同期失敗: %s (%s)', $curriculumName, $e->getMessage())
            );
            continue;
        }
    }

    logMessage(
        'INFO',
        sprintf(
            '処理終了: insert=%d, update=%d, skip=%d, sheet_errors=%d',
            $totalInserted,
            $totalUpdated,
            $totalSkipped,
            $sheetErrors
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
function fetchCurriculums(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT id, curriculum_name, sheet_key, sheet_name, map FROM curriculum ORDER BY id ASC');
    $rows = $stmt->fetchAll();

    return is_array($rows) ? $rows : [];
}

/**
 * @return array{client_email: string, private_key: string}
 */
function loadServiceAccountCredentials(): array
{
    $path = getenv('GOOGLE_APPLICATION_CREDENTIALS') ?: __DIR__ . '/service-account.json';
    if (!is_string($path) || $path === '' || !is_file($path)) {
        throw new RuntimeException('サービスアカウントJSONが見つかりません: ' . (string)$path);
    }

    $json = file_get_contents($path);
    if ($json === false) {
        throw new RuntimeException('サービスアカウントJSONの読み込みに失敗しました');
    }

    $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data) || empty($data['client_email']) || empty($data['private_key'])) {
        throw new RuntimeException('サービスアカウントJSONの形式が不正です');
    }

    return [
        'client_email' => (string)$data['client_email'],
        'private_key' => (string)$data['private_key'],
    ];
}

/**
 * @param array{client_email: string, private_key: string} $credentials
 */
function getGoogleAccessToken(array $credentials): string
{
    $now = time();
    $header = base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
    $claimSet = base64UrlEncode(json_encode([
        'iss' => $credentials['client_email'],
        'scope' => SHEETS_SCOPE,
        'aud' => TOKEN_URI,
        'iat' => $now,
        'exp' => $now + 3600,
    ], JSON_THROW_ON_ERROR));

    $unsignedToken = $header . '.' . $claimSet;
    $signature = '';
    $signed = openssl_sign($unsignedToken, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256);
    if ($signed !== true) {
        throw new RuntimeException('JWT署名に失敗しました');
    }

    $assertion = $unsignedToken . '.' . base64UrlEncode($signature);

    $response = httpPostForm(TOKEN_URI, [
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => $assertion,
    ]);

    $data = json_decode($response['body'], true);
    if (!is_array($data) || empty($data['access_token'])) {
        throw new RuntimeException('アクセストークンの取得に失敗しました: ' . $response['body']);
    }

    return (string)$data['access_token'];
}

/**
 * @return array<int, array<int, string>>
 */
function fetchSheetValues(string $sheetKey, string $sheetName, string $accessToken): array
{
    $range = rawurlencode(sprintf("'%s'", $sheetName));
    $url = sprintf(
        '%s/%s/values/%s?majorDimension=ROWS',
        GOOGLE_API_BASE,
        rawurlencode($sheetKey),
        $range
    );

    $response = httpGet($url, [
        'Authorization: Bearer ' . $accessToken,
    ]);

    $data = json_decode($response['body'], true);
    if (!is_array($data)) {
        throw new RuntimeException('スプレッドシートレスポンスが不正です');
    }

    $values = $data['values'] ?? [];
    if (!is_array($values)) {
        return [];
    }

    return $values;
}

/**
 * @param array<int, array<int, string>> $sheetRows
 * @param array<string, int> $map
 * @return array{0:int,1:int,2:int}
 */
function upsertSheetRows(PDO $pdo, array $sheetRows, array $map, string $curriculumId): array
{
    $requiredColumns = [
        'line_user_id', 'answer_id', 'answer_date', 'answer_id_user', 'line_name', 'display_name',
        'answer_1', 'q1', 'q2', 'q3', 'mail_address',
    ];

    foreach ($requiredColumns as $column) {
        if (!isset($map[$column])) {
            throw new RuntimeException('mapに必要なカラムがありません: ' . $column);
        }
    }

    $existsSql = 'SELECT 1 FROM curriculum_answer WHERE answer_id=:answer_id LIMIT 1';
    $updateSql = 'UPDATE curriculum_answer SET '
        . 'line_user_id=:line_user_id, answer_date=:answer_date, answer_id_user=:answer_id_user, '
        . 'line_name=:line_name, display_name=:display_name, answer_1=:answer_1, answer_2=:answer_2, '
        . 'q1=:q1, q2=:q2, q3=:q3, mail_address=:mail_address, curriculum_id=:curriculum_id '
        . 'WHERE answer_id=:answer_id';

    $insertSql = 'INSERT INTO curriculum_answer '
        . '(line_user_id, answer_id, answer_date, answer_id_user, line_name, display_name, answer_1, answer_2, q1, q2, q3, mail_address, curriculum_id) '
        . 'VALUES '
        . '(:line_user_id, :answer_id, :answer_date, :answer_id_user, :line_name, :display_name, :answer_1, :answer_2, :q1, :q2, :q3, :mail_address, :curriculum_id)';

    $existsStmt = $pdo->prepare($existsSql);
    $updateStmt = $pdo->prepare($updateSql);
    $insertStmt = $pdo->prepare($insertSql);

    $inserted = 0;
    $updated = 0;
    $skipped = 0;

    // 先頭行はヘッダー想定のためスキップ
    foreach (array_slice($sheetRows, 1) as $row) {
        if (!is_array($row) || isEmptyRow($row)) {
            $skipped++;
            continue;
        }

        $params = [];
        foreach ($requiredColumns as $column) {
            $index = (int)$map[$column] - 1;
            $value = $row[$index] ?? null;
            $params[$column] = normalizeCellValue($column, $value);
        }

        if (isset($map['answer_2'])) {
            $answer2Index = (int)$map['answer_2'] - 1;
            $params['answer_2'] = normalizeCellValue('answer_2', $row[$answer2Index] ?? null);
        } else {
            $params['answer_2'] = null;
        }

        $params['curriculum_id'] = $curriculumId;

        if (isset($map['answer_2'])) {
            $answer2Index = (int)$map['answer_2'] - 1;
            $params['answer_2'] = normalizeCellValue('answer_2', $row[$answer2Index] ?? null);
        } else {
            $params['answer_2'] = null;
        }

        $params['curriculum_id'] = $curriculumId;

        if ($params['answer_id'] === null || $params['curriculum_id'] === '') {
            $skipped++;
            continue;
        }

        $existsStmt->execute(['answer_id' => $params['answer_id']]);
        $exists = $existsStmt->fetchColumn();

        if ($exists !== false) {
            $updateStmt->execute($params);
            $updated++;
            continue;
        }

        $insertStmt->execute($params);
        $inserted++;
    }

    return [$inserted, $updated, $skipped];
}

/**
 * @param array<int, mixed> $row
 */
function isEmptyRow(array $row): bool
{
    foreach ($row as $value) {
        if (trim((string)$value) !== '') {
            return false;
        }
    }

    return true;
}

/**
 * @param mixed $value
 * @return mixed
 */
function normalizeCellValue(string $column, $value)
{
    if ($value === null) {
        return null;
    }

    $trimmed = trim((string)$value);
    if ($trimmed === '') {
        return null;
    }

    if ($column === 'answer_date') {
        return normalizeAnswerDate($trimmed);
    }

    return $trimmed;
}

function normalizeAnswerDate(string $value): ?string
{
    $cleaned = preg_replace('/\s*\([^)]*\)\s*/u', ' ', $value);
    $cleaned = trim((string)$cleaned);

    $formats = [
        'Y/m/d H:i:s',
        'Y/m/d H:i',
        'Y-m-d H:i:s',
        'Y-m-d H:i',
        'Y/m/d',
        'Y-m-d',
    ];

    foreach ($formats as $format) {
        $dateTime = DateTime::createFromFormat($format, $cleaned);
        if ($dateTime instanceof DateTime) {
            return $dateTime->format('Y-m-d H:i:s');
        }
    }

    return null;
}

function base64UrlEncode(string $input): string
{
    return rtrim(strtr(base64_encode($input), '+/', '-_'), '=');
}

/**
 * @param array<string, string> $headers
 * @return array{status:int, body:string}
 */
function httpGet(string $url, array $headers = []): array
{
    return httpRequest('GET', $url, $headers, null);
}

/**
 * @param array<string, scalar> $formData
 * @return array{status:int, body:string}
 */
function httpPostForm(string $url, array $formData): array
{
    return httpRequest(
        'POST',
        $url,
        ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query($formData)
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
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
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
