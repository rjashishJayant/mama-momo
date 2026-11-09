<?php
/**
 * Mama Momo - System Configuration & Dynamic Menu Provider
 * 
 * Manages Google Apps Script connectivity and dynamic Google Sheets Menu synchronization.
 */

if (!defined('MAMA_MOMO_INIT')) {
    define('MAMA_MOMO_INIT', true);
}

// Google Apps Script Web App Deployment URL
$APPS_SCRIPT_URL = getenv('APPS_SCRIPT_URL') ?: 'https://script.google.com/macros/s/AKfycbzgHkDCYHOk4z15LPAfFQ22a-BfY9_t4Q2LdUlsi4pYF4_uw0vpY3EXKuPcEU-W7pXfuA/exec';

// Stall Vendor Master PIN (Default: 1234, can be overridden via VENDOR_PIN environment variable)
$VENDOR_PIN = getenv('VENDOR_PIN') ?: '1234';

// Menu Cache Configuration (Seconds)
// Default: 120 seconds (2 minutes). Fast mobile rendering + near real-time sync with Google Sheets.
$MENU_CACHE_TTL = (int)(getenv('MENU_CACHE_TTL') ?: 120);

// Default fallback menu (used if Google Sheet is unreachable or on first offline boot)
$DEFAULT_MENU_ITEMS = [
    '1' => [
        'id' => '1',
        'name' => 'Classic Steamed Veg Momo',
        'price' => 50.0,
        'desc' => 'Fresh cabbage, paneer, and Himalayan herbs',
        'category' => 'Steamed Veg',
        'available' => true,
        'viewOnMenu' => true,
        'status' => 'published'
    ],
    '2' => [
        'id' => '2',
        'name' => 'Crispy Fried Veg Momo',
        'price' => 60.0,
        'desc' => 'Golden fried crunchy veg dumplings with spicy chutney',
        'category' => 'Fried Veg',
        'available' => true,
        'viewOnMenu' => true,
        'status' => 'published'
    ],
    '3' => [
        'id' => '3',
        'name' => 'Finger / Fries',
        'price' => 20.0,
        'desc' => 'crunchy potato fries',
        'category' => 'Fried Veg',
        'available' => true,
        'viewOnMenu' => true,
        'status' => 'published'
    ],
    '4' => [
        'id' => '4',
        'name' => 'Veg Roll',
        'price' => 25.0,
        'desc' => 'crunchy veg roll',
        'category' => 'Rolls',
        'available' => true,
        'viewOnMenu' => true,
        'status' => 'published'
    ],
    '5' => [
        'id' => '5',
        'name' => 'Panner Roll',
        'price' => 40.0,
        'desc' => 'Crunchy Paneer roll',
        'category' => 'Rolls',
        'available' => true,
        'viewOnMenu' => true,
        'status' => 'published'
    ]
];

/**
 * Fetch dynamic menu items from Google Sheets with multi-tier caching and fail-safe fallbacks.
 *
 * @param bool $forceRefresh When true, bypasses disk cache and forces fresh sync from Google Sheets.
 * @param array|null &$meta Output array containing sync diagnostics (source, age, cachedAt, warning).
 * @return array Associative array of menu items indexed by string ID.
 */
function getDynamicMenu(bool $forceRefresh = false, ?array &$meta = null): array
{
    global $APPS_SCRIPT_URL, $MENU_CACHE_TTL, $DEFAULT_MENU_ITEMS;

    $projCache = dirname(__DIR__) . '/menu_cache.json';
    $tempCache = sys_get_temp_dir() . '/mama_momo_menu_cache.json';
    $now = time();

    // 1. Try reading fresh local cache if force-refresh is NOT requested
    if (!$forceRefresh) {
        foreach ([$projCache, $tempCache] as $file) {
            if (file_exists($file)) {
                $json = @file_get_contents($file);
                if ($json !== false && $json !== '') {
                    $mtime = filemtime($file) ?: 0;
                    if (($now - $mtime) < $MENU_CACHE_TTL) {
                        $decoded = json_decode($json, true);
                        if (is_array($decoded) && !empty($decoded['items']) && is_array($decoded['items'])) {
                            $meta = [
                                'source' => 'cache',
                                'age' => $now - $mtime,
                                'cachedAt' => date('Y-m-d H:i:s', $mtime),
                                'itemsCount' => count($decoded['items'])
                            ];
                            return $decoded['items'];
                        }
                    }
                }
            }
        }
    }

    // 2. Fetch directly from Google Apps Script Web App (action=getMenu)
    $fetchUrl = $APPS_SCRIPT_URL . (strpos($APPS_SCRIPT_URL, '?') === false ? '?' : '&') . 'action=getMenu';
    $ch = curl_init($fetchUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 14
    ]);
    $rawResponse = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($rawResponse !== false && $httpCode >= 200 && $httpCode < 300) {
        $parsed = json_decode($rawResponse, true);
        if (is_array($parsed) && !empty($parsed['success']) && !empty($parsed['menu']) && is_array($parsed['menu'])) {
            $items = [];
            foreach ($parsed['menu'] as $index => $row) {
                if (!is_array($row)) continue;
                $rawId = $row['id'] ?? null;
                $name = trim((string)($row['name'] ?? ''));
                if ($name === '') continue; // Skip blank items

                $id = ($rawId !== null && $rawId !== '') ? (string)$rawId : (string)($index + 1);
                $price = (float)($row['price'] ?? 0);
                $desc = trim((string)($row['desc'] ?? ($row['description'] ?? '')));
                $category = trim((string)($row['category'] ?? 'General'));
                $available = !isset($row['available']) || (bool)$row['available'];
                $viewOnMenu = !isset($row['viewOnMenu']) || (bool)$row['viewOnMenu'];
                $status = !empty($row['status']) ? (string)$row['status'] : ($viewOnMenu ? 'published' : 'draft');

                $items[$id] = [
                    'id' => $id,
                    'name' => $name,
                    'price' => $price,
                    'desc' => $desc,
                    'category' => $category,
                    'available' => $available,
                    'viewOnMenu' => $viewOnMenu,
                    'status' => $status
                ];
            }

            if (!empty($items)) {
                $cacheData = json_encode([
                    'updatedAt' => $now,
                    'updatedIso' => date('c', $now),
                    'items' => $items
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

                // Persist cache in project directory and system temp directory
                @file_put_contents($projCache, $cacheData);
                @file_put_contents($tempCache, $cacheData);

                $meta = [
                    'source' => 'live_sheet',
                    'age' => 0,
                    'cachedAt' => date('Y-m-d H:i:s', $now),
                    'itemsCount' => count($items)
                ];
                return $items;
            }
        }
    }

    // 3. Graceful fallback: If live request failed, return stale cache if available
    foreach ([$projCache, $tempCache] as $file) {
        if (file_exists($file)) {
            $json = @file_get_contents($file);
            if ($json !== false && $json !== '') {
                $decoded = json_decode($json, true);
                if (is_array($decoded) && !empty($decoded['items']) && is_array($decoded['items'])) {
                    $mtime = filemtime($file) ?: 0;
                    $meta = [
                        'source' => 'stale_cache',
                        'age' => $now - $mtime,
                        'cachedAt' => date('Y-m-d H:i:s', $mtime),
                        'itemsCount' => count($decoded['items']),
                        'warning' => 'Serving cached menu because Google Sheet could not be reached.'
                    ];
                    return $decoded['items'];
                }
            }
        }
    }

    // 4. Ultimate fallback: Return standard default momo catalogue & auto-seed empty cache
    $fallbackCache = json_encode([
        'updatedAt' => $now,
        'updatedIso' => date('c', $now),
        'items' => $DEFAULT_MENU_ITEMS
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    if (!file_exists($projCache) || filesize($projCache) === 0) {
        @file_put_contents($projCache, $fallbackCache);
    }
    if (!file_exists($tempCache) || filesize($tempCache) === 0) {
        @file_put_contents($tempCache, $fallbackCache);
    }

    $meta = [
        'source' => 'default_fallback',
        'age' => 0,
        'cachedAt' => date('Y-m-d H:i:s', $now),
        'itemsCount' => count($DEFAULT_MENU_ITEMS),
        'warning' => 'Serving default menu items.'
    ];
    return $DEFAULT_MENU_ITEMS;
}

/**
 * Normalizes a 10-digit Indian mobile number (stripping +91 or leading 0).
 */
function normalizePhoneNumber($phone): string
{
    $digits = preg_replace('/\D+/', '', (string)$phone);
    if (strlen($digits) === 12 && substr($digits, 0, 2) === '91') {
        $digits = substr($digits, 2);
    } elseif (strlen($digits) === 11 && substr($digits, 0, 1) === '0') {
        $digits = substr($digits, 1);
    }
    return strlen($digits) === 10 ? $digits : '';
}

// Master Session Signing Secret for Stateless Serverless Authentication
$SESSION_SECRET = getenv('SESSION_SECRET') ?: 'mama_momo_2026_secure_session_salt_77';

/**
 * Gets path to customer cache file.
 */
function getCustomersCacheFile(): string
{
    return dirname(__DIR__) . '/customers_cache.json';
}

/**
 * Loads customer cache from project directory and system temp directory.
 */
function loadCustomersCache(): array
{
    $projCache = dirname(__DIR__) . '/customers_cache.json';
    $tempCache = sys_get_temp_dir() . '/mama_momo_customers.json';
    $cache = [];
    foreach ([$projCache, $tempCache] as $file) {
        if (file_exists($file)) {
            $data = json_decode(@file_get_contents($file), true);
            if (is_array($data)) {
                foreach ($data as $k => $v) {
                    $cache[(string)$k] = $v;
                }
            }
        }
    }
    return $cache;
}

/**
 * Saves customer to cache in both project directory and system temp directory.
 */
function saveCustomerToCache(string $phone, string $name, string $address = '', array $extra = []): array
{
    $phone = normalizePhoneNumber($phone);
    if (!$phone) return [];
    $cache = loadCustomersCache();
    $existing = $cache[$phone] ?? [];
    $record = array_merge($existing, $extra, [
        'phone' => $phone,
        'name' => trim($name) ?: ($existing['name'] ?? 'Customer'),
        'address' => trim($address) ?: ($existing['address'] ?? ''),
        'updatedAt' => time()
    ]);
    if (!isset($record['createdAt'])) {
        $record['createdAt'] = time();
    }
    $cache[$phone] = $record;
    $encoded = json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    $projCache = dirname(__DIR__) . '/customers_cache.json';
    $tempCache = sys_get_temp_dir() . '/mama_momo_customers.json';
    @file_put_contents($projCache, $encoded);
    @file_put_contents($tempCache, $encoded);
    return $record;
}

/**
 * Sets a cryptographically signed cookie with customer profile data.
 * Guarantees seamless session persistence across stateless Vercel lambda containers.
 */
function setCustomerSessionCookie(array $customerProfile): void
{
    global $SESSION_SECRET;
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    $_SESSION['customer'] = $customerProfile;

    $payload = base64_encode(json_encode($customerProfile, JSON_UNESCAPED_UNICODE));
    $sig = hash_hmac('sha256', $payload, $SESSION_SECRET);
    $token = $payload . '.' . $sig;

    $expires = time() + (86400 * 30); // 30 days
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    @setcookie('mama_cust_auth', $token, [
        'expires' => $expires,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    @setcookie('mama_cust_phone', $customerProfile['phone'] ?? '', [
        'expires' => $expires,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => false,
        'samesite' => 'Lax'
    ]);
}

/**
 * Retrieves and validates the authenticated customer across serverless requests.
 */
function getAuthenticatedCustomer(): ?array
{
    global $SESSION_SECRET;
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }

    // 1. Check PHP session first
    if (!empty($_SESSION['customer']) && is_array($_SESSION['customer']) && !empty($_SESSION['customer']['phone'])) {
        return $_SESSION['customer'];
    }

    // 2. Validate signed cookie (vital for Vercel lambdas)
    $cookieToken = $_COOKIE['mama_cust_auth'] ?? '';
    if ($cookieToken !== '' && strpos($cookieToken, '.') !== false) {
        list($payload, $sig) = explode('.', $cookieToken, 2);
        $expectedSig = hash_hmac('sha256', $payload, $SESSION_SECRET);
        if (hash_equals($expectedSig, $sig)) {
            $decoded = json_decode(base64_decode($payload), true);
            if (is_array($decoded) && !empty($decoded['phone'])) {
                $_SESSION['customer'] = $decoded;
                return $decoded;
            }
        }
    }

    // 3. Fallback: check phone cookie against customer cache
    $phoneCookie = normalizePhoneNumber($_COOKIE['mama_cust_phone'] ?? '');
    if ($phoneCookie !== '') {
        $profile = findCustomerProfile($phoneCookie);
        if ($profile) {
            $_SESSION['customer'] = $profile;
            return $profile;
        }
    }

    return null;
}

/**
 * Clears customer session and auth cookies upon logout.
 */
function clearCustomerSession(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    unset($_SESSION['customer']);
    unset($_SESSION['active_order']);

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    @setcookie('mama_cust_auth', '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    @setcookie('mama_cust_phone', '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => false,
        'samesite' => 'Lax'
    ]);
    @setcookie('mama_active_order_token', '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => false,
        'samesite' => 'Lax'
    ]);
}

/**
 * Sets active order token cookie for cross-container pass rendering.
 */
function setActiveOrderCookie(array $order): void
{
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    $_SESSION['active_order'] = $order;

    $token = (string)($order['orderId'] ?? '');
    if ($token !== '') {
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
        @setcookie('mama_active_order_token', $token, [
            'expires' => time() + 7200, // 2 hours
            'path' => '/',
            'secure' => $isHttps,
            'httponly' => false,
            'samesite' => 'Lax'
        ]);
    }
}

/**
 * Gets active order across serverless containers.
 */
function getActiveOrder(?string $tokenHint = null): ?array
{
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }

    if (!empty($_SESSION['active_order']) && is_array($_SESSION['active_order']) && !empty($_SESSION['active_order']['orderId'])) {
        return $_SESSION['active_order'];
    }

    $token = strtoupper(trim((string)($tokenHint ?: ($_COOKIE['mama_active_order_token'] ?? ''))));
    if ($token === '') {
        return null;
    }

    // 1. Lookup in local orders caches
    $projCache = dirname(__DIR__) . '/orders_cache.json';
    $tempCache = sys_get_temp_dir() . '/mama_momo_orders.json';
    foreach ([$projCache, $tempCache] as $file) {
        if (file_exists($file)) {
            $data = json_decode(@file_get_contents($file), true) ?: [];
            if (isset($data[$token]) && is_array($data[$token])) {
                $_SESSION['active_order'] = $data[$token];
                return $data[$token];
            }
        }
    }

    // 2. Query Google Apps Script by order token (source-of-truth)
    global $APPS_SCRIPT_URL;
    if (!empty($APPS_SCRIPT_URL)) {
        $gasUrl = $APPS_SCRIPT_URL . (strpos($APPS_SCRIPT_URL, '?') === false ? '?' : '&') . 'action=getOrder&orderId=' . urlencode($token);
        $ch = curl_init($gasUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 6
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);

        if ($raw) {
            $gasData = json_decode($raw, true);
            if (is_array($gasData) && (!empty($gasData['found']) || !empty($gasData['success'])) && !empty($gasData['orderId'])) {
                $ts = is_numeric($gasData['timestamp'] ?? null) 
                    ? (int)$gasData['timestamp'] 
                    : (strtotime((string)($gasData['timestamp'] ?? '')) ?: time());

                $ord = [
                    'orderId' => (string)$gasData['orderId'],
                    'name' => (string)($gasData['name'] ?? 'Customer'),
                    'phone' => (string)($gasData['phone'] ?? ''),
                    'itemsDisplay' => (string)($gasData['itemsDisplay'] ?? ($gasData['items'] ?? 'Momo Selection')),
                    'totalAmount' => (float)($gasData['totalAmount'] ?? 0),
                    'finalPrice' => (float)($gasData['finalPrice'] ?? ($gasData['totalAmount'] ?? 0)),
                    'projectedVisit' => (int)($gasData['visitNumber'] ?? 1),
                    'currentVisits' => (int)($gasData['currentVisits'] ?? 0),
                    'remainingVisits' => (int)($gasData['remainingVisits'] ?? 0),
                    'isDiscount' => !empty($gasData['discountApplied']),
                    'isCooldown' => !empty($gasData['isCooldown']),
                    'status' => strtoupper((string)($gasData['status'] ?? 'PENDING')),
                    'timestamp' => $ts
                ];
                $_SESSION['active_order'] = $ord;
                setActiveOrderCookie($ord);

                // Cache in container temp
                $cData = file_exists($tempCache) ? (json_decode(@file_get_contents($tempCache), true) ?: []) : [];
                $cData[$token] = $ord;
                @file_put_contents($tempCache, json_encode($cData, JSON_UNESCAPED_UNICODE));

                return $ord;
            }
        }
    }

    return null;
}

/**
 * Lookup customer by phone, checking local cache and Google Sheets.
 */
function findCustomerProfile(string $phone): ?array
{
    global $APPS_SCRIPT_URL;
    $phone = normalizePhoneNumber($phone);
    if (!$phone) return null;

    $cache = loadCustomersCache();
    $profile = $cache[$phone] ?? null;

    // Check with Google Apps Script action=checkCustomer
    if (!empty($APPS_SCRIPT_URL)) {
        $gasUrl = $APPS_SCRIPT_URL . (strpos($APPS_SCRIPT_URL, '?') === false ? '?' : '&') . 'action=checkCustomer&phone=' . urlencode($phone);
        $ch = curl_init($gasUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 4
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);

        if ($raw) {
            $res = json_decode($raw, true);
            if (is_array($res) && !empty($res['success']) && !empty($res['found'])) {
                $name = !empty($res['name']) ? $res['name'] : ($profile['name'] ?? '');
                $address = $profile['address'] ?? '';
                $profile = [
                    'phone' => $phone,
                    'name' => $name,
                    'address' => $address,
                    'currentVisits' => (int)($res['currentVisits'] ?? 0),
                    'nextVisit' => (int)($res['nextVisit'] ?? 1),
                    'remainingVisits' => (int)($res['remainingVisits'] ?? 0),
                    'willGetDiscount' => !empty($res['willGetDiscount']),
                    'isCooldown' => !empty($res['isCooldown']),
                    'updatedAt' => time()
                ];
                saveCustomerToCache($phone, $name, $address, $profile);
                return $profile;
            }
        }
    }

    if ($profile) {
        return $profile;
    }

    // Check if customer has orders in orders_cache.json
    $orders = getOrdersByCustomerPhone($phone);
    if (!empty($orders)) {
        $first = $orders[0];
        $profile = [
            'phone' => $phone,
            'name' => $first['name'] ?? 'Customer',
            'address' => '',
            'currentVisits' => count(array_filter($orders, function($o) {
                $st = strtoupper($o['status'] ?? '');
                return $st === 'APPROVED' || $st === 'APPROVED_COOLDOWN';
            })),
            'updatedAt' => time()
        ];
        saveCustomerToCache($phone, $profile['name'], '', $profile);
        return $profile;
    }

    return null;
}

/**
 * Fetches all orders from Google Apps Script with short caching (10 seconds) to avoid rate limits.
 *
 * @param bool $forceRefresh Bypass cache if true.
 * @return array Map of order tokens to order objects.
 */
function fetchAllOrdersFromGas(bool $forceRefresh = false): array
{
    global $APPS_SCRIPT_URL, $VENDOR_PIN;
    if (empty($APPS_SCRIPT_URL)) {
        return [];
    }

    $tempCache = sys_get_temp_dir() . '/mama_momo_gas_orders_cache.json';
    $now = time();

    if (!$forceRefresh && file_exists($tempCache)) {
        $json = @file_get_contents($tempCache);
        if ($json) {
            $mtime = @filemtime($tempCache) ?: 0;
            if (($now - $mtime) < 10) { // 10-second TTL for fast refreshes
                $decoded = json_decode($json, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }
    }

    $pin = $VENDOR_PIN ?? '1234';
    $gasUrl = $APPS_SCRIPT_URL . (strpos($APPS_SCRIPT_URL, '?') === false ? '?' : '&') . 'action=getAllOrders&pin=' . urlencode($pin);

    $ch = curl_init($gasUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 6
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);

    if (!$raw) {
        // Fallback: try reading stale cache if curl timed out
        if (file_exists($tempCache)) {
            $decoded = json_decode(@file_get_contents($tempCache), true);
            if (is_array($decoded)) return $decoded;
        }
        return [];
    }

    $resp = json_decode($raw, true);
    if (!is_array($resp) || empty($resp['success']) || !isset($resp['orders']) || !is_array($resp['orders'])) {
        return [];
    }

    $orderMap = [];
    foreach ($resp['orders'] as $ord) {
        if (!is_array($ord) || empty($ord['orderId'])) continue;
        $tok = strtoupper(trim((string)$ord['orderId']));

        // Normalize timestamp
        $ts = is_numeric($ord['timestamp'] ?? null)
            ? (int)$ord['timestamp']
            : (strtotime((string)($ord['timestamp'] ?? '')) ?: $now);

        $orderMap[$tok] = [
            'orderId' => $tok,
            'name' => (string)($ord['name'] ?? 'Customer'),
            'phone' => normalizePhoneNumber((string)($ord['phone'] ?? '')),
            'items' => (string)($ord['items'] ?? ''),
            'itemsDisplay' => (string)($ord['itemsDisplay'] ?? ($ord['items'] ?? 'Momo Selection')),
            'totalAmount' => (float)($ord['totalAmount'] ?? 0),
            'finalPrice' => (float)($ord['finalPrice'] ?? ($ord['totalAmount'] ?? 0)),
            'visitNumber' => (int)($ord['visitNumber'] ?? 1),
            'discountApplied' => !empty($ord['discountApplied']),
            'status' => strtoupper((string)($ord['status'] ?? 'PENDING')),
            'timestamp' => $ts,
            'date' => (string)($ord['date'] ?? date('Y-m-d', $ts)),
            'time' => (string)($ord['time'] ?? date('h:i A', $ts))
        ];
    }

    // Save to temp cache
    @file_put_contents($tempCache, json_encode($orderMap, JSON_UNESCAPED_UNICODE));

    // Also merge into persistent container order cache
    $containerCacheFile = sys_get_temp_dir() . '/mama_momo_orders.json';
    $cData = file_exists($containerCacheFile) ? (json_decode(@file_get_contents($containerCacheFile), true) ?: []) : [];
    foreach ($orderMap as $k => $v) {
        $cData[$k] = $v;
    }
    @file_put_contents($containerCacheFile, json_encode($cData, JSON_UNESCAPED_UNICODE));

    return $orderMap;
}

/**
 * Get all orders for a customer by phone number.
 */
function getOrdersByCustomerPhone(string $phone): array
{
    $phone = normalizePhoneNumber($phone);
    if (!$phone) return [];

    $projCache = dirname(__DIR__) . '/orders_cache.json';
    $tempCache = sys_get_temp_dir() . '/mama_momo_orders.json';
    $all = [];

    // 1. Gather from local disk caches
    foreach ([$projCache, $tempCache] as $file) {
        if (file_exists($file)) {
            $data = json_decode(@file_get_contents($file), true) ?: [];
            foreach ($data as $token => $ord) {
                if (normalizePhoneNumber($ord['phone'] ?? '') === $phone) {
                    $all[$token] = $ord;
                }
            }
        }
    }

    // 2. Query Google Apps Script source of truth
    $gasOrders = fetchAllOrdersFromGas();
    foreach ($gasOrders as $token => $ord) {
        if (normalizePhoneNumber($ord['phone'] ?? '') === $phone) {
            $all[$token] = $ord;
        }
    }

    $list = array_values($all);
    usort($list, function($a, $b) {
        $ta = $a['timestamp'] ?? 0;
        $tb = $b['timestamp'] ?? 0;
        return $tb <=> $ta;
    });

    return $list;
}

/**
 * Get all unverified (PENDING) orders for vendor terminal.
 */
function getUnverifiedOrders(): array
{
    $projCache = dirname(__DIR__) . '/orders_cache.json';
    $tempCache = sys_get_temp_dir() . '/mama_momo_orders.json';
    $all = [];

    // 1. Local disk caches
    foreach ([$projCache, $tempCache] as $file) {
        if (file_exists($file)) {
            $data = json_decode(@file_get_contents($file), true) ?: [];
            foreach ($data as $token => $ord) {
                $st = strtoupper((string)($ord['status'] ?? 'PENDING'));
                if ($st === 'PENDING' || $st === '' || empty($ord['status'])) {
                    $all[$token] = $ord;
                }
            }
        }
    }

    // 2. Query Google Apps Script source of truth
    $gasOrders = fetchAllOrdersFromGas();
    foreach ($gasOrders as $token => $ord) {
        $st = strtoupper((string)($ord['status'] ?? 'PENDING'));
        if ($st === 'PENDING' || $st === '' || empty($ord['status'])) {
            $all[$token] = $ord;
        }
    }

    $list = array_values($all);
    usort($list, function($a, $b) {
        $ta = $a['timestamp'] ?? 0;
        $tb = $b['timestamp'] ?? 0;
        return $tb <=> $ta;
    });

    return $list;
}

