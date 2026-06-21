<?php
/**
 * 清空目前正式庫存資料 API。
 *
 * 注意：
 * 這支 API 會清空資料庫內容，是管理用 API，不是查詢 API。
 * 它只清空目前乾淨版會使用的三張正式表：
 * - farm_inventory
 * - item_master
 * - farm
 *
 * 不會自動重新匯入 JSON。
 * 如果要清空後匯入，建議使用 login.php 的「清空後完整匯入 JSON」按鈕。
 *
 * 呼叫方式：
 * POST /api/clear_database.php
 * body: confirm=CLEAR_DATABASE
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json_error('POST method required.', 405);
}

$confirm = $_POST['confirm'] ?? '';
if ($confirm !== 'CLEAR_DATABASE') {
    send_json_error('Missing confirm=CLEAR_DATABASE.', 400);
}

$connection = get_db_connection();
$tables = ['farm_inventory', 'item_master', 'farm'];

$connection->begin_transaction();

try {
    // 暫時關閉外鍵檢查，才能用 TRUNCATE 清空有關聯的資料表。
    if (!$connection->query('SET FOREIGN_KEY_CHECKS=0')) {
        throw new RuntimeException($connection->error);
    }

    foreach ($tables as $table) {
        if (!$connection->query("TRUNCATE TABLE `{$table}`")) {
            throw new RuntimeException("Failed to truncate {$table}: " . $connection->error);
        }
    }

    if (!$connection->query('SET FOREIGN_KEY_CHECKS=1')) {
        throw new RuntimeException($connection->error);
    }

    $connection->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Database tables cleared.',
        'tables' => $tables,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Throwable $error) {
    $connection->rollback();
    $connection->query('SET FOREIGN_KEY_CHECKS=1');
    send_json_error($error->getMessage(), 500);
} finally {
    $connection->close();
}
