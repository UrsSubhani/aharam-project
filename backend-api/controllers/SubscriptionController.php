<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/CommissionService.php';
require_once __DIR__ . '/../services/PaymentService.php';
require_once __DIR__ . '/../services/SettingsService.php';
require_once __DIR__ . '/../models/Restaurant.php';
require_once __DIR__ . '/../middlewares/AuthMiddleware.php';
require_once __DIR__ . '/../utils/Response.php';
require_once __DIR__ . '/../utils/Helper.php';

class SubscriptionController
{
    // GET /subscription — get current subscription status
    public function status(array $params): void
    {
        $auth = AuthMiddleware::requireAuth();

        if ($auth['role'] === 'restaurant_owner') {
            $rest = (new Restaurant())->getByOwner($auth['sub']);
            if (!$rest) {
                Response::notFound('Restaurant not found.');
            }
            $status = CommissionService::getRestaurantSubscriptionStatus((int) $rest['id']);
            Response::success($status);
        }

        // Customer subscription
        $sub = Database::fetchOne(
            "SELECT * FROM customer_subscriptions
             WHERE user_id = ? AND is_active = 1 AND expires_at >= CURDATE()",
            [$auth['sub']]
        );

        Response::success([
            'active'     => (bool) $sub,
            'plan'       => $sub ? $sub['plan_name'] : null,
            'expires_at' => $sub ? $sub['expires_at'] : null,
            'benefits'   => $sub ? [
                'free_delivery'    => true,
                'discount_percent' => $sub['discount_percent'],
            ] : null,
        ]);
    }

    // POST /subscription/restaurant — restaurant subscribes
    public function subscribeRestaurant(array $params): void
    {
        $auth = AuthMiddleware::requireRole('restaurant_owner');
        $data = getRequestData();

        $rest = (new Restaurant())->getByOwner($auth['sub']);
        if (!$rest) {
            Response::notFound('Restaurant not found.');
        }

        $plans = [
            'basic'   => [
                'amount'     => SettingsService::float('plan_basic_price',      599),
                'commission' => SettingsService::float('plan_basic_commission',  12),
            ],
            'pro'     => [
                'amount'     => SettingsService::float('plan_pro_price',         999),
                'commission' => SettingsService::float('plan_pro_commission',      8),
            ],
            'premium' => [
                'amount'     => SettingsService::float('plan_premium_price',    1499),
                'commission' => SettingsService::float('plan_premium_commission',  5),
            ],
        ];

        $planKey = strtolower($data['plan'] ?? 'pro');
        if (!isset($plans[$planKey])) {
            Response::error('Invalid plan. Choose: basic, pro, premium.', 400);
        }

        $plan  = $plans[$planKey];
        $today = date('Y-m-d');
        $expires = date('Y-m-d', strtotime('+1 month'));

        // Deactivate old subscription
        Database::execute(
            "UPDATE restaurant_subscriptions SET is_active = 0 WHERE restaurant_id = ?",
            [$rest['id']]
        );

        Database::execute(
            "INSERT INTO restaurant_subscriptions
               (restaurant_id, plan_name, plan_amount, commission_percent, starts_at, expires_at, is_active)
             VALUES (?, ?, ?, ?, ?, ?, 1)",
            [$rest['id'], ucfirst($planKey), $plan['amount'], $plan['commission'], $today, $expires]
        );

        // Apply the subscription commission rate to the restaurant immediately
        Database::execute(
            "UPDATE restaurants SET commission_percent = ? WHERE id = ?",
            [$plan['commission'], $rest['id']]
        );

        Response::success([
            'plan'               => ucfirst($planKey),
            'amount'             => $plan['amount'],
            'commission_percent' => $plan['commission'],
            'expires_at'         => $expires,
        ], 'Subscription activated! Reduced commission applied.', 201);
    }

    // POST /subscription/customer — customer subscribes to Aharam Plus
    public function subscribeCustomer(array $params): void
    {
        $auth = AuthMiddleware::requireRole('customer');

        $existing = Database::fetchOne(
            "SELECT id FROM customer_subscriptions WHERE user_id = ? AND is_active = 1 AND expires_at >= CURDATE()",
            [$auth['sub']]
        );

        if ($existing) {
            Response::error('You already have an active subscription.', 409);
        }

        if ((int) SettingsService::get('customer_plan_active', '1') === 0) {
            Response::error('Aharam Plus is not available at this time.', 503);
        }

        $planName     = SettingsService::get('customer_plan_name',         'Aharam Plus');
        $planPrice    = SettingsService::float('customer_plan_price',       99);
        $planDiscount = SettingsService::float('customer_plan_discount',    10);
        $freeDelivery = (int) SettingsService::get('customer_plan_free_delivery', '1');

        $today   = date('Y-m-d');
        $expires = date('Y-m-d', strtotime('+1 month'));

        Database::execute(
            "INSERT INTO customer_subscriptions
               (user_id, plan_name, plan_amount, free_delivery, discount_percent, starts_at, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [$auth['sub'], $planName, $planPrice, $freeDelivery, $planDiscount, $today, $expires]
        );

        Response::success([
            'plan'             => $planName,
            'amount'           => $planPrice,
            'free_delivery'    => (bool) $freeDelivery,
            'discount_percent' => $planDiscount,
            'expires_at'       => $expires,
        ], "Welcome to {$planName}! Enjoy free delivery for a month.", 201);
    }
}
