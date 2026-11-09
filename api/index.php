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
$homeUrl = ($basePath ?: '') . '/';

// Handle Logout
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    clearCustomerSession();
    header('Location: ' . ($homeUrl ?: '/'), true, 303);
    exit;
}

// AJAX: Check phone number
if (isset($_GET['action']) && $_GET['action'] === 'check_phone') {
    header('Content-Type: application/json; charset=utf-8');
    $phone = normalizePhoneNumber($_GET['phone'] ?? '');
    if (!$phone) {
        echo json_encode(['success' => false, 'message' => 'Please enter a valid 10-digit mobile number.']);
        exit;
    }

    $profile = findCustomerProfile($phone);
    if ($profile) {
        setCustomerSessionCookie($profile);
        echo json_encode([
            'success' => true,
            'exists' => true,
            'profile' => $profile
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'exists' => false,
            'phone' => $phone
        ]);
    }
    exit;
}

// AJAX / POST: Register new customer
if (isset($_POST['action']) && $_POST['action'] === 'register') {
    header('Content-Type: application/json; charset=utf-8');
    $name = trim((string)($_POST['name'] ?? ''));
    $phone = normalizePhoneNumber($_POST['phone'] ?? '');
    $address = trim((string)($_POST['address'] ?? ''));

    if ($name === '' || strlen($name) > 80 || !$phone) {
        echo json_encode(['success' => false, 'message' => 'Please enter a valid name and 10-digit mobile number.']);
        exit;
    }

    $profile = saveCustomerToCache($phone, $name, $address, [
        'currentVisits' => 0,
        'remainingVisits' => 5
    ]);
    setCustomerSessionCookie($profile);

    echo json_encode([
        'success' => true,
        'message' => 'Account created successfully!',
        'profile' => $profile
    ]);
    exit;
}

// AJAX / POST: Login customer by phone
if (isset($_POST['action']) && $_POST['action'] === 'login') {
    header('Content-Type: application/json; charset=utf-8');
    $phone = normalizePhoneNumber($_POST['phone'] ?? '');
    if (!$phone) {
        echo json_encode(['success' => false, 'message' => 'Please enter a valid 10-digit mobile number.']);
        exit;
    }

    $profile = findCustomerProfile($phone);
    if ($profile) {
        setCustomerSessionCookie($profile);
        echo json_encode(['success' => true, 'profile' => $profile]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Customer profile not found. Please register.']);
    }
    exit;
}

// Check if customer is currently logged in (supports stateless signed cookie for Vercel)
$customer = getAuthenticatedCustomer();
$customerOrders = [];
if ($customer && !empty($customer['phone'])) {
    $customerOrders = getOrdersByCustomerPhone($customer['phone']);
    // Dynamically calculate and sync customer visits from approved orders
    $approvedVisits = count(array_filter($customerOrders, function($o) {
        $st = strtoupper($o['status'] ?? '');
        return $st === 'APPROVED' || $st === 'APPROVED_COOLDOWN' || $st === 'CONFIRMED';
    }));
    if ($approvedVisits > (int)($customer['currentVisits'] ?? 0)) {
        $customer['currentVisits'] = $approvedVisits;
        setCustomerSessionCookie($customer);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>The Momo Point / Mama Momo</title>
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
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
    }
    body {
      background: #fff5f2;
      display: flex;
      align-items: center;
      justify-content: center;
      min-height: 100vh;
      padding: 16px;
      color: #222;
    }
    .hero-card {
      background: #ffffff;
      max-width: 440px;
      width: 100%;
      padding: 30px 22px;
      border-radius: 20px;
      box-shadow: 0 12px 30px rgba(217, 56, 30, 0.12);
      border: 1px solid #ffe3de;
    }
    .emoji-logo {
      font-size: 3.8rem;
      margin-bottom: 8px;
      display: inline-block;
      animation: bounce 2s infinite ease-in-out;
      text-align: center;
      width: 100%;
    }
    @keyframes bounce {
      0%, 100% { transform: translateY(0); }
      50% { transform: translateY(-6px); }
    }
    h1 {
      color: #d9381e;
      font-size: 1.75rem;
      font-weight: 800;
      margin-bottom: 6px;
      line-height: 1.2;
      text-align: center;
    }
    .tagline {
      color: #666;
      font-size: 0.92rem;
      line-height: 1.4;
      margin-bottom: 18px;
      text-align: center;
    }
    .promo-banner {
      background: #ffebe8;
      border: 2px dashed #d9381e;
      color: #d9381e;
      padding: 12px 14px;
      border-radius: 12px;
      font-weight: 700;
      font-size: 0.9rem;
      display: block;
      margin-bottom: 20px;
      text-align: center;
    }
    .label {
      font-size: 0.85rem;
      font-weight: 700;
      color: #444;
      display: block;
      margin-bottom: 6px;
    }
    .input-group {
      margin-bottom: 14px;
      text-align: left;
    }
    input[type="text"], input[type="tel"] {
      width: 100%;
      padding: 13px 14px;
      border: 1.5px solid #d1d5db;
      border-radius: 10px;
      font-size: 1rem;
      outline: none;
      transition: border-color 0.2s, box-shadow 0.2s;
    }
    input[type="text"]:focus, input[type="tel"]:focus {
      border-color: #d9381e;
      box-shadow: 0 0 0 3px rgba(217, 56, 30, 0.15);
    }
    .helper-text {
      font-size: 0.78rem;
      color: #777;
      margin-top: 5px;
      line-height: 1.3;
    }
    .btn {
      display: block;
      width: 100%;
      background: #d9381e;
      color: #ffffff;
      text-decoration: none;
      padding: 14px;
      border-radius: 12px;
      font-weight: bold;
      font-size: 1.05rem;
      border: 0;
      cursor: pointer;
      box-shadow: 0 6px 16px rgba(217, 56, 30, 0.32);
      transition: transform 0.12s ease, background-color 0.15s ease;
      text-align: center;
    }
    .btn:active {
      transform: scale(0.98);
      background: #b52912;
    }
    .btn:disabled {
      opacity: 0.65;
      cursor: wait;
    }
    .btn-secondary {
      background: #f3f4f6;
      color: #4b5563;
      box-shadow: none;
      border: 1px solid #d1d5db;
      margin-top: 10px;
      padding: 11px;
      font-size: 0.9rem;
    }
    .btn-secondary:active {
      background: #e5e7eb;
    }

    /* Profile Card Styling */
    .profile-box {
      background: #fafaf9;
      border-radius: 14px;
      padding: 16px;
      border: 1px solid #e7e5e4;
      margin-bottom: 18px;
      text-align: left;
    }
    .profile-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 12px;
      border-bottom: 1px solid #e5e5e5;
      padding-bottom: 10px;
    }
    .profile-name {
      font-size: 1.25rem;
      font-weight: 800;
      color: #1f2937;
    }
    .profile-phone {
      font-size: 0.88rem;
      color: #6b7280;
      font-weight: 600;
    }
    .profile-row {
      display: flex;
      justify-content: space-between;
      font-size: 0.88rem;
      margin-bottom: 6px;
    }
    .profile-row:last-child {
      margin-bottom: 0;
    }
    .profile-label {
      color: #6b7280;
    }
    .profile-val {
      font-weight: 700;
      color: #1f2937;
      text-align: right;
    }
    .loyalty-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: #dcfce7;
      color: #15803d;
      padding: 4px 10px;
      border-radius: 12px;
      font-size: 0.8rem;
      font-weight: 800;
    }

    /* Previous Orders Section */
    .orders-section {
      margin-top: 20px;
      text-align: left;
    }
    .orders-title {
      font-size: 0.95rem;
      font-weight: 800;
      color: #374151;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      margin-bottom: 10px;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    .order-card {
      background: #ffffff;
      border: 1px solid #e5e7eb;
      border-radius: 10px;
      padding: 12px 14px;
      margin-bottom: 10px;
      box-shadow: 0 2px 6px rgba(0, 0, 0, 0.03);
    }
    .order-top {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 6px;
    }
    .order-token {
      font-weight: 800;
      color: #d9381e;
      font-size: 0.95rem;
      letter-spacing: 0.5px;
    }
    .order-status {
      font-size: 0.72rem;
      font-weight: 800;
      padding: 2px 8px;
      border-radius: 10px;
      text-transform: uppercase;
    }
    .status-approved {
      background: #dcfce7;
      color: #15803d;
    }
    .status-pending {
      background: #fef3c7;
      color: #b45309;
    }
    .order-items {
      font-size: 0.85rem;
      color: #4b5563;
      margin-bottom: 6px;
      line-height: 1.35;
    }
    .order-bottom {
      display: flex;
      justify-content: space-between;
      align-items: center;
      font-size: 0.82rem;
      color: #9ca3af;
    }
    .order-amount {
      font-weight: 800;
      color: #15803d;
      font-size: 0.95rem;
    }
    .empty-orders {
      text-align: center;
      padding: 18px 12px;
      color: #9ca3af;
      font-size: 0.85rem;
      background: #f9fafb;
      border-radius: 10px;
      border: 1px dashed #d1d5db;
    }
    .error-banner {
      background: #fee2e2;
      color: #991b1b;
      padding: 10px 12px;
      border-radius: 8px;
      font-size: 0.85rem;
      margin-bottom: 14px;
      display: none;
      font-weight: 600;
      text-align: left;
    }

    /* Brand Logo */
    .brand-logo-container {
      text-align: center;
      margin-bottom: 12px;
    }
    .brand-logo {
      width: 104px;
      height: 104px;
      border-radius: 50%;
      object-fit: cover;
      box-shadow: 0 8px 24px rgba(217, 56, 30, 0.25);
      border: 3.5px solid #ff7a59;
      display: inline-block;
      animation: floatLogo 3.2s infinite ease-in-out;
    }
    @keyframes floatLogo {
      0%, 100% { transform: translateY(0); }
      50% { transform: translateY(-5px); }
    }

    /* Intro Video Screen Overlay */
    .intro-video-overlay {
      position: fixed;
      inset: 0;
      background: #090a0f;
      z-index: 99999;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      transition: opacity 0.35s ease, visibility 0.35s ease;
      opacity: 1;
      visibility: visible;
      padding: 14px;
    }
    .intro-video-overlay.hidden {
      opacity: 0;
      visibility: hidden;
      pointer-events: none;
    }
    .intro-modal {
      width: 100%;
      max-width: 440px;
      background: #111827;
      border-radius: 20px;
      overflow: hidden;
      box-shadow: 0 25px 60px rgba(0, 0, 0, 0.7);
      border: 1px solid #374151;
      display: flex;
      flex-direction: column;
      position: relative;
    }
    .intro-header {
      padding: 12px 16px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      background: #1f2937;
      border-bottom: 1px solid #374151;
    }
    .intro-brand-row {
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .intro-mini-logo {
      width: 34px;
      height: 34px;
      border-radius: 50%;
      object-fit: cover;
      border: 2px solid #ef4444;
    }
    .intro-title {
      font-size: 0.95rem;
      font-weight: 800;
      color: #f3f4f6;
    }
    .btn-skip-top {
      background: #ef4444;
      color: #ffffff;
      border: none;
      padding: 7px 14px;
      border-radius: 20px;
      font-size: 0.8rem;
      font-weight: 800;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 5px;
      transition: background 0.15s, transform 0.1s;
      box-shadow: 0 2px 8px rgba(239, 68, 68, 0.4);
    }
    .btn-skip-top:hover {
      background: #dc2626;
      transform: scale(1.02);
    }
    .intro-video-box {
      position: relative;
      background: #000;
      width: 100%;
      line-height: 0;
      aspect-ratio: 9 / 16;
      max-height: 520px;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .intro-video-element {
      width: 100%;
      height: 100%;
      object-fit: contain;
      background: #000;
    }
    .intro-video-controls {
      position: absolute;
      bottom: 12px;
      left: 12px;
      right: 12px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      pointer-events: none;
    }
    .btn-video-ctrl {
      pointer-events: auto;
      background: rgba(17, 24, 39, 0.82);
      color: #ffffff;
      border: 1px solid rgba(255, 255, 255, 0.25);
      border-radius: 20px;
      padding: 6px 12px;
      font-size: 0.78rem;
      font-weight: 700;
      cursor: pointer;
      backdrop-filter: blur(6px);
      display: inline-flex;
      align-items: center;
      gap: 6px;
      transition: background 0.15s;
    }
    .btn-video-ctrl:hover {
      background: rgba(17, 24, 39, 0.95);
    }
    .intro-footer {
      padding: 14px 16px;
      background: #111827;
      text-align: center;
    }
    .btn-skip-main {
      width: 100%;
      background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
      color: #ffffff;
      border: none;
      padding: 13px;
      border-radius: 12px;
      font-size: 1rem;
      font-weight: 800;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      box-shadow: 0 4px 16px rgba(239, 68, 68, 0.4);
      transition: transform 0.12s, box-shadow 0.12s;
    }
    .btn-skip-main:hover {
      transform: translateY(-1px);
      box-shadow: 0 6px 20px rgba(239, 68, 68, 0.5);
    }
    .btn-skip-main:active {
      transform: scale(0.98);
    }

    /* Watch Intro Button on Home */
    .btn-watch-intro {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
      background: transparent;
      border: 1px solid #fca5a5;
      color: #dc2626;
      padding: 8px 16px;
      border-radius: 20px;
      font-size: 0.82rem;
      font-weight: 700;
      cursor: pointer;
      margin-top: 14px;
      transition: background 0.15s, border-color 0.15s;
    }
    .btn-watch-intro:hover {
      background: #fee2e2;
      border-color: #ef4444;
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
  <!-- Momo Animated Loader Overlay (Used during async actions) -->
  <div id="momoLoaderOverlay" class="momo-loader-overlay" style="display: none;">
    <div class="momo-loader-card">
      <div class="momo-loader-mascot-wrap">
        <img src="<?= ($basePath ?: '') ?>/assets/loader-momo.png" alt="Loading Mama Momo" class="momo-loader-mascot" />
        <div class="momo-loader-ring"></div>
      </div>
      <div class="momo-loader-title">Mama Momo</div>
      <div class="momo-loader-text" id="momoLoaderText">Steaming up delicious moments…</div>
      <div class="momo-loader-dots">
        <span></span><span></span><span></span>
      </div>
    </div>
  </div>

  <!-- Intro Video Screen Overlay -->
  <div id="introVideoOverlay" class="intro-video-overlay" style="display: none;">
    <div class="intro-modal">
      <div class="intro-header">
        <div class="intro-brand-row">
          <img src="<?= ($basePath ?: '') ?>/assets/mama-momo-logo.jpg" alt="Mama Momo" class="intro-mini-logo" />
          <span class="intro-title">Mama Momo</span>
        </div>
        <button type="button" class="btn-skip-top" onclick="dismissIntroVideo()">
          Skip &amp; Use App ➔
        </button>
      </div>

      <div class="intro-video-box">
        <video id="introVideoPlayer" class="intro-video-element" playsinline autoplay muted preload="auto">
          <source src="<?= ($basePath ?: '') ?>/assets/mama-momo-intro.mp4" type="video/mp4">
          Your browser does not support the video tag.
        </video>
        <div class="intro-video-controls">
          <button type="button" class="btn-video-ctrl" id="btnSoundToggle" onclick="toggleIntroSound()">
            <span id="soundIcon">🔇</span> <span id="soundLabel">Sound Off</span>
          </button>
          <button type="button" class="btn-video-ctrl" id="btnPlayToggle" onclick="toggleIntroPlay()">
            <span id="playIcon">⏸️</span>
          </button>
        </div>
      </div>

      <div class="intro-footer">
        <button type="button" class="btn-skip-main" onclick="dismissIntroVideo()">
          🥟 Skip &amp; Use the App ➔
        </button>
      </div>
    </div>
  </div>

  <div class="hero-card">
    <div class="brand-logo-container">
      <img src="<?= ($basePath ?: '') ?>/assets/mama-momo-logo.jpg" alt="Mama Momo Logo" class="brand-logo" />
    </div>
    <h1>Mama Momo</h1> 
    <p class="tagline">Hot &amp; authentic Himalayan street-style momos freshly steamed &amp; fried daily.</p>
    
    <div class="promo-banner">
      🎁 5th Visit = Flat 50% Off Your Order
    </div>

    <div id="errorBanner" class="error-banner"></div>

    <?php if (isset($_GET['login_required']) && !$customer): ?>
      <div style="background:#fffbeb; border:1px solid #fde68a; color:#92400e; padding:10px 14px; border-radius:10px; font-size:0.85rem; font-weight:600; margin-bottom:14px; text-align:center;">
        🔒 Please enter your mobile number to view the menu and place your order.
      </div>
    <?php endif; ?>

    <?php if ($customer): ?>
      <!-- SCREEN: Logged In Customer Profile -->
      <div id="profileView">
        <div class="profile-box">
          <div class="profile-header">
            <div>
              <div class="profile-name">👋 <?= htmlspecialchars($customer['name'] ?? 'Customer', ENT_QUOTES, 'UTF-8') ?></div>
              <div class="profile-phone">📱 <?= htmlspecialchars($customer['phone'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <div class="loyalty-badge">
              ⭐ <?= (int)($customer['currentVisits'] ?? 0) ?> Visits
            </div>
          </div>
          <?php if (!empty($customer['address'])): ?>
            <div class="profile-row">
              <span class="profile-label">📍 Delivery Address:</span>
              <span class="profile-val"><?= htmlspecialchars($customer['address'], ENT_QUOTES, 'UTF-8') ?></span>
            </div>
          <?php endif; ?>
          <div class="profile-row" style="margin-top:6px;">
            <span class="profile-label">Next Reward:</span>
            <span class="profile-val" style="color:#d9381e;">
              <?php
                $cur = (int)($customer['currentVisits'] ?? 0);
                $rem = 5 - ($cur % 5);
                echo ($rem === 5 && $cur > 0) ? '50% Off Unlocked on Next Visit!' : ($rem . ' visits to 50% Off');
              ?>
            </span>
          </div>
        </div>

        <!-- Prominent Place New Order Button -->
        <a href="<?= htmlspecialchars($menuUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn" id="btnPlaceOrder">
          🍽️ Place New Order
        </a>

        <!-- Switch / Logout Button -->
        <a href="?action=logout" class="btn btn-secondary">
          Switch Mobile Number / Log Out
        </a>

        <!-- Previous Orders List -->
        <div class="orders-section">
          <div class="orders-title">
            <span>📜 Your Previous Orders</span>
            <span style="font-size:0.8rem; color:#6b7280; font-weight:normal;"><?= count($customerOrders) ?> orders</span>
          </div>

          <?php if (empty($customerOrders)): ?>
            <div class="empty-orders">
              You haven't placed any orders yet.<br>Click <strong>Place New Order</strong> to enjoy delicious momos!
            </div>
          <?php else: ?>
            <?php foreach ($customerOrders as $ord): ?>
              <?php
                $st = strtoupper($ord['status'] ?? 'PENDING');
                $isApproved = ($st === 'APPROVED' || $st === 'APPROVED_COOLDOWN');
                $ts = $ord['timestamp'] ?? time();
                $dateStr = date('M j, Y • h:i A', $ts);
                $amt = (float)($ord['finalPrice'] ?? ($ord['totalAmount'] ?? 0));
              ?>
              <div class="order-card">
                <div class="order-top">
                  <span class="order-token">Token: <?= htmlspecialchars($ord['orderId'] ?? 'MMxxxx', ENT_QUOTES, 'UTF-8') ?></span>
                  <span class="order-status <?= $isApproved ? 'status-approved' : 'status-pending' ?>">
                    <?= $isApproved ? '✓ Confirmed' : '⏳ Waiting Stall Verification' ?>
                  </span>
                </div>
                <div class="order-items">
                  <?= htmlspecialchars($ord['itemsDisplay'] ?? ($ord['items'] ?? 'Momo Selection'), ENT_QUOTES, 'UTF-8') ?>
                </div>
                <div class="order-bottom">
                  <span><?= htmlspecialchars($dateStr, ENT_QUOTES, 'UTF-8') ?></span>
                  <span class="order-amount">₹<?= number_format($amt, 2) ?></span>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

    <?php else: ?>

      <!-- SCREEN 1: Phone Login View -->
      <div id="loginStep">
        <form id="phoneLoginForm" onsubmit="handlePhoneSubmit(event)">
          <div class="input-group">
            <label class="label" for="loginPhone">Enter Mobile Number to Order</label>
            <input type="tel" id="loginPhone" maxlength="10" pattern="[0-9]{10}" inputmode="numeric" placeholder="e.g. 9876543210" required autocomplete="tel" autofocus>
            <div class="helper-text">Enter your 10-digit number. If you are new, we will help you create an account in 5 seconds!</div>
          </div>
          <button type="submit" class="btn" id="btnLoginSubmit">Continue ➔</button>
        </form>
      </div>

      <!-- SCREEN 2: Quick Registration View (shown if phone not found) -->
      <div id="registerStep" style="display: none;">
        <div style="font-size:0.92rem; font-weight:700; color:#d9381e; margin-bottom:12px; text-align:left;">
          ✨ New Customer Registration
        </div>
        <form id="registerForm" onsubmit="handleRegisterSubmit(event)">
          <div class="input-group">
            <label class="label" for="regName">Your Full Name <span style="color:#d9381e;">*</span></label>
            <input type="text" id="regName" maxlength="80" placeholder="e.g. Rahul Sharma" required autocomplete="name">
          </div>

          <div class="input-group">
            <label class="label" for="regPhone">Mobile Number</label>
            <input type="tel" id="regPhone" maxlength="10" readonly style="background:#f3f4f6; color:#555;" required>
          </div>

          <div class="input-group">
            <label class="label" for="regAddress">Delivery Address <span style="font-size:0.8rem; color:#888; font-weight:normal;">(Optional for delivery only)</span></label>
            <input type="text" id="regAddress" maxlength="160" placeholder="House/Flat, Street or Stall Table" autocomplete="street-address">
            <div class="helper-text">Optional: Required only if you want momos delivered to your spot.</div>
          </div>

          <button type="submit" class="btn" id="btnRegisterSubmit">Create Account &amp; Continue ➔</button>
          <button type="button" class="btn btn-secondary" onclick="backToPhoneLogin()">← Use a Different Number</button>
        </form>
      </div>

    <?php endif; ?>

    <div style="text-align: center; margin-top: 14px;">
      <button type="button" class="btn-watch-intro" onclick="openIntroVideo(true)">
        🎬 Watch Intro Video
      </button>
    </div>

    <div style="margin-top: 18px; font-size: 0.78rem; color: #999; text-align:center;">
      Loyalty rewards tracked automatically with your mobile number.
    </div>
  </div>

  <script>
    const basePath = <?= json_encode($basePath ?: '') ?>;
    const errorBanner = document.getElementById('errorBanner');
    const loginStep = document.getElementById('loginStep');
    const registerStep = document.getElementById('registerStep');
    const loginPhone = document.getElementById('loginPhone');
    const regPhone = document.getElementById('regPhone');
    const regName = document.getElementById('regName');
    const regAddress = document.getElementById('regAddress');
    const btnLoginSubmit = document.getElementById('btnLoginSubmit');
    const btnRegisterSubmit = document.getElementById('btnRegisterSubmit');
    const btnPlaceOrder = document.getElementById('btnPlaceOrder');

    // Momo Animated Loader Controls
    function showLoader(msg) {
      const overlay = document.getElementById('momoLoaderOverlay');
      const text = document.getElementById('momoLoaderText');
      if (text && msg) text.textContent = msg;
      if (overlay) overlay.style.display = 'flex';
    }

    function hideLoader() {
      const overlay = document.getElementById('momoLoaderOverlay');
      if (overlay) overlay.style.display = 'none';
    }

    if (btnPlaceOrder) {
      btnPlaceOrder.addEventListener('click', () => {
        showLoader('Opening delicious menu…');
      });
    }

    // Intro Video Logic
    const introOverlay = document.getElementById('introVideoOverlay');
    const introVideo = document.getElementById('introVideoPlayer');
    const soundIcon = document.getElementById('soundIcon');
    const soundLabel = document.getElementById('soundLabel');
    const playIcon = document.getElementById('playIcon');

    function openIntroVideo(manual) {
      if (!introOverlay || !introVideo) return;
      introOverlay.style.display = 'flex';
      introOverlay.classList.remove('hidden');
      introVideo.currentTime = 0;
      if (manual) {
        introVideo.muted = false;
        if (soundIcon) soundIcon.textContent = '🔊';
        if (soundLabel) soundLabel.textContent = 'Sound On';
      }
      introVideo.play().catch(() => {});
      if (playIcon) playIcon.textContent = '⏸️';
    }

    function dismissIntroVideo() {
      if (!introOverlay) return;
      if (introVideo) {
        try { introVideo.pause(); } catch (_) {}
      }
      introOverlay.classList.add('hidden');
      setTimeout(() => {
        introOverlay.style.display = 'none';
      }, 350);
      try {
        sessionStorage.setItem('mama_intro_seen', '1');
      } catch (_) {}
    }

    function toggleIntroSound() {
      if (!introVideo) return;
      introVideo.muted = !introVideo.muted;
      if (soundIcon) soundIcon.textContent = introVideo.muted ? '🔇' : '🔊';
      if (soundLabel) soundLabel.textContent = introVideo.muted ? 'Sound Off' : 'Sound On';
    }

    function toggleIntroPlay() {
      if (!introVideo) return;
      if (introVideo.paused) {
        introVideo.play().catch(() => {});
        if (playIcon) playIcon.textContent = '⏸️';
      } else {
        introVideo.pause();
        if (playIcon) playIcon.textContent = '▶️';
      }
    }

    if (introVideo) {
      introVideo.addEventListener('ended', dismissIntroVideo);
    }

    // Auto-launch intro video on fresh visit
    window.addEventListener('DOMContentLoaded', () => {
      const urlParams = new URLSearchParams(window.location.search);
      const forceIntro = urlParams.get('intro') === '1';
      let seen = false;
      try {
        seen = sessionStorage.getItem('mama_intro_seen') === '1';
      } catch (_) {}

      if (!seen || forceIntro) {
        openIntroVideo(false);
      }
    });

    function showError(msg) {
      if (!errorBanner) return;
      errorBanner.textContent = msg;
      errorBanner.style.display = 'block';
    }

    function clearError() {
      if (!errorBanner) return;
      errorBanner.textContent = '';
      errorBanner.style.display = 'none';
    }

    function backToPhoneLogin() {
      clearError();
      if (registerStep) registerStep.style.display = 'none';
      if (loginStep) loginStep.style.display = 'block';
      if (loginPhone) {
        loginPhone.focus();
      }
    }

    async function handlePhoneSubmit(e) {
      e.preventDefault();
      clearError();
      let phone = (loginPhone.value || '').replace(/\D/g, '');
      if (phone.length === 12 && phone.startsWith('91')) phone = phone.slice(2);
      if (phone.length === 11 && phone.startsWith('0')) phone = phone.slice(1);
      if (phone.length !== 10) {
        showError('Please enter a valid 10-digit mobile number.');
        return;
      }

      btnLoginSubmit.disabled = true;
      btnLoginSubmit.textContent = 'Checking…';
      showLoader('Finding your account & loyalty stamps…');

      try {
        const res = await fetch(`${basePath}/?action=check_phone&phone=${encodeURIComponent(phone)}`);
        const data = await res.json();

        if (data && data.success) {
          if (data.exists) {
            // Customer found and session set! Save to localStorage and refresh page
            try {
              localStorage.setItem('mama_customer', JSON.stringify(data.profile));
            } catch (_) {}
            window.location.reload();
            return;
          } else {
            // Customer does not exist -> show registration form
            hideLoader();
            loginStep.style.display = 'none';
            registerStep.style.display = 'block';
            regPhone.value = phone;
            if (regName) {
              regName.focus();
            }
          }
        } else {
          hideLoader();
          showError((data && data.message) ? data.message : 'Error checking phone number. Please retry.');
        }
      } catch (err) {
        hideLoader();
        showError('Could not connect to server. Please check connection.');
      } finally {
        btnLoginSubmit.disabled = false;
        btnLoginSubmit.textContent = 'Continue ➔';
      }
    }

    async function handleRegisterSubmit(e) {
      e.preventDefault();
      clearError();
      const name = (regName.value || '').trim();
      const phone = (regPhone.value || '').replace(/\D/g, '');
      const address = (regAddress.value || '').trim();

      if (!name) {
        showError('Please enter your name.');
        return;
      }
      if (phone.length !== 10) {
        showError('Invalid mobile number.');
        return;
      }

      btnRegisterSubmit.disabled = true;
      btnRegisterSubmit.textContent = 'Creating Account…';
      showLoader('Setting up your Mama Momo profile…');

      const formData = new FormData();
      formData.append('action', 'register');
      formData.append('name', name);
      formData.append('phone', phone);
      formData.append('address', address);

      try {
        const res = await fetch(`${basePath}/`, {
          method: 'POST',
          body: formData
        });
        const data = await res.json();

        if (data && data.success) {
          // Account registered! Store in localStorage for cross-page persistence
          try {
            localStorage.setItem('mama_customer', JSON.stringify(data.profile));
          } catch (_) {}
          window.location.reload();
          return;
        } else {
          hideLoader();
          showError((data && data.message) ? data.message : 'Registration failed. Please retry.');
        }
      } catch (err) {
        hideLoader();
        showError('Could not complete registration. Please check connection.');
      } finally {
        btnRegisterSubmit.disabled = false;
        btnRegisterSubmit.textContent = 'Create Account & Continue ➔';
      }
    }

    // Auto-sync customer to localStorage if logged in
    <?php if ($customer): ?>
      try {
        localStorage.setItem('mama_customer', JSON.stringify(<?= json_encode($customer, JSON_UNESCAPED_UNICODE) ?>));
      } catch (_) {}
    <?php else: ?>
      // Auto-prefill phone from localStorage if customer had logged in previously
      try {
        const saved = JSON.parse(localStorage.getItem('mama_customer') || 'null');
        if (saved && saved.phone && loginPhone && !loginPhone.value && !window.location.search.includes('action=logout')) {
          loginPhone.value = saved.phone;
        }
      } catch (_) {}
    <?php endif; ?>
  </script>
</body>
</html>
