<?php
declare(strict_types=1);

require_once __DIR__ . '/BaseModel.php';

class MenuItem extends BaseModel
{
    protected string $table = 'menu_items';
    protected array $fillable = [
        'restaurant_id', 'category_id', 'name', 'description', 'price',
        'discount_price', 'image', 'food_type', 'is_available', 'is_featured',
        'is_bestseller', 'tags', 'calories', 'prep_time', 'sort_order',
    ];

    /**
     * Get full menu for a restaurant grouped by category.
     */
    public function getMenuGrouped(int $restaurantId, bool $allItems = false): array
    {
        // Fetch global + restaurant-specific categories
        $categories = $this->raw(
            "SELECT * FROM menu_categories WHERE (restaurant_id IS NULL OR restaurant_id = ?) AND is_active = 1 ORDER BY sort_order ASC, name ASC",
            [$restaurantId]
        );

        // Fetch items — owner sees all, customers see only available
        $availFilter = $allItems ? '' : 'AND mi.is_available = 1';
        $items = $this->raw(
            "SELECT mi.*, mc.name AS category_name
             FROM menu_items mi
             LEFT JOIN menu_categories mc ON mc.id = mi.category_id
             WHERE mi.restaurant_id = ? $availFilter
             ORDER BY mi.sort_order ASC, mi.name ASC",
            [$restaurantId]
        );

        // Group items under categories
        $grouped = [];
        $uncategorised = [];

        foreach ($categories as $cat) {
            $grouped[$cat['id']] = [
                'id'    => $cat['id'],
                'name'  => $cat['name'],
                'items' => [],
            ];
        }

        foreach ($items as $item) {
            if ($item['category_id'] && isset($grouped[$item['category_id']])) {
                $grouped[$item['category_id']]['items'][] = $item;
            } else {
                $uncategorised[] = $item;
            }
        }

        $result = array_values($grouped);

        if (!empty($uncategorised)) {
            $result[] = [
                'id'    => null,
                'name'  => 'Other',
                'items' => $uncategorised,
            ];
        }

        // Remove empty categories
        return array_filter($result, fn($c) => !empty($c['items']));
    }

    /**
     * Get flat list of all items for a restaurant (admin/owner view).
     */
    public function getByRestaurant(int $restaurantId): array
    {
        return $this->raw(
            "SELECT mi.*, mc.name AS category_name
             FROM menu_items mi
             LEFT JOIN menu_categories mc ON mc.id = mi.category_id
             WHERE mi.restaurant_id = ?
             ORDER BY mc.sort_order ASC, mi.sort_order ASC",
            [$restaurantId]
        );
    }

    /**
     * Get bestsellers for a restaurant.
     */
    public function getBestsellers(int $restaurantId, int $limit = 5): array
    {
        return $this->raw(
            "SELECT * FROM menu_items
             WHERE restaurant_id = ? AND is_available = 1
             ORDER BY total_orders DESC LIMIT ?",
            [$restaurantId, $limit]
        );
    }

    /**
     * Increment order count when item is ordered.
     */
    public function incrementOrders(int $itemId, int $quantity = 1): void
    {
        $this->execute(
            "UPDATE menu_items SET total_orders = total_orders + ? WHERE id = ?",
            [$quantity, $itemId]
        );
    }

    /**
     * Validate that all item IDs belong to the given restaurant.
     */
    public function validateItemsForRestaurant(array $itemIds, int $restaurantId): bool
    {
        if (empty($itemIds)) {
            return false;
        }
        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        $params = $itemIds;
        $params[] = $restaurantId;
        $row = $this->rawOne(
            "SELECT COUNT(*) AS n FROM menu_items WHERE id IN ($placeholders) AND restaurant_id = ? AND is_available = 1",
            $params
        );
        return (int) ($row['n'] ?? 0) === count($itemIds);
    }
}
