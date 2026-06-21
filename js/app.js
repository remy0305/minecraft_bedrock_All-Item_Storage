const INVENTORY_API_URL = 'api/inventory.php';
const SYNC_API_URL = 'api/sync_data.php';
const LOW_STOCK_THRESHOLD = 64;
const FULL_STOCK_THRESHOLD = 2048;

let farms = [];
let currentFarmCode = 'all';
let selectedStatus = 'all';
let selectedItemKey = null;
let searchKeyword = '';
let statusMessage = '';
let isRefreshing = false;

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

function escapeHtml(value) {
  return String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}

function formatNumber(value) {
  return Number(value || 0).toLocaleString('zh-TW');
}

function normalizeNamespaceId(value) {
  return String(value || '').trim();
}

function normalizeNamespaceKey(value) {
  return normalizeNamespaceId(value).replace(/^minecraft:/i, '');
}

function getFarmLabel(farm) {
  return farm.farm_name || farm.farm_code;
}

function getItemName(item) {
  return item.name_zh || item.name || item.NamespaceID;
}

function getItemImage(item) {
  return item.image || '';
}

function getItemKey(item) {
  return `${item.farm_code}::${item.NamespaceID}`;
}

function getStockStatus(item) {
  const amount = Number(item.amount || 0);
  if (amount <= LOW_STOCK_THRESHOLD) return 'low';
  if (amount >= FULL_STOCK_THRESHOLD) return 'full';
  return 'normal';
}

function getStatusText(status) {
  return {
    low: '低庫存',
    normal: '正常',
    full: '高庫存'
  }[status] || '正常';
}

function compactAmount(value) {
  const amount = Number(value || 0);
  if (amount >= 1000000) return `${Math.floor(amount / 1000000)}m`;
  if (amount >= 10000) return `${Math.floor(amount / 1000)}k`;
  return String(amount);
}

function normalizeInventoryData(payload) {
  const source = Array.isArray(payload) ? payload : (payload?.farms || []);
  return source.map((farm, index) => {
    const farmCode = farm.farm_code || `farm_${index + 1}`;
    const items = Array.isArray(farm.items) ? farm.items : [];

    return {
      farm_code: farmCode,
      farm_name: farm.farm_name || farmCode,
      description: farm.description || '',
      updated_at: farm.updated_at || '',
      item_count: Number(farm.item_count ?? items.length),
      items: items
        .filter(item => item && item.NamespaceID)
        .map(item => ({
          NamespaceID: item.NamespaceID,
          name_zh: item.name_zh || '',
          image: item.image || '',
          amount: Number(item.amount || 0),
          updated_at: item.updated_at || farm.updated_at || '',
          farm_code: farmCode,
          farm_name: farm.farm_name || farmCode
        }))
    };
  });
}

function getSelectedFarm() {
  if (currentFarmCode === 'all') return null;
  return farms.find(farm => farm.farm_code === currentFarmCode) || null;
}

function getTotalFarmItemCount() {
  return farms.reduce((sum, farm) => sum + Number(farm.item_count ?? (farm.items || []).length), 0);
}

function getAllFarmItems() {
  const merged = new Map();

  farms.forEach(farm => {
    (farm.items || []).forEach(item => {
      const namespaceId = normalizeNamespaceId(item.NamespaceID);
      if (!namespaceId) return;

      const source = {
        farm_code: farm.farm_code,
        farm_name: getFarmLabel(farm),
        amount: Number(item.amount || 0),
        updated_at: item.updated_at || farm.updated_at || ''
      };

      if (!merged.has(namespaceId)) {
        merged.set(namespaceId, {
          ...item,
          NamespaceID: namespaceId,
          amount: 0,
          farm_code: 'all',
          farm_name: '全部',
          farm_sources: []
        });
      }

      const aggregate = merged.get(namespaceId);
      aggregate.amount += source.amount;
      aggregate.farm_sources.push(source);
      if (!aggregate.updated_at || source.updated_at > aggregate.updated_at) {
        aggregate.updated_at = source.updated_at;
      }
    });
  });

  return Array.from(merged.values()).sort((a, b) => {
    const amountDiff = Number(b.amount || 0) - Number(a.amount || 0);
    return amountDiff || normalizeNamespaceId(a.NamespaceID).localeCompare(normalizeNamespaceId(b.NamespaceID));
  });
}

function getDisplayItems() {
  if (currentFarmCode === 'all') return getAllFarmItems();
  return farms
    .filter(farm => farm.farm_code === currentFarmCode)
    .flatMap(farm => (farm.items || []).map(item => ({
      ...item,
      farm_code: farm.farm_code,
      farm_name: getFarmLabel(farm),
      farm_description: farm.description
    })));
}

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

function renderFarmTabs() {
  const tabs = [{ farm_code: 'all', farm_name: '全部', item_count: getTotalFarmItemCount(), items: [] }, ...farms];

  elements.farmTabs.innerHTML = tabs.map(farm => {
    const farmCode = farm.farm_code;
    const isActive = currentFarmCode === farmCode;
    const count = farmCode === 'all'
      ? getTotalFarmItemCount()
      : Number(farm.item_count ?? (farm.items || []).length);

    return `
      <button class="chest-tab ${isActive ? 'active' : ''}" type="button" data-farm-code="${escapeHtml(farmCode)}">
        <span>${escapeHtml(farmCode === 'all' ? '全部' : getFarmLabel(farm))}</span>
        <strong>${formatNumber(count)}</strong>
      </button>
    `;
  }).join('');
}

function renderSummary(items) {
  const selectedFarm = getSelectedFarm();
  const total = items.reduce((sum, item) => sum + Number(item.amount || 0), 0);
  const lowCount = items.filter(item => getStockStatus(item) === 'low').length;
  const lastUpdated = farms
    .flatMap(farm => [farm.updated_at, ...(farm.items || []).map(item => item.updated_at)])
    .filter(Boolean)
    .sort()
    .pop();

  elements.totalAmount.textContent = formatNumber(total);
  elements.itemCount.textContent = formatNumber(items.length);
  elements.lowCount.textContent = formatNumber(lowCount);
  elements.lastUpdated.textContent = lastUpdated || '--';
  elements.selectedChestName.textContent = selectedFarm ? getFarmLabel(selectedFarm) : '全部庫存';
  elements.selectedChestDesc.textContent = selectedFarm?.description || `目前顯示 ${formatNumber(items.length)} 種物品`;
  elements.dashboardSubtitle.textContent = statusMessage || `${selectedFarm ? getFarmLabel(selectedFarm) : '全部'}：${formatNumber(items.length)} 種物品`;
}

function renderPlaceholderIcon(namespaceId) {
  const label = normalizeNamespaceKey(namespaceId).charAt(0).toUpperCase() || '?';
  return `<span class="slot-placeholder">${escapeHtml(label)}</span>`;
}

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

function getTooltip(item) {
  const sourceText = Array.isArray(item.farm_sources) && item.farm_sources.length
    ? item.farm_sources.map(source => `${source.farm_name} ${formatNumber(source.amount)}`).join('\n')
    : item.farm_name;

  return [
    getItemName(item),
    normalizeNamespaceId(item.NamespaceID),
    `數量：${formatNumber(item.amount)}`,
    `狀態：${getStatusText(getStockStatus(item))}`,
    `來源：${sourceText}`
  ].join('\n');
}

function getItemSourceText(item) {
  if (!Array.isArray(item.farm_sources) || !item.farm_sources.length) {
    return item.farm_name || '--';
  }

  return item.farm_sources
    .map(source => `${source.farm_name}: ${formatNumber(source.amount)}`)
    .join('\n');
}

function renderInventory() {
  const items = filterInventory();
  const selectedFarm = getSelectedFarm();

  renderFarmTabs();
  renderSummary(items);

  if (!items.length) {
    elements.inventoryGrid.innerHTML = '<div class="empty-state">沒有符合條件的物品</div>';
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
    elements.dashboardSubtitle.textContent = `${getFarmLabel(selectedFarm)}：${formatNumber(items.length)} 種物品`;
  }
}

function findItemByKey(itemKey) {
  return getDisplayItems().find(item => getItemKey(item) === itemKey) || null;
}

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
      <div><dt>來源</dt><dd>${escapeHtml(getItemSourceText(item))}</dd></div>
      <div><dt>更新時間</dt><dd>${escapeHtml(item.updated_at || '--')}</dd></div>
    </dl>
  `;

  renderInventory();
}

function resetItemDetail() {
  selectedItemKey = null;
  elements.itemDetail.className = 'item-detail empty';
  elements.itemDetail.innerHTML = `
    <div class="detail-slot"><span class="slot-placeholder">?</span></div>
    <h3>選擇一個物品</h3>
    <p>點選左側庫存格查看物品明細。</p>
  `;
}

function handleItemImageError(img) {
  const namespaceId = img.dataset.namespaceId || '';
  const wrapper = img.closest('.item-slot, .detail-slot');
  if (!wrapper) return;
  img.remove();
  wrapper.insertAdjacentHTML('afterbegin', renderPlaceholderIcon(namespaceId));
}

window.handleItemImageError = handleItemImageError;

async function loadInventory() {
  try {
    elements.refreshButton.disabled = true;
    elements.refreshButton.textContent = '載入中';

    const response = await fetch(INVENTORY_API_URL, { cache: 'no-store' });
    if (!response.ok) throw new Error(`HTTP ${response.status}`);

    const payload = await response.json();
    if (payload && payload.success === false) {
      throw new Error(payload.error || '載入失敗');
    }

    farms = normalizeInventoryData(payload);

    const selectedFarmExists = currentFarmCode === 'all'
      || farms.some(farm => farm.farm_code === currentFarmCode);
    if (!selectedFarmExists) currentFarmCode = 'all';

    const selectedItemStillExists = selectedItemKey && findItemByKey(selectedItemKey);
    if (!selectedItemStillExists) resetItemDetail();

    renderInventory();
    if (selectedItemStillExists) showItemDetail(selectedItemKey);
  } catch (error) {
    elements.inventoryGrid.innerHTML = `<div class="empty-state">載入失敗：${escapeHtml(error.message)}</div>`;
  } finally {
    elements.refreshButton.disabled = false;
    elements.refreshButton.textContent = '同步';
  }
}

async function refreshData() {
  if (isRefreshing) return;
  isRefreshing = true;

  try {
    elements.refreshButton.disabled = true;
    elements.refreshButton.textContent = '同步中';

    const response = await fetch(SYNC_API_URL, { method: 'POST', cache: 'no-store' });
    const result = await response.json();

    if (!response.ok || !result.success) {
      throw new Error(result.error || result.message || '同步失敗');
    }

    statusMessage = `已同步 ${result.farms_imported || 0} 個農場，${result.inventory_rows || 0} 筆庫存`;
    if (Array.isArray(result.errors) && result.errors.length) {
      statusMessage += `，${result.errors.length} 個檔案有錯誤`;
    }
  } catch (error) {
    statusMessage = `同步失敗：${error.message}`;
  } finally {
    await loadInventory();
    isRefreshing = false;
  }
}

elements.searchInput.addEventListener('input', event => {
  searchKeyword = event.target.value;
  renderInventory();
});

elements.statusFilter.addEventListener('change', event => {
  selectedStatus = event.target.value;
  renderInventory();
});

elements.farmTabs.addEventListener('click', event => {
  const tab = event.target.closest('[data-farm-code]');
  if (!tab) return;

  currentFarmCode = tab.dataset.farmCode;
  resetItemDetail();
  renderInventory();
});

elements.inventoryGrid.addEventListener('click', event => {
  const slot = event.target.closest('[data-item-key]');
  if (!slot) return;
  showItemDetail(slot.dataset.itemKey);
});

elements.refreshButton.addEventListener('click', refreshData);

resetItemDetail();
loadInventory();
setInterval(loadInventory, 10000);
