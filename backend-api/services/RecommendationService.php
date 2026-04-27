<?php
/**
 * RecommendationService.php — Rule-based recommendation engine
 *
 * Provides personalised food and restaurant recommendations using:
 *  1. Time-based suggestions (breakfast/lunch/dinner)
 *  2. Previous order history (personalised)
 *  3. Trending / most-ordered items (popularity)
 *  4. Reorder suggestions from past orders
 *
 * No ML/AI required — pure rule-based logic that works on shared hosting.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../utils/Helper.php';

class RecommendationService
{
    /**
     * Main entry: get personalised recommendations for a user.
     *
     * @param int    $userId   0 for guest
     * @param string $city     Customer's city
     * @return array{
     *   meal_period: string,
     *   suggested_restaurants: array,
     *   trending_items: array,
     *   reorder_suggestion: array|null,
     *   based_on_history: array
     * }
     */
    public static function forUser(int $userId, string $city): array
    {
        $mealPeriod  = getMealPeriod();
        $restaurants = self::getTimeBasedRestaurants($city, $mealPeriod);
        $trending    = self::getTrendingItems($city);
        $history     = $userId ? self::getHistoryBasedItems($userId) : [];
        $reorder     = $userId ? self::getReorderSuggestion($userId) : null;

        return [
            'meal_period'           => $mealPeriod,
            'suggested_restaurants' => $restaurants,
            'trending_items'        => $trending,
            'reorder_suggestion'    => $reorder,
            'based_on_history'      => $history,
        ];
    }

    /**
     * Time-based restaurant suggestions.
     *
     * Maps meal periods to cuisine types the system promotes.
     */
    public static function getTimeBasedRestaurants(string $city, string $mealPeriod): array
    {
        $cuisineHints = match ($mealPeriod) {
            'breakfast'  => ['South Indian', 'Tiffins', 'Breakfast'],
            'lunch'      => ['Rice', 'North Indian', 'South Indian', 'Meals'],
            'snack'      => ['Snacks', 'Fast Food', 'Chinese', 'Bakery'],
            'dinner'     => ['North Indian', 'Biryani', 'Chinese', 'Multi-cuisine'],
            'late_night' => ['Fast Food', 'Biryani', 'Pizza'],
            default      => [],
        };

        if (empty($cuisineHints)) {
            // Generic fallback
            return Database::fetchAll(
                "SELECT r.id, r.name, r.cuisine_type, r.avg_rating, r.logo_image, r.avg_delivery_time, r.is_open AS is_currently_open
                 FROM restaurants r
                 WHERE r.is_active = 1 AND r.city = ?
                   AND r.is_open = 1
                 ORDER BY r.avg_rating DESC LIMIT 6",
                [$city]
            );
        }

        // Build a LIKE query for each cuisine hint
        $likes  = [];
        $params = [$city];
        foreach ($cuisineHints as $hint) {
            $likes[]  = "r.cuisine_type LIKE ?";
            $params[] = "%$hint%";
        }
        $likeStr = implode(' OR ', $likes);

        return Database::fetchAll(
            "SELECT r.id, r.name, r.cuisine_type, r.avg_rating, r.logo_image, r.avg_delivery_time, r.is_open AS is_currently_open
             FROM restaurants r
             WHERE r.is_active = 1 AND r.city = ?
               AND ($likeStr)
               AND r.is_open = 1
             ORDER BY r.avg_rating DESC LIMIT 6",
            $params
        );
    }

    /**
     * Platform-wide trending items (most ordered in the last 7 days).
     */
    public static function getTrendingItems(string $city, int $limit = 10): array
    {
        return Database::fetchAll(
            "SELECT mi.id, mi.name, mi.price, mi.image, mi.food_type,
                    r.name AS restaurant_name, r.id AS restaurant_id,
                    COUNT(oi.id) AS order_count
             FROM menu_items mi
             JOIN order_items oi ON oi.menu_item_id = mi.id
             JOIN orders o ON o.id = oi.order_id
             JOIN restaurants r ON r.id = mi.restaurant_id
             WHERE r.city = ?
               AND o.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
               AND o.status = 'delivered'
               AND mi.is_available = 1
             GROUP BY mi.id
             ORDER BY order_count DESC
             LIMIT ?",
            [$city, $limit]
        );
    }

    /**
     * Suggest items based on the customer's previous order history.
     * Returns cuisines/items they ordered before.
     */
    public static function getHistoryBasedItems(int $userId, int $limit = 5): array
    {
        // Find most frequently ordered menu items
        return Database::fetchAll(
            "SELECT mi.id, mi.name, mi.price, mi.image, mi.food_type,
                    r.name AS restaurant_name, r.id AS restaurant_id,
                    COUNT(oi.id) AS times_ordered
             FROM order_items oi
             JOIN orders o ON o.id = oi.order_id
             JOIN menu_items mi ON mi.id = oi.menu_item_id
             JOIN restaurants r ON r.id = mi.restaurant_id
             WHERE o.user_id = ?
               AND o.status = 'delivered'
               AND mi.is_available = 1
               AND r.is_active = 1
             GROUP BY mi.id
             ORDER BY times_ordered DESC
             LIMIT ?",
            [$userId, $limit]
        );
    }

    /**
     * Reorder suggestion — the user's most recent completed order.
     */
    public static function getReorderSuggestion(int $userId): ?array
    {
        $order = Database::fetchOne(
            "SELECT o.id AS order_id, o.order_number, o.total_amount,
                    r.id AS restaurant_id, r.name AS restaurant_name, r.logo_image
             FROM orders o
             JOIN restaurants r ON r.id = o.restaurant_id
             WHERE o.user_id = ? AND o.status = 'delivered'
             ORDER BY o.created_at DESC LIMIT 1",
            [$userId]
        );

        if (!$order) {
            return null;
        }

        $items = Database::fetchAll(
            "SELECT oi.name, oi.quantity, oi.price, mi.image
             FROM order_items oi
             LEFT JOIN menu_items mi ON mi.id = oi.menu_item_id
             WHERE oi.order_id = ?",
            [$order['order_id']]
        );

        $order['items'] = $items;
        return $order;
    }

    /**
     * Similar restaurants to one currently viewing.
     */
    public static function getSimilar(int $restaurantId, string $city): array
    {
        $rest = Database::fetchOne(
            "SELECT cuisine_type FROM restaurants WHERE id = ?",
            [$restaurantId]
        );

        if (!$rest || !$rest['cuisine_type']) {
            return [];
        }

        $cuisine = explode(',', $rest['cuisine_type'])[0] ?? '';

        return Database::fetchAll(
            "SELECT id, name, cuisine_type, avg_rating, logo_image, avg_delivery_time
             FROM restaurants
             WHERE is_active = 1 AND city = ? AND cuisine_type LIKE ? AND id != ?
             ORDER BY avg_rating DESC LIMIT 4",
            [$city, "%$cuisine%", $restaurantId]
        );
    }
}
