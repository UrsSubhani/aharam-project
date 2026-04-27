/**
 * auth.js — Authentication state management
 *
 * Handles token storage, user session, and UI updates based on login state.
 * Included on every page — sets up header user/auth sections automatically.
 */

const Auth = {
  /**
   * Save auth data after login/register.
   */
  save(token, user) {
    localStorage.setItem('aharam_customer_token', token);
    localStorage.setItem('aharam_customer_user', JSON.stringify(user));
    this.updateUI(user);
  },

  /**
   * Clear session and optionally redirect to login.
   */
  logout(redirect = true) {
    localStorage.removeItem('aharam_customer_token');
    localStorage.removeItem('aharam_customer_user');
    this.updateUI(null);
    if (redirect) window.location.href = '/aharam/customer-app/index.html';
  },

  /**
   * Get current user from localStorage.
   */
  getUser() {
    try {
      return JSON.parse(localStorage.getItem('aharam_customer_user'));
    } catch { return null; }
  },

  /**
   * Get current JWT token.
   */
  getToken() {
    return localStorage.getItem('aharam_customer_token');
  },

  /**
   * Check if user is logged in.
   */
  isLoggedIn() {
    return !!this.getToken();
  },

  /**
   * Update header based on auth state.
   * Runs automatically on DOMContentLoaded.
   */
  updateUI(user) {
    const authSection = document.getElementById('authSection');
    const userSection = document.getElementById('userSection');
    const userNameEl  = document.getElementById('userName');

    if (!authSection && !userSection) return;

    if (user || this.isLoggedIn()) {
      const u = user || this.getUser();
      if (authSection) authSection.style.display = 'none';
      if (userSection) userSection.style.display = 'flex';
      if (userNameEl && u) userNameEl.textContent = u.name.split(' ')[0];
    } else {
      if (authSection) authSection.style.display = 'flex';
      if (userSection) userSection.style.display = 'none';
    }
  },

  /**
   * Require login — if not logged in, redirect to login page.
   */
  requireLogin() {
    if (!this.isLoggedIn()) {
      window.location.href = '/aharam/customer-app/pages/login.html?redirect=' + encodeURIComponent(window.location.href);
      return false;
    }
    return true;
  },
};

// ── Global toast helper ─────────────────────────────────────
function showToast(message, type = 'default', duration = 3000) {
  let toast = document.getElementById('toast');
  if (!toast) {
    toast = document.createElement('div');
    toast.id = 'toast';
    document.body.appendChild(toast);
  }
  toast.textContent = message;
  toast.className   = `show ${type}`;
  setTimeout(() => toast.classList.remove('show'), duration);
}

// ── Global city management ──────────────────────────────────
const CityManager = {
  getCity()     { return localStorage.getItem('aharam_city') ?? ''; },
  setCity(city) {
    localStorage.setItem('aharam_city', city);
    const el = document.getElementById('currentCity');
    if (el) el.textContent = city || 'All Cities';
    const sel = document.getElementById('citySelect');
    if (sel) sel.value = city;
  },
};

// ── Cart badge update ──────────────────────────────────────
async function updateCartBadge() {
  if (!Auth.isLoggedIn()) return;
  const res = await Api.cart.get();
  const badge = document.getElementById('cartBadge');
  if (badge && res?.success) {
    badge.textContent = res.data?.item_count || 0;
  }
}

// ── DOMContentLoaded init ──────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  // Update auth UI
  Auth.updateUI(null);

  // City display
  const cityEl = document.getElementById('currentCity');
  if (cityEl) cityEl.textContent = CityManager.getCity() || 'All Cities';
  const citySelect = document.getElementById('citySelect');
  if (citySelect) citySelect.value = CityManager.getCity();

  // City modal toggle
  const locationBar = document.getElementById('locationBar');
  const cityModal   = document.getElementById('cityModal');
  if (locationBar && cityModal) {
    locationBar.addEventListener('click', () => cityModal.classList.add('active'));
    document.getElementById('closeCityModal')?.addEventListener('click', () => cityModal.classList.remove('active'));
    cityModal.addEventListener('click', e => { if (e.target === cityModal) cityModal.classList.remove('active'); });
    document.querySelectorAll('.city-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        CityManager.setCity(btn.dataset.city);
        cityModal.classList.remove('active');
        showToast(btn.dataset.city ? `City: ${btn.dataset.city}` : 'Showing all cities');
        // Reload restaurant listings if on home page
        if (typeof loadRestaurants === 'function') loadRestaurants();
      });
    });
  }

  // Logout button
  document.getElementById('logoutBtn')?.addEventListener('click', (e) => {
    e.preventDefault();
    Auth.logout();
  });

  // Cart badge
  updateCartBadge();
});
