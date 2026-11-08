# Mama Momo – corrected integration setup

## Files and routes
- `/` → `api/index.php`
- `/menu` → `api/menu.php`
- `/checkout` → `api/checkout.php`
- `/confirm` → `api/confirm.php`
- `/vendor` → `api/vendor.php` (with root `vendor.php` forwarder for local WAMP)
- `api/config.php` contains the Apps Script deployment URL and the PHP display menu. Do not put the vendor PIN in this file.

## 1. Google Sheets
Use the Google spreadsheet bound to the Apps Script project. On first API request, the script automatically verifies and creates `Customers`, `Orders`, and `Menu` sheets:

### Customers (A:D)
`Phone | Name | VisitCount | LastVisit`

### Orders (A:I)
`Timestamp | OrderID | Phone | Name | Items | TotalAmount | VisitNumber | DiscountApplied | Status`

### Menu (A:G) - Dynamic Item Catalog & Draft Management
`ID | Name | Price | Description | Category | Available | View on Menu`
- **ID:** Unique item identifier (e.g. `1`, `2`, `MM01`).
- **Name:** Display name on the customer menu (e.g. `Classic Steamed Veg Momo`).
- **Price:** Price in ₹ (e.g. `80`).
- **Description:** Ingredients and preparation note.
- **Category:** Categorization tag used for interactive filter chips on the customer menu (e.g. `Steamed Veg`, `Fried Non-Veg`, `Specialties`).
- **Available:** Stock status (`YES` or `NO`). Setting this to `NO` marks the item as "Sold Out" on the customer menu, disables addition to cart, and prevents checkout.
- **View on Menu (Drafts):** Visibility status (`YES` or `NO`, or `Draft`). Setting this to `NO` or `DRAFT` keeps the item as a **Draft** — it is **completely hidden from the customer menu**! Customers will not see it, and server validation rejects any order containing draft items. Setting it to `YES` publishes it live to customers.

## 2. Apps Script deployment
1. Open the spreadsheet-bound Apps Script project.
2. Replace the existing `Code.gs` with the included `Code.gs`.
3. In Apps Script, open **Project Settings → Script properties → Add script property**.
4. Add `VENDOR_PIN` with a strong private PIN (do not use the old public/default `1234`). Never put it in `vendor.php`, JavaScript, or PHP source.
5. Save and deploy **Deploy → Manage deployments → Edit → New version → Deploy** as a Web app.
6. Set **Execute as: Me** and access to **Anyone** (or the broadest access your customer flow requires). The web app URL must be the `/exec` URL.
7. Update `MAMA_MOMO_APPS_SCRIPT_URL` in `api/config.php` if the deployment URL changed.

The Apps Script URL in the supplied project has been retained. A redeployment may create a new URL; use the new `/exec` URL if so.

## 3. Dynamic Menu Management from Google Sheet
The menu is now completely dynamic and managed directly from the **`Menu`** tab in Google Sheets:
- **Adding Items:** Simply add a new row in the `Menu` tab in Google Sheets with ID, Name, Price, Description, Category, and Available = `YES`.
- **Changing Prices:** Update the `Price` column in Google Sheets. Changes take effect on the customer screen within 2 minutes (or instantly upon clicking `🔄 Refresh` or using the Vendor terminal `🔄 Sync Menu` button).
- **Out-of-Stock / Sold-Out Management:** Change `Available` from `YES` to `NO` in Google Sheets. The website immediately displays the dish with a "Sold Out" badge, disables order buttons, and prevents customers from adding it to cart.
- **Authoritative Server Validation:** In `Code.gs`, `createOrder_` validates ordered items and prices directly against the Google Sheet `Menu` tab, preventing client-side price tampering or ordering of sold-out items.
- **Smart Caching:** Fast sub-millisecond mobile page loads with local file caching (`menu_cache.json` with 120s TTL) and automatic graceful fallback if Google Apps Script is momentarily unreachable.

## 4. Loyalty rules implemented
- A visit is counted only when a pending order is confirmed by the vendor.
- The fifth counted visit gets 50% off the order subtotal.
- A confirmation within 12 hours of the last counted visit is marked cooldown, receives no loyalty stamp, and does not get the fifth-visit discount.
- Pending order tokens expire after 2 hours.
- Only one active pending order per phone number is allowed at a time.
- An order can transition from PENDING to CONFIRMED only once. Duplicate confirmation is rejected under a script lock.

## 5. Live Order Confirmation & Thank You Collection Screen
- When a customer generates an order, their `/confirm` screen displays the order token pass and instructions to present it at the counter.
- While the customer is at the counter, the `/confirm` screen actively listens for vendor confirmation (via fast local polling and Google Apps Script).
- As soon as the attendant confirms the order in the vendor terminal (`/vendor`), the customer's screen automatically transitions to the **Thank You & Collect Order** screen with:
  - Celebration audio chime & haptic feedback.
  - Prominent **"🔔 ORDER CONFIRMED - Please collect your freshly prepared momos at the counter!"** banner.
  - Token and itemized paid order summary.
  - Updated loyalty stamp progress and an option to place another order.

## 6. Vercel deployment
Deploy the entire folder contents. Confirm the PHP runtime supports cURL and `mbstring` (the code uses `mb_strlen`). If `mbstring` is not available, replace `mb_strlen($name)` with `strlen($name)` or enable the extension. PHP cURL must be enabled.

## 6. Smoke tests
1. Open `/menu`, add one item, proceed to checkout and create a token.
2. Confirm a row appears in `Orders` with status `PENDING` and a two-hour expiry.
3. Try creating another order using the same phone while the first is pending; it should be blocked.
4. Open `/vendor`, enter the token and configured PIN. It should become `CONFIRMED`, and `Customers` should gain/update one visit.
5. Submit the same token again; it must be rejected as already confirmed.
6. For a test customer with 4 counted visits and last visit more than 12 hours ago, create and confirm an order; it should receive 50% off and become visit 5.
7. Confirm another order for the same customer within 12 hours; it should be marked cooldown, add no stamp, and receive no discount.

## Important security notes
- Anyone who can access the public web app endpoint can attempt to call it; the vendor PIN protects confirmation, but add Apps Script quotas/monitoring and consider rate limiting if abuse becomes a concern.
- The PIN is stored in Apps Script Script Properties, not frontend code. Use a strong PIN and rotate it periodically.
- The browser receives the pending order token; protect vendor access and do not expose the vendor URL publicly if possible.
- This implementation does not accept payments online. Vendor confirmation records that the order was approved/collected; integrate a payment provider separately if needed.
