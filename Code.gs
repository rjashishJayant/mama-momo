/**
 * Mama Momo - Google Sheets & Apps Script Loyalty Tracker & Dynamic Menu Manager
 * Tabs:
 *   - Customers: Phone | Name | VisitCount | LastVisit
 *   - Orders: Timestamp | OrderID | Phone | Name | Items | TotalAmount | VisitNumber | DiscountApplied | Status
 *   - Menu: ID | Name | Price | Description | Category | Available | View on Menu
 */

const COOLDOWN_HOURS = 12;
const DEFAULT_VENDOR_PIN = '1234';
const CUSTOMER_HEADERS = ['Phone', 'Name', 'VisitCount', 'LastVisit'];
const ORDER_HEADERS = ['Timestamp', 'OrderID', 'Phone', 'Name', 'Items', 'TotalAmount', 'VisitNumber', 'DiscountApplied', 'Status'];
const MENU_HEADERS = ['ID', 'Name', 'Price', 'Description', 'Category', 'Available', 'View on Menu'];

const DEFAULT_MENU_ITEMS = [
  [1, 'Classic Steamed Veg Momo', 80, 'Fresh cabbage, paneer, and Himalayan herbs', 'Steamed Veg', 'YES', 'YES'],
  [2, 'Steamed Chicken Momo', 120, 'Juicy minced chicken infused with mild spices', 'Steamed Non-Veg', 'YES', 'YES'],
  [3, 'Crispy Fried Veg Momo', 100, 'Golden fried crunchy veg dumplings with spicy chutney', 'Fried Veg', 'YES', 'YES'],
  [4, 'Crispy Fried Chicken Momo', 140, 'Deep fried crisp chicken momos served hot', 'Fried Non-Veg', 'YES', 'YES'],
  [5, 'Jhol Momo Soup Bowl', 130, 'Steamed momos dipped in spicy sesame-tomato jhol broth', 'Specialties', 'YES', 'YES']
];

function doGet(e) {
  try {
    const action = String((e && e.parameter && e.parameter.action) || '');
    if (action === 'health') {
      return jsonResponse({ success: true, service: 'Mama Momo API', version: '2.4' });
    }
    const ss = SpreadsheetApp.getActiveSpreadsheet();

    // Fix and verify schemas across all tabs
    if (action === 'fixSchema') {
      ensureSheet_(ss, 'Customers', CUSTOMER_HEADERS);
      ensureSheet_(ss, 'Orders', ORDER_HEADERS);
      ensureMenuSheet_(ss);
      return jsonResponse({ success: true, message: 'Schemas (Customers, Orders, Menu) verified successfully' });
    }

    // Dynamic Menu fetch action
    if (action === 'getMenu' || action === 'menu') {
      const menu = getMenu_(ss);
      return jsonResponse({
        success: true,
        count: menu.length,
        menu: menu,
        updatedAt: new Date().toISOString()
      });
    }

    // Fetch latest 5 orders for vendor terminal feed
    if (action === 'getLatestOrders') {
      const ordersSheet = ensureSheet_(ss, 'Orders', ORDER_HEADERS);
      const lastRow = ordersSheet.getLastRow();
      const list = [];
      if (lastRow > 1) {
        const data = ordersSheet.getRange(2, 1, lastRow - 1, 9).getValues();
        for (let i = data.length - 1; i >= 0 && list.length < 5; i--) {
          const rowOrderId = String(data[i][1] || '').trim().toUpperCase();
          if (!rowOrderId) continue;
          const rawDate = data[i][0];
          let dateStr = '';
          let timeStr = '';
          if (rawDate instanceof Date && !isNaN(rawDate.getTime())) {
            const y = rawDate.getFullYear();
            const m = String(rawDate.getMonth() + 1).padStart(2, '0');
            const d = String(rawDate.getDate()).padStart(2, '0');
            dateStr = y + '-' + m + '-' + d;
            timeStr = rawDate.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: true });
          } else if (rawDate) {
            dateStr = String(rawDate);
          }
          list.push({
            orderId: rowOrderId,
            name: String(data[i][3] || ''),
            phone: normalizePhone(data[i][2]),
            items: String(data[i][4] || ''),
            amount: parseFloat(data[i][5]) || 0,
            visit: parseInt(data[i][6], 10) || 1,
            time: timeStr,
            date: dateStr,
            status: String(data[i][8] || 'PENDING')
          });
        }
      }
      return jsonResponse({ success: true, count: list.length, orders: list });
    }

    // Fetch unverified (PENDING) orders only for vendor queue
    if (action === 'getUnverifiedOrders') {
      const ordersSheet = ensureSheet_(ss, 'Orders', ORDER_HEADERS);
      const lastRow = ordersSheet.getLastRow();
      const list = [];
      if (lastRow > 1) {
        const data = ordersSheet.getRange(2, 1, lastRow - 1, 9).getValues();
        for (let i = data.length - 1; i >= 0; i--) {
          const rowStatus = String(data[i][8] || 'PENDING').trim().toUpperCase();
          if (rowStatus === 'PENDING') {
            const rawDate = data[i][0];
            let dateStr = '';
            let timeStr = '';
            if (rawDate instanceof Date && !isNaN(rawDate.getTime())) {
              const y = rawDate.getFullYear();
              const m = String(rawDate.getMonth() + 1).padStart(2, '0');
              const d = String(rawDate.getDate()).padStart(2, '0');
              dateStr = y + '-' + m + '-' + d;
              timeStr = rawDate.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: true });
            } else if (rawDate) {
              dateStr = String(rawDate);
            }
            list.push({
              orderId: String(data[i][1] || '').trim().toUpperCase(),
              phone: normalizePhone(data[i][2]),
              name: String(data[i][3] || ''),
              items: String(data[i][4] || ''),
              itemsDisplay: String(data[i][4] || ''),
              totalAmount: parseFloat(data[i][5]) || 0,
              finalPrice: parseFloat(data[i][5]) || 0,
              visitNumber: parseInt(data[i][6], 10) || 1,
              discountApplied: String(data[i][7] || 'NO').toUpperCase() === 'YES',
              status: 'PENDING',
              time: timeStr,
              date: dateStr
            });
          }
        }
      }
      return jsonResponse({ success: true, count: list.length, orders: list });
    }

    // Fetch all orders with vendor password protection
    if (action === 'getOrders' || action === 'getAllOrders') {
      const pin = String((e && e.parameter && e.parameter.pin) || '').trim();
      const configuredPin = PropertiesService.getScriptProperties().getProperty('VENDOR_PIN') || DEFAULT_VENDOR_PIN;
      if (!constantTimeEquals_(pin, configuredPin)) {
        return jsonResponse({ success: false, message: 'Invalid vendor password/PIN.' });
      }

      const ordersSheet = ensureSheet_(ss, 'Orders', ORDER_HEADERS);
      const lastRow = ordersSheet.getLastRow();
      const list = [];
      if (lastRow > 1) {
        const data = ordersSheet.getRange(2, 1, lastRow - 1, 9).getValues();
        for (let i = data.length - 1; i >= 0; i--) {
          const rowOrderId = String(data[i][1] || '').trim().toUpperCase();
          if (!rowOrderId) continue;
          const rawDate = data[i][0];
          let dateStr = '';
          let timeStr = '';
          let isoDate = '';
          if (rawDate instanceof Date && !isNaN(rawDate.getTime())) {
            isoDate = rawDate.toISOString();
            const y = rawDate.getFullYear();
            const m = String(rawDate.getMonth() + 1).padStart(2, '0');
            const d = String(rawDate.getDate()).padStart(2, '0');
            dateStr = y + '-' + m + '-' + d;
            timeStr = rawDate.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: true });
          } else if (rawDate) {
            dateStr = String(rawDate).split('T')[0] || String(rawDate);
            isoDate = String(rawDate);
          }

          list.push({
            orderId: rowOrderId,
            timestamp: isoDate,
            date: dateStr,
            time: timeStr,
            phone: normalizePhone(data[i][2]),
            name: String(data[i][3] || ''),
            items: String(data[i][4] || ''),
            totalAmount: parseFloat(data[i][5]) || 0,
            visitNumber: parseInt(data[i][6], 10) || 1,
            discountApplied: String(data[i][7] || 'NO').toUpperCase() === 'YES',
            status: String(data[i][8] || 'PENDING')
          });
        }
      }

      return jsonResponse({
        success: true,
        count: list.length,
        orders: list
      });
    }

    if (action === 'getOrder') {
      const orderId = String((e && e.parameter && e.parameter.orderId) || '').trim().toUpperCase();
      if (!orderId) return jsonResponse({ success: false, message: 'Missing orderId parameter.' });
      const orders = ensureSheet_(ss, 'Orders', ORDER_HEADERS);
      const order = findOrder_(orders, orderId);
      if (!order) {
        return jsonResponse({ success: false, found: false, message: 'Order token not found.' });
      }
      return jsonResponse({
        success: true,
        found: true,
        orderId: order.orderId,
        phone: order.phone,
        name: order.name,
        items: order.items,
        itemsDisplay: order.items,
        totalAmount: order.totalAmount,
        finalPrice: order.totalAmount,
        discountApplied: order.discountApplied === 'YES',
        status: order.status
      });
    }

    if (action === 'checkCustomer') {
      const phone = normalizePhone(e.parameter.phone || '');
      if (!phone) return jsonResponse({ success: false, message: 'Enter a valid 10-digit mobile number.' });
      const customer = findCustomer_(ensureSheet_(ss, 'Customers', CUSTOMER_HEADERS), phone);
      const now = new Date();
      const cooldown = customer.lastVisit && (now.getTime() - customer.lastVisit.getTime()) < COOLDOWN_HOURS * 3600000;
      const nextVisit = customer.visitCount + (cooldown ? 0 : 1);
      const isDiscount = !cooldown && nextVisit > 0 && nextVisit % 5 === 0;
      const remainingVisits = isDiscount ? 0 : (5 - (nextVisit % 5));
      return jsonResponse({
        success: true,
        found: customer.row > 0,
        name: customer.name,
        currentVisits: customer.visitCount,
        nextVisit: nextVisit,
        remainingVisits: remainingVisits,
        willGetDiscount: isDiscount,
        isCooldown: !!cooldown
      });
    }

    return jsonResponse({ success: false, message: 'Invalid action.' });
  } catch (err) {
    return jsonResponse({ success: false, message: safeError_(err) });
  }
}

function doPost(e) {
  const lock = LockService.getScriptLock();
  // Concurrency Control: 15-second timeout on write operations
  if (!lock.tryLock(15000)) {
    return jsonResponse({ success: false, message: 'The ordering system is busy. Please retry in a moment.' });
  }
  try {
    if (!e || !e.postData || !e.postData.contents) {
      return jsonResponse({ success: false, message: 'Missing request body.' });
    }
    const data = JSON.parse(e.postData.contents);
    const action = String(data.action || '');
    const ss = SpreadsheetApp.getActiveSpreadsheet();
    const customers = ensureSheet_(ss, 'Customers', CUSTOMER_HEADERS);
    const orders = ensureSheet_(ss, 'Orders', ORDER_HEADERS);

    if (action === 'getMenu' || action === 'menu') {
      const menu = getMenu_(ss);
      return jsonResponse({ success: true, count: menu.length, menu: menu });
    }
    if (action === 'createOrder') {
      return createOrder_(data, customers, orders, ss);
    }
    if (action === 'confirmOrder') {
      return confirmOrder_(data, customers, orders);
    }
    if (action === 'getOrder') {
      const orderId = String(data.orderId || data.token || '').trim().toUpperCase();
      const order = findOrder_(orders, orderId);
      if (!order) return jsonResponse({ success: false, found: false, message: 'Order token not found.' });
      return jsonResponse({
        success: true,
        found: true,
        orderId: order.orderId,
        phone: order.phone,
        name: order.name,
        items: order.items,
        itemsDisplay: order.items,
        totalAmount: order.totalAmount,
        finalPrice: order.totalAmount,
        discountApplied: order.discountApplied === 'YES',
        status: order.status
      });
    }
    return jsonResponse({ success: false, message: 'Unknown action.' });
  } catch (err) {
    return jsonResponse({ success: false, message: safeError_(err) });
  } finally {
    lock.releaseLock();
  }
}

/**
 * Validates incoming request, generates a 6-character token (e.g., MM4219),
 * recalculates authoritative price from Menu sheet if items are provided,
 * stores pending order so token manages customer data, and returns the payload.
 */
function createOrder_(data, customers, orders, ss) {
  const name = String(data.name || '').trim();
  const phone = normalizePhone(data.phone || '');
  if (!name || name.length > 80 || !phone) {
    return jsonResponse({ success: false, message: 'Enter a valid name and 10-digit mobile number.' });
  }

  let itemsDisplay = String(data.itemsDisplay || data.cart_items || '').trim();
  if (!itemsDisplay && Array.isArray(data.items)) {
    itemsDisplay = data.items.map(function(item) {
      return (item.qty || 1) + ' × ' + (item.name || 'Momo');
    }).join(', ');
  }
  if (!itemsDisplay && typeof data.items === 'string') {
    itemsDisplay = data.items.trim();
  }
  if (!itemsDisplay) {
    itemsDisplay = 'Momo Selection';
  }

  const rawTotal = parseFloat(data.totalAmount !== undefined ? data.totalAmount : data.total_amount);
  let totalAmount = (!isNaN(rawTotal) && rawTotal > 0) ? roundMoney_(rawTotal) : 0;

  // Authoritative validation against Menu sheet when items array is provided
  if (Array.isArray(data.items) && data.items.length > 0 && ss) {
    try {
      const menuList = getMenu_(ss);
      const menuMap = {};
      for (let m = 0; m < menuList.length; m++) {
        menuMap[String(menuList[m].id)] = menuList[m];
      }

      let calculated = 0;
      let matchedItems = 0;
      for (let j = 0; j < data.items.length; j++) {
        const it = data.items[j];
        if (!it) continue;
        const itId = String(it.id !== undefined ? it.id : '');
        const itQty = parseInt(it.qty, 10) || 0;
        if (itQty > 0 && menuMap[itId]) {
          if (menuMap[itId].viewOnMenu === false) {
            return jsonResponse({
              success: false,
              message: 'Sorry, "' + menuMap[itId].name + '" is currently in draft mode and not available for ordering.'
            });
          }
          if (!menuMap[itId].available) {
            return jsonResponse({
              success: false,
              message: 'Sorry, "' + menuMap[itId].name + '" is currently out of stock.'
            });
          }
          calculated += itQty * menuMap[itId].price;
          matchedItems++;
        }
      }

      if (matchedItems > 0 && calculated > 0) {
        totalAmount = roundMoney_(calculated);
      }
    } catch (_) {
      // Fallback to submitted total if Menu read is not available
    }
  }

  if (totalAmount <= 0) {
    return jsonResponse({ success: false, message: 'Invalid order amount.' });
  }

  const now = new Date();
  const customer = findCustomer_(customers, phone);
  const cooldown = isInCooldown_(customer.lastVisit, now);
  const projectedVisit = customer.visitCount + (cooldown ? 0 : 1);
  const isDiscount = !cooldown && projectedVisit > 0 && (projectedVisit % 5 === 0);
  const finalPrice = isDiscount ? roundMoney_(totalAmount * 0.5) : totalAmount;
  const remainingVisits = isDiscount ? 0 : (5 - (projectedVisit % 5));

  // Generate 6-character token (e.g. MM4219)
  const orderId = generate6CharToken_();

  // Store order under token with PENDING status in the 9 directed columns
  orders.appendRow([
    now,
    orderId,
    phone,
    name,
    itemsDisplay,
    finalPrice,
    projectedVisit,
    isDiscount ? 'YES' : 'NO',
    'PENDING'
  ]);

  return jsonResponse({
    success: true,
    orderId: orderId,
    name: name,
    phone: phone,
    items: itemsDisplay,
    itemsDisplay: itemsDisplay,
    totalAmount: totalAmount,
    finalPrice: finalPrice,
    projectedVisit: projectedVisit,
    currentVisits: customer.visitCount,
    remainingVisits: remainingVisits,
    isDiscount: isDiscount,
    isCooldown: cooldown
  });
}

/**
 * Enforces VENDOR_PIN (default: "1234").
 * Automatically manages customer data via the order token (or uses passed fields),
 * increments VisitCount on Customers tab, and finalizes row in Orders tab (9 directed columns).
 */
function confirmOrder_(data, customers, orders) {
  const configuredPin = PropertiesService.getScriptProperties().getProperty('VENDOR_PIN') || DEFAULT_VENDOR_PIN;
  const pin = String(data.pin || '').trim();
  if (!constantTimeEquals_(pin, configuredPin)) {
    return jsonResponse({ success: false, message: 'Invalid vendor PIN.' });
  }

  const orderId = String(data.orderId || data.token || '').trim().toUpperCase();
  if (!orderId || orderId.length < 4 || orderId.length > 12) {
    return jsonResponse({ success: false, message: 'Invalid order token.' });
  }

  // Lookup existing order managed by token
  const existing = findOrder_(orders, orderId);

  let phone = normalizePhone(data.phone || (existing ? existing.phone : ''));
  let name = String(data.name || (existing ? existing.name : '')).trim();
  let items = String(data.items || data.itemsDisplay || (existing ? existing.items : '')).trim();

  let rawAmount = parseFloat(data.totalAmount !== undefined ? data.totalAmount : data.collectedAmount);
  if (isNaN(rawAmount) && existing) {
    rawAmount = existing.totalAmount;
  }
  const totalAmount = (!isNaN(rawAmount) && rawAmount >= 0) ? roundMoney_(rawAmount) : 0;

  const discountApplied = (data.discountApplied !== undefined || data.discountGiven !== undefined)
    ? !!(data.discountApplied || data.discountGiven)
    : (existing ? (existing.discountApplied === 'YES') : false);

  if (!phone) {
    return jsonResponse({ success: false, message: 'Could not resolve customer phone for this order token. Please verify token.' });
  }
  if (!name) name = 'Customer';
  if (!items) items = 'Momo Selection';

  const now = new Date();
  const customer = findCustomer_(customers, phone);
  const cooldown = isInCooldown_(customer.lastVisit, now);

  let visitNumber = customer.visitCount;
  let status = 'APPROVED';

  if (cooldown) {
    status = 'APPROVED_COOLDOWN';
  } else {
    visitNumber = customer.visitCount + 1;
    if (customer.row > 0) {
      customers.getRange(customer.row, 1, 1, 4).setValues([[phone, name, visitNumber, now]]);
    } else {
      customers.appendRow([phone, name, visitNumber, now]);
    }
  }

  // Row columns: Timestamp | OrderID | Phone | Name | Items | TotalAmount | VisitNumber | DiscountApplied | Status
  if (existing && existing.row > 0) {
    orders.getRange(existing.row, 1, 1, 9).setValues([[
      now,
      orderId,
      phone,
      name,
      items,
      totalAmount,
      visitNumber,
      discountApplied ? 'YES' : 'NO',
      status
    ]]);
  } else {
    orders.appendRow([
      now,
      orderId,
      phone,
      name,
      items,
      totalAmount,
      visitNumber,
      discountApplied ? 'YES' : 'NO',
      status
    ]);
  }

  return jsonResponse({
    success: true,
    message: 'Order successfully confirmed and saved to Sheet!',
    orderId: orderId,
    phone: phone,
    name: name,
    items: items,
    totalAmount: totalAmount,
    visitNumber: visitNumber,
    status: status,
    discountApplied: discountApplied,
    isCooldown: cooldown
  });
}

/**
 * Ensures the 'Menu' tab exists. Automatically initializes headers and default
 * items if the sheet was newly created.
 */
function ensureMenuSheet_(ss) {
  let sheet = ss.getSheetByName('Menu');
  if (!sheet) {
    sheet = ss.insertSheet('Menu');
  }
  if (sheet.getLastRow() === 0) {
    sheet.getRange(1, 1, 1, MENU_HEADERS.length).setValues([MENU_HEADERS]);
    sheet.getRange(2, 1, DEFAULT_MENU_ITEMS.length, MENU_HEADERS.length).setValues(DEFAULT_MENU_ITEMS);
    sheet.getRange(1, 1, 1, MENU_HEADERS.length).setFontWeight('bold');
    sheet.setFrozenRows(1);
    try {
      sheet.autoResizeColumns(1, MENU_HEADERS.length);
    } catch (_) {}
  } else {
    // Self-healing: Ensure 'View on Menu' column exists
    const lastCol = sheet.getLastColumn();
    const headers = sheet.getRange(1, 1, 1, Math.max(lastCol, 1)).getValues()[0].map(function(h) {
      return String(h || '').trim().toLowerCase();
    });
    let hasViewOnMenu = false;
    for (let c = 0; c < headers.length; c++) {
      const h = headers[c];
      if (h === 'view on menu' || h === 'view on menu?' || h === 'visible' || h === 'status' || h === 'draft' || h === 'show on menu') {
        hasViewOnMenu = true;
        break;
      }
    }
    if (!hasViewOnMenu) {
      const targetCol = Math.max(lastCol + 1, 7);
      sheet.getRange(1, targetCol).setValue('View on Menu').setFontWeight('bold');
      const lastRow = sheet.getLastRow();
      if (lastRow > 1) {
        const fillVals = [];
        for (let r = 2; r <= lastRow; r++) {
          fillVals.push(['YES']);
        }
        sheet.getRange(2, targetCol, lastRow - 1, 1).setValues(fillVals);
      }
    }
  }
  return sheet;
}

/**
 * Reads all menu items from the 'Menu' tab.
 */
function getMenu_(ss) {
  const sheet = ensureMenuSheet_(ss);
  const lastRow = sheet.getLastRow();
  if (lastRow <= 1) return [];

  const lastCol = Math.max(sheet.getLastColumn(), MENU_HEADERS.length);
  const headerRow = sheet.getRange(1, 1, 1, lastCol).getValues()[0];

  let colId = 0, colName = 1, colPrice = 2, colDesc = 3, colCat = 4, colAvail = 5, colView = 6;
  let isDraftColumn = false;

  for (let c = 0; c < headerRow.length; c++) {
    const h = String(headerRow[c] || '').trim().toLowerCase();
    if (h === 'id') colId = c;
    else if (h === 'name' || h === 'item' || h === 'item name') colName = c;
    else if (h === 'price' || h === 'rate' || h === 'amount') colPrice = c;
    else if (h === 'description' || h === 'desc') colDesc = c;
    else if (h === 'category' || h === 'cat' || h === 'type') colCat = c;
    else if (h === 'available' || h === 'in stock' || h === 'instock') colAvail = c;
    else if (h === 'view on menu' || h === 'view on menu?' || h === 'visible' || h === 'show on menu' || h === 'status' || h === 'published') {
      colView = c;
      isDraftColumn = false;
    } else if (h === 'draft' || h === 'is draft' || h === 'is_draft') {
      colView = c;
      isDraftColumn = true;
    }
  }

  const data = sheet.getRange(2, 1, lastRow - 1, lastCol).getValues();
  const menu = [];

  for (let i = 0; i < data.length; i++) {
    const row = data[i];
    const name = String(row[colName] || '').trim();
    if (!name) continue; // Skip empty rows

    const rawId = row[colId];
    const id = (rawId !== '' && rawId !== null && rawId !== undefined) ? String(rawId).trim() : String(i + 1);
    const rawPrice = row[colPrice];
    const price = (typeof rawPrice === 'number') ? rawPrice : (parseFloat(String(rawPrice).replace(/[^0-9.]/g, '')) || 0);
    const desc = String(row[colDesc] || '').trim();
    const category = String(row[colCat] || 'General').trim();
    
    // Available / Stock
    const availVal = String(row[colAvail] !== undefined && row[colAvail] !== null ? row[colAvail] : 'YES').trim().toUpperCase();
    const available = !(availVal === 'NO' || availVal === 'FALSE' || availVal === '0' || availVal === 'OUT OF STOCK' || availVal === 'INACTIVE');

    // View on Menu / Draft
    let viewOnMenu = true;
    let status = 'published';
    const viewRaw = (colView < row.length && row[colView] !== undefined && row[colView] !== null) ? String(row[colView]).trim().toUpperCase() : '';

    if (isDraftColumn) {
      if (viewRaw === 'YES' || viewRaw === 'TRUE' || viewRaw === '1' || viewRaw === 'DRAFT') {
        viewOnMenu = false;
        status = 'draft';
      } else {
        viewOnMenu = true;
        status = 'published';
      }
    } else {
      if (viewRaw === 'NO' || viewRaw === 'FALSE' || viewRaw === '0' || viewRaw === 'DRAFT' || viewRaw === 'HIDE' || viewRaw === 'HIDDEN' || viewRaw === 'OFF') {
        viewOnMenu = false;
        status = 'draft';
      } else {
        viewOnMenu = true;
        status = 'published';
      }
    }

    menu.push({
      id: id,
      name: name,
      price: price,
      desc: desc,
      category: category,
      available: available,
      viewOnMenu: viewOnMenu,
      status: status
    });
  }

  return menu;
}

function normalizePhone(value) {
  const digits = String(value || '').replace(/\D/g, '');
  return /^[0-9]{10}$/.test(digits) ? digits : '';
}

function ensureSheet_(ss, name, headers) {
  let sheet = ss.getSheetByName(name);
  if (!sheet) {
    sheet = ss.insertSheet(name);
  }
  if (sheet.getLastRow() === 0) {
    sheet.getRange(1, 1, 1, headers.length).setValues([headers]);
    return sheet;
  }

  // Self-healing duplicate column detection (e.g. extra Phone column in Orders)
  if (name === 'Orders') {
    const lastCol = sheet.getLastColumn();
    if (lastCol > headers.length) {
      const currentHeaders = sheet.getRange(1, 1, 1, lastCol).getValues()[0];
      let phoneIndices = [];
      for (let c = 0; c < currentHeaders.length; c++) {
        if (String(currentHeaders[c]).trim().toLowerCase() === 'phone') {
          phoneIndices.push(c + 1); // 1-based column index
        }
      }
      if (phoneIndices.length > 1) {
        const lastRow = sheet.getLastRow();
        let colFirstEmpty = true;
        if (lastRow > 1) {
          const sampleRows = Math.min(lastRow - 1, 20);
          const c1Vals = sheet.getRange(2, phoneIndices[0], sampleRows, 1).getValues();
          const filled = c1Vals.filter(function(r) { return String(r[0]).trim() !== ''; });
          if (filled.length > 3) {
            colFirstEmpty = false;
          }
        }
        sheet.deleteColumn(colFirstEmpty ? phoneIndices[0] : phoneIndices[1]);
      }
    }
  }

  // Strictly enforce directed headers on Row 1
  sheet.getRange(1, 1, 1, headers.length).setValues([headers]);
  return sheet;
}

function findCustomer_(sheet, phone) {
  const lastRow = sheet.getLastRow();
  if (lastRow <= 1) {
    return { row: 0, phone: phone, name: '', visitCount: 0, lastVisit: null };
  }
  const data = sheet.getRange(2, 1, lastRow - 1, 4).getValues();
  for (let i = 0; i < data.length; i++) {
    const rowPhone = normalizePhone(data[i][0]);
    if (rowPhone === phone) {
      const visitCount = parseInt(data[i][2], 10);
      return {
        row: i + 2,
        phone: rowPhone,
        name: String(data[i][1] || '').trim(),
        visitCount: isNaN(visitCount) ? 0 : visitCount,
        lastVisit: parseDate_(data[i][3])
      };
    }
  }
  return { row: 0, phone: phone, name: '', visitCount: 0, lastVisit: null };
}

function findOrder_(sheet, orderId) {
  const lastRow = sheet.getLastRow();
  if (lastRow <= 1) return null;
  // Read first 9 columns: Timestamp, OrderId, Phone, Name, Items, TotalAmount, VisitNumber, DiscountApplied, Status
  const data = sheet.getRange(2, 1, lastRow - 1, 9).getValues();
  for (let i = data.length - 1; i >= 0; i--) {
    const rowOrderId = String(data[i][1] || '').trim().toUpperCase();
    if (rowOrderId === orderId) {
      return {
        row: i + 2,
        timestamp: data[i][0],
        orderId: rowOrderId,
        phone: normalizePhone(data[i][2]),
        name: String(data[i][3] || ''),
        items: String(data[i][4] || ''),
        totalAmount: parseFloat(data[i][5]) || 0,
        visitNumber: parseInt(data[i][6], 10) || 1,
        discountApplied: String(data[i][7] || 'NO').toUpperCase(),
        status: String(data[i][8] || '')
      };
    }
  }
  return null;
}

function generate6CharToken_() {
  const digits = '0123456789';
  let token = 'MM';
  for (let i = 0; i < 4; i++) {
    token += digits.charAt(Math.floor(Math.random() * digits.length));
  }
  return token;
}

function isInCooldown_(lastVisitDate, nowDate) {
  if (!lastVisitDate || !(lastVisitDate instanceof Date) || isNaN(lastVisitDate.getTime())) {
    return false;
  }
  const diffHours = (nowDate.getTime() - lastVisitDate.getTime()) / 3600000;
  return diffHours >= 0 && diffHours < COOLDOWN_HOURS;
}

function parseDate_(val) {
  if (!val) return null;
  if (val instanceof Date) return isNaN(val.getTime()) ? null : val;
  const parsed = new Date(val);
  return isNaN(parsed.getTime()) ? null : parsed;
}

function roundMoney_(amount) {
  return Math.round(Number(amount) * 100) / 100;
}

function constantTimeEquals_(a, b) {
  if (typeof a !== 'string' || typeof b !== 'string') return false;
  if (a.length !== b.length) return false;
  let result = 0;
  for (let i = 0; i < a.length; i++) {
    result |= a.charCodeAt(i) ^ b.charCodeAt(i);
  }
  return result === 0;
}

// Typo-safe alias ensuring resilience if deployed Apps Script references constantTimeEyuals_
function constantTimeEyuals_(a, b) {
  return constantTimeEquals_(a, b);
}

function safeError_(err) {
  return (err && err.message) ? err.message : String(err);
}

function jsonResponse(obj) {
  return ContentService.createTextOutput(JSON.stringify(obj))
    .setMimeType(ContentService.MimeType.JSON);
}
