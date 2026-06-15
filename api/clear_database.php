<?php
// 清空資料庫 API：需要 POST 並帶 confirm=CLEAR_DATABASE，避免誤觸清空資料。
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';

// 這支 API 會刪資料，所以限制只能用 POST。
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json_error('只允許 POST 請求', 405);
}

// 二次確認參數，避免一般請求不小心清掉資料表。
$confirm = $_POST['confirm'] ?? '';
if ($confirm !== 'CLEAR_DATABASE') {
    send_json_error('缺少清空確認參數', 400);
}

// 要清空的資料表；順序搭配暫時關閉外鍵檢查。
$connection = get_db_connection();
$tables = ['farm_inventory', 'item_master', 'farm'];

$connection->begin_transaction();

try {
    // TRUNCATE 有外鍵關聯時會失敗，所以清空期間暫時關閉外鍵檢查。
    if (!$connection->query('SET FOREIGN_KEY_CHECKS=0')) {
        throw new RuntimeException($connection->error);
    }

    // 逐一清空指定資料表。
    foreach ($tables as $table) {
        if (!$connection->query("TRUNCATE TABLE `$table`")) {
            throw new RuntimeException("清空 {$table} 失敗：" . $connection->error);
        }
    }

    // 清空完成後恢復外鍵檢查並提交交易。
    if (!$connection->query('SET FOREIGN_KEY_CHECKS=1')) {
        throw new RuntimeException($connection->error);
    }

    $connection->commit();

    // 回傳成功訊息與被清空的資料表清單。
    echo json_encode([
        'success' => true,
        'message' => '資料庫資料已清空',
        'tables' => $tables,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Throwable $error) {
    // 失敗時 rollback，並盡量恢復外鍵檢查狀態。
    $connection->rollback();
    $connection->query('SET FOREIGN_KEY_CHECKS=1');
    send_json_error($error->getMessage(), 500);
} finally {
    // 關閉連線。
    $connection->close();
}
