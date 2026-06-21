<?php
/**
 * 查詢 data/ 目錄中最新的一份農場 JSON。
 *
 * 這支 API 是「檔案查詢」API，不查 MySQL。
 * 目前前端主畫面不依賴它，主畫面讀的是 api/inventory.php。
 *
 * 用途：
 * - 測試 data/*.json 是否存在。
 * - 查看目前 data/ 裡最新更新的單一 JSON payload。
 *
 * 注意：
 * - namespace_assets.json 會被跳過。
 * - 這支不會匯入資料庫。
 */
header('Content-Type: application/json; charset=utf-8');

function get_payload_updated_at(array $payload): ?int
{
    $updatedAt = $payload['updated_at'] ?? null;
    if (!is_string($updatedAt) || trim($updatedAt) === '') {
        return null;
    }

    $timestamp = strtotime($updatedAt);
    return $timestamp === false ? null : $timestamp;
}

$dataDir = realpath(__DIR__ . '/../data');
if ($dataDir === false || !is_dir($dataDir)) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Cannot find data directory.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$files = glob($dataDir . DIRECTORY_SEPARATOR . '*.json') ?: [];
$candidates = [];

foreach ($files as $file) {
    $name = basename($file);
    if ($name === 'namespace_assets.json') {
        continue;
    }

    if (!is_file($file) || !is_readable($file)) {
        continue;
    }

    $decoded = json_decode((string) file_get_contents($file), true);
    if (!is_array($decoded)) {
        continue;
    }

    $candidates[] = [
        'name' => $name,
        'mtime' => filemtime($file) ?: 0,
        'payload_time' => get_payload_updated_at($decoded),
        'data' => $decoded,
    ];
}

if (!$candidates) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'No inventory JSON files found.'], JSON_UNESCAPED_UNICODE);
    exit;
}

usort($candidates, function (array $a, array $b): int {
    $aTime = $a['payload_time'] ?? $a['mtime'];
    $bTime = $b['payload_time'] ?? $b['mtime'];
    return $aTime === $bTime ? strcmp($b['name'], $a['name']) : $bTime <=> $aTime;
});

$latest = $candidates[0];
$latestTime = $latest['payload_time'] ?? $latest['mtime'];

echo json_encode([
    'success' => true,
    'source' => 'data',
    'file' => $latest['name'],
    'updated_at' => date('Y-m-d H:i:s', $latestTime),
    'data' => $latest['data'],
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
