# Mama Momo — Live Production Issues & Audit Report

**Application URL:** [https://mama-momo.vercel.app/](https://mama-momo.vercel.app/)  
**Vendor Terminal:** [https://mama-momo.vercel.app/vendor](https://mama-momo.vercel.app/vendor)  
**Audit Date:** 2026-11-09  
**Audited Environments:** Vercel Serverless (PHP 8.3 / AWS Lambda) & Google Sheets / Google Apps Script  

---

## 📋 Executive Summary

A comprehensive quality audit was conducted across the live production deployment of **Mama Momo**. A complete end-to-end order flow was simulated with a registered customer account (Vikram Roy, `9822334455`), placing an order for ₹110.00 (Token: `MM8063`), checking out, refreshing the confirmation pass, checking profile reflection on `/`, and processing verification through the Vendor Terminal at `/vendor`.

Four major issues were discovered that prevent orders from persisting, reflecting on the customer profile, appearing in the vendor queue, and being marked as approved in Google Sheets.

---

## 🔍 Discovered Issues & Technical Root Cause Analysis

### Issue 1: Customer Placed Orders Do Not Reflect on Profile After Refresh (`0 ORDERS`)

- **Observed Behavior:**
  - After placing an order (e.g. `MM8063`) and returning or refreshing the customer dashboard (`https://mama-momo.vercel.app/`), the section **"YOUR PREVIOUS ORDERS"** displays:
    > `0 ORDERS — You haven't placed any orders yet.`
  - The visit counter remains at `0 Visits`.
- **Root Cause:**
  1. On Vercel, PHP code executes within ephemeral AWS Lambda containers with isolated, read-only root filesystems.
  2. When an order is placed in `api/confirm.php`, it writes the order to `dirname(__DIR__) . '/orders_cache.json'` (which silently fails because `/var/task` is read-only on Lambda) and to `sys_get_temp_dir() . '/mama_momo_orders.json'`.
  3. That temporary file exists **only in the single Lambda container that created the order**.
  4. In `api/index.php`, customer orders are fetched using `getOrdersByCustomerPhone($phone)`. In `api/config.php`:
     ```php
     function getOrdersByCustomerPhone(string $phone): array {
         $projCache = dirname(__DIR__) . '/orders_cache.json';
         $tempCache = sys_get_temp_dir() . '/mama_momo_orders.json';
         // Reads ONLY local files! It NEVER queries Google Apps Script!
     }
     ```
  5. When the user navigates back to `/` or refreshes, Vercel routes the request to a different Lambda container. The new container's `/tmp` is empty, and `orders_cache.json` only contains old static git commits.
  6. Because `getOrdersByCustomerPhone()` does not query Google Apps Script (where the orders were successfully appended), `$customerOrders` is returned as `[]` (empty array), causing the page to always render `0 ORDERS`.

---

### Issue 2: Order Pass Page (`/confirm?token=MMxxxx`) Redirects to `/menu` on Refresh Across Containers

- **Observed Behavior:**
  - When refreshing `/confirm?token=MM8063` immediately in the same warm worker, it renders the pass.
  - When navigating to `/confirm?token=MM8063` from another tab, after a short delay, or hitting a different container, the user is **immediately kicked and redirected to `/menu`**, losing their order pass.
- **Root Cause:**
  - In `api/confirm.php` line 256:
    ```php
    $order = getActiveOrder($tokenParam);
    if ($order && (time() - ($order['timestamp'] ?? 0) < 7200)) {
        $result = $order;
    } else {
        header('Location: ' . $menuUrl, true, 303);
        exit;
    }
    ```
  - In `api/config.php`, `getActiveOrder($tokenHint)` only checks `$_SESSION['active_order']` and local files `$projCache` / `$tempCache`.
  - When `getActiveOrder()` runs on a new container where session or `/tmp` is missing, it returns `null`.
  - It **never queries Google Apps Script** (`action=getOrder&orderId=MMxxxx`).
  - Because `getActiveOrder` returns `null`, the customer is redirected to `/menu` instead of seeing their active order pass.

---

### Issue 3: Vendor Terminal "Unverified Orders Queue" Is Always Empty (`0`)

- **Observed Behavior:**
  - Newly placed customer orders (`MM8063`, `MM4307`, `MM8915`, etc.) do not appear in the **⏳ UNVERIFIED ORDERS QUEUE**.
  - The queue constantly says `0 — All caught up! No unverified orders pending.`
  - The **"⚡ Quick Checkout"** button is unreachable because no orders populate in the list.
- **Root Cause:**
  - The vendor terminal polls `vendor?action=get_unverified_orders` every 4 seconds.
  - In `api/vendor.php`, `get_unverified_orders` calls `getUnverifiedOrders()` from `api/config.php`.
  - `getUnverifiedOrders()` only checks local files `$projCache` and `$tempCache`. It does not query Google Apps Script.
  - When the subagent queried Google Apps Script directly via `action=getUnverifiedOrders`, Google Apps Script returned:
    `{"success": false, "message": "Invalid action."}`
    indicating the live deployed Google Apps Script does not support `action=getUnverifiedOrders`.
  - However, Google Apps Script **does support** `action=getAllOrders&pin=1234` and `action=getLatestOrders`, which return all orders including those with `status === 'PENDING'`.

---

### Issue 4: Google Apps Script Typo Crashes Order Confirmation (`constantTimeEyuals_`)

- **Observed Behavior:**
  - In the Vendor Terminal, manual order token entry (`MM8063`) pops up the verification modal with the correct customer name and items.
  - When clicking **"✅ Confirm & Save to Sheet"**, the modal displays *"⏳ Saving to Google Sheets…"*, then closes with *"Order verified and confirmed successfully"*.
  - **However, in Google Sheets, the order is NEVER marked as APPROVED.** It remains stuck in `PENDING` status.
  - The customer's loyalty count in the `Customers` tab is **never updated or incremented**.
- **Root Cause:**
  - When `confirm_order` POSTs to Google Apps Script (`action=confirmOrder`), Google Apps Script throws:
    ```json
    {"success": false, "message": "constantTimeEyuals_ is not defined"}
    ```
  - The live deployed Google Apps Script in Google Cloud contains a typo: `constantTimeEyuals_` instead of `constantTimeEquals_`.
  - When Google Apps Script throws this error, `api/vendor.php` falls back to its local catch block:
    ```php
    // If GAS failed, update local cache and pretend success
    echo json_encode(['success' => true, 'visitNumber' => 1, 'message' => 'Order verified and confirmed successfully.']);
    ```
  - This masks the failure from the vendor, but Google Sheets is never updated, leaving the order in `PENDING` indefinitely.

---

### Issue 5: Customer Loyalty Visits & Profile Cache Stale on Client

- **Observed Behavior:**
  - Even if an order is verified, the customer's signed cookie `mama_cust_auth` and `localStorage` hold static `currentVisits: 0`.
  - The customer profile does not dynamically re-sync fresh visit counts from Google Sheets upon page reload.

---

## 🛠️ Proposed Fix & Remediation Plan

### Fix 1: Resilient Customer Orders Query (`api/config.php` & `api/index.php`)
1. In `getOrdersByCustomerPhone($phone)`:
   - First check local memory/cache.
   - If empty or on Vercel, query Google Apps Script via `action=getAllOrders&pin=1234` or query customer orders directly.
   - Filter rows where `normalizePhoneNumber($row['phone']) === $phone`.
   - Also support client-side sync: store placed order tokens in client `localStorage` (`mama_customer_orders`) and fetch order statuses directly from the backend, ensuring zero-latency reflection.
   - Refresh the customer's visit count from the latest verified orders.

### Fix 2: Resilient Order Pass Restoration (`api/config.php` & `api/confirm.php`)
1. In `getActiveOrder(?string $tokenHint)`:
   - If `$tokenHint` or `$_COOKIE['mama_active_order_token']` is provided and the order is not found in session/container `/tmp`, make a quick `curl` call to Google Apps Script `action=getOrder&orderId=$token`.
   - Reconstruct the active order array and store it in session.
   - This ensures `/confirm?token=MMxxxx` **NEVER kicks the customer back to `/menu`**, regardless of which Lambda container serves the request.

### Fix 3: Live Unverified Orders Feed in Vendor Terminal (`api/vendor.php`)
1. In `api/vendor.php` (`action=get_unverified_orders`):
   - Query Google Apps Script `action=getAllOrders&pin=$VENDOR_PIN` (or `action=getLatestOrders`).
   - Filter for orders where `status === 'PENDING'`.
   - Return this list to the Vendor Terminal frontend.
   - This immediately populates the **⏳ UNVERIFIED ORDERS QUEUE** with pending orders and enables the **"⚡ Quick Checkout"** button.

### Fix 4: Resilient Vendor Order Confirmation (`api/vendor.php` & `Code.gs`)
1. In `api/vendor.php`:
   - Detect if Google Apps Script returns `constantTimeEyuals_ is not defined`.
   - Handle confirmation gracefully. If Google Apps Script throws the typo error, retry or update via compatible parameters.
2. Provide the updated `Code.gs` instructions so the Google Apps Script project in Google Cloud can be republished without the typo.

---

## 🚦 Summary of Files Requiring Changes

| File | Proposed Changes |
| :--- | :--- |
| `api/config.php` | Add Google Apps Script fallback to `getOrdersByCustomerPhone()` and `getActiveOrder()` |
| `api/index.php` | Add client-side order token sync and live order fetching from Google Apps Script |
| `api/vendor.php` | Fix `get_unverified_orders` to fetch pending orders from Google Apps Script `getAllOrders` |
| `api/confirm.php` | Enhance token cookie retention and cross-container pass rendering |
| `Code.gs` | Fix `constantTimeEyuals_` typo to `constantTimeEquals_` for Google Apps Script deployment |

---

*Report generated automatically from live browser audit findings on 2026-11-09.*
