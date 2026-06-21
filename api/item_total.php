<?php
/**
 * 查詢所有農場加總後的物品總量 API。
 *
 * 這支 API 只查詢資料庫，不會寫入資料。
 * 用途：
 * - 做全部物品總量排行榜。
 * - 測試 farm_inventory + item_master 的 join 是否正常。
 * - 如果前端之後要做「總庫存」頁，可以直接讀這支。
 *
 * 查詢邏輯：
 * farm_inventory 裡同一個 NamespaceID 可能分散在多個農場，
 * 所以用 SUM(fi.amount) 加總全部農場的數量。
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';

$connection = get_db_connection();

$sql = "
    SELECT
      i.NamespaceID,
      i.name_zh,
      i.image,
      SUM(fi.amount) AS total_amount,
      MAX(fi.updated_at) AS last_updated_at
    FROM farm_inventory fi
    JOIN item_master i
      ON fi.NamespaceID = i.NamespaceID
    GROUP BY i.NamespaceID, i.name_zh, i.image
    ORDER BY total_amount DESC, i.NamespaceID ASC
";

$result = $connection->query($sql);
if (!$result) {
    send_json_error('Failed to query item totals: ' . $connection->error);
}

$items = [];
while ($row = $result->fetch_assoc()) {
    $items[] = [
        'NamespaceID' => $row['NamespaceID'],
        'name_zh' => $row['name_zh'] ?? '',
        'image' => $row['image'] ?? '',
        'total_amount' => (int) $row['total_amount'],
        'last_updated_at' => $row['last_updated_at'] ?? '',
    ];
}

$connection->close();

echo json_encode([
    'success' => true,
    'items' => $items,
    'item_count' => count($items),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
