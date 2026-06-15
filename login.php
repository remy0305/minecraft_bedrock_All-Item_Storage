<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Minecraft Bedrock 資料庫管理</title>
    <style>
        body {
            margin: 0;
            padding: 20px;
            background: #151d17;
            color: #efe6d2;
            font-family: Consolas, "Microsoft JhengHei", monospace;
        }

        h1,
        h2 {
            color: #d8c18f;
        }

        .toolbar,
        .status-ok,
        .status-error,
        .empty,
        .sql-error {
            border: 2px solid #5f4a34;
            padding: 12px;
            margin-bottom: 18px;
            background: #2a241d;
        }

        .status-ok {
            color: #cbe7bd;
            border-color: #5e7b52;
            background: #1d2c1d;
        }

        .status-error,
        .sql-error {
            color: #ffd1c7;
            border-color: #8b4b3f;
            background: #35201d;
        }

        .empty {
            color: #ead593;
        }

        .small {
            color: #b9ad9d;
            font-size: 14px;
            overflow-wrap: anywhere;
        }

        .danger-button {
            min-height: 42px;
            padding: 8px 14px;
            color: #fff2df;
            font-weight: 700;
            cursor: pointer;
            background: #8b3f33;
            border: 2px solid #4d2f27;
            box-shadow: inset 2px 2px 0 rgba(255,255,255,.12), inset -3px -3px 0 rgba(0,0,0,.22);
        }

        .danger-button:hover {
            filter: brightness(1.08);
        }

        table {
            width: 100%;
            margin-bottom: 32px;
            border-collapse: collapse;
            background: #211d18;
        }

        th,
        td {
            padding: 8px;
            border: 1px solid #5f4a34;
            text-align: left;
            vertical-align: top;
        }

        th {
            color: #fff4d8;
            background: #4a3927;
        }

        tr:nth-child(even) {
            background: #28231d;
        }
    </style>
</head>
<body>
<h1>Minecraft Bedrock 資料庫管理</h1>

<?php
// 資料庫檢視頁：直接連 MySQL，把 farm、item_master、farm_inventory 等表格列出來給開發檢查。
$clearResult = null;

// 如果表單送出 clear_database，呼叫本頁內的 clearDatabase() 清空資料。
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear_database') {
    $clearResult = clearDatabase();
}

// MySQL 連線設定；本機 XAMPP 預設 root 空密碼。
$host = 'localhost';
$user = 'root';
$password = '';
$database = 'minecraft_bedrock';

$conn = new mysqli($host, $user, $password, $database);

// 連線失敗就顯示錯誤並停止後續查詢。
if ($conn->connect_error) {
    echo '<div class="status-error">資料庫連線失敗：' . htmlspecialchars($conn->connect_error) . '</div>';
    exit;
}

$conn->set_charset('utf8mb4');

// 顯示清空資料庫後的結果訊息。
if ($clearResult) {
    $className = $clearResult['success'] ? 'status-ok' : 'status-error';
    echo '<div class="' . $className . '">' . htmlspecialchars($clearResult['message']) . '</div>';
}

echo '<div class="status-ok">已連線資料庫：' . htmlspecialchars($database) . '</div>';
?>

<div class="toolbar">
    <h2>危險操作</h2>
    <p class="small">清空資料只會刪除 farm、farm_inventory、item_master 內的資料，不會刪除資料庫、資料表或 view。</p>
    <form method="post" onsubmit="return confirm('確定要清空 minecraft_bedrock 內的資料嗎？這個動作無法復原。');">
        <input type="hidden" name="action" value="clear_database">
        <button class="danger-button" type="submit">清空資料庫資料</button>
    </form>
</div>

<?php
// 清空主要資料表，供上方表單使用。
function clearDatabase(): array
{
    $conn = new mysqli('localhost', 'root', '', 'minecraft_bedrock');
    if ($conn->connect_error) {
        return [
            'success' => false,
            'message' => '資料庫連線失敗：' . $conn->connect_error,
        ];
    }

    $conn->set_charset('utf8mb4');
    // 需要清空的表格清單。
    $tables = ['farm_inventory', 'item_master', 'farm'];

    try {
        // 使用 transaction 包住清空流程，失敗時可 rollback。
        $conn->begin_transaction();
        $conn->query('SET FOREIGN_KEY_CHECKS=0');

        // 暫時關閉外鍵檢查後，逐一清空資料表。
        foreach ($tables as $table) {
            if (!$conn->query("TRUNCATE TABLE `$table`")) {
                throw new RuntimeException("清空 {$table} 失敗：" . $conn->error);
            }
        }

        // 全部成功後恢復外鍵檢查並提交。
        $conn->query('SET FOREIGN_KEY_CHECKS=1');
        $conn->commit();
        return [
            'success' => true,
            'message' => '資料庫資料已清空。',
        ];
    } catch (Throwable $error) {
        // 失敗時回復交易並恢復外鍵檢查。
        $conn->rollback();
        $conn->query('SET FOREIGN_KEY_CHECKS=1');
        return [
            'success' => false,
            'message' => $error->getMessage(),
        ];
    } finally {
        // 關閉這次清空流程建立的連線。
        $conn->close();
    }
}

// 執行查詢並把結果輸出成 HTML 表格，方便在瀏覽器檢查資料。
function showTable(mysqli $conn, string $title, string $sql): void
{
    echo '<h2>' . htmlspecialchars($title) . '</h2>';
    echo '<div class="small">SQL：' . htmlspecialchars($sql) . '</div><br>';

    $result = $conn->query($sql);

    // SQL 執行失敗時顯示錯誤，不中斷整個頁面。
    if (!$result) {
        echo '<div class="sql-error">SQL 錯誤：' . htmlspecialchars($conn->error) . '</div>';
        return;
    }

    // 沒資料時顯示空狀態。
    if ($result->num_rows === 0) {
        echo '<div class="empty">目前沒有資料</div>';
        return;
    }

    // 先輸出欄位名稱。
    echo '<table><tr>';
    foreach ($result->fetch_fields() as $field) {
        echo '<th>' . htmlspecialchars($field->name) . '</th>';
    }
    echo '</tr>';

    // 再逐列輸出查詢結果。
    while ($row = $result->fetch_assoc()) {
        echo '<tr>';
        foreach ($row as $value) {
            echo '<td>' . ($value === null ? '<span style="color:#999;">NULL</span>' : htmlspecialchars((string) $value)) . '</td>';
        }
        echo '</tr>';
    }

    echo '</table>';
}

showTable($conn, '1. farm 倉庫資料', 'SELECT * FROM farm ORDER BY farm_id ASC');
showTable($conn, '2. item_master 物品主檔', 'SELECT * FROM item_master ORDER BY NamespaceID ASC LIMIT 100');
showTable($conn, '3. farm_inventory 庫存資料', 'SELECT * FROM farm_inventory ORDER BY farm_id ASC, NamespaceID ASC');
showTable(
    $conn,
    '4. 倉庫庫存明細',
    "
    SELECT
        f.farm_id,
        f.farm_code,
        f.farm_name,
        fi.NamespaceID,
        fi.amount,
        fi.updated_at
    FROM farm_inventory fi
    JOIN farm f ON fi.farm_id = f.farm_id
    ORDER BY f.farm_id ASC, fi.NamespaceID ASC
    "
);

$viewCheck = $conn->query("SHOW FULL TABLES WHERE Tables_in_{$database} = 'item_total_view'");
if ($viewCheck && $viewCheck->num_rows > 0) {
    showTable($conn, '5. item_total_view 物品總量', 'SELECT * FROM item_total_view ORDER BY total_amount DESC');
}

showTable(
    $conn,
    '6. 各倉庫統計',
    "
    SELECT
        f.farm_code,
        f.farm_name,
        COUNT(fi.NamespaceID) AS item_kind_count,
        COALESCE(SUM(fi.amount), 0) AS total_amount,
        MAX(fi.updated_at) AS last_updated_at
    FROM farm f
    LEFT JOIN farm_inventory fi ON f.farm_id = fi.farm_id
    GROUP BY f.farm_id, f.farm_code, f.farm_name
    ORDER BY f.farm_id ASC
    "
);

$conn->close();
?>
<script>
    // 這段前端 JS 在檢視頁上方加一個重新整理按鈕，方便重新讀取最新資料表內容。
    const refreshBar = document.createElement('div');
    refreshBar.style.cssText = 'position:sticky;top:0;z-index:10;margin-bottom:16px;padding:10px 0;background:#151d17;';

    const refreshButton = document.createElement('button');
    refreshButton.type = 'button';
    refreshButton.textContent = '重新整理';
    refreshButton.style.cssText = 'min-height:42px;padding:8px 14px;color:#fff2df;font-weight:700;cursor:pointer;background:#4f6f45;border:2px solid #4d2f27;box-shadow:inset 2px 2px 0 rgba(255,255,255,.12),inset -3px -3px 0 rgba(0,0,0,.22);';
    // 點擊後重新載入目前頁面。
    refreshButton.addEventListener('click', () => {
        window.location.href = window.location.pathname;
    });

    refreshBar.appendChild(refreshButton);
    document.body.insertBefore(refreshBar, document.body.firstChild);
</script>
</body>
</html>
