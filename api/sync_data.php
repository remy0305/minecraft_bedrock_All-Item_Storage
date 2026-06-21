<?php
/**
 * JSON 匯入 MySQL API。
 *
 * 這支 API 是「寫入資料庫」用，不是資料查詢 API。
 *
 * 功能：
 * 1. 掃描 ../data/*.json。
 * 2. 跳過 namespace_assets.json，因為它是物品素材資料，不是農場庫存。
 * 3. 將每個農場 JSON 匯入 farm。
 * 4. 將每個物品 NamespaceID 匯入 item_master。
 * 5. 將每個農場的物品數量匯入 farm_inventory。
 * 6. amount 採用 JSON 數值覆蓋，不累加。
 * 7. 如果某 farm 以前有某 item，但這次 JSON 沒有，就從 farm_inventory 刪掉。
 *
 * 呼叫方式：
 * POST /minecraft_badrock/api/sync_data.php
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json_error('POST method required.', 405);
}

/**
 * 將任意值轉成 trim 後的字串。
 */
function clean_string(mixed $value): string
{
    return trim((string) ($value ?? ''));
}

/**
 * 讀取 namespace_assets.json。
 *
 * 這個檔案提供物品中文名稱與圖片路徑。
 * 匯入 item_master 時，如果 NamespaceID 有對應資料，就一起寫入 name_zh / image。
 */
function load_namespace_assets(string $dataDir): array
{
    $path = $dataDir . DIRECTORY_SEPARATOR . 'namespace_assets.json';
    if (!is_file($path) || !is_readable($path)) {
        return [];
    }

    $decoded = json_decode((string) file_get_contents($path), true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        return [];
    }

    $assets = [];
    foreach ($decoded as $namespaceId => $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $id = clean_string($entry['NamespaceID'] ?? $entry['namespace_id'] ?? $entry['id'] ?? $namespaceId);
        if ($id === '') {
            continue;
        }

        $assets[$id] = [
            'name_zh' => clean_string($entry['name_zh'] ?? $entry['zh_name'] ?? $entry['name'] ?? ''),
            'image' => clean_string($entry['image'] ?? $entry['image_path'] ?? $entry['texture'] ?? $entry['icon'] ?? ''),
        ];
    }

    return $assets;
}

/**
 * 驗證並整理單一農場 JSON。
 *
 * 支援格式：
 * {
 *   "farm_code": "...",
 *   "farm_name": "...",
 *   "description": "...",
 *   "updated_at": "...",
 *   "items": [
 *     {"NamespaceID": "minecraft:bone", "amount": 10}
 *   ]
 * }
 */
function normalize_farm_payload(mixed $decoded, string $filename): array
{
    if (!is_array($decoded) || array_is_list($decoded)) {
        throw new RuntimeException("{$filename}: top-level JSON must be a farm object.");
    }

    $farmCode = clean_string($decoded['farm_code'] ?? '');
    if ($farmCode === '') {
        throw new RuntimeException("{$filename}: missing farm_code.");
    }

    if (!isset($decoded['items']) || !is_array($decoded['items']) || !array_is_list($decoded['items'])) {
        throw new RuntimeException("{$filename}: items must be an array.");
    }

    // 用 NamespaceID 當 key，可避免同一個 JSON 裡重複 item 造成重複寫入。
    $items = [];
    foreach ($decoded['items'] as $index => $item) {
        if (!is_array($item) || array_is_list($item)) {
            throw new RuntimeException("{$filename}: item {$index} must be an object.");
        }

        $namespaceId = clean_string($item['NamespaceID'] ?? $item['namespace_id'] ?? $item['namespace'] ?? $item['id'] ?? '');
        if ($namespaceId === '') {
            throw new RuntimeException("{$filename}: item {$index} missing NamespaceID.");
        }

        if (!array_key_exists('amount', $item) || !is_numeric($item['amount'])) {
            throw new RuntimeException("{$filename}: item {$namespaceId} amount must be numeric.");
        }

        $items[$namespaceId] = [
            'NamespaceID' => $namespaceId,
            'amount' => (int) $item['amount'],
            'updated_at' => clean_string($item['updated_at'] ?? $decoded['updated_at'] ?? ''),
        ];
    }

    return [
        'farm_code' => $farmCode,
        'farm_name' => clean_string($decoded['farm_name'] ?? $farmCode),
        'description' => clean_string($decoded['description'] ?? ''),
        'updated_at' => clean_string($decoded['updated_at'] ?? ''),
        'items' => array_values($items),
    ];
}

/**
 * 空字串進資料庫時改成 NULL。
 */
function nullable_string(string $value): ?string
{
    return $value === '' ? null : $value;
}

$dataDir = realpath(__DIR__ . '/../data');
if ($dataDir === false || !is_dir($dataDir)) {
    send_json_error('Cannot find data directory.', 404);
}

$assets = load_namespace_assets($dataDir);
$files = glob($dataDir . DIRECTORY_SEPARATOR . '*.json') ?: [];
$farms = [];
$errors = [];

// 逐一讀取 data/*.json，跳過 namespace_assets.json。
foreach ($files as $file) {
    $filename = basename($file);
    if ($filename === 'namespace_assets.json') {
        continue;
    }

    if (!is_readable($file)) {
        $errors[] = "{$filename}: file is not readable.";
        continue;
    }

    $decoded = json_decode((string) file_get_contents($file), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $errors[] = "{$filename}: invalid JSON - " . json_last_error_msg();
        continue;
    }

    try {
        $farm = normalize_farm_payload($decoded, $filename);
        $farms[$farm['farm_code']] = $farm;
    } catch (Throwable $error) {
        // 單一 JSON 檔錯誤不會中斷整批同步，只記錄到 errors。
        $errors[] = $error->getMessage();
    }
}

$connection = get_db_connection();

// farm 用 farm_code 當唯一鍵；重複時更新名稱、描述與時間。
$farmUpsert = $connection->prepare("
    INSERT INTO farm (farm_code, farm_name, description, updated_at)
    VALUES (?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
      farm_name = VALUES(farm_name),
      description = VALUES(description),
      updated_at = VALUES(updated_at)
");

// 寫入 farm 後再用 farm_code 查 farm_id，讓 farm_inventory 使用外鍵。
$farmSelect = $connection->prepare("
    SELECT farm_id
    FROM farm
    WHERE farm_code = ?
    LIMIT 1
");

// item_master 只保存物品主檔；如果素材檔有 name_zh / image 就補上。
$itemUpsert = $connection->prepare("
    INSERT INTO item_master (NamespaceID, name_zh, image)
    VALUES (?, ?, ?)
    ON DUPLICATE KEY UPDATE
      name_zh = COALESCE(NULLIF(VALUES(name_zh), ''), name_zh),
      image = COALESCE(NULLIF(VALUES(image), ''), image)
");

// farm_inventory 用複合主鍵 (farm_id, NamespaceID)；重複時覆蓋 amount。
$inventoryUpsert = $connection->prepare("
    INSERT INTO farm_inventory (farm_id, NamespaceID, amount, updated_at)
    VALUES (?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
      amount = VALUES(amount),
      updated_at = VALUES(updated_at)
");

// 如果某 farm 的 JSON items 是空陣列，就清掉該 farm 的全部庫存。
$inventoryDeleteAll = $connection->prepare("
    DELETE FROM farm_inventory
    WHERE farm_id = ?
");

if (!$farmUpsert || !$farmSelect || !$itemUpsert || !$inventoryUpsert || !$inventoryDeleteAll) {
    send_json_error('Failed to prepare SQL statements: ' . $connection->error);
}

$farmsImported = 0;
$itemsImported = 0;
$inventoryRows = 0;

try {
    $connection->begin_transaction();

    foreach (array_values($farms) as $farm) {
        $farmCode = $farm['farm_code'];
        $farmName = $farm['farm_name'];
        $description = nullable_string($farm['description']);
        $farmUpdatedAt = nullable_string($farm['updated_at']);

        $farmUpsert->bind_param('ssss', $farmCode, $farmName, $description, $farmUpdatedAt);
        if (!$farmUpsert->execute()) {
            throw new RuntimeException("Failed to upsert farm {$farmCode}: {$farmUpsert->error}");
        }

        $farmSelect->bind_param('s', $farmCode);
        if (!$farmSelect->execute()) {
            throw new RuntimeException("Failed to query farm_id for {$farmCode}: {$farmSelect->error}");
        }

        $farmResult = $farmSelect->get_result();
        $farmRow = $farmResult ? $farmResult->fetch_assoc() : null;
        if (!$farmRow) {
            throw new RuntimeException("Cannot find farm_id for {$farmCode}.");
        }

        $farmId = (int) $farmRow['farm_id'];
        $farmsImported++;

        // 記住這次 JSON 裡存在的 NamespaceID，後面用來刪除舊殘留。
        $namespaceIds = [];

        foreach ($farm['items'] as $item) {
            $namespaceId = $item['NamespaceID'];
            $asset = $assets[$namespaceId] ?? [];
            $nameZh = $asset['name_zh'] ?? '';
            $image = $asset['image'] ?? '';

            $itemUpsert->bind_param('sss', $namespaceId, $nameZh, $image);
            if (!$itemUpsert->execute()) {
                throw new RuntimeException("Failed to upsert item {$namespaceId}: {$itemUpsert->error}");
            }
            $itemsImported++;

            $amount = (int) $item['amount'];
            $itemUpdatedAt = nullable_string($item['updated_at'] ?: $farm['updated_at']);

            $inventoryUpsert->bind_param('isis', $farmId, $namespaceId, $amount, $itemUpdatedAt);
            if (!$inventoryUpsert->execute()) {
                throw new RuntimeException("Failed to upsert inventory {$farmCode} / {$namespaceId}: {$inventoryUpsert->error}");
            }

            $inventoryRows++;
            $namespaceIds[] = $namespaceId;
        }

        if ($namespaceIds === []) {
            $inventoryDeleteAll->bind_param('i', $farmId);
            if (!$inventoryDeleteAll->execute()) {
                throw new RuntimeException("Failed to clear inventory for {$farmCode}: {$inventoryDeleteAll->error}");
            }
            continue;
        }

        // 刪除同一個 farm 以前有、但這次 JSON 沒有的物品，避免舊資料殘留。
        $placeholders = implode(',', array_fill(0, count($namespaceIds), '?'));
        $deleteSql = "
            DELETE FROM farm_inventory
            WHERE farm_id = ?
              AND NamespaceID NOT IN ({$placeholders})
        ";
        $deleteStmt = $connection->prepare($deleteSql);
        if (!$deleteStmt) {
            throw new RuntimeException('Failed to prepare stale inventory delete: ' . $connection->error);
        }

        $types = 'i' . str_repeat('s', count($namespaceIds));
        $deleteParams = array_merge([$farmId], $namespaceIds);
        $deleteStmt->bind_param($types, ...$deleteParams);
        if (!$deleteStmt->execute()) {
            throw new RuntimeException("Failed to delete stale inventory for {$farmCode}: {$deleteStmt->error}");
        }
        $deleteStmt->close();
    }

    $connection->commit();
} catch (Throwable $error) {
    $connection->rollback();
    $connection->close();
    send_json_error($error->getMessage(), 500);
}

$connection->close();

echo json_encode([
    'success' => true,
    'farms_imported' => $farmsImported,
    'items_imported' => $itemsImported,
    'inventory_rows' => $inventoryRows,
    'errors' => $errors,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
