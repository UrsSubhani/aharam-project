<?php
/**
 * Helper.php — General utility functions
 *
 * Standalone functions used across the application.
 * Not a class — just a collection of helpers.
 */

declare(strict_types=1);

require_once __DIR__ . '/../services/SettingsService.php';

/**
 * Generate a unique order number.
 * Format: AHR-YYYYMMDD-XXXX  e.g., AHR-20260416-7823
 */
function generateOrderNumber(): string
{
    return 'AHR-' . date('Ymd') . '-' . str_pad((string) random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
}

/**
 * Calculate distance between two lat/lng points using Haversine formula.
 * Returns distance in kilometres.
 */
function haversineDistance(
    float $lat1,
    float $lng1,
    float $lat2,
    float $lng2
): float {
    $earthRadius = 6371; // km
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2
       + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return round($earthRadius * $c, 2);
}

/**
 * Calculate delivery fee based on distance.
 *
 * Rules:
 *  - Free if order total >= FREE_DELIVERY_ABOVE
 *  - Otherwise: BASE_DELIVERY_FEE + (distance * PER_KM_CHARGE)
 *  - Capped at ₹80
 */
function calculateDeliveryFee(float $distanceKm, float $orderTotal): float
{
    $freeAbove = SettingsService::float('free_delivery_above', FREE_DELIVERY_ABOVE);
    $baseFee   = SettingsService::float('base_delivery_fee',   BASE_DELIVERY_FEE);
    $perKm     = SettingsService::float('per_km_rate',         PER_KM_CHARGE);

    if ($orderTotal >= $freeAbove) {
        return 0.0;
    }
    $fee = $baseFee + ($distanceKm * $perKm);
    return min(round($fee, 2), 80.0);
}

/**
 * Calculate commission amount based on whether restaurant has an active subscription.
 *
 * @param float $foodTotal        Total food cost (before taxes)
 * @param float $commissionPct    Restaurant's standard commission %
 * @param bool  $hasSubscription  Whether the restaurant has an active subscription
 * @return array{commission_percent: float, commission_amount: float, subscription_applied: bool}
 */
function calculateCommission(
    float $foodTotal,
    float $commissionPct,
    bool  $hasSubscription = false
): array {
    $pct = $hasSubscription ? SUBSCRIPTION_COMMISSION : $commissionPct;
    return [
        'commission_percent'   => $pct,
        'commission_amount'    => round(($foodTotal * $pct) / 100, 2),
        'subscription_applied' => $hasSubscription,
    ];
}

/**
 * Build the complete order price breakdown.
 *
 * @param float  $foodTotal
 * @param float  $deliveryFee
 * @param float  $couponDiscount
 * @param float  $commissionPct
 * @param bool   $hasSubscription
 * @return array
 */
function buildPriceBreakdown(
    float $foodTotal,
    float $deliveryFee,
    float $couponDiscount,
    float $commissionPct,
    bool  $hasSubscription = false
): array {
    // ₹5 base + ₹1 per ₹100 of food total (matches cart JS formula)
    $platformFeeBase = SettingsService::float('platform_fee_base', 5);
    $platformFee  = $platformFeeBase + floor($foodTotal / 100);
    // GST on discounted amount, rounded to whole rupees (matches cart display)
    $taxableAmount = max(0, $foodTotal - $couponDiscount);
    $gstAmount    = (float) round(($taxableAmount * GST_PERCENT) / 100);
    $totalAmount  = $foodTotal - $couponDiscount + $deliveryFee + $platformFee + $gstAmount;
    $commission   = calculateCommission($foodTotal, $commissionPct, $hasSubscription);

    return [
        'food_total'          => round($foodTotal, 2),
        'discount_amount'     => round($couponDiscount, 2),
        'delivery_fee'        => round($deliveryFee, 2),
        'platform_fee'        => $platformFee,
        'gst_amount'          => $gstAmount,
        'total_amount'        => round(max($totalAmount, 0), 2),
        'commission_percent'  => $commission['commission_percent'],
        'commission_amount'   => $commission['commission_amount'],
        'restaurant_payout'   => round($foodTotal - $commission['commission_amount'], 2),
        'subscription_applied'=> $commission['subscription_applied'],
    ];
}

/**
 * Generate a random OTP (numeric).
 */
function generateOTP(int $length = 6): string
{
    return str_pad((string) random_int(0, (int)(10 ** $length) - 1), $length, '0', STR_PAD_LEFT);
}

/**
 * Securely hash a password using bcrypt.
 */
function hashPassword(string $password): string
{
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
}

/**
 * Verify a plain password against a bcrypt hash.
 */
function verifyPassword(string $plain, string $hash): bool
{
    return password_verify($plain, $hash);
}

/**
 * Slugify a string for URL-safe identifiers.
 * e.g., "Ravi's Kitchen" → "ravis-kitchen"
 */
function slugify(string $text): string
{
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9\s-]/', '', $text);
    $text = preg_replace('/[\s-]+/', '-', $text);
    return trim($text, '-');
}

/**
 * Parse JSON request body.
 * Used in controllers: $body = getJsonBody();
 */
function getJsonBody(): array
{
    $raw = file_get_contents('php://input');
    if (empty($raw)) {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Merge $_POST and JSON body — works with both content types.
 */
function getRequestData(): array
{
    $json = getJsonBody();
    return array_merge($_POST, $json);
}

/**
 * Determine the current meal period based on IST time.
 * Returns: breakfast | lunch | snack | dinner | late_night
 */
function getMealPeriod(): string
{
    $hour = (int) date('G'); // 0-23 in IST
    return match (true) {
        $hour >= 6  && $hour < 12 => 'breakfast',
        $hour >= 12 && $hour < 15 => 'lunch',
        $hour >= 15 && $hour < 18 => 'snack',
        $hour >= 18 && $hour < 23 => 'dinner',
        default                    => 'late_night',
    };
}

/**
 * Strip sensitive fields from an array before returning to clients.
 */
function sanitizeUser(array $user): array
{
    $strip = ['password_hash', 'otp_code', 'otp_expires_at'];
    return array_diff_key($user, array_flip($strip));
}

/**
 * Paginate an SQL query — returns [offset, limit, page].
 */
function getPagination(int $defaultLimit = 20): array
{
    $page  = max(1, (int) ($_GET['page']  ?? 1));
    $limit = min(100, max(1, (int) ($_GET['limit'] ?? $defaultLimit)));
    return [
        'page'   => $page,
        'limit'  => $limit,
        'offset' => ($page - 1) * $limit,
    ];
}

/**
 * Log a message to the application log file.
 * Errors always logged; debug messages only in DEBUG mode.
 */
function appLog(string $level, string $message, array $context = []): void
{
    if ($level === 'debug' && !APP_DEBUG) {
        return;
    }
    $logDir  = dirname(__DIR__) . '/logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    $logFile = $logDir . '/' . date('Y-m-d') . '.log';
    $entry   = sprintf(
        "[%s] [%s] %s %s\n",
        date('Y-m-d H:i:s'),
        strtoupper($level),
        $message,
        $context ? json_encode($context) : ''
    );
    file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
}
