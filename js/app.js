// 前端主程式：負責呼叫 PHP API、整理回傳資料，並把庫存狀態渲染到 HTML 畫面。
// 串接流程：瀏覽器 JS fetch() -> PHP API -> PHP 回傳 JSON -> JS 更新 DOM。
const INVENTORY_API_URL = 'api/inventory.php';
const SYNC_API_URL = 'api/sync_data.php';
const DOWNLOAD_JSON_API_URL = 'api/download_json.php';
const ASSET_MAP_URL = 'data/namespace_assets.json';
const RESOURCE_PACK_PREFIX = 'game_assets/ResourcePack_v26.20.0/';
const LOW_STOCK_THRESHOLD = 64;
const FULL_STOCK_THRESHOLD = 2048;

// 前端暫存狀態：這些變數只存在瀏覽器端，用來記錄目前資料、篩選條件與選取項目。
let inventoryData = [];
let namespaceAssets = {};
let assetMapLoaded = false;
let selectedFarmCode = 'all';
let selectedStatus = 'all';
let selectedItemKey = null;
let searchKeyword = '';
let statusMessage = '';
let isRefreshing = false;

// 集中保存會被 JS 操作的 HTML 元素，避免每次渲染都重複查詢 DOM。
const elements = {
  totalAmount: document.getElementById('totalAmount'),
  itemCount: document.getElementById('itemCount'),
  lowCount: document.getElementById('lowCount'),
  lastUpdated: document.getElementById('lastUpdated'),
  selectedChestName: document.getElementById('selectedChestName'),
  selectedChestDesc: document.getElementById('selectedChestDesc'),
  searchInput: document.getElementById('searchInput'),
  statusFilter: document.getElementById('statusFilter'),
  farmTabs: document.getElementById('farmTabs'),
  inventoryGrid: document.getElementById('inventoryGrid'),
  itemDetail: document.getElementById('itemDetail'),
  dashboardSubtitle: document.getElementById('dashboardSubtitle'),
  refreshButton: document.getElementById('refreshButton')
};

// 將文字轉成安全的 HTML，避免資料內容包含特殊字元時破壞頁面。
function escapeHtml(value) {
  return String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}

// 統一使用台灣地區數字格式，例如 2,048。
function formatNumber(value) {
  return Number(value || 0).toLocaleString('zh-TW');
}

// 清理 NamespaceID 的空白，例如 minecraft:diamond。
function normalizeNamespaceId(value) {
  return String(value || '').trim();
}

// 將 minecraft: 前綴移除，方便跟素材表的 key 比對。
function normalizeNamespaceKey(value) {
  return normalizeNamespaceId(value).replace(/^minecraft:/i, '');
}

// 從多個可能欄位中取第一個有值的資料，處理不同 JSON 格式的相容性。
function firstDefined(...values) {
  return values.find(value => value !== undefined && value !== null && String(value).trim() !== '') || '';
}

// 將素材圖片路徑整理成瀏覽器可以直接載入的 URL。
function normalizeImagePath(path) {
  const rawPath = firstDefined(path);
  if (!rawPath) return '';

  const cleanPath = String(rawPath).replaceAll('\\', '/').replace(/^\/+/, '');
  if (/^(https?:)?\/\//i.test(cleanPath) || cleanPath.startsWith('data:')) return cleanPath;
  if (cleanPath.startsWith('game_assets/')) return cleanPath;
  if (cleanPath.startsWith('ResourcePack_v')) return `game_assets/${cleanPath}`;
  if (cleanPath.startsWith('textures/')) return `${RESOURCE_PACK_PREFIX}${cleanPath}`;
  return cleanPath;
}

// 把 namespace_assets.json 裡單筆素材資料整理成統一格式。
function normalizeAssetEntry(namespaceId, entry) {
  const source = entry && typeof entry === 'object' ? entry : { image: entry };
  const id = normalizeNamespaceKey(firstDefined(
    source.NamespaceID,
    source.namespace_id,
    source.namespace,
    source.id,
    namespaceId
  ));

  if (!id) return null;

  return {
    NamespaceID: id,
    name: firstDefined(source.name_zh, source.zh_name, source.nameZh, source.name, source.display_name),
    category: firstDefined(source.category, source.type, source.group),
    image: normalizeImagePath(firstDefined(source.image, source.image_path, source.texture, source.icon, source.path))
  };
}

// 把素材 JSON 轉成以 NamespaceID 為 key 的查詢表，之後渲染物品時會用到。
function normalizeAssetMap(payload) {
  const map = {};

  if (Array.isArray(payload)) {
    payload.forEach(entry => {
      const normalized = normalizeAssetEntry(null, entry);
      if (normalized) map[normalized.NamespaceID] = normalized;
    });
    return map;
  }

  if (payload && typeof payload === 'object') {
    Object.entries(payload).forEach(([key, entry]) => {
      const normalized = normalizeAssetEntry(key, entry);
      if (normalized) map[normalized.NamespaceID] = normalized;
    });
  }

  return map;
}

// 依照物品 NamespaceID 找出對應的名稱、分類與圖片資料。
function getAssetEntry(namespaceId) {
  const rawId = normalizeNamespaceId(namespaceId);
  const cleanId = normalizeNamespaceKey(rawId);
  return namespaceAssets[rawId] || namespaceAssets[cleanId] || null;
}

// 以下 get* 函式統一處理顯示用欄位，避免資料缺欄位時畫面直接壞掉。
function getFarmLabel(farm) {
  return firstDefined(farm.farm_name, farm.name, farm.farm_code);
}

function getItemName(item) {
  const asset = getAssetEntry(item.NamespaceID);
  return firstDefined(item.name, item.name_zh, asset?.name, item.NamespaceID);
}

function getItemCategory(item) {
  const asset = getAssetEntry(item.NamespaceID);
  return firstDefined(item.category, asset?.category, '物品');
}

function getItemImage(item) {
  const asset = getAssetEntry(item.NamespaceID);
  return firstDefined(item.image, asset?.image);
}

function getItemKey(item) {
  return `${item.farm_code}::${item.NamespaceID}`;
}

// 用數量判斷庫存狀態，供篩選、顏色樣式與摘要統計使用。
function getStockStatus(item) {
  const amount = Number(item.amount || 0);
  if (amount <= LOW_STOCK_THRESHOLD) return 'low';
  if (amount >= FULL_STOCK_THRESHOLD) return 'full';
  return 'normal';
}

// 將狀態代碼轉成畫面上顯示的文字。
function getStatusText(status) {
  return {
    low: '低庫存',
    normal: '正常',
    full: '滿倉'
  }[status] || '正常';
}

// 庫存格空間有限，數量太大時改成 k/m 顯示。
function compactAmount(value) {
  const amount = Number(value || 0);
  if (amount >= 1000000) return `${Math.floor(amount / 1000000)}m`;
  if (amount >= 10000) return `${Math.floor(amount / 1000)}k`;
  return String(amount);
}

// 將 PHP API 回傳的 JSON 整理成前端固定使用的 farm/items 結構。
function normalizeInventoryData(payload) {
  const source = Array.isArray(payload) ? payload : (payload?.data || payload?.farms || payload?.inventory || []);
  const data = Array.isArray(source) ? source : (source && typeof source === 'object' ? [source] : []);

  return data.map((farm, index) => {
    const items = Array.isArray(farm.items) ? farm.items : [];
    const farmCode = farm.farm_code || farm.code || `storage_${index + 1}`;

    return {
      farm_code: farmCode,
      farm_name: farm.farm_name || farm.name || farmCode,
      description: farm.description || '',
      updated_at: farm.updated_at || farm.latest_updated_at || '',
      items: items
        .filter(item => item && (item.NamespaceID || item.namespace_id || item.namespace || item.id))
        .map(item => ({
          NamespaceID: item.NamespaceID || item.namespace_id || item.namespace || item.id,
          name: item.name || item.name_zh || '',
          category: item.category || '',
          image: item.image || '',
          amount: Number(item.amount || item.count || item.qty || 0),
          updated_at: item.updated_at || farm.updated_at || ''
        }))
    };
  });
}

// 找出目前選到的農場；選「全部」時回傳 null。
function getSelectedFarm() {
  if (selectedFarmCode === 'all') return null;
  return inventoryData.find(farm => farm.farm_code === selectedFarmCode) || null;
}

// 依目前農場選擇攤平成物品清單，方便後續篩選與渲染。
function getDisplayItems() {
  const farms = selectedFarmCode === 'all'
    ? inventoryData
    : inventoryData.filter(farm => farm.farm_code === selectedFarmCode);

  return farms.flatMap(farm => (farm.items || []).map(item => ({
    ...item,
    farm_code: farm.farm_code,
    farm_name: getFarmLabel(farm),
    farm_description: farm.description
  })));
}

// 套用狀態篩選與搜尋關鍵字，回傳實際要顯示的物品。
function filterInventory() {
  let items = getDisplayItems();
  const keyword = searchKeyword.trim().toLowerCase();

  if (selectedStatus !== 'all') {
    items = items.filter(item => getStockStatus(item) === selectedStatus);
  }

  if (keyword) {
    items = items.filter(item => {
      const name = getItemName(item).toLowerCase();
      const namespaceId = normalizeNamespaceId(item.NamespaceID).toLowerCase();
      return name.includes(keyword) || namespaceId.includes(keyword);
    });
  }

  return items;
}

// 渲染上方農場分頁按鈕，並顯示每個農場的物品數。
function renderFarmTabs() {
  const tabs = [{ farm_code: 'all', farm_name: '全部容器', items: [] }, ...inventoryData];

  elements.farmTabs.innerHTML = tabs.map(farm => {
    const farmCode = farm.farm_code;
    const isActive = selectedFarmCode === farmCode;
    const count = farmCode === 'all'
      ? inventoryData.reduce((sum, itemFarm) => sum + (itemFarm.items || []).length, 0)
      : (farm.items || []).length;

    return `
      <button class="chest-tab ${isActive ? 'active' : ''}" type="button" data-farm-code="${escapeHtml(farmCode)}">
        <span>${escapeHtml(farmCode === 'all' ? '全部' : getFarmLabel(farm))}</span>
        <strong>${formatNumber(count)}</strong>
      </button>
    `;
  }).join('');
}

// 計算並渲染總數、品項數、低庫存數量與最後更新時間。
function renderSummary(items) {
  const selectedFarm = getSelectedFarm();
  const total = items.reduce((sum, item) => sum + Number(item.amount || 0), 0);
  const lowCount = items.filter(item => getStockStatus(item) === 'low').length;
  const lastUpdated = inventoryData
    .flatMap(farm => [farm.updated_at, ...(farm.items || []).map(item => item.updated_at)])
    .filter(Boolean)
    .sort()
    .pop();

  elements.totalAmount.textContent = formatNumber(total);
  elements.itemCount.textContent = formatNumber(items.length);
  elements.lowCount.textContent = formatNumber(lowCount);
  elements.lastUpdated.textContent = lastUpdated || '--';
  elements.selectedChestName.textContent = selectedFarm ? getFarmLabel(selectedFarm) : '全部容器';
  elements.selectedChestDesc.textContent = selectedFarm?.description || `目前顯示 ${formatNumber(items.length)} 個物品格`;
  elements.dashboardSubtitle.textContent = statusMessage || `正在顯示最新 JSON 的 ${formatNumber(items.length)} 個物品格`;
}

// 沒有圖片時用 NamespaceID 第一個字母當作替代圖示。
function renderPlaceholderIcon(namespaceId) {
  const label = normalizeNamespaceKey(namespaceId).charAt(0).toUpperCase() || '?';
  return `<span class="slot-placeholder">${escapeHtml(label)}</span>`;
}

// 渲染物品圖片；圖片載入失敗時會呼叫 handleItemImageError() 換成替代圖示。
function renderItemIcon(item) {
  const image = getItemImage(item);
  if (!image) return renderPlaceholderIcon(item.NamespaceID);

  return `
    <img
      src="${escapeHtml(image)}"
      alt=""
      data-namespace-id="${escapeHtml(item.NamespaceID)}"
      onerror="handleItemImageError(this)"
    >
  `;
}

// 組出滑鼠提示文字，內容包含名稱、NamespaceID、數量、狀態與農場。
function getTooltip(item) {
  return [
    getItemName(item),
    normalizeNamespaceId(item.NamespaceID),
    `數量：${formatNumber(item.amount)}`,
    `狀態：${getStatusText(getStockStatus(item))}`,
    `倉庫：${item.farm_name}`
  ].join('\n');
}

// 主要畫面渲染函式：重新畫農場分頁、摘要與物品格子。
function renderInventory() {
  const items = filterInventory();
  const selectedFarm = getSelectedFarm();

  renderFarmTabs();
  renderSummary(items);

  if (!items.length) {
    elements.inventoryGrid.innerHTML = '<div class="empty-state">找不到符合條件的物品</div>';
    return;
  }

  elements.inventoryGrid.innerHTML = items.map(item => {
    const status = getStockStatus(item);
    const itemKey = getItemKey(item);

    return `
      <button
        class="item-slot ${status} ${selectedItemKey === itemKey ? 'active' : ''}"
        type="button"
        data-item-key="${escapeHtml(itemKey)}"
        data-tooltip="${escapeHtml(getTooltip(item))}"
        aria-label="${escapeHtml(`${getItemName(item)} ${formatNumber(item.amount)}`)}"
      >
        ${renderItemIcon(item)}
        <span class="slot-count">${escapeHtml(compactAmount(item.amount))}</span>
      </button>
    `;
  }).join('');

  if (selectedFarm) {
    elements.dashboardSubtitle.textContent = `${getFarmLabel(selectedFarm)} - ${formatNumber(items.length)} 個物品格`;
  }
}

// 用前端唯一 key 找出目前點選的物品。
function findItemByKey(itemKey) {
  return getDisplayItems().find(item => getItemKey(item) === itemKey) || null;
}

// 點選物品後，在右側詳細欄顯示該物品資訊。
function showItemDetail(itemKey) {
  const item = findItemByKey(itemKey);
  if (!item) return;

  selectedItemKey = itemKey;
  const status = getStockStatus(item);

  elements.itemDetail.className = `item-detail ${status}`;
  elements.itemDetail.innerHTML = `
    <div class="detail-slot">
      ${renderItemIcon(item)}
      <span class="slot-count">${escapeHtml(compactAmount(item.amount))}</span>
    </div>
    <h3>${escapeHtml(getItemName(item))}</h3>
    <dl>
      <div><dt>NamespaceID</dt><dd>${escapeHtml(item.NamespaceID)}</dd></div>
      <div><dt>數量</dt><dd>${formatNumber(item.amount)}</dd></div>
      <div><dt>狀態</dt><dd>${escapeHtml(getStatusText(status))}</dd></div>
      <div><dt>分類</dt><dd>${escapeHtml(getItemCategory(item))}</dd></div>
      <div><dt>倉庫</dt><dd>${escapeHtml(item.farm_name)}</dd></div>
      <div><dt>更新時間</dt><dd>${escapeHtml(item.updated_at || '--')}</dd></div>
    </dl>
  `;

  renderInventory();
}

// 清空右側詳細欄，回到尚未選取物品的狀態。
function resetItemDetail() {
  selectedItemKey = null;
  elements.itemDetail.className = 'item-detail empty';
  elements.itemDetail.innerHTML = `
    <div class="detail-slot"><span class="slot-placeholder">?</span></div>
    <h3>尚未選取物品格</h3>
    <p>將滑鼠移到物品格上可查看提示，點擊後會固定顯示在這裡。</p>
  `;
}

// 圖片載入失敗時移除 img，避免破圖，改顯示文字替代圖示。
function handleItemImageError(img) {
  const namespaceId = img.dataset.namespaceId || '';
  const wrapper = img.closest('.item-slot, .detail-slot');
  if (!wrapper) return;
  img.remove();
  wrapper.insertAdjacentHTML('afterbegin', renderPlaceholderIcon(namespaceId));
}

window.handleItemImageError = handleItemImageError;

// 載入素材對照表；只載入一次，避免每次刷新庫存都重複下載。
async function loadAssetMap() {
  if (assetMapLoaded) return;

  try {
    const response = await fetch(ASSET_MAP_URL, { cache: 'no-store' });
    if (!response.ok) throw new Error(`HTTP ${response.status}`);
    namespaceAssets = normalizeAssetMap(await response.json());
  } catch (error) {
    namespaceAssets = {};
  } finally {
    assetMapLoaded = true;
  }
}

// 呼叫 inventory.php 取得庫存 JSON，整理資料後更新整個畫面。
async function loadInventory() {
  try {
    elements.refreshButton.disabled = true;
    elements.refreshButton.textContent = '載入中';

    await loadAssetMap();

    const response = await fetch(INVENTORY_API_URL, { cache: 'no-store' });
    if (!response.ok) throw new Error(`HTTP ${response.status}`);

    inventoryData = normalizeInventoryData(await response.json());

    const selectedFarmExists = selectedFarmCode === 'all'
      || inventoryData.some(farm => farm.farm_code === selectedFarmCode);
    if (!selectedFarmExists) selectedFarmCode = 'all';

    const selectedItemStillExists = selectedItemKey && findItemByKey(selectedItemKey);
    if (!selectedItemStillExists) resetItemDetail();

    renderInventory();
    if (selectedItemStillExists) showItemDetail(selectedItemKey);
  } catch (error) {
    elements.inventoryGrid.innerHTML = `<div class="empty-state">載入失敗：${escapeHtml(error.message)}</div>`;
  } finally {
    elements.refreshButton.disabled = false;
    elements.refreshButton.textContent = '刷新';
  }
}

// 呼叫 sync_data.php 要求後端把 data/*.json 同步到 MySQL，再重新載入畫面。
async function refreshData() {
  if (isRefreshing) return;

  isRefreshing = true;

  try {
    elements.refreshButton.disabled = true;
    elements.refreshButton.textContent = '同步中';

    // 重新整理時也呼叫下載腳本；失敗不阻擋後面的資料庫同步。
    try {
      const downloadResponse = await fetch(DOWNLOAD_JSON_API_URL, { cache: 'no-store' });
      await downloadResponse.text();
    } catch (downloadError) {
      console.warn('download_json.php failed, continue sync_data.php:', downloadError);
    }

    const response = await fetch(SYNC_API_URL, { method: 'POST', cache: 'no-store' });
    const result = await response.json();

    if (!response.ok || !result.success) {
      throw new Error(result.error || result.message || '同步失敗');
    }

    statusMessage = `已同步 ${result.files_processed} 個檔案，${result.items_processed} 個物品`;
  } catch (error) {
    statusMessage = `同步失敗：${error.message}`;
  } finally {
    await loadInventory();
    isRefreshing = false;
  }
}

// 搜尋框輸入時，只更新前端篩選，不需要重新打 API。
elements.searchInput.addEventListener('input', event => {
  searchKeyword = event.target.value;
  renderInventory();
});

// 狀態下拉選單切換時，重新套用前端篩選。
elements.statusFilter.addEventListener('change', event => {
  selectedStatus = event.target.value;
  renderInventory();
});

// 點農場分頁時切換目前農場，並清除右側物品詳細資訊。
elements.farmTabs.addEventListener('click', event => {
  const tab = event.target.closest('[data-farm-code]');
  if (!tab) return;

  selectedFarmCode = tab.dataset.farmCode;
  resetItemDetail();
  renderInventory();
});

// 點庫存格時顯示該物品的詳細資料。
elements.inventoryGrid.addEventListener('click', event => {
  const slot = event.target.closest('[data-item-key]');
  if (!slot) return;
  showItemDetail(slot.dataset.itemKey);
});

// 手動刷新按鈕：觸發後端同步資料，再重讀庫存。
elements.refreshButton.addEventListener('click', refreshData);

// 初始化畫面，並每 10 秒自動重新讀取一次庫存 API。
resetItemDetail();
loadInventory();
setInterval(loadInventory, 10000);
