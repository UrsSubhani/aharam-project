const API_BASE = 'http://localhost/aharam/backend-api';

const Api = {
  async request(method, path, body = null) {
    const token   = localStorage.getItem('aharam_rider_token');
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
  get:   p     => Api.request('GET',   p),
  post:  (p,b) => Api.request('POST',  p, b),
  patch: (p,b) => Api.request('PATCH', p, b),

  auth:      { login: d => Api.post('/login', d), register: d => Api.post('/register', d), me: () => Api.get('/me') },
  addresses: {
    list:       ()        => Api.get('/addresses'),
    create:     (d)       => Api.post('/addresses', d),
    update:     (id, d)   => Api.request('PUT', `/addresses/${id}`, d),
    delete:     (id)      => Api.request('DELETE', `/addresses/${id}`),
    setDefault: (id)      => Api.patch(`/addresses/${id}/default`),
  },
  delivery: {
    setupProfile:       (d)       => Api.post('/delivery/profile', d),
    updateProfile:      (d)       => Api.request('PUT', '/delivery/profile', d),
    myOrders:           ()        => Api.get('/delivery/my-orders?status=active'),
    history:            ()        => Api.get('/delivery/my-orders?status=completed'),
    availableOrders:    (lat,lng) => Api.get(`/delivery/available-orders${lat ? `?lat=${lat}&lng=${lng}` : ''}`),
    acceptOrder:        (orderId, distanceKm) => Api.post('/delivery/accept', { order_id: orderId, distance_km: distanceKm }),
    updateStatus:       (id,s,otp) => Api.patch(`/delivery/${id}/status`, otp ? { status: s, otp } : { status: s }),
    updateLocation:     (lat,lng) => Api.patch('/delivery/location', { lat, lng }),
    toggleAvailability: (v)       => Api.patch('/delivery/availability', { available: v }),
  },
};

const Auth = {
  save(t,u)    { localStorage.setItem('aharam_rider_token',t); localStorage.setItem('aharam_rider_user',JSON.stringify(u)); },
  logout()     {
    const uid = JSON.parse(localStorage.getItem('aharam_rider_user') || '{}')?.id || 'guest';
    sessionStorage.removeItem('dismissed_orders_' + uid);
    sessionStorage.removeItem('dismissed_orders'); // clear legacy key too
    localStorage.removeItem('aharam_rider_token');
    localStorage.removeItem('aharam_rider_user');
    window.location.href = '/aharam/delivery-app/login.html';
  },
  getUser()    { try{ return JSON.parse(localStorage.getItem('aharam_rider_user')); }catch{ return null; } },
  isLoggedIn() { return !!localStorage.getItem('aharam_rider_token'); },
};

function showToast(msg, type='default', duration=3000) {
  let t = document.getElementById('toast');
  if (!t) { t=document.createElement('div'); t.id='toast'; document.body.appendChild(t); }
  t.textContent=msg; t.className=`show ${type}`;
  setTimeout(()=>t.classList.remove('show'),duration);
}
function escHtml(s) { if(!s)return''; return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
