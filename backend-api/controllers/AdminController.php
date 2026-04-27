<?php
/**
 * AdminController.php — Platform administration
 *
 * All routes require role=admin.
 *
 * GET  /admin/dashboard
 * GET  /admin/users
 * GET  /admin/restaurants
 * PATCH /admin/restaurants/:id/approve
 * GET  /admin/orders
 * GET  /admin/earnings
 * GET  /admin/settlements
 * POST /admin/settlements/process/:id
 * GET  /admin/delivery-partners
 * PATCH /admin/delivery-partners/:id/verify
 * GET  /admin/settings
 * PUT  /admin/settings
 * POST /admin/coupons
 */

declare(strict_types=1);

require_once __DIR__ . '/../models/Order.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Restaurant.php';
require_once __DIR__ . '/../models/DeliveryPartner.php';
require_once __DIR__ . '/../services/CommissionService.php';
require_once __DIR__ . '/../services/WalletService.php';
require_once __DIR__ . '/../middlewares/AuthMiddleware.php';
require_once __DIR__ . '/../utils/Response.php';
require_once __DIR__ . '/../utils/Validator.php';
require_once __DIR__ . '/../utils/Helper.php';

class AdminController
{
    private Order           $order;
    private User            $user;
    private Restaurant      $restaurant;
    private DeliveryPartner $partner;

    public function __construct()
    {
        $this->order      = new Order();
        $this->user       = new User();
        $this->restaurant = new Restaurant();
        $this->partner    = new DeliveryPartner();
    }

    // ── GET /admin/dashboard ───────────────────────────────────────────────
    public function dashboard(array $params): void
    {
        AuthMiddleware::requireRole('admin');

        $orderStats = Database::fetchOne(
            "SELECT COUNT(*) AS total_orders,
                    SUM(status = 'delivered') AS delivered_orders,
                    SUM(DATE(created_at) = CURDATE()) AS orders_today,
                    SUM(total_amount) AS gross_revenue,
                    SUM(CASE WHEN status = 'delivered' THEN commission_amount ELSE 0 END) AS total_commission,
                    SUM(CASE WHEN status = 'delivered' AND DATE(created_at) = CURDATE() THEN commission_amount ELSE 0 END) AS revenue_today
             FROM orders"
        ) ?: [];

        $userStats = Database::fetchOne(
            "SELECT COUNT(*) AS total,
                    SUM(DATE(created_at) = CURDATE()) AS users_today
             FROM users"
        ) ?: [];

        $restStats = Database::fetchOne(
            "SELECT SUM(is_active = 1) AS active,
                    SUM(approval_status = 'pending') AS pending_approval
             FROM restaurants"
        ) ?: [];

        $partnerStats = Database::fetchOne(
            "SELECT COUNT(*) AS total,
                    SUM(is_available = 1) AS online
             FROM delivery_partners"
        ) ?: [];

        $activeCoupons = Database::fetchOne(
            "SELECT COUNT(*) AS n FROM coupons WHERE is_active = 1"
        )['n'] ?? 0;

        Response::success([
            'stats' => [
                'total_orders'        => (int)   ($orderStats['total_orders']     ?? 0),
                'orders_today'        => (int)   ($orderStats['orders_today']     ?? 0),
                'total_revenue'       => (float) ($orderStats['total_commission'] ?? 0),
                'revenue_today'       => (float) ($orderStats['revenue_today']    ?? 0),
                'active_restaurants'  => (int)   ($restStats['active']            ?? 0),
                'pending_restaurants' => (int)   ($restStats['pending_approval']  ?? 0),
                'total_users'         => (int)   ($userStats['total']             ?? 0),
                'users_today'         => (int)   ($userStats['users_today']       ?? 0),
                'active_partners'     => (int)   ($partnerStats['total']          ?? 0),
                'online_partners'     => (int)   ($partnerStats['online']         ?? 0),
                'active_coupons'      => (int)   $activeCoupons,
                'coupon_uses_today'   => 0,
            ],
        ]);
    }

    // ── PATCH /admin/restaurants/:id/commission ───────────────────────────
    public function updateCommission(array $params): void
    {
        AuthMiddleware::requireRole('admin');
        $id  = (int) ($params['id'] ?? 0);
        $pct = (float) (getRequestData()['commission_percent'] ?? 0);

        if ($pct < 1 || $pct > 50) {
            Response::error('Commission must be between 1 and 50.', 400);
        }

        Database::execute(
            "UPDATE restaurants SET commission_percent = ? WHERE id = ?",
            [$pct, $id]
        );
        Response::success(null, 'Commission updated.');
    }

    // ── GET /admin/restaurants/:id ────────────────────────────────────────
    public function restaurantDetail(array $params): void
    {
        AuthMiddleware::requireRole('admin');
        $id = (int) ($params['id'] ?? 0);

        $r = $this->restaurant->getDetail($id);
        if (!$r) Response::notFound('Restaurant not found.');

        $stats = $this->restaurant->getStats($id);

        Response::success(array_merge($r, [
            'total_orders'   => $stats['total_orders']   ?? 0,
            'total_revenue'  => $stats['total_revenue']  ?? 0,
            'today_orders'   => $stats['today_orders']   ?? 0,
            'rating'         => $stats['avg_rating']     ?? $r['avg_rating'] ?? 0,
        ]));
    }

    // ── GET /admin/users ───────────────────────────────────────────────────
    public function users(array $params): void
    {
        AuthMiddleware::requireRole('admin');
        ['page' => $page, 'limit' => $limit, 'offset' => $offset] = getPagination(20);

        $role = $_GET['role'] ?? '';
        $params = $role ? [$role, $limit, $offset] : [$limit, $offset];
        $roleFilter = $role ? "WHERE role = ?" : '';

        $users = Database::fetchAll(
            "SELECT id, name, email, phone, role, is_active, wallet_balance, created_at
             FROM users $roleFilter ORDER BY created_at DESC LIMIT ? OFFSET ?",
            $params
        );

        $total = Database::fetchOne("SELECT COUNT(*) AS n FROM users" . ($role ? " WHERE role = ?" : ""), $role ? [$role] : [])['n'] ?? 0;

        Response::success($users, 'Users fetched.', 200, Response::paginate((int)$total, $page, $limit));
    }

    // ── POST /admin/users/:id/credit-wallet ────────────────────────────────
    public function creditWallet(array $params): void
    {
        AuthMiddleware::requireRole('admin');
        $userId = (int) ($params['id'] ?? 0);
        $data   = getRequestData();

        $amount = (float) ($data['amount'] ?? 0);
        if ($amount <= 0) {
            Response::error('Amount must be greater than 0.', 400);
        }

        $user = Database::fetchOne("SELECT id, name FROM users WHERE id = ?", [$userId]);
        if (!$user) {
            Response::notFound('User not found.');
        }

        $note = trim($data['note'] ?? '') ?: 'Admin wallet credit';
        WalletService::credit($userId, $amount, $note, null);

        Response::success(null, "₹{$amount} credited to {$user['name']}'s wallet.");
    }

    // ── GET /admin/restaurants ─────────────────────────────────────────────
    public function restaurants(array $params): void
    {
        AuthMiddleware::requireRole('admin');
        ['page' => $page, 'limit' => $limit, 'offset' => $offset] = getPagination(20);

        $status = $_GET['status'] ?? 'all'; // all | active | pending
        $where  = match ($status) {
            'active'  => "WHERE r.is_active = 1",
            'pending' => "WHERE r.approval_status = 'pending'",
            default   => '',
        };

        $restaurants = Database::fetchAll(
            "SELECT r.id, r.name, r.city, r.category, r.is_active, r.is_open,
                    r.approval_status, r.avg_rating, r.total_orders, r.commission_percent,
                    r.phone, r.created_at,
                    u.name AS owner_name, u.email AS owner_email,
                    CASE WHEN rs.id IS NOT NULL AND rs.expires_at >= CURDATE() THEN 1 ELSE 0 END AS has_subscription
             FROM restaurants r
             JOIN users u ON u.id = r.owner_id
             LEFT JOIN restaurant_subscriptions rs ON rs.restaurant_id = r.id AND rs.is_active = 1 AND rs.expires_at >= CURDATE()
             $where ORDER BY r.created_at DESC LIMIT ? OFFSET ?",
            [$limit, $offset]
        );

        Response::success($restaurants);
    }

    // ── PATCH /admin/restaurants/:id/approve ──────────────────────────────
    public function approveRestaurant(array $params): void
    {
        AuthMiddleware::requireRole('admin');
        $id   = (int) ($params['id'] ?? 0);
        $data = getRequestData();

        $action = (string) ($data['action'] ?? 'approve');

        if ($action === 'approve') {
            $this->restaurant->update($id, ['is_active' => 1, 'approval_status' => 'approved']);
            Response::success(null, 'Restaurant approved.');
        } elseif ($action === 'reject') {
            $this->restaurant->update($id, ['is_active' => 0, 'approval_status' => 'rejected']);
            Response::success(null, 'Restaurant rejected.');
        } elseif ($action === 'deactivate') {
            $this->restaurant->update($id, ['is_active' => 0]);
            Response::success(null, 'Restaurant deactivated.');
        } elseif ($action === 'activate') {
            $this->restaurant->update($id, ['is_active' => 1]);
            Response::success(null, 'Restaurant activated.');
        } else {
            Response::error('Invalid action.', 400);
        }
    }

    // ── GET /admin/orders ──────────────────────────────────────────────────
    public function orders(array $params): void
    {
        AuthMiddleware::requireRole('admin');
        ['page' => $page, 'limit' => $limit, 'offset' => $offset] = getPagination(20);

        $filters = [
            'status'    => $_GET['status']    ?? '',
            'date_from' => $_GET['date_from'] ?? '',
            'date_to'   => $_GET['date_to']   ?? '',
        ];

        $orders = $this->order->getAll($filters, $limit, $offset);
        Response::success($orders);
    }

    // ── GET /admin/earnings ────────────────────────────────────────────────
    public function earnings(array $params): void
    {
        AuthMiddleware::requireRole('admin');

        $from = $_GET['from'] ?? date('Y-m-01');
        $to   = $_GET['to']   ?? date('Y-m-d');

        $summary = Database::fetchOne(
            "SELECT
               SUM(commission_amount)                                        AS total_platform_earnings,
               SUM(platform_fee)                                             AS total_platform_fee,
               SUM(delivery_fee)                                             AS delivery_fee_total,
               SUM(gst_amount)                                               AS total_gst_collected,
               SUM(discount_amount)                                          AS total_discount_given,
               SUM(food_total)                                               AS total_food_value,
               SUM(total_amount)                                             AS total_gross_revenue,
               SUM(CASE WHEN DATE(created_at) = CURDATE() THEN commission_amount ELSE 0 END) AS earnings_today,
               SUM(CASE WHEN DATE(created_at) BETWEEN ? AND ? THEN commission_amount ELSE 0 END) AS earnings_this_month
             FROM orders
             WHERE status = 'delivered'",
            [$from, $to]
        ) ?: [];

        // Commission split: subscription partners pay ~8%, standard pay 20%
        $commSplit = Database::fetchOne(
            "SELECT
               SUM(CASE WHEN rs.id IS NOT NULL THEN o.commission_amount ELSE 0 END) AS subscription_commission_total,
               SUM(CASE WHEN rs.id IS     NULL THEN o.commission_amount ELSE 0 END) AS standard_commission_total
             FROM orders o
             JOIN restaurants r ON r.id = o.restaurant_id
             LEFT JOIN restaurant_subscriptions rs
               ON rs.restaurant_id = r.id AND rs.is_active = 1 AND rs.expires_at >= CURDATE()
             WHERE o.status = 'delivered'"
        ) ?: [];

        // Top earning restaurants
        $topRestaurants = Database::fetchAll(
            "SELECT r.name, COUNT(o.id) AS order_count, SUM(o.commission_amount) AS commission_total
             FROM orders o
             JOIN restaurants r ON r.id = o.restaurant_id
             WHERE o.status = 'delivered'
             GROUP BY r.id, r.name
             ORDER BY commission_total DESC
             LIMIT 10"
        );

        // Daily breakdown for current month
        $daily = Database::fetchAll(
            "SELECT DATE(created_at) AS date, COUNT(*) AS orders, SUM(commission_amount) AS revenue
             FROM orders
             WHERE status = 'delivered' AND DATE(created_at) BETWEEN ? AND ?
             GROUP BY DATE(created_at)
             ORDER BY date ASC",
            [$from, $to]
        );

        Response::success(array_merge($summary, $commSplit, [
            'top_restaurants' => $topRestaurants,
            'daily'           => $daily,
            'period'          => ['from' => $from, 'to' => $to],
        ]));
    }

    // ── GET /admin/settlements ─────────────────────────────────────────────
    public function settlements(array $params): void
    {
        AuthMiddleware::requireRole('admin');
        ['page' => $page, 'limit' => $limit, 'offset' => $offset] = getPagination(50);

        $settlements = Database::fetchAll(
            "SELECT s.id, r.name AS restaurant_name,
                    s.period_from AS period_start, s.period_to AS period_end,
                    s.total_orders, s.gross_amount, s.commission_amount,
                    s.net_payout, s.status, s.payment_reference, s.paid_at, s.created_at
             FROM settlements s
             JOIN restaurants r ON r.id = s.restaurant_id
             ORDER BY s.created_at DESC LIMIT ? OFFSET ?",
            [$limit, $offset]
        );

        Response::success($settlements);
    }

    // ── POST /admin/settlements/generate ──────────────────────────────────
    public function generateSettlements(array $params): void
    {
        AuthMiddleware::requireRole('admin');
        $data = getRequestData();

        $from = $data['from'] ?? date('Y-m-01');           // default: start of current month
        $to   = $data['to']   ?? date('Y-m-d');            // default: today

        // Aggregate delivered orders per restaurant in the period
        $rows = Database::fetchAll(
            "SELECT o.restaurant_id,
                    COUNT(o.id)               AS total_orders,
                    SUM(o.food_total)         AS gross_amount,
                    SUM(o.commission_amount)  AS commission_amount,
                    SUM(o.platform_fee)       AS platform_fees,
                    SUM(o.food_total - o.commission_amount) AS net_payout
             FROM orders o
             WHERE o.status = 'delivered'
               AND DATE(o.created_at) BETWEEN ? AND ?
             GROUP BY o.restaurant_id",
            [$from, $to]
        );

        if (empty($rows)) {
            Response::error('No delivered orders found in this period.', 404);
        }

        $created = 0;
        foreach ($rows as $row) {
            // Skip if settlement already exists for this restaurant+period
            $exists = Database::fetchOne(
                "SELECT id FROM settlements WHERE restaurant_id = ? AND period_from = ? AND period_to = ?",
                [$row['restaurant_id'], $from, $to]
            );
            if ($exists) continue;

            Database::execute(
                "INSERT INTO settlements
                    (restaurant_id, period_from, period_to, total_orders,
                     gross_amount, commission_amount, platform_fees, net_payout, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')",
                [
                    $row['restaurant_id'], $from, $to,
                    $row['total_orders'],  $row['gross_amount'],
                    $row['commission_amount'], $row['platform_fees'],
                    $row['net_payout'],
                ]
            );
            $created++;
        }

        Response::success(['generated' => $created], "$created settlement(s) generated.");
    }

    // ── POST /admin/settlements/process/:id ───────────────────────────────
    public function processSettlement(array $params): void
    {
        AuthMiddleware::requireRole('admin');
        $id = (int) ($params['id'] ?? 0);

        $settlement = Database::fetchOne("SELECT * FROM settlements WHERE id = ?", [$id]);
        if (!$settlement) {
            Response::notFound('Settlement not found.');
        }

        if ($settlement['status'] === 'paid') {
            Response::error('Settlement already processed.', 400);
        }

        $ref = 'PAY-' . strtoupper(bin2hex(random_bytes(6)));
        Database::execute(
            "UPDATE settlements SET status = 'paid', payment_reference = ?, paid_at = NOW() WHERE id = ?",
            [$ref, $id]
        );

        Response::success(['payment_reference' => $ref], 'Settlement marked as paid.');
    }

    // ── GET /admin/delivery-partners ──────────────────────────────────────
    public function deliveryPartners(array $params): void
    {
        AuthMiddleware::requireRole('admin');
        ['page' => $page, 'limit' => $limit, 'offset' => $offset] = getPagination(20);

        $partners = Database::fetchAll(
            "SELECT dp.id, dp.vehicle_type, dp.is_verified, dp.verification_status, dp.is_available,
                    dp.total_deliveries, dp.total_earnings, dp.city, dp.created_at,
                    u.name, u.email, u.phone
             FROM delivery_partners dp
             JOIN users u ON u.id = dp.user_id
             ORDER BY dp.created_at DESC LIMIT ? OFFSET ?",
            [$limit, $offset]
        );

        Response::success($partners);
    }

    // ── GET /admin/delivery-partners/:id ─────────────────────────────────
    public function deliveryPartnerDetail(array $params): void
    {
        AuthMiddleware::requireRole('admin');
        $id = (int) ($params['id'] ?? 0);

        $partner = Database::fetchOne(
            "SELECT dp.*, u.name, u.email, u.phone, u.created_at AS registered_at
             FROM delivery_partners dp
             JOIN users u ON u.id = dp.user_id
             WHERE dp.id = ?",
            [$id]
        );

        if (!$partner) Response::notFound('Partner not found.');
        Response::success($partner);
    }

    // ── PATCH /admin/delivery-partners/:id/verify ─────────────────────────
    public function verifyPartner(array $params): void
    {
        AuthMiddleware::requireRole('admin');
        $id = (int) ($params['id'] ?? 0);

        $data   = getRequestData();
        $status = $data['status'] ?? 'approved';

        if ($status === 'approved') {
            Database::execute(
                "UPDATE delivery_partners SET is_verified = 1, verification_status = 'approved' WHERE id = ?",
                [$id]
            );
            Response::success(null, 'Partner verified.');
        } else {
            Database::execute(
                "UPDATE delivery_partners SET is_verified = 0, verification_status = 'suspended' WHERE id = ?",
                [$id]
            );
            Response::success(null, 'Partner suspended.');
        }
    }

    // ── GET /cities ───────────────────────────────────────────────────────
    public function getCities(array $params): void
    {
        $row    = Database::fetchOne("SELECT `value` FROM system_settings WHERE `key` = 'cities'");
        $cities = $row ? json_decode($row['value'], true) : ['Chennai','Hyderabad','Bangalore','Coimbatore','Madurai','Salem','Vinukonda'];
        Response::success($cities);
    }

    // ── PUT /admin/cities ─────────────────────────────────────────────────
    public function updateCities(array $params): void
    {
        AuthMiddleware::requireRole('admin');
        $data   = getRequestData();
        $cities = array_values(array_filter(array_map('trim', (array)($data['cities'] ?? [])), fn($c) => $c !== ''));
        if (empty($cities)) Response::error('At least one city is required.', 400);

        Database::execute(
            "INSERT INTO system_settings (`key`, `value`, `description`) VALUES ('cities', ?, 'Available delivery cities')
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
            [json_encode($cities, JSON_UNESCAPED_UNICODE)]
        );
        Response::success($cities, 'Cities updated.');
    }

    // ── GET /admin/settings ────────────────────────────────────────────────
    public function getSettings(array $params): void
    {
        AuthMiddleware::requireRole('admin');

        $settings = Database::fetchAll("SELECT `key`, `value`, `description` FROM system_settings ORDER BY `key`");
        $map = array_column($settings, 'value', 'key');
        Response::success($map);
    }

    // ── GET /settings/public ──────────────────────────────────────────────
    public function publicSettings(array $params): void
    {
        $allowed = [
            'support_email', 'support_phone', 'support_whatsapp', 'platform_name',
            'plan_basic_price', 'plan_basic_commission',
            'plan_pro_price',   'plan_pro_commission',
            'plan_premium_price', 'plan_premium_commission',
            'platform_fee_base', 'platform_name',
            'free_delivery_above', 'base_delivery_fee', 'per_km_rate',
            'customer_plan_active', 'customer_plan_name', 'customer_plan_price',
            'customer_plan_discount', 'customer_plan_free_delivery',
            'customer_plan_free_delivery_km', 'customer_plan_min_order', 'customer_plan_benefits',
            'rider_base_pay', 'rider_free_km', 'rider_per_km_pay',
            'referral_enabled', 'referral_reward_amount', 'referral_min_order',
            'referral_monthly_limit', 'wallet_expiry_days', 'gst_label',
        ];
        $rows    = Database::fetchAll(
            "SELECT `key`, `value` FROM system_settings WHERE `key` IN ('" . implode("','", $allowed) . "')"
        );
        $map = array_column($rows, 'value', 'key');
        Response::success($map);
    }

    // ── PUT /admin/settings ────────────────────────────────────────────────
    public function updateSettings(array $params): void
    {
        AuthMiddleware::requireRole('admin');
        $data = getRequestData();

        foreach ($data as $key => $value) {
            $key   = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $key));
            $value = (string) $value;

            Database::execute(
                "INSERT INTO system_settings (`key`, `value`) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
                [$key, $value]
            );
        }

        Response::success(null, 'Settings updated.');
    }

    // ── GET /admin/coupons ─────────────────────────────────────────────────
    public function listCoupons(array $params): void
    {
        AuthMiddleware::requireRole('admin');

        $coupons = Database::fetchAll(
            "SELECT id, code, description, discount_type, discount_value,
                    min_order_amount, max_discount_amount, usage_limit, per_user_limit,
                    used_count, valid_from, valid_until, is_active
             FROM coupons
             ORDER BY created_at DESC"
        );

        Response::success($coupons);
    }

    // ── POST /admin/coupons ────────────────────────────────────────────────
    public function createCoupon(array $params): void
    {
        AuthMiddleware::requireRole('admin');
        $data = getRequestData();

        $v = new Validator($data);
        $v->required(['code', 'discount_type', 'discount_value', 'valid_from', 'valid_until'])
          ->in('discount_type', ['percent', 'flat'])
          ->numeric('discount_value')
          ->positive('discount_value');
        if ($v->fails()) {
            Response::validationError($v->errors());
        }

        Database::execute(
            "INSERT INTO coupons
               (code, description, discount_type, discount_value, min_order_amount,
                max_discount_amount, usage_limit, per_user_limit, valid_from, valid_until)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                strtoupper($data['code']),
                $data['description'] ?? '',
                $data['discount_type'],
                (float) $data['discount_value'],
                (float) ($data['min_order_amount'] ?? 0),
                !empty($data['max_discount_amount']) ? (float) $data['max_discount_amount'] : null,
                !empty($data['usage_limit']) ? (int) $data['usage_limit'] : null,
                (int) ($data['per_user_limit'] ?? 1),
                $data['valid_from'],
                $data['valid_until'],
            ]
        );

        Response::success(['coupon_id' => (int) Database::lastInsertId()], 'Coupon created.', 201);
    }

    // ── PUT /admin/coupons/{id} ────────────────────────────────────────────
    public function updateCoupon(array $params): void
    {
        AuthMiddleware::requireRole('admin');
        $id   = (int) ($params['id'] ?? 0);
        $data = getRequestData();

        if (!$id) { Response::error('Coupon ID required.', 400); }

        $v = new Validator($data);
        $v->required(['discount_type', 'discount_value', 'valid_from', 'valid_until'])
          ->in('discount_type', ['percent', 'flat'])
          ->numeric('discount_value')
          ->positive('discount_value');
        if ($v->fails()) { Response::validationError($v->errors()); }

        Database::execute(
            "UPDATE coupons SET
               description        = ?,
               discount_type      = ?,
               discount_value     = ?,
               min_order_amount   = ?,
               max_discount_amount= ?,
               usage_limit        = ?,
               per_user_limit     = ?,
               valid_from         = ?,
               valid_until        = ?
             WHERE id = ?",
            [
                $data['description'] ?? '',
                $data['discount_type'],
                (float)  $data['discount_value'],
                (float) ($data['min_order_amount']    ?? 0),
                !empty($data['max_discount_amount'])  ? (float) $data['max_discount_amount'] : null,
                !empty($data['usage_limit'])          ? (int)   $data['usage_limit']         : null,
                (int) ($data['per_user_limit']        ?? 1),
                $data['valid_from'],
                $data['valid_until'],
                $id,
            ]
        );

        Response::success([], 'Coupon updated.');
    }

    // ── PATCH /admin/coupons/{id}/toggle ──────────────────────────────────
    public function toggleCoupon(array $params): void
    {
        AuthMiddleware::requireRole('admin');
        $id = (int) ($params['id'] ?? 0);
        if (!$id) { Response::error('Coupon ID required.', 400); }

        Database::execute(
            "UPDATE coupons SET is_active = NOT is_active WHERE id = ?",
            [$id]
        );

        Response::success([], 'Coupon status toggled.');
    }
}
