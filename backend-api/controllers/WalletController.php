<?php
/**
 * WalletController.php
 *
 * GET  /wallet                — balance + last 20 transactions
 * GET  /wallet/transactions   — paginated transaction history
 * GET  /referral              — referral code, stats, settings
 * POST /referral/apply        — apply a referral code (post-signup)
 */
declare(strict_types=1);

require_once __DIR__ . '/../middlewares/AuthMiddleware.php';
require_once __DIR__ . '/../utils/Response.php';
require_once __DIR__ . '/../services/WalletService.php';
require_once __DIR__ . '/../services/ReferralService.php';
require_once __DIR__ . '/../services/SettingsService.php';

class WalletController
{
    // ── GET /wallet ───────────────────────────────────────────────────────────
    public function balance(array $params): void
    {
        $auth = AuthMiddleware::requireAuth();
        $uid  = (int) $auth['sub'];

        $balance      = WalletService::getBalance($uid);
        $transactions = WalletService::getTransactions($uid, 20, 0);

        Response::success([
            'balance'      => $balance,
            'transactions' => $transactions,
        ]);
    }

    // ── GET /wallet/transactions ──────────────────────────────────────────────
    public function transactions(array $params): void
    {
        $auth = AuthMiddleware::requireAuth();
        ['limit' => $limit, 'offset' => $offset, 'page' => $page] = getPagination(20);
        $uid = (int) $auth['sub'];

        $rows  = WalletService::getTransactions($uid, $limit, $offset);
        $total = Database::fetchOne(
            "SELECT COUNT(*) AS n FROM wallet_transactions WHERE user_id = ?",
            [$uid]
        )['n'] ?? 0;

        Response::paginate((int) $total, $page, $limit, $rows);
    }

    // ── GET /referral ─────────────────────────────────────────────────────────
    public function referral(array $params): void
    {
        $auth  = AuthMiddleware::requireAuth();
        $uid   = (int) $auth['sub'];
        $user  = Database::fetchOne("SELECT name, referral_code FROM users WHERE id = ?", [$uid]);

        // Ensure code exists
        if (empty($user['referral_code'])) {
            ReferralService::generateCode($uid, $user['name'] ?? 'USER');
            $user = Database::fetchOne("SELECT name, referral_code FROM users WHERE id = ?", [$uid]);
        }

        $stats = ReferralService::getStats($uid);

        $appUrl      = defined('APP_URL') ? APP_URL : 'https://aharam.com';
        $code        = $user['referral_code'] ?? '';
        $referralUrl = "$appUrl/customer-app/pages/register.html?ref=$code";

        Response::success([
            'referral_code'     => $code,
            'referral_url'      => $referralUrl,
            'stats'             => $stats,
            'reward_amount'     => SettingsService::float('referral_reward_amount', 25),
            'min_order'         => SettingsService::float('referral_min_order', 100),
            'enabled'           => SettingsService::get('referral_enabled', '1') === '1',
        ]);
    }

    // ── POST /referral/apply ──────────────────────────────────────────────────
    public function applyReferral(array $params): void
    {
        $auth = AuthMiddleware::requireRole('customer');
        $data = getRequestData();
        $uid  = (int) $auth['sub'];

        $code = strtoupper(trim((string) ($data['referral_code'] ?? '')));
        if (!$code) {
            Response::error('Referral code is required.', 400);
        }

        // Check if already used a referral
        $existing = Database::fetchOne(
            "SELECT id FROM referrals WHERE referred_id = ?",
            [$uid]
        );
        if ($existing) {
            Response::error('You have already used a referral code.', 409);
        }

        $applied = ReferralService::applyReferral($uid, $code);
        if (!$applied) {
            Response::error('Invalid or expired referral code.', 400);
        }

        Response::success(null, 'Referral code applied! You will receive a bonus after your first order.');
    }

    // ── GET /admin/wallet ─────────────────────────────────────────────────────
    public function adminWallet(array $params): void
    {
        AuthMiddleware::requireRole('admin');
        ['limit' => $limit, 'offset' => $offset, 'page' => $page] = getPagination(50);

        $stats = WalletService::getAdminStats();
        $txns  = WalletService::getAdminTransactions($limit, $offset);
        $total = Database::fetchOne("SELECT COUNT(*) AS n FROM wallet_transactions")['n'] ?? 0;

        // Top wallet users
        $topUsers = Database::fetchAll(
            "SELECT id, name, email, wallet_balance FROM users WHERE wallet_balance > 0 ORDER BY wallet_balance DESC LIMIT 10"
        );

        Response::success([
            'stats'     => $stats,
            'top_users' => $topUsers,
            'transactions' => $txns,
            'total'        => (int) $total,
            'page'         => $page,
            'limit'        => $limit,
        ]);
    }

    // ── GET /admin/referrals ──────────────────────────────────────────────────
    public function adminReferrals(array $params): void
    {
        AuthMiddleware::requireRole('admin');
        ['limit' => $limit, 'offset' => $offset, 'page' => $page] = getPagination(50);

        $rows = Database::fetchAll(
            "SELECT r.*,
                    ur.name AS referrer_name, ur.email AS referrer_email,
                    uf.name AS referred_name, uf.email AS referred_email
             FROM referrals r
             JOIN users ur ON ur.id = r.referrer_id
             JOIN users uf ON uf.id = r.referred_id
             ORDER BY r.created_at DESC LIMIT ? OFFSET ?",
            [$limit, $offset]
        );
        $total = Database::fetchOne("SELECT COUNT(*) AS n FROM referrals")['n'] ?? 0;

        // Summary stats
        $summary = Database::fetchOne(
            "SELECT COUNT(*) AS total,
                    SUM(status='completed') AS completed,
                    SUM(status='pending') AS pending,
                    COALESCE(SUM(reward_amount),0) AS total_rewarded
             FROM referrals"
        );

        Response::success([
            'summary'    => $summary,
            'referrals'  => $rows,
            'total'      => (int) $total,
            'page'       => $page,
            'limit'      => $limit,
        ]);
    }

    // ── POST /admin/referrals/:id/approve ─────────────────────────────────────
    public function approveReferral(array $params): void
    {
        AuthMiddleware::requireRole('admin');
        $id = (int) ($params['id'] ?? 0);

        $referral = Database::fetchOne(
            "SELECT * FROM referrals WHERE id = ? AND status = 'pending' AND reward_given = 0",
            [$id]
        );
        if (!$referral) {
            Response::error('Referral not found or already processed.', 404);
        }

        $rewardAmount = SettingsService::float('referral_reward_amount', 25.0);

        $stmt = Database::execute(
            "UPDATE referrals SET status = 'completed', reward_given = 1, reward_amount = ?, rewarded_at = NOW()
             WHERE id = ? AND reward_given = 0",
            [$rewardAmount, $id]
        );
        if ($stmt->rowCount() === 0) {
            Response::error('Already processed.', 409);
        }

        WalletService::credit(
            (int) $referral['referrer_id'],
            $rewardAmount,
            "Referral reward — manual approval by admin",
            0
        );
        WalletService::credit(
            (int) $referral['referred_id'],
            $rewardAmount,
            "Welcome bonus — referral reward (manual approval)",
            0
        );

        Response::success(null, "Referral approved. ₹{$rewardAmount} credited to both users.");
    }
}
