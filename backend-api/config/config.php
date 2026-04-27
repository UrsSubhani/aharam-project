<?php
/**
 * config.php — Central application configuration
 *
 * Loads environment from .env file and exposes typed constants.
 * Never put credentials here — use .env.
 */

declare(strict_types=1);

// ── Load .env file ────────────────────────────────────────────────────────────
$envFile = dirname(__DIR__) . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value);
        // Strip surrounding quotes
        if (preg_match('/^["\'](.+)["\']$/', $value, $m)) {
            $value = $m[1];
        }
        if (!array_key_exists($key, $_SERVER) && !array_key_exists($key, $_ENV)) {
            putenv("$key=$value");
            $_ENV[$key]    = $value;
            $_SERVER[$key] = $value;
        }
    }
}

// ── Helper: read env with default ────────────────────────────────────────────
function env(string $key, mixed $default = null): mixed
{
    $value = getenv($key);
    return ($value !== false) ? $value : $default;
}

// ── App settings ─────────────────────────────────────────────────────────────
define('APP_ENV',   env('APP_ENV',   'production'));
define('APP_NAME',  env('APP_NAME',  'Aharam'));
define('APP_URL',   env('APP_URL',   'https://api.aharam.in'));
define('APP_DEBUG', filter_var(env('APP_DEBUG', false), FILTER_VALIDATE_BOOLEAN));

// ── Database ─────────────────────────────────────────────────────────────────
define('DB_HOST',   env('DB_HOST', 'localhost'));
define('DB_PORT',   (int) env('DB_PORT', 3306));
define('DB_NAME',   env('DB_NAME', 'aharam_db'));
define('DB_USER',   env('DB_USER', 'root'));
define('DB_PASS',   env('DB_PASS', ''));

// ── JWT ───────────────────────────────────────────────────────────────────────
define('JWT_SECRET',          env('JWT_SECRET', 'change_me_in_production'));
define('JWT_EXPIRY',          (int) env('JWT_EXPIRY',         86400));   // 24 h
define('JWT_REFRESH_EXPIRY',  (int) env('JWT_REFRESH_EXPIRY', 604800));  // 7 days

// ── Razorpay ──────────────────────────────────────────────────────────────────
define('RAZORPAY_KEY_ID',     env('RAZORPAY_KEY_ID',     ''));
define('RAZORPAY_KEY_SECRET', env('RAZORPAY_KEY_SECRET', ''));

// ── Upload ────────────────────────────────────────────────────────────────────
define('UPLOAD_DIR',    dirname(__DIR__) . '/' . env('UPLOAD_DIR', 'uploads'));
define('MAX_UPLOAD_MB', (int) env('MAX_UPLOAD_MB', 5));

// ── CORS ──────────────────────────────────────────────────────────────────────
define('CORS_ORIGINS', explode(',', env('CORS_ORIGINS', 'http://localhost')));

// ── Business Rules ────────────────────────────────────────────────────────────
define('DEFAULT_COMMISSION',      20.0);   // %
define('SUBSCRIPTION_COMMISSION', 8.0);    // %
define('BASE_DELIVERY_FEE',       30.0);   // INR
define('PER_KM_CHARGE',           5.0);    // INR per km
define('PLATFORM_FEE',            5.0);    // INR flat
define('GST_PERCENT',             5.0);    // %
define('FREE_DELIVERY_ABOVE',     299.0);  // INR
define('AUTO_CANCEL_MINUTES',     30);     // minutes

// ── Timezone ──────────────────────────────────────────────────────────────────
date_default_timezone_set('Asia/Kolkata');

// ── Error handling ────────────────────────────────────────────────────────────
if (APP_DEBUG) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(0);
}
