<?php
/**
 * migrate_wallet.php — Aharam Wallet + Referral System DB migration
 * Run once: http://localhost/aharam/backend-api/migrate_wallet.php
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

$steps = [];

// ── 1. Add wallet_balance, referral_code, referred_by to users ───────────────
$cols = Database::fetchAll("SHOW COLUMNS FROM users LIKE 'wallet_balance'");
if (!$cols) {
    Database::execute("ALTER TABLE users ADD COLUMN wallet_balance DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER email_verified");
    $steps[] = '✅ Added wallet_balance to users';
} else { $steps[] = '⏭ wallet_balance already exists'; }

$cols = Database::fetchAll("SHOW COLUMNS FROM users LIKE 'referral_code'");
if (!$cols) {
    Database::execute("ALTER TABLE users ADD COLUMN referral_code VARCHAR(20) NULL UNIQUE AFTER wallet_balance");
    $steps[] = '✅ Added referral_code to users';
} else { $steps[] = '⏭ referral_code already exists'; }

$cols = Database::fetchAll("SHOW COLUMNS FROM users LIKE 'referred_by'");
if (!$cols) {
    Database::execute("ALTER TABLE users ADD COLUMN referred_by INT UNSIGNED NULL AFTER referral_code");
    $steps[] = '✅ Added referred_by to users';
} else { $steps[] = '⏭ referred_by already exists'; }

// ── 2. wallet_transactions ────────────────────────────────────────────────────
$tables = Database::fetchAll("SHOW TABLES LIKE 'wallet_transactions'");
if (!$tables) {
    Database::execute("
        CREATE TABLE wallet_transactions (
          id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
          user_id       INT UNSIGNED NOT NULL,
          type          ENUM('credit','debit') NOT NULL,
          amount        DECIMAL(10,2) NOT NULL,
          description   VARCHAR(200) NOT NULL DEFAULT '',
          reference_id  INT UNSIGNED NULL,
          balance_before DECIMAL(12,2) NOT NULL DEFAULT 0,
          balance_after  DECIMAL(12,2) NOT NULL DEFAULT 0,
          expires_at    DATETIME NULL,
          created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          KEY idx_wt_user (user_id),
          KEY idx_wt_created (created_at),
          CONSTRAINT fk_wt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $steps[] = '✅ Created wallet_transactions';
} else { $steps[] = '⏭ wallet_transactions already exists'; }

// ── 3. referrals ──────────────────────────────────────────────────────────────
$tables = Database::fetchAll("SHOW TABLES LIKE 'referrals'");
if (!$tables) {
    Database::execute("
        CREATE TABLE referrals (
          id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
          referrer_id   INT UNSIGNED NOT NULL,
          referred_id   INT UNSIGNED NOT NULL,
          status        ENUM('pending','completed') NOT NULL DEFAULT 'pending',
          reward_given  TINYINT(1) NOT NULL DEFAULT 0,
          reward_amount DECIMAL(10,2) NULL,
          rewarded_at   DATETIME NULL,
          created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          UNIQUE KEY uk_referred (referred_id),
          KEY idx_referrer (referrer_id),
          CONSTRAINT fk_ref_referrer FOREIGN KEY (referrer_id) REFERENCES users(id) ON DELETE CASCADE,
          CONSTRAINT fk_ref_referred FOREIGN KEY (referred_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $steps[] = '✅ Created referrals';
} else { $steps[] = '⏭ referrals already exists'; }

// ── 4. Add wallet_amount to orders ───────────────────────────────────────────
$cols = Database::fetchAll("SHOW COLUMNS FROM orders LIKE 'wallet_amount'");
if (!$cols) {
    Database::execute("ALTER TABLE orders ADD COLUMN wallet_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER payment_method");
    $steps[] = '✅ Added wallet_amount to orders';
} else { $steps[] = '⏭ wallet_amount already exists'; }

// ── 5. Generate referral codes for existing users ────────────────────────────
$users = Database::fetchAll("SELECT id, name FROM users WHERE referral_code IS NULL");
$generated = 0;
foreach ($users as $u) {
    $namepart = strtoupper(preg_replace('/[^A-Za-z]/', '', $u['name']));
    $namepart = substr(str_pad($namepart, 4, 'X'), 0, 6);
    $code     = $namepart . $u['id'];
    try {
        Database::execute("UPDATE users SET referral_code = ? WHERE id = ?", [$code, $u['id']]);
        $generated++;
    } catch (\Exception $e) {
        // duplicate — append extra chars
        Database::execute("UPDATE users SET referral_code = ? WHERE id = ?", [$code . 'A', $u['id']]);
        $generated++;
    }
}
if ($generated) $steps[] = "✅ Generated referral codes for $generated existing users";

// ── 6. Seed default wallet settings ──────────────────────────────────────────
$defaults = [
    'referral_enabled'        => '1',
    'referral_reward_amount'  => '25',
    'referral_min_order'      => '100',
    'referral_monthly_limit'  => '10',
    'wallet_expiry_days'      => '0',
];
foreach ($defaults as $key => $value) {
    Database::execute(
        "INSERT INTO system_settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `key` = `key`",
        [$key, $value]
    );
}
$steps[] = '✅ Seeded default wallet/referral settings';

header('Content-Type: text/plain');
echo implode("\n", $steps) . "\n\nDone! You can delete this file.\n";
