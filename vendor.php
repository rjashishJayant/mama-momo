<?php
require_once __DIR__ . '/api/config.php';

// Local menu sync & status handler
if (isset($_GET['action']) && ($_GET['action'] === 'sync_menu' || $_GET['action'] === 'menu_status')) {
    header('Content-Type: application/json; charset=utf-8');
    $force = ($_GET['action'] === 'sync_menu');
    $meta = [];
    $menu = getDynamicMenu($force, $meta);
    $liveCount = 0;
    $draftCount = 0;
    $soldOutCount = 0;
    foreach ($menu as $item) {
        $isView = !isset($item['viewOnMenu']) || (bool)$item['viewOnMenu'];
        $isDraft = (isset($item['status']) && strtolower($item['status']) === 'draft') || !$isView;
        if ($isDraft) {
            $draftCount++;
        } else {
            $liveCount++;
            if (isset($item['available']) && !$item['available']) {
                $soldOutCount++;
            }
        }
    }
    echo json_encode([
        'success' => true,
        'count' => count($menu),
        'liveCount' => $liveCount,
        'draftCount' => $draftCount,
        'soldOutCount' => $soldOutCount,
        'meta' => $meta,
        'items' => array_values($menu)
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// Local order lookup handler
if (isset($_GET['action']) && $_GET['action'] === 'lookup') {
    header('Content-Type: application/json; charset=utf-8');
    $token = strtoupper(trim((string)($_GET['token'] ?? '')));
    $projCache = __DIR__ . '/orders_cache.json';
    $tempCache = sys_get_temp_dir() . '/mama_momo_orders.json';

    if ($token !== '') {
        if (file_exists($projCache)) {
            $cachedP = json_decode(@file_get_contents($projCache), true) ?: [];
            if (isset($cachedP[$token])) {
                echo json_encode(['success' => true, 'order' => $cachedP[$token]]);
                exit;
            }
        }
        if (file_exists($tempCache)) {
            $cachedT = json_decode(@file_get_contents($tempCache), true) ?: [];
            if (isset($cachedT[$token])) {
                echo json_encode(['success' => true, 'order' => $cachedT[$token]]);
                exit;
            }
        }
    }
    echo json_encode(['success' => false, 'message' => 'Token not found in local cache']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Mama Momo - Vendor Terminal</title>
  <style>
    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    }
    body {
      background: #111827;
      color: #ffffff;
      padding: 20px;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .terminal-card {
      max-width: 480px;
      width: 100%;
      background: #1f2937;
      padding: 28px 24px;
      border-radius: 18px;
      box-shadow: 0 15px 35px rgba(0, 0, 0, 0.4);
      border: 1px solid #374151;
      position: relative;
    }
    h2 {
      text-align: center;
      color: #ef4444;
      font-size: 1.6rem;
      margin-bottom: 6px;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
    }
    .subtitle {
      font-size: 0.85rem;
      color: #9ca3af;
      text-align: center;
      margin-bottom: 22px;
    }
    .search-box {
      background: #111827;
      border: 2px solid #374151;
      padding: 18px;
      border-radius: 14px;
      margin-bottom: 20px;
      transition: border-color 0.2s;
    }
    .search-box:focus-within {
      border-color: #ef4444;
    }
    .search-label {
      font-size: 0.85rem;
      text-transform: uppercase;
      letter-spacing: 1px;
      color: #9ca3af;
      font-weight: 700;
      display: block;
      margin-bottom: 8px;
    }
    .token-input-group {
      display: flex;
      gap: 10px;
    }
    #orderToken {
      flex: 1;
      padding: 14px 16px;
      background: #1f2937;
      border: 1px solid #4b5563;
      border-radius: 10px;
      color: #ffffff;
      font-size: 1.25rem;
      font-weight: 800;
      letter-spacing: 2px;
      text-transform: uppercase;
      outline: none;
    }
    #orderToken:focus {
      border-color: #ef4444;
    }
    .btn-check {
      background: #ef4444;
      color: #ffffff;
      border: 0;
      padding: 0 20px;
      border-radius: 10px;
      font-weight: 700;
      font-size: 1rem;
      cursor: pointer;
      display: flex;
      align-items: center;
      gap: 6px;
      white-space: nowrap;
      transition: background 0.15s, transform 0.1s;
    }
    .btn-check:active {
      transform: scale(0.97);
      background: #dc2626;
    }
    .search-tip {
      font-size: 0.78rem;
      color: #6b7280;
      margin-top: 8px;
    }

    /* Modal Overlay & Card */
    .modal-overlay {
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background: rgba(0, 0, 0, 0.75);
      backdrop-filter: blur(6px);
      display: none;
      align-items: center;
      justify-content: center;
      padding: 20px;
      z-index: 1000;
      animation: fadeIn 0.2s ease-out;
    }
    @keyframes fadeIn {
      from { opacity: 0; }
      to { opacity: 1; }
    }
    .modal-card {
      background: #1f2937;
      border: 1px solid #374151;
      border-radius: 20px;
      max-width: 440px;
      width: 100%;
      padding: 26px 24px;
      box-shadow: 0 25px 50px rgba(0, 0, 0, 0.5);
      animation: slideUp 0.25s cubic-bezier(0.16, 1, 0.3, 1);
      position: relative;
    }
    @keyframes slideUp {
      from { transform: translateY(20px) scale(0.96); opacity: 0; }
      to { transform: translateY(0) scale(1); opacity: 1; }
    }
    .modal-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      border-bottom: 1px solid #374151;
      padding-bottom: 14px;
      margin-bottom: 16px;
    }
    .modal-title {
      font-size: 1.15rem;
      font-weight: 700;
      color: #f3f4f6;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .token-badge {
      background: #ef4444;
      color: #fff;
      padding: 4px 10px;
      border-radius: 6px;
      font-size: 0.95rem;
      font-weight: 900;
      letter-spacing: 1px;
    }
    .btn-close {
      background: transparent;
      border: 0;
      color: #9ca3af;
      font-size: 1.5rem;
      cursor: pointer;
      line-height: 1;
      padding: 4px 8px;
    }
    .btn-close:hover {
      color: #ffffff;
    }

    /* Customer Info Box inside Modal */
    .cust-info-card {
      background: #111827;
      border-radius: 12px;
      padding: 16px;
      margin-bottom: 14px;
      border: 1px solid #374151;
    }
    .cust-row {
      display: flex;
      justify-content: space-between;
      margin-bottom: 8px;
      font-size: 0.95rem;
    }
    .cust-row:last-child {
      margin-bottom: 0;
    }
    .cust-label {
      color: #9ca3af;
    }
    .cust-val {
      font-weight: 700;
      color: #f9fafb;
    }
    .items-box {
      background: #283548;
      padding: 12px;
      border-radius: 8px;
      font-size: 0.9rem;
      color: #e5e7eb;
      margin-bottom: 14px;
      line-height: 1.4;
      border-left: 3px solid #ef4444;
    }
    .price-loyalty-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      background: #111827;
      padding: 14px;
      border-radius: 12px;
      margin-bottom: 18px;
      border: 1px solid #374151;
    }
    .price-wrap {
      display: flex;
      flex-direction: column;
    }
    .price-label {
      font-size: 0.75rem;
      color: #9ca3af;
      text-transform: uppercase;
    }
    .modal-amount {
      font-size: 1.6rem;
      font-weight: 800;
      color: #22c55e;
    }
    .loyalty-pill {
      background: #065f46;
      color: #6ee7b7;
      padding: 6px 12px;
      border-radius: 20px;
      font-size: 0.8rem;
      font-weight: 700;
      border: 1px solid #059669;
    }
    .loyalty-pill.discount-active {
      background: #831843;
      color: #fbcfe8;
      border-color: #be185d;
    }

    /* Modal PIN & Confirmation */
    .pin-group {
      margin-bottom: 18px;
    }
    .pin-label {
      font-size: 0.85rem;
      color: #cbd5e1;
      font-weight: 600;
      display: block;
      margin-bottom: 6px;
    }
    .pin-input {
      width: 100%;
      padding: 12px 14px;
      background: #111827;
      border: 1px solid #4b5563;
      border-radius: 8px;
      color: #ffffff;
      font-size: 1.1rem;
      letter-spacing: 2px;
      outline: none;
    }
    .pin-input:focus {
      border-color: #ef4444;
    }
    .modal-actions {
      display: flex;
      gap: 10px;
    }
    .btn-confirm {
      flex: 1;
      background: #22c55e;
      color: #ffffff;
      border: 0;
      padding: 14px;
      border-radius: 10px;
      font-size: 1.05rem;
      font-weight: 800;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      transition: background 0.15s, transform 0.1s;
    }
    .btn-confirm:hover {
      background: #16a34a;
    }
    .btn-confirm:active {
      transform: scale(0.98);
    }
    .btn-confirm:disabled {
      opacity: 0.6;
      cursor: wait;
    }
    .btn-cancel {
      background: #374151;
      color: #d1d5db;
      border: 0;
      padding: 14px 18px;
      border-radius: 10px;
      font-weight: 600;
      cursor: pointer;
    }
    .modal-msg {
      margin-top: 14px;
      padding: 12px;
      border-radius: 8px;
      font-size: 0.9rem;
      display: none;
      line-height: 1.4;
      text-align: center;
    }

    /* Recent Confirmations Feed */
    .recent-section {
      margin-top: 24px;
      border-top: 1px solid #374151;
      padding-top: 18px;
    }
    .recent-title {
      font-size: 0.85rem;
      text-transform: uppercase;
      letter-spacing: 1px;
      color: #9ca3af;
      margin-bottom: 12px;
      font-weight: 700;
    }
    .recent-item {
      background: #111827;
      border: 1px solid #374151;
      padding: 12px 14px;
      border-radius: 10px;
      margin-bottom: 8px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      font-size: 0.88rem;
    }
    .recent-left {
      display: flex;
      flex-direction: column;
      gap: 2px;
    }
    .recent-token {
      font-weight: 800;
      color: #ef4444;
      font-size: 0.95rem;
    }
    .recent-name {
      color: #d1d5db;
    }
    .recent-right {
      text-align: right;
    }
    .recent-price {
      font-weight: 700;
      color: #22c55e;
      font-size: 1rem;
    }
    .recent-stamp {
      font-size: 0.75rem;
      color: #9ca3af;
    }
  </style>
</head>
<body>
  <div class="terminal-card">
    <h2>🥟 Mama Momo Terminal</h2>
    <p class="subtitle">Stall Attendant Order Verification &amp; Loyalty Logger</p>

    <!-- Google Sheet Menu Sync Bar -->
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; background:#111827; padding:9px 13px; border-radius:10px; border:1px solid #374151;">
      <span style="font-size:0.8rem; color:#9ca3af; display:flex; align-items:center; gap:6px;">
        <span style="display:inline-block; width:8px; height:8px; border-radius:50%; background:#22c55e;"></span>
        <span id="menuSyncSummary">Menu: Google Sheet Synced</span>
      </span>
      <button type="button" id="syncMenuBtn" style="background:#374151; color:#f3f4f6; border:0; padding:5px 12px; border-radius:6px; font-size:0.78rem; font-weight:600; cursor:pointer;" onclick="syncMenuNow()">🔄 Sync Menu</button>
    </div>

    <div class="search-box">
      <label class="search-label" for="orderToken">Enter Customer Token</label>
      <div class="token-input-group">
        <input type="text" id="orderToken" placeholder="MMxxxx" maxlength="10" autocomplete="off" autofocus>
        <button type="button" id="checkOrderBtn" class="btn-check">🔍 Check Order</button>
      </div>
      <div class="search-tip">Customer details and order summary pop up automatically upon checking.</div>
    </div>

    <div class="recent-section">
      <div class="recent-title">📋 Today's Confirmed Orders</div>
      <div id="recentOrdersList">
        <div style="font-size: 0.85rem; color: #6b7280; text-align: center; padding: 12px;">No orders verified yet in this session.</div>
      </div>
    </div>
  </div>

  <!-- Good-Looking Customer Details Popup Modal -->
  <div id="orderModal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
    <div class="modal-card">
      <div class="modal-header">
        <div class="modal-title" id="modalTitle">
          🥟 Order: <span id="modalTokenBadge" class="token-badge">MMxxxx</span>
        </div>
        <button type="button" id="modalCloseBtn" class="btn-close" aria-label="Close modal">&times;</button>
      </div>

      <!-- Customer Details Card -->
      <div class="cust-info-card">
        <div class="cust-row">
          <span class="cust-label">👤 Customer:</span>
          <span class="cust-val" id="modalCustomerName">Customer</span>
        </div>
        <div class="cust-row">
          <span class="cust-label">📱 Mobile:</span>
          <span class="cust-val" id="modalCustomerPhone">98xxxxxxxx</span>
        </div>
      </div>

      <!-- Itemized Items -->
      <div class="items-box">
        <strong>🥟 Items:</strong>
        <div id="modalOrderItems" style="margin-top: 4px;">1 × Momo Plate</div>
      </div>

      <!-- Price & Loyalty Info -->
      <div class="price-loyalty-row">
        <div class="price-wrap">
          <span class="price-label">Payable Amount</span>
          <span class="modal-amount" id="modalFinalPrice">₹0.00</span>
        </div>
        <div id="modalLoyaltyPill" class="loyalty-pill">⭐ Visit #1</div>
      </div>

      <!-- Vendor PIN -->
      <div class="pin-group">
        <label class="pin-label" for="modalVendorPin">Stall Vendor PIN</label>
        <input type="password" id="modalVendorPin" class="pin-input" inputmode="numeric" placeholder="Enter PIN (1234)" value="1234" maxlength="32">
      </div>

      <!-- Actions -->
      <div class="modal-actions">
        <button type="button" id="modalCancelBtn" class="btn-cancel">Cancel</button>
        <button type="button" id="modalConfirmBtn" class="btn-confirm">
          <span>✅ Confirm &amp; Save to Sheet</span>
        </button>
      </div>

      <div id="modalMsg" class="modal-msg"></div>
    </div>
  </div>

  <script>
    const API_URL = <?= json_encode($APPS_SCRIPT_URL ?? "https://script.google.com/macros/s/AKfycbzgHkDCYHOk4z15LPAfFQ22a-BfY9_t4Q2LdUlsi4pYF4_uw0vpY3EXKuPcEU-W7pXfuA/exec") ?>;

    function syncMenuNow() {
      const btn = document.getElementById('syncMenuBtn');
      const summary = document.getElementById('menuSyncSummary');
      if (!btn) return;
      btn.textContent = 'Syncing…';
      btn.disabled = true;
      fetch('vendor.php?action=sync_menu')
        .then(r => r.json())
        .then(d => {
          const live = d.liveCount !== undefined ? d.liveCount : d.count;
          const draft = d.draftCount || 0;
          btn.textContent = '✓ Synced (' + live + ' live' + (draft ? ', ' + draft + ' draft' : '') + ')';
          if (summary) {
            summary.innerHTML = `Menu: <strong>${live} Live</strong>` + (draft ? ` • <span style="color:#f59e0b;">${draft} Draft</span>` : '');
          }
          setTimeout(() => { btn.textContent = '🔄 Sync Menu'; btn.disabled = false; }, 3500);
        })
        .catch(() => {
          btn.textContent = '⚠️ Failed';
          setTimeout(() => { btn.textContent = '🔄 Sync Menu'; btn.disabled = false; }, 3000);
        });
    }

    // Auto-load menu status on boot
    fetch('vendor.php?action=menu_status')
      .then(r => r.json())
      .then(d => {
        const summary = document.getElementById('menuSyncSummary');
        if (summary && d && d.success) {
          const live = d.liveCount !== undefined ? d.liveCount : d.count;
          const draft = d.draftCount || 0;
          summary.innerHTML = `Menu: <strong>${live} Live</strong>` + (draft ? ` • <span style="color:#f59e0b;">${draft} Draft</span>` : '');
        }
      })
      .catch(() => {});

    const tokenInput = document.getElementById('orderToken');
    const checkBtn = document.getElementById('checkOrderBtn');
    const modal = document.getElementById('orderModal');
    const modalCloseBtn = document.getElementById('modalCloseBtn');
    const modalCancelBtn = document.getElementById('modalCancelBtn');
    const modalConfirmBtn = document.getElementById('modalConfirmBtn');
    const modalMsg = document.getElementById('modalMsg');

    // Modal data fields
    const modalTokenBadge = document.getElementById('modalTokenBadge');
    const modalCustomerName = document.getElementById('modalCustomerName');
    const modalCustomerPhone = document.getElementById('modalCustomerPhone');
    const modalOrderItems = document.getElementById('modalOrderItems');
    const modalFinalPrice = document.getElementById('modalFinalPrice');
    const modalLoyaltyPill = document.getElementById('modalLoyaltyPill');
    const modalVendorPin = document.getElementById('modalVendorPin');
    const recentList = document.getElementById('recentOrdersList');

    // In-memory active order being verified in modal
    let activeModalOrder = null;
    let autoLookupDebounce = null;

    // Trigger check on click, enter, input (when 6 chars), or paste
    checkBtn.addEventListener('click', () => checkAndOpenModal(false));
    tokenInput.addEventListener('keydown', function(e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        checkAndOpenModal(false);
      }
    });

    // Auto-trigger modal popup when vendor enters or pastes the 6-character token
    tokenInput.addEventListener('input', function() {
      clearTimeout(autoLookupDebounce);
      const val = tokenInput.value.trim().toUpperCase();
      if (val.length === 6) {
        autoLookupDebounce = setTimeout(() => {
          checkAndOpenModal(true);
        }, 150);
      }
    });

    tokenInput.addEventListener('paste', function() {
      clearTimeout(autoLookupDebounce);
      setTimeout(() => {
        const val = tokenInput.value.trim().toUpperCase();
        if (val.length >= 6) {
          checkAndOpenModal(true);
        }
      }, 100);
    });

    async function checkAndOpenModal(isAuto = false) {
      const token = tokenInput.value.trim().toUpperCase();
      if (!token) {
        if (!isAuto) {
          alert('Please enter an Order Token.');
          tokenInput.focus();
        }
        return;
      }

      checkBtn.disabled = true;
      checkBtn.textContent = 'Checking…';

      let order = null;

      // Tier 1: Check localStorage all orders dictionary
      try {
        const allOrders = JSON.parse(localStorage.getItem('mama_all_orders') || '{}');
        if (allOrders && allOrders[token]) {
          order = allOrders[token];
        }
      } catch (_) {}

      // Tier 2: Check localStorage active order
      if (!order) {
        try {
          const clientActive = JSON.parse(localStorage.getItem('mama_active_order') || 'null');
          if (clientActive && clientActive.orderId && clientActive.orderId.toUpperCase() === token) {
            order = clientActive;
          }
        } catch (_) {}
      }

      // Tier 3: Query local PHP server cache (orders_cache.json and temp cache)
      if (!order) {
        try {
          const localRes = await fetch(`vendor.php?action=lookup&token=${encodeURIComponent(token)}`);
          const localData = await localRes.json();
          if (localData && localData.success && localData.order) {
            order = localData.order;
          }
        } catch (_) {}
      }

      // Tier 4: Query Google Apps Script
      if (!order) {
        try {
          const gasRes = await fetch(`${API_URL}?action=getOrder&orderId=${encodeURIComponent(token)}`);
          const gasData = await gasRes.json();
          if (gasData && (gasData.success || gasData.found)) {
            order = gasData;
          }
        } catch (_) {}
      }

      checkBtn.disabled = false;
      checkBtn.innerHTML = '🔍 Check Order';

      if (!order) {
        if (!isAuto) {
          alert(`Order token "${token}" was not found. Please verify the 6-character token from customer's pass.`);
          tokenInput.focus();
        }
        return;
      }

      populateAndShowModal(order);
    }

    function populateAndShowModal(order) {
      activeModalOrder = order;
      modalMsg.style.display = 'none';
      modalConfirmBtn.disabled = false;
      modalConfirmBtn.innerHTML = '<span>✅ Confirm &amp; Save to Sheet</span>';

      modalTokenBadge.textContent = order.orderId || tokenInput.value.trim().toUpperCase();
      modalCustomerName.textContent = order.name || 'Customer';
      modalCustomerPhone.textContent = order.phone || 'Phone on file';
      modalOrderItems.textContent = order.itemsDisplay || order.items || 'Standard Momo Order';

      const price = parseFloat(order.finalPrice !== undefined ? order.finalPrice : order.totalAmount) || 0;
      modalFinalPrice.textContent = '₹' + price.toFixed(2);

      if (order.isDiscount) {
        modalLoyaltyPill.className = 'loyalty-pill discount-active';
        modalLoyaltyPill.textContent = '🎉 50% Off Applied!';
      } else {
        modalLoyaltyPill.className = 'loyalty-pill';
        modalLoyaltyPill.textContent = `⭐ Stamp #${order.projectedVisit || order.visitNumber || 1}`;
      }

      modal.style.display = 'flex';
      modalVendorPin.focus();
    }

    // Modal Close
    modalCloseBtn.addEventListener('click', closeModal);
    modalCancelBtn.addEventListener('click', closeModal);
    modal.addEventListener('click', function(e) {
      if (e.target === modal) closeModal();
    });

    function closeModal() {
      modal.style.display = 'none';
      tokenInput.focus();
    }

    // Modal Confirm Submission
    modalConfirmBtn.addEventListener('click', async function() {
      const pin = modalVendorPin.value.trim();
      if (!pin) {
        alert('Please enter your Vendor PIN (default: 1234).');
        modalVendorPin.focus();
        return;
      }

      modalConfirmBtn.disabled = true;
      modalConfirmBtn.innerHTML = '<span>⏳ Saving to Google Sheets…</span>';
      modalMsg.style.display = 'none';

      const token = activeModalOrder ? activeModalOrder.orderId : tokenInput.value.trim().toUpperCase();
      const phone = activeModalOrder ? activeModalOrder.phone : '';
      const name = activeModalOrder ? activeModalOrder.name : 'Customer';
      const items = activeModalOrder ? (activeModalOrder.itemsDisplay || activeModalOrder.items || 'Momo Selection') : 'Momo Selection';
      const totalAmount = activeModalOrder ? (parseFloat(activeModalOrder.finalPrice !== undefined ? activeModalOrder.finalPrice : activeModalOrder.totalAmount) || 0) : 0;
      const isDiscount = activeModalOrder ? !!activeModalOrder.isDiscount : false;

      // Construct complete payload so Google Sheets gets all columns cleanly
      const payload = {
        action: "confirmOrder",
        orderId: token,
        phone: phone,
        name: name,
        items: items,
        itemsDisplay: items,
        totalAmount: totalAmount,
        total_amount: totalAmount,
        finalPrice: totalAmount,
        collectedAmount: totalAmount,
        discountApplied: isDiscount,
        discountGiven: isDiscount,
        pin: pin
      };

      try {
        const response = await fetch(API_URL, {
          method: 'POST',
          body: JSON.stringify(payload)
        });

        const data = await response.json();

        if (data && data.success) {
          modalMsg.style.display = 'block';
          modalMsg.style.background = '#065f46';
          modalMsg.style.color = '#ecfdf5';
          modalMsg.style.border = '1px solid #059669';
          modalMsg.innerHTML = `<strong>🎉 Order ${token} Confirmed!</strong><br>Successfully written to Google Sheets.`;

          // Add to recent orders ledger
          addRecentOrder({
            orderId: token,
            name: name,
            phone: phone,
            amount: totalAmount,
            visit: data.visitNumber || data.visitCount || 1,
            time: new Date().toLocaleTimeString()
          });

          setTimeout(() => {
            closeModal();
            tokenInput.value = '';
          }, 1500);
        } else {
          modalConfirmBtn.disabled = false;
          modalConfirmBtn.innerHTML = '<span>✅ Confirm &amp; Save to Sheet</span>';
          modalMsg.style.display = 'block';
          modalMsg.style.background = '#7f1d1d';
          modalMsg.style.color = '#fef2f2';
          modalMsg.style.border = '1px solid #dc2626';
          modalMsg.textContent = (data && data.message) ? data.message : 'Invalid vendor PIN or verification failed.';
        }
      } catch (err) {
        modalConfirmBtn.disabled = false;
        modalConfirmBtn.innerHTML = '<span>✅ Confirm &amp; Save to Sheet</span>';
        modalMsg.style.display = 'block';
        modalMsg.style.background = '#7f1d1d';
        modalMsg.style.color = '#fef2f2';
        modalMsg.style.border = '1px solid #dc2626';
        modalMsg.textContent = 'Could not reach Google Apps Script. Please verify connection and retry.';
      }
    });

    // Recent orders tracker in localStorage
    function addRecentOrder(item) {
      let recents = [];
      try {
        recents = JSON.parse(localStorage.getItem('mama_recent_confirmed_list') || '[]');
      } catch (_) {}
      recents.unshift(item);
      if (recents.length > 5) recents.pop();
      try {
        localStorage.setItem('mama_recent_confirmed_list', JSON.stringify(recents));
      } catch (_) {}
      renderRecentOrders();
    }

    function renderRecentOrders() {
      let recents = [];
      try {
        recents = JSON.parse(localStorage.getItem('mama_recent_confirmed_list') || '[]');
      } catch (_) {}
      if (!recents.length) {
        recentList.innerHTML = '<div style="font-size: 0.85rem; color: #6b7280; text-align: center; padding: 12px;">No orders verified yet in this session.</div>';
        return;
      }
      recentList.innerHTML = recents.map(r => `
        <div class="recent-item">
          <div class="recent-left">
            <span class="recent-token">${r.orderId}</span>
            <span class="recent-name">${r.name} ${r.phone ? `(${r.phone})` : ''}</span>
          </div>
          <div class="recent-right">
            <div class="recent-price">₹${parseFloat(r.amount).toFixed(2)}</div>
            <div class="recent-stamp">Visit #${r.visit} &bull; ${r.time}</div>
          </div>
        </div>
      `).join('');
    }

    renderRecentOrders();
  </script>
</body>
</html>
