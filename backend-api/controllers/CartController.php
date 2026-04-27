<?php
/**
 * CartController.php — Server-side cart management
 *
 * Cart is tied to a user account — survives page refreshes.
 * All items in cart must belong to the same restaurant.
 *
 * GET    /cart               - Get cart contents
 * POST   /cart/add           - Add item to cart
 * PUT    /cart/update        - Update item quantity
 * DELETE /cart/remove/:id    - Remove single item
 * DELETE /cart/clear         - Clear entire cart
 */

declare(strict_types=1);

require_once __DIR__ . '/../models/MenuItem.php';
require_once __DIR__ . '/../middlewares/AuthMiddleware.php';
require_once __DIR__ . '/../utils/Response.php';
require_once __DIR__ . '/../utils/Validator.php';
require_once __DIR__ . '/../utils/Helper.php';

class CartController
{
    // ── GET /cart ──────────────────────────────────────────────────────────
    public function index(array $params): void
    {
        $auth = AuthMiddleware::requireAuth();
        $cart = $this->getCartWithTotals($auth['sub']);
        Response::success($cart);
    }

    // ── POST /cart/add ─────────────────────────────────────────────────────
    public function add(array $params): void
    {
        $auth = AuthMiddleware::requireAuth();
        $data = getRequestData();

        $v = new Validator($data);
        $v->required(['menu_item_id'])
          ->numeric('menu_item_id')
          ->numeric('quantity');

        if ($v->fails()) {
            Response::validationError($v->errors());
        }

        $menuItemId = (int) $data['menu_item_id'];
        $quantity   = max(1, (int) ($data['quantity'] ?? 1));

        $item = (new MenuItem())->find($menuItemId);
        if (!$item || !$item['is_available']) {
            Response::notFound('Menu item not found or unavailable.');
        }

        // Check if cart already has items from a different restaurant
        $existingRestaurant = Database::fetchOne(
            "SELECT restaurant_id FROM cart_sessions WHERE user_id = ? LIMIT 1",
            [$auth['sub']]
        );

        if ($existingRestaurant && (int) $existingRestaurant['restaurant_id'] !== (int) $item['restaurant_id']) {
            Response::error(
                'Your cart has items from another restaurant. Clear the cart first to add items from this restaurant.',
                409
            );
        }

        // Check cart size limit
        $cartCount = Database::fetchOne(
            "SELECT SUM(quantity) AS total FROM cart_sessions WHERE user_id = ?",
            [$auth['sub']]
        );

        if (($cartCount['total'] ?? 0) + $quantity > 20) {
            Response::error('Cart limit reached (max 20 items).', 400);
        }

        // Upsert: if item already in cart, increment quantity
        Database::execute(
            "INSERT INTO cart_sessions (user_id, restaurant_id, menu_item_id, quantity, notes)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               quantity = quantity + VALUES(quantity),
               notes    = VALUES(notes)",
            [
                $auth['sub'],
                $item['restaurant_id'],
                $menuItemId,
                $quantity,
                $data['notes'] ?? null,
            ]
        );

        $cart = $this->getCartWithTotals($auth['sub']);
        Response::success($cart, 'Item added to cart.');
    }

    // ── PUT /cart/update ───────────────────────────────────────────────────
    public function update(array $params): void
    {
        $auth = AuthMiddleware::requireAuth();
        $data = getRequestData();

        $v = new Validator($data);
        $v->required(['menu_item_id', 'quantity'])
          ->numeric('menu_item_id')
          ->numeric('quantity');

        if ($v->fails()) {
            Response::validationError($v->errors());
        }

        $menuItemId = (int) $data['menu_item_id'];
        $quantity   = (int) $data['quantity'];

        if ($quantity <= 0) {
            // Remove item
            Database::execute(
                "DELETE FROM cart_sessions WHERE user_id = ? AND menu_item_id = ?",
                [$auth['sub'], $menuItemId]
            );
        } else {
            Database::execute(
                "UPDATE cart_sessions SET quantity = ? WHERE user_id = ? AND menu_item_id = ?",
                [$quantity, $auth['sub'], $menuItemId]
            );
        }

        $cart = $this->getCartWithTotals($auth['sub']);
        Response::success($cart, 'Cart updated.');
    }

    // ── DELETE /cart/remove/:id ────────────────────────────────────────────
    public function remove(array $params): void
    {
        $auth       = AuthMiddleware::requireAuth();
        $menuItemId = (int) ($params['id'] ?? 0);

        Database::execute(
            "DELETE FROM cart_sessions WHERE user_id = ? AND menu_item_id = ?",
            [$auth['sub'], $menuItemId]
        );

        $cart = $this->getCartWithTotals($auth['sub']);
        Response::success($cart, 'Item removed from cart.');
    }

    // ── DELETE /cart/clear ─────────────────────────────────────────────────
    public function clear(array $params): void
    {
        $auth = AuthMiddleware::requireAuth();

        Database::execute(
            "DELETE FROM cart_sessions WHERE user_id = ?",
            [$auth['sub']]
        );

        Response::success(['items' => [], 'total' => 0], 'Cart cleared.');
    }

    // ── Private: build cart with price totals ─────────────────────────────
    private function getCartWithTotals(int $userId): array
    {
        $items = Database::fetchAll(
            "SELECT cs.id, cs.menu_item_id, cs.quantity, cs.notes,
                    mi.name, mi.price, mi.image, mi.food_type, mi.is_available,
                    (mi.price * cs.quantity) AS subtotal,
                    r.id AS restaurant_id, r.name AS restaurant_name,
                    r.min_order_amount, r.avg_delivery_time
             FROM cart_sessions cs
             JOIN menu_items mi ON mi.id = cs.menu_item_id
             JOIN restaurants r  ON r.id  = cs.restaurant_id
             WHERE cs.user_id = ?
             ORDER BY cs.created_at ASC",
            [$userId]
        );

        $foodTotal    = array_sum(array_column($items, 'subtotal'));
        $itemCount    = array_sum(array_column($items, 'quantity'));
        $restaurantId = !empty($items) ? $items[0]['restaurant_id'] : null;
        $minOrder     = !empty($items) ? (float) $items[0]['min_order_amount'] : 0;

        return [
            'items'         => $items,
            'item_count'    => $itemCount,
            'food_total'    => round($foodTotal, 2),
            'restaurant_id' => $restaurantId,
            'min_order_met' => $foodTotal >= $minOrder,
            'min_order'     => $minOrder,
        ];
    }
}
