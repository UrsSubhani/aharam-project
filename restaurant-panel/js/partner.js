/**
 * partner.js — Restaurant Panel Application Logic
 */

let restaurantData = null;
let restaurantId   = null;
let editItemId     = null;
let orderPollInt   = null;
let currentOrderFilter = '';

// ── Init ─────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', async () => {
  if (!Auth.isLoggedIn()) { window.location.href = 'login.html'; return; }

  const user = Auth.getUser();
  if (user?.role !== 'restaurant_owner') { Auth.logout(); return; }

  // Load restaurant for this owner
  const meRes = await Api.auth.me();
  if (!meRes?.success) { Auth.logout(); return; }

  const rest = meRes.data?.restaurant;
  if (!rest) {
    showRegisterRestaurantForm();
    return;
  }

  restaurantId = rest.id;
  document.getElementById('sidebarOwnerName').textContent = user.name;
  document.getElementById('sidebarRestName').textContent  = rest.name;

  // Load restaurant details — use owner endpoint to bypass is_active check
  const restRes = await Api.restaurant.getOwner(restaurantId);
  if (restRes?.success) {
    restaurantData = restRes.data;
    updateOpenToggle(restaurantData.is_open);
    scheduleAutoClose();
  }

  // Page navigation
  document.querySelectorAll('.nav-item').forEach(item => {
    item.addEventListener('click', e => {
      e.preventDefault();
      showPage(item.dataset.page);
    });
  });

  // Open/Close toggle
  document.getElementById('openToggle').addEventListener('change', async (e) => {
    const res = await Api.restaurant.toggle(restaurantId);
    if (res?.success) {
      const isOpen = res.data.is_open;
      updateOpenToggle(isOpen);
      showToast(isOpen ? 'Restaurant is now Open' : 'Restaurant is now Closed', isOpen ? 'success' : 'error');
    }
  });

  // Mobile menu toggle
  document.getElementById('menuToggle')?.addEventListener('click', () => {
    document.getElementById('sidebar').classList.toggle('open');
  });

  // Load first page — honour #subscription hash from external links
  const hash = location.hash.replace('#', '');
  showPage(['dashboard','orders','menu','earnings','coupons','subscription','settings'].includes(hash) ? hash : 'dashboard');
});

function updateOpenToggle(isOpen) {
  const toggle  = document.getElementById('openToggle');
  const textEl  = document.getElementById('openText');
  if (toggle) toggle.checked = !!isOpen;
  if (textEl) { textEl.textContent = isOpen ? 'Open' : 'Closed'; textEl.style.color = isOpen ? '#4caf50' : '#f44336'; }
}

function isWithinBusinessHours() {
  if (!restaurantData) return false;
  const now = new Date();
  const cur = now.getHours() * 60 + now.getMinutes();
  const toMins = t => { const [h,m] = (t||'').split(':').map(Number); return h*60+(m||0); };
  const open  = toMins(restaurantData.opening_time);
  const close = toMins(restaurantData.closing_time);
  return cur >= open && cur < close;
}

async function checkAndAutoClose() {
  if (!restaurantData || !restaurantData.is_open) return;
  if (!isWithinBusinessHours()) {
    const res = await Api.restaurant.toggle(restaurantId);
    if (res?.success) {
      restaurantData.is_open = 0;
      updateOpenToggle(false);
      showToast('Restaurant auto-closed — outside business hours.', 'error');
    }
  }
}

function scheduleAutoClose() {
  if (!restaurantData?.is_open || !restaurantData.closing_time) return;
  const now = new Date();
  const [h, m] = restaurantData.closing_time.split(':').map(Number);
  const closeAt = new Date();
  closeAt.setHours(h, m, 0, 0);
  const msUntilClose = closeAt - now;
  if (msUntilClose > 0) {
    setTimeout(checkAndAutoClose, msUntilClose);
  }
}

// ── Page Routing ─────────────────────────────────────────
function showPage(name) {
  document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));

  const pageEl = document.getElementById(`page-${name}`);
  if (pageEl) pageEl.classList.add('active');

  const navEl = document.querySelector(`.nav-item[data-page="${name}"]`);
  if (navEl) navEl.classList.add('active');

  const titles = { dashboard:'Dashboard', orders:'Live Orders', menu:'Menu', earnings:'Earnings', coupons:'Coupons', reviews:'Reviews', subscription:'Subscription', settings:'Settings' };
  document.getElementById('pageTitle').textContent = titles[name] || name;

  // Lazy load page data
  switch (name) {
    case 'dashboard':    loadDashboard(); break;
    case 'orders':       loadOrders(); startOrderPolling(); break;
    case 'menu':         loadMenu(); break;
    case 'earnings':     loadEarnings(); break;
    case 'coupons':       loadRestCoupons(); break;
    case 'reviews':       loadReviews(); break;
    case 'subscription': loadSubscription(); break;
    case 'settings':     loadSettings(); break;
  }

  if (name !== 'orders') stopOrderPolling();
}

// ── REVIEWS ──────────────────────────────────────────────
async function loadReviews() {
  const el = document.getElementById('reviewsContent');
  el.innerHTML = '<div class="loading-spinner">Loading reviews…</div>';

  const res = await Api.get(`/reviews/${restaurantId}`);
  const reviews = res?.data || [];

  if (!reviews.length) {
    el.innerHTML = `
      <div style="text-align:center;padding:60px 20px">
        <div style="font-size:56px;margin-bottom:12px">⭐</div>
        <div style="font-size:18px;font-weight:700;margin-bottom:6px">No reviews yet</div>
        <div style="color:#999;font-size:14px">Customer reviews will appear here after delivered orders.</div>
      </div>`;
    return;
  }

  const avg = (reviews.reduce((s, r) => s + (r.food_rating || 0), 0) / reviews.length).toFixed(1);
  const dist = [5,4,3,2,1].map(n => ({ n, count: reviews.filter(r => r.food_rating == n).length }));

  el.innerHTML = `
    <!-- Summary card -->
    <div class="panel-card" style="margin-bottom:20px">
      <div style="display:flex;align-items:center;gap:32px;flex-wrap:wrap">
        <div style="text-align:center;flex-shrink:0">
          <div style="font-size:52px;font-weight:800;color:#333;line-height:1">${avg}</div>
          <div style="color:#f5a623;font-size:22px;margin:4px 0">${'★'.repeat(Math.round(avg))}${'☆'.repeat(5-Math.round(avg))}</div>
          <div style="font-size:13px;color:#999">${reviews.length} review${reviews.length>1?'s':''}</div>
        </div>
        <div style="flex:1;min-width:180px;display:flex;flex-direction:column;gap:5px">
          ${dist.map(d => {
            const pct = reviews.length ? Math.round((d.count/reviews.length)*100) : 0;
            return `<div style="display:flex;align-items:center;gap:8px;font-size:13px">
              <span style="width:14px;text-align:right;color:#555">${d.n}</span>
              <span style="color:#f5a623;font-size:12px">★</span>
              <div style="flex:1;height:8px;background:#f0f0f0;border-radius:4px;overflow:hidden">
                <div style="width:${pct}%;height:100%;background:#f5a623;border-radius:4px"></div>
              </div>
              <span style="color:#999;width:28px">${d.count}</span>
            </div>`;
          }).join('')}
        </div>
      </div>
    </div>

    <!-- Review cards -->
    <div style="display:flex;flex-direction:column;gap:12px">
      ${reviews.map(r => {
        const filled = Math.round(r.food_rating || 0);
        const stars = Array.from({length:5},(_,i)=>`<span style="color:${i<filled?'#f5a623':'#ddd'};font-size:15px">★</span>`).join('');
        const date  = new Date(r.created_at).toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'});
        const init  = (r.customer_name||'?').split(' ').map(w=>w[0]).join('').toUpperCase().slice(0,2);
        return `
          <div class="panel-card" style="padding:16px 18px">
            <div style="display:flex;align-items:flex-start;gap:12px">
              <div style="width:40px;height:40px;border-radius:50%;background:#ff5722;color:#fff;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:700;flex-shrink:0">${init}</div>
              <div style="flex:1">
                <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:4px">
                  <div style="font-weight:700;font-size:14px">${escHtml(r.customer_name||'Customer')}</div>
                  <div style="font-size:12px;color:#aaa">${date}</div>
                </div>
                <div style="margin:4px 0 8px">${stars}</div>
                ${r.review_text ? `<div style="font-size:14px;color:#555;line-height:1.5;margin-bottom:10px">${escHtml(r.review_text)}</div>` : ''}
                ${(r.items||[]).length ? `
                <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:4px">
                  ${r.items.map(item => `
                    <div style="display:flex;align-items:center;gap:6px;background:#f9f9f9;border-radius:8px;padding:5px 10px 5px 5px">
                      ${item.image
                        ? `<img src="${escHtml(item.image)}" style="width:28px;height:28px;border-radius:6px;object-fit:cover;flex-shrink:0"/>`
                        : `<div style="width:28px;height:28px;border-radius:6px;background:#eee;display:flex;align-items:center;justify-content:center;font-size:13px">🍽</div>`}
                      <span style="font-size:12px;font-weight:600;color:#333">${escHtml(item.name)}</span>
                      <span style="font-size:11px;color:#aaa">×${item.qty}</span>
                    </div>`).join('')}
                </div>` : ''}
              </div>
            </div>
          </div>`;
      }).join('')}
    </div>`;
}

// ── DASHBOARD ────────────────────────────────────────────
async function loadDashboard() {
  if (!restaurantId) return;
  const res = await Api.restaurant.stats(restaurantId);
  if (!res?.success) return;

  const s = res.data;
  document.getElementById('todayOrders').textContent   = s.today_orders   || 0;
  document.getElementById('todayRevenue').textContent  = `₹${s.today_revenue || 0}`;
  document.getElementById('pendingOrders').textContent = s.pending_orders || 0;
  document.getElementById('avgRating').textContent     = parseFloat(s.avg_rating || 0).toFixed(1);
  document.getElementById('dashTotalOrders').textContent  = s.total_orders || 0;
  document.getElementById('dashTotalRevenue').textContent = `₹${s.total_food_revenue || 0}`;
  document.getElementById('dashNetPayout').textContent    = `₹${s.total_net_payout || 0}`;

  // Recent orders
  const orderRes = await Api.orders.list({ limit: 5 });
  const orders   = orderRes?.data || [];
  const tableEl  = document.getElementById('recentOrdersTable');

  if (!orders.length) {
    tableEl.innerHTML = '<p style="color:#777;font-size:14px;padding:16px 0">No orders yet today.</p>';
    return;
  }

  tableEl.innerHTML = `
    <table class="data-table">
      <thead><tr><th>Order #</th><th>Customer</th><th>Amount</th><th>Status</th><th>Time</th></tr></thead>
      <tbody>${orders.map(o => `
        <tr>
          <td style="font-weight:600">${escHtml(o.order_number)}</td>
          <td>${escHtml(o.customer_name)}</td>
          <td>₹${o.food_total || o.total_amount}</td>
          <td><span class="status-badge status-${o.status}">${fmtStatus(o.status)}</span></td>
          <td style="font-size:12px;color:#777">${timeSince(o.created_at)}</td>
        </tr>`).join('')}
      </tbody>
    </table>`;
}

// ── LIVE ORDERS ──────────────────────────────────────────
async function loadOrders() {
  if (!restaurantId) return;
  const res = await Api.orders.list({ status: currentOrderFilter, limit: 30 });
  const orders = res?.data || [];
  const wrap   = document.getElementById('ordersTableWrap');

  if (!orders.length) {
    wrap.innerHTML = '<div style="text-align:center;padding:40px;color:#777">No orders found.</div>';
    return;
  }

  wrap.innerHTML = `
    <div class="panel-card" style="overflow-x:auto">
      <table class="data-table">
        <thead><tr>
          <th>Order #</th><th>Customer</th><th>Items</th><th>Amount</th>
          <th>Payment</th><th>Status</th><th>Time</th><th>Actions</th>
        </tr></thead>
        <tbody>${orders.map(o => `
          <tr>
            <td style="font-weight:700">${escHtml(o.order_number)}</td>
            <td>
              <div style="font-weight:600">${escHtml(o.customer_name)}</div>
              <div style="font-size:12px;color:#777">${escHtml(o.customer_phone)}</div>
            </td>
            <td style="max-width:160px;font-size:12px;color:#555">${escHtml(o.delivery_address_text||'').substring(0,50)}...</td>
            <td style="font-weight:700;color:#ff5722">₹${o.food_total || o.total_amount}</td>
            <td><span style="font-size:12px;text-transform:uppercase">${o.payment_method}</span></td>
            <td><span class="status-badge status-${o.status}">${fmtStatus(o.status)}</span></td>
            <td style="font-size:12px;color:#777">${timeSince(o.created_at)}</td>
            <td>${renderOrderActions(o)}</td>
          </tr>`).join('')}
        </tbody>
      </table>
    </div>`;
}

const STATUS_LABELS = {
  pending:    'Pending',
  confirmed:  'Accepted',
  preparing:  'Preparing',
  ready:      'Ready',
  picked:     'Picked Up',
  on_the_way: 'Out for Delivery',
  delivered:  'Delivered',
  cancelled:  'Cancelled',
};
function fmtStatus(s) { return STATUS_LABELS[s] || s; }

function renderOrderActions(o) {
  if (o.status === 'pending')
    return `<button class="btn btn-success btn-sm" onclick="updateOrderStatus(${o.id},'confirmed')">✔ Accept Order</button>`;
  if (o.status === 'confirmed')
    return `<button class="btn btn-warning btn-sm" onclick="updateOrderStatus(${o.id},'preparing')">▶ Start Preparing</button>`;
  if (o.status === 'preparing')
    return `<button class="btn btn-primary btn-sm" onclick="updateOrderStatus(${o.id},'ready')">✅ Mark Ready</button>`;
  if (o.status === 'ready')
    return `<div style="font-size:12px;color:#ff9800;font-weight:600">🛵 Rider on the way…</div>
            ${o.pickup_otp ? `<div style="margin-top:4px;font-size:11px;color:#555">Pickup OTP: <strong style="font-size:15px;letter-spacing:3px;color:#e64a19">${escHtml(o.pickup_otp)}</strong></div>` : ''}`;
  if (o.status === 'picked' || o.status === 'on_the_way')
    return `<div style="font-size:12px;color:#2196f3;font-weight:600">🚀 Out for Delivery</div>
            ${o.pickup_otp ? `<div style="margin-top:4px;font-size:11px;color:#aaa">Pickup OTP: <strong style="font-size:13px;letter-spacing:2px;color:#999">${escHtml(o.pickup_otp)}</strong></div>` : ''}`;
  if (o.status === 'delivered')
    return `<span style="font-size:12px;color:#4caf50;font-weight:600">✔ Delivered</span>`;
  if (o.status === 'cancelled')
    return `<span style="font-size:12px;color:#f44336;font-weight:600">✘ Cancelled</span>`;
  return '<span style="color:#aaa;font-size:12px">—</span>';
}

async function updateOrderStatus(orderId, status) {
  const res = await Api.orders.updateStatus(orderId, status);
  if (res?.success) {
    showToast(`Order marked as ${status}`, 'success');
    loadOrders();
  } else {
    showToast(res?.message || 'Update failed.', 'error');
  }
}

// Order filter buttons
document.querySelectorAll('.order-filter').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.order-filter').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    currentOrderFilter = btn.dataset.status;
    loadOrders();
  });
});
document.getElementById('refreshOrdersBtn')?.addEventListener('click', loadOrders);

function startOrderPolling() { stopOrderPolling(); orderPollInt = setInterval(loadOrders, 15000); }
function stopOrderPolling()  { if (orderPollInt) { clearInterval(orderPollInt); orderPollInt = null; } }

// ── MENU ─────────────────────────────────────────────────
async function loadMenu() {
  if (!restaurantId) return;

  // Load global categories for dropdown
  const catRes = await Api.menu.cats();
  const cats   = catRes?.data || [];
  const catSel = document.getElementById('iCategory');
  if (catSel) {
    catSel.innerHTML = '<option value="">-- No Category --</option>' +
      cats.map(c => `<option value="${c.id}">${escHtml(c.name)}</option>`).join('');
  }

  const res   = await Api.menu.get(restaurantId);
  const cats2 = res?.data?.categories || [];
  const wrap  = document.getElementById('menuItemsTable');

  if (!cats2.length || cats2.every(c => !c.items?.length)) {
    wrap.innerHTML = '<div style="text-align:center;padding:40px;color:#777">No menu items yet. Click "+ Add Item" to start.</div>';
    return;
  }

  wrap.innerHTML = cats2.map(cat => cat.items?.length ? `
    <div class="panel-card" style="margin-bottom:16px">
      <h3 style="font-size:15px;font-weight:700;margin-bottom:12px">${escHtml(cat.name)}</h3>
      <table class="data-table">
        <thead><tr><th>Item</th><th>Type</th><th>Price</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>${cat.items.map(item => `
          <tr style="vertical-align:middle">
            <td>
              <div style="display:flex;gap:10px;align-items:center">
                ${item.image ? `<img src="${escHtml(item.image)}" style="width:48px;height:48px;object-fit:cover;border-radius:6px;flex-shrink:0" onerror="this.style.display='none'"/>` : ''}
                <div>
                  <div style="font-weight:600">${escHtml(item.name)}</div>
                  <div style="font-size:12px;color:#777">${escHtml(item.description||'')}</div>
                </div>
              </div>
            </td>
            <td style="vertical-align:middle"><span style="font-size:12px;text-transform:capitalize">${item.food_type.replace('_',' ')}</span></td>
            <td style="vertical-align:middle">
              ${item.discount_price ? `<span style="font-weight:700;color:#ff5722">₹${item.discount_price}</span> <span style="font-size:11px;text-decoration:line-through;color:#aaa">₹${item.price}</span>` : `<span style="font-weight:700;color:#ff5722">₹${item.price}</span>`}
            </td>
            <td style="vertical-align:middle">
              <span class="status-badge ${item.is_available ? 'status-delivered' : 'status-cancelled'}">
                ${item.is_available ? 'Available' : 'Unavailable'}
              </span>
            </td>
            <td style="vertical-align:middle">
              <div style="display:flex;gap:6px;flex-wrap:wrap">
                <button class="btn btn-info btn-sm" onclick="editItem(${item.id})">✏ Edit</button>
                <button class="btn ${item.is_available ? 'btn-outline' : 'btn-success'} btn-sm" onclick="toggleItem(${item.id})">${item.is_available ? 'Disable' : 'Enable'}</button>
                <button class="btn btn-danger btn-sm" onclick="deleteItem(${item.id})">Delete</button>
              </div>
            </td>
          </tr>`).join('')}
        </tbody>
      </table>
    </div>` : '').join('');
}

// Add Item
document.getElementById('addItemBtn')?.addEventListener('click', () => {
  editItemId = null;
  document.getElementById('itemFormTitle').textContent = 'Add Menu Item';
  ['iName','iPrice','iDiscountPrice','iDesc','iImage'].forEach(id => document.getElementById(id).value = '');
  document.getElementById('iFoodType').value = 'veg';
  document.getElementById('iCategory').value = '';
  document.getElementById('itemFormCard').style.display = 'block';
});

document.getElementById('cancelItemBtn')?.addEventListener('click', () => {
  document.getElementById('itemFormCard').style.display = 'none';
  editItemId = null;
});

document.getElementById('saveItemBtn')?.addEventListener('click', async () => {
  const name  = document.getElementById('iName').value.trim();
  const price = parseFloat(document.getElementById('iPrice').value);
  if (!name || !price) { showToast('Name and price are required.', 'error'); return; }

  const discountRaw = document.getElementById('iDiscountPrice').value;
  const imageRaw    = document.getElementById('iImage').value.trim();
  const data = {
    restaurant_id:  restaurantId,
    name,
    price,
    discount_price: discountRaw ? parseFloat(discountRaw) : null,
    image:          imageRaw || null,
    description:    document.getElementById('iDesc').value,
    food_type:      document.getElementById('iFoodType').value,
    category_id:    document.getElementById('iCategory').value || null,
  };

  const res = editItemId
    ? await Api.menu.update(editItemId, data)
    : await Api.menu.create(data);

  if (res?.success) {
    showToast(editItemId ? 'Item updated.' : 'Item added.', 'success');
    document.getElementById('itemFormCard').style.display = 'none';
    loadMenu();
  } else {
    showToast(res?.message || 'Save failed.', 'error');
  }
});

async function toggleItem(id) {
  const res = await Api.menu.toggle(id);
  if (res?.success) { showToast('Item status updated.', 'success'); loadMenu(); }
}

async function deleteItem(id) {
  if (!confirm('Delete this menu item?')) return;
  const res = await Api.menu.delete(id);
  if (res?.success) { showToast('Item deleted.', 'success'); loadMenu(); }
  else showToast(res?.message || 'Delete failed.', 'error');
}

async function editItem(id) {
  const res = await Api.menu.getItem(restaurantId, id);
  if (!res?.success) { showToast('Could not load item.', 'error'); return; }
  const item = res.data;

  editItemId = id;
  document.getElementById('itemFormTitle').textContent    = 'Edit Menu Item';
  document.getElementById('iName').value                  = item.name || '';
  document.getElementById('iPrice').value                 = item.price || '';
  document.getElementById('iDiscountPrice').value         = item.discount_price || '';
  document.getElementById('iDesc').value                  = item.description || '';
  document.getElementById('iImage').value                 = item.image || '';
  document.getElementById('iFoodType').value              = item.food_type || 'veg';
  document.getElementById('iCategory').value              = item.category_id || '';
  document.getElementById('itemFormCard').style.display   = 'block';
  document.getElementById('itemFormCard').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// ── EARNINGS ─────────────────────────────────────────────
async function loadEarnings() {
  const statsRes = await Api.restaurant.stats(restaurantId);
  if (!statsRes?.success) return;
  const s = statsRes.data;
  document.getElementById('earnTotalOrders').textContent  = s.total_orders  || 0;
  document.getElementById('earnTotalRevenue').textContent = `₹${s.total_food_revenue || 0}`;
  document.getElementById('earnNetPayout').textContent    = `₹${s.total_net_payout || 0}`;

  const subRes = await Api.subscription.status();
  const sub    = subRes?.data;
  const commEl = document.getElementById('commissionInfo');
  if (sub?.active) {
    commEl.innerHTML = `
      <div style="background:#e8f5e9;padding:14px;border-radius:8px;margin-bottom:12px">
        <div style="font-weight:700;color:#2e7d32">✓ ${sub.plan} Subscription Active</div>
        <div>Commission Rate: <strong>${sub.commission_percent}%</strong> (reduced from standard 20%)</div>
        <div>Expires: ${sub.expires_at}</div>
      </div>
      <p style="font-size:13px;color:#555">Your subscription saves you ${20 - sub.commission_percent}% commission on every order.</p>`;
  } else {
    commEl.innerHTML = `
      <div style="background:#fff3e0;padding:14px;border-radius:8px;margin-bottom:12px">
        <div style="font-weight:700;color:#e65100">Standard Commission: 20%</div>
        <div style="font-size:13px;margin-top:4px">Subscribe to reduce to 5–12% and keep more of your earnings!</div>
      </div>
      <button class="btn btn-primary" onclick="showPage('subscription')">View Subscription Plans →</button>`;
  }
}

// ── SUBSCRIPTION ─────────────────────────────────────────
async function loadSubscription() {
  const [res, cfgRes] = await Promise.all([
    Api.subscription.status(),
    Api.get('/settings/public'),
  ]);
  const sub = res?.data;
  const cfg = cfgRes?.data || {};

  const currentEl = document.getElementById('currentSubInfo');
  if (sub?.active) {
    currentEl.innerHTML = `
      <div style="background:#e8f5e9;padding:14px;border-radius:8px;border:1px solid #4caf50">
        <div style="font-weight:700;color:#2e7d32">✓ Active: ${sub.plan} Plan</div>
        <div style="font-size:13px">Commission: ${sub.commission_percent}% | Expires: ${sub.expires_at}</div>
      </div>`;
  } else {
    currentEl.innerHTML = '<p style="color:#777;font-size:14px">No active subscription. Choose a plan below to reduce your commission.</p>';
  }

  const activePlanName = sub?.active ? (sub.plan || '').toLowerCase() : '';

  const plans = [
    { key:'basic',   name:'Basic',   price:`₹${cfg.plan_basic_price || 599}`,   commission:`${cfg.plan_basic_commission || 12}%`,   desc:'Good for small restaurants' },
    { key:'pro',     name:'Pro',     price:`₹${cfg.plan_pro_price || 999}`,     commission:`${cfg.plan_pro_commission || 8}%`,     desc:'Most popular — best value', popular:true },
    { key:'premium', name:'Premium', price:`₹${cfg.plan_premium_price || 1499}`, commission:`${cfg.plan_premium_commission || 5}%`, desc:'Ideal for high-volume kitchens' },
  ];

  document.getElementById('planGrid').innerHTML = plans.map(p => {
    const isActive = activePlanName && (activePlanName.includes(p.key) || p.key.includes(activePlanName));
    return `
    <div class="plan-card ${isActive ? 'recommended' : ''}" style="display:flex;flex-direction:column">
      <div style="min-height:20px">
        ${isActive
          ? '<div style="font-size:11px;font-weight:700;color:#4caf50;margin-bottom:8px">✓ CURRENT PLAN</div>'
          : (p.popular ? '<div style="font-size:11px;font-weight:700;color:#ff5722;margin-bottom:8px">⭐ POPULAR</div>' : '')}
      </div>
      <div class="plan-name">${p.name}</div>
      <div class="plan-price">${p.price}<span>/month</span></div>
      <div class="plan-commission">Commission: ${p.commission}</div>
      <div class="plan-feature" style="flex:1">${p.desc}</div>
      <button class="btn ${isActive ? 'btn-outline' : 'btn-primary'} btn-block" style="margin-top:16px"
        ${isActive ? 'disabled' : `onclick="subscribePlan('${p.key}')"`}>
        ${isActive ? 'Active' : 'Subscribe'}
      </button>
    </div>`;
  }).join('');
}

// ── CATEGORY MANAGEMENT ──────────────────────────────────

function subscribePlan(plan) {
  const planMeta = {
    basic:   { icon: '🥉', color: '#607d8b', commission: '12%', price: '₹599/mo' },
    pro:     { icon: '🥇', color: '#ff5722', commission: '8%',  price: '₹999/mo' },
    premium: { icon: '💎', color: '#9c27b0', commission: '5%',  price: '₹1,499/mo' },
  };
  const m = planMeta[plan] || { icon: '📋', color: '#333', commission: '—', price: '—' };

  document.getElementById('subConfirmModal')?.remove();
  document.body.insertAdjacentHTML('beforeend', `
    <div id="subConfirmModal"
         style="position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;display:flex;align-items:center;justify-content:center;padding:16px"
         onclick="if(event.target===this)this.remove()">
      <div style="background:#fff;border-radius:20px;width:min(420px,100%);overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.2);animation:popIn .2s ease">

        <!-- Header -->
        <div style="background:${m.color};padding:24px 24px 20px;text-align:center;color:#fff">
          <div style="font-size:40px;margin-bottom:8px">${m.icon}</div>
          <div style="font-size:20px;font-weight:800;letter-spacing:.3px">${plan.charAt(0).toUpperCase()+plan.slice(1)} Plan</div>
          <div style="font-size:14px;opacity:.85;margin-top:4px">${m.price}</div>
        </div>

        <!-- Body -->
        <div style="padding:24px">
          <div style="display:flex;flex-direction:column;gap:10px;margin-bottom:20px">
            <div style="display:flex;align-items:center;gap:10px;background:#f9f9f9;border-radius:10px;padding:12px 14px">
              <span style="font-size:18px">💸</span>
              <div>
                <div style="font-size:11px;color:#999;font-weight:600;text-transform:uppercase">Commission Rate</div>
                <div style="font-size:15px;font-weight:700;color:#333">Reduced to ${m.commission}</div>
              </div>
            </div>
            <div style="display:flex;align-items:center;gap:10px;background:#f9f9f9;border-radius:10px;padding:12px 14px">
              <span style="font-size:18px">📅</span>
              <div>
                <div style="font-size:11px;color:#999;font-weight:600;text-transform:uppercase">Billing</div>
                <div style="font-size:15px;font-weight:700;color:#333">Monthly · Auto renews</div>
              </div>
            </div>
          </div>

          <p style="font-size:13px;color:#888;text-align:center;margin-bottom:20px">
            By confirming, you agree to subscribe to the <strong>${plan.charAt(0).toUpperCase()+plan.slice(1)} Plan</strong>.
          </p>

          <div style="display:flex;gap:10px">
            <button onclick="document.getElementById('subConfirmModal').remove()"
              style="flex:1;padding:12px;background:#f5f5f5;color:#555;border:none;border-radius:10px;font-size:14px;font-weight:600;cursor:pointer;font-family:inherit">
              Cancel
            </button>
            <button onclick="confirmSubscribePlan('${plan}')"
              style="flex:1;padding:12px;background:${m.color};color:#fff;border:none;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer;font-family:inherit">
              Confirm Subscribe
            </button>
          </div>
        </div>
      </div>
    </div>
    <style>@keyframes popIn{from{transform:scale(.85);opacity:0}to{transform:scale(1);opacity:1}}</style>`);
}

async function confirmSubscribePlan(plan) {
  document.getElementById('subConfirmModal')?.remove();
  const res = await Api.subscription.subscribe(plan);
  if (res?.success) {
    showToast(`${plan.charAt(0).toUpperCase()+plan.slice(1)} plan activated! Commission reduced to ${res.data.commission_percent}%`, 'success');
    loadSubscription();
  } else {
    showToast(res?.message || 'Subscription failed.', 'error');
  }
}

// ── SETTINGS ─────────────────────────────────────────────
async function loadSettings() {
  if (!restaurantData) {
    const res = await Api.restaurant.getOwner(restaurantId);
    if (res?.success) restaurantData = res.data;
  }
  if (!restaurantData) return;
  const r = restaurantData;

  // Populate account section
  const meRes = await Api.auth.me();
  if (meRes?.success) {
    const el = document.getElementById('acEmail');
    if (el) el.textContent = meRes.data.email || '—';
  }
  document.getElementById('sName').value        = r.name              || '';
  setCuisineChips('sCuisineChips', 'sCuisine', r.cuisine_type || '');
  document.getElementById('sOpen').value        = r.opening_time?.substring(0,5) || '08:00';
  document.getElementById('sClose').value       = r.closing_time?.substring(0,5) || '22:00';
  document.getElementById('sMinOrder').value    = r.min_order_amount  || 0;
  document.getElementById('sWhatsapp').value    = r.whatsapp_number   || '';
  document.getElementById('sAddress').value     = r.address           || '';
  document.getElementById('sCity').value        = r.city              || '';
  document.getElementById('sPincode').value     = r.pincode           || '';
  document.getElementById('sLat').value         = r.latitude          || '';
  document.getElementById('sLng').value         = r.longitude         || '';
  document.getElementById('sDesc').value        = r.description       || '';
  document.getElementById('sLogoImage').value   = r.logo_image        || '';
  showLogoPreview(r.logo_image);
}

document.getElementById('settingsForm')?.addEventListener('submit', async (e) => {
  e.preventDefault();
  const payload = {
    cuisine_type:     document.getElementById('sCuisine').value,
    opening_time:     document.getElementById('sOpen').value,
    closing_time:     document.getElementById('sClose').value,
    min_order_amount: document.getElementById('sMinOrder').value,
    whatsapp_number:  document.getElementById('sWhatsapp').value,
    address:          document.getElementById('sAddress').value.trim(),
    city:             document.getElementById('sCity').value.trim(),
    pincode:          document.getElementById('sPincode').value.trim(),
    description:      document.getElementById('sDesc').value,
    logo_image:       document.getElementById('sLogoImage').value.trim() || null,
  };
  const lat = document.getElementById('sLat').value;
  const lng = document.getElementById('sLng').value;
  if (lat) payload.latitude  = parseFloat(lat);
  if (lng) payload.longitude = parseFloat(lng);

  const res = await Api.restaurant.update(restaurantId, payload);
  if (res?.success) {
    restaurantData = { ...restaurantData, ...payload };
    showToast('Settings saved!', 'success');
  } else {
    showToast(res?.message || 'Save failed.', 'error');
  }
});

function toggleSettingsPwd(inputId, btn) {
  const input = document.getElementById(inputId);
  const show  = input.type === 'password';
  input.type  = show ? 'text' : 'password';
  btn.textContent = show ? '🙈' : '👁';
  btn.style.color = show ? '#ff5722' : '#aaa';
}

async function saveRestaurantPassword() {
  const current = document.getElementById('acCurrentPwd').value;
  const newPwd  = document.getElementById('acNewPwd').value;
  const confirm = document.getElementById('acConfirmPwd').value;
  const msgEl   = document.getElementById('acPwdMsg');

  function showMsg(text, success) {
    msgEl.textContent = text;
    msgEl.style.display    = 'block';
    msgEl.style.background = success ? '#e8f5e9' : '#ffebee';
    msgEl.style.color      = success ? '#2e7d32' : '#c62828';
    setTimeout(() => { msgEl.style.display = 'none'; }, 4000);
  }

  if (!current || !newPwd) { showMsg('Fill in all password fields.', false); return; }
  if (newPwd.length < 6)   { showMsg('New password must be at least 6 characters.', false); return; }
  if (newPwd !== confirm)  { showMsg('Passwords do not match.', false); return; }

  const btn = document.querySelector('[onclick="saveRestaurantPassword()"]');
  if (btn) { btn.disabled = true; btn.textContent = 'Updating…'; }

  const res = await Api.request('PUT', '/me', { current_password: current, new_password: newPwd });

  if (btn) { btn.disabled = false; btn.textContent = 'Update Password'; }

  if (res?.success) {
    showMsg('Password changed successfully!', true);
    document.getElementById('acCurrentPwd').value = '';
    document.getElementById('acNewPwd').value      = '';
    document.getElementById('acConfirmPwd').value  = '';
  } else {
    showMsg(res?.message || 'Failed to update password.', false);
  }
}

// ── Cuisine chip helpers ──────────────────────────────────
const CUISINE_LIST = [
  'South Indian','North Indian','Chinese','Biryani','Fast Food',
  'Snacks','Desserts','Beverages','Seafood','Rolls & Wraps','Tiffins','Meals'
];

function cuisineChipHTML(containerId, hiddenId) {
  return `
    <div class="cuisine-chip-picker" id="${containerId}">
      ${CUISINE_LIST.map(c => `<button type="button" class="c-chip" data-v="${c}">${c}</button>`).join('')}
    </div>
    <input type="hidden" id="${hiddenId}"/>`;
}

function setCuisineChips(containerId, hiddenId, value) {
  const selected = value.split(',').map(s => s.trim()).filter(Boolean);
  const container = document.getElementById(containerId);
  if (!container) return;
  container.querySelectorAll('.c-chip').forEach(btn => {
    btn.classList.toggle('selected', selected.includes(btn.dataset.v));
  });
  document.getElementById(hiddenId).value = selected.join(', ');
  bindCuisineChips(containerId, hiddenId);
}

function bindCuisineChips(containerId, hiddenId) {
  const container = document.getElementById(containerId);
  if (!container || container._bound) return;
  container._bound = true;
  container.addEventListener('click', e => {
    const btn = e.target.closest('.c-chip');
    if (!btn) return;
    btn.classList.toggle('selected');
    const vals = [...container.querySelectorAll('.c-chip.selected')].map(b => b.dataset.v);
    document.getElementById(hiddenId).value = vals.join(', ');
  });
}

// ── Logo image preview ────────────────────────────────────
function showLogoPreview(url) {
  const wrap = document.getElementById('sLogoPreview');
  const img  = document.getElementById('sLogoImg');
  if (!wrap || !img) return;
  if (url) {
    img.src = url;
    wrap.style.display = 'block';
  } else {
    wrap.style.display = 'none';
  }
}

document.getElementById('sLogoImage')?.addEventListener('input', e => {
  showLogoPreview(e.target.value.trim());
});

// ── GPS location detect for settings ─────────────────────
async function detectSettingsLocation() {
  if (!navigator.geolocation) {
    showToast('GPS not supported on this browser.', 'error'); return;
  }
  const btn     = document.getElementById('sGpsBtn');
  const iconEl  = document.getElementById('sGpsIcon');
  const txtEl   = document.getElementById('sGpsTxt');
  const status  = document.getElementById('sLocStatus');
  btn.disabled  = true;
  iconEl.textContent = '⏳';
  txtEl.textContent  = 'Detecting location…';

  navigator.geolocation.getCurrentPosition(
    async (pos) => {
      const lat = pos.coords.latitude;
      const lng = pos.coords.longitude;
      document.getElementById('sLat').value = lat;
      document.getElementById('sLng').value = lng;
      try {
        const r    = await fetch(`https://nominatim.openstreetmap.org/reverse?lat=${lat}&lon=${lng}&format=json`, { headers: { 'Accept-Language': 'en' } });
        const data = await r.json();
        const addr = data.address || {};
        const road    = [addr.house_number, addr.road, addr.neighbourhood, addr.suburb].filter(Boolean).join(', ');
        const city    = addr.city || addr.town || addr.village || addr.county || '';
        const pincode = addr.postcode || '';
        if (road)    document.getElementById('sAddress').value  = road;
        if (city)    document.getElementById('sCity').value     = city;
        if (pincode) document.getElementById('sPincode').value  = pincode;
        iconEl.textContent = '✅';
        txtEl.textContent  = 'Location detected — verify details below';
        status.textContent = `📍 Coordinates: ${lat.toFixed(5)}, ${lng.toFixed(5)}`;
        status.style.display = 'block';
      } catch {
        iconEl.textContent = '✅';
        txtEl.textContent  = 'Coordinates saved — fill address manually';
        status.textContent = `📍 ${lat.toFixed(5)}, ${lng.toFixed(5)}`;
        status.style.display = 'block';
      }
      btn.disabled = false;
    },
    () => {
      iconEl.textContent = '📍';
      txtEl.textContent  = 'Detect Location via GPS';
      btn.disabled = false;
      showToast('Could not get location. Allow GPS access.', 'error');
    },
    { timeout: 10000 }
  );
}

// ── Utility ───────────────────────────────────────────────
function timeSince(dateStr) {
  const diff = Math.floor((Date.now() - new Date(dateStr)) / 60000);
  if (diff < 1) return 'Just now';
  if (diff < 60) return `${diff}m ago`;
  return `${Math.floor(diff/60)}h ago`;
}

// ── Register Restaurant Form ───────────────────────────────
function showRegisterRestaurantForm() {
  document.body.style.visibility = 'visible';
  document.body.innerHTML = `
    <div style="min-height:100vh;background:#f4f6f9;font-family:Inter,sans-serif;display:flex;align-items:flex-start;justify-content:center;padding:40px 16px">
      <div style="background:#fff;border-radius:16px;box-shadow:0 4px 24px rgba(0,0,0,.1);width:100%;max-width:560px;padding:36px 32px">
        <div style="text-align:center;margin-bottom:28px">
          <div style="font-size:44px;margin-bottom:8px">🏪</div>
          <h2 style="font-size:22px;font-weight:800;margin:0">Register Your Restaurant</h2>
          <p style="color:#777;font-size:13px;margin-top:6px">Fill in the details below. Admin will approve within 24 hours.</p>
        </div>
        <div id="regErr" style="display:none;background:#ffebee;color:#c62828;padding:10px 14px;border-radius:8px;font-size:13px;margin-bottom:16px"></div>
        <div id="regSuccess" style="display:none;background:#e8f5e9;color:#2e7d32;padding:16px;border-radius:8px;font-size:14px;text-align:center;margin-bottom:16px"></div>
        <form id="regForm">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
            <div style="grid-column:1/-1">
              <label style="display:block;font-size:13px;font-weight:600;margin-bottom:5px">Restaurant Name *</label>
              <input name="name" placeholder="e.g. Ravi South Kitchen" required style="${iStyle()}"/>
            </div>
            <div>
              <label style="display:block;font-size:13px;font-weight:600;margin-bottom:5px">Phone *</label>
              <input name="phone" placeholder="9876543210" required style="${iStyle()}"/>
            </div>
            <div>
              <label style="display:block;font-size:13px;font-weight:600;margin-bottom:5px">Email</label>
              <input name="email" type="email" placeholder="restaurant@example.com" style="${iStyle()}"/>
            </div>
            <div style="grid-column:1/-1">
              <label style="display:block;font-size:13px;font-weight:600;margin-bottom:5px">Cuisine Type * <span style="font-size:11px;color:#999;font-weight:400">(select all that apply)</span></label>
              ${cuisineChipHTML('regCuisineChips', 'regCuisine')}
            </div>
            <div>
              <label style="display:block;font-size:13px;font-weight:600;margin-bottom:5px">Category</label>
              <select name="category" style="${iStyle()}">
                <option value="restaurant">Restaurant</option>
                <option value="home_kitchen">Home Kitchen</option>
                <option value="cloud_kitchen">Cloud Kitchen</option>
                <option value="cafe">Cafe</option>
                <option value="bakery">Bakery</option>
              </select>
            </div>
            <div style="grid-column:1/-1">
              <label style="display:block;font-size:13px;font-weight:600;margin-bottom:5px">Address *</label>
              <input name="address" id="regAddress" placeholder="Street address" required style="${iStyle()}"/>
            </div>
            <div>
              <label style="display:block;font-size:13px;font-weight:600;margin-bottom:5px">City *</label>
              <input name="city" id="regCity" placeholder="Chennai" required style="${iStyle()}"/>
            </div>
            <div>
              <label style="display:block;font-size:13px;font-weight:600;margin-bottom:5px">Pincode *</label>
              <input name="pincode" id="regPincode" placeholder="600001" required style="${iStyle()}"/>
            </div>
            <div style="grid-column:1/-1">
              <button type="button" id="regLocBtn" style="width:100%;padding:10px;background:#fff3e0;color:#e64a19;border:1px dashed #ff5722;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:6px">
                📍 Detect Restaurant Location (GPS)
              </button>
              <div id="regLocStatus" style="font-size:12px;text-align:center;margin-top:6px;display:none"></div>
              <input type="hidden" id="regLat" name="latitude"/>
              <input type="hidden" id="regLng" name="longitude"/>
            </div>
            <div style="grid-column:1/-1">
              <label style="display:block;font-size:13px;font-weight:600;margin-bottom:5px">Description</label>
              <textarea name="description" placeholder="Tell customers about your restaurant..." rows="3" style="${iStyle()}"></textarea>
            </div>
          </div>
          <button type="submit" id="regBtn" style="width:100%;margin-top:20px;padding:13px;background:#ff5722;color:#fff;border:none;border-radius:8px;font-size:15px;font-weight:700;cursor:pointer;font-family:inherit">
            Submit for Approval
          </button>
        </form>
        <p style="text-align:center;margin-top:16px;font-size:13px;color:#999">
          Wrong account? <a href="#" onclick="Auth.logout()" style="color:#ff5722;font-weight:600">Sign out</a>
        </p>
      </div>
    </div>`;

  document.getElementById('regLocBtn').addEventListener('click', () => {
    if (!navigator.geolocation) { alert('Geolocation not supported.'); return; }
    const btn    = document.getElementById('regLocBtn');
    const status = document.getElementById('regLocStatus');
    btn.textContent  = '⏳ Getting location…';
    btn.disabled     = true;
    status.style.display = 'block';
    status.style.color   = '#777';
    status.textContent   = 'Requesting GPS…';

    navigator.geolocation.getCurrentPosition(async pos => {
      const { latitude: lat, longitude: lng } = pos.coords;
      document.getElementById('regLat').value = lat;
      document.getElementById('regLng').value = lng;
      status.textContent = 'Fetching address…';

      try {
        const r    = await fetch(`https://nominatim.openstreetmap.org/reverse?lat=${lat}&lon=${lng}&format=json`, { headers: { 'Accept-Language': 'en' } });
        const data = await r.json();
        const addr = data.address || {};

        const road   = addr.road || addr.neighbourhood || addr.suburb || '';
        const area   = addr.suburb || addr.village || '';
        const city   = addr.city  || addr.town || addr.county || '';
        const pin    = addr.postcode || '';

        if (road)  document.getElementById('regAddress').value = [road, area].filter(Boolean).join(', ');
        if (city)  document.getElementById('regCity').value    = city;
        if (pin)   document.getElementById('regPincode').value = pin;

        status.style.color  = '#4caf50';
        status.textContent  = `✓ Location saved (${lat.toFixed(5)}, ${lng.toFixed(5)}) — verify address above`;
      } catch {
        status.style.color  = '#ff5722';
        status.textContent  = `✓ Coordinates saved (${lat.toFixed(5)}, ${lng.toFixed(5)}) — fill address manually`;
      }
      btn.textContent = '📍 Detect Restaurant Location (GPS)';
      btn.disabled    = false;
    }, err => {
      status.style.color  = '#f44336';
      status.textContent  = err.code === 1 ? 'Location permission denied.' : 'Could not get location.';
      btn.textContent = '📍 Detect Restaurant Location (GPS)';
      btn.disabled    = false;
    }, { timeout: 10000 });
  });

  // Bind cuisine chips in register form
  bindCuisineChips('regCuisineChips', 'regCuisine');

  document.getElementById('regForm').addEventListener('submit', async e => {
    e.preventDefault();
    const btn = document.getElementById('regBtn');
    const err = document.getElementById('regErr');
    const suc = document.getElementById('regSuccess');
    err.style.display = 'none';
    btn.disabled = true; btn.textContent = 'Submitting…';

    const fd = new FormData(e.target);
    const data = {};
    fd.forEach((v, k) => { if (v) data[k] = v; });
    // Add cuisine from chip picker (hidden input not in FormData)
    const cuisine = document.getElementById('regCuisine')?.value || '';
    if (!cuisine) {
      err.textContent = 'Please select at least one cuisine type.';
      err.style.display = 'block';
      btn.disabled = false; btn.textContent = 'Submit for Approval';
      return;
    }
    data.cuisine_type = cuisine;

    const res = await Api.restaurant.create(data);
    btn.disabled = false; btn.textContent = 'Submit for Approval';

    if (res?.success) {
      e.target.style.display = 'none';
      suc.style.display = 'block';
      suc.innerHTML = `<strong>✅ Restaurant registered!</strong><br>Your restaurant is pending admin approval. You can login again once it's approved.`;
    } else {
      err.textContent = res?.message || 'Registration failed. Please try again.';
      err.style.display = 'block';
    }
  });
}

function iStyle() {
  return 'width:100%;padding:10px 12px;border:1px solid #e0e0e0;border-radius:8px;font-size:14px;font-family:inherit;outline:none;box-sizing:border-box';
}

// ── COUPONS ──────────────────────────────────────────────
async function loadRestCoupons() {
  const el  = document.getElementById('restCouponList');
  const res = await Api.coupons.list();
  const rows = res?.data || [];

  if (!rows.length) {
    el.innerHTML = '<p style="color:#777;text-align:center;padding:32px">No coupons yet. Create one to attract customers!</p>';
    return;
  }

  el.innerHTML = `
    <table style="width:100%;border-collapse:collapse;font-size:14px">
      <thead>
        <tr style="border-bottom:2px solid #eee;text-align:left">
          <th style="padding:8px 12px">Code</th>
          <th style="padding:8px 12px">Type</th>
          <th style="padding:8px 12px">Value</th>
          <th style="padding:8px 12px">Min Order</th>
          <th style="padding:8px 12px">Max Discount</th>
          <th style="padding:8px 12px">Per-Customer</th>
          <th style="padding:8px 12px">Cooldown</th>
          <th style="padding:8px 12px">Expires</th>
          <th style="padding:8px 12px">Status</th>
          <th style="padding:8px 12px">Actions</th>
        </tr>
      </thead>
      <tbody>
        ${rows.map(c => `
          <tr style="border-bottom:1px solid #f5f5f5">
            <td style="padding:10px 12px"><strong style="font-family:monospace;color:#ff5722">${escHtml(c.code)}</strong></td>
            <td style="padding:10px 12px"><span style="background:#e3f2fd;color:#1976d2;padding:2px 8px;border-radius:4px;font-size:12px">${c.discount_type}</span></td>
            <td style="padding:10px 12px;font-weight:600">${c.discount_type === 'percent' ? c.discount_value + '%' : '₹' + c.discount_value}</td>
            <td style="padding:10px 12px">₹${c.min_order_amount || 0}</td>
            <td style="padding:10px 12px">${c.max_discount_amount ? '₹' + c.max_discount_amount : '—'}</td>
            <td style="padding:10px 12px;text-align:center">${c.per_user_limit || 1}×</td>
            <td style="padding:10px 12px;color:#777;font-size:12px">${c.cooldown_hours ? (c.cooldown_hours % 24 === 0 ? c.cooldown_hours/24+'d' : c.cooldown_hours+'h') : '—'}</td>
            <td style="padding:10px 12px;color:#777;font-size:12px">${c.valid_until && c.valid_until !== '2099-12-31' ? c.valid_until : 'No expiry'}</td>
            <td style="padding:10px 12px">
              <span style="padding:3px 10px;border-radius:4px;font-size:12px;font-weight:600;background:${c.is_active ? '#e8f5e9' : '#f5f5f5'};color:${c.is_active ? '#2e7d32' : '#777'}">${c.is_active ? 'Active' : 'Inactive'}</span>
            </td>
            <td style="padding:10px 12px;white-space:nowrap">
              <button class="btn btn-sm btn-outline" onclick="showEditRestCoupon(${JSON.stringify(c).replace(/"/g,'&quot;')})">Edit</button>
              <button class="btn btn-sm ${c.is_active ? 'btn-outline' : 'btn-primary'}" style="margin-left:4px" onclick="toggleRestCoupon(${c.id},this)">${c.is_active ? 'Deactivate' : 'Activate'}</button>
            </td>
          </tr>`).join('')}
      </tbody>
    </table>`;
}

function couponFormFields(c = {}) {
  const noExpiry = !c.valid_until || c.valid_until === '2099-12-31';
  return `
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
      <div>
        <label style="font-size:13px;font-weight:600;display:block;margin-bottom:6px">Coupon Code</label>
        <input id="rcCode" class="form-control" value="${escHtml(c.code||'')}" placeholder="e.g. SAVE20" style="text-transform:uppercase" ${c.id ? 'readonly' : ''}/>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;grid-column:span 1">
        <div>
          <label style="font-size:13px;font-weight:600;display:block;margin-bottom:6px">Type</label>
          <select id="rcType" class="form-control">
            <option value="percent" ${c.discount_type==='percent'?'selected':''}>Percentage (%)</option>
            <option value="flat"    ${c.discount_type==='flat'   ?'selected':''}>Flat (₹)</option>
          </select>
        </div>
        <div>
          <label style="font-size:13px;font-weight:600;display:block;margin-bottom:6px">Value</label>
          <input id="rcValue" type="number" class="form-control" value="${c.discount_value||''}" placeholder="e.g. 20" min="1"/>
        </div>
      </div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:14px">
      <div>
        <label style="font-size:13px;font-weight:600;display:block;margin-bottom:6px">Min Order (₹)</label>
        <input id="rcMin" type="number" class="form-control" value="${c.min_order_amount||0}" min="0"/>
      </div>
      <div>
        <label style="font-size:13px;font-weight:600;display:block;margin-bottom:6px">Max Discount (₹, optional)</label>
        <input id="rcMax" type="number" class="form-control" value="${c.max_discount_amount||''}" placeholder="No limit" min="0"/>
      </div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:14px">
      <div>
        <label style="font-size:13px;font-weight:600;display:block;margin-bottom:6px">Max Uses Per Customer</label>
        <input id="rcPerUser" type="number" class="form-control" value="${c.per_user_limit||1}" min="1"/>
      </div>
      <div>
        <label style="font-size:13px;font-weight:600;display:block;margin-bottom:6px">Cooldown Between Uses (hours)</label>
        <input id="rcCooldown" type="number" class="form-control" value="${c.cooldown_hours||''}" placeholder="e.g. 24 = once/day" min="1"/>
      </div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:14px">
      <div>
        <label style="font-size:13px;font-weight:600;display:block;margin-bottom:6px">Valid From</label>
        <input id="rcValidFrom" type="date" class="form-control" value="${c.valid_from||''}"/>
      </div>
      <div>
        <label style="font-size:13px;font-weight:600;display:block;margin-bottom:6px">Expires At (blank = no expiry)</label>
        <input id="rcExpiry" type="date" class="form-control" value="${noExpiry ? '' : c.valid_until}"/>
      </div>
    </div>
    <div id="rcErr" style="display:none;margin-top:12px;padding:10px 14px;background:#fff3f3;color:#c62828;border-radius:8px;font-size:13px"></div>`;
}

function showCreateRestCoupon() {
  const overlay = document.createElement('div');
  overlay.id = 'rcModal';
  overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;display:flex;align-items:center;justify-content:center;padding:16px';
  overlay.innerHTML = `
    <div style="background:#fff;border-radius:12px;padding:24px;width:100%;max-width:560px;max-height:90vh;overflow-y:auto">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px">
        <h3 style="font-size:18px;font-weight:700">Create Coupon</h3>
        <button onclick="document.getElementById('rcModal').remove()" style="background:none;border:none;font-size:20px;cursor:pointer;color:#777">✕</button>
      </div>
      ${couponFormFields()}
      <div style="display:flex;gap:10px;margin-top:20px;justify-content:flex-end">
        <button class="btn btn-outline" onclick="document.getElementById('rcModal').remove()">Cancel</button>
        <button class="btn btn-primary" onclick="submitCreateRestCoupon()">Create Coupon</button>
      </div>
    </div>`;
  document.body.appendChild(overlay);
}

function showEditRestCoupon(c) {
  const overlay = document.createElement('div');
  overlay.id = 'rcModal';
  overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;display:flex;align-items:center;justify-content:center;padding:16px';
  overlay.innerHTML = `
    <div style="background:#fff;border-radius:12px;padding:24px;width:100%;max-width:560px;max-height:90vh;overflow-y:auto">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px">
        <h3 style="font-size:18px;font-weight:700">Edit Coupon — ${escHtml(c.code)}</h3>
        <button onclick="document.getElementById('rcModal').remove()" style="background:none;border:none;font-size:20px;cursor:pointer;color:#777">✕</button>
      </div>
      ${couponFormFields(c)}
      <div style="display:flex;gap:10px;margin-top:20px;justify-content:flex-end">
        <button class="btn btn-outline" onclick="document.getElementById('rcModal').remove()">Cancel</button>
        <button class="btn btn-primary" onclick="submitEditRestCoupon(${c.id})">Save Changes</button>
      </div>
    </div>`;
  document.body.appendChild(overlay);
}

function collectCouponForm() {
  return {
    code:               document.getElementById('rcCode').value.trim().toUpperCase(),
    discount_type:      document.getElementById('rcType').value,
    discount_value:     parseFloat(document.getElementById('rcValue').value) || 0,
    min_order_amount:   parseFloat(document.getElementById('rcMin').value)   || 0,
    max_discount_amount:parseFloat(document.getElementById('rcMax').value)   || null,
    per_user_limit:     parseInt(document.getElementById('rcPerUser').value) || 1,
    cooldown_hours:     parseInt(document.getElementById('rcCooldown').value)|| null,
    valid_from:         document.getElementById('rcValidFrom').value         || new Date().toISOString().split('T')[0],
    valid_until:        document.getElementById('rcExpiry').value            || '2099-12-31',
  };
}

async function submitCreateRestCoupon() {
  const data = collectCouponForm();
  const err  = document.getElementById('rcErr');
  if (!data.code || !data.discount_value) { err.textContent = 'Code and discount value are required.'; err.style.display = 'block'; return; }
  const res = await Api.coupons.create(data);
  if (res?.success) {
    document.getElementById('rcModal').remove();
    showToast('Coupon created!', 'success');
    loadRestCoupons();
  } else {
    err.textContent = res?.message || 'Failed to create coupon.';
    err.style.display = 'block';
  }
}

async function submitEditRestCoupon(id) {
  const data = collectCouponForm();
  const err  = document.getElementById('rcErr');
  if (!data.discount_value) { err.textContent = 'Discount value is required.'; err.style.display = 'block'; return; }
  const res = await Api.coupons.update(id, data);
  if (res?.success) {
    document.getElementById('rcModal').remove();
    showToast('Coupon updated!', 'success');
    loadRestCoupons();
  } else {
    err.textContent = res?.message || 'Failed to update.';
    err.style.display = 'block';
  }
}

async function toggleRestCoupon(id, btn) {
  btn.disabled = true;
  const res = await Api.coupons.toggle(id);
  if (res?.success) { loadRestCoupons(); }
  else { showToast('Failed to update status.', 'error'); btn.disabled = false; }
}
