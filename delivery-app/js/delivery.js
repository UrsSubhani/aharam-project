/**
 * delivery.js — Delivery Partner App Logic
 *
 * - Shows active delivery with step-by-step status flow
 * - Updates GPS location every 10 seconds while on delivery
 * - Polls for new delivery requests when online
 */

let activeDelivery  = null;
let locationTimer   = null;
let pollTimer       = null;
let partnerData     = null;
// Persist dismissed order IDs in sessionStorage, scoped per rider user ID
const _dismissKey = 'dismissed_orders_' + (JSON.parse(localStorage.getItem('aharam_rider_user') || '{}')?.id || 'guest');
const _dismissed = JSON.parse(sessionStorage.getItem(_dismissKey) || '[]');
let dismissedOrders = new Set(_dismissed);

// Status flow: assigned → accepted → (OTP) → picked → on_the_way → (OTP) → delivered
const NEXT_STATUS = {
  assigned:   { next: 'accepted',  label: '✅ Accept Delivery',   btnClass: 'action-btn-primary' },
};

// ── Init ─────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', async () => {
  if (!Auth.isLoggedIn()) { window.location.href = 'login.html'; return; }

  const meRes = await Api.auth.me();
  if (!meRes?.success || meRes.data.role !== 'delivery_partner') {
    Auth.logout(); return;
  }

  partnerData = meRes.data?.delivery_partner;

  // No profile yet → redirect to setup
  if (!partnerData) {
    window.location.href = 'pages/register.html';
    return;
  }

  // Profile exists but not active
  if (!partnerData.is_verified) {
    const isSuspended = partnerData.verification_status === 'suspended';
    document.body.innerHTML = `
      <div style="display:flex;flex-direction:column;align-items:center;justify-content:center;
                  min-height:100vh;padding:32px;text-align:center;background:#f5f5f5">
        <div style="font-size:60px;margin-bottom:16px">${isSuspended ? '🚫' : '⏳'}</div>
        <h2 style="font-weight:800;font-size:20px;margin-bottom:8px;color:${isSuspended ? '#c62828' : '#333'}">
          ${isSuspended ? 'Account Suspended' : 'Verification Pending'}
        </h2>
        <p style="color:#777;font-size:14px;max-width:300px;margin-bottom:24px">
          ${isSuspended
            ? 'Your account has been suspended by the admin. Please contact support to resolve this.'
            : 'Your profile has been submitted. Our admin team will verify your documents shortly. You\'ll be able to start accepting orders once verified.'}
        </p>
        <div style="background:${isSuspended ? '#ffebee' : '#fff3e0'};border-radius:12px;padding:16px 20px;
                    font-size:13px;color:${isSuspended ? '#c62828' : '#e65100'};max-width:300px">
          📞 Questions? Call support: <strong>1800-123-4567</strong>
        </div>
        <button onclick="Auth.logout()" style="margin-top:24px;padding:12px 28px;
                background:${isSuspended ? '#c62828' : '#ff5722'};
                color:#fff;border:none;border-radius:8px;font-size:14px;font-weight:700;cursor:pointer">
          Sign Out
        </button>
      </div>`;
    return;
  }

  // Set availability toggle
  const isAvailable = partnerData?.is_available ?? false;
  updateAvailabilityUI(isAvailable);

  // Availability toggle
  document.getElementById('availabilityToggle').addEventListener('change', async (e) => {
    const val = e.target.checked;
    const res = await Api.delivery.toggleAvailability(val);
    if (res?.success) {
      updateAvailabilityUI(val);
      showToast(val ? 'You are Online! 🟢' : 'You are Offline 🔴', val ? 'success' : 'default');
      if (val) startLocationTracking();
      else     stopLocationTracking();
    }
  });

  // Tab switching
  document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
      document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
      btn.classList.add('active');
      document.getElementById(`tab-${btn.dataset.tab}`).classList.add('active');
      if (btn.dataset.tab === 'history')  loadHistory();
      if (btn.dataset.tab === 'earnings') loadEarnings();
      if (btn.dataset.tab === 'profile')  loadDeliveryProfile();
    });
  });

  document.getElementById('refreshNewBtn')?.addEventListener('click', () => {
    loadMyOrders();
    loadAvailableOrders();
  });

  // Initial load
  loadMyOrders();
  loadAvailableOrders();
  if (isAvailable) startLocationTracking();
  if (partnerData) updateEarningsDisplay();

  // Poll every 15 seconds
  pollTimer = setInterval(() => { loadMyOrders(); loadAvailableOrders(); }, 15000);
});

function updateAvailabilityUI(available) {
  document.getElementById('availabilityToggle').checked   = available;
  const lbl = document.getElementById('availabilityLabel');
  lbl.textContent   = available ? 'Online' : 'Offline';
  lbl.style.color   = available ? '#4caf50' : '#f44336';
  document.getElementById('locationBar').style.display = available ? 'block' : 'none';
}

// ── Load My Orders ────────────────────────────────────────
async function loadMyOrders() {
  const res = await Api.delivery.myOrders();
  const orders = res?.data || [];

  // Separate active from pending assignment
  const active  = orders.find(o => ['accepted','picked','on_the_way'].includes(o.delivery_status));
  const assigned = orders.find(o => o.delivery_status === 'assigned');

  // Show active delivery
  if (active) {
    activeDelivery = active;
    renderActiveOrder(active);
  } else if (assigned) {
    activeDelivery = assigned;
    renderActiveOrder(assigned);
  } else {
    activeDelivery = null;
    document.getElementById('noActiveOrder').style.display  = 'block';
    document.getElementById('activeOrderCard').style.display = 'none';
  }

  // New requests (assigned, not yet accepted)
  const newReqs = orders.filter(o => o.delivery_status === 'assigned');
  renderNewRequests(newReqs);
}

// ── Render Active Order ───────────────────────────────────
function renderActiveOrder(o) {
  document.getElementById('noActiveOrder').style.display   = 'none';
  document.getElementById('activeOrderCard').style.display = 'block';

  const next   = NEXT_STATUS[o.delivery_status];
  const status = o.delivery_status;

  const steps = [
    { key: 'assigned',   label: 'Assigned',  icon: '📱' },
    { key: 'accepted',   label: 'Accepted',  icon: '✅' },
    { key: 'picked',     label: 'Picked',    icon: '📦' },
    { key: 'on_the_way', label: 'On Way',    icon: '🛵' },
    { key: 'delivered',  label: 'Delivered', icon: '🏠' },
  ];

  const statusOrder = ['assigned','accepted','picked','on_the_way','delivered'];
  const currentIdx  = statusOrder.indexOf(status);

  const stepsHtml = steps.map((s, i) => `
    <div style="display:flex;flex-direction:column;align-items:center;gap:4px">
      <div style="width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:14px;
           background:${i <= currentIdx ? '#4caf50' : '#eee'};color:${i <= currentIdx ? '#fff' : '#aaa'}">
        ${i <= currentIdx ? '✓' : s.icon}
      </div>
      <div style="font-size:10px;font-weight:600;color:${i <= currentIdx ? '#4caf50' : '#aaa'}">${s.label}</div>
    </div>`).join('<div style="flex:1;height:2px;background:#eee;margin-top:15px"></div>');

  document.getElementById('activeOrderCard').innerHTML = `
    <div class="order-card">
      <div class="order-card-header">
        <div style="font-size:13px;opacity:.8;margin-bottom:4px">Order ${escHtml(o.order_number)}</div>
        <div style="font-size:18px;font-weight:800">₹${o.partner_earnings} earnings</div>
        <div style="font-size:13px;opacity:.8">${o.distance_km} km • ${o.delivery_status.replace('_',' ').toUpperCase()}</div>
      </div>
      <div class="order-card-body">

        <!-- Progress -->
        <div style="display:flex;align-items:center;margin-bottom:20px;gap:0">
          ${stepsHtml}
        </div>

        <!-- Pickup Details -->
        <div style="background:#fff3e0;border-radius:8px;padding:12px;margin-bottom:12px">
          <div style="font-size:12px;font-weight:700;color:#e65100;margin-bottom:6px">📍 PICKUP FROM</div>
          <div style="font-weight:600">${escHtml(o.restaurant_name)}</div>
          <div style="font-size:13px;color:#555">${escHtml(o.restaurant_address)}</div>
          <div style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap">
            <a href="tel:${o.restaurant_phone}" class="action-btn action-btn-primary" style="display:inline-block;padding:8px 14px;font-size:13px;border-radius:6px;text-decoration:none">📞 Call</a>
            ${o.latitude && o.longitude ? `<a href="https://www.google.com/maps/dir/?api=1&destination=${o.latitude},${o.longitude}" target="_blank" class="action-btn action-btn-warning" style="display:inline-block;padding:8px 14px;font-size:13px;border-radius:6px;text-decoration:none">🗺 Navigate</a>` : ''}
          </div>
          ${status === 'accepted' ? `
            <div style="margin-top:10px;background:#fff;border:1px solid #ff5722;border-radius:8px;padding:10px">
              <div style="font-size:12px;font-weight:700;color:#e65100;margin-bottom:6px">🔐 Verify Pickup OTP</div>
              <div style="font-size:12px;color:#777;margin-bottom:8px">Ask the restaurant for their OTP and enter it below to confirm pickup.</div>
              <div style="display:flex;gap:8px">
                <input type="number" id="pickupOtpInput" placeholder="Enter OTP" maxlength="6"
                  style="flex:1;padding:10px;border:1px solid #ddd;border-radius:6px;font-size:18px;letter-spacing:4px;text-align:center"/>
                <button class="action-btn action-btn-primary" style="padding:10px 16px;font-size:13px"
                  onclick="verifyAndPick(${o.delivery_id})">✅ Verify</button>
              </div>
              <div id="pickupOtpErr" style="color:#c62828;font-size:12px;margin-top:6px;display:none"></div>
            </div>` : ''}
        </div>

        <!-- Delivery Details -->
        <div style="background:#e8f5e9;border-radius:8px;padding:12px;margin-bottom:16px">
          <div style="font-size:12px;font-weight:700;color:#2e7d32;margin-bottom:6px">🏠 DELIVER TO</div>
          <div style="font-weight:600">${escHtml(o.customer_name)}</div>
          <div style="font-size:13px;color:#555">${escHtml(o.delivery_address_text)}</div>

          <!-- Cash to collect indicator -->
          ${(() => {
            const cashAmt = parseFloat(o.cash_to_collect ?? o.total_amount ?? 0);
            if (cashAmt <= 0) return `
              <div style="margin-top:10px;background:#e8f5e9;border:1px solid #4caf50;border-radius:8px;padding:10px;display:flex;align-items:center;gap:8px">
                <span style="font-size:20px">💳</span>
                <div>
                  <div style="font-size:13px;font-weight:700;color:#2e7d32">Paid via Wallet — No cash needed</div>
                  <div style="font-size:11px;color:#777">Customer has already paid online</div>
                </div>
              </div>`;
            return `
              <div style="margin-top:10px;background:#fff3e0;border:2px solid #ff5722;border-radius:8px;padding:10px;display:flex;align-items:center;justify-content:space-between">
                <div style="display:flex;align-items:center;gap:8px">
                  <span style="font-size:22px">💵</span>
                  <div>
                    <div style="font-size:11px;color:#777;font-weight:600;text-transform:uppercase">Collect from customer</div>
                    <div style="font-size:22px;font-weight:800;color:#ff5722">₹${cashAmt.toFixed(0)}</div>
                  </div>
                </div>
                ${parseFloat(o.wallet_amount ?? 0) > 0 ? `<div style="font-size:11px;color:#aaa;text-align:right">₹${parseFloat(o.wallet_amount).toFixed(0)} paid<br>via wallet</div>` : ''}
              </div>`;
          })()}

          <div style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap">
            <a href="tel:${o.customer_phone}" class="action-btn action-btn-success" style="display:inline-block;padding:8px 14px;font-size:13px;border-radius:6px;text-decoration:none">📞 Call</a>
            ${o.delivery_lat && o.delivery_lng ? `<a href="https://www.google.com/maps/dir/?api=1&destination=${o.delivery_lat},${o.delivery_lng}" target="_blank" class="action-btn action-btn-primary" style="display:inline-block;padding:8px 14px;font-size:13px;border-radius:6px;text-decoration:none">🗺 Navigate</a>` : ''}
          </div>
          ${status === 'on_the_way' ? `
            <div style="margin-top:10px;background:#fff;border:1px solid #4caf50;border-radius:8px;padding:10px">
              <div style="font-size:12px;font-weight:700;color:#2e7d32;margin-bottom:6px">🔐 Verify Delivery OTP</div>
              <div style="font-size:12px;color:#777;margin-bottom:8px">Ask the customer to show their OTP and enter it below to confirm delivery.</div>
              <div style="display:flex;gap:8px">
                <input type="number" id="deliveryOtpInput" placeholder="Enter OTP" maxlength="6"
                  style="flex:1;padding:10px;border:1px solid #ddd;border-radius:6px;font-size:18px;letter-spacing:4px;text-align:center"/>
                <button class="action-btn action-btn-success" style="padding:10px 16px;font-size:13px"
                  onclick="verifyAndDeliver(${o.delivery_id})">✅ Verify</button>
              </div>
              <div id="deliveryOtpErr" style="color:#c62828;font-size:12px;margin-top:6px;display:none"></div>
            </div>` : ''}
        </div>

        <!-- Special instructions -->
        ${o.special_instructions ? `<div style="background:#f5f5f5;border-radius:8px;padding:10px;margin-bottom:12px;font-size:13px;color:#555">📝 ${escHtml(o.special_instructions)}</div>` : ''}

        <!-- Action Button (only for assigned → accepted step) -->
        ${next && status === 'assigned'
          ? `<button class="action-btn ${next.btnClass}" onclick="updateDeliveryStatus(${o.delivery_id}, '${next.next}')">${next.label}</button>`
          : ''}
        ${status === 'delivered' ? '<div style="text-align:center;font-size:16px;padding:12px;color:#4caf50;font-weight:700">🎉 Delivery Complete!</div>' : ''}
      </div>
    </div>`;
}

// ── Load Available Orders (unassigned ready orders) ───────
async function loadAvailableOrders() {
  if (!navigator.geolocation) {
    const res = await Api.delivery.availableOrders();
    renderNewRequests(res?.data || []);
    return;
  }
  navigator.geolocation.getCurrentPosition(
    async pos => {
      const res = await Api.delivery.availableOrders(pos.coords.latitude, pos.coords.longitude);
      renderNewRequests(res?.data || []);
    },
    async () => {
      const res = await Api.delivery.availableOrders();
      renderNewRequests(res?.data || []);
    },
    { timeout: 4000 }
  );
}

// ── Render New Requests ───────────────────────────────────
function renderNewRequests(requests) {
  const el      = document.getElementById('newOrdersList');
  const visible = requests.filter(o => !dismissedOrders.has(o.order_id));

  if (!visible.length) {
    el.innerHTML = `<div style="text-align:center;padding:40px;color:#777">
      <div style="font-size:40px;margin-bottom:12px">📱</div>
      <p>No new requests right now</p>
      <p style="font-size:13px;margin-top:8px">Go online and wait for restaurants to mark orders ready</p>
    </div>`;
    return;
  }

  el.innerHTML = visible.map(o => `
    <div id="req-${o.order_id}" style="margin:12px 16px;background:#fff;border-radius:12px;padding:16px;box-shadow:0 2px 12px rgba(0,0,0,.08);border-left:4px solid #ff5722">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
        <div style="font-weight:700">${escHtml(o.order_number)}</div>
        <div style="display:flex;align-items:center;gap:10px">
          <div style="font-size:16px;font-weight:800;color:#4caf50">₹${o.partner_earnings}</div>
          <button onclick="dismissOrder(${o.order_id})" title="Not interested"
            style="background:#fff0ee;border:none;border-radius:50%;width:28px;height:28px;cursor:pointer;font-size:16px;color:#f44336;display:flex;align-items:center;justify-content:center;line-height:1;padding:0">✕</button>
        </div>
      </div>
      <div style="font-size:13px;color:#555;margin-bottom:4px">📍 Pickup: ${escHtml(o.restaurant_name)}</div>
      <div style="font-size:12px;color:#777;margin-bottom:4px">${escHtml(o.restaurant_address)}</div>
      <div style="font-size:13px;color:#555;margin-bottom:4px">🏠 Drop: ${escHtml(o.delivery_address_text)?.substring(0,60)}...</div>
      <div style="font-size:12px;color:#777;margin-bottom:8px">📏 ~${o.distance_km} km • ₹${o.total_amount} order</div>
      ${(() => {
        const cash = parseFloat(o.cash_to_collect ?? o.total_amount ?? 0);
        if (cash <= 0) return `<div style="background:#e8f5e9;border-radius:6px;padding:6px 10px;font-size:12px;color:#2e7d32;font-weight:600;margin-bottom:10px">💳 Paid — No cash collection</div>`;
        return `<div style="background:#fff3e0;border-radius:6px;padding:6px 10px;font-size:12px;color:#e64a19;font-weight:700;margin-bottom:10px">💵 Collect ₹${cash.toFixed(0)} from customer</div>`;
      })()}
      <button class="action-btn action-btn-primary" onclick="acceptNewOrder(${o.order_id}, ${o.distance_km})">✅ Accept Delivery</button>
    </div>`).join('');
}

function dismissOrder(orderId) {
  dismissedOrders.add(orderId);
  sessionStorage.setItem(_dismissKey, JSON.stringify([...dismissedOrders]));
  const card = document.getElementById(`req-${orderId}`);
  if (card) {
    card.style.transition = 'opacity 0.2s';
    card.style.opacity    = '0';
    setTimeout(() => {
      card.remove();
      if (!document.getElementById('newOrdersList').querySelector('[id^="req-"]')) {
        document.getElementById('newOrdersList').innerHTML = `<div style="text-align:center;padding:40px;color:#777">
          <div style="font-size:40px;margin-bottom:12px">📱</div>
          <p>No new requests right now</p>
          <p style="font-size:13px;margin-top:8px">Go online and wait for restaurants to mark orders ready</p>
        </div>`;
      }
    }, 200);
  }
}

// ── Accept New Order ──────────────────────────────────────
async function acceptNewOrder(orderId, distanceKm) {
  const res = await Api.delivery.acceptOrder(orderId, distanceKm);
  if (res?.success) {
    // Auto-advance to 'accepted' so rider doesn't need to tap accept again
    const deliveryId = res.data?.delivery_id;
    if (deliveryId) await Api.delivery.updateStatus(deliveryId, 'accepted');

    showToast('Order accepted! Head to the restaurant 🛵', 'success', 4000);
    loadMyOrders();
    loadAvailableOrders();
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    document.querySelector('[data-tab="active"]').classList.add('active');
    document.getElementById('tab-active').classList.add('active');
  } else {
    showToast(res?.message || 'Failed to accept order.', 'error');
  }
}

// ── Update Delivery Status ────────────────────────────────
async function verifyAndPick(deliveryId) {
  const otp = document.getElementById('pickupOtpInput')?.value?.trim();
  const err = document.getElementById('pickupOtpErr');
  if (!otp) { err.textContent = 'Enter the OTP from the restaurant.'; err.style.display = 'block'; return; }
  const res = await Api.delivery.updateStatus(deliveryId, 'picked', otp);
  if (res?.success) {
    // Auto-advance to on_the_way immediately after pickup
    await Api.delivery.updateStatus(deliveryId, 'on_the_way');
    startLocationTracking();
    showToast('Pickup verified! 🛵 Head to the customer.', 'success', 4000);
    loadMyOrders();
  } else {
    err.textContent = res?.message || 'Incorrect OTP.';
    err.style.display = 'block';
  }
}

async function verifyAndDeliver(deliveryId) {
  const otp = document.getElementById('deliveryOtpInput')?.value?.trim();
  const err = document.getElementById('deliveryOtpErr');
  if (!otp) { err.textContent = 'Enter the OTP from the customer.'; err.style.display = 'block'; return; }
  const res = await Api.delivery.updateStatus(deliveryId, 'delivered', otp);
  if (res?.success) {
    stopLocationTracking();
    showToast('🎉 Delivery confirmed! Great job!', 'success', 4000);
    updateEarningsDisplay();
    loadMyOrders();
  } else {
    err.textContent = res?.message || 'Incorrect OTP.';
    err.style.display = 'block';
  }
}

async function updateDeliveryStatus(deliveryId, newStatus) {
  const res = await Api.delivery.updateStatus(deliveryId, newStatus);
  if (res?.success) {
    showToast(`Status updated: ${newStatus.replace('_',' ')}`, 'success');
    loadMyOrders();
    if (newStatus === 'delivered') {
      stopLocationTracking();
      showToast('Great job! Delivery completed! 🎉', 'success', 4000);
      updateEarningsDisplay();
    }
  } else {
    showToast(res?.message || 'Update failed.', 'error');
  }
}

// ── GPS Location Tracking ─────────────────────────────────
function startLocationTracking() {
  if (locationTimer) return;
  sendLocation();
  locationTimer = setInterval(sendLocation, 10000);
}

function stopLocationTracking() {
  if (locationTimer) { clearInterval(locationTimer); locationTimer = null; }
}

function sendLocation() {
  if (!navigator.geolocation) return;
  navigator.geolocation.getCurrentPosition(
    pos => Api.delivery.updateLocation(pos.coords.latitude, pos.coords.longitude),
    err => console.warn('Location error:', err.message),
    { enableHighAccuracy: true, timeout: 5000 }
  );
}

// ── History ───────────────────────────────────────────────
async function loadHistory() {
  const res   = await Api.delivery.history();
  const items = res?.data || [];
  const el    = document.getElementById('historyList');

  if (!items.length) {
    el.innerHTML = '<div class="loading-msg">No completed deliveries yet.</div>';
    return;
  }

  el.innerHTML = items.map(o => `
    <div class="history-item">
      <div class="history-item-left">
        <div class="history-order-num">${escHtml(o.order_number)}</div>
        <div class="history-time">${new Date(o.created_at).toLocaleDateString('en-IN')}</div>
        <div style="font-size:12px;margin-top:2px;color:#555">${escHtml(o.restaurant_name)} → ${escHtml(o.delivery_address_text)?.substring(0,30)}...</div>
      </div>
      <div style="text-align:right">
        <div class="history-earn">₹${o.partner_earnings}</div>
        <div class="status-pill ${o.delivery_status === 'delivered' ? 'pill-delivered' : 'pill-cancelled'}" style="margin-top:4px">
          ${o.delivery_status}
        </div>
      </div>
    </div>`).join('');
}

// ── Earnings ──────────────────────────────────────────────
async function loadEarnings() {
  const [meRes, histRes, settingsRes] = await Promise.all([
    Api.auth.me(),
    Api.delivery.history(),
    Api.get('/settings/public'),
  ]);

  if (!meRes?.success) return;
  const dp = meRes.data?.delivery_partner;
  if (!dp) return;

  document.getElementById('totalEarn').textContent = `₹${dp.total_earnings || 0}`;
  document.getElementById('totalDel').textContent  = dp.total_deliveries  || 0;

  // Calculate today and this week from history
  const items = histRes?.data || [];
  const now   = new Date();
  const todayStr = now.toDateString();
  const weekAgo  = new Date(now); weekAgo.setDate(now.getDate() - 6);

  let todaySum = 0, weekSum = 0;
  items.forEach(o => {
    const d = new Date(o.created_at);
    const earn = parseFloat(o.partner_earnings) || 0;
    if (d.toDateString() === todayStr) todaySum += earn;
    if (d >= weekAgo) weekSum += earn;
  });

  document.getElementById('todayEarn').textContent = `₹${todaySum.toFixed(0)}`;
  document.getElementById('weekEarn').textContent  = `₹${weekSum.toFixed(0)}`;

  // Dynamic pay rates from admin settings
  const s       = settingsRes?.data || {};
  const basePay = parseFloat(s.rider_base_pay  || 25);
  const freeKm  = parseFloat(s.rider_free_km   || 2);
  const perKm   = parseFloat(s.rider_per_km_pay || 3);

  const earnBase = document.getElementById('earnBasePay');
  const earnLbl  = document.getElementById('earnDistanceLabel');
  const earnKm   = document.getElementById('earnPerKm');
  if (earnBase) earnBase.textContent = `₹${basePay}`;
  if (earnLbl)  earnLbl.textContent  = `Distance bonus (after ${freeKm} km)`;
  if (earnKm)   earnKm.textContent   = `₹${perKm}/km`;
}

function updateEarningsDisplay() {
  // Refresh earnings badge in topbar
  Api.auth.me().then(res => {
    const dp = res?.data?.delivery_partner;
    if (dp) document.getElementById('earningsDisplay').textContent = `₹${dp.total_earnings || 0} total`;
  });
}

// ── Profile Tab ───────────────────────────────────────────
function loadDeliveryProfile() {
  if (!partnerData) return;

  const dp   = partnerData;
  const user = Auth.getUser() || {};
  const name = user.name || dp.name || '?';

  document.getElementById('dpAvatar').textContent    = name[0].toUpperCase();
  document.getElementById('dpName').textContent      = name;
  document.getElementById('dpPhone').textContent     = user.phone || dp.phone || '—';
  document.getElementById('dpEmail').textContent     = user.email || '—';
  document.getElementById('dpVehicle').textContent   = dp.vehicle_type   || '—';
  document.getElementById('dpVehicleNo').textContent = dp.vehicle_number || '—';
  document.getElementById('dpTotalDel').textContent  = dp.total_deliveries || 0;
  document.getElementById('dpTotalEarn').textContent = `₹${dp.total_earnings || 0}`;

  const status = dp.verification_status || 'pending';
  const badgeMap = {
    approved:  { bg: '#e8f5e9', color: '#2e7d32', text: '✓ Verified'  },
    pending:   { bg: '#fff3e0', color: '#e65100', text: '⏳ Pending'  },
    suspended: { bg: '#ffebee', color: '#c62828', text: '🚫 Suspended'},
    rejected:  { bg: '#ffebee', color: '#c62828', text: '✗ Rejected'  },
  };
  const badge = badgeMap[status] || badgeMap.pending;
  document.getElementById('dpBadge').innerHTML =
    `<span style="background:${badge.bg};color:${badge.color};padding:3px 10px;border-radius:12px;font-size:12px;font-weight:700">${badge.text}</span>`;

  fillDpProfileView(dp);
}

// ── Edit Registration Profile (Rider) ────────────────────
let dpSelectedVehicle = '';

function loadDpAddresses() {} // kept for compatibility, no-op

function fillDpProfileView(dp) {
  document.getElementById('dpViewCity').textContent      = dp.city          || '—';
  document.getElementById('dpViewVehicleType').textContent = dp.vehicle_type  || '—';
  document.getElementById('dpViewVehicleNo').textContent = dp.vehicle_number || '—';
  document.getElementById('dpViewBank').textContent      = dp.bank_account   || '—';
  document.getElementById('dpViewIfsc').textContent      = dp.ifsc_code      || '—';
}

function toggleDpEditForm() {
  const form = document.getElementById('dpEditForm');
  const open = form.style.display === 'none';
  form.style.display = open ? 'block' : 'none';
  document.getElementById('dpEditToggleBtn').textContent = open ? 'Cancel' : 'Edit';

  if (open && partnerData) {
    const dp = partnerData;
    const cityEl = document.getElementById('dpEditCity');
    if (cityEl) cityEl.value = dp.city || '';
    document.getElementById('dpEditVehicleNo').value = dp.vehicle_number || '';
    document.getElementById('dpEditBank').value      = dp.bank_account   || '';
    document.getElementById('dpEditIfsc').value      = dp.ifsc_code      || '';
    dpSelectedVehicle = dp.vehicle_type || '';
    document.querySelectorAll('.dp-vehicle-btn').forEach(b => {
      const sel = b.dataset.v === dpSelectedVehicle;
      b.style.borderColor = sel ? '#ff5722' : '#e0e0e0';
      b.style.background  = sel ? '#fff3f0' : '#fff';
    });
    dpResetGps();
  }
}

function dpPickVehicle(btn) {
  document.querySelectorAll('.dp-vehicle-btn').forEach(b => {
    b.style.borderColor = '#e0e0e0'; b.style.background = '#fff';
  });
  btn.style.borderColor = '#ff5722'; btn.style.background = '#fff3f0';
  dpSelectedVehicle = btn.dataset.v;
}

async function saveDpProfile() {
  const city      = document.getElementById('dpEditCity').value;
  const vehicleNo = document.getElementById('dpEditVehicleNo').value.trim();
  const bank      = document.getElementById('dpEditBank').value.trim();
  const ifsc      = document.getElementById('dpEditIfsc').value.trim();

  const payload = {};
  if (city)           payload.city           = city;
  if (dpSelectedVehicle) payload.vehicle_type = dpSelectedVehicle;
  if (vehicleNo)      payload.vehicle_number = vehicleNo;
  if (bank)           payload.bank_account   = bank;
  if (ifsc)           payload.ifsc_code      = ifsc;

  if (!Object.keys(payload).length) {
    showDpEditMsg('Nothing to update.', false); return;
  }

  const btn = document.getElementById('saveDpProfileBtn');
  btn.disabled = true; btn.textContent = 'Saving…';

  const res = await Api.delivery.updateProfile(payload);
  btn.disabled = false; btn.textContent = 'Save Changes';

  if (res?.success) {
    // Update local partnerData so view reflects changes immediately
    Object.assign(partnerData, payload);
    fillDpProfileView(partnerData);
    // Also update the top info row
    if (payload.vehicle_type) document.getElementById('dpVehicle').textContent   = payload.vehicle_type;
    if (payload.vehicle_number) document.getElementById('dpVehicleNo').textContent = payload.vehicle_number;
    showDpEditMsg('Profile updated!', true);
    setTimeout(toggleDpEditForm, 1200);
  } else {
    showDpEditMsg(res?.message || 'Failed to update.', false);
  }
}

function showDpEditMsg(msg, ok) {
  const el = document.getElementById('dpEditMsg');
  if (!el) return;
  el.textContent = msg;
  el.style.display    = 'block';
  el.style.background = ok ? '#e8f5e9' : '#ffebee';
  el.style.color      = ok ? '#2e7d32' : '#c62828';
  setTimeout(() => el.style.display = 'none', 3500);
}

function dpResetGps() {
  const btn = document.getElementById('dpGpsBtn');
  if (!btn) return;
  document.getElementById('dpGpsIcon').textContent = '📍';
  document.getElementById('dpGpsTxt').textContent  = 'Detect City via GPS';
  btn.disabled = false;
}

async function dpUseGPS() {
  if (!navigator.geolocation) {
    showDpEditMsg('GPS not supported on this browser.', false); return;
  }
  const btn = document.getElementById('dpGpsBtn');
  btn.disabled = true;
  document.getElementById('dpGpsIcon').textContent = '⏳';
  document.getElementById('dpGpsTxt').textContent  = 'Fetching location…';

  navigator.geolocation.getCurrentPosition(
    async (pos) => {
      try {
        const r = await fetch(`https://nominatim.openstreetmap.org/reverse?lat=${pos.coords.latitude}&lon=${pos.coords.longitude}&format=json`);
        const d = await r.json();
        const detectedCity = d.address?.city || d.address?.town || d.address?.village || d.address?.county || '';
        const cityEl = document.getElementById('dpEditCity');
        if (detectedCity && cityEl) {
          // Try to match against existing options
          const opt = Array.from(cityEl.options).find(o => o.value.toLowerCase() === detectedCity.toLowerCase());
          if (opt) {
            cityEl.value = opt.value;
            document.getElementById('dpGpsIcon').textContent = '✅';
            document.getElementById('dpGpsTxt').textContent  = `City set: ${opt.value}`;
          } else {
            document.getElementById('dpGpsIcon').textContent = '📍';
            document.getElementById('dpGpsTxt').textContent  = `Detected: ${detectedCity} — select manually`;
          }
        }
      } catch {
        document.getElementById('dpGpsIcon').textContent = '📍';
        document.getElementById('dpGpsTxt').textContent  = 'Could not detect — select manually';
      }
      btn.disabled = false;
    },
    () => {
      dpResetGps();
      showDpEditMsg('Could not get location. Allow GPS access.', false);
    },
    { timeout: 10000 }
  );
}

function toggleDpPwd(inputId, btn) {
  const input = document.getElementById(inputId);
  const show  = input.type === 'password';
  input.type  = show ? 'text' : 'password';
  btn.textContent  = show ? '🙈' : '👁';
  btn.style.color  = show ? '#ff5722' : '#aaa';
}

async function saveDpPassword() {
  const current = document.getElementById('dpCurrentPwd').value;
  const newPwd  = document.getElementById('dpNewPwd').value;
  const confirm = document.getElementById('dpConfirmPwd').value;

  const msgEl = document.getElementById('dpPwdMsg');

  function showDpMsg(text, success) {
    msgEl.textContent = text;
    msgEl.style.display    = 'block';
    msgEl.style.background = success ? '#e8f5e9' : '#ffebee';
    msgEl.style.color      = success ? '#2e7d32' : '#c62828';
    setTimeout(() => { msgEl.style.display = 'none'; }, 4000);
  }

  if (!current || !newPwd) { showDpMsg('Fill in all password fields.', false); return; }
  if (newPwd.length < 6)   { showDpMsg('New password must be at least 6 characters.', false); return; }
  if (newPwd !== confirm)  { showDpMsg('Passwords do not match.', false); return; }

  const btn = document.querySelector('[onclick="saveDpPassword()"]');
  if (btn) { btn.disabled = true; btn.textContent = 'Updating…'; }

  const res = await Api.request('PUT', '/me', { current_password: current, new_password: newPwd });

  if (btn) { btn.disabled = false; btn.textContent = 'Update Password'; }

  if (res?.success) {
    showDpMsg('Password changed successfully!', true);
    document.getElementById('dpCurrentPwd').value = '';
    document.getElementById('dpNewPwd').value      = '';
    document.getElementById('dpConfirmPwd').value  = '';
  } else {
    showDpMsg(res?.message || 'Failed to update password.', false);
  }
}
