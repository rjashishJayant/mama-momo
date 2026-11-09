<?php
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

require_once __DIR__ . '/config.php';

$scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
if (substr($scriptDir, -4) === '/api' || substr($scriptDir, -4) === '\\api') {
    $basePath = substr($scriptDir, 0, -4);
} else {
    $basePath = $scriptDir;
}
$basePath = rtrim(str_replace('\\', '/', $basePath), '/');
$menuUrl = ($basePath ?: '') . '/menu';
$confirmUrl = ($basePath ?: '') . '/confirm';
$homeUrl = ($basePath ?: '') . '/';

$customer = getAuthenticatedCustomer();
if (!$customer) {
    // If not logged in, redirect to login page
    header('Location: ' . ($homeUrl ?: '/') . '?login_required=1', true, 303);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Review &amp; Checkout - Mama Momo</title>
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
      padding: 16px;
      color: #222;
    }
    .box {
      max-width: 480px;
      margin: 0 auto;
      background: #ffffff;
      padding: 24px;
      border-radius: 16px;
      box-shadow: 0 4px 18px rgba(0, 0, 0, 0.06);
      border: 1px solid #f0f0f0;
    }
    .back-btn {
      display: inline-block;
      margin-bottom: 16px;
      color: #d9381e;
      text-decoration: none;
      font-size: 0.9rem;
      font-weight: 600;
    }
    h2 {
      color: #d9381e;
      font-size: 1.4rem;
      margin-bottom: 16px;
    }
    .order-summary {
      background: #fafafa;
      border-radius: 12px;
      padding: 16px;
      margin-bottom: 20px;
      border: 1px dashed #ddd;
    }
    .cart-row, .total-row {
      display: flex;
      justify-content: space-between;
      gap: 12px;
      font-size: 0.95rem;
      margin-bottom: 8px;
    }
    .total-row {
      font-weight: 800;
      font-size: 1.15rem;
      border-top: 1px solid #e0e0e0;
      padding-top: 10px;
      margin-top: 10px;
      color: #d9381e;
    }
    
    /* Verified Customer Card */
    .customer-card {
      background: #f0fdf4;
      border: 1.5px solid #bbf7d0;
      border-radius: 12px;
      padding: 14px 16px;
      margin-bottom: 20px;
    }
    .cust-badge {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      background: #dcfce7;
      color: #166534;
      padding: 3px 8px;
      border-radius: 10px;
      font-size: 0.75rem;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      margin-bottom: 8px;
    }
    .cust-row {
      display: flex;
      justify-content: space-between;
      font-size: 0.9rem;
      margin-bottom: 5px;
    }
    .cust-row:last-child {
      margin-bottom: 0;
    }
    .cust-label {
      color: #4b5563;
      font-weight: 600;
    }
    .cust-val {
      font-weight: 700;
      color: #111827;
      text-align: right;
    }
    .note {
      font-size: 0.8rem;
      color: #6b7280;
      margin-top: 8px;
      line-height: 1.35;
      text-align: center;
    }
    .submit-btn {
      width: 100%;
      background: #d9381e;
      color: #fff;
      border: 0;
      padding: 16px;
      border-radius: 12px;
      font-size: 1.1rem;
      font-weight: bold;
      margin-top: 20px;
      cursor: pointer;
      box-shadow: 0 4px 14px rgba(217, 56, 30, 0.35);
      transition: transform 0.1s;
    }
    .submit-btn:active {
      transform: scale(0.98);
    }
    .submit-btn:disabled {
      opacity: 0.6;
      cursor: wait;
    }

    /* Momo Animated Loader Overlay */
    .momo-loader-overlay {
      position: fixed;
      inset: 0;
      background: rgba(15, 23, 42, 0.72);
      backdrop-filter: blur(8px);
      -webkit-backdrop-filter: blur(8px);
      z-index: 999999;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 20px;
      transition: opacity 0.25s ease, visibility 0.25s ease;
    }
    .momo-loader-card {
      background: #5B0E0A;
      border: 2px solid #ff7a59;
      border-radius: 24px;
      padding: 24px 28px;
      max-width: 320px;
      width: 100%;
      text-align: center;
      box-shadow: 0 20px 50px rgba(0, 0, 0, 0.5), 0 0 30px rgba(255, 122, 89, 0.2);
      animation: popIn 0.3s cubic-bezier(0.16, 1, 0.3, 1);
    }
    @keyframes popIn {
      0% { transform: scale(0.85); opacity: 0; }
      100% { transform: scale(1); opacity: 1; }
    }
    .momo-loader-mascot-wrap {
      position: relative;
      display: inline-block;
      margin-bottom: 12px;
    }
    .momo-loader-mascot {
      width: 130px;
      height: auto;
      border-radius: 16px;
      box-shadow: 0 6px 18px rgba(0,0,0,0.3);
      animation: momoBounce 1.6s infinite ease-in-out;
      display: block;
    }
    @keyframes momoBounce {
      0%, 100% { transform: translateY(0) scale(1); }
      50% { transform: translateY(-7px) scale(1.04); }
    }
    .momo-loader-ring {
      position: absolute;
      inset: -6px;
      border: 3px solid transparent;
      border-top-color: #fbbf24;
      border-right-color: #fbbf24;
      border-radius: 20px;
      animation: spinRing 1.2s linear infinite;
    }
    @keyframes spinRing {
      0% { transform: rotate(0deg); }
      100% { transform: rotate(360deg); }
    }
    .momo-loader-title {
      font-size: 1.15rem;
      font-weight: 900;
      color: #ffffff;
      margin-bottom: 4px;
      letter-spacing: 0.5px;
    }
    .momo-loader-text {
      font-size: 0.85rem;
      color: #ffd8d3;
      margin-bottom: 12px;
      font-weight: 600;
      min-height: 20px;
    }
    .momo-loader-dots {
      display: flex;
      justify-content: center;
      gap: 6px;
    }
    .momo-loader-dots span {
      width: 7px;
      height: 7px;
      background: #ff7a59;
      border-radius: 50%;
      animation: dotPulse 1.2s infinite ease-in-out;
    }
    .momo-loader-dots span:nth-child(2) { animation-delay: 0.2s; }
    .momo-loader-dots span:nth-child(3) { animation-delay: 0.4s; }
    @keyframes dotPulse {
      0%, 100% { opacity: 0.3; transform: scale(0.8); }
      50% { opacity: 1; transform: scale(1.3); }
    }
  </style>
</head>
<body>
  <!-- Momo Animated Loader Overlay -->
  <div id="momoLoaderOverlay" class="momo-loader-overlay" style="display: none;">
    <div class="momo-loader-card">
      <div class="momo-loader-mascot-wrap">
        <img src="<?= ($basePath ?: '') ?>/assets/loader-momo.png" alt="Loading Mama Momo" class="momo-loader-mascot" />
        <div class="momo-loader-ring"></div>
      </div>
      <div class="momo-loader-title">Mama Momo</div>
      <div class="momo-loader-text" id="momoLoaderText">Generating your order token…</div>
      <div class="momo-loader-dots">
        <span></span><span></span><span></span>
      </div>
    </div>
  </div>

  <div class="box">
    <a href="<?= htmlspecialchars($menuUrl, ENT_QUOTES, 'UTF-8') ?>" class="back-btn">← Back to Menu</a>
    <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 14px;">
      <img src="<?= ($basePath ?: '') ?>/assets/mama-momo-logo.jpg" alt="Mama Momo Logo" style="width: 44px; height: 44px; border-radius: 50%; object-fit: cover; border: 2.5px solid #ef4444; box-shadow: 0 2px 8px rgba(239, 68, 68, 0.25);" />
      <h2 style="margin: 0; font-size: 1.35rem;">Review Order &amp; Details</h2>
    </div>

    <!-- Verified Customer Identity (Pre-filled, not asked again) -->
    <div class="customer-card">
      <div class="cust-badge">✓ Verified Customer Account</div>
      <div class="cust-row">
        <span class="cust-label">👤 Name:</span>
        <span class="cust-val"><?= htmlspecialchars($customer['name'] ?? 'Customer', ENT_QUOTES, 'UTF-8') ?></span>
      </div>
      <div class="cust-row">
        <span class="cust-label">📱 Mobile:</span>
        <span class="cust-val"><?= htmlspecialchars($customer['phone'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
      </div>
      <?php if (!empty($customer['address'])): ?>
        <div class="cust-row">
          <span class="cust-label">📍 Delivery Address:</span>
          <span class="cust-val"><?= htmlspecialchars($customer['address'], ENT_QUOTES, 'UTF-8') ?></span>
        </div>
      <?php endif; ?>
    </div>

    <!-- Itemized Order Summary -->
    <div class="order-summary" id="cartSummary">
      <!-- Injected via JS from local cart -->
    </div>

    <form action="<?= htmlspecialchars($confirmUrl, ENT_QUOTES, 'UTF-8') ?>" method="POST" id="checkoutForm">
      <!-- Pre-authenticated customer details submitted without re-asking -->
      <input type="hidden" name="name" value="<?= htmlspecialchars($customer['name'] ?? 'Customer', ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="phone" value="<?= htmlspecialchars($customer['phone'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="address" value="<?= htmlspecialchars($customer['address'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="cart_items" id="cartItemsInput">
      <input type="hidden" name="cart_json" id="cartJsonInput">
      <input type="hidden" name="total_amount" id="totalAmountInput">

      <button type="submit" class="submit-btn" id="submitBtn">Generate Order Token</button>
      <div class="note">A 6-character order token will be generated for stall verification &amp; loyalty stamping.</div>
    </form>
  </div>

  <script>
    const menuUrl = <?= json_encode($menuUrl) ?>;
    let cart = {};
    try {
      cart = JSON.parse(localStorage.getItem('mama_cart') || '{}');
    } catch (_) {
      cart = {};
    }

    const summary = document.getElementById('cartSummary');
    const items = Object.values(cart).filter(i => i && i.qty > 0);

    if (items.length === 0) {
      alert('Your cart is empty. Please select items first.');
      window.location.replace(menuUrl);
    }

    let subtotal = 0;
    const itemStrings = [];

    items.forEach(item => {
      const lineTotal = item.qty * item.price;
      subtotal += lineTotal;
      itemStrings.push(`${item.qty} × ${item.name}`);

      const row = document.createElement('div');
      row.className = 'cart-row';
      row.innerHTML = `<span>${item.name} × <strong>${item.qty}</strong></span><strong>₹${lineTotal}</strong>`;
      summary.appendChild(row);
    });

    const totalRow = document.createElement('div');
    totalRow.className = 'total-row';
    totalRow.innerHTML = `<span>Total Payable</span><span>₹${subtotal}</span>`;
    summary.appendChild(totalRow);

    function showLoader(msg) {
      const overlay = document.getElementById('momoLoaderOverlay');
      const text = document.getElementById('momoLoaderText');
      if (text && msg) text.textContent = msg;
      if (overlay) overlay.style.display = 'flex';
    }

    document.getElementById('checkoutForm').addEventListener('submit', function (e) {
      if (items.length === 0) {
        e.preventDefault();
        alert('Your cart is empty.');
        window.location.href = menuUrl;
        return;
      }

      document.getElementById('cartItemsInput').value = itemStrings.join(', ');
      document.getElementById('cartJsonInput').value = JSON.stringify(items);
      document.getElementById('totalAmountInput').value = subtotal;

      localStorage.removeItem('mama_cart');

      const btn = document.getElementById('submitBtn');
      btn.disabled = true;
      btn.textContent = 'Generating Token…';
      showLoader('Generating your unique order pass & token…');
    });
  </script>
</body>
</html>
