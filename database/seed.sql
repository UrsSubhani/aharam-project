-- ============================================================
-- AHARAM — SAMPLE SEED DATA
-- Run AFTER schema.sql
-- ============================================================
USE `aharam_db`;

-- ============================================================
-- USERS: 1 admin, 2 restaurant owners, 2 customers, 2 delivery partners
-- Password for all: Test@1234 → bcrypt hash below
-- ============================================================
INSERT INTO `users` (`name`, `email`, `phone`, `password_hash`, `role`, `is_active`, `email_verified`, `phone_verified`) VALUES
('Aharam Admin',        'admin@aharam.in',       '9000000001', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin',             1, 1, 1),
('Ravi Kitchen',        'ravi@kitchen.in',       '9000000002', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'restaurant_owner',  1, 1, 1),
('Priya Home Foods',    'priya@homefoods.in',    '9000000003', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'restaurant_owner',  1, 1, 1),
('Arun Kumar',          'arun@example.com',      '9000000004', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'customer',          1, 1, 1),
('Divya Priya',         'divya@example.com',     '9000000005', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'customer',          1, 1, 1),
('Rajesh (Rider)',      'rajesh@delivery.in',    '9000000006', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'delivery_partner',  1, 1, 1),
('Suresh (Rider)',      'suresh@delivery.in',    '9000000007', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'delivery_partner',  1, 1, 1);

-- ============================================================
-- RESTAURANTS
-- ============================================================
INSERT INTO `restaurants`
  (`owner_id`, `name`, `slug`, `description`, `cuisine_type`, `category`,
   `address`, `city`, `pincode`, `latitude`, `longitude`, `phone`,
   `is_active`, `commission_percent`, `avg_delivery_time`, `min_order_amount`)
VALUES
(2, 'Ravi South Kitchen',  'ravi-south-kitchen',  'Authentic South Indian home-style food', 'South Indian,Tamil', 'restaurant',
   '12, Anna Nagar, Chennai', 'Chennai', '600040', 13.0850, 80.2101, '9000000002', 1, 20.00, 30, 100.00),
(3, 'Priya Home Kitchen',  'priya-home-kitchen',  'Healthy home-cooked meals by Priya',     'North Indian,Tiffins', 'home_kitchen',
   '45, Velachery Main Road, Chennai', 'Chennai', '600042', 12.9780, 80.2209, '9000000003', 1, 15.00, 45, 150.00);

-- ============================================================
-- RESTAURANT SUBSCRIPTION (Priya has a subscription)
-- ============================================================
INSERT INTO `restaurant_subscriptions`
  (`restaurant_id`, `plan_name`, `plan_amount`, `commission_percent`, `starts_at`, `expires_at`, `is_active`)
VALUES
(2, 'Pro', 999.00, 8.00, '2026-04-01', '2026-04-30', 1);

-- ============================================================
-- DELIVERY PARTNERS
-- ============================================================
INSERT INTO `delivery_partners` (`user_id`, `vehicle_type`, `vehicle_number`, `is_verified`, `is_available`, `city`)
VALUES
(6, 'motorbike', 'TN09AB1234', 1, 1, 'Chennai'),
(7, 'scooter',   'TN09CD5678', 1, 0, 'Chennai');

-- ============================================================
-- MENU CATEGORIES
-- ============================================================
INSERT INTO `menu_categories` (`restaurant_id`, `name`, `sort_order`) VALUES
(1, 'Breakfast',    1),
(1, 'Lunch',        2),
(1, 'Dinner',       3),
(1, 'Beverages',    4),
(2, 'Tiffins',      1),
(2, 'Rice Meals',   2),
(2, 'Specials',     3);

-- ============================================================
-- MENU ITEMS — Restaurant 1 (Ravi South Kitchen)
-- ============================================================
INSERT INTO `menu_items`
  (`restaurant_id`, `category_id`, `name`, `description`, `price`, `food_type`, `is_available`, `is_bestseller`, `total_orders`)
VALUES
(1, 1, 'Idli (2 pcs)',       'Soft steamed idli with sambar and chutney',    40.00,  'veg', 1, 1, 450),
(1, 1, 'Dosa',               'Crispy plain dosa with sambar and chutney',    50.00,  'veg', 1, 1, 380),
(1, 1, 'Pongal',             'Creamy rice and lentil breakfast',             60.00,  'veg', 1, 0, 200),
(1, 1, 'Upma',               'Semolina cooked with vegetables',              45.00,  'veg', 1, 0, 180),
(1, 2, 'Meals (Full)',       'Full rice meals with rasam, sambar, 3 curries',120.00, 'veg', 1, 1, 620),
(1, 2, 'Curd Rice',          'Tempered curd rice with pickle',               70.00,  'veg', 1, 0, 310),
(1, 3, 'Parotta + Kurma',    '3 parotta with vegetable kurma',               90.00,  'veg', 1, 1, 400),
(1, 3, 'Egg Parotta',        '2 parotta with egg bhurji',                    80.00,  'egg', 1, 0, 250),
(1, 4, 'Filter Coffee',      'Traditional South Indian filter coffee',       25.00,  'veg', 1, 1, 900),
(1, 4, 'Masala Tea',         'Ginger cardamom tea',                          20.00,  'veg', 1, 0, 400);

-- ============================================================
-- MENU ITEMS — Restaurant 2 (Priya Home Kitchen)
-- ============================================================
INSERT INTO `menu_items`
  (`restaurant_id`, `category_id`, `name`, `description`, `price`, `food_type`, `is_available`, `is_bestseller`, `total_orders`)
VALUES
(2, 5, 'Poha',              'Light flattened rice with onion, peanuts',      35.00, 'veg', 1, 1, 300),
(2, 5, 'Bread Omelette',    '2 egg omelette with 2 bread slices',            55.00, 'egg', 1, 0, 220),
(2, 6, 'Dal Rice',          'Yellow dal with steamed rice and salad',        90.00, 'veg', 1, 1, 500),
(2, 6, 'Rajma Rice',        'Kidney bean curry with rice',                  100.00, 'veg', 1, 1, 420),
(2, 7, 'Paneer Butter Masala + Roti', 'Rich paneer gravy with 3 rotis',    150.00, 'veg', 1, 1, 380),
(2, 7, 'Chicken Curry + Rice',        'Home-style chicken curry with rice', 160.00, 'non_veg', 1, 0, 290);

-- ============================================================
-- COUPONS
-- ============================================================
INSERT INTO `coupons`
  (`code`, `description`, `discount_type`, `discount_value`, `min_order_amount`, `max_discount_amount`, `valid_from`, `valid_until`)
VALUES
('FIRST50',   'Flat ₹50 off on first order',         'flat',    50.00,  199.00, NULL,  '2026-01-01', '2026-12-31'),
('WELCOME20', '20% off up to ₹100 on welcome',       'percent', 20.00,  249.00, 100.00,'2026-01-01', '2026-12-31'),
('AHARAM10',  '10% off sitewide - no minimum',       'percent', 10.00,    0.00,  80.00,'2026-01-01', '2026-12-31');

-- ============================================================
-- SAMPLE ADDRESSES for customers
-- ============================================================
INSERT INTO `user_addresses` (`user_id`, `label`, `address`, `city`, `pincode`, `latitude`, `longitude`, `is_default`)
VALUES
(4, 'Home', '23 Gandhi Street, Anna Nagar',    'Chennai', '600040', 13.0860, 80.2110, 1),
(5, 'Home', '7 Lake View Road, Velachery',     'Chennai', '600042', 12.9790, 80.2220, 1),
(5, 'Work', '100 IT Expressway, Sholinganallur','Chennai', '600119', 12.9010, 80.2280, 0);
