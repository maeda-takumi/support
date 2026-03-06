<?php

$targetUrl = 'https://www.notion.so/30fcb0887e8780c3a2a8fb8a17daf831?source=copy_link';
$apiToken  = '8wbSs_0XMkImX7dKiV8CkgBLeT4egrhMFdZ9c19j';
$accountId = 'b637ccd8d4232960555b263a9390593e';

$saveDir = __DIR__ . '/captures';
if (!is_dir($saveDir)) {
    mkdir($saveDir, 0755, true);
}

$fileName = 'capture_' . date('Ymd_His') . '.png';
$savePath = $saveDir . '/' . $fileName;

$endpoint = "https://api.cloudflare.com/client/v4/accounts/{$accountId}/browser-rendering/screenshot";

$postData = [
    'url' => $targetUrl,

    // ページ読み込み待ち設定
    'gotoOptions' => [
        'waitUntil' => 'networkidle2',
        'timeout'   => 60000
    ],

    // 読み込み後にさらに少し待つ
    'waitForTimeout' => 5000,

    // スクショ設定
    'screenshotOptions' => [
        'fullPage' => true
    ],

    // スクショ処理自体の待機上限
    'actionTimeout' => 120000
];

$ch = curl_init($endpoint);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $apiToken,
        'Content-Type: application/json',
    ],
    CURLOPT_POSTFIELDS => json_encode($postData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    CURLOPT_TIMEOUT => 180,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($response === false || $curlError) {
    exit('cURLエラー: ' . $curlError);
}

if ($httpCode !== 200) {
    exit("HTTPエラー: {$httpCode}\nレスポンス:\n" . $response);
}

if (file_put_contents($savePath, $response) === false) {
    exit('画像保存に失敗しました');
}

echo "保存完了: " . $savePath;