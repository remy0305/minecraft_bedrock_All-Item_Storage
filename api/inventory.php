<?php
/**
 * 查詢完整農場 / 倉庫庫存 API。
 *
 * 前端主要讀這支 API：
 *   js/app.js -> fetch('api/inventory.php')
 *
 * 這支 API 只「查詢」MySQL，不會讀 data/*.json，也不會寫入資料庫。
 * 資料來源是 sync_data.php 已經匯入好的三張表：
 * - farm
 * - farm_inventory
 * - item_master
 *
 * 回傳格式：
 * {
 *   "success": true,
 *   "updated_at": "...",
 *   "farms": [
 *     {
 *       "farm_code": "...",
 *       "farm_name": "...",
 *       "items": [...]
 *     }
 *   ]
 * }
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';

$connection = get_db_connection();//建立一個連到 MySQL 資料庫的連線。


// 用 farm 當主表，LEFT JOIN 庫存與物品主檔。
// 使用 LEFT JOIN 的原因：就算某個 farm 暫時沒有物品，也仍然會出現在回傳結果。
$sql = "
    SELECT
      f.farm_id,
      f.farm_code,
      f.farm_name,
      f.description,
      f.updated_at AS farm_updated_at,
      fi.NamespaceID,
      i.name_zh,
      i.image,
      fi.amount,
      fi.updated_at AS item_updated_at
    FROM farm AS f
    LEFT JOIN farm_inventory AS fi
      ON fi.farm_id = f.farm_id
    LEFT JOIN item_master AS i
      ON i.NamespaceID = fi.NamespaceID
    ORDER BY f.farm_code ASC, fi.amount DESC, fi.NamespaceID ASC
";

$result = $connection->query($sql);
if (!$result) {
    send_json_error('Failed to query inventory: ' . $connection->error);
}

// 先用 farm_id 分組，避免 SQL join 後每個 item 都重複一份 farm 資料。
$farmsById = [];

while ($row = $result->fetch_assoc()) {
    $farmId = (int) $row['farm_id'];

    if (!isset($farmsById[$farmId])) {
        $farmsById[$farmId] = [
            'farm_code' => $row['farm_code'],
            'farm_name' => $row['farm_name'],
            'description' => $row['description'] ?? '',
            'updated_at' => $row['farm_updated_at'] ?? '',
            'item_count' => 0,
            'items' => [],
        ];
    }

    // 如果這個 farm 沒有任何庫存，LEFT JOIN 會得到 NamespaceID = NULL。
    // 這種情況只保留 farm 本身，不加入 items。
    if ($row['NamespaceID'] === null) {
        continue;
    }

    $farmsById[$farmId]['items'][] = [
        'NamespaceID' => $row['NamespaceID'],
        'name_zh' => $row['name_zh'] ?? '',
        'image' => $row['image'] ?? '',
        'amount' => (int) $row['amount'],
        'updated_at' => $row['item_updated_at'] ?? $row['farm_updated_at'] ?? '',
    ];
    $farmsById[$farmId]['item_count']++;
}

$farms = array_values($farmsById);
$totalItemCount = array_sum(array_column($farms, 'item_count'));

// 找出全資料庫最新更新時間，給前端摘要顯示。
$updatedAt = '';
foreach ($farms as $farm) {
    if ($farm['updated_at'] !== '' && ($updatedAt === '' || strcmp($farm['updated_at'], $updatedAt) > 0)) {
        $updatedAt = $farm['updated_at'];
    }
}

$connection->close();

echo json_encode([
    'success' => true,
    'updated_at' => $updatedAt,
    'farms' => $farms,
    'total_item_count' => $totalItemCount,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
