<?php
/**
 * 資料庫管理頁。
 *
 * 這不是登入系統，檔名保留為 login.php 是為了沿用你原本的入口。
 *
 * 功能：
 * 1. 顯示目前 farm / item_master / farm_inventory 筆數。
 * 2. 清空目前正式庫存資料表。
 * 3. 呼叫 api/sync_data.php，將 data/*.json 完整匯入 MySQL。
 *
 * 注意：
 * - 這個頁面會執行清空資料庫的管理操作，不建議公開到外網。
 * - 真正的匯入邏輯在 api/sync_data.php。
 */
require_once __DIR__ . '/api/db.php';

$message = '';
$messageClass = '';

/**
 * 清空目前乾淨版資料庫使用的三張正式表。
 */
function clear_current_inventory_database(): array
{
    $connection = get_db_connection();
    $tables = ['farm_inventory', 'item_master', 'farm'];

    try {
        $connection->begin_transaction();

        if (!$connection->query('SET FOREIGN_KEY_CHECKS=0')) {
            throw new RuntimeException($connection->error);
        }

        foreach ($tables as $table) {
            if (!$connection->query("TRUNCATE TABLE `{$table}`")) {
                throw new RuntimeException("Failed to clear {$table}: " . $connection->error);
            }
        }

        if (!$connection->query('SET FOREIGN_KEY_CHECKS=1')) {
            throw new RuntimeException($connection->error);
        }

        $connection->commit();
        return [
            'success' => true,
            'message' => '已清空 farm、item_master、farm_inventory。',
        ];
    } catch (Throwable $error) {
        $connection->rollback();
        $connection->query('SET FOREIGN_KEY_CHECKS=1');
        return [
            'success' => false,
            'message' => $error->getMessage(),
        ];
    } finally {
        $connection->close();
    }
}

/**
 * 讀取資料表筆數，用於管理頁顯示目前狀態。
 */
function get_table_counts(): array
{
    $connection = get_db_connection();
    $counts = [];

    foreach (['farm', 'item_master', 'farm_inventory'] as $table) {
        $result = $connection->query("SELECT COUNT(*) AS row_count FROM `{$table}`");
        $row = $result ? $result->fetch_assoc() : ['row_count' => 0];
        $counts[$table] = (int) $row['row_count'];
    }

    $connection->close();
    return $counts;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'clear_database' || $action === 'clear_and_sync') {
        $result = clear_current_inventory_database();
        $message = $result['message'];
        $messageClass = $result['success'] ? 'ok' : 'error';
    }
}

$counts = get_table_counts();
$shouldAutoSync = $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'clear_and_sync'
    && $messageClass === 'ok';
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Minecraft Bedrock 資料庫管理</title>
  <style>
    body {
      margin: 0;
      padding: 24px;
      background: #151d17;
      color: #efe6d2;
      font-family: Consolas, "Microsoft JhengHei", monospace;
    }

    h1, h2 { color: #d8c18f; }

    .panel, .message, table {
      max-width: 980px;
      border: 2px solid #5f4a34;
      background: #211d18;
      margin-bottom: 18px;
    }

    .panel, .message { padding: 14px; }

    .message.ok {
      color: #cbe7bd;
      border-color: #5e7b52;
      background: #1d2c1d;
    }

    .message.error {
      color: #ffd1c7;
      border-color: #8b4b3f;
      background: #35201d;
    }

    button, a.button {
      display: inline-block;
      min-height: 42px;
      margin: 4px 8px 4px 0;
      padding: 10px 14px;
      color: #fff2df;
      font-weight: 700;
      text-decoration: none;
      cursor: pointer;
      background: #4f6f45;
      border: 2px solid #4d2f27;
    }

    button.danger { background: #8b3f33; }

    table {
      border-collapse: collapse;
      width: 100%;
    }

    th, td {
      padding: 10px;
      border: 1px solid #5f4a34;
      text-align: left;
    }

    th { background: #4a3927; }
    code { color: #ead593; }
  </style>
</head>
<body>
  <h1>Minecraft Bedrock 資料庫管理</h1>

  <?php if ($message !== ''): ?>
    <div class="message <?php echo htmlspecialchars($messageClass); ?>">
      <?php echo htmlspecialchars($message); ?>
    </div>
  <?php endif; ?>

  <div id="syncMessage" class="message" style="display:none;"></div>

  <section class="panel">
    <h2>操作</h2>
    <form method="post" onsubmit="return confirm('確定要清空目前庫存資料？');">
      <input type="hidden" name="action" value="clear_database">
      <button class="danger" type="submit">只清空資料庫內容</button>
    </form>

    <form method="post" onsubmit="return confirm('確定要清空後重新匯入 data/*.json？');">
      <input type="hidden" name="action" value="clear_and_sync">
      <button type="submit">清空後完整匯入 JSON</button>
    </form>

    <button id="syncButton" type="button">只匯入 / 同步 JSON</button>
    <a class="button" href="index.html">回到庫存頁</a>
  </section>

  <section class="panel">
    <h2>目前資料筆數</h2>
    <table>
      <tr><th>資料表</th><th>筆數</th></tr>
      <tr><td>farm</td><td><?php echo number_format($counts['farm']); ?></td></tr>
      <tr><td>item_master</td><td><?php echo number_format($counts['item_master']); ?></td></tr>
      <tr><td>farm_inventory</td><td><?php echo number_format($counts['farm_inventory']); ?></td></tr>
    </table>
  </section>

  <section class="panel">
    <h2>說明</h2>
    <p><code>login.php</code> 是管理頁，不是資料查詢 API。</p>
    <p>清空後要完整匯入 JSON，請按「清空後完整匯入 JSON」。</p>
    <p>真正匯入邏輯在 <code>api/sync_data.php</code>。</p>
  </section>

  <script>
    const syncButton = document.getElementById('syncButton');
    const syncMessage = document.getElementById('syncMessage');

    function showSyncMessage(text, ok = true) {
      syncMessage.style.display = 'block';
      syncMessage.className = `message ${ok ? 'ok' : 'error'}`;
      syncMessage.textContent = text;
    }

    async function syncJsonData() {
      syncButton.disabled = true;
      syncButton.textContent = '匯入中...';
      showSyncMessage('正在匯入 data/*.json...', true);

      try {
        const response = await fetch('api/sync_data.php', {
          method: 'POST',
          cache: 'no-store'
        });
        const result = await response.json();

        if (!response.ok || !result.success) {
          throw new Error(result.error || '匯入失敗');
        }

        const errors = Array.isArray(result.errors) && result.errors.length
          ? `，${result.errors.length} 個檔案有錯誤`
          : '';
        showSyncMessage(`匯入完成：${result.farms_imported} 個農場，${result.items_imported} 個物品處理，${result.inventory_rows} 筆庫存${errors}`, true);
      } catch (error) {
        showSyncMessage(`匯入失敗：${error.message}`, false);
      } finally {
        syncButton.disabled = false;
        syncButton.textContent = '只匯入 / 同步 JSON';
      }
    }

    syncButton.addEventListener('click', syncJsonData);

    <?php if ($shouldAutoSync): ?>
    syncJsonData();
    <?php endif; ?>
  </script>
</body>
</html>
