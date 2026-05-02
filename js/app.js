/**
 * app.js — Homepage logic
 *
 * - Loads nearby restaurants
 * - Loads recommendations based on time-of-day
 * - Loads trending items
 * - Search functionality
 */

let currentPage     = 1;
let isLoadingMore   = false;
let allLoaded       = false;

// ── Restaurant Card HTML ─────────────────────────────────
const CARD_GRADIENTS = [
  ['#ff6b6b','#feca57'], ['#48dbfb','#ff9ff3'], ['#1dd1a1','#54a0ff'],
  ['#fd9644','#e55039'], ['#a29bfe','#fd79a8'], ['#00b894','#00cec9'],
  ['#e17055','#fdcb6e'], ['#6c5ce7','#a29bfe'], ['#fab1a0','#e84393'],
  ['#55efc4','#0984e3'],
];
const CARD_ICONS = ['🍛','🍜','🍚','🥘','🍲','🥗','🍱','🍔','🌮','🥙'];

function renderRestaurantCard(r) {
  const isOpen    = r.is_currently_open !== undefined ? r.is_currently_open : r.is_open;
  const rating    = parseFloat(r.avg_rating || 0).toFixed(1);
  const ratingCol = rating >= 4 ? '#2e7d32' : rating >= 3 ? '#e65100' : '#777';

  const gi = (r.id || 0) % CARD_GRADIENTS.length;
  const [c1, c2] = CARD_GRADIENTS[gi];
  const icon = CARD_ICONS[(r.id || 0) % CARD_ICONS.length];

  const imgHtml = r.logo_image
    ? `<img src="${r.logo_image}" alt="${escHtml(r.name)}" loading="lazy"/>`
    : `<div style="width:100%;height:100%;display:flex;flex-direction:column;align-items:center;justify-content:center;background:linear-gradient(135deg,${c1},${c2});gap:8px">
         <span style="font-size:52px;filter:drop-shadow(0 2px 6px rgba(0,0,0,.18))">${icon}</span>
       </div>`;

  return `
    <div class="restaurant-card${isOpen ? '' : ' card-closed'}" onclick="${isOpen ? `openRestaurant(${r.id})` : ''}">
      <div class="card-image-wrap">
        ${imgHtml}
        ${!isOpen ? '<div class="card-closed-overlay">Closed</div>' : ''}
        <div class="card-status-badge ${isOpen ? 'badge-open' : 'badge-closed'}">${isOpen ? '● Open' : '● Closed'}</div>
      </div>
      <div class="card-body">
        <div class="card-title">${escHtml(r.name)}</div>
        <div class="card-cuisine">${escHtml((r.cuisine_type || 'Multi-cuisine').replace(/,\s*/g, ' · '))}</div>
        <div class="card-meta">
          <span class="card-rating" style="color:${ratingCol}">★ ${rating}</span>
          <span class="card-dot">·</span>
          <span class="card-time">🕐 ${r.avg_delivery_time || 30} min</span>
          ${r.min_order_amount > 0 ? `<span class="card-dot">·</span><span class="card-min">₹${r.min_order_amount} min</span>` : ''}
        </div>
      </div>
    </div>`;
}

// ── Trending Item Card HTML ───────────────────────────────
function renderItemCard(item) {
  const vegClass  = item.food_type === 'veg' ? 'veg' : item.food_type === 'egg' ? 'egg' : 'non_veg';
  const imgHtml   = item.image
    ? `<img src="${item.image}" alt="${item.name}"/>`
    : `<div style="font-size:40px;display:flex;align-items:center;justify-content:center;height:100%">🍜</div>`;

  return `
    <div class="item-card" onclick="openRestaurant(${item.restaurant_id})">
      <div class="item-img">${imgHtml}</div>
      <div class="item-body">
        <div class="item-name">
          <span class="veg-dot ${vegClass}"></span>${escHtml(item.name)}
        </div>
        <div class="item-rest">${escHtml(item.restaurant_name)}</div>
        <div class="item-price">${item.discount_price ? `<span style="text-decoration:line-through;color:#aaa;font-size:12px;margin-right:4px">₹${item.price}</span>₹${item.discount_price}` : `₹${item.price}`}</div>
      </div>
    </div>`;
}

// ── Load Restaurants ─────────────────────────────────────
async function loadRestaurants(reset = true) {
  if (reset) {
    currentPage = 1;
    allLoaded   = false;
  }

  const city    = CityManager.getCity();
  const sortBy  = document.getElementById('sortSelect')?.value || 'avg_rating';
  const cuisine = document.querySelector('.cuisine-chip.active')?.dataset.cuisine || '';
  const grid    = document.getElementById('restaurantGrid');
  const btn     = document.getElementById('loadMoreBtn');

  if (reset) {
    grid.innerHTML = '<div class="loading-spinner">🍽 Loading restaurants...</div>';
  }

  const res = await Api.restaurants.list(city, {
    sort:    sortBy,
    page:    currentPage,
    limit:   12,
    cuisine: cuisine || undefined,
  });

  if (!res?.success) {
    grid.innerHTML = '<div class="loading-spinner">Could not load restaurants. Please try again.</div>';
    return;
  }

  const items = res.data || [];

  if (reset) {
    grid.innerHTML = items.length
      ? items.map(renderRestaurantCard).join('')
      : '<div class="loading-spinner">No restaurants found in your city yet.</div>';
  } else {
    grid.insertAdjacentHTML('beforeend', items.map(renderRestaurantCard).join(''));
  }

  // Show/hide Load More based on pagination
  const meta = res.meta;
  if (meta && meta.has_next) {
    btn.style.display = 'inline-flex';
  } else {
    btn.style.display = 'none';
    allLoaded = true;
  }
}

// ── Load Cities Dynamically ───────────────────────────────
async function loadCities() {
  const res    = await Api.get('/cities');
  const cities = res?.data || [];
  if (!cities.length) return;

  const sel      = document.getElementById('citySelect');
  const cityList = document.getElementById('cityList');
  const saved    = CityManager.getCity();

  // Populate hero search dropdown
  cities.forEach(c => {
    const opt      = document.createElement('option');
    opt.value      = c;
    opt.textContent = c;
    if (c === saved) opt.selected = true;
    sel.appendChild(opt);
  });

  // Populate city modal
  if (cityList) {
    cities.forEach(c => {
      const btn = document.createElement('button');
      btn.className    = 'city-btn';
      btn.dataset.city = c;
      btn.textContent  = c;
      cityList.appendChild(btn);
    });
    // Re-bind click handlers on new buttons
    cityList.querySelectorAll('.city-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        CityManager.setCity(btn.dataset.city);
        document.getElementById('cityModal').classList.remove('open');
        document.getElementById('currentCity').textContent = btn.dataset.city || 'All Cities';
        loadRestaurants();
      });
    });
  }
}

// ── Load Recommendations ──────────────────────────────────
async function loadRecommendations() {
  const city  = CityManager.getCity();
  const grid  = document.getElementById('recommendedGrid');
  const title = document.getElementById('mealTimeTitle');

  const res = await Api.recommendations.get(city);

  if (!res?.success) return;

  const { meal_period, suggested_restaurants, reorder_suggestion } = res.data;

  const h = new Date().getHours();
  const greeting = h < 12 ? 'Good Morning' : h < 17 ? 'Good Afternoon' : 'Good Evening';
  const mealLabels = {
    breakfast:  `🌅 ${greeting}! Try these for Breakfast`,
    lunch:      `☀️ ${greeting}! What's for Lunch?`,
    snack:      `🍟 ${greeting}! Snack Time Picks`,
    dinner:     `🌙 ${greeting}! Dinner Ideas for You`,
    late_night: '🌃 Late Night Cravings?',
  };
  if (title) title.textContent = mealLabels[meal_period] || `✨ ${greeting}! Recommended for You`;

  if (grid && suggested_restaurants?.length) {
    grid.innerHTML = suggested_restaurants.map(renderRestaurantCard).join('');
  }

}

// ── Load Trending ─────────────────────────────────────────
async function loadTrending() {
  const city = CityManager.getCity();
  const grid = document.getElementById('trendingGrid');
  if (!grid) return;

  const res = await Api.recommendations.trending(city);
  if (!res?.success || !res.data?.length) {
    grid.closest('.section')?.remove();
    return;
  }

  grid.innerHTML = res.data.map(renderItemCard).join('');
}

// ── Reorder Banner ────────────────────────────────────────
function showReorderBanner(suggestion) {
  const section = document.getElementById('recommendSection');
  if (!section) return;

  const items = suggestion.items.map(i => `${i.quantity}× ${i.name}`).join(', ');
  const banner = document.createElement('div');
  banner.className = 'reorder-banner';
  banner.innerHTML = `
    <div class="reorder-left">
      <div class="reorder-icon">🔄</div>
      <div>
        <div class="reorder-title">Order Again from <strong>${escHtml(suggestion.restaurant_name)}</strong></div>
        <div class="reorder-items">${escHtml(items)}</div>
      </div>
    </div>
    <button class="reorder-btn" onclick="reorderFromHistory(${suggestion.order_id})">Reorder</button>`;

  section.querySelector('.container')?.prepend(banner);
}

// ── Reorder ───────────────────────────────────────────────
async function reorderFromHistory(orderId) {
  if (!Auth.requireLogin()) return;

  const res = await Api.orders.reorder(orderId);
  if (res?.success) {
    showToast('Items added to cart!', 'success');
    updateCartBadge();
    setTimeout(() => openCart(), 500);
  } else {
    showToast(res?.message || 'Reorder failed.', 'error');
  }
}

// ── Navigate to restaurant ────────────────────────────────
function openRestaurant(id) {
  window.location.href = `pages/menu.html?restaurant_id=${id}`;
}

// ── Search ────────────────────────────────────────────────
async function doSearch() {
  const q    = document.getElementById('searchInput')?.value.trim();
  const city = document.getElementById('citySelect')?.value || CityManager.getCity();

  if (city) CityManager.setCity(city);

  if (!q) {
    loadRestaurants();
    return;
  }

  const grid = document.getElementById('restaurantGrid');
  const btn  = document.getElementById('loadMoreBtn');
  grid.innerHTML = '<div class="loading-spinner">🔍 Searching...</div>';
  if (btn) btn.style.display = 'none';

  document.getElementById('restaurantGrid')?.closest('section')
    ?.scrollIntoView({ behavior: 'smooth', block: 'start' });

  // Fetch restaurants AND menu items in parallel
  const [restRes, itemRes] = await Promise.all([
    Api.restaurants.list(city, { q, limit: 20 }),
    Api.menu.search(q, city),
  ]);

  const restaurants = restRes?.data || [];
  const menuItems   = itemRes?.data  || [];

  const titleEl = document.querySelector('.section-title');
  if (titleEl) titleEl.textContent = `Results for "${q}"`;

  if (!restaurants.length && !menuItems.length) {
    grid.innerHTML = `<div class="loading-spinner">No results found for "<strong>${escHtml(q)}</strong>"</div>`;
    return;
  }

  let html = '';

  // Menu item results
  if (menuItems.length) {
    html += `<div style="grid-column:1/-1;font-size:13px;font-weight:700;color:#777;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px">Dishes</div>`;
    html += menuItems.map(item => {
      const vegClass = item.food_type === 'veg' ? 'veg' : item.food_type === 'egg' ? 'egg' : 'non_veg';
      const imgHtml  = item.image
        ? `<img src="${escHtml(item.image)}" alt="${escHtml(item.name)}" style="width:90px;height:90px;object-fit:cover;border-radius:10px;flex-shrink:0"/>`
        : `<div style="width:90px;height:90px;border-radius:10px;background:#f5f5f5;display:flex;align-items:center;justify-content:center;font-size:32px;flex-shrink:0">🍽</div>`;
      const priceHtml = item.discount_price
        ? `<span style="text-decoration:line-through;color:#aaa;font-size:13px;margin-right:6px">₹${item.price}</span><span style="font-weight:700;color:#ff5722">₹${item.discount_price}</span>`
        : `<span style="font-weight:700;color:#ff5722">₹${item.price}</span>`;
      return `
        <div style="grid-column:1/-1;background:#fff;border-radius:14px;box-shadow:0 2px 10px rgba(0,0,0,.07);padding:16px;display:flex;gap:14px;align-items:center;cursor:pointer" onclick="openRestaurant(${item.restaurant_id})">
          <div style="flex:1;min-width:0">
            <div style="display:flex;align-items:center;gap:6px;margin-bottom:4px">
              <span class="veg-dot ${vegClass}"></span>
              <span style="font-weight:700;font-size:15px">${escHtml(item.name)}</span>
            </div>
            <div style="font-size:12px;color:#999;margin-bottom:6px">${escHtml(item.restaurant_name)}</div>
            ${item.description ? `<div style="font-size:12px;color:#777;margin-bottom:8px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${escHtml(item.description)}</div>` : ''}
            <div>${priceHtml}</div>
          </div>
          ${imgHtml}
        </div>`;
    }).join('');
  }

  // Restaurant results
  if (restaurants.length) {
    if (menuItems.length) {
      html += `<div style="grid-column:1/-1;font-size:13px;font-weight:700;color:#777;text-transform:uppercase;letter-spacing:.5px;margin:12px 0 4px">Restaurants</div>`;
    }
    html += restaurants.map(renderRestaurantCard).join('');
  }

  grid.innerHTML = html;
}

// ── Cart Drawer ───────────────────────────────────────────
function openCart() {
  if (!Auth.requireLogin()) return;
  window.location.href = 'pages/cart.html';
}

// ── Utility: HTML escape ──────────────────────────────────
function escHtml(str) {
  if (!str) return '';
  return String(str)
    .replace(/&/g,'&amp;')
    .replace(/</g,'&lt;')
    .replace(/>/g,'&gt;')
    .replace(/"/g,'&quot;')
    .replace(/'/g,'&#39;');
}

// ── Init ──────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  loadCities();
  loadRestaurants();
  loadTrending();

  // Search button
  document.getElementById('searchBtn')?.addEventListener('click', doSearch);
  document.getElementById('searchInput')?.addEventListener('keydown', e => {
    if (e.key === 'Enter') doSearch();
  });
  document.getElementById('searchInput')?.addEventListener('input', e => {
    if (!e.target.value.trim()) {
      const titleEl = document.querySelector('.section-title');
      if (titleEl) titleEl.textContent = 'All Restaurants';
      loadRestaurants();
    }
  });

  // City select change
  document.getElementById('citySelect')?.addEventListener('change', e => {
    CityManager.setCity(e.target.value);
    loadRestaurants();
  });

  // Cuisine chips
  document.querySelectorAll('.cuisine-chip').forEach(chip => {
    chip.addEventListener('click', () => {
      document.querySelectorAll('.cuisine-chip').forEach(c => c.classList.remove('active'));
      chip.classList.add('active');
      loadRestaurants();
    });
  });

  // Sort pills
  document.querySelectorAll('.sort-pill').forEach(pill => {
    pill.addEventListener('click', () => {
      document.querySelectorAll('.sort-pill').forEach(p => p.classList.remove('active'));
      pill.classList.add('active');
      document.getElementById('sortSelect').value = pill.dataset.val;
      loadRestaurants();
    });
  });

  // Load more
  document.getElementById('loadMoreBtn')?.addEventListener('click', () => {
    if (isLoadingMore || allLoaded) return;
    isLoadingMore = true;
    currentPage++;
    loadRestaurants(false).finally(() => { isLoadingMore = false; });
  });

  // Cart nav button
  document.getElementById('cartNavBtn')?.addEventListener('click', (e) => {
    e.preventDefault();
    openCart();
  });
});
