<?php
/**
 * RestaurantController.php
 *
 * GET  /restaurants              - List nearby restaurants (public)
 * GET  /restaurants/:id          - Restaurant detail (public)
 * POST /restaurants              - Create restaurant (restaurant_owner)
 * PUT  /restaurants/:id          - Update restaurant (owner)
 * PATCH /restaurants/:id/toggle  - Toggle open/close (owner)
 * GET  /restaurants/:id/stats    - Dashboard stats (owner)
 */

declare(strict_types=1);

require_once __DIR__ . '/../models/Restaurant.php';
require_once __DIR__ . '/../middlewares/AuthMiddleware.php';
require_once __DIR__ . '/../utils/Response.php';
require_once __DIR__ . '/../utils/Validator.php';
require_once __DIR__ . '/../utils/Helper.php';

class RestaurantController
{
    private Restaurant $restaurant;

    public function __construct()
    {
        $this->restaurant = new Restaurant();
    }

    // ── GET /restaurants ───────────────────────────────────────────────────
    public function index(array $params): void
    {
        $city    = trim($_GET['city']    ?? '');
        $pincode = trim($_GET['pincode'] ?? '');
        $sortBy  = $_GET['sort']         ?? 'avg_rating';
        $query   = trim($_GET['q'] ?? $_GET['search'] ?? '');
        $cuisine = trim($_GET['cuisine'] ?? '');

        ['page' => $page, 'limit' => $limit, 'offset' => $offset] = getPagination(20);

        if ($query) {
            $restaurants = $this->restaurant->search($query, $city, $limit);
            $total       = count($restaurants);
        } else {
            $restaurants = $this->restaurant->getNearby($city, $pincode, $limit, $offset, $sortBy, $cuisine);
            $total       = $this->restaurant->countNearby($city, $pincode, $cuisine);
        }

        Response::success(
            $restaurants,
            'Restaurants fetched.',
            200,
            Response::paginate($total, $page, $limit)
        );
    }

    // ── GET /restaurants/:id ───────────────────────────────────────────────
    public function show(array $params): void
    {
        $id   = (int) ($params['id'] ?? 0);
        $rest = $this->restaurant->getDetail($id);

        if (!$rest || !$rest['is_active']) {
            Response::notFound('Restaurant not found.');
        }

        // Remove internal/sensitive fields before sending to public
        unset($rest['bank_account'], $rest['ifsc_code'], $rest['bank_holder_name'],
              $rest['commission_percent'], $rest['effective_commission']);

        Response::success($rest);
    }

    // ── GET /restaurants/:id/owner ────────────────────────────────────────
    public function showOwner(array $params): void
    {
        $auth = AuthMiddleware::requireRole('restaurant_owner');
        $id   = (int) ($params['id'] ?? 0);
        $rest = $this->restaurant->getDetail($id);

        if (!$rest || (int)$rest['owner_id'] !== (int)$auth['sub']) {
            Response::forbidden('Access denied.');
        }

        // Return raw DB is_open so owner panel toggle reflects actual saved state,
        // not the time-computed value shown to customers.
        $raw = $this->restaurant->find($id);
        $rest['is_open'] = (int) ($raw['is_open'] ?? 0);

        Response::success($rest);
    }

    // ── POST /restaurants ─────────────────────────────────────────────────
    public function create(array $params): void
    {
        $auth = AuthMiddleware::requireRole('restaurant_owner');
        $data = getRequestData();

        $v = new Validator($data);
        $v->required(['name', 'address', 'city', 'pincode', 'phone', 'cuisine_type'])
          ->phone('phone')
          ->min('name', 3)
          ->max('name', 150);

        if ($v->fails()) {
            Response::validationError($v->errors());
        }

        // Check owner doesn't already have a restaurant
        $existing = $this->restaurant->getByOwner($auth['sub']);
        if ($existing) {
            Response::error('You already have a registered restaurant. Contact support to add more.', 409);
        }

        $slug = slugify($data['name']);
        // Ensure slug uniqueness
        $checkSlug = $this->restaurant->findBy('slug', $slug);
        if ($checkSlug) {
            $slug .= '-' . rand(100, 999);
        }

        $id = $this->restaurant->create([
            'owner_id'      => $auth['sub'],
            'name'          => $data['name'],
            'slug'          => $slug,
            'description'   => $data['description'] ?? '',
            'cuisine_type'  => $data['cuisine_type'],
            'category'      => $data['category'] ?? 'restaurant',
            'address'       => $data['address'],
            'city'          => $data['city'],
            'pincode'       => $data['pincode'],
            'latitude'      => $data['latitude'] ?? null,
            'longitude'     => $data['longitude'] ?? null,
            'phone'         => $data['phone'],
            'email'         => $data['email'] ?? null,
            'gstin'         => $data['gstin'] ?? null,
            'fssai_number'  => $data['fssai_number'] ?? null,
            'opening_time'  => $data['opening_time'] ?? '08:00:00',
            'closing_time'  => $data['closing_time'] ?? '22:00:00',
            'whatsapp_number' => $data['whatsapp_number'] ?? null,
            'is_open'       => 0, // Owner must open manually
            'is_active'     => 0, // Admin must approve
        ]);

        Response::success(
            ['restaurant_id' => $id],
            'Restaurant registered. Awaiting admin approval.',
            201
        );
    }

    // ── PUT /restaurants/:id ───────────────────────────────────────────────
    public function update(array $params): void
    {
        $auth = AuthMiddleware::requireRole('restaurant_owner');
        $id   = (int) ($params['id'] ?? 0);
        $data = getRequestData();

        $rest = $this->restaurant->find($id);
        if (!$rest || (int) $rest['owner_id'] !== $auth['sub']) {
            Response::forbidden('You do not own this restaurant.');
        }

        $allowed = [
            'description', 'cuisine_type', 'address', 'city', 'pincode',
            'latitude', 'longitude',
            'phone', 'email', 'opening_time', 'closing_time', 'logo_image',
            'cover_image', 'min_order_amount', 'avg_delivery_time',
            'whatsapp_number', 'bank_account', 'ifsc_code', 'bank_holder_name',
        ];

        $updateData = array_intersect_key($data, array_flip($allowed));
        if (empty($updateData)) {
            Response::error('No valid fields to update.', 400);
        }

        $this->restaurant->update($id, $updateData);
        Response::success(null, 'Restaurant updated successfully.');
    }

    // ── PATCH /restaurants/:id/toggle ─────────────────────────────────────
    public function toggleStatus(array $params): void
    {
        $auth = AuthMiddleware::requireRole('restaurant_owner');
        $id   = (int) ($params['id'] ?? 0);

        $rest = $this->restaurant->find($id);
        if (!$rest || (int) $rest['owner_id'] !== $auth['sub']) {
            Response::forbidden('You do not own this restaurant.');
        }

        $newStatus = $rest['is_open'] ? 0 : 1;
        $this->restaurant->update($id, ['is_open' => $newStatus]);

        Response::success(
            ['is_open' => (bool) $newStatus],
            $newStatus ? 'Restaurant is now open.' : 'Restaurant is now closed.'
        );
    }

    // ── GET /restaurants/:id/stats ─────────────────────────────────────────
    public function stats(array $params): void
    {
        $auth = AuthMiddleware::requireRole('restaurant_owner');
        $id   = (int) ($params['id'] ?? 0);

        $rest = $this->restaurant->find($id);
        if (!$rest || (int) $rest['owner_id'] !== $auth['sub']) {
            Response::forbidden('Access denied.');
        }

        $stats = $this->restaurant->getStats($id);
        Response::success($stats);
    }
}
