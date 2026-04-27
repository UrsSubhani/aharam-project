<?php
declare(strict_types=1);

require_once __DIR__ . '/BaseModel.php';

class User extends BaseModel
{
    protected string $table = 'users';
    protected array $fillable = [
        'name', 'email', 'phone', 'password_hash', 'role',
        'profile_image', 'is_active', 'email_verified', 'phone_verified',
        'otp_code', 'otp_expires_at', 'last_login', 'last_login_at', 'fcm_token',
        'wallet_balance', 'referral_code', 'referred_by',
    ];

    public function findByEmail(string $email): array|false
    {
        return $this->findBy('email', $email);
    }

    public function findByPhone(string $phone): array|false
    {
        return $this->findBy('phone', $phone);
    }

    public function emailExists(string $email): bool
    {
        return $this->exists(['email' => $email]);
    }

    public function phoneExists(string $phone): bool
    {
        return $this->exists(['phone' => $phone]);
    }

    /**
     * Update last login timestamp.
     */
    public function recordLogin(int $id): void
    {
        $this->execute(
            "UPDATE users SET last_login = NOW() WHERE id = ?",
            [$id]
        );
    }

    /**
     * Set OTP for password reset / phone verification.
     */
    public function setOtp(int $id, string $otp, int $expiryMinutes = 10): void
    {
        $this->execute(
            "UPDATE users SET otp_code = ?, otp_expires_at = DATE_ADD(NOW(), INTERVAL ? MINUTE) WHERE id = ?",
            [$otp, $expiryMinutes, $id]
        );
    }

    /**
     * Verify OTP — returns true if valid and not expired.
     */
    public function verifyOtp(int $id, string $otp): bool
    {
        $row = $this->rawOne(
            "SELECT id FROM users WHERE id = ? AND otp_code = ? AND otp_expires_at > NOW()",
            [$id, $otp]
        );
        return (bool) $row;
    }

    /**
     * Clear OTP after use.
     */
    public function clearOtp(int $id): void
    {
        $this->execute(
            "UPDATE users SET otp_code = NULL, otp_expires_at = NULL WHERE id = ?",
            [$id]
        );
    }

    public function getActiveCustomers(): array
    {
        return $this->raw(
            "SELECT id, name, email, phone, created_at FROM users WHERE role = 'customer' AND is_active = 1 ORDER BY created_at DESC"
        );
    }

    public function getStats(): array
    {
        return $this->rawOne(
            "SELECT
               COUNT(*) AS total,
               SUM(role = 'customer') AS customers,
               SUM(role = 'restaurant_owner') AS restaurant_owners,
               SUM(role = 'delivery_partner') AS delivery_partners,
               SUM(is_active = 1) AS active
             FROM users"
        );
    }
}
