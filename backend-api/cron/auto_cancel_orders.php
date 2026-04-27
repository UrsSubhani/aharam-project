<?php
/**
 * auto_cancel_orders.php — Auto-cancel unpaid/stale orders
 *
 * Schedule (cPanel Cron):
 *   */15 * * * *   php /home/username/public_html/api/cron/auto_cancel_orders.php
 *   (Every 15 minutes)
 *
 * Cancels orders that are:
 *  1. Still 'pending' with payment_status='pending' after N minutes (online orders)
 *  2. Still 'pending' after N minutes for COD (restaurant never confirmed)
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

$cancelMinutes = AUTO_CANCEL_MINUTES;
$scriptName    = basename(__FILE__);

appLog('info', "[$scriptName] Checking for stale orders (timeout: {$cancelMinutes} min)");
echo "[$scriptName] Checking stale orders...\n";

// Cancel unpaid online orders
$staleOnline = Database::fetchAll(
    "SELECT id, order_number FROM orders
     WHERE status = 'pending'
       AND payment_method = 'online'
       AND payment_status = 'pending'
       AND created_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)",
    [$cancelMinutes]
);

$cancelled = 0;
foreach ($staleOnline as $order) {
    Database::execute(
        "UPDATE orders
         SET status = 'cancelled',
             cancellation_reason = ?,
             cancelled_by = 'system',
             cancelled_at = NOW()
         WHERE id = ?",
        ["Auto-cancelled: payment not received within {$cancelMinutes} minutes.", $order['id']]
    );

    // Mark payment as failed
    Database::execute(
        "UPDATE payments SET status = 'failed' WHERE order_id = ? AND status = 'initiated'",
        [$order['id']]
    );

    echo "  Cancelled (unpaid): {$order['order_number']}\n";
    $cancelled++;
}

// Cancel COD orders stuck in pending for > 2x the timeout (restaurant never acknowledged)
$staleCod = Database::fetchAll(
    "SELECT id, order_number FROM orders
     WHERE status = 'pending'
       AND payment_method = 'cod'
       AND created_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)",
    [$cancelMinutes * 2]
);

foreach ($staleCod as $order) {
    Database::execute(
        "UPDATE orders
         SET status = 'cancelled',
             cancellation_reason = 'Auto-cancelled: restaurant did not confirm order.',
             cancelled_by = 'system',
             cancelled_at = NOW()
         WHERE id = ?",
        [$order['id']]
    );

    echo "  Cancelled (unconfirmed COD): {$order['order_number']}\n";
    $cancelled++;
}

appLog('info', "[$scriptName] Cancelled $cancelled orders.");
echo "[$scriptName] Done. Cancelled $cancelled orders.\n";
