<?php
/**
 * CommissionService.php — Commission & earnings calculation
 *
 * This is the core business logic for Aharam's monetisation model.
 *
 * Three models:
 *  1. Standard  — platform takes X% of food total (default 20%)
 *  2. Subscription — restaurant pays flat monthly fee → reduced rate (8%)
 *  3. Hybrid  — subscription active → low rate; else → standard rate
 *
 * The Hybrid model is always applied automatically. The system checks
 * whether the restaurant has an active subscription and picks the rate.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/SettingsService.php';

class CommissionService
{
    /**
     * Check if a restaurant has an active subscription.
     *
     * @return array{active: bool, plan: string|null, commission_percent: float, expires_at: string|null}
     */
    public static function getRestaurantSubscriptionStatus(int $restaurantId): array
    {
        $row = Database::fetchOne(
            "SELECT rs.*, r.commission_percent AS standard_commission
             FROM restaurant_subscriptions rs
             JOIN restaurants r ON r.id = rs.restaurant_id
             WHERE rs.restaurant_id = ?
               AND rs.is_active = 1
               AND rs.expires_at >= CURDATE()
             ORDER BY rs.expires_at DESC
             LIMIT 1",
            [$restaurantId]
        );

        if ($row) {
            return [
                'active'              => true,
                'plan'                => $row['plan_name'],
                'commission_percent'  => (float) $row['commission_percent'],
                'expires_at'          => $row['expires_at'],
                'standard_commission' => (float) $row['standard_commission'],
            ];
        }

        // No active subscription — fetch standard commission
        $rest = Database::fetchOne(
            "SELECT commission_percent FROM restaurants WHERE id = ?",
            [$restaurantId]
        );

        return [
            'active'              => false,
            'plan'                => null,
            'commission_percent'  => $rest ? (float) $rest['commission_percent'] : DEFAULT_COMMISSION,
            'expires_at'          => null,
            'standard_commission' => $rest ? (float) $rest['commission_percent'] : DEFAULT_COMMISSION,
        ];
    }

    /**
     * Calculate the full commission breakdown for an order.
     *
     * @return array{
     *   commission_percent: float,
     *   commission_amount: float,
     *   restaurant_payout: float,
     *   subscription_applied: bool,
     *   model: string
     * }
     */
    public static function calculate(float $foodTotal, int $restaurantId): array
    {
        $sub = self::getRestaurantSubscriptionStatus($restaurantId);

        $pct    = $sub['commission_percent'];
        $amount = round(($foodTotal * $pct) / 100, 2);
        $payout = round($foodTotal - $amount, 2);

        return [
            'commission_percent'   => $pct,
            'commission_amount'    => $amount,
            'restaurant_payout'    => $payout,
            'subscription_applied' => $sub['active'],
            'model'                => $sub['active'] ? 'subscription' : 'standard',
        ];
    }

    /**
     * Calculate delivery partner earnings for a delivery.
     *
     * Earnings = BASE_PAY + DISTANCE_PAY
     *  BASE_PAY     = ₹25 per delivery
     *  DISTANCE_PAY = ₹3 per km over 2km
     */
    public static function calculateDeliveryEarnings(float $distanceKm): array
    {
        $basePay    = SettingsService::float('rider_base_pay',    25.00);
        $freeKm     = SettingsService::float('rider_free_km',      2.00);
        $perKmPay   = SettingsService::float('rider_per_km_pay',   3.00);

        $distancePay = max(0.0, ($distanceKm - $freeKm) * $perKmPay);
        $total       = round($basePay + $distancePay, 2);

        return [
            'base_pay'     => $basePay,
            'distance_pay' => round($distancePay, 2),
            'total'        => $total,
        ];
    }

    /**
     * Record platform earnings after an order is placed.
     */
    public static function recordPlatformEarnings(
        int   $orderId,
        float $commissionAmount,
        float $platformFee,
        float $deliveryFeeShare = 0.0
    ): void {
        $total = $commissionAmount + $platformFee + $deliveryFeeShare;
        Database::execute(
            "INSERT INTO platform_earnings
               (order_id, commission_amount, platform_fee, delivery_fee_share, total_revenue)
             VALUES (?, ?, ?, ?, ?)",
            [$orderId, $commissionAmount, $platformFee, $deliveryFeeShare, $total]
        );
    }

    /**
     * Get platform earnings summary for admin.
     */
    public static function getPlatformEarningsSummary(string $from = '', string $to = ''): array
    {
        $params  = [];
        $where   = '';

        if ($from && $to) {
            $where    = "WHERE DATE(pe.created_at) BETWEEN ? AND ?";
            $params[] = $from;
            $params[] = $to;
        }

        return Database::fetchOne(
            "SELECT
               COUNT(*) AS total_orders,
               SUM(commission_amount)   AS total_commission,
               SUM(platform_fee)        AS total_platform_fee,
               SUM(delivery_fee_share)  AS total_delivery_share,
               SUM(total_revenue)       AS total_revenue
             FROM platform_earnings pe $where",
            $params
        ) ?: [];
    }
}
