<?php
declare(strict_types=1);

require_once __DIR__ . '/BaseModel.php';

class Restaurant extends BaseModel
{
    protected string $table = 'restaurants';
    protected array $fillable = [
        'owner_id', 'name', 'slug', 'description', 'cuisine_type', 'category',
        'logo_image', 'cover_image', 'address', 'city', 'pincode',
        'latitude', 'longitude', 'phone', 'email', 'gstin', 'fssai_number',
        'opening_time', 'closing_time', 'is_open', 'is_active', 'is_featured',
        'commission_percent', 'avg_delivery_time', 'min_order_amount', 'approval_status',
        'whatsapp_number', 'bank_account', 'ifsc_code', 'bank_holder_name',
    ];

    /**
     * Get all active restaurants near a city/pincode, with subscription info.
     */
    public function getNearby(
        string $city,
        string $pincode  = '',
        int    $limit    = 20,
        int    $offset   = 0,
        string $sortBy   = 'avg_rating',
        string $cuisine  = ''
    ): array {
        $allowed  = ['avg_rating', 'avg_delivery_time', 'min_order_amount', 'total_orders'];
        $orderCol = in_array($sortBy, $allowed, true) ? $sortBy : 'avg_rating';

        $filters = '';
        $params  = [];
        if ($city) {
            $filters .= ' AND (r.city = ?';
            $params[] = $city;
            if ($pincode) {
                $filters .= ' OR r.pincode = ?';
                $params[] = $pincode;
            }
            $filters .= ')';
        }
        if ($cuisine) {
            $filters .= ' AND FIND_IN_SET(?, REPLACE(r.cuisine_type, \', \', \',\'))';
            $params[] = $cuisine;
        }

        $params[] = $limit;
        $params[] = $offset;

        return $this->raw(
            "SELECT r.*,
                    CASE WHEN rs.id IS NOT NULL AND rs.expires_at >= CURDATE() THEN 1 ELSE 0 END AS has_subscription,
                    CASE WHEN rs.id IS NOT NULL AND rs.expires_at >= CURDATE() THEN rs.commission_percent
                         ELSE r.commission_percent END AS effective_commission,
                    r.is_open
             FROM restaurants r
             LEFT JOIN restaurant_subscriptions rs
                ON rs.restaurant_id = r.id AND rs.is_active = 1 AND rs.expires_at >= CURDATE()
             WHERE r.is_active = 1 $filters
             ORDER BY r.is_featured DESC, r.$orderCol DESC
             LIMIT ? OFFSET ?",
            $params
        );
    }

    /**
     * Count active restaurants in a city.
     */
    public function countNearby(string $city, string $pincode = '', string $cuisine = ''): int
    {
        $filters = '';
        $params  = [];
        if ($city) {
            $filters .= ' AND (city = ?';
            $params[] = $city;
            if ($pincode) {
                $filters .= ' OR pincode = ?';
                $params[] = $pincode;
            }
            $filters .= ')';
        }
        if ($cuisine) {
            $filters .= ' AND FIND_IN_SET(?, REPLACE(cuisine_type, \', \', \',\'))';
            $params[] = $cuisine;
        }
        $row = $this->rawOne(
            "SELECT COUNT(*) AS n FROM restaurants WHERE is_active = 1 $filters",
            $params
        );
        return (int) ($row['n'] ?? 0);
    }

    /**
     * Full restaurant detail with subscription info.
     */
    public function getDetail(int $id): array|false
    {
        return $this->rawOne(
            "SELECT r.*,
                    CASE WHEN rs.id IS NOT NULL AND rs.expires_at >= CURDATE() THEN 1 ELSE 0 END AS has_subscription,
                    rs.plan_name AS sub_plan, rs.expires_at AS sub_expires,
                    CASE WHEN rs.id IS NOT NULL AND rs.expires_at >= CURDATE() THEN rs.commission_percent
                         ELSE r.commission_percent END AS effective_commission,
                    r.is_open
             FROM restaurants r
             LEFT JOIN restaurant_subscriptions rs
                ON rs.restaurant_id = r.id AND rs.is_active = 1 AND rs.expires_at >= CURDATE()
             WHERE r.id = ?",
            [$id]
        );
    }

    /**
     * Get restaurant owned by a user.
     */
    public function getByOwner(int $userId): array|false
    {
        return $this->findBy('owner_id', $userId);
    }

    /**
     * Update aggregate rating after a new review.
     */
    public function updateRating(int $restaurantId, float $newRating): void
    {
        $this->execute(
            "UPDATE restaurants
             SET avg_rating    = ((avg_rating * total_ratings) + ?) / (total_ratings + 1),
                 total_ratings = total_ratings + 1
             WHERE id = ?",
            [$newRating, $restaurantId]
        );
    }

    /**
     * Increment total order count.
     */
    public function incrementOrders(int $restaurantId): void
    {
        $this->execute(
            "UPDATE restaurants SET total_orders = total_orders + 1 WHERE id = ?",
            [$restaurantId]
        );
    }

    public function search(string $query, string $city, int $limit = 20): array
    {
        $cityFilter = '';
        $params     = [];
        if ($city) {
            $cityFilter = 'AND r.city = ?';
            $params[]   = $city;
        }
        $like = "%$query%";
        $params = array_merge($params, [$like, $like, $like, $like, $limit]);

        return $this->raw(
            "SELECT DISTINCT r.*,
                    CASE WHEN rs.id IS NOT NULL AND rs.expires_at >= CURDATE() THEN 1 ELSE 0 END AS has_subscription,
                    r.is_open
             FROM restaurants r
             LEFT JOIN restaurant_subscriptions rs
                ON rs.restaurant_id = r.id AND rs.is_active = 1 AND rs.expires_at >= CURDATE()
             LEFT JOIN menu_items mi ON mi.restaurant_id = r.id AND mi.is_available = 1
             WHERE r.is_active = 1 $cityFilter
               AND (r.name LIKE ? OR r.cuisine_type LIKE ? OR r.description LIKE ? OR mi.name LIKE ?)
             ORDER BY r.avg_rating DESC
             LIMIT ?",
            $params
        );
    }

    public function getStats(int $restaurantId): array
    {
        return $this->rawOne(
            "SELECT
               (SELECT COUNT(*) FROM orders WHERE restaurant_id = ? AND status = 'delivered') AS total_orders,
               (SELECT SUM(food_total) FROM orders WHERE restaurant_id = ? AND status = 'delivered') AS total_food_revenue,
               (SELECT SUM(restaurant_payout) FROM orders WHERE restaurant_id = ? AND status = 'delivered') AS total_net_payout,
               (SELECT COUNT(*) FROM orders WHERE restaurant_id = ? AND status = 'delivered' AND DATE(created_at) = CURDATE()) AS today_orders,
               (SELECT SUM(food_total) FROM orders WHERE restaurant_id = ? AND status = 'delivered' AND DATE(created_at) = CURDATE()) AS today_revenue,
               (SELECT COUNT(*) FROM orders WHERE restaurant_id = ? AND status = 'pending') AS pending_orders,
               (SELECT AVG(food_rating) FROM reviews WHERE restaurant_id = ?) AS avg_rating",
            array_fill(0, 7, $restaurantId)
        );
    }
}
