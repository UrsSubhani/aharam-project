<?php
declare(strict_types=1);

require_once __DIR__ . '/../models/Restaurant.php';
require_once __DIR__ . '/../middlewares/AuthMiddleware.php';
require_once __DIR__ . '/../utils/Response.php';
require_once __DIR__ . '/../utils/Validator.php';

class ReviewController
{
    // POST /review
    public function create(array $params): void
    {
        $auth = AuthMiddleware::requireRole('customer');
        $data = getRequestData();

        $data['food_rating'] = (string) ((int) ($data['food_rating'] ?? 0));

        $v = new Validator($data);
        $v->required(['order_id', 'food_rating'])
          ->numeric('food_rating')
          ->in('food_rating', ['1','2','3','4','5']);
        if ($v->fails()) {
            Response::validationError($v->errors());
        }

        $orderId = (int) $data['order_id'];

        $order = Database::fetchOne(
            "SELECT * FROM orders WHERE id = ? AND user_id = ? AND status = 'delivered'",
            [$orderId, $auth['sub']]
        );
        if (!$order) {
            Response::error('You can only review delivered orders.', 400);
        }

        $exists = Database::fetchOne("SELECT id FROM reviews WHERE order_id = ?", [$orderId]);
        if ($exists) {
            Response::error('You have already reviewed this order.', 409);
        }

        Database::execute(
            "INSERT INTO reviews (order_id, user_id, restaurant_id, food_rating, delivery_rating, review_text)
             VALUES (?, ?, ?, ?, ?, ?)",
            [
                $orderId,
                $auth['sub'],
                $order['restaurant_id'],
                (int) $data['food_rating'],
                !empty($data['delivery_rating']) ? (int) $data['delivery_rating'] : null,
                $data['review_text'] ?? null,
            ]
        );

        // Update restaurant rating
        (new Restaurant())->updateRating((int) $order['restaurant_id'], (float) $data['food_rating']);

        Response::success(null, 'Thank you for your review!', 201);
    }

    // GET /reviews/:restaurant_id
    public function restaurantReviews(array $params): void
    {
        $restaurantId = (int) ($params['restaurant_id'] ?? 0);

        $reviews = Database::fetchAll(
            "SELECT r.food_rating, r.delivery_rating, r.review_text, r.created_at,
                    u.name AS customer_name,
                    GROUP_CONCAT(CONCAT(oi.name, '×', oi.quantity) ORDER BY oi.id SEPARATOR '||') AS items_summary
             FROM reviews r
             JOIN users u ON u.id = r.user_id
             LEFT JOIN order_items oi ON oi.order_id = r.order_id
             WHERE r.restaurant_id = ?
             GROUP BY r.id
             ORDER BY r.created_at DESC LIMIT 20",
            [$restaurantId]
        );

        // Parse items into array
        foreach ($reviews as &$rev) {
            $names = $rev['items_summary'] ? explode('||', $rev['items_summary']) : [];
            $items = [];
            foreach ($names as $nameQty) {
                [$name, $qty] = explode('×', $nameQty, 2) + ['', '1'];
                $items[] = ['name' => $name, 'qty' => (int) $qty];
            }
            $rev['items'] = $items;
            unset($rev['items_summary']);
        }
        unset($rev);

        Response::success($reviews);
    }
}
