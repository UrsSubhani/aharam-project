<?php
/**
 * OrderController.php — The heart of the platform
 *
 * POST   /order/place              - Place an order (customer)
 * GET    /order/:id                - Order details
 * GET    /order/:id/status         - Live status polling (no auth for tracking)
 * GET    /orders/history           - Customer order history
 * POST   /order/:id/cancel         - Cancel an order (customer)
 * POST   /order/:id/reorder        - Reorder from history
 * GET    /restaurant-orders        - Restaurant's orders (restaurant_owner)
 * PATCH  /order/:id/status         - Update order status (restaurant_owner)
 */

declare(strict_types=1);

require_once __DIR__ . '/../models/Order.php';
require_once __DIR__ . '/../models/MenuItem.php';
require_once __DIR__ . '/../models/Restaurant.php';
require_once __DIR__ . '/../services/CommissionService.php';
require_once __DIR__ . '/../services/PaymentService.php';
require_once __DIR__ . '/../services/WalletService.php';
require_once __DIR__ . '/../middlewares/AuthMiddleware.php';
require_once __DIR__ . '/../utils/Response.php';
require_once __DIR__ . '/../utils/Validator.php';
require_once __DIR__ . '/../utils/Helper.php';

class OrderController
{
    private Order      $order;
    private MenuItem   $menuItem;
    private Restaurant $restaurant;

    public function __construct()
    {
        $this->order      = new Order();
        $this->menuItem   = new MenuItem();
        $this->restaurant = new Restaurant();
    }

    // ── POST /order/place or POST /orders ────────────────────────────────
    public function place(array $params): void
    {
        $auth = AuthMiddleware::requireRole('customer');
        $data = getRequestData();

        $v = new Validator($data);
        $v->required(['payment_method'])
          ->in('payment_method', ['online', 'cod', 'wallet']);
        if ($v->fails()) {
            Response::validationError($v->errors());
        }

        $payMethod = $data['payment_method'];

        // ── Support both modes:
        //    1. Cart-based (frontend default): address_id is sent, items pulled from cart
        //    2. Direct: restaurant_id + items[] in body

        if (!empty($data['address_id'])) {
            // Cart-based mode: pull items and address from server state
            $addressId = (int) $data['address_id'];

            $address = Database::fetchOne(
                "SELECT * FROM user_addresses WHERE id = ? AND user_id = ?",
                [$addressId, $auth['sub']]
            );
            if (!$address) {
                Response::error('Delivery address not found.', 400);
            }

            $cartItems = Database::fetchAll(
                "SELECT cs.*, mi.name, mi.price, mi.restaurant_id
                 FROM cart_sessions cs
                 JOIN menu_items mi ON mi.id = cs.menu_item_id
                 WHERE cs.user_id = ?",
                [$auth['sub']]
            );
            if (empty($cartItems)) {
                Response::error('Your cart is empty.', 400);
            }

            $restaurantId = (int) $cartItems[0]['restaurant_id'];
            $items = array_map(fn($ci) => [
                'menu_item_id' => $ci['menu_item_id'],
                'quantity'     => $ci['quantity'],
                'notes'        => $ci['notes'] ?? null,
            ], $cartItems);

            $data['delivery_address'] = $address['address'] . ', ' . $address['city'];
            $data['delivery_lat']     = $address['latitude']  ?? null;
            $data['delivery_lng']     = $address['longitude'] ?? null;

        } else {
            // Direct mode: restaurant_id and items in body
            $v2 = new Validator($data);
            $v2->required(['restaurant_id', 'items', 'delivery_address']);
            if ($v2->fails()) {
                Response::validationError($v2->errors());
            }
            $restaurantId = (int) $data['restaurant_id'];
            $items        = $data['items'];
        }

        if (!is_array($items) || empty($items)) {
            Response::error('Order must have at least one item.', 400);
        }

        // Validate restaurant
        $rest = $this->restaurant->getDetail($restaurantId);
        if (!$rest || !$rest['is_active']) {
            Response::error('Restaurant is not accepting orders.', 400);
        }

        // Validate items and compute food total
        [$foodTotal, $orderItems] = $this->validateAndPriceItems($items, $restaurantId);

        // No minimum order restriction

        // Coupon discount
        $couponCode     = $data['coupon_code'] ?? null;
        $couponDiscount = 0.0;
        if ($couponCode) {
            $couponDiscount = $this->applyCoupon($couponCode, $auth['sub'], $foodTotal, $restaurantId);
        }

        // Customer subscription: free delivery
        $hasCustSub = $this->hasCustomerSubscription($auth['sub']);

        // Delivery fee — base rate only, no per-km added (matches cart display)
        $deliveryFee = $hasCustSub ? 0.0 : calculateDeliveryFee(0.0, $foodTotal);

        // Commission (subscription-aware)
        $subStatus  = CommissionService::getRestaurantSubscriptionStatus($restaurantId);

        // Build price breakdown
        $pricing = buildPriceBreakdown(
            $foodTotal,
            $deliveryFee,
            $couponDiscount,
            (float) $rest['commission_percent'],
            $subStatus['active']
        );

        // Address snapshot
        $addressText = is_array($data['delivery_address'])
            ? implode(', ', array_filter($data['delivery_address']))
            : (string) $data['delivery_address'];

        // Restaurant pickup coordinates (saved on order for delivery partner navigation)
        $pickupAddress = $rest['address'] . ', ' . $rest['city'];
        $pickupLat     = $rest['latitude']  ?? null;
        $pickupLng     = $rest['longitude'] ?? null;

        // Generate unique order number
        $orderNumber = generateOrderNumber();
        // Ensure uniqueness (retry once if collision)
        if ($this->order->findBy('order_number', $orderNumber)) {
            $orderNumber = generateOrderNumber();
        }

        Database::beginTransaction();
        try {
            $orderId = $this->order->create([
                'order_number'        => $orderNumber,
                'user_id'             => $auth['sub'],
                'restaurant_id'       => $restaurantId,
                'delivery_address_text' => $addressText,
                'delivery_lat'        => $data['delivery_lat'] ?? null,
                'delivery_lng'        => $data['delivery_lng'] ?? null,
                'status'              => 'pending',
                'order_type'          => 'delivery',
                'food_total'          => $pricing['food_total'],
                'discount_amount'     => 0,
                'coupon_code'         => $couponCode,
                'coupon_discount'     => $pricing['discount_amount'],
                'delivery_fee'        => $pricing['delivery_fee'],
                'platform_fee'        => $pricing['platform_fee'],
                'gst_amount'          => $pricing['gst_amount'],
                'total_amount'        => $pricing['total_amount'],
                'commission_percent'  => $pricing['commission_percent'],
                'commission_amount'   => $pricing['commission_amount'],
                'restaurant_payout'   => $pricing['restaurant_payout'],
                'subscription_applied'=> $pricing['subscription_applied'] ? 1 : 0,
                'payment_method'      => $payMethod,
                'wallet_amount'       => min((float)($data['wallet_amount'] ?? 0), $pricing['total_amount']),
                'payment_status'      => 'pending',
                'special_instructions'=> $data['special_instructions'] ?? null,
            ]);

            // Insert order items
            foreach ($orderItems as $oi) {
                Database::execute(
                    "INSERT INTO order_items (order_id, menu_item_id, name, price, quantity, subtotal, notes)
                     VALUES (?, ?, ?, ?, ?, ?, ?)",
                    [
                        $orderId,
                        $oi['menu_item_id'],
                        $oi['name'],
                        $oi['price'],
                        $oi['quantity'],
                        $oi['subtotal'],
                        $oi['notes'] ?? null,
                    ]
                );

                // Increment item popularity
                $this->menuItem->incrementOrders($oi['menu_item_id'], $oi['quantity']);
            }

            // Increment restaurant order count
            $this->restaurant->incrementOrders($restaurantId);

            // Clear user cart after order
            Database::execute("DELETE FROM cart_sessions WHERE user_id = ?", [$auth['sub']]);

            // Handle COD immediately
            // Partial or full wallet deduction (works for both 'wallet' and 'cod' payment methods)
            $walletAmountRequested = (float) ($data['wallet_amount'] ?? 0);
            if ($walletAmountRequested > 0) {
                $walletBalance = WalletService::getBalance((int) $auth['sub']);
                // Clamp to available balance and order total
                $walletDeduct = min($walletAmountRequested, $walletBalance, $pricing['total_amount']);
                if ($walletDeduct > 0) {
                    WalletService::debit(
                        (int) $auth['sub'],
                        $walletDeduct,
                        "Wallet used for order #{$orderNumber}",
                        $orderId
                    );
                }
            }

            if ($payMethod === 'cod') {
                PaymentService::processCOD($orderId, $auth['sub'], $pricing['total_amount']);
            }

            // Wallet payment settled — mark payment as paid but leave status pending for restaurant to confirm
            if ($payMethod === 'wallet') {
                Database::execute(
                    "UPDATE orders SET payment_status = 'paid' WHERE id = ?",
                    [$orderId]
                );
            }

            // Record platform earnings
            CommissionService::recordPlatformEarnings(
                $orderId,
                $pricing['commission_amount'],
                $pricing['platform_fee']
            );

            // Update coupon usage
            if ($couponCode) {
                Database::execute(
                    "UPDATE coupons SET used_count = used_count + 1 WHERE code = ?",
                    [$couponCode]
                );
            }

            Database::commit();
        } catch (\Exception $e) {
            Database::rollback();
            appLog('error', 'Order placement failed', ['error' => $e->getMessage()]);
            Response::serverError('Order could not be placed. Please try again.');
        }

        $response = [
            'order_id'     => $orderId,
            'order_number' => $orderNumber,
            'status'       => 'pending',
            'pricing'      => $pricing,
        ];

        // For online payment, return Razorpay order details
        if ($payMethod === 'online') {
            $rzpOrder = PaymentService::createOrder($pricing['total_amount'], $orderNumber);
            PaymentService::createRecord(
                $orderId,
                $auth['sub'],
                $pricing['total_amount'],
                'razorpay',
                $rzpOrder['razorpay_order_id']
            );
            $response['payment'] = $rzpOrder;
        }

        Response::success($response, 'Order placed successfully.', 201);
    }

    // ── GET /order/:id ─────────────────────────────────────────────────────
    public function show(array $params): void
    {
        $auth    = AuthMiddleware::requireRole('customer');
        $orderId = (int) ($params['id'] ?? 0);

        $order = $this->order->getWithItems($orderId);
        if (!$order) {
            Response::notFound('Order not found.');
        }

        // Customers can only see their own orders; restaurant owners see their restaurant's
        if ($auth['role'] === 'customer' && (int) $order['user_id'] !== $auth['sub']) {
            Response::forbidden('Access denied.');
        }
        if ($auth['role'] === 'restaurant_owner') {
            $rest = $this->restaurant->getByOwner($auth['sub']);
            if (!$rest || (int) $order['restaurant_id'] !== (int) $rest['id']) {
                Response::forbidden('Access denied.');
            }
        }

        Response::success($order);
    }

    // ── GET /order/:id/status ─────────────────────────────────────────────
    // Lightweight polling endpoint — returns status + delivery location
    public function status(array $params): void
    {
        $orderId = (int) ($params['id'] ?? 0);

        $row = Database::fetchOne(
            "SELECT o.id, o.order_number, o.status, o.payment_status,
                    o.confirmed_at, o.preparing_at, o.ready_at, o.picked_at, o.delivered_at,
                    o.delivery_lat AS dest_lat, o.delivery_lng AS dest_lng,
                    do2.status AS delivery_status, do2.delivery_otp,
                    dp.current_lat AS partner_lat, dp.current_lng AS partner_lng,
                    dp_user.name AS partner_name, dp_user.phone AS partner_phone,
                    r.name AS restaurant_name, r.whatsapp_number AS restaurant_whatsapp
             FROM orders o
             LEFT JOIN delivery_orders do2 ON do2.order_id = o.id
             LEFT JOIN delivery_partners dp ON dp.id = do2.partner_id
             LEFT JOIN users dp_user ON dp_user.id = dp.user_id
             LEFT JOIN restaurants r ON r.id = o.restaurant_id
             WHERE o.id = ?",
            [$orderId]
        );

        if (!$row) {
            Response::notFound('Order not found.');
        }

        Response::success($row);
    }

    // ── GET /orders/history ────────────────────────────────────────────────
    public function history(array $params): void
    {
        $auth = AuthMiddleware::requireRole('customer');

        ['page' => $page, 'limit' => $limit, 'offset' => $offset] = getPagination(10);

        $orders = $this->order->getByUser($auth['sub'], $limit, $offset);
        $total  = Database::fetchOne(
            "SELECT COUNT(*) AS n FROM orders WHERE user_id = ?",
            [$auth['sub']]
        )['n'] ?? 0;

        Response::success($orders, 'Order history.', 200, Response::paginate((int)$total, $page, $limit));
    }

    // ── POST /order/:id/cancel ─────────────────────────────────────────────
    public function cancel(array $params): void
    {
        $auth    = AuthMiddleware::requireRole('customer');
        $orderId = (int) ($params['id'] ?? 0);
        $data    = getRequestData();

        $order = $this->order->find($orderId);
        if (!$order || (int) $order['user_id'] !== $auth['sub']) {
            Response::forbidden('Order not found.');
        }

        $cancelableStatuses = ['pending', 'confirmed'];
        if (!in_array($order['status'], $cancelableStatuses, true)) {
            Response::error('Order cannot be cancelled at this stage.', 400);
        }

        $this->order->update($orderId, [
            'status'             => 'cancelled',
            'cancellation_reason'=> $data['reason'] ?? 'Customer requested cancellation',
            'cancelled_by'       => 'customer',
        ]);

        Response::success(null, 'Order cancelled successfully.');
    }

    // ── POST /order/:id/reorder ────────────────────────────────────────────
    public function reorder(array $params): void
    {
        $auth    = AuthMiddleware::requireRole('customer');
        $orderId = (int) ($params['id'] ?? 0);

        $order = $this->order->getWithItems($orderId);
        if (!$order || (int) $order['user_id'] !== $auth['sub']) {
            Response::forbidden('Order not found.');
        }

        // Clear existing cart
        Database::execute("DELETE FROM cart_sessions WHERE user_id = ?", [$auth['sub']]);

        // Add items back to cart
        foreach ($order['items'] as $item) {
            $menuItem = $this->menuItem->find($item['menu_item_id']);
            if ($menuItem && $menuItem['is_available']) {
                Database::execute(
                    "INSERT INTO cart_sessions (user_id, restaurant_id, menu_item_id, quantity)
                     VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)",
                    [$auth['sub'], $order['restaurant_id'], $item['menu_item_id'], $item['quantity']]
                );
            }
        }

        Response::success(
            ['restaurant_id' => $order['restaurant_id']],
            'Items added to cart. Proceed to checkout.'
        );
    }

    // ── GET /restaurant-orders ─────────────────────────────────────────────
    public function restaurantOrders(array $params): void
    {
        $auth   = AuthMiddleware::requireRole('restaurant_owner');
        $status = $_GET['status'] ?? '';
        ['page' => $page, 'limit' => $limit, 'offset' => $offset] = getPagination(20);

        $rest = $this->restaurant->getByOwner($auth['sub']);
        if (!$rest) {
            Response::error('No restaurant found for this account.', 404);
        }

        $orders = $this->order->getByRestaurant((int) $rest['id'], $status, $limit, $offset);
        $total  = Database::fetchOne(
            "SELECT COUNT(*) AS n FROM orders WHERE restaurant_id = ?" . ($status ? " AND status = ?" : ""),
            $status ? [(int) $rest['id'], $status] : [(int) $rest['id']]
        )['n'] ?? 0;

        Response::success($orders, 'Orders fetched.', 200, Response::paginate((int)$total, $page, $limit));
    }

    // ── PATCH /order/:id/status ────────────────────────────────────────────
    public function updateStatus(array $params): void
    {
        $auth    = AuthMiddleware::requireAnyRole(['restaurant_owner', 'admin']);
        $orderId = (int) ($params['id'] ?? 0);
        $data    = getRequestData();

        $v = new Validator($data);
        $v->required(['status'])
          ->in('status', ['confirmed', 'preparing', 'ready', 'cancelled']);
        if ($v->fails()) {
            Response::validationError($v->errors());
        }

        $order = $this->order->find($orderId);
        if (!$order) {
            Response::notFound('Order not found.');
        }

        if ($auth['role'] === 'restaurant_owner') {
            $rest = $this->restaurant->getByOwner($auth['sub']);
            if (!$rest || (int) $order['restaurant_id'] !== (int) $rest['id']) {
                Response::forbidden('Access denied.');
            }
        }

        $this->order->updateStatus($orderId, $data['status']);
        Response::success(['status' => $data['status']], 'Order status updated.');
    }

    // ── Private helpers ────────────────────────────────────────────────────

    /**
     * Validate item array, ensure they belong to the restaurant, and compute total.
     * Returns [foodTotal, orderItems[]]
     */
    private function validateAndPriceItems(array $items, int $restaurantId): array
    {
        $foodTotal  = 0.0;
        $orderItems = [];

        foreach ($items as $row) {
            $itemId   = (int) ($row['menu_item_id'] ?? 0);
            $quantity = max(1, (int) ($row['quantity'] ?? 1));

            $item = $this->menuItem->find($itemId);
            if (!$item || !$item['is_available'] || (int) $item['restaurant_id'] !== $restaurantId) {
                Response::error("Item ID {$itemId} is not available at this restaurant.", 400);
            }

            $price    = (float) $item['price'];
            $subtotal = round($price * $quantity, 2);
            $foodTotal += $subtotal;

            $orderItems[] = [
                'menu_item_id' => $itemId,
                'name'         => $item['name'],
                'price'        => $price,
                'quantity'     => $quantity,
                'subtotal'     => $subtotal,
                'notes'        => $row['notes'] ?? null,
            ];
        }

        return [$foodTotal, $orderItems];
    }

    /**
     * Apply a coupon code and return the discount amount.
     */
    private function applyCoupon(string $code, int $userId, float $foodTotal, int $restaurantId): float
    {
        $coupon = Database::fetchOne(
            "SELECT * FROM coupons
             WHERE code = ? AND is_active = 1
               AND valid_from <= CURDATE() AND valid_until >= CURDATE()
               AND (restaurant_id IS NULL OR restaurant_id = ?)",
            [$code, $restaurantId]
        );

        if (!$coupon) {
            return 0.0;
        }

        // Minimum order check
        if ($foodTotal < (float) $coupon['min_order_amount']) {
            return 0.0;
        }

        // Usage limit
        if ($coupon['usage_limit'] && $coupon['used_count'] >= $coupon['usage_limit']) {
            return 0.0;
        }

        // Per-user limit
        $userUsage = Database::fetchOne(
            "SELECT COUNT(*) AS n FROM orders WHERE user_id = ? AND coupon_code = ?",
            [$userId, $code]
        );
        if ((int) ($userUsage['n'] ?? 0) >= $coupon['per_user_limit']) {
            return 0.0;
        }

        // Calculate discount
        if ($coupon['discount_type'] === 'percent') {
            $discount = ($foodTotal * (float) $coupon['discount_value']) / 100;
            if ($coupon['max_discount_amount']) {
                $discount = min($discount, (float) $coupon['max_discount_amount']);
            }
        } else {
            $discount = (float) $coupon['discount_value'];
        }

        return round(min($discount, $foodTotal), 2);
    }

    /**
     * Check if customer has an active Aharam Plus subscription (free delivery).
     */
    private function hasCustomerSubscription(int $userId): bool
    {
        $row = Database::fetchOne(
            "SELECT id FROM customer_subscriptions
             WHERE user_id = ? AND is_active = 1 AND expires_at >= CURDATE()",
            [$userId]
        );
        return (bool) $row;
    }
}
