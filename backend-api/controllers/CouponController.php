<?php
/**
 * CouponController.php
 *
 * POST /coupon/apply  - Validate & preview coupon discount
 * GET  /coupons       - List active coupons (customer)
 * POST /coupons       - Create coupon (admin only)
 */

declare(strict_types=1);

require_once __DIR__ . '/../middlewares/AuthMiddleware.php';
require_once __DIR__ . '/../utils/Response.php';
require_once __DIR__ . '/../utils/Validator.php';
require_once __DIR__ . '/../utils/Helper.php';

class CouponController
{
    // ── POST /coupon/apply ────────────────────────────────────────────────
    public function apply(array $params): void
    {
        $auth = AuthMiddleware::requireAuth();
        $data = getRequestData();

        $v = new Validator($data);
        $v->required(['code', 'order_amount'])->numeric('order_amount');
        if ($v->fails()) {
            Response::validationError($v->errors());
        }

        $code        = strtoupper(trim($data['code']));
        $orderAmount = (float) $data['order_amount'];
        $restaurantId = (int) ($data['restaurant_id'] ?? 0);

        $coupon = Database::fetchOne(
            "SELECT * FROM coupons
             WHERE code = ? AND is_active = 1
               AND valid_from <= CURDATE() AND valid_until >= CURDATE()
               AND (restaurant_id IS NULL OR restaurant_id = ?)",
            [$code, $restaurantId]
        );

        if (!$coupon) {
            Response::error('Invalid or expired coupon code.', 400);
        }

        if ($orderAmount < (float) $coupon['min_order_amount']) {
            Response::error(
                "Minimum order amount ₹{$coupon['min_order_amount']} required for this coupon.",
                400
            );
        }

        // Usage limit check
        if ($coupon['usage_limit'] && $coupon['used_count'] >= $coupon['usage_limit']) {
            Response::error('This coupon has reached its usage limit.', 400);
        }

        // Per-user limit
        $userUsage = Database::fetchOne(
            "SELECT COUNT(*) AS n, MAX(created_at) AS last_used
             FROM orders WHERE user_id = ? AND coupon_code = ?",
            [$auth['sub'], $code]
        );
        if ((int)($userUsage['n'] ?? 0) >= (int)$coupon['per_user_limit']) {
            Response::error('You have already used this coupon the maximum number of times.', 400);
        }

        // Calculate discount
        if ($coupon['discount_type'] === 'percent') {
            $discount = ($orderAmount * (float)$coupon['discount_value']) / 100;
            if ($coupon['max_discount_amount']) {
                $discount = min($discount, (float)$coupon['max_discount_amount']);
            }
        } else {
            $discount = (float)$coupon['discount_value'];
        }

        $discount = round(min($discount, $orderAmount), 2);

        Response::success([
            'code'            => $code,
            'description'     => $coupon['description'],
            'discount_type'   => $coupon['discount_type'],
            'discount_value'  => $coupon['discount_value'],
            'discount_amount' => $discount,
            'final_amount'    => round($orderAmount - $discount, 2),
        ], 'Coupon applied successfully.');
    }

    // ── GET /coupons ──────────────────────────────────────────────────────
    public function index(array $params): void
    {
        $auth   = AuthMiddleware::optionalAuth();
        $userId = $auth ? (int)$auth['sub'] : 0;

        $restId = (int)($_GET['restaurant_id'] ?? 0);
        // Show global coupons (restaurant_id IS NULL) + this restaurant's coupons
        $restFilter = $restId
            ? "AND (c.restaurant_id IS NULL OR c.restaurant_id = {$restId})"
            : "AND c.restaurant_id IS NULL";

        if ($userId) {
            $coupons = Database::fetchAll(
                "SELECT c.code, c.description, c.discount_type, c.discount_value,
                        c.min_order_amount, c.max_discount_amount, c.valid_until
                 FROM coupons c
                 LEFT JOIN (
                     SELECT coupon_code, COUNT(*) AS use_count
                     FROM orders
                     WHERE user_id = ?
                     GROUP BY coupon_code
                 ) u ON u.coupon_code = c.code
                 WHERE c.is_active = 1 AND c.valid_until >= CURDATE()
                   AND (c.usage_limit IS NULL OR c.used_count < c.usage_limit)
                   AND COALESCE(u.use_count, 0) < c.per_user_limit
                   {$restFilter}
                 ORDER BY c.restaurant_id DESC, c.discount_value DESC",
                [$userId]
            );
        } else {
            $coupons = Database::fetchAll(
                "SELECT code, description, discount_type, discount_value,
                        min_order_amount, max_discount_amount, valid_until
                 FROM coupons
                 WHERE is_active = 1 AND valid_until >= CURDATE()
                   AND (usage_limit IS NULL OR used_count < usage_limit)
                   {$restFilter}
                 ORDER BY restaurant_id DESC, discount_value DESC"
            );
        }

        Response::success($coupons);
    }

    // ── POST /coupons (admin) ─────────────────────────────────────────────
    public function create(array $params): void
    {
        AuthMiddleware::requireRole('admin');
        $data = getRequestData();

        $v = new Validator($data);
        $v->required(['code', 'discount_type', 'discount_value', 'valid_from', 'valid_until'])
          ->in('discount_type', ['percent', 'flat'])
          ->numeric('discount_value')->positive('discount_value');
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
                (float)$data['discount_value'],
                (float)($data['min_order_amount'] ?? 0),
                !empty($data['max_discount_amount']) ? (float)$data['max_discount_amount'] : null,
                !empty($data['usage_limit']) ? (int)$data['usage_limit'] : null,
                (int)($data['per_user_limit'] ?? 1),
                $data['valid_from'],
                $data['valid_until'],
            ]
        );

        Response::success(
            ['coupon_id' => (int)Database::lastInsertId()],
            'Coupon created.',
            201
        );
    }

    // ── Helpers: get authenticated restaurant_id ──────────────────────────
    private function getRestaurantId(): int
    {
        $auth = AuthMiddleware::requireAuth();
        $rest = Database::fetchOne(
            "SELECT id FROM restaurants WHERE owner_id = ?",
            [$auth['sub']]
        );
        if (!$rest) { Response::error('Restaurant not found.', 404); }
        return (int)$rest['id'];
    }

    // ── GET /restaurant/coupons ───────────────────────────────────────────
    public function restaurantList(array $params): void
    {
        $restId = $this->getRestaurantId();

        $coupons = Database::fetchAll(
            "SELECT id, code, description, discount_type, discount_value,
                    min_order_amount, max_discount_amount, usage_limit, per_user_limit,
                    used_count, valid_from, valid_until, is_active
             FROM coupons
             WHERE restaurant_id = ?
             ORDER BY created_at DESC",
            [$restId]
        );

        Response::success($coupons);
    }

    // ── POST /restaurant/coupons ──────────────────────────────────────────
    public function restaurantCreate(array $params): void
    {
        $restId = $this->getRestaurantId();
        $data   = getRequestData();

        $v = new Validator($data);
        $v->required(['code', 'discount_type', 'discount_value'])
          ->in('discount_type', ['percent', 'flat'])
          ->numeric('discount_value')->positive('discount_value');
        if ($v->fails()) { Response::validationError($v->errors()); }

        // Ensure code is unique
        $exists = Database::fetchOne("SELECT id FROM coupons WHERE code = ?", [strtoupper($data['code'])]);
        if ($exists) { Response::error('Coupon code already exists.', 400); }

        Database::execute(
            "INSERT INTO coupons
               (code, description, discount_type, discount_value, min_order_amount,
                max_discount_amount, usage_limit, per_user_limit,
                valid_from, valid_until, restaurant_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                strtoupper($data['code']),
                $data['description'] ?? '',
                $data['discount_type'],
                (float)$data['discount_value'],
                (float)($data['min_order_amount'] ?? 0),
                !empty($data['max_discount_amount']) ? (float)$data['max_discount_amount'] : null,
                !empty($data['usage_limit'])         ? (int)$data['usage_limit']           : null,
                (int)($data['per_user_limit'] ?? 1),
                $data['valid_from']  ?? date('Y-m-d'),
                $data['valid_until'] ?? '2099-12-31',
                $restId,
            ]
        );

        Response::success(['coupon_id' => (int)Database::lastInsertId()], 'Coupon created.', 201);
    }

    // ── PUT /restaurant/coupons/:id ───────────────────────────────────────
    public function restaurantUpdate(array $params): void
    {
        $restId = $this->getRestaurantId();
        $id     = (int)($params['id'] ?? 0);
        $data   = getRequestData();

        $own = Database::fetchOne("SELECT id FROM coupons WHERE id = ? AND restaurant_id = ?", [$id, $restId]);
        if (!$own) { Response::error('Coupon not found.', 404); }

        $v = new Validator($data);
        $v->required(['discount_type', 'discount_value'])
          ->in('discount_type', ['percent', 'flat'])
          ->numeric('discount_value')->positive('discount_value');
        if ($v->fails()) { Response::validationError($v->errors()); }

        Database::execute(
            "UPDATE coupons SET
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
                $data['discount_type'],
                (float)$data['discount_value'],
                (float)($data['min_order_amount']    ?? 0),
                !empty($data['max_discount_amount']) ? (float)$data['max_discount_amount'] : null,
                !empty($data['usage_limit'])         ? (int)$data['usage_limit']           : null,
                (int)($data['per_user_limit']        ?? 1),
                $data['valid_from']  ?? date('Y-m-d'),
                $data['valid_until'] ?? '2099-12-31',
                $id,
            ]
        );

        Response::success([], 'Coupon updated.');
    }

    // ── PATCH /restaurant/coupons/:id/toggle ──────────────────────────────
    public function restaurantToggle(array $params): void
    {
        $restId = $this->getRestaurantId();
        $id     = (int)($params['id'] ?? 0);

        $own = Database::fetchOne("SELECT id FROM coupons WHERE id = ? AND restaurant_id = ?", [$id, $restId]);
        if (!$own) { Response::error('Coupon not found.', 404); }

        Database::execute("UPDATE coupons SET is_active = NOT is_active WHERE id = ?", [$id]);
        Response::success([], 'Status toggled.');
    }
}
