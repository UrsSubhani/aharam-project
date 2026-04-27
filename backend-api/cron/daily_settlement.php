<?php
/**
 * daily_settlement.php — Restaurant daily settlement cron
 *
 * Calculates each restaurant's net payout for yesterday's delivered orders
 * and creates a settlement record.
 *
 * Schedule (cPanel Cron):
 *   0 2 * * *   php /home/username/public_html/api/cron/daily_settlement.php
 *   (Runs daily at 2:00 AM IST)
 *
 * What it does:
 *  1. Finds all restaurants with delivered orders yesterday
 *  2. Calculates gross revenue, commission, and net payout
 *  3. Creates a settlements record (status=pending)
 *  4. Admin then marks settlements as paid from the admin panel
 */

declare(strict_types=1);

// CLI-only guard
if (PHP_SAPI !== 'cli' && !isset($_GET['cron_key'])) {
    http_response_code(403);
    exit('Access denied.');
}

// Validate optional web-based cron key (for cPanel URL-based cron)
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

$yesterday  = date('Y-m-d', strtotime('-1 day'));
$scriptName = basename(__FILE__);

appLog('info', "[$scriptName] Starting daily settlement for $yesterday");
echo "[$scriptName] Starting settlement for $yesterday\n";

// Find all restaurants with delivered orders yesterday
$restaurants = Database::fetchAll(
    "SELECT
       o.restaurant_id,
       r.name AS restaurant_name,
       COUNT(o.id)              AS total_orders,
       SUM(o.food_total)        AS gross_amount,
       SUM(o.commission_amount) AS commission_amount,
       SUM(o.platform_fee)      AS platform_fees,
       SUM(o.gst_amount)        AS tax_amount,
       SUM(o.restaurant_payout) AS net_payout
     FROM orders o
     JOIN restaurants r ON r.id = o.restaurant_id
     WHERE o.status = 'delivered'
       AND DATE(o.delivered_at) = ?
       AND NOT EXISTS (
           SELECT 1 FROM settlements s
           WHERE s.restaurant_id = o.restaurant_id
             AND s.period_from   = ?
             AND s.period_to     = ?
       )
     GROUP BY o.restaurant_id",
    [$yesterday, $yesterday, $yesterday]
);

if (empty($restaurants)) {
    echo "[$scriptName] No settlements to process for $yesterday.\n";
    appLog('info', "[$scriptName] No settlements to process.");
    exit(0);
}

$created = 0;
foreach ($restaurants as $row) {
    try {
        Database::execute(
            "INSERT INTO settlements
               (restaurant_id, period_from, period_to, total_orders, gross_amount,
                commission_amount, platform_fees, tax_deducted, net_payout, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')",
            [
                $row['restaurant_id'],
                $yesterday,
                $yesterday,
                $row['total_orders'],
                $row['gross_amount'],
                $row['commission_amount'],
                $row['platform_fees'],
                $row['tax_amount'],
                $row['net_payout'],
            ]
        );

        echo "  ✓ {$row['restaurant_name']}: ₹{$row['net_payout']} payout ({$row['total_orders']} orders)\n";
        $created++;
    } catch (\Exception $e) {
        appLog('error', "[$scriptName] Failed for restaurant {$row['restaurant_id']}: " . $e->getMessage());
        echo "  ✗ Failed: {$row['restaurant_name']}\n";
    }
}

appLog('info', "[$scriptName] Created $created settlement records.");
echo "[$scriptName] Done. Created $created settlement records.\n";
