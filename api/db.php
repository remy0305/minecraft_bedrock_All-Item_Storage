<?php
/**
 * 共用資料庫工具。
 *
 * 這個檔案不直接提供頁面功能，而是給其他 API include。
 * 主要負責：
 * 1. 統一輸出 JSON 錯誤格式。
 * 2. 建立 MySQL / phpMyAdmin 的 minecraft_bedrock 連線。
 * 3. 將連線字元集固定為 utf8mb4，避免中文與 Minecraft NamespaceID 亂碼。
 */

/**
 * 回傳 API 錯誤 JSON 並結束程式。
 *
 * @param string $message 錯誤訊息
 * @param int $statusCode HTTP 狀態碼
 * @param array $extra 額外要放進 JSON 的資料
 */
function send_json_error(string $message, int $statusCode = 500, array $extra = []): void
{
    http_response_code($statusCode);
    echo json_encode([
        'success' => false,
        'error' => $message,
    ] + $extra, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/**
 * 建立 MySQL 連線。
 *
 * 目前使用 XAMPP 預設帳號：
 * - host: localhost
 * - user: root
 * - password: 空白
 * - database: minecraft_bedrock
 */
function get_db_connection(): mysqli
{
    $host = 'localhost';
    $user = 'root';
    $password = '';
    $database = 'minecraft_bedrock';

    // 關閉 mysqli 自動丟 exception，讓 API 可以自己回傳 JSON 錯誤。
    mysqli_report(MYSQLI_REPORT_OFF);

    $connection = new mysqli($host, $user, $password, $database);
    if ($connection->connect_error) {
        send_json_error('Database connection failed: ' . $connection->connect_error);
    }

    if (!$connection->set_charset('utf8mb4')) {
        send_json_error('Failed to set database charset: ' . $connection->error);
    }

    return $connection;
}
