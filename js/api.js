/**
 * api.js — Central API client for Customer App
 *
 * All HTTP calls to backend-api go through this module.
 * Automatically attaches JWT token from localStorage.
 */

const API_BASE = window.location.hostname === 'localhost'
  ? 'http://localhost/aharam/backend-api'
  : 'https://aharam.in/backend-api';

const Api = {
  /**
   * Core request method.
   * @param {string} method  GET | POST | PUT | PATCH | DELETE
   * @param {string} path    e.g. '/restaurants'
   * @param {object} body    Request body (for POST/PUT/PATCH)
   * @param {object} query   Query params appended to URL
   */
  async request(method, path, body = null, query = {}) {
    const token = localStorage.getItem('aharam_customer_token');
    const headers = { 'Content-Type': 'application/json' };
    if (token) headers['Authorization'] = `Bearer ${token}`;

    // Build URL with query params
    const url = new URL(API_BASE + path, window.location.origin);
    Object.entries(query).forEach(([k, v]) => v && url.searchParams.set(k, v));

    const options = { method, headers };
    if (body && method !== 'GET') options.body = JSON.stringify(body);

    try {
      const res  = await fetch(url.toString(), options);
      const data = await res.json();

      if (res.status === 401) {
        // Token expired — clear session and redirect to login
        Auth.logout(false);
        window.location.href = '/aharam/customer-app/pages/login.html?expired=1';
        return null;
      }

      if (data && typeof data === 'object') data.status = res.status;
      return data;
    } catch (err) {
      console.error('API Error:', err);
      return { success: false, message: 'Network error. Please check your connection.' };
    }
  },

  get:    (path, query = {})       => Api.request('GET',    path, null, query),
  post:   (path, body = {})        => Api.request('POST',   path, body),
  put:    (path, body = {})        => Api.request('PUT',    path, body),
  patch:  (path, body = {})        => Api.request('PATCH',  path, body),
  delete: (path)                   => Api.request('DELETE', path),

  // ── Auth endpoints ──────────────────────────────────────
  auth: {
    register:  (data)  => Api.post('/register', data),
    login:     (data)  => Api.post('/login', data),
    logout:    ()      => Api.post('/logout'),
    me:        ()      => Api.get('/me'),
    verifyOtp: (data)  => Api.post('/verify-otp', data),
    forgotPwd: (data)  => Api.post('/forgot-password', data),
    resetPwd:  (data)  => Api.post('/reset-password', data),
  },

  // ── Restaurants ─────────────────────────────────────────
  restaurants: {
    list:   (city, query = {})    => Api.get('/restaurants', { city, ...query }),
    show:   (id)                  => Api.get(`/restaurants/${id}`),
    reviews:(id)                  => Api.get(`/reviews/${id}`),
  },

  // ── Menu ────────────────────────────────────────────────
  menu: {
    get:    (restaurantId)      => Api.get(`/menu/${restaurantId}`),
    search: (q, city)           => Api.get('/items/search', { q, city }),
  },

  // ── Cart ────────────────────────────────────────────────
  cart: {
    get:    ()                    => Api.get('/cart'),
    add:    (data)                => Api.post('/cart/add', data),
    update: (data)                => Api.put('/cart/update', data),
    remove: (menuItemId)          => Api.delete(`/cart/remove/${menuItemId}`),
    clear:  ()                    => Api.delete('/cart/clear'),
  },

  // ── Coupons ─────────────────────────────────────────────
  coupons: {
    apply:  (data)  => Api.post('/coupon/apply', data),
    list:   (restId) => Api.get('/coupons', restId ? { restaurant_id: restId } : {}),
  },

  // ── Orders ──────────────────────────────────────────────
  orders: {
    place:   (data)  => Api.post('/order/place', data),
    show:    (id)    => Api.get(`/order/${id}`),
    status:  (id)    => Api.get(`/order/${id}/status`),
    history: (page)  => Api.get('/orders/history', { page }),
    cancel:  (id, reason) => Api.post(`/order/${id}/cancel`, { reason }),
    reorder: (id)    => Api.post(`/order/${id}/reorder`),
    track:   (id)    => Api.get(`/delivery/${id}/track`),
  },

  // ── Payment ─────────────────────────────────────────────
  payment: {
    initiate: (data) => Api.post('/payment/initiate', data),
    verify:   (data) => Api.post('/payment/verify', data),
  },

  // ── Recommendations ─────────────────────────────────────
  recommendations: {
    get:      (city) => Api.get('/recommendations', { city }),
    trending: (city) => Api.get('/recommendations/trending', { city }),
  },

  // ── Addresses ───────────────────────────────────────────
  addresses: {
    list:       ()     => Api.get('/addresses'),
    create:     (data) => Api.post('/addresses', data),
    update:     (id, data) => Api.put(`/addresses/${id}`, data),
    delete:     (id)   => Api.delete(`/addresses/${id}`),
    setDefault: (id)   => Api.patch(`/addresses/${id}/default`),
  },

  // ── Subscriptions ────────────────────────────────────────
  subscription: {
    status:    ()     => Api.get('/subscription'),
    subscribe: ()     => Api.post('/subscription/customer'),
  },

  // ── Reviews ─────────────────────────────────────────────
  reviews: {
    create: (data) => Api.post('/review', data),
  },

  // ── Notifications ────────────────────────────────────────
  notifications: {
    list:     ()     => Api.get('/notifications'),
    markRead: (id)   => Api.patch('/notifications/read', id ? { id } : {}),
  },

  // ── Wallet ───────────────────────────────────────────────
  wallet: {
    get:          ()     => Api.get('/wallet'),
    transactions: (page) => Api.get('/wallet/transactions', { page }),
  },

  // ── Referral ─────────────────────────────────────────────
  referral: {
    get:   ()     => Api.get('/referral'),
    apply: (code) => Api.post('/referral/apply', { referral_code: code }),
  },
};
