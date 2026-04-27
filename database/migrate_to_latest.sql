-- ============================================================
-- AHARAM — Migration to Latest Schema
-- Run this on existing databases to bring them up to date.
-- Safe to run multiple times (uses IF NOT EXISTS checks).
-- ============================================================

USE `aharam_db`;

-- ── users table ───────────────────────────────────────────────
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `wallet_balance`  DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `fcm_token`,
  ADD COLUMN IF NOT EXISTS `referral_code`   VARCHAR(20)   DEFAULT NULL AFTER `wallet_balance`,
  ADD COLUMN IF NOT EXISTS `referred_by`     INT UNSIGNED  DEFAULT NULL AFTER `referral_code`,
  ADD COLUMN IF NOT EXISTS `last_login_at`   DATETIME      DEFAULT NULL AFTER `referred_by`;

ALTER TABLE `users`
  ADD UNIQUE KEY IF NOT EXISTS `uq_referral_code` (`referral_code`);

-- ── restaurants table ─────────────────────────────────────────
ALTER TABLE `restaurants`
  ADD COLUMN IF NOT EXISTS `approval_status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending' AFTER `fssai_number`;

-- ── orders table ──────────────────────────────────────────────
ALTER TABLE `orders`
  ADD COLUMN IF NOT EXISTS `wallet_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `payment_method`;

-- ── delivery_partners table ───────────────────────────────────
ALTER TABLE `delivery_partners`
  ADD COLUMN IF NOT EXISTS `verification_status` ENUM('pending','approved','suspended') NOT NULL DEFAULT 'pending' AFTER `is_verified`;

-- ── wallet_transactions table ─────────────────────────────────
CREATE TABLE IF NOT EXISTS `wallet_transactions` (
  `id`              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `user_id`         INT UNSIGNED    NOT NULL,
  `type`            ENUM('credit','debit') NOT NULL,
  `amount`          DECIMAL(10,2)   NOT NULL,
  `description`     VARCHAR(255)    NOT NULL,
  `reference_id`    INT UNSIGNED    DEFAULT NULL,
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

-- ── referrals table ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `referrals` (
  `id`              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `referrer_id`     INT UNSIGNED    NOT NULL,
  `referred_id`     INT UNSIGNED    NOT NULL,
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

-- ── system_settings — add missing keys ────────────────────────
INSERT IGNORE INTO `system_settings` (`key`, `value`, `description`) VALUES
('referral_enabled',        '1',               'Enable/disable referral programme'),
('referral_reward_amount',  '25',              'Wallet credit for both referrer and friend (INR)'),
('referral_min_order',      '50',              'Minimum first order amount to unlock referral reward'),
('referral_monthly_limit',  '10',              'Max referrals rewarded per user per month'),
('wallet_expiry_days',      '0',               '0 = wallet credits never expire'),
('gst_label',               'Other charges',   'Label shown to customers for GST/tax line'),
('support_phone',           '',                'Customer support phone number'),
('support_email',           '',                'Customer support email'),
('support_whatsapp',        '',                'Customer support WhatsApp number'),
('customer_plan_free_delivery_km', '0',        'Free delivery km for subscribed customers'),
('customer_plan_min_order',        '0',        'Min order for customer plan benefits'),
('customer_plan_benefits',         '',         'Customer plan benefit description');

SELECT 'Migration complete!' AS status;
