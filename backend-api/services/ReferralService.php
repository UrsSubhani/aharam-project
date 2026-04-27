<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/SettingsService.php';
require_once __DIR__ . '/WalletService.php';

class ReferralService
{
    /**
     * Generate a unique referral code for a user (idempotent).
     */
    public static function generateCode(int $userId, string $name): string
    {
        $existing = Database::fetchOne(
            "SELECT referral_code FROM users WHERE id = ?",
            [$userId]
        );
        if (!empty($existing['referral_code'])) {
            return $existing['referral_code'];
        }

        $namepart = strtoupper(preg_replace('/[^A-Za-z]/', '', $name));
        $namepart = substr(str_pad($namepart, 4, 'X'), 0, 6);
        $code     = $namepart . $userId;

        // Ensure uniqueness
        $attempt = 0;
        while (Database::fetchOne("SELECT id FROM users WHERE referral_code = ?", [$code])) {
            $attempt++;
            $code = $namepart . $userId . $attempt;
        }

        Database::execute(
            "UPDATE users SET referral_code = ? WHERE id = ?",
            [$code, $userId]
        );
        return $code;
    }

    /**
     * Apply a referral code when a new user signs up.
     * Returns true if applied successfully.
     */
    public static function applyReferral(int $newUserId, string $referralCode): bool
    {
        if (!SettingsService::get('referral_enabled', '1') === '0') return false;

        $referralCode = strtoupper(trim($referralCode));

        // Find referrer
        $referrer = Database::fetchOne(
            "SELECT id, email, phone FROM users WHERE referral_code = ? AND id != ? AND is_active = 1",
            [$referralCode, $newUserId]
        );
        if (!$referrer) return false;

        $newUser = Database::fetchOne("SELECT email, phone FROM users WHERE id = ?", [$newUserId]);
        if (!$newUser) return false;

        // Anti-fraud: same email/phone cannot refer itself (already blocked by id != above,
        // but check for multiple accounts with same phone)
        if ($referrer['phone'] && $referrer['phone'] === $newUser['phone']) return false;

        // Monthly referral limit
        $monthLimit = SettingsService::int('referral_monthly_limit', 10);
        $thisMonth  = Database::fetchOne(
            "SELECT COUNT(*) AS cnt FROM referrals
             WHERE referrer_id = ? AND MONTH(created_at) = MONTH(NOW()) AND YEAR(created_at) = YEAR(NOW())",
            [$referrer['id']]
        );
        if ((int)($thisMonth['cnt'] ?? 0) >= $monthLimit) return false;

        // Already referred?
        $existing = Database::fetchOne(
            "SELECT id FROM referrals WHERE referred_id = ?",
            [$newUserId]
        );
        if ($existing) return false;

        Database::execute(
            "UPDATE users SET referred_by = ? WHERE id = ?",
            [$referrer['id'], $newUserId]
        );
        Database::execute(
            "INSERT INTO referrals (referrer_id, referred_id, status, reward_given) VALUES (?, ?, 'pending', 0)",
            [$referrer['id'], $newUserId]
        );

        return true;
    }

    /**
     * Process referral reward after a successful order.
     * Call this when order status becomes 'delivered'.
     */
    public static function processFirstOrderReward(int $userId, int $orderId, float $orderAmount): void
    {
        if (SettingsService::get('referral_enabled', '1') === '0') return;

        $minOrder = SettingsService::float('referral_min_order', 100.0);
        if ($orderAmount < $minOrder) return;

        // Only trigger on first DELIVERED order
        $deliveredCount = Database::fetchOne(
            "SELECT COUNT(*) AS cnt FROM orders WHERE user_id = ? AND status = 'delivered'",
            [$userId]
        );
        if ((int)($deliveredCount['cnt'] ?? 0) !== 1) return; // Exactly 1 means this IS the first

        // Get pending referral for this user
        $referral = Database::fetchOne(
            "SELECT * FROM referrals WHERE referred_id = ? AND status = 'pending' AND reward_given = 0",
            [$userId]
        );
        if (!$referral) return;

        $rewardAmount = SettingsService::float('referral_reward_amount', 25.0);

        // Mark referral completed first (idempotency guard) before crediting
        $stmt = Database::execute(
            "UPDATE referrals
             SET status = 'completed', reward_given = 1, reward_amount = ?, rewarded_at = NOW()
             WHERE id = ? AND reward_given = 0",
            [$rewardAmount, $referral['id']]
        );
        if ($stmt->rowCount() === 0) return; // Already processed

        try {
            // Credit referrer (WalletService handles its own transaction)
            WalletService::credit(
                (int) $referral['referrer_id'],
                $rewardAmount,
                "Referral reward — your friend placed their first order! 🎉",
                $orderId
            );

            // Credit referred user
            WalletService::credit(
                $userId,
                $rewardAmount,
                "Welcome bonus — referral reward for first order 🎁",
                $orderId
            );

            appLog('info', "Referral reward ₹{$rewardAmount} each given for order $orderId");
        } catch (\Throwable $e) {
            appLog('error', "Referral reward failed for order $orderId: " . $e->getMessage());
        }
    }

    /**
     * Get referral stats for a user.
     */
    public static function getStats(int $userId): array
    {
        $user = Database::fetchOne(
            "SELECT referral_code FROM users WHERE id = ?",
            [$userId]
        );
        $stats = Database::fetchOne(
            "SELECT
               COUNT(*)                          AS total_referrals,
               SUM(status = 'completed')         AS successful_referrals,
               COALESCE(SUM(reward_amount), 0)   AS total_earned,
               SUM(status = 'pending')           AS pending_referrals
             FROM referrals WHERE referrer_id = ?",
            [$userId]
        );
        return [
            'referral_code'        => $user['referral_code']              ?? '',
            'total_referrals'      => (int)   ($stats['total_referrals']      ?? 0),
            'successful_referrals' => (int)   ($stats['successful_referrals'] ?? 0),
            'pending_referrals'    => (int)   ($stats['pending_referrals']    ?? 0),
            'total_earned'         => (float) ($stats['total_earned']         ?? 0),
        ];
    }
}
