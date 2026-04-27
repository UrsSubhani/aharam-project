<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/SettingsService.php';

class WalletService
{
    /**
     * Credit wallet atomically. Returns new balance.
     */
    public static function credit(
        int    $userId,
        float  $amount,
        string $description,
        ?int   $referenceId = null
    ): float {
        $ownTx = !Database::inTransaction();
        if ($ownTx) Database::beginTransaction();
        try {
            $row = Database::fetchOne(
                "SELECT wallet_balance FROM users WHERE id = ? FOR UPDATE",
                [$userId]
            );
            if (!$row) throw new \RuntimeException("User $userId not found");

            $before = (float) $row['wallet_balance'];
            $after  = round($before + $amount, 2);

            // Compute expiry if wallet_expiry_days > 0
            $expiryDays = SettingsService::int('wallet_expiry_days', 0);
            $expiresAt  = $expiryDays > 0
                ? date('Y-m-d H:i:s', strtotime("+$expiryDays days"))
                : null;

            Database::execute(
                "UPDATE users SET wallet_balance = ? WHERE id = ?",
                [$after, $userId]
            );
            Database::execute(
                "INSERT INTO wallet_transactions
                   (user_id, type, amount, description, reference_id, balance_before, balance_after, expires_at)
                 VALUES (?, 'credit', ?, ?, ?, ?, ?, ?)",
                [$userId, $amount, $description, $referenceId, $before, $after, $expiresAt]
            );

            if ($ownTx) Database::commit();
            return $after;
        } catch (\Throwable $e) {
            if ($ownTx) Database::rollback();
            throw $e;
        }
    }

    /**
     * Debit wallet atomically. Throws if insufficient balance. Returns new balance.
     */
    public static function debit(
        int    $userId,
        float  $amount,
        string $description,
        ?int   $referenceId = null
    ): float {
        $ownTx = !Database::inTransaction();
        if ($ownTx) Database::beginTransaction();
        try {
            $row = Database::fetchOne(
                "SELECT wallet_balance FROM users WHERE id = ? FOR UPDATE",
                [$userId]
            );
            if (!$row) throw new \RuntimeException("User $userId not found");

            $before = (float) $row['wallet_balance'];
            if ($before < $amount - 0.001) {
                if ($ownTx) Database::rollback();
                throw new \RuntimeException("Insufficient wallet balance");
            }

            $after = round(max(0, $before - $amount), 2);

            Database::execute(
                "UPDATE users SET wallet_balance = ? WHERE id = ?",
                [$after, $userId]
            );
            Database::execute(
                "INSERT INTO wallet_transactions
                   (user_id, type, amount, description, reference_id, balance_before, balance_after)
                 VALUES (?, 'debit', ?, ?, ?, ?, ?)",
                [$userId, $amount, $description, $referenceId, $before, $after]
            );

            if ($ownTx) Database::commit();
            return $after;
        } catch (\Throwable $e) {
            if ($ownTx) Database::rollback();
            throw $e;
        }
    }

    public static function getBalance(int $userId): float
    {
        $row = Database::fetchOne("SELECT wallet_balance FROM users WHERE id = ?", [$userId]);
        return $row ? (float) $row['wallet_balance'] : 0.0;
    }

    public static function getTransactions(int $userId, int $limit = 50, int $offset = 0): array
    {
        return Database::fetchAll(
            "SELECT * FROM wallet_transactions WHERE user_id = ?
             ORDER BY created_at DESC LIMIT ? OFFSET ?",
            [$userId, $limit, $offset]
        );
    }

    public static function getAdminTransactions(int $limit = 100, int $offset = 0): array
    {
        return Database::fetchAll(
            "SELECT wt.*, u.name AS user_name, u.email AS user_email
             FROM wallet_transactions wt
             JOIN users u ON u.id = wt.user_id
             ORDER BY wt.created_at DESC LIMIT ? OFFSET ?",
            [$limit, $offset]
        );
    }

    public static function getAdminStats(): array
    {
        $stats = Database::fetchOne(
            "SELECT
               SUM(CASE WHEN type='credit' THEN amount ELSE 0 END) AS total_credited,
               SUM(CASE WHEN type='debit'  THEN amount ELSE 0 END) AS total_debited,
               COUNT(*) AS total_transactions
             FROM wallet_transactions"
        );
        $balances = Database::fetchOne(
            "SELECT SUM(wallet_balance) AS total_outstanding FROM users WHERE wallet_balance > 0"
        );
        return [
            'total_credited'     => (float) ($stats['total_credited']  ?? 0),
            'total_debited'      => (float) ($stats['total_debited']   ?? 0),
            'total_transactions' => (int)   ($stats['total_transactions'] ?? 0),
            'total_outstanding'  => (float) ($balances['total_outstanding'] ?? 0),
        ];
    }
}
