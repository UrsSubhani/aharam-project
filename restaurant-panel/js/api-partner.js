/**
 * api-partner.js — API client for Restaurant Panel
 */

const API_BASE = window.location.hostname === 'localhost'
  ? 'http://localhost/aharam/backend-api'
  : 'https://aharam.in/backend';

const Api = {
  async request(method, path, body = null, query = {}) {
    const token = localStorage.getItem('aharam_partner_token');
    const headers = { 'Content-Type': 'application/json' };
    if (token) headers['Authorization'] = `Bearer ${token}`;

    const url = new URL(API_BASE + path, window.location.origin);
    Object.entries(query).forEach(([k, v]) => v !== undefined && v !== '' && url.searchParams.set(k, v));

    const options = { method, headers };
    if (body && method !== 'GET') options.body = JSON.stringify(body);

    try {
      const res  = await fetch(url.toString(), options);
      const data = await res.json();
      if (res.status === 401) {
        localStorage.removeItem('aharam_partner_token');
        localStorage.removeItem('aharam_partner_user');
        window.location.href = 'login.html';
        return null;
      }
      return data;
    } catch (err) {
      return { success: false, message: 'Network error.' };
    }
  },

  get:   (path, q = {}) => Api.request('GET',    path, null, q),
  post:  (path, body)   => Api.request('POST',   path, body),
  put:   (path, body)   => Api.request('PUT',    path, body),
  patch: (path, body)   => Api.request('PATCH',  path, body),
  del:   (path)         => Api.request('DELETE', path),

  auth:    { login: d => Api.post('/login', d), me: () => Api.get('/me') },
  restaurant: {
    get:      id    => Api.get(`/restaurants/${id}`),
    getOwner: id    => Api.get(`/restaurants/${id}/owner`),
    create:   d     => Api.post('/restaurants', d),
    update:   (id,d)=> Api.put(`/restaurants/${id}`, d),
    toggle:   id    => Api.patch(`/restaurants/${id}/toggle`),
    stats:    id    => Api.get(`/restaurants/${id}/stats`),
  },
  menu: {
    get:       id          => Api.get(`/menu/${id}`),
    getItem:   (rId, iId) => Api.get(`/menu/${rId}/item/${iId}`),
    create:    data        => Api.post('/menu', data),
    update:    (id,d)      => Api.put(`/menu/${id}`, d),
    delete:    id          => Api.del(`/menu/${id}`),
    toggle:    id          => Api.patch(`/menu/${id}/toggle`),
    cats:      ()          => Api.get('/menu-categories'),
  },
  orders: {
    list:         (q={}) => Api.get('/restaurant-orders', q),
    show:         id     => Api.get(`/order/${id}`),
    updateStatus: (id,s) => Api.patch(`/order/${id}/status`, { status: s }),
  },
  coupons: {
    list:   ()       => Api.get('/restaurant/coupons'),
    create: d        => Api.post('/restaurant/coupons', d),
    update: (id, d)  => Api.put(`/restaurant/coupons/${id}`, d),
    toggle: id       => Api.patch(`/restaurant/coupons/${id}/toggle`, {}),
  },
  subscription: {
    status:    ()  => Api.get('/subscription'),
    subscribe: p   => Api.post('/subscription/restaurant', { plan: p }),
  },
};

// Auth helpers
const Auth = {
  save(token, user) { localStorage.setItem('aharam_partner_token', token); localStorage.setItem('aharam_partner_user', JSON.stringify(user)); },
  logout() { localStorage.removeItem('aharam_partner_token'); localStorage.removeItem('aharam_partner_user'); window.location.href = 'login.html'; },
  getUser() { try { return JSON.parse(localStorage.getItem('aharam_partner_user')); } catch { return null; } },
  isLoggedIn() { return !!localStorage.getItem('aharam_partner_token'); },
};

function showToast(msg, type = 'default', duration = 3000) {
  let t = document.getElementById('toast');
  if (!t) { t = document.createElement('div'); t.id = 'toast'; document.body.appendChild(t); }
  t.textContent = msg; t.className = `show ${type}`;
  setTimeout(() => t.classList.remove('show'), duration);
}

function escHtml(s) {
  if (!s) return '';
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
