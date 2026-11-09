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

// Background status polling for live order confirmation by vendor
if (isset($_GET['action']) && $_GET['action'] === 'check_status') {
    header('Content-Type: application/json; charset=utf-8');
    $token = strtoupper(trim((string)($_GET['token'] ?? ($_SESSION['active_order']['orderId'] ?? ''))));
    if ($token === '') {
        echo json_encode(['success' => false, 'confirmed' => false, 'status' => 'UNKNOWN']);
        exit;
    }

    // 1. Fast local order cache check
    $projCache = dirname(__DIR__) . '/orders_cache.json';
    $tempCache = sys_get_temp_dir() . '/mama_momo_orders.json';
    foreach ([$projCache, $tempCache] as $file) {
        if (file_exists($file)) {
            $cached = json_decode(@file_get_contents($file), true) ?: [];
            if (isset($cached[$token])) {
                $st = strtoupper((string)($cached[$token]['status'] ?? 'PENDING'));
                if ($st === 'APPROVED' || $st === 'APPROVED_COOLDOWN' || $st === 'CONFIRMED') {
                    if (!empty($_SESSION['active_order']) && $_SESSION['active_order']['orderId'] === $token) {
                        $_SESSION['active_order']['status'] = 'APPROVED';
                    }
                    echo json_encode([
                        'success' => true,
                        'confirmed' => true,
                        'status' => $st,
                        'visitNumber' => $cached[$token]['visitNumber'] ?? null
                    ]);
                    exit;
                }
            }
        }
    }

    // 2. Authoritative check via Google Apps Script
    $gasUrl = $APPS_SCRIPT_URL . (strpos($APPS_SCRIPT_URL, '?') === false ? '?' : '&') . 'action=getOrder&orderId=' . urlencode($token);
    $ch = curl_init($gasUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 4
    ]);
    $res = curl_exec($ch);
    curl_close($ch);

    if ($res) {
        $parsed = json_decode($res, true);
        if ($parsed && !empty($parsed['success']) && !empty($parsed['status'])) {
            $st = strtoupper((string)$parsed['status']);
            $isConfirmed = ($st === 'APPROVED' || $st === 'APPROVED_COOLDOWN' || $st === 'CONFIRMED');
            if ($isConfirmed) {
                if (!empty($_SESSION['active_order']) && $_SESSION['active_order']['orderId'] === $token) {
                    $_SESSION['active_order']['status'] = 'APPROVED';
                }
                foreach ([$projCache, $tempCache] as $file) {
                    $data = file_exists($file) ? (json_decode(@file_get_contents($file), true) ?: []) : [];
                    if (isset($data[$token])) {
                        $data[$token]['status'] = 'APPROVED';
                        @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE));
                    }
                }
                echo json_encode([
                    'success' => true,
                    'confirmed' => true,
                    'status' => $st,
                    'visitNumber' => $parsed['visitNumber'] ?? null
                ]);
                exit;
            }
        }
    }

    echo json_encode(['success' => true, 'confirmed' => false, 'status' => 'PENDING']);
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

        if ($rawResponse === false || empty($rawResponse)) {
            // Local failover token generation if Google Apps Script is slow/unreachable
            $orderToken = 'MM' . str_pad((string)random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
            $profile = findCustomerProfile($phone);
            $currVisits = $profile['currentVisits'] ?? 0;
            $result = [
                'success' => true,
                'orderId' => $orderToken,
                'name' => $name,
                'phone' => $phone,
                'itemsDisplay' => $cartItems,
                'totalAmount' => $totalAmount,
                'finalPrice' => $totalAmount,
                'projectedVisit' => $currVisits + 1,
                'currentVisits' => $currVisits,
                'remainingVisits' => max(0, 5 - (($currVisits + 1) % 5)),
                'isDiscount' => false,
                'isCooldown' => false,
                'status' => 'PENDING'
            ];
        } else {
            $result = json_decode($rawResponse, true);
        }

        if (!is_array($result) || empty($result['success'])) {
            // Self-healing failover token
            $orderToken = 'MM' . str_pad((string)random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
            $profile = findCustomerProfile($phone);
            $currVisits = $profile['currentVisits'] ?? 0;
            $result = [
                'success' => true,
                'orderId' => $orderToken,
                'name' => $name,
                'phone' => $phone,
                'itemsDisplay' => $cartItems,
                'totalAmount' => $totalAmount,
                'finalPrice' => $totalAmount,
                'projectedVisit' => $currVisits + 1,
                'currentVisits' => $currVisits,
                'remainingVisits' => max(0, 5 - (($currVisits + 1) % 5)),
                'isDiscount' => false,
                'isCooldown' => false,
                'status' => 'PENDING'
            ];
        }

        // Safeguard parsed values against empty or 0 returns
        $safeTotal = (!empty($result['totalAmount']) && (float)$result['totalAmount'] > 0) ? (float)$result['totalAmount'] : $totalAmount;
        $safeFinal = (!empty($result['finalPrice']) && (float)$result['finalPrice'] > 0) ? (float)$result['finalPrice'] : $safeTotal;
        $safeItems = (!empty($result['itemsDisplay']) && trim($result['itemsDisplay']) !== '')
            ? (string)$result['itemsDisplay']
            : ((!empty($result['items']) && is_string($result['items']) && trim($result['items']) !== '') ? (string)$result['items'] : $cartItems);
        $orderAddress = trim((string)($_POST['address'] ?? ''));

        // Save confirmed order data to session so it persists across refreshes
        $_SESSION['active_order'] = [
            'orderId' => (string)$result['orderId'],
            'name' => (string)($result['name'] ?? $name),
            'phone' => (string)($result['phone'] ?? $phone),
            'address' => $orderAddress,
            'itemsDisplay' => $safeItems,
            'totalAmount' => $safeTotal,
            'finalPrice' => $safeFinal,
            'projectedVisit' => (int)($result['projectedVisit'] ?? 1),
            'currentVisits' => (int)($result['currentVisits'] ?? 0),
            'remainingVisits' => (int)($result['remainingVisits'] ?? 0),
            'isDiscount' => !empty($result['isDiscount']),
            'isCooldown' => !empty($result['isCooldown']),
            'status' => 'PENDING',
            'timestamp' => time()
        ];

        // Also ensure customer profile exists in cache
        saveCustomerToCache($phone, $name, $orderAddress);

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
  <!-- Favicon & Touch Icons -->
  <link rel="icon" type="image/svg+xml" href="<?= ($basePath ?: '') ?>/favicon.svg">
  <link rel="alternate icon" type="image/png" sizes="32x32" href="<?= ($basePath ?: '') ?>/favicon-32x32.png">
  <link rel="alternate icon" type="image/png" sizes="16x16" href="<?= ($basePath ?: '') ?>/favicon-16x16.png">
  <link rel="apple-touch-icon" sizes="180x180" href="<?= ($basePath ?: '') ?>/apple-touch-icon.png">
  <link rel="shortcut icon" href="<?= ($basePath ?: '') ?>/favicon.ico">
  <meta name="theme-color" content="#ef4444">
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

// Detect if order is already confirmed
$isAlreadyConfirmed = false;
if (!empty($result['status']) && ($result['status'] === 'APPROVED' || $result['status'] === 'APPROVED_COOLDOWN' || $result['status'] === 'CONFIRMED')) {
    $isAlreadyConfirmed = true;
} else {
    $projCache = dirname(__DIR__) . '/orders_cache.json';
    $tempCache = sys_get_temp_dir() . '/mama_momo_orders.json';
    foreach ([$projCache, $tempCache] as $file) {
        if (file_exists($file)) {
            $cached = json_decode(@file_get_contents($file), true) ?: [];
            if (isset($cached[(string)$result['orderId']])) {
                $st = strtoupper((string)($cached[(string)$result['orderId']]['status'] ?? ''));
                if ($st === 'APPROVED' || $st === 'APPROVED_COOLDOWN' || $st === 'CONFIRMED') {
                    $isAlreadyConfirmed = true;
                    break;
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Order Pass - <?= $orderId ?></title>
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
    .waiting-badge {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      background: #eff6ff;
      border: 1px solid #bfdbfe;
      color: #1e40af;
      padding: 8px 14px;
      border-radius: 20px;
      font-size: 0.82rem;
      font-weight: 600;
      margin-top: 14px;
    }
    .pulse-dot {
      width: 8px;
      height: 8px;
      background: #2563eb;
      border-radius: 50%;
      animation: pulseDot 1.2s infinite ease-in-out;
    }
    @keyframes pulseDot {
      0%, 100% { transform: scale(0.85); opacity: 0.5; }
      50% { transform: scale(1.35); opacity: 1; }
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

    /* Thank You & Collect Order Card */
    .thankyou-card {
      max-width: 440px;
      width: 100%;
      background: #ffffff;
      border-radius: 24px;
      padding: 32px 24px;
      text-align: center;
      box-shadow: 0 16px 40px rgba(0, 0, 0, 0.08);
      border: 1px solid #e2e8f0;
      animation: popIn 0.35s cubic-bezier(0.16, 1, 0.3, 1);
    }
    @keyframes popIn {
      from { transform: scale(0.92); opacity: 0; }
      to { transform: scale(1); opacity: 1; }
    }
    .success-ring {
      width: 72px;
      height: 72px;
      background: #ecfdf5;
      border: 3px solid #10b981;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 16px;
      animation: bounceRing 0.6s ease-out;
    }
    @keyframes bounceRing {
      0% { transform: scale(0.4); opacity: 0; }
      60% { transform: scale(1.15); opacity: 1; }
      100% { transform: scale(1); }
    }
    .checkmark {
      font-size: 2.2rem;
      color: #10b981;
      font-weight: 900;
      line-height: 1;
    }
    .thankyou-title {
      font-size: 1.55rem;
      font-weight: 800;
      color: #111827;
      margin-bottom: 14px;
    }
    .collect-banner {
      background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
      border: 2px solid #f59e0b;
      border-radius: 16px;
      padding: 16px 14px;
      margin-bottom: 20px;
      box-shadow: 0 4px 14px rgba(245, 158, 11, 0.15);
    }
    .collect-badge {
      display: inline-block;
      background: #b45309;
      color: #ffffff;
      font-size: 0.75rem;
      font-weight: 800;
      letter-spacing: 1px;
      padding: 3px 10px;
      border-radius: 12px;
      text-transform: uppercase;
      margin-bottom: 6px;
    }
    .collect-main-msg {
      font-size: 1.15rem;
      font-weight: 900;
      color: #92400e;
      line-height: 1.35;
      margin-bottom: 10px;
    }
    .collect-token-row {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      background: #ffffff;
      padding: 6px 16px;
      border-radius: 10px;
      border: 1px solid #fbbf24;
    }
    .collect-token-label {
      font-size: 0.8rem;
      color: #78350f;
      font-weight: 700;
    }
    .collect-token-badge {
      font-size: 1.25rem;
      font-weight: 900;
      color: #d9381e;
      letter-spacing: 1.5px;
    }
    .confirmed-details-card {
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      border-radius: 14px;
      padding: 14px 16px;
      text-align: left;
      margin-bottom: 16px;
      font-size: 0.92rem;
    }
    .conf-row {
      display: flex;
      justify-content: space-between;
      gap: 12px;
      margin-bottom: 8px;
    }
    .conf-row:last-child {
      margin-bottom: 0;
      border-top: 1px dashed #cbd5e1;
      padding-top: 8px;
      margin-top: 8px;
      align-items: center;
    }
    .conf-row span {
      color: #64748b;
    }
    .conf-row strong {
      color: #1e293b;
      text-align: right;
    }
    .status-pill-confirmed {
      background: #dcfce7;
      color: #15803d;
      font-size: 0.78rem;
      font-weight: 800;
      padding: 4px 10px;
      border-radius: 20px;
      border: 1px solid #bbf7d0;
    }
    .loyalty-celebration {
      background: #f0fdf4;
      border: 1px solid #86efac;
      color: #166534;
      padding: 12px 14px;
      border-radius: 12px;
      font-size: 0.88rem;
      line-height: 1.4;
      margin-bottom: 18px;
    }
    .btn-new-order {
      display: block;
      background: #d9381e;
      color: #ffffff;
      padding: 14px 20px;
      border-radius: 12px;
      font-weight: 800;
      font-size: 1rem;
      text-decoration: none;
      box-shadow: 0 4px 14px rgba(217, 56, 30, 0.25);
      transition: transform 0.1s;
    }
    .btn-new-order:active {
      transform: scale(0.98);
    }
    .btn-toggle-pass {
      background: transparent;
      border: 1px solid #cbd5e1;
      color: #64748b;
      padding: 10px 16px;
      border-radius: 10px;
      font-size: 0.85rem;
      font-weight: 700;
      cursor: pointer;
      width: 100%;
      transition: background 0.15s;
    }
    .btn-toggle-pass:hover {
      background: #f1f5f9;
      color: #334155;
    }
  </style>
</head>
<body>
  <!-- PASS CARD (Active before vendor confirmation) -->
  <div class="pass-card" id="passCard" style="<?= $isAlreadyConfirmed ? 'display: none;' : 'display: block;' ?>">
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

    <div class="waiting-badge" id="waitingBadge">
      <span class="pulse-dot"></span>
      <span>Waiting for counter verification &amp; confirmation…</span>
    </div>

    <?php if ($isCooldown): ?>
      <div class="cooldown-note">
        Orders within 12 hours of your last visit are logged without incrementing loyalty stamps.
      </div>
    <?php endif; ?>

    <a href="<?= htmlspecialchars($menuUrl, ENT_QUOTES, 'UTF-8') ?>?clear=1" class="new-order-link" onclick="clearLocalActiveOrder()">Place Another Order</a>
  </div>

  <!-- THANK YOU & COLLECT ORDER CARD (Revealed upon vendor confirmation) -->
  <div class="thankyou-card" id="thankYouCard" style="<?= $isAlreadyConfirmed ? 'display: block;' : 'display: none;' ?>">
    <div class="success-ring">
      <span class="checkmark">✓</span>
    </div>

    <h1 class="thankyou-title">Thank You, <?= $displayName ?>!</h1>

    <div class="collect-banner">
      <div class="collect-badge">🔔 ORDER CONFIRMED</div>
      <div class="collect-main-msg">Please collect your freshly prepared momos at the counter!</div>
      <div class="collect-token-row">
        <span class="collect-token-label">Your Token:</span>
        <span class="collect-token-badge"><?= $orderId ?></span>
      </div>
    </div>

    <div class="confirmed-details-card">
      <div class="conf-row">
        <span>Customer:</span>
        <strong><?= $displayName ?> (<?= $displayPhone ?>)</strong>
      </div>
      <div class="conf-row">
        <span>Items:</span>
        <strong><?= $displayItems ?></strong>
      </div>
      <div class="conf-row">
        <span>Amount Paid:</span>
        <strong style="color: #166534; font-size: 1.05rem;">₹<?= number_format($resFinal, 2) ?> (Verified &amp; Paid)</strong>
      </div>
      <div class="conf-row">
        <span>Status:</span>
        <span class="status-pill-confirmed">✓ Confirmed &amp; Stamped</span>
      </div>
    </div>

    <div class="loyalty-celebration">
      <?php if ($isDiscount): ?>
        🎉 <strong>50% Loyalty Discount Applied!</strong> Enjoy your half-price momos!
      <?php elseif ($isCooldown): ?>
        🕒 <strong>Visit Recorded!</strong> Stamp counter maintained under cooldown.
      <?php else: ?>
        ⭐ <strong>Stamp Recorded!</strong> Visit <?= $projectedVisit ?> of 5 stamped &bull; <?= $remainingVisits ?> more until 50% OFF!
      <?php endif; ?>
    </div>

    <div style="margin-top: 20px; display: flex; flex-direction: column; gap: 10px;">
      <a href="<?= htmlspecialchars($menuUrl, ENT_QUOTES, 'UTF-8') ?>?clear=1" class="btn-new-order" onclick="clearLocalActiveOrder()">
        🥟 Place Another Order
      </a>
      <button type="button" class="btn-toggle-pass" onclick="togglePassView()">
        📋 View Original Token Pass
      </button>
    </div>
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

    let isOrderConfirmed = <?= $isAlreadyConfirmed ? 'true' : 'false' ?>;
    let pollTimer = null;

    function showThankYouScreen() {
      if (isOrderConfirmed && document.getElementById('thankYouCard').style.display !== 'none') return;
      isOrderConfirmed = true;
      if (pollTimer) clearInterval(pollTimer);

      playCelebrationTone();
      if (navigator.vibrate) {
        try { navigator.vibrate([150, 80, 200]); } catch (_) {}
      }

      const passCard = document.getElementById('passCard');
      const thankYouCard = document.getElementById('thankYouCard');

      if (passCard) passCard.style.display = 'none';
      if (thankYouCard) thankYouCard.style.display = 'block';

      try {
        localStorage.setItem('mama_confirmed_' + orderData.orderId.toUpperCase(), 'APPROVED');
      } catch (_) {}
    }

    function playCelebrationTone() {
      try {
        const AudioCtx = window.AudioContext || window.webkitAudioContext;
        if (!AudioCtx) return;
        const ctx = new AudioCtx();
        const now = ctx.currentTime;

        const osc1 = ctx.createOscillator();
        const gain1 = ctx.createGain();
        osc1.frequency.setValueAtTime(587.33, now);
        gain1.gain.setValueAtTime(0.2, now);
        gain1.gain.exponentialRampToValueAtTime(0.001, now + 0.22);
        osc1.connect(gain1);
        gain1.connect(ctx.destination);
        osc1.start(now);
        osc1.stop(now + 0.22);

        const osc2 = ctx.createOscillator();
        const gain2 = ctx.createGain();
        osc2.frequency.setValueAtTime(880, now + 0.12);
        gain2.gain.setValueAtTime(0.25, now + 0.12);
        gain2.gain.exponentialRampToValueAtTime(0.001, now + 0.5);
        osc2.connect(gain2);
        gain2.connect(ctx.destination);
        osc2.start(now + 0.12);
        osc2.stop(now + 0.5);
      } catch (_) {}
    }

    function pollOrderStatus() {
      if (isOrderConfirmed) return;

      // Tier 1: Check localStorage (instant same-browser cross-tab signal)
      try {
        const localStatus = localStorage.getItem('mama_confirmed_' + orderData.orderId.toUpperCase());
        if (localStatus === 'APPROVED' || localStatus === 'CONFIRMED') {
          showThankYouScreen();
          return;
        }
      } catch (_) {}

      // Tier 2: Check server endpoint
      fetch('confirm?action=check_status&token=' + encodeURIComponent(orderData.orderId))
        .then(res => res.json())
        .then(data => {
          if (data && data.confirmed) {
            showThankYouScreen();
          }
        })
        .catch(() => {});
    }

    if (!isOrderConfirmed) {
      pollTimer = setInterval(pollOrderStatus, 2200);
      setTimeout(pollOrderStatus, 400);
    }

    function togglePassView() {
      const passCard = document.getElementById('passCard');
      const thankYouCard = document.getElementById('thankYouCard');
      if (!passCard || !thankYouCard) return;

      if (passCard.style.display === 'none') {
        passCard.style.display = 'block';
        thankYouCard.style.display = 'none';
      } else {
        passCard.style.display = 'none';
        thankYouCard.style.display = 'block';
      }
    }
  </script>
</body>
</html>
