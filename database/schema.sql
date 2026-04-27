-- ============================================================
-- AHARAM FOOD DELIVERY PLATFORM — DATABASE SCHEMA
-- Compatible with MySQL 5.7+ / MariaDB 10.3+
-- ============================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+05:30";

CREATE DATABASE IF NOT EXISTS `aharam_db`
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
USE `aharam_db`;

-- ============================================================
-- TABLE: users
-- Stores customers, restaurant owners, delivery partners, admins
-- role: customer | restaurant_owner | delivery_partner | admin
-- ============================================================
CREATE TABLE `users` (
  `id`                  INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `name`                VARCHAR(120)    NOT NULL,
  `email`               VARCHAR(180)    NOT NULL,
  `phone`               VARCHAR(20)     NOT NULL,
  `password_hash`       VARCHAR(255)    NOT NULL,
  `role`                ENUM('customer','restaurant_owner','delivery_partner','admin') NOT NULL DEFAULT 'customer',
  `profile_image`       VARCHAR(255)    DEFAULT NULL,
  `is_active`           TINYINT(1)      NOT NULL DEFAULT 1,
  `email_verified`      TINYINT(1)      NOT NULL DEFAULT 0,
  `phone_verified`      TINYINT(1)      NOT NULL DEFAULT 0,
  `otp_code`            VARCHAR(10)     DEFAULT NULL,
  `otp_expires_at`      DATETIME        DEFAULT NULL,
  `last_login`          DATETIME        DEFAULT NULL,
  `fcm_token`           VARCHAR(255)    DEFAULT NULL COMMENT 'Firebase push token for mobile',
  `wallet_balance`      DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
  `referral_code`       VARCHAR(20)     DEFAULT NULL,
  `referred_by`         INT UNSIGNED    DEFAULT NULL COMMENT 'FK to users.id',
  `last_login_at`       DATETIME        DEFAULT NULL,
  `created_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_email` (`email`),
  UNIQUE KEY `uq_phone` (`phone`),
  UNIQUE KEY `uq_referral_code` (`referral_code`),
  KEY `idx_role` (`role`),
  KEY `idx_active` (`is_active`),
  CONSTRAINT `fk_user_referrer` FOREIGN KEY (`referred_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: user_addresses
-- Multiple saved addresses per customer
-- ============================================================
CREATE TABLE `user_addresses` (
  `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED  NOT NULL,
  `label`       VARCHAR(50)   NOT NULL DEFAULT 'Home' COMMENT 'Home / Work / Other',
  `address`     TEXT          NOT NULL,
  `city`        VARCHAR(100)  NOT NULL,
  `pincode`     VARCHAR(10)   NOT NULL,
  `latitude`    DECIMAL(10,7) DEFAULT NULL,
  `longitude`   DECIMAL(10,7) DEFAULT NULL,
  `is_default`  TINYINT(1)    NOT NULL DEFAULT 0,
  `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  CONSTRAINT `fk_addr_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: restaurants
-- Registered restaurants / home kitchens / street vendors
-- ============================================================
CREATE TABLE `restaurants` (
  `id`                  INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `owner_id`            INT UNSIGNED    NOT NULL COMMENT 'FK to users.id (role=restaurant_owner)',
  `name`                VARCHAR(150)    NOT NULL,
  `slug`                VARCHAR(160)    NOT NULL COMMENT 'URL-friendly name',
  `description`         TEXT            DEFAULT NULL,
  `cuisine_type`        VARCHAR(200)    DEFAULT NULL COMMENT 'Comma-separated: Indian,Chinese',
  `category`            ENUM('restaurant','home_kitchen','street_vendor','cloud_kitchen') NOT NULL DEFAULT 'restaurant',
  `logo_image`          VARCHAR(255)    DEFAULT NULL,
  `cover_image`         VARCHAR(255)    DEFAULT NULL,
  `address`             TEXT            NOT NULL,
  `city`                VARCHAR(100)    NOT NULL,
  `pincode`             VARCHAR(10)     NOT NULL,
  `latitude`            DECIMAL(10,7)   DEFAULT NULL,
  `longitude`           DECIMAL(10,7)   DEFAULT NULL,
  `phone`               VARCHAR(20)     NOT NULL,
  `email`               VARCHAR(180)    DEFAULT NULL,
  `gstin`               VARCHAR(20)     DEFAULT NULL,
  `fssai_number`        VARCHAR(30)     DEFAULT NULL,
  `opening_time`        TIME            NOT NULL DEFAULT '08:00:00',
  `closing_time`        TIME            NOT NULL DEFAULT '22:00:00',
  `approval_status`     ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `is_open`             TINYINT(1)      NOT NULL DEFAULT 0 COMMENT 'Manual open/close toggle',
  `is_active`           TINYINT(1)      NOT NULL DEFAULT 0 COMMENT 'Admin approved',
  `is_featured`         TINYINT(1)      NOT NULL DEFAULT 0,
  `commission_percent`  DECIMAL(5,2)    NOT NULL DEFAULT 20.00 COMMENT 'Standard commission %',
  `avg_delivery_time`   INT             NOT NULL DEFAULT 30 COMMENT 'Minutes',
  `min_order_amount`    DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
  `avg_rating`          DECIMAL(3,2)    NOT NULL DEFAULT 0.00,
  `total_ratings`       INT             NOT NULL DEFAULT 0,
  `total_orders`        INT             NOT NULL DEFAULT 0,
  `whatsapp_number`     VARCHAR(20)     DEFAULT NULL COMMENT 'For WhatsApp ordering',
  `bank_account`        VARCHAR(20)     DEFAULT NULL,
  `ifsc_code`           VARCHAR(15)     DEFAULT NULL,
  `bank_holder_name`    VARCHAR(120)    DEFAULT NULL,
  `created_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_slug` (`slug`),
  KEY `idx_city` (`city`),
  KEY `idx_pincode` (`pincode`),
  KEY `idx_active` (`is_active`),
  KEY `idx_owner` (`owner_id`),
  CONSTRAINT `fk_rest_owner` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: restaurant_subscriptions
-- Monthly subscription for reduced commission rates
-- ============================================================
CREATE TABLE `restaurant_subscriptions` (
  `id`                  INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `restaurant_id`       INT UNSIGNED    NOT NULL,
  `plan_name`           VARCHAR(80)     NOT NULL COMMENT 'Basic/Pro/Premium',
  `plan_amount`         DECIMAL(10,2)   NOT NULL COMMENT 'Monthly fee in INR',
  `commission_percent`  DECIMAL(5,2)    NOT NULL COMMENT 'Reduced commission while subscribed',
  `billing_cycle`       ENUM('monthly','quarterly','yearly') NOT NULL DEFAULT 'monthly',
  `starts_at`           DATE            NOT NULL,
  `expires_at`          DATE            NOT NULL,
  `is_active`           TINYINT(1)      NOT NULL DEFAULT 1,
  `payment_reference`   VARCHAR(100)    DEFAULT NULL,
  `auto_renew`          TINYINT(1)      NOT NULL DEFAULT 0,
  `created_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_restaurant` (`restaurant_id`),
  KEY `idx_active_expiry` (`is_active`, `expires_at`),
  CONSTRAINT `fk_sub_restaurant` FOREIGN KEY (`restaurant_id`) REFERENCES `restaurants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: customer_subscriptions
-- Customer plan: ₹99/month → free delivery + discounts
-- ============================================================
CREATE TABLE `customer_subscriptions` (
  `id`                  INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `user_id`             INT UNSIGNED    NOT NULL,
  `plan_name`           VARCHAR(80)     NOT NULL DEFAULT 'Aharam Plus',
  `plan_amount`         DECIMAL(10,2)   NOT NULL DEFAULT 99.00,
  `free_delivery`       TINYINT(1)      NOT NULL DEFAULT 1,
  `discount_percent`    DECIMAL(5,2)    NOT NULL DEFAULT 10.00,
  `starts_at`           DATE            NOT NULL,
  `expires_at`          DATE            NOT NULL,
  `is_active`           TINYINT(1)      NOT NULL DEFAULT 1,
  `payment_reference`   VARCHAR(100)    DEFAULT NULL,
  `created_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_active_expiry` (`is_active`, `expires_at`),
  CONSTRAINT `fk_csub_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: menu_categories
-- Categories within a restaurant menu (Starters, Mains, etc.)
-- ============================================================
CREATE TABLE `menu_categories` (
  `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `restaurant_id`   INT UNSIGNED  NOT NULL,
  `name`            VARCHAR(100)  NOT NULL,
  `sort_order`      INT           NOT NULL DEFAULT 0,
  `is_active`       TINYINT(1)    NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_restaurant` (`restaurant_id`),
  CONSTRAINT `fk_mcat_restaurant` FOREIGN KEY (`restaurant_id`) REFERENCES `restaurants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: menu_items
-- Individual dishes in the restaurant menu
-- ============================================================
CREATE TABLE `menu_items` (
  `id`              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `restaurant_id`   INT UNSIGNED    NOT NULL,
  `category_id`     INT UNSIGNED    DEFAULT NULL,
  `name`            VARCHAR(150)    NOT NULL,
  `description`     TEXT            DEFAULT NULL,
  `price`           DECIMAL(10,2)   NOT NULL,
  `discount_price`  DECIMAL(10,2)   DEFAULT NULL COMMENT 'Strike-through price if discounted',
  `image`           VARCHAR(255)    DEFAULT NULL,
  `food_type`       ENUM('veg','non_veg','egg','vegan') NOT NULL DEFAULT 'veg',
  `is_available`    TINYINT(1)      NOT NULL DEFAULT 1,
  `is_featured`     TINYINT(1)      NOT NULL DEFAULT 0,
  `is_bestseller`   TINYINT(1)      NOT NULL DEFAULT 0,
  `tags`            VARCHAR(255)    DEFAULT NULL COMMENT 'spicy,must_try,new',
  `calories`        INT             DEFAULT NULL,
  `prep_time`       INT             DEFAULT NULL COMMENT 'Minutes',
  `sort_order`      INT             NOT NULL DEFAULT 0,
  `total_orders`    INT             NOT NULL DEFAULT 0 COMMENT 'For popularity ranking',
  `created_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_restaurant` (`restaurant_id`),
  KEY `idx_category` (`category_id`),
  KEY `idx_available` (`is_available`),
  KEY `idx_featured` (`is_featured`),
  CONSTRAINT `fk_menu_restaurant` FOREIGN KEY (`restaurant_id`) REFERENCES `restaurants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_menu_category` FOREIGN KEY (`category_id`) REFERENCES `menu_categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: coupons
-- Platform-wide and restaurant-specific coupons
-- ============================================================
CREATE TABLE `coupons` (
  `id`                  INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `restaurant_id`       INT UNSIGNED    DEFAULT NULL COMMENT 'NULL = platform-wide',
  `code`                VARCHAR(30)     NOT NULL,
  `description`         VARCHAR(255)    DEFAULT NULL,
  `discount_type`       ENUM('percent','flat') NOT NULL,
  `discount_value`      DECIMAL(10,2)   NOT NULL,
  `min_order_amount`    DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
  `max_discount_amount` DECIMAL(10,2)   DEFAULT NULL COMMENT 'Cap for percent coupons',
  `usage_limit`         INT             DEFAULT NULL COMMENT 'NULL = unlimited',
  `per_user_limit`      INT             NOT NULL DEFAULT 1,
  `used_count`          INT             NOT NULL DEFAULT 0,
  `valid_from`          DATE            NOT NULL,
  `valid_until`         DATE            NOT NULL,
  `is_active`           TINYINT(1)      NOT NULL DEFAULT 1,
  `created_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_code` (`code`),
  KEY `idx_restaurant` (`restaurant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: orders
-- Central orders table
-- status flow: pending → confirmed → preparing → ready → picked → on_the_way → delivered | cancelled
-- ============================================================
CREATE TABLE `orders` (
  `id`                      INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `order_number`            VARCHAR(20)     NOT NULL COMMENT 'AHR-YYYYMMDD-XXXX',
  `user_id`                 INT UNSIGNED    NOT NULL,
  `restaurant_id`           INT UNSIGNED    NOT NULL,
  `delivery_address_id`     INT UNSIGNED    DEFAULT NULL,
  `delivery_address_text`   TEXT            NOT NULL COMMENT 'Snapshot at order time',
  `delivery_lat`            DECIMAL(10,7)   DEFAULT NULL,
  `delivery_lng`            DECIMAL(10,7)   DEFAULT NULL,
  `status`                  ENUM('pending','confirmed','preparing','ready','picked','on_the_way','delivered','cancelled','refunded') NOT NULL DEFAULT 'pending',
  `order_type`              ENUM('delivery','pickup','whatsapp') NOT NULL DEFAULT 'delivery',
  -- Pricing Breakdown
  `food_total`              DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
  `discount_amount`         DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
  `coupon_code`             VARCHAR(30)     DEFAULT NULL,
  `coupon_discount`         DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
  `delivery_fee`            DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
  `platform_fee`            DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
  `gst_amount`              DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
  `total_amount`            DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
  -- Commission Tracking
  `commission_percent`      DECIMAL(5,2)    NOT NULL DEFAULT 20.00,
  `commission_amount`       DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
  `restaurant_payout`       DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
  `subscription_applied`    TINYINT(1)      NOT NULL DEFAULT 0,
  -- Payment
  `payment_method`          ENUM('online','cod','wallet') NOT NULL DEFAULT 'online',
  `wallet_amount`           DECIMAL(10,2)   NOT NULL DEFAULT 0.00 COMMENT 'Amount paid via wallet',
  `payment_status`          ENUM('pending','paid','failed','refunded') NOT NULL DEFAULT 'pending',
  -- Notes
  `special_instructions`    TEXT            DEFAULT NULL,
  `cancellation_reason`     VARCHAR(255)    DEFAULT NULL,
  `cancelled_by`            ENUM('customer','restaurant','admin','system') DEFAULT NULL,
  -- Timestamps
  `confirmed_at`            DATETIME        DEFAULT NULL,
  `preparing_at`            DATETIME        DEFAULT NULL,
  `ready_at`                DATETIME        DEFAULT NULL,
  `picked_at`               DATETIME        DEFAULT NULL,
  `delivered_at`            DATETIME        DEFAULT NULL,
  `cancelled_at`            DATETIME        DEFAULT NULL,
  `created_at`              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_order_number` (`order_number`),
  KEY `idx_user` (`user_id`),
  KEY `idx_restaurant` (`restaurant_id`),
  KEY `idx_status` (`status`),
  KEY `idx_payment_status` (`payment_status`),
  KEY `idx_created` (`created_at`),
  CONSTRAINT `fk_order_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_order_restaurant` FOREIGN KEY (`restaurant_id`) REFERENCES `restaurants` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: order_items
-- Line items in each order (snapshot of price at order time)
-- ============================================================
CREATE TABLE `order_items` (
  `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `order_id`      INT UNSIGNED    NOT NULL,
  `menu_item_id`  INT UNSIGNED    NOT NULL,
  `name`          VARCHAR(150)    NOT NULL COMMENT 'Snapshot',
  `price`         DECIMAL(10,2)   NOT NULL COMMENT 'Price at order time',
  `quantity`      INT             NOT NULL DEFAULT 1,
  `subtotal`      DECIMAL(10,2)   NOT NULL,
  `notes`         VARCHAR(255)    DEFAULT NULL COMMENT 'e.g., extra spicy',
  PRIMARY KEY (`id`),
  KEY `idx_order` (`order_id`),
  KEY `idx_menu_item` (`menu_item_id`),
  CONSTRAINT `fk_oi_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_oi_menu_item` FOREIGN KEY (`menu_item_id`) REFERENCES `menu_items` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: payments
-- Payment records linked to orders
-- ============================================================
CREATE TABLE `payments` (
  `id`                      INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `order_id`                INT UNSIGNED    NOT NULL,
  `user_id`                 INT UNSIGNED    NOT NULL,
  `amount`                  DECIMAL(10,2)   NOT NULL,
  `currency`                VARCHAR(5)      NOT NULL DEFAULT 'INR',
  `method`                  ENUM('razorpay','cod','wallet','upi','card','netbanking') NOT NULL,
  `status`                  ENUM('initiated','pending','success','failed','refunded') NOT NULL DEFAULT 'initiated',
  `razorpay_order_id`       VARCHAR(100)    DEFAULT NULL,
  `razorpay_payment_id`     VARCHAR(100)    DEFAULT NULL,
  `razorpay_signature`      VARCHAR(255)    DEFAULT NULL,
  `gateway_response`        JSON            DEFAULT NULL,
  `refund_id`               VARCHAR(100)    DEFAULT NULL,
  `refund_amount`           DECIMAL(10,2)   DEFAULT NULL,
  `refund_at`               DATETIME        DEFAULT NULL,
  `created_at`              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_order` (`order_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_status` (`status`),
  KEY `idx_razorpay_order` (`razorpay_order_id`),
  CONSTRAINT `fk_pay_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`),
  CONSTRAINT `fk_pay_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: delivery_partners
-- Delivery partner profile (extends users)
-- ============================================================
CREATE TABLE `delivery_partners` (
  `id`                  INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `user_id`             INT UNSIGNED    NOT NULL,
  `vehicle_type`        ENUM('bicycle','motorbike','scooter','car') NOT NULL DEFAULT 'motorbike',
  `vehicle_number`      VARCHAR(20)     DEFAULT NULL,
  `license_number`      VARCHAR(30)     DEFAULT NULL,
  `aadhar_number`       VARCHAR(20)     DEFAULT NULL,
  `is_verified`         TINYINT(1)      NOT NULL DEFAULT 0,
  `verification_status` ENUM('pending','approved','suspended') NOT NULL DEFAULT 'pending',
  `is_available`        TINYINT(1)      NOT NULL DEFAULT 0 COMMENT 'Online/offline toggle',
  `current_lat`         DECIMAL(10,7)   DEFAULT NULL,
  `current_lng`         DECIMAL(10,7)   DEFAULT NULL,
  `location_updated_at` DATETIME        DEFAULT NULL,
  `city`                VARCHAR(100)    DEFAULT NULL,
  `total_deliveries`    INT             NOT NULL DEFAULT 0,
  `total_earnings`      DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
  `avg_rating`          DECIMAL(3,2)    NOT NULL DEFAULT 0.00,
  `bank_account`        VARCHAR(20)     DEFAULT NULL,
  `ifsc_code`           VARCHAR(15)     DEFAULT NULL,
  `created_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user` (`user_id`),
  KEY `idx_available` (`is_available`),
  KEY `idx_city` (`city`),
  CONSTRAINT `fk_dp_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: delivery_orders
-- Assignment of a delivery partner to an order
-- ============================================================
CREATE TABLE `delivery_orders` (
  `id`                  INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `order_id`            INT UNSIGNED    NOT NULL,
  `partner_id`          INT UNSIGNED    NOT NULL COMMENT 'FK to delivery_partners.id',
  `status`              ENUM('assigned','accepted','picked','on_the_way','delivered','cancelled') NOT NULL DEFAULT 'assigned',
  `pickup_otp`          VARCHAR(6)      DEFAULT NULL COMMENT 'OTP to confirm pickup',
  `delivery_otp`        VARCHAR(6)      DEFAULT NULL COMMENT 'OTP to confirm delivery',
  `distance_km`         DECIMAL(8,2)    DEFAULT NULL,
  `delivery_fee`        DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
  `partner_earnings`    DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
  `accepted_at`         DATETIME        DEFAULT NULL,
  `picked_at`           DATETIME        DEFAULT NULL,
  `delivered_at`        DATETIME        DEFAULT NULL,
  `partner_rating`      TINYINT         DEFAULT NULL COMMENT '1-5',
  `partner_feedback`    VARCHAR(255)    DEFAULT NULL,
  `created_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_order` (`order_id`),
  KEY `idx_partner` (`partner_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_do_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`),
  CONSTRAINT `fk_do_partner` FOREIGN KEY (`partner_id`) REFERENCES `delivery_partners` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: settlements
-- Daily settlement records for restaurants
-- ============================================================
CREATE TABLE `settlements` (
  `id`                  INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `restaurant_id`       INT UNSIGNED    NOT NULL,
  `period_from`         DATE            NOT NULL,
  `period_to`           DATE            NOT NULL,
  `total_orders`        INT             NOT NULL DEFAULT 0,
  `gross_amount`        DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
  `commission_amount`   DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
  `platform_fees`       DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
  `tax_deducted`        DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
  `net_payout`          DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
  `status`              ENUM('pending','processed','paid','failed') NOT NULL DEFAULT 'pending',
  `payment_reference`   VARCHAR(100)    DEFAULT NULL,
  `paid_at`             DATETIME        DEFAULT NULL,
  `notes`               TEXT            DEFAULT NULL,
  `created_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_restaurant` (`restaurant_id`),
  KEY `idx_status` (`status`),
  KEY `idx_period` (`period_from`, `period_to`),
  CONSTRAINT `fk_settle_restaurant` FOREIGN KEY (`restaurant_id`) REFERENCES `restaurants` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: delivery_earnings
-- Per-delivery earnings for delivery partners
-- ============================================================
CREATE TABLE `delivery_earnings` (
  `id`                  INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `partner_id`          INT UNSIGNED    NOT NULL,
  `delivery_order_id`   INT UNSIGNED    NOT NULL,
  `order_id`            INT UNSIGNED    NOT NULL,
  `base_pay`            DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
  `distance_pay`        DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
  `tip_amount`          DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
  `total_earnings`      DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
  `is_settled`          TINYINT(1)      NOT NULL DEFAULT 0,
  `settled_at`          DATETIME        DEFAULT NULL,
  `created_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_partner` (`partner_id`),
  KEY `idx_delivery_order` (`delivery_order_id`),
  CONSTRAINT `fk_de_partner` FOREIGN KEY (`partner_id`) REFERENCES `delivery_partners` (`id`),
  CONSTRAINT `fk_de_delivery_order` FOREIGN KEY (`delivery_order_id`) REFERENCES `delivery_orders` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: platform_earnings
-- Platform revenue tracking
-- ============================================================
CREATE TABLE `platform_earnings` (
  `id`                  INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `order_id`            INT UNSIGNED    NOT NULL,
  `commission_amount`   DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
  `delivery_fee_share`  DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
  `platform_fee`        DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
  `total_revenue`       DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
  `created_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_order` (`order_id`),
  CONSTRAINT `fk_pe_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: reviews
-- Customer reviews for restaurants and delivery partners
-- ============================================================
CREATE TABLE `reviews` (
  `id`              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `order_id`        INT UNSIGNED    NOT NULL,
  `user_id`         INT UNSIGNED    NOT NULL,
  `restaurant_id`   INT UNSIGNED    NOT NULL,
  `partner_id`      INT UNSIGNED    DEFAULT NULL,
  `food_rating`     TINYINT         NOT NULL DEFAULT 5 COMMENT '1-5',
  `delivery_rating` TINYINT         DEFAULT NULL COMMENT '1-5',
  `review_text`     TEXT            DEFAULT NULL,
  `created_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_order_review` (`order_id`),
  KEY `idx_restaurant` (`restaurant_id`),
  KEY `idx_user` (`user_id`),
  CONSTRAINT `fk_rev_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`),
  CONSTRAINT `fk_rev_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_rev_restaurant` FOREIGN KEY (`restaurant_id`) REFERENCES `restaurants` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: cart_sessions
-- Server-side cart for authenticated users
-- ============================================================
CREATE TABLE `cart_sessions` (
  `id`              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `user_id`         INT UNSIGNED    NOT NULL,
  `restaurant_id`   INT UNSIGNED    NOT NULL,
  `menu_item_id`    INT UNSIGNED    NOT NULL,
  `quantity`        INT             NOT NULL DEFAULT 1,
  `notes`           VARCHAR(255)    DEFAULT NULL,
  `created_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_item` (`user_id`, `menu_item_id`),
  KEY `idx_user` (`user_id`),
  CONSTRAINT `fk_cart_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cart_item` FOREIGN KEY (`menu_item_id`) REFERENCES `menu_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: whatsapp_orders
-- WhatsApp ordering simulation (mock)
-- ============================================================
CREATE TABLE `whatsapp_orders` (
  `id`              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `phone`           VARCHAR(20)     NOT NULL,
  `restaurant_id`   INT UNSIGNED    DEFAULT NULL,
  `message`         TEXT            NOT NULL,
  `parsed_items`    JSON            DEFAULT NULL COMMENT 'AI-parsed order items',
  `status`          ENUM('received','parsed','confirmed','linked_to_order') NOT NULL DEFAULT 'received',
  `order_id`        INT UNSIGNED    DEFAULT NULL,
  `created_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_phone` (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: notifications
-- In-app notifications for all user types
-- ============================================================
CREATE TABLE `notifications` (
  `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED    NOT NULL,
  `type`        VARCHAR(50)     NOT NULL COMMENT 'order_confirmed, delivery_assigned, etc.',
  `title`       VARCHAR(150)    NOT NULL,
  `message`     TEXT            NOT NULL,
  `data`        JSON            DEFAULT NULL,
  `is_read`     TINYINT(1)      NOT NULL DEFAULT 0,
  `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_read` (`is_read`),
  CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: system_settings
-- Platform-wide configurable settings
-- ============================================================
CREATE TABLE `system_settings` (
  `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `key`         VARCHAR(100)    NOT NULL,
  `value`       TEXT            NOT NULL,
  `description` VARCHAR(255)    DEFAULT NULL,
  `updated_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_key` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: wallet_transactions
-- Credit/debit history for user wallets
-- ============================================================
CREATE TABLE `wallet_transactions` (
  `id`              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `user_id`         INT UNSIGNED    NOT NULL,
  `type`            ENUM('credit','debit') NOT NULL,
  `amount`          DECIMAL(10,2)   NOT NULL,
  `description`     VARCHAR(255)    NOT NULL,
  `reference_id`    INT UNSIGNED    DEFAULT NULL COMMENT 'order_id or referral_id',
  `balance_before`  DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
  `balance_after`   DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
  `expires_at`      DATETIME        DEFAULT NULL,
  `created_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_type` (`type`),
  KEY `idx_created` (`created_at`),
  CONSTRAINT `fk_wt_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: referrals
-- Tracks referral relationships and reward status
-- ============================================================
CREATE TABLE `referrals` (
  `id`              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `referrer_id`     INT UNSIGNED    NOT NULL COMMENT 'User who shared the code',
  `referred_id`     INT UNSIGNED    NOT NULL COMMENT 'New user who used the code',
  `status`          ENUM('pending','completed','expired') NOT NULL DEFAULT 'pending',
  `reward_given`    TINYINT(1)      NOT NULL DEFAULT 0,
  `reward_amount`   DECIMAL(10,2)   DEFAULT NULL,
  `rewarded_at`     DATETIME        DEFAULT NULL,
  `created_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_referred` (`referred_id`),
  KEY `idx_referrer` (`referrer_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_ref_referrer` FOREIGN KEY (`referrer_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_ref_referred` FOREIGN KEY (`referred_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- SEED: System Settings
-- ============================================================
INSERT INTO `system_settings` (`key`, `value`, `description`) VALUES
('platform_name',           'Aharam',                     'Brand name'),
('default_commission',      '20',                         'Default commission % for restaurants'),
('subscription_commission', '8',                          'Commission % for subscribed restaurants'),
('restaurant_sub_price',    '999',                        'Monthly restaurant subscription price (INR)'),
('customer_sub_price',      '99',                         'Monthly customer subscription price (INR)'),
('base_delivery_fee',       '30',                         'Base delivery fee (INR)'),
('per_km_charge',           '5',                          'Per KM delivery charge (INR)'),
('platform_fee',            '5',                          'Flat platform fee per order (INR)'),
('gst_percent',             '5',                          'GST percentage on food'),
('free_delivery_above',     '299',                        'Free delivery if order above this amount'),
('auto_cancel_minutes',     '30',                         'Auto-cancel unpaid orders after N minutes'),
('razorpay_key_id',         'rzp_test_XXXXXXXXXX',        'Razorpay Key ID'),
('razorpay_key_secret',     'XXXXXXXXXXXXXXXXXXXXXX',     'Razorpay Key Secret (encrypted in prod)'),
('whatsapp_api_url',        'https://api.whatsapp.com',   'WhatsApp Business API URL'),
('max_cart_items',          '20',                         'Maximum items allowed in cart'),
('order_radius_km',         '10',                         'Max delivery radius in KM'),
('rider_base_pay',          '25',                         'Base pay per delivery to rider (INR)'),
('rider_free_km',           '2',                          'KM included free in base pay'),
('rider_per_km_pay',        '3',                          'Extra pay per KM beyond free km (INR)'),
('referral_enabled',        '1',                          'Enable/disable referral programme'),
('referral_reward_amount',  '25',                         'Wallet credit for both referrer and friend (INR)'),
('referral_min_order',      '50',                         'Minimum first order amount to unlock referral reward'),
('referral_monthly_limit',  '10',                         'Max referrals rewarded per user per month'),
('wallet_expiry_days',      '0',                          '0 = wallet credits never expire'),
('gst_label',               'Other charges',              'Label shown to customers for GST/tax line'),
('support_phone',           '',                           'Customer support phone number'),
('support_email',           '',                           'Customer support email'),
('support_whatsapp',        '',                           'Customer support WhatsApp number');

COMMIT;
