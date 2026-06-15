<?php
// 庫存讀取 API：前端 app.js 會 fetch 這支，取得目前要顯示的庫存 JSON。
header('Content-Type: application/json; charset=utf-8');

// 讀取 JSON 內的 updated_at，轉成 timestamp，方便判斷哪個檔案最新。
function get_payload_updated_at(array $payload): ?int
{
    $updatedAt = $payload['updated_at'] ?? null;
    if (!is_string($updatedAt) || trim($updatedAt) === '') {
        return null;
    }

    $timestamp = strtotime($updatedAt);
    return $timestamp === false ? null : $timestamp;
}

// 將不同形狀的 JSON 正規化成前端固定使用的 farms/items 陣列格式。
function normalize_farms(array $payload): array
{
    $farms = array_is_list($payload) ? $payload : [$payload];

    return array_values(array_filter(array_map(function ($farm) {
        if (!is_array($farm) || empty($farm['farm_code']) || !isset($farm['items']) || !is_array($farm['items'])) {
            return null;
        }

        $farmUpdatedAt = isset($farm['updated_at']) && $farm['updated_at'] !== ''
            ? (string) $farm['updated_at']
            : '';

        return [
            'farm_code' => (string) $farm['farm_code'],
            'farm_name' => isset($farm['farm_name']) && $farm['farm_name'] !== '' ? (string) $farm['farm_name'] : (string) $farm['farm_code'],
            'description' => isset($farm['description']) ? (string) $farm['description'] : '',
            'updated_at' => $farmUpdatedAt,
            'items' => array_values(array_filter(array_map(function ($item) use ($farmUpdatedAt) {
                if (!is_array($item) || empty($item['NamespaceID'])) {
                    return null;
                }

                return [
                    'NamespaceID' => (string) $item['NamespaceID'],
                    'amount' => (int) ($item['amount'] ?? 0),
                    'updated_at' => isset($item['updated_at']) && $item['updated_at'] !== '' ? (string) $item['updated_at'] : $farmUpdatedAt,
                ];
            }, $farm['items']))),
        ];
    }, $farms)));
}

// 找到 data 資料夾，這裡放外部產生或匯入的庫存 JSON 檔案。
$dataDir = realpath(__DIR__ . '/../data');
if ($dataDir === false || !is_dir($dataDir)) {
    http_response_code(404);
    echo json_encode(['error' => 'Cannot find data directory.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$files = glob($dataDir . DIRECTORY_SEPARATOR . '*.json') ?: [];
$candidates = [];

// 掃描所有 JSON 檔，排除素材對照表，只留下可作為庫存來源的檔案。
foreach ($files as $file) {
    $name = basename($file);
    if ($name === 'namespace_assets.json' || !is_file($file) || !is_readable($file)) {
        continue;
    }

    $raw = file_get_contents($file);
    $decoded = json_decode($raw, true);
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

// 如果沒有任何可用庫存檔，回傳空陣列，前端會顯示空狀態。
if (!$candidates) {
    echo json_encode([], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// 依 JSON 內 updated_at 或檔案修改時間排序，取最新的一份庫存資料。
usort($candidates, function ($a, $b) {
    $aTime = $a['payload_time'] ?? $a['mtime'];
    $bTime = $b['payload_time'] ?? $b['mtime'];

    if ($aTime === $bTime) {
        return strcmp($b['name'], $a['name']);
    }

    return $bTime <=> $aTime;
});

// 回傳前端需要的庫存資料。
echo json_encode(normalize_farms($candidates[0]['data']), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
