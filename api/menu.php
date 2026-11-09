<?php
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}
if (isset($_GET['clear']) && $_GET['clear'] === '1') {
    unset($_SESSION['active_order']);
}

require_once __DIR__ . '/config.php';

$scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
if (substr($scriptDir, -4) === '/api' || substr($scriptDir, -4) === '\\api') {
    $basePath = substr($scriptDir, 0, -4);
} else {
    $basePath = $scriptDir;
}
$basePath = rtrim(str_replace('\\', '/', $basePath), '/');
$checkoutUrl = ($basePath ?: '') . '/checkout';
$menuUrl = ($basePath ?: '') . '/menu';
$homeUrl = ($basePath ?: '') . '/';

// Customer login enforcement: customer must be logged in via phone before ordering
$customer = $_SESSION['customer'] ?? null;
if (!$customer && !isset($_GET['format']) && !isset($_GET['action'])) {
    header('Location: ' . ($homeUrl ?: '/'), true, 303);
    exit;
}

$isRefresh = (isset($_GET['refresh']) && $_GET['refresh'] === '1');
$previewDrafts = (isset($_GET['preview_drafts']) && $_GET['preview_drafts'] === '1') || (isset($_GET['preview']) && $_GET['preview'] === '1') || (isset($_GET['admin']) && $_GET['admin'] === '1');
$syncMeta = [];
$rawMenuItems = getDynamicMenu($isRefresh, $syncMeta);

// Filter items: items where viewOnMenu is false or status is 'draft' are strictly hidden from customers
$menuItems = [];
$totalDraftCount = 0;
foreach ($rawMenuItems as $id => $item) {
    $isViewOnMenu = !isset($item['viewOnMenu']) || (bool)$item['viewOnMenu'];
    $isDraft = (isset($item['status']) && strtolower($item['status']) === 'draft') || !$isViewOnMenu;

    if ($isDraft) {
        $totalDraftCount++;
        if ($previewDrafts) {
            $item['isDraft'] = true;
            $menuItems[$id] = $item;
        }
    } else {
        $item['isDraft'] = false;
        $menuItems[$id] = $item;
    }
}

// Direct JSON endpoint support
if ((isset($_GET['format']) && $_GET['format'] === 'json') || (isset($_GET['action']) && $_GET['action'] === 'getMenu')) {
    header('Content-Type: application/json; charset=utf-8');
    $includeAll = (isset($_GET['all']) && $_GET['all'] === '1') || (isset($_GET['include_drafts']) && $_GET['include_drafts'] === '1');
    $outputItems = $includeAll ? $rawMenuItems : $menuItems;
    echo json_encode([
        'success' => true,
        'count' => count($outputItems),
        'publishedCount' => count($rawMenuItems) - $totalDraftCount,
        'draftCount' => $totalDraftCount,
        'previewMode' => $previewDrafts,
        'menu' => array_values($outputItems),
        'meta' => $syncMeta
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// Extract unique categories for filter pills from visible items
$categories = [];
foreach ($menuItems as $item) {
    $cat = trim($item['category'] ?? '');
    if ($cat !== '' && !in_array($cat, $categories, true)) {
        $categories[] = $cat;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Menu - Mama Momo</title>
  <!-- Favicon & Touch Icons -->
  <link rel="icon" type="image/svg+xml" href="<?= ($basePath ?: '') ?>/favicon.svg">
  <link rel="alternate icon" type="image/png" sizes="32x32" href="<?= ($basePath ?: '') ?>/favicon-32x32.png">
  <link rel="alternate icon" type="image/png" sizes="16x16" href="<?= ($basePath ?: '') ?>/favicon-16x16.png">
  <link rel="apple-touch-icon" sizes="180x180" href="<?= ($basePath ?: '') ?>/apple-touch-icon.png">
  <link rel="shortcut icon" href="<?= ($basePath ?: '') ?>/favicon.ico">
  <meta name="theme-color" content="#ef4444">
  <style>
    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    }
    body {
      background: #fdfcfb;
      padding: 16px 16px 110px;
      color: #222;
    }
    .header {
      max-width: 520px;
      margin: 0 auto 12px;
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 12px;
    }
    .header-left h2 {
      font-size: 1.45rem;
      color: #d9381e;
      display: flex;
      align-items: center;
      gap: 6px;
    }
    .sync-status-row {
      display: flex;
      align-items: center;
      gap: 8px;
      margin-top: 4px;
      font-size: 0.78rem;
      color: #666;
    }
    .sync-indicator {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      background: #f0fdf4;
      color: #166534;
      padding: 2px 8px;
      border-radius: 12px;
      border: 1px solid #bbf7d0;
      font-weight: 500;
    }
    .sync-dot {
      width: 7px;
      height: 7px;
      border-radius: 50%;
      background: #22c55e;
      display: inline-block;
    }
    .sync-refresh-btn {
      color: #d9381e;
      text-decoration: none;
      font-weight: 600;
      border-bottom: 1px dotted #d9381e;
      transition: opacity 0.2s;
    }
    .sync-refresh-btn:hover {
      opacity: 0.75;
    }
    .step-badge {
      font-size: 0.85rem;
      color: #777;
      background: #eee;
      padding: 5px 12px;
      border-radius: 20px;
      white-space: nowrap;
    }
    .flash-notice {
      max-width: 520px;
      margin: 0 auto 14px;
      background: #eff6ff;
      border: 1px solid #bfdbfe;
      color: #1e40af;
      padding: 10px 14px;
      border-radius: 10px;
      font-size: 0.82rem;
      display: flex;
      align-items: center;
      justify-content: space-between;
    }
    .category-bar {
      max-width: 520px;
      margin: 0 auto 16px;
      display: flex;
      gap: 8px;
      overflow-x: auto;
      padding-bottom: 4px;
      scrollbar-width: none;
    }
    .category-bar::-webkit-scrollbar {
      display: none;
    }
    .cat-pill {
      background: #ffffff;
      border: 1px solid #e5e7eb;
      color: #4b5563;
      padding: 6px 14px;
      border-radius: 20px;
      font-size: 0.85rem;
      font-weight: 600;
      cursor: pointer;
      white-space: nowrap;
      transition: all 0.15s ease;
    }
    .cat-pill.active {
      background: #d9381e;
      border-color: #d9381e;
      color: #ffffff;
      box-shadow: 0 2px 8px rgba(217, 56, 30, 0.25);
    }
    .container {
      max-width: 520px;
      margin: 0 auto;
      display: flex;
      flex-direction: column;
      gap: 12px;
    }
    .card {
      background: #fff;
      padding: 16px;
      border-radius: 14px;
      box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
      border: 1px solid #f0f0f0;
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 12px;
      transition: transform 0.1s, border-color 0.15s;
    }
    .card.sold-out {
      opacity: 0.65;
      background: #fafafa;
      border-style: dashed;
    }
    .item-info {
      flex: 1;
    }
    .item-name-row {
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
      margin-bottom: 4px;
    }
    .item-name {
      font-weight: 700;
      font-size: 1.02rem;
      color: #222;
    }
    .item-cat-tag {
      font-size: 0.7rem;
      background: #fff1ee;
      color: #d9381e;
      padding: 2px 7px;
      border-radius: 6px;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }
    .item-desc {
      font-size: 0.82rem;
      color: #666;
      margin-bottom: 6px;
      line-height: 1.35;
    }
    .price-row {
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .item-price {
      font-weight: 800;
      color: #d9381e;
      font-size: 1.05rem;
    }
    .card.is-draft {
      background: #fffbeb;
      border: 1px dashed #f59e0b;
      opacity: 0.85;
    }
    .draft-badge {
      font-size: 0.72rem;
      background: #fef3c7;
      color: #92400e;
      padding: 2px 8px;
      border-radius: 6px;
      font-weight: 700;
      text-transform: uppercase;
      border: 1px solid #fde68a;
    }
    .preview-banner {
      max-width: 520px;
      margin: 0 auto 12px;
      background: #fef3c7;
      border: 1px solid #f59e0b;
      color: #92400e;
      padding: 10px 14px;
      border-radius: 10px;
      font-size: 0.86rem;
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 10px;
    }
    .preview-banner a {
      color: #b45309;
      font-weight: 700;
      text-decoration: underline;
    }
    .sold-badge {
      font-size: 0.72rem;
      background: #fee2e2;
      color: #991b1b;
      padding: 2px 8px;
      border-radius: 6px;
      font-weight: 700;
      text-transform: uppercase;
    }
    .qty-controls {
      display: flex;
      align-items: center;
      gap: 8px;
      flex-shrink: 0;
    }
    .qty-btn {
      width: 36px;
      height: 36px;
      border: 1px solid #ddd;
      background: #f8f8f8;
      border-radius: 8px;
      font-weight: bold;
      cursor: pointer;
      font-size: 1.2rem;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: background 0.1s;
    }
    .qty-btn:active {
      background: #e5e5e5;
    }
    .qty-btn:disabled {
      opacity: 0.4;
      cursor: not-allowed;
    }
    .qty-display {
      min-width: 24px;
      text-align: center;
      font-weight: 700;
      font-size: 1rem;
    }
    .out-of-stock-label {
      font-size: 0.82rem;
      font-weight: 700;
      color: #991b1b;
      background: #fef2f2;
      padding: 6px 10px;
      border-radius: 8px;
      border: 1px solid #fecaca;
    }
    .cart-bar {
      position: fixed;
      bottom: 0;
      left: 0;
      right: 0;
      background: #ffffff;
      padding: 14px 20px;
      border-top: 1px solid #eee;
      box-shadow: 0 -4px 20px rgba(0, 0, 0, 0.08);
      z-index: 100;
    }
    .cart-bar-inner {
      max-width: 520px;
      margin: 0 auto;
      width: 100%;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    .total-label {
      font-size: 0.75rem;
      color: #666;
    }
    .total-price {
      font-size: 1.35rem;
      font-weight: 800;
      color: #d9381e;
    }
    .checkout-btn {
      background: #d9381e;
      color: #fff;
      border: 0;
      padding: 13px 24px;
      border-radius: 10px;
      font-size: 1rem;
      font-weight: bold;
      cursor: pointer;
      box-shadow: 0 4px 12px rgba(217, 56, 30, 0.3);
      transition: transform 0.1s;
    }
    .checkout-btn:active {
      transform: scale(0.97);
    }
  </style>
</head>
<body>
  <?php if ($customer): ?>
    <div style="max-width: 520px; margin: 0 auto 12px; display: flex; justify-content: space-between; align-items: center; background: #ffffff; padding: 10px 14px; border-radius: 12px; border: 1px solid #fee2e2; box-shadow: 0 2px 6px rgba(217,56,30,0.06);">
      <div style="font-size: 0.88rem; color: #374151;">
        👋 Ordering as <strong><?= htmlspecialchars($customer['name'] ?? 'Customer', ENT_QUOTES, 'UTF-8') ?></strong>
        <span style="font-size: 0.8rem; color: #6b7280;">(<?= htmlspecialchars($customer['phone'] ?? '', ENT_QUOTES, 'UTF-8') ?>)</span>
      </div>
      <a href="<?= htmlspecialchars($homeUrl ?: '/', ENT_QUOTES, 'UTF-8') ?>" style="font-size: 0.8rem; font-weight: 700; color: #d9381e; text-decoration: none; padding: 5px 10px; background: #fff5f2; border-radius: 8px; border: 1px solid #fecaca;">👤 Profile</a>
    </div>
  <?php endif; ?>

  <div class="header">
    <div class="header-left">
      <h2>🥟 Mama Momo Menu</h2>
      <div class="sync-status-row">
        <!-- <span class="sync-indicator">
          <span class="sync-dot"></span>
          Google Sheet Live
        </span>
        <span>•</span> -->
        <a href="<?= htmlspecialchars($menuUrl, ENT_QUOTES, 'UTF-8') ?>?refresh=1" class="sync-refresh-btn" title="Sync live menu updates from Google Sheets">🔄 Refresh</a>
      </div>
    </div>
    <span class="step-badge">Step 1 of 3</span>
  </div>

  <?php if ($previewDrafts): ?>
    <div class="preview-banner">
      <span>👁️ <strong>Stall Admin Preview:</strong> Showing <?= $totalDraftCount ?> draft item(s) normally hidden from customers.</span>
      <a href="<?= htmlspecialchars($menuUrl, ENT_QUOTES, 'UTF-8') ?>">Exit Preview</a>
    </div>
  <?php endif; ?>

  <?php if ($isRefresh): ?>
    <div class="flash-notice" id="flashNotice">
      <span>✓ Menu synced! (<?= count($menuItems) ?> visible items loaded<?= $totalDraftCount > 0 ? ", $totalDraftCount draft(s) hidden" : '' ?>)</span>
      <span style="cursor:pointer;" onclick="this.parentElement.style.display='none'">✕</span>
    </div>
  <?php endif; ?>

  <?php if (!empty($categories) && count($categories) > 1): ?>
    <div class="category-bar">
      <button type="button" class="cat-pill active" onclick="filterCategory('all', this)">All (<?= count($menuItems) ?>)</button>
      <?php foreach ($categories as $cat): ?>
        <?php
          $catCount = 0;
          foreach ($menuItems as $it) {
              if (($it['category'] ?? '') === $cat) $catCount++;
          }
        ?>
        <button type="button" class="cat-pill" onclick="filterCategory('<?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>', this)"><?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?> (<?= $catCount ?>)</button>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="container" id="menuContainer">
    <?php if (empty($menuItems)): ?>
      <div style="text-align: center; padding: 48px 20px; background: #fff; border-radius: 14px; border: 1px dashed #ddd; margin: 20px 0;">
        <div style="font-size: 2.8rem; margin-bottom: 12px;">🥟</div>
        <h3 style="color: #374151; margin-bottom: 8px;">No Items Currently Visible</h3>
        <p style="color: #6b7280; font-size: 0.9rem; max-width: 320px; margin: 0 auto;">Items in Google Sheet are either marked as drafts or being updated. Check back shortly!</p>
      </div>
    <?php endif; ?>

    <?php foreach ($menuItems as $item): ?>
      <?php
        $isAvailable = !isset($item['available']) || (bool)$item['available'];
        $isDraft = !empty($item['isDraft']);
        $itemId = (string)$item['id'];
        $itemCategory = (string)($item['category'] ?? 'General');
      ?>
      <div class="card <?= !$isAvailable ? 'sold-out' : '' ?> <?= $isDraft ? 'is-draft' : '' ?>" data-id="<?= htmlspecialchars($itemId, ENT_QUOTES, 'UTF-8') ?>" data-category="<?= htmlspecialchars($itemCategory, ENT_QUOTES, 'UTF-8') ?>">
        <div class="item-info">
          <div class="item-name-row">
            <span class="item-name"><?= htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8') ?></span>
            <?php if (!empty($item['category'])): ?>
              <span class="item-cat-tag"><?= htmlspecialchars($item['category'], ENT_QUOTES, 'UTF-8') ?></span>
            <?php endif; ?>
            <?php if ($isDraft): ?>
              <span class="draft-badge">📝 DRAFT</span>
            <?php endif; ?>
          </div>
          <?php if (!empty($item['desc'])): ?>
            <div class="item-desc"><?= htmlspecialchars($item['desc'], ENT_QUOTES, 'UTF-8') ?></div>
          <?php endif; ?>
          <div class="price-row">
            <span class="item-price">₹<?= number_format((float)$item['price'], 0) ?></span>
            <?php if ($isDraft): ?>
              <span class="draft-badge" style="font-size:0.68rem;">Hidden from Customers</span>
            <?php elseif (!$isAvailable): ?>
              <span class="sold-badge">Sold Out</span>
            <?php endif; ?>
          </div>
        </div>

        <div class="qty-controls">
          <?php if ($isDraft): ?>
            <div class="out-of-stock-label" style="background:#fffbeb; color:#b45309; border-color:#fcd34d;">Draft Item</div>
          <?php elseif ($isAvailable): ?>
            <button type="button" class="qty-btn" aria-label="Decrease" onclick="modifyQty('<?= htmlspecialchars($itemId, ENT_QUOTES, 'UTF-8') ?>', -1)">−</button>
            <span class="qty-display" id="qty-<?= htmlspecialchars($itemId, ENT_QUOTES, 'UTF-8') ?>">0</span>
            <button type="button" class="qty-btn" aria-label="Increase" onclick="modifyQty('<?= htmlspecialchars($itemId, ENT_QUOTES, 'UTF-8') ?>', 1)">+</button>
          <?php else: ?>
            <div class="out-of-stock-label">Out of Stock</div>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="cart-bar">
    <div class="cart-bar-inner">
      <div>
        <div class="total-label">Subtotal:</div>
        <div class="total-price" id="totalAmount">₹0</div>
      </div>
      <button class="checkout-btn" type="button" onclick="goToCheckout()">Proceed to Checkout</button>
    </div>
  </div>

  <script>
    const menu = <?= json_encode(array_values($menuItems), JSON_UNESCAPED_UNICODE) ?>;
    const menuById = Object.fromEntries(menu.map(item => [String(item.id), item]));
    const checkoutUrl = <?= json_encode($checkoutUrl) ?>;
    let cart = {};

    try {
      const saved = JSON.parse(localStorage.getItem('mama_cart') || '{}');
      if (saved && typeof saved === 'object' && !Array.isArray(saved)) {
        cart = saved;
      }
    } catch (_) {
      cart = {};
    }

    function cleanCart() {
      const cleaned = {};
      for (const [id, value] of Object.entries(cart)) {
        const item = menuById[String(id)];
        const qty = Number(value && value.qty);
        // Only retain items that exist and are available (not marked out of stock in Google Sheet)
        if (item && item.available !== false && Number.isInteger(qty) && qty > 0 && qty <= 50) {
          cleaned[String(id)] = {
            id: String(item.id),
            name: item.name,
            price: Number(item.price),
            qty: qty
          };
        }
      }
      cart = cleaned;
    }

    function syncView() {
      cleanCart();
      let total = 0;
      let totalItems = 0;
      for (const item of menu) {
        const key = String(item.id);
        const qty = cart[key] ? cart[key].qty : 0;
        const display = document.getElementById('qty-' + key);
        if (display) display.textContent = qty;
        if (item.available !== false) {
          total += qty * item.price;
          totalItems += qty;
        }
      }
      document.getElementById('totalAmount').textContent = '₹' + total;
      localStorage.setItem('mama_cart', JSON.stringify(cart));
    }

    function modifyQty(id, delta) {
      const key = String(id);
      const item = menuById[key];
      if (!item || item.available === false) {
        alert('This item is currently out of stock.');
        return;
      }

      const current = (cart[key] && cart[key].qty) ? cart[key].qty : 0;
      const next = current + delta;
      if (next <= 0) {
        delete cart[key];
      } else if (next <= 50) {
        cart[key] = {
          id: key,
          name: item.name,
          price: Number(item.price),
          qty: next
        };
      }
      syncView();
    }

    function filterCategory(cat, btn) {
      document.querySelectorAll('.cat-pill').forEach(p => p.classList.remove('active'));
      if (btn) btn.classList.add('active');

      document.querySelectorAll('.card').forEach(card => {
        if (cat === 'all' || card.dataset.category === cat) {
          card.style.display = 'flex';
        } else {
          card.style.display = 'none';
        }
      });
    }

    function goToCheckout() {
      cleanCart();
      const count = Object.values(cart).reduce((sum, line) => sum + line.qty, 0);
      if (count === 0) {
        alert('Please add at least 1 plate of momos to proceed!');
        return;
      }
      window.location.href = checkoutUrl;
    }

    syncView();
  </script>
</body>
</html>
