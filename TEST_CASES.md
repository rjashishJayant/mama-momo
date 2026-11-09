# Mama Momo - Comprehensive QA Test Cases & Verification Matrix

## 1. System Overview & Test Scope

Mama Momo is a mobile-first momo stall ordering application and automated loyalty tracker with real-time Google Sheets and local cache synchronization. This document provides the definitive test specification covering all user personas (Customer, Stall Vendor Attendant, and Backend/Admin Sheet interference).

```mermaid
flowchart TD
    subgraph Customer Experience
        C1["Enter 10-Digit Mobile Number"] --> C2{"Account Exists?"}
        C2 -->|No| C3["Register: Name + Phone + Optional Address"]
        C2 -->|Yes| C4["Customer Profile Dashboard"]
        C3 --> C4
        C4 -->|Review Profile & Past Orders| C4
        C4 -->|Click 'Place New Order'| C5["Menu Catalog (/menu)"]
        C5 -->|Select Items & Floating Cart| C6["Checkout (/checkout)"]
        C6 -->|No Re-entry of Name/Phone| C7["Order Pass Card (/confirm)"]
        C7 -->|Show Token MMxxxx| V1["Stall Attendant"]
    end

    subgraph Vendor Terminal (/vendor)
        V1 --> V2["Vendor Security PIN (1234)"]
        V2 --> V3["⏳ Unverified Orders Queue<br>(Exclusively PENDING Orders)"]
        V3 -->|Action: Click '⚡ Quick Checkout'| V4["Order Verification Modal"]
        V3 -->|Action: Manual Token Entry| V4
        V4 -->|Verify & Confirm| V5["Google Sheets & Ledger Write"]
        V5 -->|Auto-Drop| V3
        V5 -->|Live Update| C7
    end
```

---

## 2. Test Execution Matrix Summary

| Suite ID | Category | Total Cases | Passed | Failed | Status |
| :--- | :--- | :---: | :---: | :---: | :---: |
| **SUITE 1** | Customer Authentication & Profile | 10 | 10 | 0 | 🟢 **PASSED** |
| **SUITE 2** | Dynamic Menu, Cart & Checkout Integrity | 7 | 7 | 0 | 🟢 **PASSED** |
| **SUITE 3** | Order Token Generation & Pass Lifecycle | 5 | 5 | 0 | 🟢 **PASSED** |
| **SUITE 4** | 5th-Visit Loyalty & 12h Cooldown Engine | 4 | 4 | 0 | 🟢 **PASSED** |
| **SUITE 5** | Vendor Terminal Security & Operational Workflow | 11 | 11 | 0 | 🟢 **PASSED** |
| **SUITE 6** | Admin Direct Sheet & Cache Interference | 4 | 4 | 0 | 🟢 **PASSED** |
| **TOTAL** | **Full System Lifecycle** | **41** | **41** | **0** | 🟢 **100% PASSED** |

---

## 3. Detailed Test Cases by Persona & Scenario

### SUITE 1: Customer Authentication & Profile

| Test ID | Scenario / Feature | Test Input & Steps | Expected Result | Actual Result | Status |
| :--- | :--- | :--- | :--- | :--- | :---: |
| **TC-C01** | Direct unauthenticated access to `/menu` | Navigate directly to `/menu` without an active session | Redirects immediately to `/` (home login screen) | HTTP 303 Redirect to `/` | 🟢 PASSED |
| **TC-C02** | Direct unauthenticated access to `/checkout` | Navigate directly to `/checkout` without an active session | Redirects immediately to `/` (home login screen) | HTTP 303 Redirect to `/` | 🟢 PASSED |
| **TC-C03** | Phone number format normalization | Enter `+91 98223-34455`, `09822334455`, or `98223 34455` | Strips `+91`, leading `0`, spaces, and dashes to store normalized 10 digits (`9822334455`) | Normalized to `9822334455` | 🟢 PASSED |
| **TC-C04** | Invalid phone number validation | Enter `12345`, `abcdefghij`, or `987654321012345` | Client and server-side validation reject input with helpful error message | Error displayed; form blocked | 🟢 PASSED |
| **TC-C05** | Non-existent customer lookup | Enter an unrecorded 10-digit number (e.g. `9199887766`) | System detects `exists: false` and smoothly presents the Registration form | Registration form displayed | 🟢 PASSED |
| **TC-C06** | Brand new customer registration with address | Enter Name: `Priya Sharma`, Phone: `9199887766`, Address: `Table 12, Garden Section` | Account created in cache/sheet, session established, profile dashboard rendered | Account created & profile opened | 🟢 PASSED |
| **TC-C07** | Brand new customer registration without address | Enter Name: `Meera Nair`, Phone: `8887776656`, Address: `""` (left blank) | Account created successfully; address remains optional for delivery only | Created without error | 🟢 PASSED |
| **TC-C08** | Existing customer login | Enter recorded phone number `9822334455` | Directly opens customer profile view showing `Vikram Roy` without asking to register | Profile opened instantly | 🟢 PASSED |
| **TC-C09** | Profile past orders list | Log in as customer with past transactions | Itemized list of previous orders displayed with Token, items, amount ₹, and status badges (`Approved` / `Pending`) | All past orders rendered cleanly | 🟢 PASSED |
| **TC-C10** | Logout / Switch mobile number | Click *"Switch Mobile Number / Log Out"* | Clears session `$_SESSION['customer']` and returns to phone login form | Session cleared; redirected to login | 🟢 PASSED |

---

### SUITE 2: Dynamic Menu, Cart & Checkout Integrity

| Test ID | Scenario / Feature | Test Input & Steps | Expected Result | Actual Result | Status |
| :--- | :--- | :--- | :--- | :--- | :---: |
| **TC-M01** | Authenticated Menu Greeting | Navigate to `/menu` as logged-in user | Header renders: `👋 Ordering as Priya Sharma (9199887766)` with `[👤 Profile]` shortcut | Greeting bar displayed | 🟢 PASSED |
| **TC-M02** | Draft items hidden from customers | Check customer menu catalog | Items with `viewOnMenu: NO` or `status: draft` are strictly excluded from catalog | 0 draft items visible to customers | 🟢 PASSED |
| **TC-M03** | Menu pricing & details integrity | Inspect menu item fields | Name, description, category tag, and positive price in ₹ are rendered correctly | Valid prices & descriptions | 🟢 PASSED |
| **TC-M04** | Dynamic cart calculation | Add 2 × Steamed Veg (₹50) + 1 × Crispy Fried (₹60) | Live cart floating bar updates subtotal to exact sum: ₹160.00 | Subtotal ₹160.00 displayed | 🟢 PASSED |
| **TC-M05** | Checkout pre-authentication | Proceed from menu to `/checkout` | Verified customer card displays pre-authenticated Name, Mobile, and Delivery Address. **Does not ask again for Name & Phone** | Name & Phone pre-filled; no inputs | 🟢 PASSED |
| **TC-M06** | Empty cart submission prevention | Clear cart and attempt to submit checkout form | Alert blocks checkout and redirects user back to `/menu` | Blocked with alert | 🟢 PASSED |
| **TC-M07** | Price tampering prevention | Submit modified client-side price payload | Server-side recalculates authoritative price from catalog and rejects mismatch | Authoritative recalculation enforced | 🟢 PASSED |

---

### SUITE 3: Order Token Generation & Pass Lifecycle

| Test ID | Scenario / Feature | Test Input & Steps | Expected Result | Actual Result | Status |
| :--- | :--- | :--- | :--- | :--- | :---: |
| **TC-O01** | 6-Character token generation | Click *"Generate Order Token"* | Token adheres strictly to `MM` followed by 4 digits (e.g. `MM7547`) | Generated valid `MMxxxx` token | 🟢 PASSED |
| **TC-O02** | Initial order state | Check created order in database / cache | Transaction created under status `PENDING` | `status: "PENDING"` recorded | 🟢 PASSED |
| **TC-O03** | Order pass card presentation | Review `/confirm` screen | Displays token in large font, itemized breakdown, payable counter amount, and QR code | Pass card rendered properly | 🟢 PASSED |
| **TC-O04** | Real-time status listener | Background polling `action=check_status` | Returns `{ confirmed: false, status: "PENDING" }` while order is awaiting vendor counter verification | Returns PENDING | 🟢 PASSED |
| **TC-O05** | Browser reload resilience (PRG) | Refresh `/confirm` in browser | Post-Redirect-Get pattern restores session order without re-triggering order creation | Order maintained; no duplicate rows | 🟢 PASSED |

---

### SUITE 4: 5th-Visit Loyalty & 12-Hour Cooldown Engine

| Test ID | Scenario / Feature | Test Input & Steps | Expected Result | Actual Result | Status |
| :--- | :--- | :--- | :--- | :--- | :---: |
| **TC-L01** | Standard visit pricing | Customer on visit 1 to 4 places order | Regular menu pricing is charged; projected visit increments normally | Regular pricing applied | 🟢 PASSED |
| **TC-L02** | 5th Visit 50% Off reward | Customer with 4 completed visits orders | System flags `isDiscount: true` and cuts payable total by 50% (`finalPrice = totalAmount * 0.5`) | 50% discount applied automatically | 🟢 PASSED |
| **TC-L03** | 12-Hour cooldown enforcement | Customer places 2nd order within 12 hours of prior verified visit | Order is fulfilled and verified under `APPROVED_COOLDOWN`; **no extra loyalty stamp granted** | Protected against repeat stamps | 🟢 PASSED |
| **TC-L04** | Cooldown window expiration | Customer returns >12 hours after prior visit | Cooldown flag clears; visit count increments by +1 | Stamp counter increments | 🟢 PASSED |

---

### SUITE 5: Vendor Terminal Security & Operational Workflow

| Test ID | Scenario / Feature | Test Input & Steps | Expected Result | Actual Result | Status |
| :--- | :--- | :--- | :--- | :--- | :---: |
| **TC-V01** | Security gate enforcement | Navigate to `/vendor` | Attendant is presented with PIN lock screen (`vendorGateScreen`); dashboard is hidden | Terminal locked | 🟢 PASSED |
| **TC-V02** | Incorrect Vendor PIN rejection | Enter PIN `0000` or `9999` | Access denied with error: *"Incorrect Vendor PIN. Access denied."* | Error message displayed | 🟢 PASSED |
| **TC-V03** | Correct Vendor PIN authorization | Enter PIN `1234` | Terminal unlocks; attendant dashboard becomes visible | Terminal unlocked | 🟢 PASSED |
| **TC-V04** | Unverified Orders Queue filter | Observe active orders queue in terminal | Lists **only orders whose status is `PENDING`**. Verified orders are excluded | Only pending orders shown | 🟢 PASSED |
| **TC-V05** | Unverified queue order details | Check order card in queue | Shows Token ID, Customer Name, Mobile, Address (if set), items, and payable amount ₹ | All details visible | 🟢 PASSED |
| **TC-V06** | "⚡ Quick Checkout" button action | Click *"⚡ Quick Checkout"* on order card | Order details popup modal opens immediately with customer info, items, payable amount, and pre-filled PIN | Modal opened with details | 🟢 PASSED |
| **TC-V07** | Order confirmation & ledger update | Click *"✅ Confirm & Save to Sheet"* in modal | Order status updated to `APPROVED` in Google Sheets and cache; visit number stamped | Saved and approved | 🟢 PASSED |
| **TC-V08** | Real-time queue removal | Observe queue after order confirmation | Confirmed order automatically drops out of the Unverified Orders Queue | Order dropped from queue | 🟢 PASSED |
| **TC-V09** | Live customer screen confirmation | Inspect customer's `/confirm` screen | Real-time listener detects confirmation and updates to *"🔔 ORDER CONFIRMED - Please collect your momos"* | Screen updated live | 🟢 PASSED |
| **TC-V10** | Manual token entry checkout | Enter token `MMxxxx` into *"ENTER CUSTOMER TOKEN"* and click *"🔍 Check Order"* | Preserved manual search finds order and opens verification modal | Modal opened via manual search | 🟢 PASSED |
| **TC-V11** | Non-existent manual token lookup | Enter unknown token `MM0000` | Alert displays: *"Order token MM0000 was not found. Please verify token from customer's pass."* | Alert shown | 🟢 PASSED |
| **TC-V12** | All Orders history & date filters | Click *"📜 All Orders"*, enter PIN | Opens modal with metrics (Orders, Revenue, Discounts) and filter pills (`Today`, `Yesterday`, `Last 7 Days`, `All Time`) | Dashboard rendered with filters | 🟢 PASSED |
| **TC-V13** | Terminal Lock button | Click *"🔒 Lock"* in top bar | Clears session authentication and returns terminal to PIN gate screen | Terminal secured | 🟢 PASSED |

---

### SUITE 6: Admin Direct Sheet & Cache Interference

| Test ID | Scenario / Feature | Test Input & Steps | Expected Result | Actual Result | Status |
| :--- | :--- | :--- | :--- | :--- | :---: |
| **TC-A01** | Direct insertion of pending order in Sheet/Cache | Admin injects new order with status `PENDING` directly into Google Sheet or `orders_cache.json` | Vendor Terminal detects new order on next poll and displays it in the Unverified Queue | Appears in Unverified Queue | 🟢 PASSED |
| **TC-A02** | Direct admin approval in Sheet/Cache | Admin modifies order status from `PENDING` to `APPROVED` directly in Sheet | Order is excluded from Unverified Queue on next refresh | Dropped from queue | 🟢 PASSED |
| **TC-A03** | Direct menu price change in Sheet | Admin updates price in Google Sheet `Menu` tab | Cache refreshes; customer menu dynamically shows the new updated price | New price adopted | 🟢 PASSED |
| **TC-A04** | Offline / Slow Google Sheets resilience | Google Sheets API experiences network delay or timeout | PHP backend falls back to local disk caches (`menu_cache.json`, `orders_cache.json`) without throwing 500 error | 100% uptime with local cache | 🟢 PASSED |

---

## 4. Security & Data Protection Guarantees

1. **XSS & Output Sanitization**:
   * All dynamic customer names, phone numbers, addresses, and menu descriptions are sanitized through `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`.
2. **Timing-Attack Resilient PIN Verification**:
   * Apps Script and PHP verify the vendor passkey using constant-time comparison (`constantTimeEquals_`) to prevent side-channel timing attacks.
3. **Session & Order Isolation**:
   * Customer sessions strictly isolate order generation so one customer cannot tamper with or submit orders under another customer's identity.
4. **Script Lock Concurrency**:
   * Google Apps Script implements a 15-second concurrency lock (`LockService.getScriptLock().tryLock(15000)`) preventing race conditions during peak stall rushes.
