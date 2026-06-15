<?php
// 共用資料庫工具檔：所有 API 需要連 MySQL 時都引入這個檔案。

// 回傳統一格式的 JSON 錯誤訊息，並設定 HTTP 狀態碼後結束程式。
function send_json_error(string $message, int $statusCode = 500): void
{
    http_response_code($statusCode);
    echo json_encode([
        'success' => false,
        'error' => $message,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// 建立 MySQL 連線，並統一設定 utf8mb4，讓其他 API 不用重複寫連線程式。
function get_db_connection(): mysqli
{
    $host = 'localhost';
    $user = 'root';
    $password = '';
    $database = 'minecraft_bedrock';

    // 關閉 mysqli 自動丟例外，讓 API 可以自己回傳固定 JSON 錯誤格式。
    mysqli_report(MYSQLI_REPORT_OFF);

    $connection = new mysqli($host, $user, $password, $database);
    if ($connection->connect_error) {
        send_json_error('資料庫連線失敗：' . $connection->connect_error);
    }

    if (!$connection->set_charset('utf8mb4')) {
        send_json_error('設定資料庫字元集失敗：' . $connection->error);
    }

    return $connection;
}
