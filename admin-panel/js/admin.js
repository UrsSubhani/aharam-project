/**
 * admin.js — Aharam Admin Console SPA
 *
 * Pages: dashboard | restaurants | orders | users | delivery
 *         earnings | settlements | coupons | settings
 */

let currentPage = 'dashboard';

// ── Init ────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', async () => {
  if (!Auth.isLoggedIn()) { window.location.href = 'login.html'; return; }

  const meRes = await Api.auth.me();
  if (!meRes?.success || meRes.data.role !== 'admin') { Auth.logout(); return; }

  const u = meRes.data;
  document.getElementById('adminName').textContent     = u.name || 'Admin';
  document.getElementById('avatarInitial').textContent = (u.name || 'A')[0].toUpperCase();
  document.getElementById('todayDate').textContent     = new Date().toLocaleDateString('en-IN', { weekday: 'short', day: '2-digit', month: 'short', year: 'numeric' });

  // Nav clicks
  document.querySelectorAll('.nav-item').forEach(item => {
    item.addEventListener('click', () => navigate(item.dataset.page));
  });

  navigate('dashboard');
  loadBadges();
});

async function loadBadges() {
  const [restRes, partnerRes] = await Promise.all([
    Api.admin.restaurants('?status=pending'),
    Api.admin.deliveryPartners(),
  ]);

  const pendingRest = (restRes?.data || []).filter(r => r.approval_status === 'pending').length;
  const pendingPart = (partnerRes?.data || []).filter(p => !p.is_verified).length;

  const rb = document.getElementById('pendingRestBadge');
  const pb = document.getElementById('pendingPartnerBadge');
  if (rb) { rb.textContent = pendingRest; rb.style.display = pendingRest ? 'inline-block' : 'none'; }
  if (pb) { pb.textContent = pendingPart; pb.style.display = pendingPart ? 'inline-block' : 'none'; }
}

function navigate(page) {
  currentPage = page;
  document.querySelectorAll('.nav-item').forEach(i => i.classList.toggle('active', i.dataset.page === page));
  const titles = { dashboard: 'Dashboard', restaurants: 'Restaurants', orders: 'Orders',
    users: 'Users', delivery: 'Delivery Partners', earnings: 'Earnings',
    settlements: 'Settlements', coupons: 'Coupons', categories: 'Menu Categories', settings: 'Settings' };
  document.getElementById('pageTitle').textContent = titles[page] || page;

  const content = document.getElementById('pageContent');
  content.innerHTML = '<div class="empty-state"><div class="empty-icon">⏳</div><div class="empty-title">Loading…</div></div>';

  const loaders = {
    dashboard:   loadDashboard,
    restaurants: loadRestaurants,
    orders:      loadOrders,
    users:       loadUsers,
    delivery:    loadDeliveryPartners,
    earnings:    loadEarnings,
    settlements: loadSettlements,
    coupons:     loadCoupons,
    categories:  loadMenuCategories,
    wallet:      loadAdminWallet,
    settings:    loadSettings,
  };
  (loaders[page] || (() => {}))();
}

function refreshPage() { navigate(currentPage); }

// ── Modal helpers ────────────────────────────────────────
function openModal(title, bodyHtml, footerHtml = '') {
  document.getElementById('modalTitle').textContent  = title;
  document.getElementById('modalBody').innerHTML     = bodyHtml;
  document.getElementById('modalFooter').innerHTML   = footerHtml;
  document.getElementById('modalOverlay').classList.add('open');
}
function closeModal() { document.getElementById('modalOverlay').classList.remove('open'); }
document.getElementById('modalOverlay').addEventListener('click', e => {
  if (e.target === e.currentTarget) closeModal();
});

// ═══════════════════════════════════════════════════════════
// DASHBOARD
// ═══════════════════════════════════════════════════════════
async function loadDashboard() {
  const res = await Api.admin.dashboard();
  const d   = res?.data || {};
  const s   = d.stats || {};

  document.getElementById('pageContent').innerHTML = `
    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-icon orange">📦</div>
        <div><div class="stat-val">${s.total_orders || 0}</div><div class="stat-label">Total Orders</div><div class="stat-change up">+${s.orders_today || 0} today</div></div>
      </div>
      <div class="stat-card">
        <div class="stat-icon green">💰</div>
        <div><div class="stat-val">${fmtMoney(s.total_revenue)}</div><div class="stat-label">Platform Revenue</div><div class="stat-change up">+${fmtMoney(s.revenue_today)} today</div></div>
      </div>
      <div class="stat-card">
        <div class="stat-icon blue">🏪</div>
        <div><div class="stat-val">${s.active_restaurants || 0}</div><div class="stat-label">Active Restaurants</div><div class="stat-change">${s.pending_restaurants || 0} pending approval</div></div>
      </div>
      <div class="stat-card">
        <div class="stat-icon red">👥</div>
        <div><div class="stat-val">${s.total_users || 0}</div><div class="stat-label">Registered Users</div><div class="stat-change up">+${s.users_today || 0} today</div></div>
      </div>
      <div class="stat-card">
        <div class="stat-icon purple">🛵</div>
        <div><div class="stat-val">${s.active_partners || 0}</div><div class="stat-label">Delivery Partners</div><div class="stat-change">${s.online_partners || 0} online now</div></div>
      </div>
      <div class="stat-card">
        <div class="stat-icon orange">🎟</div>
        <div><div class="stat-val">${s.active_coupons || 0}</div><div class="stat-label">Active Coupons</div><div class="stat-change">${s.coupon_uses_today || 0} used today</div></div>
      </div>
    </div>

    <div class="two-col">
      <div class="section">
        <div class="section-header"><div class="section-title">Recent Orders</div></div>
        <div class="table-wrap">
          <table>
            <thead><tr><th>Order</th><th>Customer</th><th>Amount</th><th>Status</th><th>Time</th></tr></thead>
            <tbody id="dashRecentOrders"><tr><td colspan="5" style="text-align:center;color:#777;padding:24px">Loading…</td></tr></tbody>
          </table>
        </div>
      </div>
      <div class="section">
        <div class="section-header"><div class="section-title">Pending Approvals</div></div>
        <div id="dashPending" style="padding:16px">Loading…</div>
      </div>
    </div>`;

  // Load recent orders
  const ordRes = await Api.admin.orders('?limit=8&sort=latest');
  const orders = ordRes?.data || [];
  document.getElementById('dashRecentOrders').innerHTML = orders.length
    ? orders.map(o => `
        <tr>
          <td><strong>${escHtml(o.order_number)}</strong></td>
          <td>${escHtml(o.customer_name)}</td>
          <td>${fmtMoney(o.total_amount)}</td>
          <td><span class="badge ${statusBadge(o.status)}">${o.status}</span></td>
          <td style="color:#777">${fmtDateTime(o.created_at)}</td>
        </tr>`).join('')
    : '<tr><td colspan="5" style="text-align:center;color:#777;padding:24px">No orders yet</td></tr>';

  // Load pending approvals
  const [restRes, partRes] = await Promise.all([Api.admin.restaurants('?status=pending'), Api.admin.deliveryPartners()]);
  const pendingRest  = (restRes?.data  || []).filter(r => !r.is_active);
  const pendingParts = (partRes?.data  || []).filter(p => !p.is_verified);

  let pendHtml = '';
  pendingRest.forEach(r => {
    pendHtml += `<div style="display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid #f0f0f0">
      <div>
        <div style="font-weight:600;font-size:13px">${escHtml(r.name)}</div>
        <div style="font-size:12px;color:#777">🏪 Restaurant · ${escHtml(r.city || '—')}</div>
      </div>
      <button class="btn btn-sm btn-outline" onclick="navigate('restaurants')">Review →</button>
    </div>`;
  });
  pendingParts.forEach(p => {
    pendHtml += `<div style="display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid #f0f0f0">
      <div>
        <div style="font-weight:600;font-size:13px">${escHtml(p.name)}</div>
        <div style="font-size:12px;color:#777">🛵 Delivery Partner · ${escHtml(p.city || '—')}</div>
      </div>
      <button class="btn btn-sm btn-outline" onclick="navigate('delivery')">Review →</button>
    </div>`;
  });
  document.getElementById('dashPending').innerHTML = pendHtml ||
    '<div style="text-align:center;color:#777;padding:24px">No pending approvals</div>';
}

// ═══════════════════════════════════════════════════════════
// RESTAURANTS
// ═══════════════════════════════════════════════════════════
let restFilter = { status: '', search: '', page: 1 };

async function loadRestaurants() {
  document.getElementById('pageContent').innerHTML = `
    <div class="section">
      <div class="filter-bar">
        <input type="text"   id="restSearch"  placeholder="Search by name or city…" oninput="restFilter.search=this.value;restFilter.page=1;fetchRestaurants()" style="flex:1;min-width:180px"/>
        <select id="restStatus" onchange="restFilter.status=this.value;restFilter.page=1;fetchRestaurants()">
          <option value="">All Status</option>
          <option value="pending">Pending Approval</option>
          <option value="approved">Approved</option>
          <option value="rejected">Rejected</option>
        </select>
      </div>
      <div class="table-wrap">
        <table>
          <thead><tr><th>#</th><th>Restaurant</th><th>Owner</th><th>City</th><th>Commission</th><th>Subscription</th><th>Approval</th><th>Status</th><th>Actions</th></tr></thead>
          <tbody id="restTableBody"><tr><td colspan="9" style="text-align:center;padding:32px;color:#777">Loading…</td></tr></tbody>
        </table>
      </div>
      <div id="restPagination" class="pagination"></div>
    </div>`;
  fetchRestaurants();
}

async function fetchRestaurants() {
  let qs = '?';
  if (restFilter.status) qs += `status=${restFilter.status}&`;
  if (restFilter.search) qs += `search=${encodeURIComponent(restFilter.search)}&`;
  qs += `page=${restFilter.page}`;

  const res  = await Api.admin.restaurants(qs);
  const rows = res?.data || [];
  const meta = res?.meta || {};

  const tbody = document.getElementById('restTableBody');
  if (!rows.length) {
    tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;padding:32px;color:#777">No restaurants found</td></tr>';
    document.getElementById('restPagination').innerHTML = '';
    return;
  }

  tbody.innerHTML = rows.map(r => `
    <tr>
      <td style="color:#777">${r.id}</td>
      <td>
        <div style="font-weight:600">${escHtml(r.name)}</div>
        <div style="font-size:12px;color:#777">${escHtml(r.phone || '')}</div>
      </td>
      <td style="font-size:13px">${escHtml(r.owner_name || '—')}</td>
      <td style="font-size:13px">${escHtml(r.city || '—')}</td>
      <td style="font-size:13px">${r.commission_percent || 20}%</td>
      <td><span class="badge ${r.has_subscription ? 'badge-success' : 'badge-default'}">${r.has_subscription ? 'Active' : 'None'}</span></td>
      <td><span class="badge ${r.approval_status === 'approved' ? 'badge-success' : r.approval_status === 'rejected' ? 'badge-danger' : 'badge-warning'}">${r.approval_status || 'pending'}</span></td>
      <td><span class="badge ${r.is_active ? 'badge-success' : 'badge-default'}">${r.is_active ? 'Active' : 'Inactive'}</span></td>
      <td style="white-space:nowrap">
        ${r.approval_status === 'pending' || r.approval_status === 'rejected' ? `
          <button class="btn btn-sm btn-success" onclick="approveRestaurant(${r.id},'approve')">✓ Approve</button>
          <button class="btn btn-sm btn-danger"  onclick="approveRestaurant(${r.id},'reject')">✗ Reject</button>` :
        r.is_active ? `
          <button class="btn btn-sm btn-outline" onclick="viewRestaurant(${r.id})">View</button>
          <button class="btn btn-sm btn-danger"  onclick="approveRestaurant(${r.id},'deactivate')">Deactivate</button>` : `
          <button class="btn btn-sm btn-outline" onclick="viewRestaurant(${r.id})">View</button>
          <button class="btn btn-sm btn-success" onclick="approveRestaurant(${r.id},'activate')">Activate</button>`}
      </td>
    </tr>`).join('');

  renderPagination('restPagination', meta, p => { restFilter.page = p; fetchRestaurants(); });
}

async function approveRestaurant(id, action) {
  const res = await Api.admin.approveRestaurant(id, action);
  if (res?.success) {
    showToast(res.message || 'Done!', 'success');
    if (currentPage === 'dashboard') {
      loadDashboard();
    } else {
      fetchRestaurants();
    }
  } else {
    showToast(res?.message || 'Action failed.', 'error');
  }
}

function viewRestaurant(id) {
  openModal('Restaurant Details', '<div style="text-align:center;padding:20px;color:#777">Loading…</div>');
  Api.get(`/admin/restaurants/${id}`).then(res => {
    const r = res?.data;
    if (!r) { document.getElementById('modalBody').innerHTML = '<p style="color:#f44336">Failed to load.</p>'; return; }
    document.getElementById('modalBody').innerHTML = `
      <div class="form-row" style="margin-bottom:12px">
        <div><div class="form-label">Name</div><div>${escHtml(r.name)}</div></div>
        <div><div class="form-label">City</div><div>${escHtml(r.city || '—')}</div></div>
      </div>
      <div class="form-row" style="margin-bottom:12px">
        <div><div class="form-label">Phone</div><div>${escHtml(r.phone || '—')}</div></div>
        <div><div class="form-label">Cuisine</div><div>${escHtml(r.cuisine_type || '—')}</div></div>
      </div>
      <div style="margin-bottom:12px"><div class="form-label">Address</div><div>${escHtml(r.address || '—')}</div></div>
      <div class="form-row" style="margin-bottom:16px">
        <div><div class="form-label">Total Orders</div><div>${r.total_orders || 0}</div></div>
        <div><div class="form-label">Rating</div><div>${r.avg_rating || '—'} ⭐</div></div>
      </div>
      <hr style="margin-bottom:16px;border-color:#f0f0f0"/>
      <div class="form-label" style="font-weight:700;margin-bottom:8px">Commission Rate</div>
      <div style="font-size:12px;color:#777;margin-bottom:10px">Platform default is 20%. Reduce for high-volume or premium partners.</div>
      <div style="display:flex;gap:8px;align-items:center">
        <input type="number" id="modalCommission" value="${r.commission_percent || 20}" min="1" max="50" step="0.5"
               class="form-control" style="width:100px"/>
        <span style="font-size:14px">%</span>
        <button class="btn btn-primary btn-sm" onclick="saveRestaurantCommission(${r.id})">Save Commission</button>
      </div>
      <div id="commissionMsg" style="margin-top:8px;font-size:13px"></div>`;
    document.getElementById('modalFooter').innerHTML = `<button class="btn btn-outline" onclick="closeModal()">Close</button>`;
  });
}

async function saveRestaurantCommission(id) {
  const val = parseFloat(document.getElementById('modalCommission').value);
  if (!val || val < 1 || val > 50) {
    document.getElementById('commissionMsg').innerHTML = '<span style="color:#c62828">Enter a value between 1–50%</span>';
    return;
  }
  const res = await Api.patch(`/admin/restaurants/${id}/commission`, { commission_percent: val });
  const msg = document.getElementById('commissionMsg');
  if (res?.success) {
    msg.innerHTML = '<span style="color:#2e7d32">✓ Commission updated!</span>';
    fetchRestaurants();
  } else {
    msg.innerHTML = `<span style="color:#c62828">${res?.message || 'Failed to save'}</span>`;
  }
}

// ═══════════════════════════════════════════════════════════
// ORDERS
// ═══════════════════════════════════════════════════════════
let orderFilter = { status: '', search: '', page: 1 };

async function loadOrders() {
  document.getElementById('pageContent').innerHTML = `
    <div class="section">
      <div class="filter-bar">
        <input type="text" id="orderSearch" placeholder="Search order number…" oninput="orderFilter.search=this.value;orderFilter.page=1;fetchOrders()" style="flex:1;min-width:180px"/>
        <select id="orderStatus" onchange="orderFilter.status=this.value;orderFilter.page=1;fetchOrders()">
          <option value="">All Status</option>
          <option value="pending">Pending</option>
          <option value="confirmed">Confirmed</option>
          <option value="preparing">Preparing</option>
          <option value="ready">Ready</option>
          <option value="picked_up">Picked Up</option>
          <option value="delivered">Delivered</option>
          <option value="cancelled">Cancelled</option>
        </select>
      </div>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Order #</th><th>Customer</th><th>Restaurant</th><th>Amount</th><th>Commission</th><th>Payment</th><th>Status</th><th>Date</th><th>Assign</th></tr></thead>
          <tbody id="orderTableBody"><tr><td colspan="9" style="text-align:center;padding:32px;color:#777">Loading…</td></tr></tbody>
        </table>
      </div>
      <div id="orderPagination" class="pagination"></div>
    </div>`;
  fetchOrders();
}

async function fetchOrders() {
  let qs = '?';
  if (orderFilter.status) qs += `status=${orderFilter.status}&`;
  if (orderFilter.search) qs += `search=${encodeURIComponent(orderFilter.search)}&`;
  qs += `page=${orderFilter.page}`;

  const res  = await Api.admin.orders(qs);
  const rows = res?.data || [];
  const meta = res?.meta || {};

  const tbody = document.getElementById('orderTableBody');
  if (!rows.length) {
    tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;padding:32px;color:#777">No orders found</td></tr>';
    document.getElementById('orderPagination').innerHTML = '';
    return;
  }

  tbody.innerHTML = rows.map(o => `
    <tr>
      <td><strong style="font-size:13px">${escHtml(o.order_number)}</strong></td>
      <td style="font-size:13px">${escHtml(o.customer_name || '—')}</td>
      <td style="font-size:13px">${escHtml(o.restaurant_name || '—')}</td>
      <td style="font-weight:600">${fmtMoney(o.total_amount)}</td>
      <td style="color:${o.status === 'delivered' ? '#4caf50' : '#ff5722'};font-weight:600">${fmtMoney(o.commission_amount)}</td>
      <td><span class="badge ${o.payment_method === 'cod' ? 'badge-warning' : 'badge-info'}">${o.payment_method || '—'}</span></td>
      <td><span class="badge ${statusBadge(o.status)}">${o.status}</span></td>
      <td style="color:#777;font-size:12px">${fmtDateTime(o.created_at)}</td>
      <td>${o.status === 'ready' ? `<button class="btn btn-sm btn-primary" onclick="openAssignModal(${o.id},'${escHtml(o.order_number)}')">🛵 Assign</button>` : '—'}</td>
    </tr>`).join('');

  renderPagination('orderPagination', meta, p => { orderFilter.page = p; fetchOrders(); });
}

// ── Manual Assign Delivery Partner ───────────────────────
async function openAssignModal(orderId, orderNumber) {
  // Fetch all verified, available delivery partners
  const res = await Api.get('/admin/delivery-partners?page=1&limit=50');
  const partners = (res?.data || []).filter(p => p.is_verified == 1);

  const modalHtml = `
    <div id="assignModal" style="position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;display:flex;align-items:center;justify-content:center;padding:16px">
      <div style="background:#fff;border-radius:16px;padding:28px;width:min(480px,100%);max-height:80vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.3)">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px">
          <div>
            <h3 style="font-size:16px;font-weight:700;margin:0">🛵 Assign Delivery Partner</h3>
            <div style="font-size:12px;color:#777;margin-top:2px">Order ${escHtml(orderNumber)}</div>
          </div>
          <button onclick="document.getElementById('assignModal').remove()" style="background:none;border:none;font-size:22px;cursor:pointer;color:#aaa">×</button>
        </div>
        ${!partners.length ? '<p style="text-align:center;color:#aaa;padding:20px">No verified delivery partners found.</p>' :
          partners.map(p => `
            <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 14px;border:1.5px solid ${p.is_available ? '#e8f5e9' : '#f0f0f0'};border-radius:10px;margin-bottom:8px;background:${p.is_available ? '#f9fff9' : '#fafafa'}">
              <div>
                <div style="font-weight:700;font-size:14px">${escHtml(p.name)}</div>
                <div style="font-size:12px;color:#777;margin-top:2px">${escHtml(p.city || '—')} · ${escHtml(p.vehicle_type || '—')} · ${escHtml(p.phone || '')}</div>
                <div style="font-size:11px;margin-top:3px">
                  <span style="color:${p.is_available ? '#4caf50' : '#aaa'};font-weight:600">${p.is_available ? '● Online' : '○ Offline'}</span>
                  <span style="color:#aaa;margin-left:8px">${p.total_deliveries || 0} deliveries</span>
                </div>
              </div>
              <button onclick="confirmAssign(${orderId}, ${p.partner_id || p.id}, '${escHtml(p.name)}')"
                style="padding:8px 16px;background:#ff5722;color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit">
                Assign
              </button>
            </div>`).join('')}
      </div>
    </div>`;

  document.body.insertAdjacentHTML('beforeend', modalHtml);
}

async function confirmAssign(orderId, partnerId, partnerName) {
  if (!confirm(`Assign ${partnerName} to this order?`)) return;
  const res = await Api.post('/delivery/assign', { order_id: orderId, partner_id: partnerId });
  document.getElementById('assignModal')?.remove();
  if (res?.success) {
    showToast(`Assigned to ${partnerName}!`, 'success');
    fetchOrders();
  } else {
    showToast(res?.message || 'Assignment failed.', 'error');
  }
}

// ═══════════════════════════════════════════════════════════
// USERS
// ═══════════════════════════════════════════════════════════
let userFilter = { role: '', search: '', page: 1 };

async function loadUsers() {
  document.getElementById('pageContent').innerHTML = `
    <div class="section">
      <div class="filter-bar">
        <input type="text" id="userSearch" placeholder="Search name or email…" oninput="userFilter.search=this.value;userFilter.page=1;fetchUsers()" style="flex:1;min-width:180px"/>
        <select id="userRole" onchange="userFilter.role=this.value;userFilter.page=1;fetchUsers()">
          <option value="">All Roles</option>
          <option value="customer">Customer</option>
          <option value="restaurant_owner">Restaurant Owner</option>
          <option value="delivery_partner">Delivery Partner</option>
          <option value="admin">Admin</option>
        </select>
      </div>
      <div class="table-wrap">
        <table>
          <thead><tr><th>#</th><th>Name</th><th>Email</th><th>Phone</th><th>Role</th><th>Wallet</th><th>Joined</th><th>Action</th></tr></thead>
          <tbody id="userTableBody"><tr><td colspan="8" style="text-align:center;padding:32px;color:#777">Loading…</td></tr></tbody>
        </table>
      </div>
      <div id="userPagination" class="pagination"></div>
    </div>`;
  fetchUsers();
}

async function fetchUsers() {
  let qs = '?';
  if (userFilter.role)   qs += `role=${userFilter.role}&`;
  if (userFilter.search) qs += `search=${encodeURIComponent(userFilter.search)}&`;
  qs += `page=${userFilter.page}`;

  const res  = await Api.admin.users(qs);
  const rows = res?.data || [];
  const meta = res?.meta || {};

  const tbody = document.getElementById('userTableBody');
  if (!rows.length) {
    tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:32px;color:#777">No users found</td></tr>';
    document.getElementById('userPagination').innerHTML = '';
    return;
  }

  tbody.innerHTML = rows.map(u => `
    <tr>
      <td style="color:#777">${u.id}</td>
      <td><div style="font-weight:600;font-size:13px">${escHtml(u.name)}</div></td>
      <td style="font-size:13px">${escHtml(u.email)}</td>
      <td style="font-size:13px">${escHtml(u.phone || '—')}</td>
      <td><span class="badge ${roleBadge(u.role)}">${u.role}</span></td>
      <td style="font-weight:600;color:#22c55e">₹${parseFloat(u.wallet_balance||0).toFixed(2)}</td>
      <td style="font-size:12px;color:#777">${fmtDate(u.created_at)}</td>
      <td><button onclick="openCreditWallet(${u.id},'${escHtml(u.name)}')" style="background:#ff5722;color:#fff;border:none;padding:4px 10px;border-radius:4px;cursor:pointer;font-size:12px;white-space:nowrap">+ Wallet</button></td>
    </tr>`).join('');

  renderPagination('userPagination', meta, p => { userFilter.page = p; fetchUsers(); });
}

function openCreditWallet(userId, userName) {
  document.getElementById('creditWalletModal')?.remove();
  const html = `
    <div id="creditWalletModal" style="position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:9999;display:flex;align-items:center;justify-content:center">
      <div style="background:#fff;border-radius:12px;padding:28px;width:340px;box-shadow:0 8px 32px rgba(0,0,0,0.2)">
        <div style="font-size:16px;font-weight:700;margin-bottom:4px">💳 Credit Wallet</div>
        <div style="color:#777;font-size:13px;margin-bottom:18px">${escHtml(userName)}</div>
        <div class="form-group">
          <label class="form-label">Amount (₹)</label>
          <input type="number" id="creditAmount" class="form-control" placeholder="e.g. 200" min="1" style="font-size:18px;font-weight:600"/>
        </div>
        <div class="form-group" style="margin-top:10px">
          <label class="form-label">Note (optional)</label>
          <input type="text" id="creditNote" class="form-control" placeholder="e.g. Goodwill bonus" value="Admin wallet credit"/>
        </div>
        <div style="display:flex;gap:10px;margin-top:18px">
          <button onclick="document.getElementById('creditWalletModal').remove()" style="flex:1;padding:10px;border:1px solid #ddd;background:#fff;border-radius:6px;cursor:pointer">Cancel</button>
          <button onclick="submitCreditWallet(${userId})" style="flex:1;padding:10px;background:#22c55e;color:#fff;border:none;border-radius:6px;cursor:pointer;font-weight:600">Credit ₹</button>
        </div>
      </div>
    </div>`;
  document.body.insertAdjacentHTML('beforeend', html);
  setTimeout(() => document.getElementById('creditAmount')?.focus(), 50);
}

async function submitCreditWallet(userId) {
  const amount = parseFloat(document.getElementById('creditAmount').value);
  const note   = document.getElementById('creditNote').value.trim();
  if (!amount || amount <= 0) { showToast('Enter a valid amount.', 'error'); return; }

  const btn = document.querySelector('#creditWalletModal button:last-child');
  btn.disabled = true; btn.textContent = 'Crediting…';

  const res = await Api.admin.creditWallet(userId, amount, note);
  document.getElementById('creditWalletModal')?.remove();
  if (res?.success) {
    showToast(res.message || `₹${amount} credited!`);
    fetchUsers();
  } else {
    showToast(res?.message || 'Failed to credit wallet.', 'error');
  }
}

// ═══════════════════════════════════════════════════════════
// DELIVERY PARTNERS
// ═══════════════════════════════════════════════════════════
let allPartners = [];

async function loadDeliveryPartners() {
  document.getElementById('pageContent').innerHTML = `
    <div class="section">
      <div class="section-header">
        <div class="section-title">Delivery Partners</div>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
          <input type="text" id="partnerSearch" class="form-control" placeholder="Search name, phone, city…" style="width:220px" oninput="filterPartners()"/>
          <select id="partnerVerifyFilter" class="form-control" style="width:140px" onchange="filterPartners()">
            <option value="">All Status</option>
            <option value="approved">Verified</option>
            <option value="pending">Pending</option>
            <option value="suspended">Suspended</option>
          </select>
        </div>
      </div>
      <div class="table-wrap">
        <table>
          <thead><tr><th>#</th><th>Name</th><th>Phone</th><th>Vehicle</th><th>City</th><th>Total Del.</th><th>Earnings</th><th>Verification</th><th>Online</th><th>Actions</th></tr></thead>
          <tbody id="partnerTableBody"><tr><td colspan="10" style="text-align:center;padding:32px;color:#777">Loading…</td></tr></tbody>
        </table>
      </div>
    </div>`;
  fetchDeliveryPartners();
}

async function fetchDeliveryPartners() {
  const res = await Api.admin.deliveryPartners();
  allPartners = res?.data || [];
  renderPartners(allPartners);
}

function filterPartners() {
  const q      = (document.getElementById('partnerSearch')?.value || '').toLowerCase();
  const status = document.getElementById('partnerVerifyFilter')?.value || '';
  const filtered = allPartners.filter(p => {
    const matchQ = !q || (p.name||'').toLowerCase().includes(q)
                       || (p.phone||'').includes(q)
                       || (p.city||'').toLowerCase().includes(q);
    const vs = p.verification_status || (p.is_verified ? 'approved' : 'pending');
    const matchS = !status || vs === status;
    return matchQ && matchS;
  });
  renderPartners(filtered);
}

function renderPartners(rows) {
  document.getElementById('partnerTableBody').innerHTML = rows.length
    ? rows.map(p => `
        <tr>
          <td style="color:#777">${p.id}</td>
          <td style="font-weight:600;font-size:13px">${escHtml(p.name)}</td>
          <td style="font-size:13px">${escHtml(p.phone || '—')}</td>
          <td style="font-size:13px">${escHtml(p.vehicle_type || '—')}</td>
          <td style="font-size:13px">${escHtml(p.city || '—')}</td>
          <td>${p.total_deliveries || 0}</td>
          <td style="font-weight:600;color:#ff5722">${fmtMoney(p.total_earnings)}</td>
          <td>${(() => {
            const s = p.verification_status || (p.is_verified ? 'approved' : 'pending');
            if (s === 'approved')  return '<span class="badge badge-success">Verified</span>';
            if (s === 'suspended') return '<span class="badge badge-danger">Suspended</span>';
            return '<span class="badge badge-warning">Pending</span>';
          })()}</td>
          <td><span class="badge ${p.is_available ? 'badge-success' : 'badge-default'}">${p.is_available ? 'Online' : 'Offline'}</span></td>
          <td style="white-space:nowrap">${(() => {
            const s = p.verification_status || (p.is_verified ? 'approved' : 'pending');
            if (s === 'approved')  return `<button class="btn btn-sm btn-outline" onclick="viewPartnerDetail(${p.id})">View</button>
                                            <button class="btn btn-sm btn-danger" onclick="verifyPartner(${p.id},'rejected')">Suspend</button>`;
            if (s === 'suspended') return `<button class="btn btn-sm btn-outline" onclick="viewPartnerDetail(${p.id})">View</button>
                                            <button class="btn btn-sm btn-success" onclick="verifyPartner(${p.id},'approved')">Re-verify</button>`;
            return `<button class="btn btn-sm btn-outline" onclick="viewPartnerDetail(${p.id})">🔍 Review</button>`;
          })()}</td>
        </tr>`).join('')
    : '<tr><td colspan="10" style="text-align:center;padding:32px;color:#777">No results found</td></tr>';
}

async function verifyPartner(id, status) {
  const res = await Api.admin.verifyPartner(id, status);
  if (res?.success) {
    showToast(`Partner ${status}!`, 'success');
    fetchDeliveryPartners(); loadBadges();
  } else {
    showToast(res?.message || 'Action failed.', 'error');
  }
}

async function viewPartnerDetail(id) {
  openModal('Delivery Partner Details', '<div style="text-align:center;padding:20px;color:#777">Loading…</div>');

  const res = await Api.get(`/admin/delivery-partners/${id}`);
  const p   = res?.data;
  if (!p) { document.getElementById('modalBody').innerHTML = '<p style="color:#f44336">Failed to load.</p>'; return; }

  const verified = p.is_verified;
  document.getElementById('modalBody').innerHTML = `
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px">
      <div>
        <div style="font-size:11px;font-weight:700;color:#aaa;text-transform:uppercase;margin-bottom:4px">Full Name</div>
        <div style="font-weight:600">${escHtml(p.name)}</div>
      </div>
      <div>
        <div style="font-size:11px;font-weight:700;color:#aaa;text-transform:uppercase;margin-bottom:4px">Phone</div>
        <div>${escHtml(p.phone || '—')}</div>
      </div>
      <div>
        <div style="font-size:11px;font-weight:700;color:#aaa;text-transform:uppercase;margin-bottom:4px">Email</div>
        <div style="font-size:13px">${escHtml(p.email || '—')}</div>
      </div>
      <div>
        <div style="font-size:11px;font-weight:700;color:#aaa;text-transform:uppercase;margin-bottom:4px">City</div>
        <div>${escHtml(p.city || '—')}</div>
      </div>
    </div>
    <div style="background:#f8f9fa;border-radius:8px;padding:14px;margin-bottom:16px">
      <div style="font-size:12px;font-weight:700;color:#555;margin-bottom:10px">🏍 Vehicle Details</div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;font-size:13px">
        <div><span style="color:#777">Type:</span> <strong>${escHtml(p.vehicle_type || '—')}</strong></div>
        <div><span style="color:#777">Number:</span> <strong>${escHtml(p.vehicle_number || '—')}</strong></div>
        <div><span style="color:#777">License:</span> <strong>${escHtml(p.license_number || '—')}</strong></div>
        <div><span style="color:#777">Aadhaar:</span> <strong>${p.aadhar_number ? '••••' + p.aadhar_number.slice(-4) : '—'}</strong></div>
      </div>
    </div>
    <div style="background:#f8f9fa;border-radius:8px;padding:14px;margin-bottom:16px">
      <div style="font-size:12px;font-weight:700;color:#555;margin-bottom:10px">🏦 Bank Details</div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;font-size:13px">
        <div><span style="color:#777">Account:</span> <strong>${p.bank_account ? '••••' + p.bank_account.slice(-4) : 'Not provided'}</strong></div>
        <div><span style="color:#777">IFSC:</span> <strong>${escHtml(p.ifsc_code || 'Not provided')}</strong></div>
      </div>
    </div>
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;font-size:13px;text-align:center">
      <div style="background:#fff3e0;border-radius:8px;padding:10px">
        <div style="font-weight:700;font-size:16px;color:#ff5722">${p.total_deliveries || 0}</div>
        <div style="color:#777;font-size:11px">Deliveries</div>
      </div>
      <div style="background:#e8f5e9;border-radius:8px;padding:10px">
        <div style="font-weight:700;font-size:16px;color:#4caf50">${fmtMoney(p.total_earnings)}</div>
        <div style="color:#777;font-size:11px">Earnings</div>
      </div>
      <div style="background:#e3f2fd;border-radius:8px;padding:10px">
        <div style="font-weight:700;font-size:16px;color:#1976d2">${parseFloat(p.avg_rating || 0).toFixed(1)} ⭐</div>
        <div style="color:#777;font-size:11px">Rating</div>
      </div>
    </div>`;

  document.getElementById('modalFooter').innerHTML = verified
    ? `<button class="btn btn-outline" onclick="closeModal()">Close</button>
       <button class="btn btn-danger" onclick="verifyPartner(${p.id},'rejected');closeModal()">Suspend Partner</button>`
    : `<button class="btn btn-outline" onclick="verifyPartner(${p.id},'rejected');closeModal()">✗ Reject</button>
       <button class="btn btn-success" onclick="verifyPartner(${p.id},'approved');closeModal()">✓ Verify & Approve</button>`;
}

// ═══════════════════════════════════════════════════════════
// EARNINGS
// ═══════════════════════════════════════════════════════════
async function loadEarnings() {
  const res = await Api.admin.earnings();
  const d   = res?.data || {};

  document.getElementById('pageContent').innerHTML = `
    <div class="stats-grid" style="margin-bottom:24px">
      <div class="stat-card">
        <div class="stat-icon green">💰</div>
        <div><div class="stat-val">${fmtMoney(d.total_platform_earnings)}</div><div class="stat-label">Total Platform Revenue</div></div>
      </div>
      <div class="stat-card">
        <div class="stat-icon orange">📅</div>
        <div><div class="stat-val">${fmtMoney(d.earnings_today)}</div><div class="stat-label">Earned Today</div></div>
      </div>
      <div class="stat-card">
        <div class="stat-icon blue">📊</div>
        <div><div class="stat-val">${fmtMoney(d.earnings_this_month)}</div><div class="stat-label">This Month</div></div>
      </div>
      <div class="stat-card">
        <div class="stat-icon purple">🎟</div>
        <div><div class="stat-val">${fmtMoney(d.total_discount_given)}</div><div class="stat-label">Discounts Given</div></div>
      </div>
    </div>

    <div class="two-col">
      <div class="section">
        <div class="section-header"><div class="section-title">Top Earning Restaurants</div></div>
        <div class="table-wrap">
          <table>
            <thead><tr><th>Restaurant</th><th>Orders</th><th>Commission Paid</th></tr></thead>
            <tbody>${(d.top_restaurants || []).map(r => `
              <tr>
                <td style="font-weight:600">${escHtml(r.name)}</td>
                <td>${r.order_count || 0}</td>
                <td style="color:#ff5722;font-weight:600">${fmtMoney(r.commission_total)}</td>
              </tr>`).join('') || '<tr><td colspan="3" style="text-align:center;color:#777;padding:20px">No data</td></tr>'}
            </tbody>
          </table>
        </div>
      </div>
      <div class="section">
        <div class="section-header"><div class="section-title">Revenue Breakdown</div></div>
        <div class="section-body">
          <div style="font-size:12px;font-weight:700;color:#999;text-transform:uppercase;margin-bottom:8px;letter-spacing:.5px">Order Totals</div>
          <div class="earn-info-row" style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #f0f0f0">
            <span style="color:#555">Total Food Value</span>
            <strong>${fmtMoney(d.total_food_value)}</strong>
          </div>
          <div class="earn-info-row" style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #f0f0f0">
            <span style="color:#555">Delivery Fees Collected</span>
            <strong>${fmtMoney(d.delivery_fee_total)}</strong>
          </div>
          <div class="earn-info-row" style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #f0f0f0">
            <span style="color:#555">GST Collected</span>
            <strong>${fmtMoney(d.total_gst_collected)}</strong>
          </div>
          <div class="earn-info-row" style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #f0f0f0">
            <span style="color:#555">Platform Fee Collected</span>
            <strong>${fmtMoney(d.total_platform_fee)}</strong>
          </div>
          <div class="earn-info-row" style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:2px solid #eee;margin-bottom:8px">
            <span style="color:#555">Discounts Given</span>
            <strong style="color:#e53935">-${fmtMoney(d.total_discount_given)}</strong>
          </div>
          <div style="font-size:12px;font-weight:700;color:#999;text-transform:uppercase;margin-bottom:8px;letter-spacing:.5px">Commission Earned</div>
          <div class="earn-info-row" style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #f0f0f0">
            <span style="color:#555">Standard Commission (20%)</span>
            <strong>${fmtMoney(d.standard_commission_total)}</strong>
          </div>
          <div class="earn-info-row" style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #f0f0f0">
            <span style="color:#555">Subscription Commission (8%)</span>
            <strong>${fmtMoney(d.subscription_commission_total)}</strong>
          </div>
          <div style="display:flex;justify-content:space-between;padding:12px 0;font-weight:700;font-size:15px;border-top:2px solid #eee;margin-top:4px">
            <span>Total Commission Earned</span>
            <span style="color:#ff5722">${fmtMoney(d.total_platform_earnings)}</span>
          </div>
        </div>
      </div>
    </div>`;
}

// ═══════════════════════════════════════════════════════════
// SETTLEMENTS
// ═══════════════════════════════════════════════════════════
async function loadSettlements() {
  const today     = new Date().toISOString().slice(0, 10);
  const monthStart= today.slice(0, 8) + '01';
  document.getElementById('pageContent').innerHTML = `
    <div class="section" style="margin-bottom:20px">
      <div class="section-header">
        <div class="section-title">Generate Settlements</div>
      </div>
      <div class="section-body" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
        <div>
          <label class="form-label">From</label>
          <input type="date" class="form-control" id="settleFrom" value="${monthStart}" style="width:160px"/>
        </div>
        <div>
          <label class="form-label">To</label>
          <input type="date" class="form-control" id="settleTo" value="${today}" style="width:160px"/>
        </div>
        <button class="btn btn-primary" onclick="generateSettlements()">Generate Settlements</button>
      </div>
    </div>
    <div class="section">
      <div class="section-header">
        <div class="section-title">Restaurant Settlements</div>
        <button class="btn btn-outline btn-sm" onclick="fetchSettlements()">🔄 Refresh</button>
      </div>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Restaurant</th><th>Period</th><th>Gross</th><th>Commission</th><th>Payout</th><th>Status</th><th>Action</th></tr></thead>
          <tbody id="settleTableBody"><tr><td colspan="7" style="text-align:center;padding:32px;color:#777">Loading…</td></tr></tbody>
        </table>
      </div>
    </div>`;
  fetchSettlements();
}

async function generateSettlements() {
  const from = document.getElementById('settleFrom').value;
  const to   = document.getElementById('settleTo').value;
  if (!from || !to) { showToast('Select a date range first.', 'error'); return; }
  const res = await Api.admin.generateSettlements(from, to);
  if (res?.success) {
    showToast(res.message || 'Settlements generated!', 'success');
    fetchSettlements();
  } else {
    showToast(res?.message || 'Failed to generate.', 'error');
  }
}

async function fetchSettlements() {
  const res  = await Api.admin.settlements();
  const rows = res?.data || [];

  document.getElementById('settleTableBody').innerHTML = rows.length
    ? rows.map(s => `
        <tr>
          <td style="font-weight:600">${escHtml(s.restaurant_name)}</td>
          <td style="font-size:12px;color:#777">${fmtDate(s.period_start)} – ${fmtDate(s.period_end)}</td>
          <td>${fmtMoney(s.gross_amount)}</td>
          <td style="color:#ff5722">${fmtMoney(s.commission_amount)}</td>
          <td style="font-weight:700;color:#4caf50">${fmtMoney(s.net_payout)}</td>
          <td><span class="badge ${s.status === 'paid' ? 'badge-success' : s.status === 'processing' ? 'badge-warning' : 'badge-default'}">${s.status}</span></td>
          <td>
            ${s.status === 'pending' ? `<button class="btn btn-sm btn-success" onclick="processSettlement(${s.id})">Process</button>` : '—'}
          </td>
        </tr>`).join('')
    : '<tr><td colspan="7" style="text-align:center;padding:32px;color:#777">No settlements found</td></tr>';
}

async function processSettlement(id) {
  const res = await Api.admin.processSettlement(id);
  if (res?.success) {
    showToast('Settlement processed!', 'success');
    fetchSettlements();
  } else {
    showToast(res?.message || 'Failed to process.', 'error');
  }
}

// ═══════════════════════════════════════════════════════════
// MENU CATEGORIES
// ═══════════════════════════════════════════════════════════
async function loadMenuCategories() {
  document.getElementById('pageContent').innerHTML = `
    <div class="section" style="margin-bottom:20px">
      <div class="section-header"><div class="section-title">Add Global Category</div></div>
      <div class="section-body">
        <div style="font-size:13px;color:#777;margin-bottom:12px">Categories added here are available to all restaurants when adding menu items.</div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
          <div>
            <label class="form-label">Category Name</label>
            <input type="text" class="form-control" id="catName" placeholder="e.g. Starters, Main Course, Beverages" style="width:260px"/>
          </div>
          <button class="btn btn-primary" onclick="adminAddCategory()">Add Category</button>
        </div>
        <div id="catMsg" style="margin-top:10px;font-size:13px"></div>
      </div>
    </div>
    <div class="section">
      <div class="section-header">
        <div class="section-title">All Global Categories</div>
        <button class="btn btn-outline btn-sm" onclick="loadMenuCategories()">🔄 Refresh</button>
      </div>
      <div id="catListWrap"><div style="text-align:center;padding:32px;color:#777">Loading…</div></div>
    </div>`;

  const cRes = await Api.get('/menu-categories');
  const cats = cRes?.data || [];
  const wrap = document.getElementById('catListWrap');

  if (!cats.length) {
    wrap.innerHTML = '<div style="text-align:center;padding:32px;color:#777">No categories yet. Add some above.</div>';
    return;
  }

  wrap.innerHTML = `<div style="display:flex;gap:10px;flex-wrap:wrap;padding:8px 0">
    ${cats.map(c => `
      <div style="display:flex;align-items:center;gap:6px;background:#f5f5f5;border-radius:20px;padding:6px 14px;font-size:13px">
        <span>${escHtml(c.name)}</span>
        <button onclick="adminDeleteCategory(${c.id},'${escHtml(c.name)}')" style="background:none;border:none;color:#c62828;cursor:pointer;font-size:14px;line-height:1;padding:0 2px" title="Remove">✕</button>
      </div>`).join('')}
  </div>`;
}

async function adminAddCategory() {
  const name = document.getElementById('catName').value.trim();
  const msg  = document.getElementById('catMsg');

  if (!name) { msg.innerHTML = '<span style="color:#c62828">Enter a category name.</span>'; return; }

  const res = await Api.post('/menu-categories', { name });
  if (res?.success) {
    msg.innerHTML = `<span style="color:#2e7d32">✓ Category "${name}" added!</span>`;
    document.getElementById('catName').value = '';
    loadMenuCategories();
  } else {
    msg.innerHTML = `<span style="color:#c62828">${res?.message || 'Failed to add.'}</span>`;
  }
}

async function adminDeleteCategory(id, name) {
  if (!confirm(`Remove category "${name}"? Items assigned to it will become uncategorised.`)) return;
  const res = await Api.delete(`/menu-categories/${id}`);
  if (res?.success) loadMenuCategories();
  else alert(res?.message || 'Failed to remove.');
}

// COUPONS
// ═══════════════════════════════════════════════════════════
async function loadCoupons() {
  document.getElementById('pageContent').innerHTML = `
    <div class="section">
      <div class="section-header">
        <div class="section-title">Coupons</div>
        <button class="btn btn-primary btn-sm" onclick="showCreateCoupon()">+ Create Coupon</button>
      </div>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Code</th><th>Type</th><th>Value</th><th>Min Order</th><th>Max Discount</th><th>Per-User</th><th>Uses</th><th>Expires</th><th>Status</th><th>Actions</th></tr></thead>
          <tbody id="couponTableBody"><tr><td colspan="9" style="text-align:center;padding:32px;color:#777">Loading…</td></tr></tbody>
        </table>
      </div>
    </div>`;
  fetchCoupons();
}

async function fetchCoupons() {
  const res  = await Api.admin.coupons();
  const rows = res?.data || [];

  document.getElementById('couponTableBody').innerHTML = rows.length
    ? rows.map(c => `
        <tr>
          <td><strong style="font-family:monospace;font-size:14px;color:#ff5722">${escHtml(c.code)}</strong></td>
          <td><span class="badge badge-info">${c.discount_type}</span></td>
          <td style="font-weight:600">${c.discount_type === 'percent' ? c.discount_value + '%' : fmtMoney(c.discount_value)}</td>
          <td>${fmtMoney(c.min_order_amount)}</td>
          <td>${c.max_discount_amount ? fmtMoney(c.max_discount_amount) : '—'}</td>
          <td style="text-align:center">${c.per_user_limit || 1}×</td>
          <td>${c.used_count || 0} / ${c.usage_limit || '∞'}</td>
          <td style="font-size:12px;color:#777">${c.valid_until && c.valid_until !== '2099-12-31' ? fmtDate(c.valid_until) : 'No expiry'}</td>
          <td><span class="badge ${c.is_active ? 'badge-success' : 'badge-default'}">${c.is_active ? 'Active' : 'Inactive'}</span></td>
          <td style="white-space:nowrap">
            <button class="btn btn-sm btn-outline" onclick="showEditCoupon(${JSON.stringify(c).replace(/"/g,'&quot;')})">Edit</button>
            <button class="btn btn-sm ${c.is_active ? 'btn-outline' : 'btn-primary'}" style="margin-left:4px" onclick="toggleCoupon(${c.id},this)">${c.is_active ? 'Deactivate' : 'Activate'}</button>
          </td>
        </tr>`).join('')
    : '<tr><td colspan="10" style="text-align:center;padding:32px;color:#777">No coupons yet</td></tr>';
}

function showCreateCoupon() {
  openModal('Create Coupon', `
    <div class="form-group">
      <label class="form-label">Coupon Code</label>
      <input type="text" class="form-control" id="cpCode" placeholder="e.g. SAVE50" style="text-transform:uppercase"/>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Discount Type</label>
        <select class="form-control" id="cpType">
          <option value="percent">Percentage (%)</option>
          <option value="flat">Flat Amount (₹)</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Discount Value</label>
        <input type="number" class="form-control" id="cpValue" placeholder="e.g. 20" min="1"/>
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Min Order Amount (₹)</label>
        <input type="number" class="form-control" id="cpMin" placeholder="e.g. 199" min="0"/>
      </div>
      <div class="form-group">
        <label class="form-label">Max Discount (₹, optional)</label>
        <input type="number" class="form-control" id="cpMax" placeholder="e.g. 100" min="0"/>
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Max Total Uses (all customers)</label>
        <input type="number" class="form-control" id="cpMaxUses" placeholder="Leave blank = unlimited" min="1"/>
      </div>
      <div class="form-group">
        <label class="form-label">Max Uses Per Customer</label>
        <input type="number" class="form-control" id="cpPerUser" placeholder="e.g. 1" min="1" value="1"/>
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Cooldown Between Uses (hours)</label>
        <input type="number" class="form-control" id="cpCooldown" placeholder="e.g. 24 = once/day, 3 = once/3h" min="1"/>
      </div>
      <div class="form-group"></div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Valid From</label>
        <input type="date" class="form-control" id="cpValidFrom"/>
      </div>
      <div class="form-group">
        <label class="form-label">Expires At (leave blank = no expiry)</label>
        <input type="date" class="form-control" id="cpExpiry"/>
      </div>
    </div>
    <div id="couponErr" class="alert alert-error" style="display:none"></div>`,
    `<button class="btn btn-outline" onclick="closeModal()">Cancel</button>
     <button class="btn btn-primary" onclick="submitCreateCoupon()">Create Coupon</button>`
  );
}

async function submitCreateCoupon() {
  const code     = document.getElementById('cpCode').value.trim().toUpperCase();
  const type     = document.getElementById('cpType').value;
  const value    = parseFloat(document.getElementById('cpValue').value);
  const min      = parseFloat(document.getElementById('cpMin').value)      || 0;
  const max      = parseFloat(document.getElementById('cpMax').value)      || null;
  const uses     = parseInt(document.getElementById('cpMaxUses').value)    || null;
  const perUser  = parseInt(document.getElementById('cpPerUser').value)    || 1;
  const validFrom= document.getElementById('cpValidFrom').value           || new Date().toISOString().split('T')[0];
  const expiry   = document.getElementById('cpExpiry').value              || '2099-12-31';
  const err      = document.getElementById('couponErr');
  err.style.display = 'none';

  if (!code || !value) { err.textContent = 'Code and discount value are required.'; err.style.display = 'block'; return; }

  const res = await Api.admin.createCoupon({ code, discount_type: type, discount_value: value, min_order_amount: min, max_discount_amount: max, usage_limit: uses, per_user_limit: perUser, valid_from: validFrom, valid_until: expiry });
  if (res?.success) {
    showToast('Coupon created!', 'success');
    closeModal(); fetchCoupons();
  } else {
    err.textContent = res?.message || 'Failed to create coupon.';
    err.style.display = 'block';
  }
}

function showEditCoupon(c) {
  const noExpiry = !c.valid_until || c.valid_until === '2099-12-31';
  openModal(`Edit Coupon — ${c.code}`, `
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Discount Type</label>
        <select class="form-control" id="ecType">
          <option value="percent" ${c.discount_type==='percent'?'selected':''}>Percentage (%)</option>
          <option value="flat"    ${c.discount_type==='flat'   ?'selected':''}>Flat Amount (₹)</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Discount Value</label>
        <input type="number" class="form-control" id="ecValue" value="${c.discount_value}" min="1"/>
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Min Order Amount (₹)</label>
        <input type="number" class="form-control" id="ecMin" value="${c.min_order_amount || 0}" min="0"/>
      </div>
      <div class="form-group">
        <label class="form-label">Max Discount (₹, optional)</label>
        <input type="number" class="form-control" id="ecMax" value="${c.max_discount_amount || ''}" min="0" placeholder="No limit"/>
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Max Total Uses (all customers)</label>
        <input type="number" class="form-control" id="ecMaxUses" value="${c.usage_limit || ''}" min="1" placeholder="Unlimited"/>
      </div>
      <div class="form-group">
        <label class="form-label">Max Uses Per Customer</label>
        <input type="number" class="form-control" id="ecPerUser" value="${c.per_user_limit || 1}" min="1"/>
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Cooldown Between Uses (hours, blank = no cooldown)</label>
        <input type="number" class="form-control" id="ecCooldown" value="${c.cooldown_hours || ''}" min="1" placeholder="e.g. 24 = once/day"/>
      </div>
      <div class="form-group"></div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Valid From</label>
        <input type="date" class="form-control" id="ecValidFrom" value="${c.valid_from || ''}"/>
      </div>
      <div class="form-group">
        <label class="form-label">Expires At (leave blank = no expiry)</label>
        <input type="date" class="form-control" id="ecExpiry" value="${noExpiry ? '' : c.valid_until}"/>
      </div>
    </div>
    <div id="ecErr" class="alert alert-error" style="display:none"></div>`,
    `<button class="btn btn-outline" onclick="closeModal()">Cancel</button>
     <button class="btn btn-primary" onclick="submitEditCoupon(${c.id})">Save Changes</button>`
  );
}

async function submitEditCoupon(id) {
  const type     = document.getElementById('ecType').value;
  const value    = parseFloat(document.getElementById('ecValue').value);
  const min      = parseFloat(document.getElementById('ecMin').value)      || 0;
  const max      = parseFloat(document.getElementById('ecMax').value)      || null;
  const uses     = parseInt(document.getElementById('ecMaxUses').value)    || null;
  const perUser  = parseInt(document.getElementById('ecPerUser').value)    || 1;
  const validFrom= document.getElementById('ecValidFrom').value           || new Date().toISOString().split('T')[0];
  const expiry   = document.getElementById('ecExpiry').value              || '2099-12-31';
  const err      = document.getElementById('ecErr');
  err.style.display = 'none';

  if (!value) { err.textContent = 'Discount value is required.'; err.style.display = 'block'; return; }

  const res = await Api.admin.updateCoupon(id, { discount_type: type, discount_value: value, min_order_amount: min, max_discount_amount: max, usage_limit: uses, per_user_limit: perUser, valid_from: validFrom, valid_until: expiry });
  if (res?.success) {
    showToast('Coupon updated!', 'success');
    closeModal(); fetchCoupons();
  } else {
    err.textContent = res?.message || 'Failed to update coupon.';
    err.style.display = 'block';
  }
}

async function toggleCoupon(id, btn) {
  btn.disabled = true;
  const res = await Api.admin.toggleCoupon(id);
  if (res?.success) { fetchCoupons(); }
  else { showToast('Failed to update status.', 'error'); btn.disabled = false; }
}

// ═══════════════════════════════════════════════════════════
// ADMIN WALLET
// ═══════════════════════════════════════════════════════════
async function loadAdminWallet() {
  const el = document.getElementById('pageContent');
  el.innerHTML = `<div style="text-align:center;padding:40px;color:#777">Loading wallet data…</div>`;

  const [walletRes, refRes] = await Promise.all([
    Api.admin.wallet(),
    Api.admin.referrals(),
  ]);

  const w = walletRes?.data  || {};
  const r = refRes?.data     || {};

  const stats = w.stats || {};
  const topUsers = w.top_users || [];
  const txs = w.transactions || [];

  const refs = r.referrals || [];
  const rSummary = r.summary || {};

  el.innerHTML = `
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px">
      ${[
        ['Total Wallet Balance', '₹' + parseFloat(stats.total_balance || 0).toFixed(2), '#ff5722'],
        ['Total Credits Given',  '₹' + parseFloat(stats.total_credited || 0).toFixed(2), '#22c55e'],
        ['Total Debits (Orders)','₹' + parseFloat(stats.total_debited || 0).toFixed(2), '#ef4444'],
        ['Total Referrals',      rSummary.total || 0, '#3b82f6'],
      ].map(([label, val, color]) => `
        <div class="stat-card">
          <div class="stat-value" style="color:${color}">${val}</div>
          <div class="stat-label">${label}</div>
        </div>`).join('')}
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:24px">
      <!-- Top Wallet Users -->
      <div class="section">
        <div class="section-header"><div class="section-title">Top Wallet Balances</div></div>
        <div class="section-body" style="padding:0">
          <table class="data-table">
            <thead><tr><th>User</th><th>Email</th><th style="text-align:right">Balance</th></tr></thead>
            <tbody>
              ${topUsers.length ? topUsers.map(u => `
                <tr>
                  <td>${escHtml(u.name)}</td>
                  <td style="color:#777;font-size:12px">${escHtml(u.email)}</td>
                  <td style="text-align:right;font-weight:700;color:#ff5722">₹${parseFloat(u.wallet_balance).toFixed(2)}</td>
                </tr>`).join('') : '<tr><td colspan="3" style="text-align:center;color:#aaa;padding:20px">No data</td></tr>'}
            </tbody>
          </table>
        </div>
      </div>

      <!-- Referral Summary -->
      <div class="section">
        <div class="section-header"><div class="section-title">Referral Summary</div></div>
        <div class="section-body">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            ${[
              ['Total Referrals', rSummary.total || 0],
              ['Completed',       rSummary.completed || 0],
              ['Pending',         rSummary.pending || 0],
              ['Total Rewarded',  '₹' + parseFloat(rSummary.total_rewarded || 0).toFixed(2)],
            ].map(([label, val]) => `
              <div style="background:#f9f9f9;border-radius:8px;padding:12px;text-align:center">
                <div style="font-size:20px;font-weight:800;color:#333">${val}</div>
                <div style="font-size:12px;color:#777;margin-top:2px">${label}</div>
              </div>`).join('')}
          </div>
        </div>
      </div>
    </div>

    <!-- Recent Transactions -->
    <div class="section" style="margin-bottom:24px">
      <div class="section-header"><div class="section-title">Recent Wallet Transactions</div></div>
      <div class="section-body" style="padding:0">
        <table class="data-table">
          <thead><tr><th>User</th><th>Type</th><th>Description</th><th>Amount</th><th>Date</th></tr></thead>
          <tbody>
            ${txs.length ? txs.map(t => `
              <tr>
                <td>${escHtml(t.user_name || '—')}</td>
                <td><span style="background:${t.type==='credit'?'#e8f5e9':'#ffebee'};color:${t.type==='credit'?'#2e7d32':'#c62828'};padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;text-transform:uppercase">${t.type}</span></td>
                <td style="color:#777;font-size:13px">${escHtml(t.description || '—')}</td>
                <td style="font-weight:700;color:${t.type==='credit'?'#22c55e':'#ef4444'}">${t.type==='credit'?'+':'-'}₹${parseFloat(t.amount).toFixed(2)}</td>
                <td style="color:#aaa;font-size:12px">${new Date(t.created_at).toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'})}</td>
              </tr>`).join('') : '<tr><td colspan="5" style="text-align:center;color:#aaa;padding:20px">No transactions</td></tr>'}
          </tbody>
        </table>
      </div>
    </div>

    <!-- Referral List -->
    <div class="section">
      <div class="section-header"><div class="section-title">All Referrals</div></div>
      <div class="section-body" style="padding:0">
        <table class="data-table">
          <thead><tr><th>Referrer</th><th>Friend</th><th>Status</th><th>Reward</th><th>Date</th><th>Action</th></tr></thead>
          <tbody>
            ${refs.length ? refs.map(r => `
              <tr>
                <td>${escHtml(r.referrer_name || '—')}</td>
                <td>${escHtml(r.referred_name || '—')}</td>
                <td><span style="background:${r.status==='completed'?'#e8f5e9':'#fff3e0'};color:${r.status==='completed'?'#2e7d32':'#e65100'};padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;text-transform:uppercase">${r.status}</span></td>
                <td style="font-weight:600;color:#ff5722">${r.reward_given ? '₹'+parseFloat(r.reward_amount).toFixed(2) : '—'}</td>
                <td style="color:#aaa;font-size:12px">${new Date(r.created_at).toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'})}</td>
                <td>${r.status === 'pending' ? `<button onclick="approveReferral(${r.id})" style="background:#22c55e;color:#fff;border:none;padding:4px 12px;border-radius:4px;cursor:pointer;font-size:12px;font-weight:600">Approve</button>` : '—'}</td>
              </tr>`).join('') : '<tr><td colspan="6" style="text-align:center;color:#aaa;padding:20px">No referrals yet</td></tr>'}
          </tbody>
        </table>
      </div>
    </div>
  `;
}

async function approveReferral(id) {
  if (!confirm('Manually approve this referral and credit reward to both users?')) return;
  const res = await Api.admin.approveReferral(id);
  if (res?.success) {
    showToast(res.message || 'Referral approved!');
    showPage('wallet'); // reload the wallet/referral page
  } else {
    showToast(res?.message || 'Failed to approve referral.', 'error');
  }
}

// ═══════════════════════════════════════════════════════════
// SETTINGS
// ═══════════════════════════════════════════════════════════
async function loadSettings() {
  document.getElementById('pageContent').innerHTML = `<div style="text-align:center;padding:40px;color:#777">Loading settings…</div>`;
  const [res, cityRes, meRes] = await Promise.all([Api.admin.settings(), Api.get('/cities'), Api.auth.me()]);
  const s      = res?.data || {};
  const cities = cityRes?.data || [];

  document.getElementById('pageContent').innerHTML = `
    <div class="two-col">
      <div class="section">
        <div class="section-header"><div class="section-title">Commission Settings</div></div>
        <div class="section-body">
          <div class="form-group">
            <label class="form-label">Standard Commission (%)</label>
            <input type="number" class="form-control" id="sPlatformFee" value="${s.platform_commission_percent || 20}" min="1" max="50"/>
          </div>
          <div class="form-group">
            <label class="form-label">Platform Fee (₹)</label>
            <div style="font-size:12px;color:#888;margin-bottom:6px">Base fee charged to customers. ₹1 is added per every ₹100 of order value on top of this.</div>
            <input type="number" class="form-control" id="sSubFee" value="${s.platform_fee_base || 5}" min="0" max="50"/>
          </div>
          <div class="form-group">
            <label class="form-label">GST Rate (%)</label>
            <input type="number" class="form-control" id="sGst" value="${s.gst_percent || 5}" min="0" max="28"/>
          </div>
          <div class="form-group">
            <label class="form-label">GST / Other Charges Label</label>
            <div style="font-size:12px;color:#888;margin-bottom:6px">This label is shown to customers on cart, order tracking &amp; order history pages.</div>
            <input type="text" class="form-control" id="sGstLabel" value="${escHtml(s.gst_label || 'Other charges')}" placeholder="e.g. GST (5%), Handling Fee, Service Charge"/>
          </div>
        </div>
      </div>
      <div class="section">
        <div class="section-header"><div class="section-title">Delivery Settings</div></div>
        <div class="section-body">
          <div style="font-size:12px;font-weight:700;color:#ff5722;text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px">Charged to Customer</div>
          <div class="form-group">
            <label class="form-label">Base Delivery Fee (₹)</label>
            <input type="number" class="form-control" id="sBaseFee" value="${s.base_delivery_fee || 30}" min="0"/>
          </div>
          <div class="form-group">
            <label class="form-label">Per KM Charge (₹)</label>
            <input type="number" class="form-control" id="sPerKm" value="${s.per_km_rate || 5}" min="0" step="0.5"/>
          </div>
          <div class="form-group">
            <label class="form-label">Free Delivery Above (₹)</label>
            <input type="number" class="form-control" id="sFreeAbove" value="${s.free_delivery_above || 299}" min="0"/>
          </div>
          <div style="font-size:12px;font-weight:700;color:#4caf50;text-transform:uppercase;letter-spacing:.5px;margin:14px 0 10px">Paid to Delivery Partner</div>
          <div class="form-group">
            <label class="form-label">Base Pay per Delivery (₹)</label>
            <div style="font-size:12px;color:#888;margin-bottom:6px">Fixed amount given to partner for every delivery.</div>
            <input type="number" class="form-control" id="sRiderBase" value="${s.rider_base_pay || 25}" min="0" step="0.5"/>
          </div>
          <div class="form-group">
            <label class="form-label">Free KM (no extra pay up to)</label>
            <div style="font-size:12px;color:#888;margin-bottom:6px">Partner gets base pay only for first N km.</div>
            <input type="number" class="form-control" id="sRiderFreeKm" value="${s.rider_free_km || 2}" min="0" step="0.5"/>
          </div>
          <div class="form-group">
            <label class="form-label">Extra Pay per KM beyond free km (₹)</label>
            <input type="number" class="form-control" id="sRiderPerKm" value="${s.rider_per_km_pay || 3}" min="0" step="0.5"/>
          </div>
          <div style="background:#f9f9f9;border-radius:8px;padding:12px;font-size:13px;color:#555;margin-top:4px">
            💡 Example: 5 km delivery → Partner earns ₹<span id="riderEgVal">${(parseFloat(s.rider_base_pay||25) + Math.max(0,(5-parseFloat(s.rider_free_km||2))*parseFloat(s.rider_per_km_pay||3))).toFixed(0)}</span>
            &nbsp;|&nbsp; Platform keeps ₹<span id="platformEgVal">${(parseFloat(s.base_delivery_fee||30) - parseFloat(s.rider_base_pay||25) - Math.max(0,(5-parseFloat(s.rider_free_km||2))*parseFloat(s.rider_per_km_pay||3))).toFixed(0)}</span>
          </div>
        </div>
      </div>
      <div class="section">
        <div class="section-header"><div class="section-title">Order Settings</div></div>
        <div class="section-body">
          <div class="form-group">
            <label class="form-label">Auto Cancel (minutes)</label>
            <input type="number" class="form-control" id="sAutoCancel" value="${s.auto_cancel_minutes || 15}" min="5"/>
          </div>
          <div class="form-group">
            <label class="form-label">Min Order Amount (₹)</label>
            <input type="number" class="form-control" id="sMinOrder" value="${s.min_order_amount || 50}" min="0"/>
          </div>
          <div class="form-group">
            <label class="form-label">Platform Name</label>
            <input type="text" class="form-control" id="sPlatformName" value="${escHtml(s.platform_name || 'Aharam')}"/>
          </div>
        </div>
      </div>
      <div class="section">
        <div class="section-header"><div class="section-title">Subscription Plans</div></div>
        <div class="section-body">
          <div style="font-size:12px;color:#888;margin-bottom:12px">Prices and commission rates shown to restaurant owners on the subscription page.</div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
            <div class="form-group">
              <label class="form-label">Basic Plan Price (₹/month)</label>
              <input type="number" class="form-control" id="sPlanBasic" value="${s.plan_basic_price || 599}" min="0"/>
            </div>
            <div class="form-group">
              <label class="form-label">Basic Plan Commission (%)</label>
              <input type="number" class="form-control" id="sPlanBasicComm" value="${s.plan_basic_commission || 12}" min="1" max="50"/>
            </div>
            <div class="form-group">
              <label class="form-label">Pro Plan Price (₹/month)</label>
              <input type="number" class="form-control" id="sPlanPro" value="${s.plan_pro_price || 999}" min="0"/>
            </div>
            <div class="form-group">
              <label class="form-label">Pro Plan Commission (%)</label>
              <input type="number" class="form-control" id="sPlanProComm" value="${s.plan_pro_commission || 8}" min="1" max="50"/>
            </div>
            <div class="form-group">
              <label class="form-label">Premium Plan Price (₹/month)</label>
              <input type="number" class="form-control" id="sPlanPremium" value="${s.plan_premium_price || 1499}" min="0"/>
            </div>
            <div class="form-group">
              <label class="form-label">Premium Plan Commission (%)</label>
              <input type="number" class="form-control" id="sPlanPremiumComm" value="${s.plan_premium_commission || 5}" min="1" max="50"/>
            </div>
          </div>
          <div style="margin-top:16px;padding-top:16px;border-top:1px solid #eee">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
              <div style="font-size:13px;font-weight:700;color:#ff5722">💎 Customer Plus Plan</div>
              <div style="display:flex;align-items:center;gap:8px">
                <span style="font-size:12px;color:#777">Plan Status:</span>
                <select class="form-control" id="sPlusActive" style="width:auto;padding:4px 10px;font-size:13px">
                  <option value="1" ${(s.customer_plan_active ?? '1') === '1' ? 'selected' : ''}>Active</option>
                  <option value="0" ${(s.customer_plan_active ?? '1') === '0' ? 'selected' : ''}>Disabled</option>
                </select>
              </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
              <div class="form-group">
                <label class="form-label">Plan Name</label>
                <input type="text" class="form-control" id="sPlusName" value="${escHtml(s.customer_plan_name || 'Aharam Plus')}"/>
              </div>
              <div class="form-group">
                <label class="form-label">Price (₹/month)</label>
                <input type="number" class="form-control" id="sPlanCustomer" value="${s.customer_plan_price || 99}" min="0"/>
              </div>
              <div class="form-group">
                <label class="form-label">Extra Discount on Orders (%)</label>
                <input type="number" class="form-control" id="sPlusDiscount" value="${s.customer_plan_discount || 10}" min="0" max="50"/>
              </div>
              <div class="form-group">
                <label class="form-label">Free Delivery</label>
                <select class="form-control" id="sPlusFreeDelivery">
                  <option value="1" ${(s.customer_plan_free_delivery ?? '1') === '1' ? 'selected' : ''}>Yes — always free</option>
                  <option value="0" ${(s.customer_plan_free_delivery ?? '1') === '0' ? 'selected' : ''}>No</option>
                </select>
              </div>
              <div class="form-group">
                <label class="form-label">Free Delivery Within (km)</label>
                <input type="number" class="form-control" id="sPlusFreeKm" value="${s.customer_plan_free_delivery_km || ''}" min="0" step="0.5" placeholder="e.g. 3 (blank = no limit)"/>
              </div>
              <div class="form-group">
                <label class="form-label">Minimum Order Amount (₹)</label>
                <input type="number" class="form-control" id="sPlusMinOrder" value="${s.customer_plan_min_order || ''}" min="0" placeholder="e.g. 99 (blank = no minimum)"/>
              </div>
            </div>
            <div class="form-group" style="margin-top:4px">
              <label class="form-label">Benefits Description (shown to customers)</label>
              <textarea class="form-control" id="sPlusBenefits" rows="3" placeholder="e.g. Free delivery on all orders + 10% off + Priority support">${escHtml(s.customer_plan_benefits || 'Free delivery on all orders + 10% discount + Priority support')}</textarea>
            </div>
          </div>
        </div>
      </div>
      <div class="section">
        <div class="section-header"><div class="section-title">Support Contact</div></div>
        <div class="section-body">
          <div class="form-group">
            <label class="form-label">Support Email</label>
            <input type="email" class="form-control" id="sSupportEmail" value="${escHtml(s.support_email || 'customersupport@aharam.in')}"/>
          </div>
          <div class="form-group">
            <label class="form-label">Support Phone</label>
            <input type="tel" class="form-control" id="sSupportPhone" value="${escHtml(s.support_phone || '1478523698')}"/>
          </div>
          <div class="form-group">
            <label class="form-label">WhatsApp Number (with country code, e.g. 911478523698)</label>
            <input type="text" class="form-control" id="sSupportWhatsapp" value="${escHtml(s.support_whatsapp || '911478523698')}"/>
          </div>
        </div>
      </div>
    </div>

    <!-- Wallet & Referral -->
    <div class="section" style="margin-top:20px">
      <div class="section-header"><div class="section-title">💳 Wallet & Referral</div></div>
      <div class="section-body">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
          <div class="form-group">
            <label class="form-label">Referral Reward Amount (₹)</label>
            <input type="number" class="form-control" id="sRefReward" value="${s.referral_reward_amount || 25}" min="0" placeholder="e.g. 25"/>
            <small style="color:#aaa;font-size:11px">Both referrer & friend earn this</small>
          </div>
          <div class="form-group">
            <label class="form-label">Min Order to Unlock Reward (₹)</label>
            <input type="number" class="form-control" id="sRefMinOrder" value="${s.referral_min_order || 100}" min="0" placeholder="e.g. 100"/>
          </div>
          <div class="form-group">
            <label class="form-label">Max Referrals per Month (per user)</label>
            <input type="number" class="form-control" id="sRefMonthlyLimit" value="${s.referral_monthly_limit || 10}" min="1" placeholder="e.g. 10"/>
          </div>
          <div class="form-group">
            <label class="form-label">Wallet Expiry Days (0 = never)</label>
            <input type="number" class="form-control" id="sWalletExpiry" value="${s.wallet_expiry_days || 0}" min="0" placeholder="0"/>
          </div>
        </div>
        <div class="form-group" style="margin-top:4px">
          <label class="form-label">Referral Programme</label>
          <select class="form-control" id="sRefEnabled" style="max-width:200px">
            <option value="1" ${(s.referral_enabled ?? '1') === '1' ? 'selected' : ''}>Enabled</option>
            <option value="0" ${(s.referral_enabled ?? '1') === '0' ? 'selected' : ''}>Disabled</option>
          </select>
        </div>
      </div>
    </div>

    <!-- Cities Manager -->
    <div class="section" style="margin-top:20px">
      <div class="section-header">
        <div class="section-title">📍 Delivery Cities</div>
        <span style="font-size:12px;color:#777">Cities shown in the customer app city selector</span>
      </div>
      <div class="section-body">
        <div id="cityTagsWrap" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px">
          ${cities.map(c => `
            <div class="city-tag">
              <span>${escHtml(c)}</span>
              <button onclick="removeCity('${escHtml(c)}')" style="background:none;border:none;cursor:pointer;color:#888;font-size:14px;line-height:1;padding:0 0 0 4px">✕</button>
            </div>`).join('')}
        </div>
        <div style="display:flex;gap:8px;max-width:360px">
          <input type="text" id="newCityInput" class="form-control" placeholder="Add city name…" style="flex:1"/>
          <button class="btn btn-primary btn-sm" onclick="addCity()">+ Add</button>
        </div>
        <div id="cityMsg" style="font-size:13px;margin-top:8px"></div>
      </div>
    </div>

    <div id="settingsErr" class="alert alert-error" style="display:none"></div>
    <div style="margin-top:16px">
      <button class="btn btn-primary" onclick="saveSettings()" style="padding:12px 28px;font-size:14px">💾 Save All Settings</button>
    </div>

    <div class="section" style="margin-top:24px">
      <div class="section-header"><div class="section-title">Account</div></div>
      <div class="section-body">
        <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid #f5f5f5;font-size:14px;margin-bottom:20px">
          <span style="color:#777">Email Address</span>
          <strong id="adminAccEmail" style="color:#333">—</strong>
        </div>
        <div style="font-size:15px;font-weight:700;margin-bottom:14px">Change Password</div>
        <div id="adminPwdMsg" style="display:none;padding:10px 14px;border-radius:8px;font-size:13px;margin-bottom:14px"></div>
        <div class="form-group">
          <label class="form-label">Current Password</label>
          <div style="position:relative">
            <input type="password" class="form-control" id="adminCurrentPwd" placeholder="Enter current password" autocomplete="new-password" style="padding-right:44px"/>
            <button type="button" onclick="toggleAdminPwd('adminCurrentPwd',this)" style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;font-size:18px;color:#aaa;line-height:1;padding:0">👁</button>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">New Password</label>
          <div style="position:relative">
            <input type="password" class="form-control" id="adminNewPwd" placeholder="Min 6 characters" style="padding-right:44px"/>
            <button type="button" onclick="toggleAdminPwd('adminNewPwd',this)" style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;font-size:18px;color:#aaa;line-height:1;padding:0">👁</button>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Confirm New Password</label>
          <div style="position:relative">
            <input type="password" class="form-control" id="adminConfirmPwd" placeholder="Repeat new password" style="padding-right:44px"/>
            <button type="button" onclick="toggleAdminPwd('adminConfirmPwd',this)" style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;font-size:18px;color:#aaa;line-height:1;padding:0">👁</button>
          </div>
        </div>
        <button class="btn btn-primary" onclick="saveAdminPassword()">Update Password</button>
      </div>
    </div>`;

  // Populate admin email after DOM is rendered
  const adminEmailEl = document.getElementById('adminAccEmail');
  if (adminEmailEl && meRes?.success) adminEmailEl.textContent = meRes.data.email || '—';
}

function getCityList() {
  return [...document.querySelectorAll('#cityTagsWrap .city-tag span')].map(s => s.textContent.trim());
}

function renderCityTags(cities) {
  document.getElementById('cityTagsWrap').innerHTML = cities.map(c => `
    <div class="city-tag">
      <span>${escHtml(c)}</span>
      <button onclick="removeCity('${escHtml(c)}')" style="background:none;border:none;cursor:pointer;color:#888;font-size:14px;line-height:1;padding:0 0 0 4px">✕</button>
    </div>`).join('');
}

function addCity() {
  const input = document.getElementById('newCityInput');
  const name  = input.value.trim();
  if (!name) return;
  const list = getCityList();
  if (list.map(c => c.toLowerCase()).includes(name.toLowerCase())) {
    document.getElementById('cityMsg').innerHTML = '<span style="color:#c62828">City already exists.</span>';
    return;
  }
  list.push(name);
  renderCityTags(list);
  saveCities(list);
  input.value = '';
}

function removeCity(name) {
  const list = getCityList().filter(c => c !== name);
  renderCityTags(list);
  saveCities(list);
}

async function saveCities(cities) {
  const msg = document.getElementById('cityMsg');
  const res = await Api.put('/admin/cities', { cities });
  if (res?.success) {
    msg.innerHTML = '<span style="color:#2e7d32">✓ Cities saved!</span>';
    setTimeout(() => msg.innerHTML = '', 2500);
  } else {
    msg.innerHTML = `<span style="color:#c62828">${res?.message || 'Failed to save.'}</span>`;
  }
}

async function saveSettings() {
  const data = {
    platform_commission_percent:     parseFloat(document.getElementById('sPlatformFee').value),
    platform_fee_base:               parseFloat(document.getElementById('sSubFee').value),
    gst_percent:                     parseFloat(document.getElementById('sGst').value),
    gst_label:                       document.getElementById('sGstLabel').value.trim() || 'Other charges',
    base_delivery_fee:               parseFloat(document.getElementById('sBaseFee').value),
    per_km_rate:                     parseFloat(document.getElementById('sPerKm').value),
    free_delivery_above:             parseFloat(document.getElementById('sFreeAbove').value),
    rider_base_pay:                  parseFloat(document.getElementById('sRiderBase').value),
    rider_free_km:                   parseFloat(document.getElementById('sRiderFreeKm').value),
    rider_per_km_pay:                parseFloat(document.getElementById('sRiderPerKm').value),
    auto_cancel_minutes:             parseInt(document.getElementById('sAutoCancel').value),
    min_order_amount:                parseFloat(document.getElementById('sMinOrder').value),
    platform_name:                   document.getElementById('sPlatformName').value.trim(),
    plan_basic_price:                parseFloat(document.getElementById('sPlanBasic').value),
    plan_basic_commission:           parseFloat(document.getElementById('sPlanBasicComm').value),
    plan_pro_price:                  parseFloat(document.getElementById('sPlanPro').value),
    plan_pro_commission:             parseFloat(document.getElementById('sPlanProComm').value),
    plan_premium_price:              parseFloat(document.getElementById('sPlanPremium').value),
    plan_premium_commission:         parseFloat(document.getElementById('sPlanPremiumComm').value),
    customer_plan_active:            document.getElementById('sPlusActive').value,
    customer_plan_name:              document.getElementById('sPlusName').value.trim(),
    customer_plan_price:             parseFloat(document.getElementById('sPlanCustomer').value),
    customer_plan_discount:          parseFloat(document.getElementById('sPlusDiscount').value),
    customer_plan_free_delivery:     document.getElementById('sPlusFreeDelivery').value,
    customer_plan_free_delivery_km:  document.getElementById('sPlusFreeKm').value || '',
    customer_plan_min_order:         document.getElementById('sPlusMinOrder').value || '',
    customer_plan_benefits:          document.getElementById('sPlusBenefits').value.trim(),
    support_email:                   document.getElementById('sSupportEmail').value.trim(),
    support_phone:                   document.getElementById('sSupportPhone').value.trim(),
    support_whatsapp:                document.getElementById('sSupportWhatsapp').value.trim(),
    referral_enabled:                document.getElementById('sRefEnabled').value,
    referral_reward_amount:          parseFloat(document.getElementById('sRefReward').value) || 25,
    referral_min_order:              parseFloat(document.getElementById('sRefMinOrder').value) || 100,
    referral_monthly_limit:          parseInt(document.getElementById('sRefMonthlyLimit').value) || 10,
    wallet_expiry_days:              parseInt(document.getElementById('sWalletExpiry').value) || 0,
  };

  const err = document.getElementById('settingsErr');
  err.style.display = 'none';

  const res = await Api.admin.updateSettings(data);
  if (res?.success) {
    showToast('Settings saved!', 'success');
  } else {
    err.textContent = res?.message || 'Failed to save settings.';
    err.style.display = 'block';
  }
}

// ── Admin Account ──────────────────────────────────────────
function toggleAdminPwd(inputId, btn) {
  const input = document.getElementById(inputId);
  const show  = input.type === 'password';
  input.type  = show ? 'text' : 'password';
  btn.textContent = show ? '🙈' : '👁';
  btn.style.color = show ? '#ff5722' : '#aaa';
}

async function saveAdminPassword() {
  const current = document.getElementById('adminCurrentPwd')?.value;
  const newPwd  = document.getElementById('adminNewPwd')?.value;
  const confirm = document.getElementById('adminConfirmPwd')?.value;
  const msgEl   = document.getElementById('adminPwdMsg');

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

  const btn = document.querySelector('[onclick="saveAdminPassword()"]');
  if (btn) { btn.disabled = true; btn.textContent = 'Updating…'; }

  const res = await Api.request('PUT', '/me', { current_password: current, new_password: newPwd });

  if (btn) { btn.disabled = false; btn.textContent = 'Update Password'; }

  if (res?.success) {
    showMsg('Password changed successfully!', true);
    document.getElementById('adminCurrentPwd').value = '';
    document.getElementById('adminNewPwd').value      = '';
    document.getElementById('adminConfirmPwd').value  = '';
  } else {
    showMsg(res?.message || 'Failed to update password.', false);
  }
}

// ═══════════════════════════════════════════════════════════
// HELPERS
// ═══════════════════════════════════════════════════════════
function fmtCooldown(hours) {
  if (!hours) return '—';
  if (hours % 24 === 0) return `${hours / 24}d cooldown`;
  return `${hours}h cooldown`;
}

function statusBadge(s) {
  const map = {
    pending: 'badge-warning', confirmed: 'badge-info', preparing: 'badge-info',
    ready: 'badge-success', picked: 'badge-info', on_the_way: 'badge-purple',
    picked_up: 'badge-info', delivered: 'badge-success', cancelled: 'badge-danger',
  };
  return map[s] || 'badge-default';
}
function approvalBadge(s) {
  return s === 'approved' ? 'badge-success' : s === 'rejected' ? 'badge-danger' : 'badge-warning';
}
function verifyBadge(s) {
  return s === 'approved' ? 'badge-success' : s === 'rejected' ? 'badge-danger' : 'badge-warning';
}
function roleBadge(r) {
  const map = { admin: 'badge-danger', restaurant_owner: 'badge-primary', delivery_partner: 'badge-info', customer: 'badge-default' };
  return map[r] || 'badge-default';
}

function renderPagination(containerId, meta, onPage) {
  const el = document.getElementById(containerId);
  if (!el || !meta.last_page || meta.last_page <= 1) { if (el) el.innerHTML = ''; return; }

  const cur   = meta.current_page || 1;
  const total = meta.last_page;
  let html    = '';

  if (cur > 1) html += `<button class="page-btn" onclick="(${onPage.toString()})(${cur - 1})">‹ Prev</button>`;

  // Window of pages
  let start = Math.max(1, cur - 2);
  let end   = Math.min(total, cur + 2);
  if (start > 1) html += `<button class="page-btn" onclick="(${onPage.toString()})(1)">1</button>${start > 2 ? '<span style="padding:6px 4px;color:#777">…</span>' : ''}`;
  for (let p = start; p <= end; p++) {
    html += `<button class="page-btn ${p === cur ? 'active' : ''}" onclick="(${onPage.toString()})(${p})">${p}</button>`;
  }
  if (end < total) html += `${end < total - 1 ? '<span style="padding:6px 4px;color:#777">…</span>' : ''}<button class="page-btn" onclick="(${onPage.toString()})(${total})">${total}</button>`;

  if (cur < total) html += `<button class="page-btn" onclick="(${onPage.toString()})(${cur + 1})">Next ›</button>`;

  html += `<span class="page-info">Page ${cur} of ${total} (${meta.total || 0} records)</span>`;
  el.innerHTML = html;
}
