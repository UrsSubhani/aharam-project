<?php
/**
 * MenuController.php
 *
 * GET    /menu/:restaurant_id              - Full menu grouped by category (public)
 * GET    /menu/:restaurant_id/item/:id     - Single item detail (public)
 * POST   /menu                             - Add menu item (restaurant_owner)
 * PUT    /menu/:id                         - Update item (restaurant_owner)
 * DELETE /menu/:id                         - Delete item (restaurant_owner)
 * PATCH  /menu/:id/toggle                  - Toggle availability (restaurant_owner)
 * GET    /menu-categories/:restaurant_id   - List categories (public)
 * POST   /menu-categories                  - Create category (restaurant_owner)
 */

declare(strict_types=1);

require_once __DIR__ . '/../models/MenuItem.php';
require_once __DIR__ . '/../models/Restaurant.php';
require_once __DIR__ . '/../middlewares/AuthMiddleware.php';
require_once __DIR__ . '/../utils/Response.php';
require_once __DIR__ . '/../utils/Validator.php';
require_once __DIR__ . '/../utils/Helper.php';

class MenuController
{
    private MenuItem   $menuItem;
    private Restaurant $restaurant;

    public function __construct()
    {
        $this->menuItem   = new MenuItem();
        $this->restaurant = new Restaurant();
    }

    // ── GET /menu/:restaurant_id ───────────────────────────────────────────
    public function index(array $params): void
    {
        $restaurantId = (int) ($params['restaurant_id'] ?? 0);

        $rest = $this->restaurant->find($restaurantId);
        if (!$rest) {
            Response::notFound('Restaurant not found.');
        }

        // Owner sees all items (including unavailable); customers see only available
        $allItems = false;
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if ($authHeader) {
            try {
                $auth = AuthMiddleware::optionalAuth();
                if ($auth && $auth['role'] === 'restaurant_owner' && (int)$rest['owner_id'] === (int)$auth['sub']) {
                    $allItems = true;
                }
            } catch (\Throwable $e) { /* not authenticated — show only available */ }
        }

        $menu = $this->menuItem->getMenuGrouped($restaurantId, $allItems);

        Response::success([
            'restaurant_id' => $restaurantId,
            'categories'    => array_values($menu),
        ]);
    }

    // ── GET /menu/:restaurant_id/item/:id ─────────────────────────────────
    public function show(array $params): void
    {
        $id   = (int) ($params['id'] ?? 0);
        $item = $this->menuItem->find($id);

        if (!$item) {
            Response::notFound('Menu item not found.');
        }

        Response::success($item);
    }

    // ── POST /menu ─────────────────────────────────────────────────────────
    public function create(array $params): void
    {
        $auth = AuthMiddleware::requireRole('restaurant_owner');
        $data = getRequestData();

        $v = new Validator($data);
        $v->required(['restaurant_id', 'name', 'price'])
          ->numeric('price')
          ->positive('price')
          ->in('food_type', ['veg', 'non_veg', 'egg', 'vegan']);

        if ($v->fails()) {
            Response::validationError($v->errors());
        }

        // Verify ownership
        $rest = $this->restaurant->find((int) $data['restaurant_id']);
        if (!$rest || (int) $rest['owner_id'] !== $auth['sub']) {
            Response::forbidden('You do not own this restaurant.');
        }

        $id = $this->menuItem->create([
            'restaurant_id' => (int) $data['restaurant_id'],
            'category_id'   => !empty($data['category_id']) ? (int) $data['category_id'] : null,
            'name'          => $data['name'],
            'description'   => $data['description'] ?? '',
            'price'         => (float) $data['price'],
            'discount_price'=> !empty($data['discount_price']) ? (float) $data['discount_price'] : null,
            'image'         => $data['image'] ?? null,
            'food_type'     => $data['food_type'] ?? 'veg',
            'tags'          => $data['tags'] ?? null,
            'calories'      => !empty($data['calories']) ? (int) $data['calories'] : null,
            'prep_time'     => !empty($data['prep_time']) ? (int) $data['prep_time'] : null,
            'is_featured'   => !empty($data['is_featured']) ? 1 : 0,
            'sort_order'    => (int) ($data['sort_order'] ?? 0),
        ]);

        Response::success(['item_id' => $id], 'Menu item created.', 201);
    }

    // ── PUT /menu/:id ─────────────────────────────────────────────────────
    public function update(array $params): void
    {
        $auth = AuthMiddleware::requireRole('restaurant_owner');
        $id   = (int) ($params['id'] ?? 0);
        $data = getRequestData();

        $item = $this->menuItem->find($id);
        if (!$item) {
            Response::notFound('Menu item not found.');
        }

        $rest = $this->restaurant->find($item['restaurant_id']);
        if (!$rest || (int) $rest['owner_id'] !== $auth['sub']) {
            Response::forbidden('Access denied.');
        }

        $allowed = [
            'category_id', 'name', 'description', 'price', 'discount_price',
            'food_type', 'tags', 'calories', 'prep_time', 'is_featured',
            'is_bestseller', 'sort_order', 'image',
        ];

        $this->menuItem->update($id, array_intersect_key($data, array_flip($allowed)));
        Response::success(null, 'Menu item updated.');
    }

    // ── DELETE /menu/:id ──────────────────────────────────────────────────
    public function delete(array $params): void
    {
        $auth = AuthMiddleware::requireRole('restaurant_owner');
        $id   = (int) ($params['id'] ?? 0);

        $item = $this->menuItem->find($id);
        if (!$item) {
            Response::notFound('Menu item not found.');
        }

        $rest = $this->restaurant->find($item['restaurant_id']);
        if (!$rest || (int) $rest['owner_id'] !== $auth['sub']) {
            Response::forbidden('Access denied.');
        }

        $this->menuItem->delete($id);
        Response::success(null, 'Menu item deleted.');
    }

    // ── PATCH /menu/:id/toggle ─────────────────────────────────────────────
    public function toggleAvailability(array $params): void
    {
        $auth = AuthMiddleware::requireRole('restaurant_owner');
        $id   = (int) ($params['id'] ?? 0);

        $item = $this->menuItem->find($id);
        if (!$item) {
            Response::notFound('Menu item not found.');
        }

        $rest = $this->restaurant->find($item['restaurant_id']);
        if (!$rest || (int) $rest['owner_id'] !== $auth['sub']) {
            Response::forbidden('Access denied.');
        }

        $newStatus = $item['is_available'] ? 0 : 1;
        $this->menuItem->update($id, ['is_available' => $newStatus]);

        Response::success(
            ['is_available' => (bool) $newStatus],
            $newStatus ? 'Item marked as available.' : 'Item marked as unavailable.'
        );
    }

    // ── GET /menu-categories  (global — no restaurant filter) ────────────
    public function categories(array $params): void
    {
        $categories = Database::fetchAll(
            "SELECT * FROM menu_categories WHERE restaurant_id IS NULL AND is_active = 1 ORDER BY sort_order ASC, name ASC"
        );

        Response::success($categories);
    }

    // ── POST /menu-categories  (admin only — creates global category) ─────
    public function createCategory(array $params): void
    {
        AuthMiddleware::requireRole('admin');
        $data = getRequestData();

        $v = new Validator($data);
        $v->required(['name']);
        if ($v->fails()) {
            Response::validationError($v->errors());
        }

        // Prevent duplicates
        $existing = Database::fetchOne(
            "SELECT id FROM menu_categories WHERE restaurant_id IS NULL AND name = ?",
            [trim($data['name'])]
        );
        if ($existing) {
            Response::error('A category with this name already exists.', 409);
        }

        Database::execute(
            "INSERT INTO menu_categories (restaurant_id, name, sort_order) VALUES (NULL, ?, ?)",
            [trim($data['name']), (int) ($data['sort_order'] ?? 0)]
        );

        Response::success(
            ['category_id' => (int) Database::lastInsertId()],
            'Category created.',
            201
        );
    }

    // ── GET /items/search?q=dosa&city=Hyderabad ──────────────────────────
    public function searchItems(array $params): void
    {
        $q    = trim($_GET['q']    ?? '');
        $city = trim($_GET['city'] ?? '');

        if (!$q) {
            Response::success([]);
        }

        $like     = "%$q%";
        $cityJoin = '';
        $params   = [];
        if ($city) {
            $cityJoin  = 'AND r.city = ?';
            $params[]  = $city;
        }
        $params[] = $like;
        $params[] = $like;

        $items = Database::fetchAll(
            "SELECT mi.id, mi.name, mi.price, mi.discount_price, mi.food_type,
                    mi.image, mi.description,
                    r.id AS restaurant_id, r.name AS restaurant_name, r.is_open AS is_currently_open
             FROM menu_items mi
             JOIN restaurants r ON r.id = mi.restaurant_id AND r.is_active = 1 $cityJoin
             WHERE mi.is_available = 1
               AND (mi.name LIKE ? OR mi.description LIKE ?)
             ORDER BY r.avg_rating DESC
             LIMIT 20",
            $params
        );

        Response::success($items);
    }

    // ── DELETE /menu-categories/:id  (admin only) ─────────────────────────
    public function deleteCategory(array $params): void
    {
        AuthMiddleware::requireRole('admin');
        $id = (int) ($params['id'] ?? 0);

        Database::execute(
            "UPDATE menu_categories SET is_active = 0 WHERE id = ? AND restaurant_id IS NULL",
            [$id]
        );

        Response::success(null, 'Category removed.');
    }
}
