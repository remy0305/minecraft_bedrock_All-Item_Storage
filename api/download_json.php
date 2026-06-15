<?php
// 測試下載檔案用的簡易腳本：從指定 URL 下載內容，存到 data/test.txt。
// 注意：這不是主要前端會呼叫的 API，只是工具/測試用途。

$url = "https://github.com/EndstoneMC/endstone/blob/main/.git_archival.txt";
$savePath = __DIR__ . "/../data/test.txt";

$data = file_get_contents($url);

if ($data === false) {
    die("下載失敗");
}

file_put_contents($savePath, $data);

echo "下載完成";



