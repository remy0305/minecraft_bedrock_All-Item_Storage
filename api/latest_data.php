<?php
// 最新原始資料 API：找出 data 資料夾中最新的庫存 JSON，並連同來源檔名回傳。
header('Content-Type: application/json; charset=utf-8');

// 從 JSON 內容讀 updated_at，轉成 timestamp 做排序用。
function get_payload_updated_at(array $payload): ?int
{
    $updatedAt = $payload['updated_at'] ?? null;
    if (!is_string($updatedAt) || trim($updatedAt) === '') {
        return null;
    }

    $timestamp = strtotime($updatedAt);
    return $timestamp === false ? null : $timestamp;
}

// 定位 data 資料夾；找不到時直接回 404。
$dataDir = realpath(__DIR__ . '/../data');
if ($dataDir === false || !is_dir($dataDir)) {
    http_response_code(404);
    echo json_encode(['error' => 'Cannot find data directory.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$files = glob($dataDir . DIRECTORY_SEPARATOR . '*.json') ?: [];
$candidates = [];

// 掃描所有 JSON，排除 namespace_assets.json，避免把素材表當成庫存資料。
foreach ($files as $file) {
    $name = basename($file);

    if ($name === 'namespace_assets.json') {
        continue;
    }

    if (!is_file($file) || !is_readable($file)) {
        continue;
    }

    $raw = file_get_contents($file);
    $decoded = json_decode($raw, true);

    if (!is_array($decoded)) {
        continue;
    }

    $payloadTimestamp = get_payload_updated_at($decoded);

    $candidates[] = [
        'path' => $file,
        'name' => $name,
        'mtime' => filemtime($file) ?: 0,
        'payload_time' => $payloadTimestamp,
        'data' => $decoded,
    ];
}

// 沒有候選檔案時，回傳明確錯誤給呼叫端。
if (!$candidates) {
    http_response_code(404);
    echo json_encode(['error' => 'No inventory JSON files found in data directory.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 依資料時間排序；時間相同時用檔名排序，確保結果穩定。
usort($candidates, function ($a, $b) {
    $aTime = $a['payload_time'] ?? $a['mtime'];
    $bTime = $b['payload_time'] ?? $b['mtime'];

    if ($aTime === $bTime) {
        return strcmp($b['name'], $a['name']);
    }

    return $bTime <=> $aTime;
});

// 回傳最新檔案的來源資訊與原始 JSON 內容。
$latest = $candidates[0];
$latestTime = $latest['payload_time'] ?? $latest['mtime'];

echo json_encode([
    'source' => 'data',
    'file' => $latest['name'],
    'updated_at' => date('Y-m-d H:i:s', $latestTime),
    'data' => $latest['data'],
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
