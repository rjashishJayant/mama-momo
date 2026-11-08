<?php
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

require_once __DIR__ . '/config.php';

// Dynamic base path determination for Vercel vs WAMP/Apache
$scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
if (substr($scriptDir, -4) === '/api' || substr($scriptDir, -4) === '\\api') {
    $basePath = substr($scriptDir, 0, -4);
} else {
    $basePath = $scriptDir;
}
$basePath = rtrim(str_replace('\\', '/', $basePath), '/');
$menuUrl = ($basePath ?: '') . '/menu';
$checkoutUrl = ($basePath ?: '') . '/checkout';
$confirmUrl = ($basePath ?: '') . '/confirm';

// Handle clear order request (e.g. when user clicks Place Another Order)
if (isset($_GET['clear']) && $_GET['clear'] === '1') {
    unset($_SESSION['active_order']);
    header('Location: ' . $menuUrl, true, 303);
    exit;
}

$errorMsg = null;
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Extract POST fields: name, phone, cart_items, total_amount
    $name = trim((string)($_POST['name'] ?? ''));
    $phone = preg_replace('/\D+/', '', (string)($_POST['phone'] ?? ''));
    $cartItems = trim((string)($_POST['cart_items'] ?? ''));
    $totalAmount = (float)($_POST['total_amount'] ?? 0);
    $cartJson = (string)($_POST['cart_json'] ?? '');

    $items = json_decode($cartJson, true);
    if (!is_array($items)) {
        $items = [];
    }

    if ($name === '' || strlen($name) > 80 || !preg_match('/^[0-9]{10}$/', $phone) || $totalAmount <= 0) {
        http_response_code(400);
        $errorMsg = 'Please enter a valid customer name, 10-digit mobile number, and select at least 1 item.';
    } else {
        $payload = [
            'action' => 'createOrder',
            'name' => $name,
            'phone' => $phone,
            'items' => is_array($items) && !empty($items) ? $items : $cartItems,
            'itemsDisplay' => $cartItems,
            'cart_items' => $cartItems,
            'cart_json' => $cartJson,
            'totalAmount' => $totalAmount,
            'total_amount' => $totalAmount
        ];

        $ch = curl_init($APPS_SCRIPT_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 25
        ]);

        $rawResponse = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($rawResponse === false) {
            http_response_code(502);
            $errorMsg = 'Could not contact the loyalty server: ' . $curlError;
        } else {
            $result = json_decode($rawResponse, true);
            if (!is_array($result) || empty($result['success'])) {
                http_response_code(400);
                $errorMsg = is_array($result) && !empty($result['message']) ? $result['message'] : 'Failed to generate order token.';
            } else {
                // Safeguard parsed values against empty or 0 returns
                $safeTotal = (!empty($result['totalAmount']) && (float)$result['totalAmount'] > 0) ? (float)$result['totalAmount'] : $totalAmount;
                $safeFinal = (!empty($result['finalPrice']) && (float)$result['finalPrice'] > 0) ? (float)$result['finalPrice'] : $safeTotal;
                $safeItems = (!empty($result['itemsDisplay']) && trim($result['itemsDisplay']) !== '')
                    ? (string)$result['itemsDisplay']
                    : ((!empty($result['items']) && is_string($result['items']) && trim($result['items']) !== '') ? (string)$result['items'] : $cartItems);

                // Save confirmed order data to session so it persists across refreshes
                $_SESSION['active_order'] = [
                    'orderId' => (string)$result['orderId'],
                    'name' => (string)($result['name'] ?? $name),
                    'phone' => (string)($result['phone'] ?? $phone),
                    'itemsDisplay' => $safeItems,
                    'totalAmount' => $safeTotal,
                    'finalPrice' => $safeFinal,
                    'projectedVisit' => (int)($result['projectedVisit'] ?? 1),
                    'currentVisits' => (int)($result['currentVisits'] ?? 0),
                    'remainingVisits' => (int)($result['remainingVisits'] ?? 0),
                    'isDiscount' => !empty($result['isDiscount']),
                    'isCooldown' => !empty($result['isCooldown']),
                    'timestamp' => time()
                ];

                // Cache order by token for instant vendor terminal lookup
                try {
                    $cachedData = $_SESSION['active_order'];
                    // 1. Project directory cache
                    $projCache = dirname(__DIR__) . '/orders_cache.json';
                    $cachedP = file_exists($projCache) ? (json_decode(@file_get_contents($projCache), true) ?: []) : [];
                    $cachedP[(string)$result['orderId']] = $cachedData;
                    @file_put_contents($projCache, json_encode($cachedP, JSON_UNESCAPED_UNICODE));

                    // 2. System temp directory cache
                    $tempCache = sys_get_temp_dir() . '/mama_momo_orders.json';
                    $cachedT = file_exists($tempCache) ? (json_decode(@file_get_contents($tempCache), true) ?: []) : [];
                    $cachedT[(string)$result['orderId']] = $cachedData;
                    @file_put_contents($tempCache, json_encode($cachedT, JSON_UNESCAPED_UNICODE));
                } catch (\Throwable $e) {}

                // Post-Redirect-Get (PRG) pattern so browser reload does not resubmit POST
                header('Location: ' . $confirmUrl, true, 303);
                exit;
            }
        }
    }
} else {
    // GET request (e.g. page refreshed) -> restore confirmed order from session
    if (!empty($_SESSION['active_order']) && (time() - ($_SESSION['active_order']['timestamp'] ?? 0) < 7200)) {
        $result = $_SESSION['active_order'];
        $cartItems = $result['itemsDisplay'];
        $totalAmount = $result['totalAmount'];
    } else {
        header('Location: ' . $menuUrl, true, 303);
        exit;
    }
}

if ($errorMsg !== null):
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Error - Mama Momo</title>
  <style>
    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #fff5f2; padding: 20px; }
    .box { max-width: 440px; margin: 8vh auto; background: #fff; padding: 28px; border-radius: 16px; box-shadow: 0 4px 15px rgba(0,0,0,0.06); text-align: center; }
    h2 { color: #d9381e; margin-bottom: 12px; }
    p { color: #555; line-height: 1.5; margin-bottom: 20px; }
    .btn { display: inline-block; background: #d9381e; color: #fff; text-decoration: none; padding: 12px 24px; border-radius: 8px; font-weight: bold; }
  </style>
</head>
<body>
  <div class="box">
    <h2>Notice</h2>
    <p><?= htmlspecialchars($errorMsg, ENT_QUOTES, 'UTF-8') ?></p>
    <a href="<?= htmlspecialchars($checkoutUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn">Back to Checkout</a>
  </div>
</body>
</html>
<?php
exit;
endif;

// Extract response fields
$orderId = htmlspecialchars((string)$result['orderId'], ENT_QUOTES, 'UTF-8');
$displayName = htmlspecialchars((string)$result['name'], ENT_QUOTES, 'UTF-8');
$displayPhone = htmlspecialchars((string)$result['phone'], ENT_QUOTES, 'UTF-8');
$displayItems = htmlspecialchars((string)($result['itemsDisplay'] ?? ($cartItems ?? '')), ENT_QUOTES, 'UTF-8');
$resTotal = (float)($result['totalAmount'] ?? ($totalAmount ?? 0));
$resFinal = (float)($result['finalPrice'] ?? $resTotal);
$isDiscount = !empty($result['isDiscount']);
$isCooldown = !empty($result['isCooldown']);
$projectedVisit = (int)($result['projectedVisit'] ?? 1);
$currentVisits = (int)($result['currentVisits'] ?? 0);
$remainingVisits = (int)($result['remainingVisits'] ?? ($isDiscount ? 0 : (5 - ($projectedVisit % 5))));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Order Pass - <?= $orderId ?></title>
  <style>
    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    }
    body {
      background: #f4f6f8;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 20px;
    }
    .pass-card {
      max-width: 420px;
      width: 100%;
      background: #ffffff;
      border-radius: 20px;
      padding: 28px 24px;
      text-align: center;
      box-shadow: 0 12px 35px rgba(0, 0, 0, 0.08);
      border: 2px dashed #cbd5e1;
      position: relative;
    }
    .ribbon-discount {
      background: #28a745;
      color: #ffffff;
      padding: 10px 16px;
      border-radius: 30px;
      font-weight: 800;
      font-size: 0.95rem;
      margin-bottom: 16px;
      display: inline-block;
      box-shadow: 0 4px 12px rgba(40, 167, 69, 0.35);
      animation: pulse 2s infinite ease-in-out;
    }
    @keyframes pulse {
      0%, 100% { transform: scale(1); }
      50% { transform: scale(1.03); }
    }
    .ribbon-normal {
      background: #e2e8f0;
      color: #334155;
      padding: 8px 16px;
      border-radius: 20px;
      font-weight: 700;
      font-size: 0.88rem;
      margin-bottom: 16px;
      display: inline-block;
    }
    .token-label {
      font-size: 0.8rem;
      text-transform: uppercase;
      color: #64748b;
      font-weight: 700;
      letter-spacing: 1.5px;
    }
    .order-token {
      font-size: 3.2rem;
      font-weight: 900;
      color: #d9381e;
      margin: 8px 0 16px;
      letter-spacing: 3px;
    }
    .details-box {
      background: #f8fafc;
      border-radius: 12px;
      padding: 16px;
      text-align: left;
      font-size: 0.95rem;
      margin-bottom: 16px;
      border: 1px solid #edf2f7;
    }
    .detail-row {
      display: flex;
      justify-content: space-between;
      margin-bottom: 8px;
    }
    .detail-row span:first-child {
      color: #64748b;
    }
    .detail-row span:last-child {
      font-weight: 600;
      color: #1e293b;
      text-align: right;
    }
    .price-box {
      background: #fff5f2;
      border: 1px solid #ffdcd6;
      padding: 14px;
      border-radius: 12px;
      margin-top: 12px;
      text-align: center;
    }
    .strike-price {
      text-decoration: line-through;
      color: #888;
      font-size: 0.95rem;
      margin-bottom: 2px;
    }
    .final-price {
      font-size: 1.8rem;
      font-weight: 800;
      color: #d9381e;
    }
    .staff-instruction {
      font-size: 0.95rem;
      font-weight: 700;
      color: #d9381e;
      background: #fff0ed;
      border: 1px solid #ffd5cc;
      padding: 12px;
      border-radius: 10px;
      margin-top: 18px;
      line-height: 1.4;
    }
    .cooldown-note {
      font-size: 0.8rem;
      color: #718096;
      margin-top: 10px;
    }
    .new-order-link {
      display: inline-block;
      margin-top: 16px;
      color: #64748b;
      font-size: 0.85rem;
      text-decoration: none;
    }
  </style>
</head>
<body>
  <div class="pass-card">
    <?php if ($isDiscount): ?>
      <div class="ribbon-discount">🎉 5th VISIT: 50% OFF UNLOCKED!</div>
    <?php elseif ($isCooldown): ?>
      <div class="ribbon-normal">🕒 12-Hour Cooldown Active (Stamp Maintained)</div>
    <?php else: ?>
      <div class="ribbon-normal">
        ⭐ Visit <?= $projectedVisit ?> of 5 &bull; <?= $remainingVisits ?> more <?= $remainingVisits === 1 ? 'visit' : 'visits' ?> until 50% OFF!
      </div>
    <?php endif; ?>

    <div class="token-label">Order Token</div>
    <div class="order-token"><?= $orderId ?></div>

    <div class="details-box">
      <div class="detail-row">
        <span>Customer:</span>
        <span><?= $displayName ?></span>
      </div>
      <div class="detail-row">
        <span>Mobile:</span>
        <span><?= $displayPhone ?></span>
      </div>
      <div class="detail-row">
        <span>Items:</span>
        <span><?= $displayItems ?></span>
      </div>

      <div class="price-box">
        <div style="font-size: 0.8rem; color: #666; margin-bottom: 2px;">Payable at Counter</div>
        <?php if ($isDiscount): ?>
          <div class="strike-price">Original: ₹<?= number_format($resTotal, 2) ?></div>
        <?php endif; ?>
        <div class="final-price">₹<?= number_format($resFinal, 2) ?></div>
      </div>
    </div>

    <!-- Explicit instruction required by spec -->
    <div class="staff-instruction">
      📢 Show this screen to the counter staff to verify.
    </div>

    <?php if ($isCooldown): ?>
      <div class="cooldown-note">
        Orders within 12 hours of your last visit are logged without incrementing loyalty stamps.
      </div>
    <?php endif; ?>

    <a href="<?= htmlspecialchars($menuUrl, ENT_QUOTES, 'UTF-8') ?>?clear=1" class="new-order-link" onclick="clearLocalActiveOrder()">Place Another Order</a>
  </div>

  <script>
    // Store active order into localStorage as an extra fallback across browser refreshes
    const orderData = <?= json_encode([
        'orderId' => $orderId,
        'name' => $displayName,
        'phone' => $displayPhone,
        'itemsDisplay' => $displayItems,
        'totalAmount' => $resTotal,
        'finalPrice' => $resFinal,
        'isDiscount' => $isDiscount,
        'isCooldown' => $isCooldown,
        'projectedVisit' => $projectedVisit,
        'remainingVisits' => $remainingVisits,
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE) ?>;

    try {
        localStorage.setItem('mama_active_order', JSON.stringify(orderData));
        let all = JSON.parse(localStorage.getItem('mama_all_orders') || '{}');
        all[orderData.orderId.toUpperCase()] = orderData;
        localStorage.setItem('mama_all_orders', JSON.stringify(all));
    } catch (_) {}

    function clearLocalActiveOrder() {
        try {
            localStorage.removeItem('mama_active_order');
        } catch (_) {}
    }
  </script>
</body>
</html>
