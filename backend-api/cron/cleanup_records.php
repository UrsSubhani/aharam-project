<?php
/**
 * cleanup_records.php — Housekeeping cron
 *
 * Schedule (cPanel Cron):
 *   0 3 * * 0   php /home/username/public_html/api/cron/cleanup_records.php
 *   (Every Sunday at 3:00 AM)
 *
 * What it cleans:
 *  1. Old log files (>30 days)
 *  2. Expired OTPs
 *  3. Expired cart sessions (>24 hours old for guests)
 *  4. Old notifications (>60 days)
 *  5. Expired subscriptions (mark inactive)
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && !isset($_GET['cron_key'])) {
    http_response_code(403);
    exit('Access denied.');
}

if (isset($_GET['cron_key'])) {
    $expectedKey = md5(env('JWT_SECRET', 'secret') . date('Ymd'));
    if ($_GET['cron_key'] !== $expectedKey) {
        http_response_code(403);
        exit('Invalid cron key.');
    }
}

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/utils/Helper.php';

$scriptName = basename(__FILE__);
appLog('info', "[$scriptName] Starting cleanup");
echo "[$scriptName] Starting cleanup...\n";

// 1. Clear expired OTPs
$n = Database::execute(
    "UPDATE users SET otp_code = NULL, otp_expires_at = NULL
     WHERE otp_expires_at IS NOT NULL AND otp_expires_at < NOW()"
)->rowCount();
echo "  Cleared $n expired OTPs\n";

// 2. Old cart sessions (> 48 hours)
$n = Database::execute(
    "DELETE FROM cart_sessions WHERE updated_at < DATE_SUB(NOW(), INTERVAL 48 HOUR)"
)->rowCount();
echo "  Deleted $n stale cart sessions\n";

// 3. Old read notifications (> 60 days)
$n = Database::execute(
    "DELETE FROM notifications WHERE is_read = 1 AND created_at < DATE_SUB(NOW(), INTERVAL 60 DAY)"
)->rowCount();
echo "  Deleted $n old notifications\n";

// 4. Mark expired restaurant subscriptions as inactive
$n = Database::execute(
    "UPDATE restaurant_subscriptions SET is_active = 0
     WHERE is_active = 1 AND expires_at < CURDATE()"
)->rowCount();
echo "  Deactivated $n expired restaurant subscriptions\n";

// 5. Mark expired customer subscriptions as inactive
$n = Database::execute(
    "UPDATE customer_subscriptions SET is_active = 0
     WHERE is_active = 1 AND expires_at < CURDATE()"
)->rowCount();
echo "  Deactivated $n expired customer subscriptions\n";

// 6. Delete old log files (> 30 days)
$logDir   = dirname(__DIR__) . '/logs';
$deleted  = 0;
if (is_dir($logDir)) {
    foreach (glob($logDir . '/*.log') as $file) {
        if (filemtime($file) < strtotime('-30 days')) {
            unlink($file);
            $deleted++;
        }
    }
}
echo "  Deleted $deleted old log files\n";

appLog('info', "[$scriptName] Cleanup complete.");
echo "[$scriptName] Cleanup complete.\n";
