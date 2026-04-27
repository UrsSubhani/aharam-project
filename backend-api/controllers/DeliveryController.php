<?php
/**
 * DeliveryController.php — Delivery partner management
 *
 * POST  /delivery/assign           - Auto-assign nearest partner (admin/system)
 * GET   /delivery/my-orders        - Partner's assigned orders
 * PATCH /delivery/:id/status       - Update delivery status (partner)
 * PATCH /delivery/location         - Update GPS location (partner)
 * GET   /delivery/:order_id/track  - Live tracking (customer polling)
 * PATCH /delivery/availability     - Toggle online/offline (partner)
 */

declare(strict_types=1);

require_once __DIR__ . '/../models/DeliveryPartner.php';
require_once __DIR__ . '/../models/Order.php';
require_once __DIR__ . '/../services/CommissionService.php';
require_once __DIR__ . '/../services/ReferralService.php';
require_once __DIR__ . '/../middlewares/AuthMiddleware.php';
require_once __DIR__ . '/../utils/Response.php';
require_once __DIR__ . '/../utils/Validator.php';
require_once __DIR__ . '/../utils/Helper.php';

class DeliveryController
{
    private DeliveryPartner $partner;
    private Order           $order;

    public function __construct()
    {
        $this->partner = new DeliveryPartner();
        $this->order   = new Order();
    }

    // ── POST /delivery/profile ───────────────────────────────────────────
    // Called after registration to create the delivery_partners profile row
    public function setupProfile(array $params): void
    {
        $auth = AuthMiddleware::requireRole('delivery_partner');
        $data = getRequestData();

        // Prevent duplicate profile
        $existing = $this->partner->getByUserId($auth['sub']);
        if ($existing) {
            Response::error('Profile already exists.', 409);
        }

        $v = new Validator($data);
        $v->required(['vehicle_type', 'vehicle_number', 'license_number', 'aadhar_number', 'city'])
          ->in('vehicle_type', ['bicycle', 'motorbike', 'scooter', 'car']);
        if ($v->fails()) Response::validationError($v->errors());

        Database::execute(
            "INSERT INTO delivery_partners
               (user_id, vehicle_type, vehicle_number, license_number, aadhar_number,
                city, bank_account, ifsc_code, is_verified, is_available,
                total_deliveries, total_earnings, avg_rating, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 0, 0.00, 0.00, NOW(), NOW())",
            [
                $auth['sub'],
                $data['vehicle_type'],
                $data['vehicle_number'],
                $data['license_number'],
                $data['aadhar_number'],
                $data['city'],
                $data['bank_account'] ?? null,
                $data['ifsc_code']    ?? null,
            ]
        );

        Response::success(null, 'Profile submitted! Waiting for admin verification.', 201);
    }

    // ── PUT /delivery/profile ─────────────────────────────────────────────
    public function updateProfile(array $params): void
    {
        $auth = AuthMiddleware::requireRole('delivery_partner');
        $data = getRequestData();

        $allowed = ['city', 'vehicle_type', 'vehicle_number', 'bank_account', 'ifsc_code'];
        $updates = [];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $updates[$field] = $data[$field];
            }
        }

        if (empty($updates)) {
            Response::error('Nothing to update.', 400);
        }

        if (isset($updates['vehicle_type'])) {
            $valid = ['bicycle', 'motorbike', 'scooter', 'car'];
            if (!in_array($updates['vehicle_type'], $valid, true)) {
                Response::validationError(['vehicle_type' => 'Invalid vehicle type.']);
            }
        }

        $setClauses = implode(', ', array_map(fn($k) => "$k = ?", array_keys($updates)));
        $values     = array_values($updates);
        $values[]   = $auth['sub'];

        Database::execute(
            "UPDATE delivery_partners SET $setClauses, updated_at = NOW() WHERE user_id = ?",
            $values
        );

        Response::success(null, 'Profile updated successfully.');
    }

    // ── POST /delivery/assign ─────────────────────────────────────────────
    // Called automatically after order is confirmed (or manually by admin)
    public function assign(array $params): void
    {
        $auth = AuthMiddleware::requireAnyRole(['admin', 'restaurant_owner']);
        $data = getRequestData();

        $v = new Validator($data);
        $v->required(['order_id']);
        if ($v->fails()) {
            Response::validationError($v->errors());
        }

        $orderId = (int) $data['order_id'];
        $order   = $this->order->find($orderId);

        if (!$order) {
            Response::notFound('Order not found.');
        }

        // Already assigned?
        $existing = Database::fetchOne(
            "SELECT id FROM delivery_orders WHERE order_id = ?",
            [$orderId]
        );
        if ($existing) {
            Response::error('A delivery partner is already assigned to this order.', 409);
        }

        // Admin can manually specify a partner_id; otherwise auto-pick nearest
        if (!empty($data['partner_id'])) {
            $partner = Database::fetchOne(
                "SELECT id, current_lat, current_lng FROM delivery_partners WHERE id = ? AND is_verified = 1",
                [(int) $data['partner_id']]
            );
            if (!$partner) {
                Response::error('Selected delivery partner not found or not verified.', 404);
            }
        } else {
            $lat = (float) ($data['lat'] ?? $order['delivery_lat'] ?? 0);
            $lng = (float) ($data['lng'] ?? $order['delivery_lng'] ?? 0);

            if (!$lat || !$lng) {
                $rest = Database::fetchOne(
                    "SELECT latitude, longitude FROM restaurants WHERE id = ?",
                    [$order['restaurant_id']]
                );
                $lat = (float) ($rest['latitude'] ?? 13.0);
                $lng = (float) ($rest['longitude'] ?? 80.2);
            }

            $partner = $this->partner->findNearest($lat, $lng);

            if (!$partner) {
                Response::error('No delivery partners available in your area right now.', 503);
            }
        }

        $distanceKm = (float) ($partner['distance_km'] ?? 3.0);
        $earnings   = CommissionService::calculateDeliveryEarnings($distanceKm);

        // Create delivery order
        $pickupOtp   = generateOTP(4);
        $deliveryOtp = generateOTP(4);

        Database::execute(
            "INSERT INTO delivery_orders
               (order_id, partner_id, status, pickup_otp, delivery_otp,
                distance_km, delivery_fee, partner_earnings)
             VALUES (?, ?, 'assigned', ?, ?, ?, ?, ?)",
            [
                $orderId,
                $partner['id'],
                $pickupOtp,
                $deliveryOtp,
                $distanceKm,
                $order['delivery_fee'],
                $earnings['total'],
            ]
        );

        Response::success([
            'delivery_order_id' => (int) Database::lastInsertId(),
            'partner_name'      => $partner['name'],
            'partner_phone'     => $partner['phone'],
            'distance_km'       => $distanceKm,
            'pickup_otp'        => $pickupOtp,  // For restaurant
            'delivery_otp'      => $deliveryOtp, // For customer
        ], 'Delivery partner assigned.');
    }

    // ── GET /delivery/my-orders ────────────────────────────────────────────
    public function myOrders(array $params): void
    {
        $auth    = AuthMiddleware::requireRole('delivery_partner');
        $partnerRow = $this->partner->getByUserId($auth['sub']);

        if (!$partnerRow) {
            Response::error('Delivery partner profile not found.', 404);
        }

        $status = $_GET['status'] ?? 'active'; // active | completed

        if ($status === 'active') {
            $statusFilter = "AND do2.status IN ('assigned','accepted','picked','on_the_way')";
        } else {
            $statusFilter = "AND do2.status IN ('delivered','cancelled')";
        }

        $orders = Database::fetchAll(
            "SELECT do2.id AS delivery_id, do2.status AS delivery_status,
                    do2.pickup_otp, do2.delivery_otp, do2.distance_km,
                    do2.partner_earnings, do2.created_at,
                    o.id AS order_id, o.order_number, o.total_amount,
                    o.wallet_amount,
                    GREATEST(0, o.total_amount - o.wallet_amount) AS cash_to_collect,
                    o.payment_method, o.delivery_address_text, o.special_instructions,
                    r.name AS restaurant_name, r.address AS restaurant_address,
                    r.phone AS restaurant_phone, r.latitude, r.longitude,
                    u.name AS customer_name, u.phone AS customer_phone,
                    o.delivery_lat, o.delivery_lng
             FROM delivery_orders do2
             JOIN orders o ON o.id = do2.order_id
             JOIN restaurants r ON r.id = o.restaurant_id
             JOIN users u ON u.id = o.user_id
             WHERE do2.partner_id = ? $statusFilter
             ORDER BY do2.created_at DESC",
            [$partnerRow['id']]
        );

        Response::success($orders);
    }

    // ── PATCH /delivery/:id/status ─────────────────────────────────────────
    public function updateStatus(array $params): void
    {
        $auth       = AuthMiddleware::requireRole('delivery_partner');
        $deliveryId = (int) ($params['id'] ?? 0);
        $data       = getRequestData();

        $v = new Validator($data);
        $v->required(['status'])
          ->in('status', ['accepted', 'picked', 'on_the_way', 'delivered', 'cancelled']);
        if ($v->fails()) {
            Response::validationError($v->errors());
        }

        $partnerRow = $this->partner->getByUserId($auth['sub']);
        if (!$partnerRow) {
            Response::error('Partner profile not found.', 404);
        }

        $delivery = Database::fetchOne(
            "SELECT * FROM delivery_orders WHERE id = ? AND partner_id = ?",
            [$deliveryId, $partnerRow['id']]
        );
        if (!$delivery) {
            Response::forbidden('Delivery not found.');
        }

        $newStatus = $data['status'];

        // OTP verification before picked / delivered
        if ($newStatus === 'picked') {
            $otp = trim((string)($data['otp'] ?? ''));
            if (!$otp) {
                Response::error('Enter the Pickup OTP shown by the restaurant.', 400);
            }
            if ($otp !== (string)$delivery['pickup_otp']) {
                Response::error('Incorrect Pickup OTP. Please try again.', 422);
            }
        }
        if ($newStatus === 'delivered') {
            $otp = trim((string)($data['otp'] ?? ''));
            if (!$otp) {
                Response::error('Enter the Delivery OTP shown by the customer.', 400);
            }
            if ($otp !== (string)$delivery['delivery_otp']) {
                Response::error('Incorrect Delivery OTP. Please try again.', 422);
            }
        }

        $updateMap = [
            'accepted'   => ['accepted_at'  => null],
            'picked'     => ['picked_at'    => null],
            'on_the_way' => [],
            'delivered'  => ['delivered_at' => null],
        ];

        $tsField = match ($newStatus) {
            'accepted'   => 'accepted_at',
            'picked'     => 'picked_at',
            'delivered'  => 'delivered_at',
            default      => null,
        };

        $tsSql = $tsField ? ", $tsField = NOW()" : '';

        Database::execute(
            "UPDATE delivery_orders SET status = ?$tsSql WHERE id = ?",
            [$newStatus, $deliveryId]
        );

        // Sync order status
        // NOTE: 'accepted' intentionally excluded — order stays 'ready' while partner is on the way to restaurant
        $orderStatusMap = [
            'picked'     => 'picked',
            'on_the_way' => 'on_the_way',
            'delivered'  => 'delivered',
        ];

        if (isset($orderStatusMap[$newStatus])) {
            $this->order->updateStatus((int) $delivery['order_id'], $orderStatusMap[$newStatus]);
        }

        // On delivery: record earnings
        if ($newStatus === 'delivered') {
            Database::execute(
                "INSERT INTO delivery_earnings
                   (partner_id, delivery_order_id, order_id, base_pay, distance_pay, total_earnings)
                 VALUES (?, ?, ?, ?, ?, ?)",
                [
                    $partnerRow['id'],
                    $deliveryId,
                    $delivery['order_id'],
                    25.00,
                    max(0, ($delivery['distance_km'] - 2) * 3),
                    $delivery['partner_earnings'],
                ]
            );

            $this->partner->recordDelivery($partnerRow['id'], (float) $delivery['partner_earnings']);

            // Trigger referral reward if this is the referred user's first delivered order
            $order = Database::fetchOne(
                "SELECT user_id, total_amount FROM orders WHERE id = ?",
                [(int) $delivery['order_id']]
            );
            if ($order) {
                ReferralService::processFirstOrderReward(
                    (int) $order['user_id'],
                    (int) $delivery['order_id'],
                    (float) $order['total_amount']
                );
            }
        }

        Response::success(['status' => $newStatus], 'Status updated.');
    }

    // ── PATCH /delivery/location ───────────────────────────────────────────
    // Called every 5-10 seconds by the partner app for live tracking
    public function updateLocation(array $params): void
    {
        $auth = AuthMiddleware::requireRole('delivery_partner');
        $data = getRequestData();

        $v = new Validator($data);
        $v->required(['lat', 'lng']);
        if ($v->fails()) {
            Response::validationError($v->errors());
        }

        $partnerRow = $this->partner->getByUserId($auth['sub']);
        if (!$partnerRow) {
            Response::error('Partner profile not found.', 404);
        }

        $this->partner->updateLocation(
            $partnerRow['id'],
            (float) $data['lat'],
            (float) $data['lng']
        );

        // Silently succeed — no need for full JSON body in a high-frequency call
        http_response_code(204);
        exit;
    }

    // ── GET /delivery/:order_id/track ─────────────────────────────────────
    // Customer polls this endpoint every 5-10 seconds
    public function track(array $params): void
    {
        $orderId = (int) ($params['order_id'] ?? 0);

        $tracking = Database::fetchOne(
            "SELECT o.id, o.order_number, o.status,
                    o.confirmed_at, o.preparing_at, o.ready_at, o.picked_at, o.delivered_at,
                    do2.status AS delivery_status, do2.pickup_otp, do2.delivery_otp,
                    dp.current_lat AS partner_lat, dp.current_lng AS partner_lng,
                    dp.location_updated_at,
                    u.name AS partner_name, u.phone AS partner_phone,
                    r.name AS restaurant_name, r.avg_delivery_time
             FROM orders o
             LEFT JOIN delivery_orders do2 ON do2.order_id = o.id
             LEFT JOIN delivery_partners dp ON dp.id = do2.partner_id
             LEFT JOIN users u ON u.id = dp.user_id
             JOIN restaurants r ON r.id = o.restaurant_id
             WHERE o.id = ?",
            [$orderId]
        );

        if (!$tracking) {
            Response::notFound('Order not found.');
        }

        // Don't expose OTPs to the customer via tracking endpoint
        unset($tracking['pickup_otp']);

        Response::success($tracking);
    }

    // ── GET /delivery/available-orders ───────────────────────────────────
    // Orders with status='ready' that have no delivery partner assigned yet
    public function availableOrders(array $params): void
    {
        $auth = AuthMiddleware::requireRole('delivery_partner');

        $partnerRow = $this->partner->getByUserId($auth['sub']);
        if (!$partnerRow || !$partnerRow['is_available']) {
            Response::success([], 'Go online to see new requests.');
            return;
        }

        // Use live GPS from query param if provided, else last known location
        $riderLat = (float) ($_GET['lat'] ?? $partnerRow['current_lat'] ?? 13.0);
        $riderLng = (float) ($_GET['lng'] ?? $partnerRow['current_lng'] ?? 80.2);

        $orders = Database::fetchAll(
            "SELECT o.id AS order_id, o.order_number, o.total_amount,
                    o.wallet_amount,
                    GREATEST(0, o.total_amount - o.wallet_amount) AS cash_to_collect,
                    o.payment_method,
                    o.delivery_address_text, o.delivery_lat, o.delivery_lng,
                    o.special_instructions,
                    r.name AS restaurant_name, r.address AS restaurant_address,
                    r.latitude AS pickup_lat, r.longitude AS pickup_lng,
                    r.phone AS restaurant_phone,
                    u.name AS customer_name,
                    ROUND(
                      6371 * ACOS(LEAST(1, GREATEST(-1,
                        COS(RADIANS(?)) * COS(RADIANS(COALESCE(r.latitude, 13.0))) *
                        COS(RADIANS(COALESCE(r.longitude, 80.2)) - RADIANS(?)) +
                        SIN(RADIANS(?)) * SIN(RADIANS(COALESCE(r.latitude, 13.0)))
                      ))), 2
                    ) AS distance_km
             FROM orders o
             JOIN restaurants r ON r.id = o.restaurant_id
             JOIN users u ON u.id = o.user_id
             WHERE o.status = 'ready'
               AND o.id NOT IN (SELECT order_id FROM delivery_orders)
             ORDER BY distance_km ASC
             LIMIT 10",
            [$riderLat, $riderLng, $riderLat]
        );

        // Calculate earnings per order based on distance
        foreach ($orders as &$ord) {
            $e = CommissionService::calculateDeliveryEarnings((float)($ord['distance_km'] ?? 3));
            $ord['partner_earnings'] = $e['total'];
        }
        unset($ord);

        Response::success($orders);
    }

    // ── POST /delivery/accept ─────────────────────────────────────────────
    // Delivery partner self-assigns to a ready order
    public function acceptOrder(array $params): void
    {
        $auth = AuthMiddleware::requireRole('delivery_partner');
        $data = getRequestData();

        $v = new Validator($data);
        $v->required(['order_id']);
        if ($v->fails()) Response::validationError($v->errors());

        $partnerRow = $this->partner->getByUserId($auth['sub']);
        if (!$partnerRow) Response::error('Partner profile not found.', 404);

        $orderId = (int) $data['order_id'];
        $order   = $this->order->find($orderId);

        if (!$order || $order['status'] !== 'ready') {
            Response::error('Order is no longer available.', 409);
        }

        // Race condition guard
        $existing = Database::fetchOne("SELECT id FROM delivery_orders WHERE order_id = ?", [$orderId]);
        if ($existing) Response::error('Order already taken by another partner.', 409);

        $distanceKm  = (float) ($data['distance_km'] ?? 3.0);
        $earnings    = CommissionService::calculateDeliveryEarnings($distanceKm);
        $pickupOtp   = generateOTP(4);
        $deliveryOtp = generateOTP(4);

        Database::execute(
            "INSERT INTO delivery_orders
               (order_id, partner_id, status, pickup_otp, delivery_otp,
                distance_km, delivery_fee, partner_earnings)
             VALUES (?, ?, 'assigned', ?, ?, ?, ?, ?)",
            [
                $orderId, $partnerRow['id'],
                $pickupOtp, $deliveryOtp,
                $distanceKm, $order['delivery_fee'], $earnings['total'],
            ]
        );

        // Order stays 'ready' — status advances only after OTP verification at pickup
        Response::success([
            'delivery_id'  => (int) Database::lastInsertId(),
            'pickup_otp'   => $pickupOtp,
            'delivery_otp' => $deliveryOtp,
        ], 'Order accepted!');
    }

    // ── PATCH /delivery/availability ──────────────────────────────────────
    public function toggleAvailability(array $params): void
    {
        $auth = AuthMiddleware::requireRole('delivery_partner');
        $data = getRequestData();

        $partnerRow = $this->partner->getByUserId($auth['sub']);
        if (!$partnerRow) {
            Response::error('Partner profile not found.', 404);
        }

        $available = filter_var($data['available'] ?? !$partnerRow['is_available'], FILTER_VALIDATE_BOOLEAN);
        $this->partner->setAvailability($partnerRow['id'], $available);

        Response::success(
            ['is_available' => $available],
            $available ? 'You are now online.' : 'You are now offline.'
        );
    }
}
