<?php
/**
 * WhatsAppService.php — WhatsApp ordering simulation
 *
 * Simulates receiving an order via WhatsApp message.
 * In production, replace the mock with real WhatsApp Business API calls.
 *
 * Flow:
 *  1. Customer sends text like "2 idli + 1 dosa from Ravi Kitchen"
 *  2. We parse the message to extract restaurant and items
 *  3. Create a whatsapp_orders record
 *  4. Return a confirmation message to send back to customer
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

class WhatsAppService
{
    /**
     * Process an incoming WhatsApp order message.
     *
     * @param string $phone   Customer's WhatsApp number
     * @param string $message Raw order message
     * @param int|null $restaurantId  Pre-known restaurant (from deep link)
     */
    public static function processOrder(
        string $phone,
        string $message,
        ?int   $restaurantId = null
    ): array {
        // Step 1: Parse the message
        $parsed = self::parseMessage($message, $restaurantId);

        // Step 2: Store in whatsapp_orders
        Database::execute(
            "INSERT INTO whatsapp_orders (phone, restaurant_id, message, parsed_items, status)
             VALUES (?, ?, ?, ?, 'received')",
            [
                $phone,
                $parsed['restaurant_id'],
                $message,
                json_encode($parsed['items']),
            ]
        );

        $whatsappOrderId = (int) Database::lastInsertId();

        // Step 3: Build a human-readable confirmation
        $reply = self::buildReply($parsed, $phone);

        return [
            'whatsapp_order_id' => $whatsappOrderId,
            'parsed'            => $parsed,
            'reply_message'     => $reply,
            'next_step'         => 'Customer should confirm order via app or reply YES',
        ];
    }

    /**
     * Simple rule-based message parser.
     * Extracts restaurant name + item names + quantities.
     *
     * Supports formats like:
     *   "2 idli + 1 dosa"
     *   "order 3 parotta from ravi kitchen"
     *   "idli 2, dosa 1, coffee 2"
     */
    private static function parseMessage(string $message, ?int $restaurantId = null): array
    {
        $message = strtolower(trim($message));
        $items   = [];

        // Try to find restaurant name in message
        if (!$restaurantId) {
            $restaurants = Database::fetchAll(
                "SELECT id, name FROM restaurants WHERE is_active = 1"
            );
            foreach ($restaurants as $r) {
                if (str_contains($message, strtolower($r['name']))) {
                    $restaurantId = (int) $r['id'];
                    break;
                }
            }
        }

        // Parse items with quantity patterns
        // Matches: "2 idli", "idli 2", "2x idli", "idli x2"
        $patterns = [
            '/(\d+)\s*x?\s+([a-z\s]+)/i',  // "2 idli"
            '/([a-z\s]+)\s+(\d+)/i',         // "idli 2"
        ];

        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $message, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $qty  = is_numeric($match[1]) ? (int) $match[1] : (int) $match[2];
                $name = is_numeric($match[1]) ? trim($match[2]) : trim($match[1]);
                if ($name && $qty > 0 && $qty <= 20) {
                    $items[$name] = ['name' => $name, 'quantity' => $qty, 'menu_item_id' => null];
                }
            }
        }

        // If restaurant found, try to match item names to actual menu items
        if ($restaurantId && !empty($items)) {
            $menuItems = Database::fetchAll(
                "SELECT id, name FROM menu_items WHERE restaurant_id = ? AND is_available = 1",
                [$restaurantId]
            );

            foreach ($menuItems as $mi) {
                $miName = strtolower($mi['name']);
                foreach ($items as $key => $item) {
                    similar_text($miName, $item['name'], $pct);
                    if ($pct > 70) {
                        $items[$key]['menu_item_id'] = $mi['id'];
                        $items[$key]['name']         = $mi['name']; // Use exact name
                    }
                }
            }
        }

        return [
            'restaurant_id' => $restaurantId,
            'items'         => array_values($items),
            'raw_message'   => $message,
        ];
    }

    /**
     * Build reply message to send back to customer.
     */
    private static function buildReply(array $parsed, string $phone): string
    {
        if (empty($parsed['items'])) {
            return "Hi! We received your message but couldn't identify the items. "
                 . "Please visit aharam.in to place your order, or reply with items like: '2 idli + 1 dosa'";
        }

        $lines = ["*Aharam Order Summary*", ""];
        foreach ($parsed['items'] as $item) {
            $lines[] = "• {$item['quantity']}x {$item['name']}";
        }
        $lines[] = "";
        $lines[] = "Reply *YES* to confirm, or visit aharam.in to complete payment.";
        $lines[] = "Order ID will be sent after confirmation.";

        return implode("\n", $lines);
    }

    /**
     * Simulate sending a WhatsApp message.
     * In production, replace with real WhatsApp Business API call.
     */
    public static function sendMessage(string $phone, string $message): bool
    {
        // Mock: just log it
        appLog('info', "WhatsApp message to $phone", ['message' => substr($message, 0, 100)]);

        // Real implementation:
        // $response = Http::post(WHATSAPP_API_URL . '/messages', [
        //     'to'   => $phone,
        //     'type' => 'text',
        //     'text' => ['body' => $message],
        // ], ['Authorization' => 'Bearer ' . WHATSAPP_TOKEN]);

        return true; // Mock always succeeds
    }
}
