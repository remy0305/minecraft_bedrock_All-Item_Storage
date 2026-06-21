<?php
/**
 * 舊版測試下載 API。
 *
 * 目前正式流程不使用這支 API。
 * 正式 JSON 來源是本機資料夾：
 *   minecraft_badrock/data/*.json
 *
 * 匯入資料庫請使用：
 *   POST api/sync_data.php
 *
 * 這支檔案保留只是為了避免舊前端或舊連結 404。
 * 為了避免誤觸外部網路下載，這裡直接回傳停用訊息。
 */
header('Content-Type: application/json; charset=utf-8');

echo json_encode([
    'success' => false,
    'message' => 'download_json.php is disabled. Put JSON files in data/ and use POST api/sync_data.php.',
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
