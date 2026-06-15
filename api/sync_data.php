<?php
// 資料同步 API：前端按刷新時 POST 到這裡，後端會把 data/*.json 同步進 MySQL。
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';

// 這支 API 會修改資料庫，所以只允許 POST，避免使用者誤用網址觸發同步。
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json_error('POST method required.', 405);
}

// 確保匯入批次與快照資料表存在，用來保留每次同步的歷史紀錄。
function ensure_history_tables(mysqli $connection): void
{
    $schemaSql = [
        "
        CREATE TABLE IF NOT EXISTS inventory_import_batch (
          batch_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          source VARCHAR(64) NOT NULL DEFAULT 'sync_data',
          imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          raw_payload LONGTEXT NOT NULL,
          PRIMARY KEY (batch_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ",
        "
        CREATE TABLE IF NOT EXISTS inventory_snapshot_item (
          snapshot_item_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          batch_id BIGINT UNSIGNED NOT NULL,
          farm_id INT NOT NULL,
          farm_code VARCHAR(100) NOT NULL,
          NamespaceID VARCHAR(255) NOT NULL,
          amount INT NOT NULL,
          updated_at DATETIME NOT NULL,
          PRIMARY KEY (snapshot_item_id),
          KEY batch_id (batch_id),
          KEY farm_snapshot (farm_id, NamespaceID),
          CONSTRAINT inventory_snapshot_item_batch_fk
            FOREIGN KEY (batch_id)
            REFERENCES inventory_import_batch (batch_id)
            ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ",
    ];

    foreach ($schemaSql as $sql) {
        if (!$connection->query($sql)) {
            throw new RuntimeException('Failed to prepare inventory history tables: ' . $connection->error);
        }
    }
}

// 驗證單一 JSON 檔案的 farm/items 結構，並統一回傳 farm 陣列。
function normalize_farm_payload(mixed $decoded, string $filename): array
{
    if (!is_array($decoded)) {
        throw new RuntimeException("{$filename} must be a JSON object or array.");
    }

    $farms = array_is_list($decoded) ? $decoded : [$decoded];

    foreach ($farms as $index => $farm) {
        if (!is_array($farm) || array_is_list($farm)) {
            throw new RuntimeException("{$filename} farm {$index} must be an object.");
        }

        if (empty($farm['farm_code'])) {
            throw new RuntimeException("{$filename} farm {$index} missing farm_code.");
        }

        if (!isset($farm['items']) || !is_array($farm['items']) || !array_is_list($farm['items'])) {
            throw new RuntimeException("{$filename} farm {$farm['farm_code']} missing items array.");
        }
    }

    return $farms;
}

// 找到 data 資料夾並列出所有 JSON 檔，namespace_assets.json 會在迴圈中排除。
$dataDir = realpath(__DIR__ . '/../data');
if ($dataDir === false || !is_dir($dataDir)) {
    send_json_error('Cannot find data directory.', 404);
}

$files = glob($dataDir . DIRECTORY_SEPARATOR . '*.json') ?: [];
$connection = get_db_connection();

// 建立同步流程需要的歷史資料表。
try {
    ensure_history_tables($connection);
} catch (Throwable $error) {
    send_json_error($error->getMessage(), 500);
}

// 預先準備所有 SQL，後面以 bind_param 帶值，避免直接拼接使用者資料。
$farmUpsert = $connection->prepare("
    INSERT INTO farm (farm_code, farm_name, description)
    VALUES (?, ?, ?)
    ON DUPLICATE KEY UPDATE
      farm_name = VALUES(farm_name),
      description = VALUES(description)
");
$farmSelect = $connection->prepare("
    SELECT farm_id
    FROM farm
    WHERE farm_code = ?
    LIMIT 1
");
$itemUpsert = $connection->prepare("
    INSERT INTO item_master (NamespaceID)
    VALUES (?)
    ON DUPLICATE KEY UPDATE
      NamespaceID = VALUES(NamespaceID)
");
$inventoryDelete = $connection->prepare("
    DELETE FROM farm_inventory
    WHERE farm_id = ?
");
$inventoryInsert = $connection->prepare("
    INSERT INTO farm_inventory (farm_id, NamespaceID, amount, updated_at)
    VALUES (?, ?, ?, ?)
");
$batchInsert = $connection->prepare("
    INSERT INTO inventory_import_batch (source, raw_payload)
    VALUES (?, ?)
");
$snapshotInsert = $connection->prepare("
    INSERT INTO inventory_snapshot_item (batch_id, farm_id, farm_code, NamespaceID, amount, updated_at)
    VALUES (?, ?, ?, ?, ?, ?)
");

if (!$farmUpsert || !$farmSelect || !$itemUpsert || !$inventoryDelete || !$inventoryInsert || !$batchInsert || !$snapshotInsert) {
    send_json_error('Failed to prepare SQL statements: ' . $connection->error);
}

// 統計本次同步結果，最後回傳給前端顯示。
$filesProcessed = 0;
$farmsProcessed = 0;
$itemsProcessed = 0;
$clearedItems = 0;
$errors = [];

// 逐一同步 data 資料夾中的庫存 JSON 檔案。
foreach ($files as $file) {
    $filename = basename($file);

    // 素材對照表不是庫存資料，所以略過。
    if ($filename === 'namespace_assets.json') {
        continue;
    }

    if (!is_readable($file)) {
        $errors[] = "{$filename}: file is not readable.";
        continue;
    }

    // 讀取並解析 JSON；單一檔案失敗時記錄錯誤，繼續處理下一個檔案。
    $raw = file_get_contents($file);
    $decoded = json_decode($raw, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        $errors[] = "{$filename}: invalid JSON - " . json_last_error_msg();
        continue;
    }

    try {
        // 每個檔案各自使用一個 transaction，避免某檔失敗影響其他檔案。
        $farms = normalize_farm_payload($decoded, $filename);
        $connection->begin_transaction();

        // 先記錄原始 JSON，方便追蹤這次同步來源。
        $source = 'sync_data:' . $filename;
        $batchInsert->bind_param('ss', $source, $raw);
        if (!$batchInsert->execute()) {
            throw new RuntimeException('Failed to record inventory import batch: ' . $batchInsert->error);
        }
        $batchId = (int) $connection->insert_id;

        // 同步每個農場：更新農場資料、清除舊庫存、寫入新庫存。
        foreach ($farms as $farm) {
            $farmCode = (string) $farm['farm_code'];
            $farmName = isset($farm['farm_name']) && $farm['farm_name'] !== ''
                ? (string) $farm['farm_name']
                : $farmCode;
            $description = array_key_exists('description', $farm) && $farm['description'] !== null && $farm['description'] !== ''
                ? (string) $farm['description']
                : null;
            $farmUpdatedAt = isset($farm['updated_at']) && $farm['updated_at'] !== ''
                ? (string) $farm['updated_at']
                : date('Y-m-d H:i:s');

            // 寫入或更新 farm 主檔。
            $farmUpsert->bind_param('sss', $farmCode, $farmName, $description);
            if (!$farmUpsert->execute()) {
                throw new RuntimeException('Failed to upsert farm: ' . $farmUpsert->error);
            }

            // 查出 farm_id，後續庫存表用這個 id 關聯。
            $farmSelect->bind_param('s', $farmCode);
            if (!$farmSelect->execute()) {
                throw new RuntimeException('Failed to query farm_id: ' . $farmSelect->error);
            }

            $farmResult = $farmSelect->get_result();
            $farmRow = $farmResult ? $farmResult->fetch_assoc() : null;
            if (!$farmRow) {
                throw new RuntimeException('Cannot find farm_id for farm_code: ' . $farmCode);
            }

            $farmId = (int) $farmRow['farm_id'];
            $farmsProcessed++;

            // 以最新 JSON 為準，先清掉該農場目前庫存。
            $inventoryDelete->bind_param('i', $farmId);
            if (!$inventoryDelete->execute()) {
                throw new RuntimeException('Failed to clear current farm inventory: ' . $inventoryDelete->error);
            }
            $clearedItems += $inventoryDelete->affected_rows;

            // 寫入這個農場底下每個物品的數量。
            foreach ($farm['items'] as $itemIndex => $item) {
                if (!is_array($item) || array_is_list($item)) {
                    throw new RuntimeException("{$filename} / {$farmCode} item {$itemIndex} must be an object.");
                }

                if (empty($item['NamespaceID'])) {
                    throw new RuntimeException("{$filename} / {$farmCode} item {$itemIndex} missing NamespaceID.");
                }

                if (!array_key_exists('amount', $item) || !is_numeric($item['amount'])) {
                    throw new RuntimeException("{$filename} / {$farmCode} / {$item['NamespaceID']} amount must be numeric.");
                }

                $namespaceId = (string) $item['NamespaceID'];
                $amount = (int) $item['amount'];
                $itemUpdatedAt = isset($item['updated_at']) && $item['updated_at'] !== ''
                    ? (string) $item['updated_at']
                    : $farmUpdatedAt;

                // 確保 item_master 有這個 NamespaceID。
                $itemUpsert->bind_param('s', $namespaceId);
                if (!$itemUpsert->execute()) {
                    throw new RuntimeException('Failed to upsert item_master: ' . $itemUpsert->error);
                }

                // 寫入目前庫存表。
                $inventoryInsert->bind_param('isis', $farmId, $namespaceId, $amount, $itemUpdatedAt);
                if (!$inventoryInsert->execute()) {
                    throw new RuntimeException('Failed to insert current inventory: ' . $inventoryInsert->error);
                }

                // 同時寫入歷史快照。
                $snapshotInsert->bind_param('iissis', $batchId, $farmId, $farmCode, $namespaceId, $amount, $itemUpdatedAt);
                if (!$snapshotInsert->execute()) {
                    throw new RuntimeException('Failed to record inventory snapshot item: ' . $snapshotInsert->error);
                }

                $itemsProcessed++;
            }
        }

        // 這個檔案全部成功後才提交。
        $connection->commit();
        $filesProcessed++;
    } catch (Throwable $error) {
        // 單一檔案失敗時 rollback，並把錯誤放入 errors 陣列回傳。
        $connection->rollback();
        $errors[] = "{$filename}: " . $error->getMessage();
    }
}

// 關閉資料庫 statement 與連線。
$farmUpsert->close();
$farmSelect->close();
$itemUpsert->close();
$inventoryDelete->close();
$inventoryInsert->close();
$batchInsert->close();
$snapshotInsert->close();
$connection->close();

// 回傳同步結果；即使部分檔案失敗，也會把錯誤列在 errors。
echo json_encode([
    'success' => true,
    'message' => 'data folder synced to database',
    'files_processed' => $filesProcessed,
    'farms_processed' => $farmsProcessed,
    'items_processed' => $itemsProcessed,
    'cleared_item_count' => $clearedItems,
    'errors' => $errors,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
