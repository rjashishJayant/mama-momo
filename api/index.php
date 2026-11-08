<?php
$scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
if (substr($scriptDir, -4) === '/api' || substr($scriptDir, -4) === '\\api') {
    $basePath = substr($scriptDir, 0, -4);
} else {
    $basePath = $scriptDir;
}
$basePath = rtrim(str_replace('\\', '/', $basePath), '/');
$menuUrl = ($basePath ?: '') . '/menu';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>The Momo Point / Mama Momo</title>
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
      padding: 20px;
    }
    .hero-card {
      background: #ffffff;
      max-width: 420px;
      width: 100%;
      padding: 36px 24px;
      border-radius: 20px;
      box-shadow: 0 12px 30px rgba(217, 56, 30, 0.12);
      text-align: center;
      border: 1px solid #ffe3de;
    }
    .emoji-logo {
      font-size: 4.5rem;
      margin-bottom: 12px;
      display: inline-block;
      animation: bounce 2s infinite ease-in-out;
    }
    @keyframes bounce {
      0%, 100% { transform: translateY(0); }
      50% { transform: translateY(-8px); }
    }
    h1 {
      color: #d9381e;
      font-size: 1.85rem;
      font-weight: 800;
      margin-bottom: 8px;
      line-height: 1.2;
    }
    .tagline {
      color: #666;
      font-size: 0.95rem;
      line-height: 1.4;
      margin-bottom: 24px;
    }
    .promo-banner {
      background: #ffebe8;
      border: 2px dashed #d9381e;
      color: #d9381e;
      padding: 14px 16px;
      border-radius: 12px;
      font-weight: 700;
      font-size: 0.95rem;
      display: block;
      margin-bottom: 28px;
      box-shadow: 0 2px 8px rgba(217, 56, 30, 0.08);
    }
    .btn {
      display: block;
      width: 100%;
      background: #d9381e;
      color: #ffffff;
      text-decoration: none;
      padding: 16px;
      border-radius: 12px;
      font-weight: bold;
      font-size: 1.1rem;
      box-shadow: 0 6px 18px rgba(217, 56, 30, 0.35);
      transition: transform 0.15s ease, background-color 0.15s ease;
    }
    .btn:active {
      transform: scale(0.98);
      background: #b52912;
    }
    .footer-note {
      margin-top: 20px;
      font-size: 0.8rem;
      color: #999;
    }
  </style>
</head>
<body>
  <div class="hero-card">
    <div class="emoji-logo">🥟</div>
    <h1>Mama Momo Terminal</h1> 
    <p class="tagline">Hot, authentic Himalayan street-style momos freshly steamed and fried every day.</p>
    
    <div class="promo-banner">
      🎁 5th Visit = Flat 50% Off Your Order
    </div>

    <a href="<?= htmlspecialchars($menuUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn">View Menu &amp; Order</a>
    
    <div class="footer-note">Loyalty rewards tracked automatically with your mobile number.</div>
  </div>
</body>
</html>
