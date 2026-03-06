<?php

$targetUrl = 'https://voracious-cherry-ec4.notion.site/NATURE-POP-99588b16ab9847369109d64584a5ecdd?pvs=143';
$apiToken  = '8wbSs_0XMkImX7dKiV8CkgBLeT4egrhMFdZ9c19j';
$accountId = 'b637ccd8d4232960555b263a9390593e';

// 保存先
$saveDir = __DIR__ . '/captures';
if (!is_dir($saveDir)) {
    mkdir($saveDir, 0755, true);
}

// PDFファイル名
$fileName = 'capture_' . date('Ymd_His') . '.pdf';
$savePath = $saveDir . '/' . $fileName;

// PDFエンドポイント
$endpoint = "https://api.cloudflare.com/client/v4/accounts/{$accountId}/browser-rendering/pdf";

// NotionみたいなJS多めページ向けに少し長めに待つ
$postData = [
    'url' => $targetUrl,

    'gotoOptions' => [
        'waitUntil' => 'networkidle2',
        'timeout'   => 60000
    ],

    'waitForTimeout' => 8000,
    'actionTimeout'  => 120000,

    // PDF設定
    'pdfOptions' => [
        'format' => 'a4',
        'printBackground' => true,
        'preferCSSPageSize' => true,
        'landscape' => false
    ]
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
    exit('PDF保存に失敗しました');
}

echo "保存完了\n";
echo "保存先: " . $savePath . "\n";