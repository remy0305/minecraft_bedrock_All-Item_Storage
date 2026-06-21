<?php
/**
 * 舊版直接更新庫存 API。
 *
 * 目前已停用。
 * 原因：
 * - 現在正式資料來源是 data/*.json。
 * - 匯入流程統一交給 api/sync_data.php。
 * - 避免有兩套寫入資料庫的流程造成資料不一致。
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';

send_json_error('update_inventory.php is disabled. Use POST api/sync_data.php to import data/*.json into MySQL.', 410);
