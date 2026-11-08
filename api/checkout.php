<?php
$scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
if (substr($scriptDir, -4) === '/api' || substr($scriptDir, -4) === '\\api') {
    $basePath = substr($scriptDir, 0, -4);
} else {
    $basePath = $scriptDir;
}
$basePath = rtrim(str_replace('\\', '/', $basePath), '/');
$menuUrl = ($basePath ?: '') . '/menu';
$confirmUrl = ($basePath ?: '') . '/confirm';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Checkout - Mama Momo</title>
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
      font-size: 1.1rem;
      border-top: 1px solid #e0e0e0;
      padding-top: 10px;
      margin-top: 10px;
      color: #d9381e;
    }
    .label {
      font-size: 0.85rem;
      font-weight: 700;
      display: block;
      margin-top: 16px;
      color: #444;
    }
    input[type="text"], input[type="tel"] {
      width: 100%;
      padding: 13px 14px;
      border: 1px solid #ccc;
      border-radius: 10px;
      margin-top: 6px;
      font-size: 1rem;
      outline: none;
      transition: border-color 0.2s;
    }
    input[type="text"]:focus, input[type="tel"]:focus {
      border-color: #d9381e;
    }
    .note {
      font-size: 0.8rem;
      color: #777;
      margin-top: 6px;
      line-height: 1.3;
    }
    .submit-btn {
      width: 100%;
      background: #d9381e;
      color: #fff;
      border: 0;
      padding: 15px;
      border-radius: 10px;
      font-size: 1.05rem;
      font-weight: bold;
      margin-top: 24px;
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

    <div class="order-summary" id="cartSummary">
      <!-- Itemized cart summary injected via JS -->
    </div>

    <form action="<?= htmlspecialchars($confirmUrl, ENT_QUOTES, 'UTF-8') ?>" method="POST" id="checkoutForm">
      <input type="hidden" name="cart_items" id="cartItemsInput">
      <input type="hidden" name="cart_json" id="cartJsonInput">
      <input type="hidden" name="total_amount" id="totalAmountInput">

      <label class="label" for="name">Customer Name</label>
      <input type="text" id="name" name="name" maxlength="80" placeholder="e.g. Rahul Sharma" required autocomplete="name">

      <label class="label" for="phone">10-Digit Mobile Number</label>
      <input type="tel" id="phone" name="phone" inputmode="numeric" maxlength="10" pattern="[0-9]{10}" placeholder="e.g. 9876543210" required autocomplete="tel">
      <div class="note">Your mobile number is used to track your loyalty stamps (5th Visit = Flat 50% Off).</div>

      <button type="submit" class="submit-btn" id="submitBtn">Generate Order Token</button>
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
    totalRow.innerHTML = `<span>Subtotal</span><span>₹${subtotal}</span>`;
    summary.appendChild(totalRow);

    document.getElementById('checkoutForm').addEventListener('submit', function (e) {
      if (items.length === 0) {
        e.preventDefault();
        alert('Your cart is empty.');
        window.location.href = menuUrl;
        return;
      }

      // Serialize cart details into hidden form fields
      document.getElementById('cartItemsInput').value = itemStrings.join(', ');
      document.getElementById('cartJsonInput').value = JSON.stringify(items);
      document.getElementById('totalAmountInput').value = subtotal;

      // Clear localStorage to prevent duplicate submissions
      localStorage.removeItem('mama_cart');

      const btn = document.getElementById('submitBtn');
      btn.disabled = true;
      btn.textContent = 'Generating Token…';
    });
  </script>
</body>
</html>
