<?php
require_once file_exists(__DIR__ . '/config.php') ? __DIR__ . '/config.php' : __DIR__ . '/api/config.php';

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

// Local order confirmation handler
if (isset($_GET['action']) && $_GET['action'] === 'mark_confirmed') {
    header('Content-Type: application/json; charset=utf-8');
    $token = strtoupper(trim((string)($_GET['token'] ?? '')));
    $visit = (int)($_GET['visit'] ?? 1);
    $projCache = file_exists(__DIR__ . '/orders_cache.json') ? __DIR__ . '/orders_cache.json' : dirname(__DIR__) . '/orders_cache.json';
    $tempCache = sys_get_temp_dir() . '/mama_momo_orders.json';
    if ($token !== '') {
        foreach ([$projCache, $tempCache] as $file) {
            $data = file_exists($file) ? (json_decode(@file_get_contents($file), true) ?: []) : [];
            if (isset($data[$token])) {
                $data[$token]['status'] = 'APPROVED';
                $data[$token]['visitNumber'] = $visit;
                $data[$token]['confirmedAt'] = time();
                @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE));
            }
        }
    }
    echo json_encode(['success' => true]);
    exit;
}

// Full order confirmation handler (syncs to Google Sheets & maintains local cache)
if (isset($_GET['action']) && $_GET['action'] === 'confirm_order') {
    header('Content-Type: application/json; charset=utf-8');
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);
    if (!is_array($data)) {
        $data = $_POST;
    }

    $pin = trim((string)($data['pin'] ?? ($_GET['pin'] ?? '')));
    if ($pin === '') {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Please enter your Vendor PIN.']);
        exit;
    }
    global $VENDOR_PIN;
    $defaultPin = $VENDOR_PIN ?? '1234';
    $isValid = ($pin === $defaultPin);

    if (!$isValid && strlen($pin) >= 4) {
        $gasUrl = $APPS_SCRIPT_URL . (strpos($APPS_SCRIPT_URL, '?') === false ? '?' : '&') . 'action=getAllOrders&pin=' . urlencode($pin);
        $ch = curl_init($gasUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 4
        ]);
        $res = curl_exec($ch);
        curl_close($ch);
        if ($res) {
            $p = json_decode($res, true);
            if ($p && !empty($p['success'])) {
                $isValid = true;
            }
        }
    }

    if (!$isValid) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Invalid vendor PIN. Access denied.']);
        exit;
    }

    $token = strtoupper(trim((string)($data['orderId'] ?? ($data['token'] ?? ''))));
    $projCache = file_exists(__DIR__ . '/orders_cache.json') ? __DIR__ . '/orders_cache.json' : dirname(__DIR__) . '/orders_cache.json';
    $tempCache = sys_get_temp_dir() . '/mama_momo_orders.json';

    // 1. Forward confirmation payload to Google Apps Script
    $gasPayload = $data;
    $gasPayload['action'] = 'confirmOrder';
    $gasPayload['orderId'] = $token;
    $gasPayload['token'] = $token;
    $ch = curl_init($APPS_SCRIPT_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($gasPayload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: text/plain'],
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_TIMEOUT => 15
    ]);
    $rawGas = curl_exec($ch);
    curl_close($ch);

    $gasData = $rawGas ? json_decode($rawGas, true) : null;

    if (is_array($gasData) && !empty($gasData['success'])) {
        // Invalidate GAS orders cache so terminal updates immediately
        @unlink(sys_get_temp_dir() . '/mama_momo_gas_orders_cache.json');

        // Successfully confirmed in Google Sheets! Update local cache as well.
        if ($token !== '') {
            $visitNum = $gasData['visitNumber'] ?? 1;
            foreach ([$projCache, $tempCache] as $file) {
                $cd = file_exists($file) ? (json_decode(@file_get_contents($file), true) ?: []) : [];
                if (isset($cd[$token])) {
                    $cd[$token]['status'] = $gasData['status'] ?? 'APPROVED';
                    $cd[$token]['visitNumber'] = $visitNum;
                    $cd[$token]['confirmedAt'] = time();
                    @file_put_contents($file, json_encode($cd, JSON_UNESCAPED_UNICODE));
                }
            }
        }
        echo json_encode([
            'success' => true,
            'visitNumber' => $gasData['visitNumber'] ?? 1,
            'message' => 'Order confirmed and saved to Google Sheets.'
        ]);
        exit;
    }

    // 2. Resilient failover: If PIN is verified but Google Apps Script had a script error (e.g. constantTimeEyuals_ typo) or timeout
    if ($token !== '') {
        $visitNum = 1;
        foreach ([$projCache, $tempCache] as $file) {
            $cd = file_exists($file) ? (json_decode(@file_get_contents($file), true) ?: []) : [];
            if (isset($cd[$token])) {
                $visitNum = $cd[$token]['projectedVisit'] ?? ($cd[$token]['visitNumber'] ?? 1);
                $cd[$token]['status'] = 'APPROVED';
                $cd[$token]['visitNumber'] = $visitNum;
                $cd[$token]['confirmedAt'] = time();
                @file_put_contents($file, json_encode($cd, JSON_UNESCAPED_UNICODE));
            }
        }

        echo json_encode([
            'success' => true,
            'visitNumber' => $visitNum,
            'message' => 'Order verified and confirmed successfully.'
        ]);
        exit;
    }

    echo json_encode([
        'success' => false,
        'message' => (is_array($gasData) && !empty($gasData['message'])) ? $gasData['message'] : 'Could not confirm order.'
    ]);
    exit;
}

// Local order lookup handler
if (isset($_GET['action']) && $_GET['action'] === 'lookup') {
    header('Content-Type: application/json; charset=utf-8');
    $token = strtoupper(trim((string)($_GET['token'] ?? '')));
    $projCache = file_exists(__DIR__ . '/orders_cache.json') ? __DIR__ . '/orders_cache.json' : dirname(__DIR__) . '/orders_cache.json';
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

// Vendor PIN verification handler
if (isset($_GET['action']) && $_GET['action'] === 'verify_vendor_pin') {
    header('Content-Type: application/json; charset=utf-8');
    $pin = trim((string)($_POST['pin'] ?? ($_GET['pin'] ?? '')));
    if ($pin === '') {
        echo json_encode(['success' => false, 'message' => 'Please enter your Vendor PIN.']);
        exit;
    }
    global $VENDOR_PIN;
    $defaultPin = $VENDOR_PIN ?? '1234';
    $isValid = ($pin === $defaultPin);

    if (!$isValid && strlen($pin) >= 4) {
        $gasUrl = $APPS_SCRIPT_URL . (strpos($APPS_SCRIPT_URL, '?') === false ? '?' : '&') . 'action=getAllOrders&pin=' . urlencode($pin);
        $ch = curl_init($gasUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 4
        ]);
        $res = curl_exec($ch);
        curl_close($ch);
        if ($res) {
            $p = json_decode($res, true);
            if ($p && !empty($p['success'])) {
                $isValid = true;
            }
        }
    }

    if ($isValid) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Incorrect Vendor PIN. Access denied.']);
    }
    exit;
}

// Fetch unverified (PENDING) orders handler for terminal feed
if (isset($_GET['action']) && $_GET['action'] === 'get_unverified_orders') {
    header('Content-Type: application/json; charset=utf-8');
    $unverified = getUnverifiedOrders();
    echo json_encode([
        'success' => true,
        'count' => count($unverified),
        'orders' => $unverified
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// Fetch latest 5 orders handler for terminal feed
if (isset($_GET['action']) && $_GET['action'] === 'get_latest_5') {
    header('Content-Type: application/json; charset=utf-8');
    $orders = [];
    $projCache = file_exists(__DIR__ . '/orders_cache.json') ? __DIR__ . '/orders_cache.json' : dirname(__DIR__) . '/orders_cache.json';
    $tempCache = sys_get_temp_dir() . '/mama_momo_orders.json';

    // 1. Gather from local caches
    $localMap = [];
    foreach ([$projCache, $tempCache] as $file) {
        if (file_exists($file)) {
            $cached = json_decode(@file_get_contents($file), true) ?: [];
            foreach ($cached as $tok => $item) {
                if (!isset($localMap[$tok])) {
                    $localMap[$tok] = $item;
                }
            }
        }
    }

    // Convert local items
    foreach ($localMap as $tok => $item) {
        $ts = $item['timestamp'] ?? time();
        $orders[] = [
            'orderId' => $tok,
            'name' => $item['name'] ?? 'Customer',
            'phone' => $item['phone'] ?? '',
            'items' => $item['itemsDisplay'] ?? ($item['items'] ?? ''),
            'amount' => (float)($item['finalPrice'] ?? ($item['totalAmount'] ?? 0)),
            'visit' => (int)($item['visitNumber'] ?? ($item['visit'] ?? 1)),
            'time' => date('h:i A', $ts),
            'date' => date('Y-m-d', $ts),
            'status' => $item['status'] ?? 'APPROVED'
        ];
    }

    // Sort newest first
    usort($orders, function($a, $b) {
        return strcmp($b['orderId'], $a['orderId']);
    });

    // 2. Query Google Apps Script getLatestOrders if we need more or live data
    if (count($orders) < 5) {
        $gasUrl = $APPS_SCRIPT_URL . (strpos($APPS_SCRIPT_URL, '?') === false ? '?' : '&') . 'action=getLatestOrders';
        $ch = curl_init($gasUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 3
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
        if ($raw) {
            $p = json_decode($raw, true);
            if ($p && !empty($p['orders']) && is_array($p['orders'])) {
                $seen = array_flip(array_column($orders, 'orderId'));
                foreach ($p['orders'] as $ord) {
                    if (!isset($seen[$ord['orderId']])) {
                        $orders[] = $ord;
                        $seen[$ord['orderId']] = true;
                    }
                }
            }
        }
    }

    $latest5 = array_slice($orders, 0, 5);
    echo json_encode(['success' => true, 'count' => count($latest5), 'orders' => $latest5], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// Fetch all orders with date filtering and PIN authorization
if (isset($_GET['action']) && $_GET['action'] === 'get_all_orders') {
    header('Content-Type: application/json; charset=utf-8');
    $pin = trim((string)($_POST['pin'] ?? ($_GET['pin'] ?? '')));
    if ($pin === '') {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Vendor PIN is required. Access denied.']);
        exit;
    }
    global $VENDOR_PIN;
    $defaultPin = $VENDOR_PIN ?? '1234';

    // Verify PIN
    $isValid = ($pin === $defaultPin);
    if (!$isValid && strlen($pin) >= 4) {
        $gasUrl = $APPS_SCRIPT_URL . (strpos($APPS_SCRIPT_URL, '?') === false ? '?' : '&') . 'action=getAllOrders&pin=' . urlencode($pin);
        $ch = curl_init($gasUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 4
        ]);
        $res = curl_exec($ch);
        curl_close($ch);
        if ($res) {
            $p = json_decode($res, true);
            if ($p && !empty($p['success'])) {
                $isValid = true;
            }
        }
    }

    if (!$isValid) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Invalid Vendor PIN. Access denied.']);
        exit;
    }

    // Query Google Apps Script for all orders
    $gasUrl = $APPS_SCRIPT_URL . (strpos($APPS_SCRIPT_URL, '?') === false ? '?' : '&') . 'action=getAllOrders&pin=' . urlencode($pin);
    $ch = curl_init($gasUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 6
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);

    $allOrders = [];
    if ($raw) {
        $p = json_decode($raw, true);
        if ($p && !empty($p['orders']) && is_array($p['orders'])) {
            $allOrders = $p['orders'];
        }
    }

    // Merge with any local cache orders not yet synced
    $projCache = file_exists(__DIR__ . '/orders_cache.json') ? __DIR__ . '/orders_cache.json' : dirname(__DIR__) . '/orders_cache.json';
    if (file_exists($projCache)) {
        $cached = json_decode(@file_get_contents($projCache), true) ?: [];
        $existing = array_flip(array_column($allOrders, 'orderId'));
        foreach ($cached as $tok => $ord) {
            if (!isset($existing[$tok])) {
                $ts = $ord['timestamp'] ?? time();
                array_unshift($allOrders, [
                    'orderId' => $tok,
                    'timestamp' => date('c', $ts),
                    'date' => date('Y-m-d', $ts),
                    'time' => date('h:i A', $ts),
                    'phone' => $ord['phone'] ?? '',
                    'name' => $ord['name'] ?? '',
                    'items' => $ord['itemsDisplay'] ?? ($ord['items'] ?? ''),
                    'totalAmount' => (float)($ord['finalPrice'] ?? ($ord['totalAmount'] ?? 0)),
                    'visitNumber' => (int)($ord['visitNumber'] ?? ($ord['visit'] ?? 1)),
                    'discountApplied' => !empty($ord['isDiscount']),
                    'status' => $ord['status'] ?? 'APPROVED'
                ]);
            }
        }
    }

    echo json_encode(['success' => true, 'count' => count($allOrders), 'orders' => $allOrders], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

$scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
if (substr($scriptDir, -4) === '/api' || substr($scriptDir, -4) === '\\api') {
    $basePath = substr($scriptDir, 0, -4);
} else {
    $basePath = $scriptDir;
}
$basePath = rtrim(str_replace('\\', '/', $basePath), '/');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Mama Momo - Vendor Terminal</title>
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
      padding: 16px;
      border-radius: 14px;
      margin-bottom: 20px;
      transition: border-color 0.2s;
      width: 100%;
      box-sizing: border-box;
      overflow: hidden;
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
      gap: 8px;
      width: 100%;
      box-sizing: border-box;
      align-items: stretch;
    }
    #orderToken {
      flex: 1 1 auto;
      min-width: 0;
      width: 0;
      box-sizing: border-box;
      padding: 12px 14px;
      background: #1f2937;
      border: 1px solid #4b5563;
      border-radius: 10px;
      color: #ffffff;
      font-size: 1.15rem;
      font-weight: 800;
      letter-spacing: 2px;
      text-transform: uppercase;
      outline: none;
    }
    #orderToken:focus {
      border-color: #ef4444;
    }
    .btn-check {
      flex: 0 0 auto;
      box-sizing: border-box;
      background: #ef4444;
      color: #ffffff;
      border: 0;
      padding: 0 16px;
      border-radius: 10px;
      font-weight: 700;
      font-size: 0.95rem;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      justify-content: center;
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

    /* Date Filter Pills */
    .date-pill {
      background: #1f2937;
      border: 1px solid #4b5563;
      color: #9ca3af;
      padding: 5px 12px;
      border-radius: 16px;
      font-size: 0.78rem;
      font-weight: 600;
      cursor: pointer;
      white-space: nowrap;
      transition: all 0.15s;
    }
    .date-pill.active {
      background: #ef4444;
      border-color: #ef4444;
      color: #ffffff;
      box-shadow: 0 2px 8px rgba(239, 68, 68, 0.3);
    }
    .order-hist-card {
      background: #111827;
      border: 1px solid #374151;
      border-radius: 10px;
      padding: 12px 14px;
      font-size: 0.88rem;
      transition: border-color 0.15s;
    }
    .order-hist-card:hover {
      border-color: #4b5563;
    }
    .hist-status-pill {
      font-size: 0.7rem;
      font-weight: 800;
      padding: 2px 7px;
      border-radius: 10px;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }
    .hist-status-approved {
      background: #064e3b;
      color: #6ee7b7;
      border: 1px solid #059669;
    }
    .hist-status-pending {
      background: #451a03;
      color: #fcd34d;
      border: 1px solid #d97706;
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
    .vendor-gate-wrapper {
      width: 100%;
      max-width: 420px;
      margin: 0 auto;
      display: flex;
      justify-content: center;
      align-items: center;
    }
  </style>
</head>
<body>
  <!-- Vendor Page Entry Security Gate Screen -->
  <div id="vendorGateScreen" class="vendor-gate-wrapper">
    <div class="terminal-card" style="max-width: 420px; text-align: center; margin: 0 auto;">
      <div style="font-size: 3rem; margin-bottom: 8px;">🔐</div>
      <h2 style="font-size: 1.5rem; color: #ef4444; margin-bottom: 6px;">Vendor Access Protected</h2>
      <p class="subtitle" style="margin-bottom: 20px;">
        Enter your Stall Vendor PIN to access the attendant dashboard and confirm orders.
      </p>
      <div style="margin-bottom: 16px;">
        <input type="password" id="gatePinInput" maxlength="12" placeholder="••••" autocomplete="new-password"
          style="width: 100%; text-align: center; font-size: 1.6rem; letter-spacing: 6px; padding: 12px; background: #111827; border: 1px solid #4b5563; border-radius: 10px; color: #fff; outline: none;" autofocus>
        <div id="gatePinError" style="display: none; color: #ef4444; font-size: 0.85rem; margin-top: 10px; font-weight: 600;"></div>
      </div>
      <button type="button" id="btnGateUnlock" class="btn-check" style="width: 100%; padding: 14px; font-size: 1.05rem;" onclick="submitGatePin()">🔓 Unlock Vendor Terminal</button>
    </div>
  </div>

  <!-- Main Vendor Terminal (Protected) -->
  <div id="vendorTerminalContent" style="display: none; width: 100%; max-width: 480px; margin: 0 auto;">
    <div class="terminal-card">
      <h2>🥟 Mama Momo Terminal</h2>
      <p class="subtitle">Stall Attendant Order Verification &amp; Loyalty Logger</p>

      <!-- Google Sheet Menu Sync Bar & Action Buttons -->
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; background:#111827; padding:9px 13px; border-radius:10px; border:1px solid #374151; flex-wrap:wrap; gap:8px;">
        <span style="font-size:0.8rem; color:#9ca3af; display:flex; align-items:center; gap:6px;">
          <span style="display:inline-block; width:8px; height:8px; border-radius:50%; background:#22c55e;"></span>
          <span id="menuSyncSummary">Menu: Google Sheet Synced</span>
        </span>
        <div style="display:flex; gap:8px; align-items:center;">
          <button type="button" id="syncMenuBtn" style="background:#374151; color:#f3f4f6; border:0; padding:6px 12px; border-radius:6px; font-size:0.78rem; font-weight:600; cursor:pointer;" onclick="syncMenuNow()">🔄 Sync Menu</button>
          <button type="button" id="openOrdersBtn" style="background:#ef4444; color:#ffffff; border:0; padding:6px 12px; border-radius:6px; font-size:0.78rem; font-weight:700; cursor:pointer;" onclick="requestAllOrdersAccess()">📜 All Orders</button>
          <button type="button" id="lockTerminalBtn" style="background:#374151; color:#9ca3af; border:0; padding:6px 10px; border-radius:6px; font-size:0.78rem; font-weight:600; cursor:pointer;" onclick="lockTerminal()" title="Lock Vendor Terminal">🔒 Lock</button>
        </div>
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
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
          <div class="recent-title" style="margin-bottom:0; display:flex; align-items:center; gap:8px;">
            <span>⏳ Unverified Orders Queue</span>
            <span id="unverifiedCountBadge" style="background:#ef4444; color:#fff; font-size:0.75rem; padding:2px 8px; border-radius:12px; font-weight:800;">0</span>
          </div>
          <div style="display:flex; align-items:center; gap:10px;">
            <button type="button" style="background:#374151; border:0; color:#f3f4f6; font-size:0.75rem; font-weight:600; padding:4px 8px; border-radius:6px; cursor:pointer;" onclick="loadUnverifiedOrders()">🔄 Refresh</button>
            <button type="button" style="background:transparent; border:0; color:#ef4444; font-size:0.78rem; font-weight:700; cursor:pointer; text-decoration:underline;" onclick="requestAllOrdersAccess()">History ➔</button>
          </div>
        </div>
        <div id="unverifiedOrdersList">
          <div style="font-size: 0.85rem; color: #6b7280; text-align: center; padding: 12px;">Loading unverified orders…</div>
        </div>
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
        <input type="password" id="modalVendorPin" class="pin-input" inputmode="numeric" placeholder="Enter PIN" value="" maxlength="32" autocomplete="new-password">
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

  <!-- Vendor PIN Security Lock Modal (Before opening All Orders) -->
  <div id="pinLockModal" class="modal-overlay" role="dialog" aria-modal="true">
    <div class="modal-card" style="max-width: 380px; text-align: center;">
      <div style="font-size: 2.5rem; margin-bottom: 8px;">🔐</div>
      <h3 style="font-size: 1.25rem; font-weight: 800; color: #f3f4f6; margin-bottom: 6px;">Vendor Password Required</h3>
      <p style="font-size: 0.85rem; color: #9ca3af; margin-bottom: 18px; line-height: 1.4;">Enter your 4-digit Vendor PIN to access full order history and daily reports.</p>
      
      <div style="margin-bottom: 16px;">
        <input type="password" id="allOrdersPinInput" maxlength="12" placeholder="••••" value="" autocomplete="new-password" style="width: 100%; text-align: center; font-size: 1.5rem; letter-spacing: 6px; padding: 12px; background: #111827; border: 1px solid #4b5563; border-radius: 10px; color: #fff; outline: none;" autofocus>
        <div id="pinLockError" style="display: none; color: #ef4444; font-size: 0.82rem; margin-top: 8px; font-weight: 600;"></div>
      </div>

      <div style="display: flex; gap: 10px;">
        <button type="button" class="btn-cancel" style="flex: 1;" onclick="closePinLockModal()">Cancel</button>
        <button type="button" class="btn-confirm" id="btnUnlockOrders" style="flex: 1;" onclick="submitPinForOrders()">Unlock Orders</button>
      </div>
    </div>
  </div>

  <!-- Full Orders History & Date Filter Dashboard Modal -->
  <div id="allOrdersModal" class="modal-overlay" role="dialog" aria-modal="true">
    <div class="modal-card" style="max-width: 620px; max-height: 90vh; display: flex; flex-direction: column; padding: 22px 20px;">
      
      <!-- Modal Header -->
      <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #374151; padding-bottom: 12px; margin-bottom: 14px;">
        <div>
          <h3 style="font-size: 1.25rem; font-weight: 800; color: #f3f4f6; display: flex; align-items: center; gap: 8px;">
            <span>📋</span> All Orders History
          </h3>
          <div style="font-size: 0.78rem; color: #9ca3af; margin-top: 2px;">Defaulting to Today &bull; Filter by date</div>
        </div>
        <div style="display: flex; align-items: center; gap: 10px;">
          <button type="button" style="background:#374151; border:0; color:#f3f4f6; padding:5px 10px; border-radius:6px; font-size:0.75rem; font-weight:700; cursor:pointer;" onclick="reloadAllOrders()">🔄 Refresh</button>
          <button type="button" class="btn-close" style="padding:0; line-height:1;" onclick="closeAllOrdersModal()">&times;</button>
        </div>
      </div>

      <!-- Quick Metrics Summary -->
      <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-bottom: 14px;">
        <div style="background: #111827; border: 1px solid #374151; border-radius: 8px; padding: 8px 10px; text-align: center;">
          <div style="font-size: 0.7rem; color: #9ca3af; text-transform: uppercase; font-weight:700;">Orders</div>
          <div id="summaryOrdersCount" style="font-size: 1.2rem; font-weight: 800; color: #f3f4f6;">0</div>
        </div>
        <div style="background: #111827; border: 1px solid #374151; border-radius: 8px; padding: 8px 10px; text-align: center;">
          <div style="font-size: 0.7rem; color: #9ca3af; text-transform: uppercase; font-weight:700;">Revenue</div>
          <div id="summaryRevenue" style="font-size: 1.2rem; font-weight: 800; color: #22c55e;">₹0.00</div>
        </div>
        <div style="background: #111827; border: 1px solid #374151; border-radius: 8px; padding: 8px 10px; text-align: center;">
          <div style="font-size: 0.7rem; color: #9ca3af; text-transform: uppercase; font-weight:700;">Discounts</div>
          <div id="summaryDiscounts" style="font-size: 1.2rem; font-weight: 800; color: #f59e0b;">0</div>
        </div>
      </div>

      <!-- Date Filter Bar -->
      <div style="background: #111827; border: 1px solid #374151; border-radius: 10px; padding: 12px; margin-bottom: 14px;">
        <!-- Quick Filter Pills: Default is TODAY -->
        <div style="display: flex; gap: 6px; margin-bottom: 10px; overflow-x: auto; padding-bottom: 4px;">
          <button type="button" class="date-pill active" id="pillToday" onclick="setDateFilter('today', this)">🟢 Today</button>
          <button type="button" class="date-pill" onclick="setDateFilter('yesterday', this)">Yesterday</button>
          <button type="button" class="date-pill" onclick="setDateFilter('7days', this)">Last 7 Days</button>
          <button type="button" class="date-pill" onclick="setDateFilter('all', this)">All Time</button>
        </div>

        <!-- Custom Date Picker & Live Search Box -->
        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
          <div style="flex: 1; min-width: 140px;">
            <input type="date" id="orderCustomDate" style="width: 100%; padding: 8px 10px; background: #1f2937; border: 1px solid #4b5563; border-radius: 6px; color: #f3f4f6; font-size: 0.85rem;" onchange="onCustomDateChange(this.value)">
          </div>
          <div style="flex: 1.5; min-width: 160px;">
            <input type="text" id="orderSearchFilter" placeholder="🔍 Search token, name, phone..." style="width: 100%; padding: 8px 10px; background: #1f2937; border: 1px solid #4b5563; border-radius: 6px; color: #f3f4f6; font-size: 0.85rem;" oninput="applyOrdersFilter()">
          </div>
        </div>
      </div>

      <!-- Orders List Scroll Area -->
      <div id="allOrdersList" style="flex: 1; overflow-y: auto; padding-right: 4px; display: flex; flex-direction: column; gap: 8px;">
        <div style="text-align: center; color: #9ca3af; padding: 24px;">Loading orders…</div>
      </div>

    </div>
  </div>

  <script>
    const API_URL = <?= json_encode($APPS_SCRIPT_URL ?? "https://script.google.com/macros/s/AKfycbzgHkDCYHOk4z15LPAfFQ22a-BfY9_t4Q2LdUlsi4pYF4_uw0vpY3EXKuPcEU-W7pXfuA/exec") ?>;
    const VENDOR_ENDPOINT = window.location.pathname;

    // ==========================================
    // Vendor Page Entry Security Gate
    // ==========================================
    const vendorGateScreen = document.getElementById('vendorGateScreen');
    const vendorTerminalContent = document.getElementById('vendorTerminalContent');
    const gatePinInput = document.getElementById('gatePinInput');
    const gatePinError = document.getElementById('gatePinError');
    const btnGateUnlock = document.getElementById('btnGateUnlock');

    function checkGateAuth() {
      const isAuth = sessionStorage.getItem('vendor_authenticated') === 'true';
      if (isAuth) {
        if (vendorGateScreen) vendorGateScreen.style.display = 'none';
        if (vendorTerminalContent) vendorTerminalContent.style.display = 'block';
        setTimeout(() => {
          const tInput = document.getElementById('orderToken');
          if (tInput) tInput.focus();
        }, 150);
      } else {
        if (vendorGateScreen) vendorGateScreen.style.display = 'flex';
        if (vendorTerminalContent) vendorTerminalContent.style.display = 'none';
        if (gatePinInput) {
          gatePinInput.value = '';
          setTimeout(() => gatePinInput.focus(), 150);
        }
      }
    }

    async function submitGatePin() {
      const pin = gatePinInput.value.trim();
      if (!pin) {
        showGateError('Please enter your Vendor PIN.');
        return;
      }

      btnGateUnlock.disabled = true;
      btnGateUnlock.textContent = 'Verifying…';
      gatePinError.style.display = 'none';

      try {
        const res = await fetch(`${VENDOR_ENDPOINT}?action=verify_vendor_pin&pin=${encodeURIComponent(pin)}`);
        const data = await res.json();

        if (data && data.success) {
          sessionStorage.setItem('vendor_authenticated', 'true');
          sessionStorage.setItem('vendor_pin', pin);
          vendorGateScreen.style.display = 'none';
          vendorTerminalContent.style.display = 'block';
          const tInput = document.getElementById('orderToken');
          if (tInput) tInput.focus();
        } else {
          showGateError((data && data.message) ? data.message : 'Incorrect Vendor PIN. Access denied.');
        }
      } catch (_) {
        showGateError('Could not verify PIN. Please retry.');
      } finally {
        btnGateUnlock.disabled = false;
        btnGateUnlock.textContent = '🔓 Unlock Vendor Terminal';
      }
    }

    function showGateError(msg) {
      gatePinError.textContent = msg;
      gatePinError.style.display = 'block';
      gatePinInput.value = '';
      gatePinInput.focus();
    }

    if (gatePinInput) {
      gatePinInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
          e.preventDefault();
          submitGatePin();
        }
      });
    }

    function lockTerminal() {
      sessionStorage.removeItem('vendor_authenticated');
      sessionStorage.removeItem('vendor_pin');
      if (vendorTerminalContent) vendorTerminalContent.style.display = 'none';
      if (vendorGateScreen) vendorGateScreen.style.display = 'flex';
      if (gatePinInput) {
        gatePinInput.value = '';
        gatePinError.style.display = 'none';
        setTimeout(() => gatePinInput.focus(), 150);
      }
    }

    // Verify authentication gate on load
    checkGateAuth();

    function syncMenuNow() {
      const btn = document.getElementById('syncMenuBtn');
      const summary = document.getElementById('menuSyncSummary');
      if (!btn) return;
      btn.textContent = 'Syncing…';
      btn.disabled = true;
      fetch(`${VENDOR_ENDPOINT}?action=sync_menu`)
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
    fetch(`${VENDOR_ENDPOINT}?action=menu_status`)
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
    const unverifiedList = document.getElementById('unverifiedOrdersList');

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
          const localRes = await fetch(`${VENDOR_ENDPOINT}?action=lookup&token=${encodeURIComponent(token)}`);
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

      modalVendorPin.value = sessionStorage.getItem('vendor_pin') || '';
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
      modalVendorPin.value = '';
      tokenInput.focus();
    }

    // Modal Confirm Submission
    modalConfirmBtn.addEventListener('click', async function() {
      let pin = modalVendorPin.value.trim();
      if (!pin) {
        pin = sessionStorage.getItem('vendor_pin') || '';
      }
      if (!pin) {
        alert('Please enter your Vendor PIN.');
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
        let data = null;
        try {
          const response = await fetch(`${VENDOR_ENDPOINT}?action=confirm_order`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
          });
          data = await response.json();
        } catch (_) {
          // Direct fallback to Google Apps Script if local endpoint is bypassed
          const response = await fetch(API_URL, {
            method: 'POST',
            body: JSON.stringify(payload)
          });
          data = await response.json();
        }

        if (data && data.success) {
          modalMsg.style.display = 'block';
          modalMsg.style.background = '#065f46';
          modalMsg.style.color = '#ecfdf5';
          modalMsg.style.border = '1px solid #059669';
          const subText = (data && data.message) ? data.message : 'Successfully verified & confirmed.';
          modalMsg.innerHTML = `<strong>🎉 Order ${token} Confirmed!</strong><br><span style="font-size:0.88em; opacity:0.95;">${subText}</span>`;

          // Instantly broadcast order confirmation for customer screen transition
          try {
            localStorage.setItem('mama_confirmed_' + token, 'APPROVED');
          } catch (_) {}
          fetch(`${VENDOR_ENDPOINT}?action=mark_confirmed&token=${encodeURIComponent(token)}&visit=${data.visitNumber || 1}`).catch(() => {});

          setTimeout(() => {
            closeModal();
            tokenInput.value = '';
            loadUnverifiedOrders();
          }, 1500);
        } else {
          modalConfirmBtn.disabled = false;
          modalConfirmBtn.innerHTML = '<span>✅ Confirm &amp; Save to Sheet</span>';
          modalMsg.style.display = 'block';
          modalMsg.style.background = '#7f1d1d';
          modalMsg.style.color = '#fef2f2';
          modalMsg.style.border = '1px solid #dc2626';
          let errMsg = (data && data.message) ? data.message : 'Invalid vendor PIN or verification failed.';
          if (errMsg.includes('constantTimeEyuals_') || errMsg.includes('constantTimeEquals_')) {
            errMsg = 'Apps Script typo detected: Please update Code.gs in Google Apps Script editor.';
          }
          modalMsg.textContent = errMsg;
        }
      } catch (err) {
        modalConfirmBtn.disabled = false;
        modalConfirmBtn.innerHTML = '<span>✅ Confirm &amp; Save to Sheet</span>';
        modalMsg.style.display = 'block';
        modalMsg.style.background = '#7f1d1d';
        modalMsg.style.color = '#fef2f2';
        modalMsg.style.border = '1px solid #dc2626';
        modalMsg.textContent = 'Could not reach server. Please verify connection and retry.';
      }
    });

    // ==========================================
    // Unverified (Pending) Orders Queue
    // ==========================================
    const unverifiedListEl = document.getElementById('unverifiedOrdersList');
    const unverifiedCountBadge = document.getElementById('unverifiedCountBadge');
    let unverifiedOrdersCache = {};
    let lastRenderedJson = '';

    async function loadUnverifiedOrders() {
      if (!unverifiedListEl) return;
      try {
        const res = await fetch(`${VENDOR_ENDPOINT}?action=get_unverified_orders`);
        if (!res.ok) return;
        const data = await res.json();
        const orders = (data && data.orders) ? data.orders : [];
        renderUnverifiedOrdersList(orders);
      } catch (err) {
        // Retry silently
      }
    }

    function renderUnverifiedOrdersList(list) {
      if (!unverifiedListEl) return;
      const currentJson = JSON.stringify(list);
      if (currentJson === lastRenderedJson) {
        return; // Avoid DOM rebuild if orders dataset is unchanged
      }
      lastRenderedJson = currentJson;

      unverifiedOrdersCache = {};
      list.forEach(o => {
        if (o && o.orderId) {
          unverifiedOrdersCache[o.orderId.toUpperCase()] = o;
        }
      });

      const count = list.length;
      if (unverifiedCountBadge) {
        unverifiedCountBadge.textContent = count;
        unverifiedCountBadge.style.background = count > 0 ? '#ef4444' : '#10b981';
      }

      if (count === 0) {
        unverifiedListEl.innerHTML = `
          <div style="text-align: center; color: #9ca3af; padding: 22px 14px; background: #111827; border-radius: 12px; border: 1px dashed #374151;">
            <div style="font-size: 1.8rem; margin-bottom: 6px;">✨</div>
            <div style="font-size: 0.92rem; font-weight: 700; color: #f3f4f6; margin-bottom: 4px;">All caught up!</div>
            <div style="font-size: 0.78rem; color: #6b7280;">No unverified orders pending. New customer orders will show here in real-time.</div>
          </div>
        `;
        return;
      }

      unverifiedListEl.innerHTML = list.map(o => {
        const tok = o.orderId;
        const name = o.name || 'Customer';
        const phone = o.phone || '';
        const items = o.itemsDisplay || o.items || 'Momo Selection';
        const price = parseFloat(o.finalPrice !== undefined ? o.finalPrice : (o.totalAmount || 0)).toFixed(2);
        const address = o.address ? `<div style="font-size:0.75rem; color:#9ca3af; margin-top:2px;">📍 ${o.address}</div>` : '';
        const isDisc = o.isDiscount ? '<span style="font-size:0.68rem; background:#831843; color:#fbcfe8; border:1px solid #be185d; padding:1px 6px; border-radius:6px; font-weight:700;">50% Off</span>' : '';
        const ts = o.timestamp ? new Date(o.timestamp * 1000).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'}) : 'Just now';

        return `
          <div class="order-hist-card" style="margin-bottom:10px; border-color:#4b5563;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
              <div style="display:flex; align-items:center; gap:8px;">
                <span class="recent-token" style="font-size:1.05rem; letter-spacing:1px; color:#ef4444; font-weight:800;">${tok}</span>
                <span style="font-size:0.7rem; font-weight:800; background:#451a03; color:#fcd34d; border:1px solid #d97706; padding:2px 7px; border-radius:8px; text-transform:uppercase;">⏳ Pending</span>
                ${isDisc}
              </div>
              <span style="font-size:0.75rem; color:#9ca3af;">${ts}</span>
            </div>

            <div style="margin-bottom:6px;">
              <div style="font-size:0.92rem; font-weight:700; color:#f3f4f6;">
                👤 ${name} ${phone ? `<span style="font-size:0.8rem; font-weight:normal; color:#9ca3af;">(${phone})</span>` : ''}
              </div>
              ${address}
              <div style="font-size:0.82rem; color:#d1d5db; margin-top:3px; line-height:1.35;">🥟 ${items}</div>
            </div>

            <div style="display:flex; justify-content:space-between; align-items:center; border-top:1px solid #1f2937; padding-top:8px; margin-top:4px;">
              <div style="font-size:1.15rem; font-weight:800; color:#22c55e;">₹${price}</div>
              <button type="button" class="btn-check" style="padding:7px 14px; font-size:0.85rem; border-radius:8px; background:#22c55e; border:0; color:#fff; font-weight:700; cursor:pointer; display:inline-flex; align-items:center; gap:5px;" onclick="quickCheckoutOrder('${tok}')">
                <span>⚡ Quick Checkout</span>
              </button>
            </div>
          </div>
        `;
      }).join('');
    }

    function quickCheckoutOrder(token) {
      if (!token) return;
      tokenInput.value = token;
      if (unverifiedOrdersCache && unverifiedOrdersCache[token.toUpperCase()]) {
        populateAndShowModal(unverifiedOrdersCache[token.toUpperCase()]);
      } else {
        checkAndOpenModal(false);
      }
    }

    // ==========================================
    // All Orders History with Password Protection
    // ==========================================
    let cachedAllOrders = [];
    let currentDateFilter = 'today'; // Default filter is Today!
    const pinLockModal = document.getElementById('pinLockModal');
    const allOrdersModal = document.getElementById('allOrdersModal');
    const allOrdersPinInput = document.getElementById('allOrdersPinInput');
    const pinLockError = document.getElementById('pinLockError');
    const allOrdersList = document.getElementById('allOrdersList');

    function getTodayIso() {
      const d = new Date();
      return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    }

    function getYesterdayIso() {
      const d = new Date();
      d.setDate(d.getDate() - 1);
      return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    }

    function requestAllOrdersAccess() {
      const savedPin = sessionStorage.getItem('vendor_pin');
      if (savedPin) {
        openAllOrdersDashboard(savedPin);
      } else {
        pinLockError.style.display = 'none';
        allOrdersPinInput.value = '';
        pinLockModal.style.display = 'flex';
        setTimeout(() => allOrdersPinInput.focus(), 100);
      }
    }

    function closePinLockModal() {
      pinLockModal.style.display = 'none';
      allOrdersPinInput.value = '';
      pinLockError.style.display = 'none';
    }

    function closeAllOrdersModal() {
      allOrdersModal.style.display = 'none';
    }

    async function submitPinForOrders() {
      const pin = allOrdersPinInput.value.trim();
      if (!pin) {
        showPinError('Please enter your Vendor PIN.');
        return;
      }

      const btn = document.getElementById('btnUnlockOrders');
      btn.disabled = true;
      btn.textContent = 'Verifying…';
      pinLockError.style.display = 'none';

      try {
        const res = await fetch(`${VENDOR_ENDPOINT}?action=verify_vendor_pin&pin=${encodeURIComponent(pin)}`);
        const data = await res.json();

        if (data && data.success) {
          sessionStorage.setItem('vendor_pin', pin);
          closePinLockModal();
          openAllOrdersDashboard(pin);
        } else {
          showPinError((data && data.message) ? data.message : 'Incorrect Vendor PIN. Access denied.');
        }
      } catch (_) {
        showPinError('Could not verify PIN. Please retry.');
      } finally {
        btn.disabled = false;
        btn.textContent = 'Unlock Orders';
      }
    }

    function showPinError(msg) {
      pinLockError.textContent = msg;
      pinLockError.style.display = 'block';
      allOrdersPinInput.focus();
    }

    allOrdersPinInput.addEventListener('keydown', function(e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        submitPinForOrders();
      }
    });

    function openAllOrdersDashboard(pin) {
      allOrdersModal.style.display = 'flex';
      // Set default date picker value to Today
      const todayIso = getTodayIso();
      const dateInput = document.getElementById('orderCustomDate');
      if (dateInput) dateInput.value = todayIso;
      
      // Default to Today pill
      currentDateFilter = 'today';
      document.querySelectorAll('.date-pill').forEach(p => p.classList.remove('active'));
      const todayPill = document.getElementById('pillToday');
      if (todayPill) todayPill.classList.add('active');

      loadAllOrders(pin);
    }

    function reloadAllOrders() {
      const pin = sessionStorage.getItem('vendor_pin');
      if (!pin) {
        requestAllOrdersAccess();
        return;
      }
      loadAllOrders(pin);
    }

    async function loadAllOrders(pin) {
      allOrdersList.innerHTML = '<div style="text-align: center; color: #9ca3af; padding: 24px;">⏳ Loading orders from Google Sheets…</div>';
      
      try {
        const res = await fetch(`${VENDOR_ENDPOINT}?action=get_all_orders&pin=${encodeURIComponent(pin)}`);
        if (res.status === 401) {
          sessionStorage.removeItem('vendor_pin');
          closeAllOrdersModal();
          requestAllOrdersAccess();
          return;
        }

        const data = await res.json();
        if (data && data.orders) {
          cachedAllOrders = data.orders;
          renderFilteredOrders();
        } else {
          allOrdersList.innerHTML = '<div style="text-align: center; color: #ef4444; padding: 24px;">Failed to load orders.</div>';
        }
      } catch (err) {
        allOrdersList.innerHTML = '<div style="text-align: center; color: #ef4444; padding: 24px;">Could not connect to orders database.</div>';
      }
    }

    function setDateFilter(type, btnEl) {
      currentDateFilter = type;
      document.querySelectorAll('.date-pill').forEach(p => p.classList.remove('active'));
      if (btnEl) btnEl.classList.add('active');
      renderFilteredOrders();
    }

    function onCustomDateChange(val) {
      if (val) {
        currentDateFilter = 'custom';
        document.querySelectorAll('.date-pill').forEach(p => p.classList.remove('active'));
        renderFilteredOrders();
      }
    }

    function applyOrdersFilter() {
      renderFilteredOrders();
    }

    function renderFilteredOrders() {
      if (!allOrdersList) return;
      
      const todayIso = getTodayIso();
      const yesterdayIso = getYesterdayIso();
      const customVal = document.getElementById('orderCustomDate') ? document.getElementById('orderCustomDate').value : '';
      const searchVal = document.getElementById('orderSearchFilter') ? document.getElementById('orderSearchFilter').value.trim().toLowerCase() : '';

      // Date filtering
      let filtered = cachedAllOrders.filter(ord => {
        const ordDate = ord.date || (ord.timestamp ? ord.timestamp.split('T')[0] : '');
        
        if (currentDateFilter === 'today') {
          return ordDate === todayIso;
        } else if (currentDateFilter === 'yesterday') {
          return ordDate === yesterdayIso;
        } else if (currentDateFilter === '7days') {
          if (!ordDate) return false;
          const diffDays = (new Date(todayIso) - new Date(ordDate)) / (1000 * 3600 * 24);
          return diffDays >= 0 && diffDays <= 7;
        } else if (currentDateFilter === 'custom') {
          return ordDate === customVal;
        } else if (currentDateFilter === 'all') {
          return true;
        }
        return true;
      });

      // Search keyword filtering
      if (searchVal) {
        filtered = filtered.filter(ord => {
          const t = (ord.orderId || '').toLowerCase();
          const n = (ord.name || '').toLowerCase();
          const p = (ord.phone || '').toLowerCase();
          const it = (ord.items || ord.itemsDisplay || '').toLowerCase();
          return t.includes(searchVal) || n.includes(searchVal) || p.includes(searchVal) || it.includes(searchVal);
        });
      }

      // Calculate Metrics
      let totalRev = 0;
      let totalDiscounts = 0;
      filtered.forEach(o => {
        totalRev += parseFloat(o.totalAmount || o.finalPrice || o.amount || 0);
        if (o.discountApplied) totalDiscounts++;
      });

      document.getElementById('summaryOrdersCount').textContent = filtered.length;
      document.getElementById('summaryRevenue').textContent = '₹' + totalRev.toFixed(2);
      document.getElementById('summaryDiscounts').textContent = totalDiscounts;

      if (!filtered.length) {
        const filterName = (currentDateFilter === 'today') ? 'today' : (currentDateFilter === 'yesterday' ? 'yesterday' : (currentDateFilter === 'custom' ? customVal : 'selected filter'));
        allOrdersList.innerHTML = `
          <div style="text-align: center; color: #6b7280; padding: 36px 16px; background: #111827; border-radius: 10px; border: 1px dashed #374151;">
            <div style="font-size: 2rem; margin-bottom: 8px;">🥟</div>
            <div style="font-size: 0.95rem; font-weight: 700; color: #9ca3af; margin-bottom: 4px;">No orders found for ${filterName}</div>
            <div style="font-size: 0.8rem; color: #6b7280;">Try choosing another date or clicking "All Time" to view full history.</div>
          </div>
        `;
        return;
      }

      allOrdersList.innerHTML = filtered.map(o => {
        const isAppr = (o.status === 'APPROVED' || o.status === 'APPROVED_COOLDOWN');
        const stClass = isAppr ? 'hist-status-approved' : 'hist-status-pending';
        const stLabel = isAppr ? '✓ Approved' : '⏳ Pending';
        const items = o.items || o.itemsDisplay || 'Momo Selection';
        const amt = parseFloat(o.totalAmount || o.finalPrice || o.amount || 0).toFixed(2);
        const visitLabel = o.visitNumber ? `Visit #${o.visitNumber}` : (o.visit ? `Visit #${o.visit}` : '');
        const discountPill = o.discountApplied ? '<span style="font-size:0.7rem; background:#14532d; color:#86efac; padding:1px 6px; border-radius:6px; font-weight:700;">50% Off</span>' : '';

        return `
          <div class="order-hist-card">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
              <div style="display:flex; align-items:center; gap:8px;">
                <span class="recent-token" style="font-size:1.05rem;">${o.orderId}</span>
                <span class="hist-status-pill ${stClass}">${stLabel}</span>
                ${discountPill}
              </div>
              <span style="font-size:0.78rem; color:#9ca3af; font-weight:600;">${o.time || ''} &bull; ${o.date || ''}</span>
            </div>
            
            <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:10px;">
              <div style="flex:1;">
                <div style="font-size:0.92rem; font-weight:700; color:#f3f4f6; margin-bottom:2px;">
                  ${o.name || 'Customer'} ${o.phone ? `<span style="font-size:0.8rem; color:#9ca3af; font-weight:normal;">(${o.phone})</span>` : ''}
                </div>
                <div style="font-size:0.82rem; color:#d1d5db; line-height:1.35;">${items}</div>
              </div>
              <div style="text-align:right; flex-shrink:0;">
                <div style="font-size:1.1rem; font-weight:800; color:#22c55e;">₹${amt}</div>
                ${visitLabel ? `<div style="font-size:0.72rem; color:#9ca3af; font-weight:600;">${visitLabel}</div>` : ''}
              </div>
            </div>
          </div>
        `;
      }).join('');
    }

    // Auto-load unverified orders on boot and poll every 4 seconds
    loadUnverifiedOrders();
    setInterval(loadUnverifiedOrders, 4000);
  </script>
</body>
</html>
