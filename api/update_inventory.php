<?php
// 庫存更新 API：接受前端或外部工具 POST 過來的 JSON，寫入 MySQL 目前庫存與歷史快照。
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';

// 檢查必要欄位是否存在，缺少就丟例外讓外層回傳 JSON 錯誤。
function require_field(array $data, string $field, string $context): void
{
    if (!array_key_exists($field, $data) || $data[$field] === null || $data[$field] === '') {
        throw new RuntimeException($context . ' missing required field: ' . $field);
    }
}

// 確保歷史匯入批次與快照資料表存在，讓每次匯入都有可追蹤紀錄。
function ensure_history_tables(mysqli $connection): void
{
    $schemaSql = [
        "
        CREATE TABLE IF NOT EXISTS inventory_import_batch (
          batch_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          source VARCHAR(64) NOT NULL DEFAULT 'update_inventory',
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

// 讀取 POST body 的原始 JSON，這支 API 預期收到最外層為 farm 陣列。
$rawInput = file_get_contents('php://input');
$payload = json_decode($rawInput, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    send_json_error('Invalid JSON: ' . json_last_error_msg(), 400);
}

if (!is_array($payload) || !array_is_list($payload)) {
    send_json_error('Expected top-level JSON array of farms.', 400);
}

$connection = get_db_connection();

// 建立需要的歷史表，如果資料庫權限或 SQL 失敗，就中止 API。
try {
    ensure_history_tables($connection);
} catch (Throwable $error) {
    send_json_error($error->getMessage(), 500);
}

// 預先準備 SQL，後面用 bind_param 帶入資料，避免 SQL injection。
$farmUpsert = $connection->prepare("
    INSERT INTO farm (farm_code, farm_name)
    VALUES (?, ?)
    ON DUPLICATE KEY UPDATE
      farm_name = VALUES(farm_name)
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
    VALUES ('update_inventory', ?)
");
$snapshotInsert = $connection->prepare("
    INSERT INTO inventory_snapshot_item (batch_id, farm_id, farm_code, NamespaceID, amount, updated_at)
    VALUES (?, ?, ?, ?, ?, ?)
");

if (!$farmUpsert || !$farmSelect || !$itemUpsert || !$inventoryDelete || !$inventoryInsert || !$batchInsert || !$snapshotInsert) {
    send_json_error('Failed to prepare SQL statements: ' . $connection->error);
}

// 統計匯入結果，最後會一起回傳給呼叫端。
$farmCount = 0;
$itemCount = 0;
$clearedItemCount = 0;

$connection->begin_transaction();

try {
    // 先記錄這次匯入的原始 JSON，方便日後查問題或回溯資料。
    $batchInsert->bind_param('s', $rawInput);
    if (!$batchInsert->execute()) {
        throw new RuntimeException('Failed to record inventory import batch: ' . $batchInsert->error);
    }
    $batchId = (int) $connection->insert_id;

    // 逐一處理每個農場：先 upsert 農場，再重建該農場目前庫存。
    foreach ($payload as $farmIndex => $farmData) {
        if (!is_array($farmData)) {
            throw new RuntimeException("Farm {$farmIndex} must be an object.");
        }

        require_field($farmData, 'farm_code', "Farm {$farmIndex}");

        if (!array_key_exists('items', $farmData) || !is_array($farmData['items']) || !array_is_list($farmData['items'])) {
            throw new RuntimeException("Farm {$farmIndex} must contain an items array.");
        }

        $farmCode = (string) $farmData['farm_code'];
        $farmName = isset($farmData['farm_name']) && $farmData['farm_name'] !== ''
            ? (string) $farmData['farm_name']
            : $farmCode;
        $farmUpdatedAt = isset($farmData['updated_at']) && $farmData['updated_at'] !== ''
            ? (string) $farmData['updated_at']
            : date('Y-m-d H:i:s');

        // upsert 農場主檔，farm_code 相同時更新名稱。
        $farmUpsert->bind_param('ss', $farmCode, $farmName);
        if (!$farmUpsert->execute()) {
            throw new RuntimeException('Failed to upsert farm: ' . $farmUpsert->error);
        }

        // 取回 farm_id，後續 farm_inventory 需要用 id 建立關聯。
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
        $farmCount++;

        // 先刪除該農場舊的目前庫存，再依這次 JSON 重新寫入。
        $inventoryDelete->bind_param('i', $farmId);
        if (!$inventoryDelete->execute()) {
            throw new RuntimeException('Failed to clear current farm inventory: ' . $inventoryDelete->error);
        }
        $clearedItemCount += $inventoryDelete->affected_rows;

        // 逐一處理農場內物品，寫入物品主檔、目前庫存與歷史快照。
        foreach ($farmData['items'] as $itemIndex => $itemData) {
            if (!is_array($itemData)) {
                throw new RuntimeException("Farm {$farmIndex} item {$itemIndex} must be an object.");
            }

            require_field($itemData, 'NamespaceID', "Farm {$farmIndex} item {$itemIndex}");
            require_field($itemData, 'amount', "Farm {$farmIndex} item {$itemIndex}");

            if (!is_numeric($itemData['amount'])) {
                throw new RuntimeException("Farm {$farmIndex} item {$itemIndex} amount must be numeric.");
            }

            $namespaceId = (string) $itemData['NamespaceID'];
            $amount = (int) $itemData['amount'];
            $itemUpdatedAt = isset($itemData['updated_at']) && $itemData['updated_at'] !== ''
                ? (string) $itemData['updated_at']
                : $farmUpdatedAt;

            // item_master 只保存 NamespaceID，確保後續查詢有主檔資料。
            $itemUpsert->bind_param('s', $namespaceId);
            if (!$itemUpsert->execute()) {
                throw new RuntimeException('Failed to upsert item_master: ' . $itemUpsert->error);
            }

            // 寫入目前庫存表，這是前端主要讀取的庫存狀態來源。
            $inventoryInsert->bind_param('isis', $farmId, $namespaceId, $amount, $itemUpdatedAt);
            if (!$inventoryInsert->execute()) {
                throw new RuntimeException('Failed to insert current inventory: ' . $inventoryInsert->error);
            }

            // 寫入歷史快照表，保留每次匯入當下的數量。
            $snapshotInsert->bind_param('iissis', $batchId, $farmId, $farmCode, $namespaceId, $amount, $itemUpdatedAt);
            if (!$snapshotInsert->execute()) {
                throw new RuntimeException('Failed to record inventory snapshot item: ' . $snapshotInsert->error);
            }

            $itemCount++;
        }
    }

    // 全部成功才 commit，任何一筆失敗都會進 catch rollback。
    $connection->commit();

    // 回傳匯入成功與統計數字給呼叫端。
    echo json_encode([
        'success' => true,
        'message' => 'inventory updated',
        'batch_id' => $batchId,
        'farm_count' => $farmCount,
        'item_count' => $itemCount,
        'cleared_item_count' => $clearedItemCount,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Throwable $error) {
    // 發生錯誤時回復整批交易，避免資料寫到一半造成不一致。
    $connection->rollback();
    send_json_error($error->getMessage(), 400);
} finally {
    // 關閉 statement 與連線，釋放資料庫資源。
    $farmUpsert->close();
    $farmSelect->close();
    $itemUpsert->close();
    $inventoryDelete->close();
    $inventoryInsert->close();
    $batchInsert->close();
    $snapshotInsert->close();
    $connection->close();
}
