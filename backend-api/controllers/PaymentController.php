<?php
/**
 * PaymentController.php — Razorpay payment flow
 *
 * POST /payment/initiate     - Create Razorpay order
 * POST /payment/verify       - Verify payment after Razorpay callback
 * POST /payment/webhook      - Razorpay webhook (server-to-server)
 * GET  /payment/:order_id    - Get payment status
 */

declare(strict_types=1);

require_once __DIR__ . '/../services/PaymentService.php';
require_once __DIR__ . '/../middlewares/AuthMiddleware.php';
require_once __DIR__ . '/../utils/Response.php';
require_once __DIR__ . '/../utils/Validator.php';
require_once __DIR__ . '/../utils/Helper.php';

class PaymentController
{
    // ── POST /payment/initiate ─────────────────────────────────────────────
    public function initiate(array $params): void
    {
        $auth = AuthMiddleware::requireRole('customer');
        $data = getRequestData();

        $v = new Validator($data);
        $v->required(['order_id']);
        if ($v->fails()) {
            Response::validationError($v->errors());
        }

        $orderId = (int) $data['order_id'];

        $order = Database::fetchOne(
            "SELECT * FROM orders WHERE id = ? AND user_id = ?",
            [$orderId, $auth['sub']]
        );

        if (!$order) {
            Response::notFound('Order not found.');
        }

        if ($order['payment_status'] === 'paid') {
            Response::error('This order has already been paid.', 400);
        }

        $rzpOrder = PaymentService::createOrder(
            (float) $order['total_amount'],
            $order['order_number']
        );

        // Update the payment record with new Razorpay order ID
        Database::execute(
            "UPDATE payments SET razorpay_order_id = ?, status = 'pending' WHERE order_id = ?",
            [$rzpOrder['razorpay_order_id'], $orderId]
        );

        Response::success([
            'razorpay_order_id' => $rzpOrder['razorpay_order_id'],
            'amount'            => $rzpOrder['amount'],
            'currency'          => $rzpOrder['currency'],
            'key_id'            => $rzpOrder['key_id'],
            'order_number'      => $order['order_number'],
            'customer_name'     => $auth['name'],
            'customer_email'    => $auth['email'],
        ], 'Payment order created.');
    }

    // ── POST /payment/verify ─────────────────────────────────────────────
    public function verify(array $params): void
    {
        $auth = AuthMiddleware::requireRole('customer');
        $data = getRequestData();

        $v = new Validator($data);
        $v->required(['razorpay_order_id', 'razorpay_payment_id', 'razorpay_signature', 'order_id']);
        if ($v->fails()) {
            Response::validationError($v->errors());
        }

        $orderId    = (int) $data['order_id'];
        $rzpOrderId = $data['razorpay_order_id'];
        $rzpPayId   = $data['razorpay_payment_id'];
        $rzpSig     = $data['razorpay_signature'];

        $order = Database::fetchOne(
            "SELECT * FROM orders WHERE id = ? AND user_id = ?",
            [$orderId, $auth['sub']]
        );

        if (!$order) {
            Response::notFound('Order not found.');
        }

        // Verify Razorpay signature
        if (!PaymentService::verifySignature($rzpOrderId, $rzpPayId, $rzpSig)) {
            PaymentService::markFailed($orderId, 'Signature verification failed.');
            Response::error('Payment verification failed. Please contact support.', 400);
        }

        // Mark payment as successful
        PaymentService::markSuccess($orderId, $rzpPayId, $rzpSig, [
            'razorpay_order_id'   => $rzpOrderId,
            'razorpay_payment_id' => $rzpPayId,
        ]);

        // Confirm the order
        Database::execute(
            "UPDATE orders SET status = 'confirmed', confirmed_at = NOW() WHERE id = ?",
            [$orderId]
        );

        Response::success([
            'order_id'     => $orderId,
            'order_status' => 'confirmed',
            'payment_id'   => $rzpPayId,
        ], 'Payment successful! Your order is confirmed.');
    }

    // ── POST /payment/webhook ─────────────────────────────────────────────
    // This endpoint does NOT require JWT (Razorpay calls it server-to-server)
    public function webhook(array $params): void
    {
        $rawBody  = file_get_contents('php://input');
        $webhookSecret = env('RAZORPAY_WEBHOOK_SECRET', '');

        // Verify Razorpay webhook signature
        if ($webhookSecret) {
            $receivedSig = $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '';
            $expectedSig = hash_hmac('sha256', $rawBody, $webhookSecret);
            if (!hash_equals($expectedSig, $receivedSig)) {
                http_response_code(400);
                exit;
            }
        }

        $event = json_decode($rawBody, true);
        if (!$event) {
            http_response_code(400);
            exit;
        }

        PaymentService::handleWebhook($event);
        http_response_code(200);
        echo json_encode(['status' => 'ok']);
    }

    // ── GET /payment/:order_id ─────────────────────────────────────────────
    public function show(array $params): void
    {
        $auth    = AuthMiddleware::requireRole('customer');
        $orderId = (int) ($params['order_id'] ?? 0);

        $payment = PaymentService::getByOrder($orderId);
        if (!$payment) {
            Response::notFound('Payment record not found.');
        }

        // Mask sensitive fields
        unset($payment['razorpay_signature'], $payment['gateway_response']);

        Response::success($payment);
    }
}
