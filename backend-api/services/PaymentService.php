<?php
/**
 * PaymentService.php — Razorpay payment integration
 *
 * This is a mock implementation that simulates the Razorpay API.
 * To use the real Razorpay, install the SDK:
 *   composer require razorpay/razorpay
 *
 * The same interface is maintained so swapping to real SDK requires
 * only changing the methods below — controllers stay identical.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

class PaymentService
{
    /**
     * Create a Razorpay order.
     * In production this calls the Razorpay Orders API and returns:
     *   { id, amount, currency, receipt, status }
     *
     * @param float  $amount   Amount in INR
     * @param string $receipt  Order number (for tracking)
     * @return array{razorpay_order_id: string, amount: int, currency: string, key_id: string}
     */
    public static function createOrder(float $amount, string $receipt): array
    {
        // ── MOCK: simulate Razorpay order creation ────────────────────────────
        $razorpayOrderId = 'order_' . strtoupper(bin2hex(random_bytes(8)));

        // In production with real SDK:
        // $api = new \Razorpay\Api\Api(RAZORPAY_KEY_ID, RAZORPAY_KEY_SECRET);
        // $order = $api->order->create([
        //     'amount'   => (int) ($amount * 100), // paise
        //     'currency' => 'INR',
        //     'receipt'  => $receipt,
        // ]);
        // return ['razorpay_order_id' => $order->id, ...];

        return [
            'razorpay_order_id' => $razorpayOrderId,
            'amount'            => (int) ($amount * 100), // paise
            'currency'          => 'INR',
            'key_id'            => RAZORPAY_KEY_ID,
            'receipt'           => $receipt,
        ];
    }

    /**
     * Verify Razorpay payment signature.
     *
     * Razorpay signs the response with HMAC-SHA256:
     *   signature = HMAC_SHA256(razorpay_order_id + "|" + razorpay_payment_id, key_secret)
     *
     * @return bool  True if signature is valid
     */
    public static function verifySignature(
        string $razorpayOrderId,
        string $razorpayPaymentId,
        string $razorpaySignature
    ): bool {
        // ── MOCK: in test mode accept any signature ────────────────────────────
        if (APP_DEBUG || str_starts_with(RAZORPAY_KEY_ID, 'rzp_test_')) {
            return true; // Accept all in dev/test
        }

        // Production signature verification
        $payload      = $razorpayOrderId . '|' . $razorpayPaymentId;
        $expectedSign = hash_hmac('sha256', $payload, RAZORPAY_KEY_SECRET);
        return hash_equals($expectedSign, $razorpaySignature);
    }

    /**
     * Record a payment record in the database.
     * Called after Razorpay order is created.
     */
    public static function createRecord(
        int    $orderId,
        int    $userId,
        float  $amount,
        string $method,
        string $razorpayOrderId = ''
    ): int {
        $stmt = Database::execute(
            "INSERT INTO payments
               (order_id, user_id, amount, method, status, razorpay_order_id)
             VALUES (?, ?, ?, ?, 'initiated', ?)",
            [$orderId, $userId, $amount, $method, $razorpayOrderId]
        );
        return (int) Database::lastInsertId();
    }

    /**
     * Mark a payment as successful.
     */
    public static function markSuccess(
        int    $orderId,
        string $razorpayPaymentId,
        string $razorpaySignature,
        array  $gatewayResponse = []
    ): void {
        Database::execute(
            "UPDATE payments
             SET status               = 'success',
                 razorpay_payment_id  = ?,
                 razorpay_signature   = ?,
                 gateway_response     = ?,
                 updated_at           = NOW()
             WHERE order_id = ?",
            [
                $razorpayPaymentId,
                $razorpaySignature,
                json_encode($gatewayResponse),
                $orderId,
            ]
        );

        // Update order payment status
        Database::execute(
            "UPDATE orders SET payment_status = 'paid' WHERE id = ?",
            [$orderId]
        );
    }

    /**
     * Mark a payment as failed.
     */
    public static function markFailed(int $orderId, string $reason = ''): void
    {
        Database::execute(
            "UPDATE payments SET status = 'failed', updated_at = NOW() WHERE order_id = ?",
            [$orderId]
        );

        Database::execute(
            "UPDATE orders SET payment_status = 'failed' WHERE id = ?",
            [$orderId]
        );
    }

    /**
     * Process a COD order — mark as pending payment on delivery.
     */
    public static function processCOD(int $orderId, int $userId, float $amount): void
    {
        // For COD, create a payment record but leave status as pending
        self::createRecord($orderId, $userId, $amount, 'cod');

        // COD orders stay 'pending' until restaurant confirms
    }

    /**
     * Get payment details for an order.
     */
    public static function getByOrder(int $orderId): array|false
    {
        return Database::fetchOne(
            "SELECT * FROM payments WHERE order_id = ? ORDER BY created_at DESC LIMIT 1",
            [$orderId]
        );
    }

    /**
     * Handle Razorpay webhook event.
     * Called by /payment/webhook endpoint.
     */
    public static function handleWebhook(array $event): void
    {
        $entity = $event['payload']['payment']['entity'] ?? [];
        $notes  = $entity['notes'] ?? [];

        $orderId = $notes['order_id'] ?? null;
        if (!$orderId) {
            return;
        }

        switch ($event['event'] ?? '') {
            case 'payment.captured':
                self::markSuccess(
                    (int) $orderId,
                    $entity['id'] ?? '',
                    '',
                    $entity
                );
                break;

            case 'payment.failed':
                self::markFailed((int) $orderId, $entity['error_description'] ?? '');
                break;
        }
    }
}
