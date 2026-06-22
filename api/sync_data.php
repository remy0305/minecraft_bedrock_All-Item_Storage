<?php
/**
 * JSON -> MySQL 同步器
 *
 * 流程：
 * 1. 只接受 POST。
 * 2. 讀取 ../data/*.json，跳過 namespace_assets.json。
 * 3. 每個 JSON 代表一個 farm。
 * 4. farm 用 farm_code 新增或更新。
 * 5. item_master 用 NamespaceID 新增或更新，name_zh / image 來自 namespace_assets.json。
 * 6. 每次同步某個 farm 時，先刪掉該 farm 原本的 farm_inventory，再依 JSON 重建庫存。
 * 7. amount 以 JSON 數值覆蓋，不累加。
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json_error('POST method required.', 405);
}

function clean_string(mixed $value): string
{
    return trim((string) ($value ?? ''));
}

function nullable_string(string $value): ?string
{
    return $value === '' ? null : $value;
}

function read_json_file(string $path, string $filename): mixed
{
    if (!is_readable($path)) {
        throw new RuntimeException("{$filename}: file is not readable.");
    }

    $decoded = json_decode((string) file_get_contents($path), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException("{$filename}: invalid JSON - " . json_last_error_msg());
    }

    return $decoded;
}

/**
 * 讀取 namespace_assets.json，建立 NamespaceID => name_zh / image 對照表。
 */
function load_namespace_assets(string $dataDir): array
{
    $path = $dataDir . DIRECTORY_SEPARATOR . 'namespace_assets.json';
    if (!is_file($path)) {
        return [];
    }

    try {
        $decoded = read_json_file($path, 'namespace_assets.json');
    } catch (Throwable) {
        return [];
    }

    $assets = [];
    foreach (is_array($decoded) ? $decoded : [] as $key => $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $id = clean_string($entry['NamespaceID'] ?? $entry['namespace_id'] ?? $entry['id'] ?? $key);
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
 * 驗證並整理單一 farm JSON。
 */
function normalize_farm(mixed $decoded, string $filename): array
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

        // 同一個 JSON 內重複的 NamespaceID，以後面的資料覆蓋前面的資料。
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
 * 掃描 data/*.json，收集可匯入的 farm；格式錯誤會寫入 errors 並略過該檔案。
 */
function load_farms(string $dataDir, array &$errors): array
{
    $farms = [];

    foreach (glob($dataDir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $path) {
        $filename = basename($path);
        if ($filename === 'namespace_assets.json') {
            continue;
        }

        try {
            $farm = normalize_farm(read_json_file($path, $filename), $filename);
            $farms[$farm['farm_code']] = $farm;
        } catch (Throwable $error) {
            $errors[] = $error->getMessage();
        }
    }

    return array_values($farms);
}

function prepare_statements(mysqli $connection): array
{
    $statements = [
        'farmUpsert' => $connection->prepare("
            INSERT INTO farm (farm_code, farm_name, description, updated_at)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
              farm_name = VALUES(farm_name),
              description = VALUES(description),
              updated_at = VALUES(updated_at)
        "),
        'farmSelect' => $connection->prepare("
            SELECT farm_id FROM farm WHERE farm_code = ? LIMIT 1
        "),
        'itemUpsert' => $connection->prepare("
            INSERT INTO item_master (NamespaceID, name_zh, image)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE
              name_zh = COALESCE(NULLIF(VALUES(name_zh), ''), name_zh),
              image = COALESCE(NULLIF(VALUES(image), ''), image)
        "),
        'inventoryDelete' => $connection->prepare("
            DELETE FROM farm_inventory WHERE farm_id = ?
        "),
        'inventoryUpsert' => $connection->prepare("
            INSERT INTO farm_inventory (farm_id, NamespaceID, amount, updated_at)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
              amount = VALUES(amount),
              updated_at = VALUES(updated_at)
        "),
    ];
    foreach ($statements as $statement) {
        if (!$statement) {
            send_json_error('Failed to prepare SQL statements: ' . $connection->error);
        }
    }
    return $statements;
}

/**
 * 同步單一農場 JSON 到資料庫。
 *
 * 邏輯：
 * 1. 寫入 / 更新 farm
 * 2. 取得 farm_id
 * 3. 清空該 farm 舊庫存
 * 4. 依照目前 JSON 重新寫入 item_master 與 farm_inventory
 *
 * @return array [item_master 匯入數量, farm_inventory 匯入數量]
 */
function sync_farm(array $statements, array $farm, array $assets): array
{
    // 取出農場基本資料，空字串轉成 NULL
    $farmCode = $farm['farm_code'];
    $farmName = $farm['farm_name'];
    $description = nullable_string($farm['description']);
    $farmUpdatedAt = nullable_string($farm['updated_at']);

    // 新增或更新 farm 資料
    $statements['farmUpsert']->bind_param('ssss', $farmCode, $farmName, $description, $farmUpdatedAt);
    if (!$statements['farmUpsert']->execute()) {
        throw new RuntimeException("Failed to upsert farm {$farmCode}: {$statements['farmUpsert']->error}");
    }

    // 用 farm_code 查出 farm_id，後面 farm_inventory 需要用 farm_id
    $statements['farmSelect']->bind_param('s', $farmCode);
    if (!$statements['farmSelect']->execute()) {
        throw new RuntimeException("Failed to query farm_id for {$farmCode}: {$statements['farmSelect']->error}");
    }

    $row = $statements['farmSelect']->get_result()?->fetch_assoc();
    if (!$row) {
        throw new RuntimeException("Cannot find farm_id for {$farmCode}.");
    }

    $farmId = (int) $row['farm_id'];

    // JSON 視為最新正確狀態：先清空該 farm 舊庫存，再重新匯入
    $statements['inventoryDelete']->bind_param('i', $farmId);
    if (!$statements['inventoryDelete']->execute()) {
        throw new RuntimeException("Failed to clear inventory for {$farmCode}: {$statements['inventoryDelete']->error}");
    }

    $itemsImported = 0;
    $inventoryRows = 0;
//==========
    // 逐筆匯入 JSON 裡的物品
    foreach ($farm['items'] as $item) {
        $namespaceId = $item['NamespaceID'];

        // 從 namespace_assets.json 補上中文名稱與圖片
        $nameZh = $assets[$namespaceId]['name_zh'] ?? '';
        $image = $assets[$namespaceId]['image'] ?? '';

        // 寫入或更新物品主檔 item_master
        $statements['itemUpsert']->bind_param('sss', $namespaceId, $nameZh, $image);
        if (!$statements['itemUpsert']->execute()) {
            throw new RuntimeException("Failed to upsert item {$namespaceId}: {$statements['itemUpsert']->error}");
        }

        $itemsImported++;

        // 寫入該 farm 的庫存數量
        $amount = (int) $item['amount'];
        $itemUpdatedAt = nullable_string($item['updated_at'] ?: $farm['updated_at']);

        $statements['inventoryUpsert']->bind_param('isis', $farmId, $namespaceId, $amount, $itemUpdatedAt);
        if (!$statements['inventoryUpsert']->execute()) {
            throw new RuntimeException("Failed to upsert inventory {$farmCode} / {$namespaceId}: {$statements['inventoryUpsert']->error}");
        }

        $inventoryRows++;
    }

    // 回傳本次同步的統計數量
    return [$itemsImported, $inventoryRows];
}

$dataDir = realpath(__DIR__ . '/../data');
if ($dataDir === false || !is_dir($dataDir)) {
    send_json_error('Cannot find data directory.', 404);
}

$errors = [];
$assets = load_namespace_assets($dataDir);
$farms = load_farms($dataDir, $errors);
$connection = get_db_connection();
$statements = prepare_statements($connection);

$farmsImported = 0;
$itemsImported = 0;
$inventoryRows = 0;

try {
    $connection->begin_transaction();

    foreach ($farms as $farm) {
        [$farmItemsImported, $farmInventoryRows] = sync_farm($statements, $farm, $assets);
        $farmsImported++;
        $itemsImported += $farmItemsImported;
        $inventoryRows += $farmInventoryRows;
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
