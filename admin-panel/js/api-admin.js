const API_BASE = window.location.hostname === 'localhost'
  ? 'http://localhost/aharam/backend-api'
  : 'https://aharam.in/backend';

const Api = {
  async request(method, path, body = null) {
    const token   = localStorage.getItem('aharam_admin_token');
    const headers = { 'Content-Type': 'application/json' };
    if (token) headers['Authorization'] = `Bearer ${token}`;
    const options = { method, headers };
    if (body && method !== 'GET') options.body = JSON.stringify(body);
    try {
      const res  = await fetch(API_BASE + path, options);
      if (res.status === 204) return { success: true };
      const data = await res.json();
      if (res.status === 401) { Auth.logout(); return null; }
      return data;
    } catch { return { success: false, message: 'Network error.' }; }
  },
  get:    p     => Api.request('GET',    p),
  post:   (p,b) => Api.request('POST',   p, b),
  patch:  (p,b) => Api.request('PATCH',  p, b),
  put:    (p,b) => Api.request('PUT',    p, b),
  delete: p     => Api.request('DELETE', p),

  auth: {
    login: d  => Api.post('/login', d),
    me:    () => Api.get('/me'),
  },
  admin: {
    dashboard:         ()       => Api.get('/admin/dashboard'),
    users:             (p='')   => Api.get(`/admin/users${p}`),
    restaurants:       (p='')   => Api.get(`/admin/restaurants${p}`),
    approveRestaurant: (id,action) => Api.patch(`/admin/restaurants/${id}/approve`, { action }),
    orders:            (p='')   => Api.get(`/admin/orders${p}`),
    earnings:          ()       => Api.get('/admin/earnings'),
    settlements:          ()         => Api.get('/admin/settlements'),
    generateSettlements:  (from, to) => Api.post('/admin/settlements/generate', { from, to }),
    processSettlement:    (id)       => Api.post(`/admin/settlements/process/${id}`),
    deliveryPartners:  ()       => Api.get('/admin/delivery-partners'),
    verifyPartner:     (id,s)   => Api.patch(`/admin/delivery-partners/${id}/verify`, { status: s }),
    settings:          ()       => Api.get('/admin/settings'),
    updateSettings:    (d)      => Api.put('/admin/settings', d),
    createCoupon:      (d)      => Api.post('/admin/coupons', d),
    updateCoupon:      (id, d)  => Api.put(`/admin/coupons/${id}`, d),
    toggleCoupon:      (id)     => Api.patch(`/admin/coupons/${id}/toggle`, {}),
    coupons:           ()       => Api.get('/admin/coupons'),
    wallet:            ()       => Api.get('/admin/wallet'),
    referrals:         ()       => Api.get('/admin/referrals'),
    approveReferral:   (id)     => Api.post(`/admin/referrals/${id}/approve`, {}),
    creditWallet:      (id, amount, note) => Api.post(`/admin/users/${id}/credit-wallet`, { amount, note }),
  },
};

const Auth = {
  save(t, u)   { localStorage.setItem('aharam_admin_token', t); localStorage.setItem('aharam_admin_user', JSON.stringify(u)); },
  logout()     { localStorage.removeItem('aharam_admin_token'); localStorage.removeItem('aharam_admin_user'); window.location.href = 'login.html'; },
  getUser()    { try { return JSON.parse(localStorage.getItem('aharam_admin_user')); } catch { return null; } },
  isLoggedIn() { return !!localStorage.getItem('aharam_admin_token'); },
};

function showToast(msg, type = 'default', duration = 3000) {
  let t = document.getElementById('toast');
  if (!t) { t = document.createElement('div'); t.id = 'toast'; document.body.appendChild(t); }
  t.textContent = msg; t.className = `show ${type}`;
  setTimeout(() => t.classList.remove('show'), duration);
}
function escHtml(s) { if (!s) return ''; return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
function fmtDate(d) { if (!d) return '—'; return new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }); }
function fmtDateTime(d) { if (!d) return '—'; return new Date(d).toLocaleString('en-IN', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }); }
function fmtMoney(n) { return '₹' + (Number(n) || 0).toLocaleString('en-IN'); }
