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
        'price' => 80.0,
        'desc' => 'Fresh cabbage, paneer, and Himalayan herbs',
        'category' => 'Steamed Veg',
        'available' => true,
        'viewOnMenu' => true,
        'status' => 'published'
    ],
    '2' => [
        'id' => '2',
        'name' => 'Steamed Chicken Momo',
        'price' => 120.0,
        'desc' => 'Juicy minced chicken infused with mild spices',
        'category' => 'Steamed Non-Veg',
        'available' => true,
        'viewOnMenu' => true,
        'status' => 'published'
    ],
    '3' => [
        'id' => '3',
        'name' => 'Crispy Fried Veg Momo',
        'price' => 100.0,
        'desc' => 'Golden fried crunchy veg dumplings with spicy chutney',
        'category' => 'Fried Veg',
        'available' => true,
        'viewOnMenu' => true,
        'status' => 'published'
    ],
    '4' => [
        'id' => '4',
        'name' => 'Crispy Fried Chicken Momo',
        'price' => 140.0,
        'desc' => 'Deep fried crisp chicken momos served hot',
        'category' => 'Fried Non-Veg',
        'available' => true,
        'viewOnMenu' => true,
        'status' => 'published'
    ],
    '5' => [
        'id' => '5',
        'name' => 'Jhol Momo Soup Bowl',
        'price' => 130.0,
        'desc' => 'Steamed momos dipped in spicy sesame-tomato jhol broth',
        'category' => 'Specialties',
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
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 6
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

    // 4. Ultimate fallback: Return standard default momo catalogue
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

/**
 * Gets path to customer cache file.
 */
function getCustomersCacheFile(): string
{
    return dirname(__DIR__) . '/customers_cache.json';
}

/**
 * Loads customer cache.
 */
function loadCustomersCache(): array
{
    $file = getCustomersCacheFile();
    if (file_exists($file)) {
        $data = json_decode(@file_get_contents($file), true);
        if (is_array($data)) return $data;
    }
    return [];
}

/**
 * Saves customer to cache.
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
    @file_put_contents(getCustomersCacheFile(), json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    return $record;
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
 * Get all orders for a customer by phone number.
 */
function getOrdersByCustomerPhone(string $phone): array
{
    $phone = normalizePhoneNumber($phone);
    if (!$phone) return [];

    $projCache = dirname(__DIR__) . '/orders_cache.json';
    $tempCache = sys_get_temp_dir() . '/mama_momo_orders.json';
    $all = [];

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

    $list = array_values($all);
    usort($list, function($a, $b) {
        $ta = $a['timestamp'] ?? 0;
        $tb = $b['timestamp'] ?? 0;
        return $tb <=> $ta;
    });

    return $list;
}

