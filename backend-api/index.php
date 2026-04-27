<?php
/**
 * index.php — API Gateway (Single Entry Point)
 *
 * ALL API requests are routed through this file via .htaccess.
 *
 * Boot sequence:
 *  1. Load config (env, constants)
 *  2. CORS headers
 *  3. Autoload helpers
 *  4. Register routes
 *  5. Dispatch to controller
 */

declare(strict_types=1);

// ── 1. Bootstrap ─────────────────────────────────────────────────────────────
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/utils/Response.php';
require_once __DIR__ . '/utils/Validator.php';
require_once __DIR__ . '/utils/Helper.php';
require_once __DIR__ . '/Router.php';

// ── 2. CORS ───────────────────────────────────────────────────────────────────
require_once __DIR__ . '/middlewares/CORSMiddleware.php';
CORSMiddleware::handle();

// ── 3. Content-Type guard ─────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');

// ── 4. Routes ─────────────────────────────────────────────────────────────────
$router = new Router();

// ── Auth
$router->post('/register',          'AuthController@register');
$router->post('/login',             'AuthController@login');
$router->post('/logout',            'AuthController@logout');
$router->get('/me',                 'AuthController@me');
$router->put('/me',                 'AuthController@updateProfile');
$router->post('/verify-otp',        'AuthController@verifyOtp');
$router->post('/resend-otp',        'AuthController@resendOtp');
$router->post('/forgot-password',   'AuthController@forgotPassword');
$router->post('/reset-password',    'AuthController@resetPassword');

// ── Restaurants
$router->get('/restaurants',                    'RestaurantController@index');
$router->get('/restaurants/:id',               'RestaurantController@show');
$router->get('/restaurants/:id/owner',         'RestaurantController@showOwner');
$router->post('/restaurants',                   'RestaurantController@create');
$router->put('/restaurants/:id',               'RestaurantController@update');
$router->patch('/restaurants/:id/toggle',      'RestaurantController@toggleStatus');
$router->get('/restaurants/:id/stats',         'RestaurantController@stats');

// ── Menu (both path styles supported)
$router->get('/restaurants/:restaurant_id/menu',        'MenuController@index');
$router->get('/menu/:restaurant_id',                    'MenuController@index');
$router->get('/menu/:restaurant_id/item/:id',           'MenuController@show');
$router->post('/menu',                                  'MenuController@create');
$router->put('/menu/:id',                               'MenuController@update');
$router->patch('/menu/:id',                             'MenuController@update');
$router->delete('/menu/:id',                            'MenuController@delete');
$router->patch('/menu/:id/availability',                'MenuController@toggleAvailability');
$router->patch('/menu/:id/toggle',                      'MenuController@toggleAvailability');

// ── Item search
$router->get('/items/search',                           'MenuController@searchItems');

// ── Menu Categories (global — admin manages, restaurants read)
$router->get('/menu-categories',                        'MenuController@categories');
$router->post('/menu-categories',                       'MenuController@createCategory');
$router->delete('/menu-categories/:id',                 'MenuController@deleteCategory');

// ── Cart
$router->get('/cart',               'CartController@index');
$router->post('/cart/add',          'CartController@add');
$router->put('/cart/update',        'CartController@update');
$router->delete('/cart/remove/:id', 'CartController@remove');
$router->delete('/cart/clear',      'CartController@clear');

// ── Coupons
$router->post('/coupon/apply',              'CouponController@apply');
$router->post('/coupons/apply',             'CouponController@apply');
$router->get('/coupons',                    'CouponController@index');
$router->post('/coupons',                   'CouponController@create');
// Restaurant-owned coupons
$router->get('/restaurant/coupons',                  'CouponController@restaurantList');
$router->post('/restaurant/coupons',                 'CouponController@restaurantCreate');
$router->put('/restaurant/coupons/:id',              'CouponController@restaurantUpdate');
$router->patch('/restaurant/coupons/:id/toggle',     'CouponController@restaurantToggle');

// ── Orders (support both /order and /orders prefix)
$router->post('/orders',                   'OrderController@place');
$router->post('/order/place',              'OrderController@place');
$router->get('/orders',                    'OrderController@history');
$router->get('/orders/history',            'OrderController@history');
$router->get('/orders/:id',               'OrderController@show');
$router->get('/order/:id',                'OrderController@show');
$router->get('/orders/:id/track',         'OrderController@status');
$router->get('/order/:id/status',         'OrderController@status');
$router->post('/orders/:id/cancel',       'OrderController@cancel');
$router->post('/order/:id/cancel',        'OrderController@cancel');
$router->post('/orders/:id/reorder',      'OrderController@reorder');
$router->post('/order/:id/reorder',       'OrderController@reorder');
$router->get('/restaurant-orders',        'OrderController@restaurantOrders');
$router->patch('/orders/:id/status',      'OrderController@updateStatus');
$router->patch('/order/:id/status',       'OrderController@updateStatus');

// ── Payments
$router->post('/payments/initiate',       'PaymentController@initiate');
$router->post('/payment/initiate',        'PaymentController@initiate');
$router->post('/payments/verify',         'PaymentController@verify');
$router->post('/payment/verify',          'PaymentController@verify');
$router->post('/payments/webhook',        'PaymentController@webhook');
$router->post('/payment/webhook',         'PaymentController@webhook');
$router->get('/payments/:order_id',       'PaymentController@show');
$router->get('/payment/:order_id',        'PaymentController@show');

// ── Delivery (fixed order: specific paths before :param paths)
$router->post('/delivery/assign',             'DeliveryController@assign');
$router->get('/delivery/my-orders',           'DeliveryController@myOrders');
$router->post('/delivery/profile',            'DeliveryController@setupProfile');
$router->put('/delivery/profile',             'DeliveryController@updateProfile');
$router->get('/delivery/available-orders',    'DeliveryController@availableOrders');
$router->post('/delivery/accept',             'DeliveryController@acceptOrder');
$router->patch('/delivery/location',          'DeliveryController@updateLocation');
$router->patch('/delivery/availability',      'DeliveryController@toggleAvailability');
$router->patch('/delivery/:id/status',        'DeliveryController@updateStatus');
$router->get('/delivery/:order_id/track',     'DeliveryController@track');

// ── Recommendations
$router->get('/recommendations',          'RecommendationController@index');
$router->get('/recommendations/trending', 'RecommendationController@trending');

// ── Reviews
$router->post('/reviews',                        'ReviewController@create');
$router->post('/review',                         'ReviewController@create');
$router->get('/restaurants/:id/reviews',         'ReviewController@restaurantReviews');
$router->get('/reviews/:restaurant_id',          'ReviewController@restaurantReviews');

// ── Subscriptions
$router->get('/subscriptions/status',            'SubscriptionController@status');
$router->get('/subscription',                    'SubscriptionController@status');
$router->post('/subscriptions/restaurant',       'SubscriptionController@subscribeRestaurant');
$router->post('/subscription/restaurant',        'SubscriptionController@subscribeRestaurant');
$router->post('/subscriptions/customer',         'SubscriptionController@subscribeCustomer');
$router->post('/subscription/customer',          'SubscriptionController@subscribeCustomer');

// ── WhatsApp Ordering (Simulation)
$router->post('/whatsapp/order',          'WhatsAppController@simulateOrder');

// ── Notifications
$router->get('/notifications',            'NotificationController@index');
$router->patch('/notifications/read',     'NotificationController@markRead');

// ── Addresses
$router->get('/addresses',                'AddressController@index');
$router->post('/addresses',               'AddressController@create');
$router->put('/addresses/:id',            'AddressController@update');
$router->delete('/addresses/:id',         'AddressController@delete');
$router->patch('/addresses/:id/default',  'AddressController@setDefault');

// ── Admin routes
$router->get('/admin/dashboard',          'AdminController@dashboard');
$router->get('/admin/users',              'AdminController@users');
$router->post('/admin/users/:id/credit-wallet', 'AdminController@creditWallet');
$router->get('/admin/restaurants',              'AdminController@restaurants');
$router->get('/admin/restaurants/:id',          'AdminController@restaurantDetail');
$router->patch('/admin/restaurants/:id/approve',     'AdminController@approveRestaurant');
$router->patch('/admin/restaurants/:id/commission',  'AdminController@updateCommission');
$router->get('/admin/orders',             'AdminController@orders');
$router->get('/admin/earnings',           'AdminController@earnings');
$router->get('/admin/settlements',        'AdminController@settlements');
$router->post('/admin/settlements/generate',    'AdminController@generateSettlements');
$router->post('/admin/settlements/process/:id', 'AdminController@processSettlement');
$router->get('/admin/delivery-partners',          'AdminController@deliveryPartners');
$router->get('/admin/delivery-partners/:id',      'AdminController@deliveryPartnerDetail');
$router->patch('/admin/delivery-partners/:id/verify', 'AdminController@verifyPartner');
$router->get('/admin/settings',           'AdminController@getSettings');
$router->put('/admin/settings',           'AdminController@updateSettings');
$router->get('/admin/coupons',              'AdminController@listCoupons');
$router->post('/admin/coupons',             'AdminController@createCoupon');
$router->put('/admin/coupons/:id',          'AdminController@updateCoupon');
$router->patch('/admin/coupons/:id/toggle', 'AdminController@toggleCoupon');

// ── Cities (public read, admin write)
$router->get('/cities',           'AdminController@getCities');
$router->put('/admin/cities',     'AdminController@updateCities');

// ── Public settings (no auth — used by customer footer)
$router->get('/settings/public',  'AdminController@publicSettings');

// ── Wallet & Referral
$router->get('/wallet',               'WalletController@balance');
$router->get('/wallet/transactions',  'WalletController@transactions');
$router->get('/referral',             'WalletController@referral');
$router->post('/referral/apply',      'WalletController@applyReferral');
$router->get('/admin/wallet',         'WalletController@adminWallet');
$router->get('/admin/referrals',      'WalletController@adminReferrals');
$router->post('/admin/referrals/:id/approve', 'WalletController@approveReferral');

// ── Health check
$router->get('/health',   'HealthController@check');
$router->get('/',         'HealthController@check');

// ── 5. Dispatch ───────────────────────────────────────────────────────────────
$router->dispatch();
