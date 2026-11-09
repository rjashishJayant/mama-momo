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

$customer = $_SESSION['customer'] ?? null;
if (!$customer) {
    // If not logged in, redirect to login page
    header('Location: ' . ($homeUrl ?: '/'), true, 303);
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
  </style>
</head>
<body>
  <div class="box">
    <a href="<?= htmlspecialchars($menuUrl, ENT_QUOTES, 'UTF-8') ?>" class="back-btn">← Back to Menu</a>
    <h2>Review Order &amp; Details</h2>

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
    });
  </script>
</body>
</html>
