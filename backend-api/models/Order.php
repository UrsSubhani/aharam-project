<?php
declare(strict_types=1);

require_once __DIR__ . '/BaseModel.php';

class Order extends BaseModel
{
    protected string $table = 'orders';
    protected array $fillable = [
        'order_number', 'user_id', 'restaurant_id', 'delivery_address_id',
        'delivery_address_text', 'delivery_lat', 'delivery_lng',
        'status', 'order_type', 'food_total', 'discount_amount', 'coupon_code',
        'coupon_discount', 'delivery_fee', 'platform_fee', 'gst_amount',
        'total_amount', 'commission_percent', 'commission_amount',
        'restaurant_payout', 'subscription_applied', 'payment_method',
        'wallet_amount', 'payment_status', 'special_instructions',
        'cancellation_reason', 'cancelled_by',
    ];

    /**
     * Full order with items and restaurant info.
     */
    public function getWithItems(int $orderId): array|false
    {
        $order = $this->rawOne(
            "SELECT o.*,
                    r.name AS restaurant_name, r.logo_image AS restaurant_logo,
                    r.phone AS restaurant_phone, r.address AS restaurant_address,
                    u.name AS customer_name, u.phone AS customer_phone
             FROM orders o
             JOIN restaurants r ON r.id = o.restaurant_id
             JOIN users u ON u.id = o.user_id
             WHERE o.id = ?",
            [$orderId]
        );

        if (!$order) {
            return false;
        }

        $order['items'] = $this->raw(
            "SELECT oi.*, mi.image AS item_image
             FROM order_items oi
             LEFT JOIN menu_items mi ON mi.id = oi.menu_item_id
             WHERE oi.order_id = ?",
            [$orderId]
        );

        return $order;
    }

    /**
     * Customer order history with pagination.
     */
    public function getByUser(int $userId, int $limit = 20, int $offset = 0): array
    {
        return $this->raw(
            "SELECT o.id, o.order_number, o.status, o.total_amount,
                    o.payment_method, o.payment_status, o.created_at,
                    r.name AS restaurant_name, r.logo_image,
                    (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) AS item_count
             FROM orders o
             JOIN restaurants r ON r.id = o.restaurant_id
             WHERE o.user_id = ?
             ORDER BY o.created_at DESC
             LIMIT ? OFFSET ?",
            [$userId, $limit, $offset]
        );
    }

    /**
     * Restaurant owner: get their orders.
     */
    public function getByRestaurant(
        int    $restaurantId,
        string $status  = '',
        int    $limit   = 20,
        int    $offset  = 0
    ): array {
        $params  = [$restaurantId];
        $statusFilter = '';
        if ($status) {
            $statusFilter = " AND o.status = ?";
            $params[] = $status;
        }
        $params[] = $limit;
        $params[] = $offset;

        return $this->raw(
            "SELECT o.id, o.order_number, o.status, o.food_total, o.total_amount, o.payment_method,
                    o.payment_status, o.special_instructions, o.created_at,
                    u.name AS customer_name, u.phone AS customer_phone,
                    o.delivery_address_text,
                    do2.pickup_otp
             FROM orders o
             JOIN users u ON u.id = o.user_id
             LEFT JOIN delivery_orders do2 ON do2.order_id = o.id
             WHERE o.restaurant_id = ?$statusFilter
             ORDER BY o.created_at DESC
             LIMIT ? OFFSET ?",
            $params
        );
    }

    /**
     * Update order status and set the corresponding timestamp.
     */
    public function updateStatus(int $orderId, string $status): void
    {
        $tsMap = [
            'confirmed'  => 'confirmed_at',
            'preparing'  => 'preparing_at',
            'ready'      => 'ready_at',
            'picked'     => 'picked_at',
            'on_the_way' => 'picked_at',
            'delivered'  => 'delivered_at',
            'cancelled'  => 'cancelled_at',
        ];

        $extra = '';
        if (isset($tsMap[$status])) {
            $extra = ", {$tsMap[$status]} = NOW()";
        }

        $this->execute(
            "UPDATE orders SET status = ?$extra, updated_at = NOW() WHERE id = ?",
            [$status, $orderId]
        );
    }

    /**
     * Get live tracking info for a customer.
     */
    public function getTrackingInfo(int $orderId, int $userId): array|false
    {
        return $this->rawOne(
            "SELECT o.id, o.order_number, o.status, o.created_at,
                    o.confirmed_at, o.preparing_at, o.ready_at, o.picked_at, o.delivered_at,
                    r.name AS restaurant_name, r.address AS restaurant_address,
                    r.avg_delivery_time,
                    dp_user.name AS partner_name, dp_user.phone AS partner_phone,
                    dp.current_lat AS partner_lat, dp.current_lng AS partner_lng,
                    do2.status AS delivery_status, do2.pickup_otp, do2.delivery_otp
             FROM orders o
             JOIN restaurants r ON r.id = o.restaurant_id
             LEFT JOIN delivery_orders do2 ON do2.order_id = o.id
             LEFT JOIN delivery_partners dp ON dp.id = do2.partner_id
             LEFT JOIN users dp_user ON dp_user.id = dp.user_id
             WHERE o.id = ? AND o.user_id = ?",
            [$orderId, $userId]
        );
    }

    /**
     * Get last order for a user (for reorder feature).
     */
    public function getLastOrder(int $userId, int $restaurantId): array|false
    {
        return $this->rawOne(
            "SELECT o.* FROM orders o
             WHERE o.user_id = ? AND o.restaurant_id = ? AND o.status = 'delivered'
             ORDER BY o.created_at DESC LIMIT 1",
            [$userId, $restaurantId]
        );
    }

    /**
     * Admin: all orders with filters.
     */
    public function getAll(
        array  $filters = [],
        int    $limit   = 20,
        int    $offset  = 0
    ): array {
        $where  = [];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 'o.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'DATE(o.created_at) >= ?';
            $params[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'DATE(o.created_at) <= ?';
            $params[] = $filters['date_to'];
        }

        $whereStr = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $params[] = $limit;
        $params[] = $offset;

        return $this->raw(
            "SELECT o.id, o.order_number, o.status, o.total_amount,
                    o.commission_amount, o.payment_status, o.created_at,
                    u.name AS customer_name, r.name AS restaurant_name
             FROM orders o
             JOIN users u ON u.id = o.user_id
             JOIN restaurants r ON r.id = o.restaurant_id
             $whereStr
             ORDER BY o.created_at DESC
             LIMIT ? OFFSET ?",
            $params
        );
    }

    /**
     * Admin dashboard stats.
     */
    public function getPlatformStats(): array
    {
        return $this->rawOne(
            "SELECT
               COUNT(*) AS total_orders,
               SUM(status = 'delivered') AS delivered,
               SUM(status = 'cancelled') AS cancelled,
               SUM(status = 'pending') AS pending,
               SUM(total_amount) AS gross_revenue,
               SUM(commission_amount) AS total_commission,
               SUM(DATE(created_at) = CURDATE()) AS today_orders,
               SUM(CASE WHEN DATE(created_at) = CURDATE() THEN total_amount ELSE 0 END) AS today_revenue
             FROM orders"
        );
    }
}
