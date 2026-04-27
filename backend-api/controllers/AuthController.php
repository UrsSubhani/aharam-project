<?php
/**
 * AuthController.php — Registration, Login, OTP, Password Reset
 *
 * Endpoints:
 *   POST /register
 *   POST /login
 *   POST /logout       (JWT is stateless — client discards token)
 *   GET  /me           (get current user profile)
 *   POST /verify-otp
 *   POST /resend-otp
 *   POST /forgot-password
 *   POST /reset-password
 */

declare(strict_types=1);

require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../services/JWTService.php';
require_once __DIR__ . '/../services/ReferralService.php';
require_once __DIR__ . '/../middlewares/AuthMiddleware.php';
require_once __DIR__ . '/../utils/Response.php';
require_once __DIR__ . '/../utils/Validator.php';
require_once __DIR__ . '/../utils/Helper.php';

class AuthController
{
    private User $user;

    public function __construct()
    {
        $this->user = new User();
    }

    // ── POST /register ────────────────────────────────────────────────────────
    public function register(array $params): void
    {
        $data = getRequestData();

        $v = new Validator($data);
        $v->required(['name', 'email', 'phone', 'password', 'role'])
          ->email('email')
          ->phone('phone')
          ->min('password', 8)
          ->max('name', 100)
          ->in('role', ['customer', 'restaurant_owner', 'delivery_partner']);

        if ($v->fails()) {
            Response::validationError($v->errors());
        }

        if ($this->user->emailExists($data['email'])) {
            Response::error('An account with this email already exists.', 409);
        }
        if ($this->user->phoneExists($data['phone'])) {
            Response::error('An account with this phone number already exists.', 409);
        }

        $userId = $this->user->create([
            'name'          => $data['name'],
            'email'         => strtolower(trim($data['email'])),
            'phone'         => preg_replace('/[^0-9]/', '', $data['phone']),
            'password_hash' => hashPassword($data['password']),
            'role'          => $data['role'],
            'is_active'     => 1,
        ]);

        // Generate referral code for this user
        ReferralService::generateCode((int) $userId, $data['name']);

        // Apply referral code if provided during signup
        $refCode = trim((string) ($data['referral_code'] ?? $_GET['ref'] ?? ''));
        if ($refCode) {
            ReferralService::applyReferral((int) $userId, $refCode);
        }

        $newUser = $this->user->find($userId);
        $token   = $this->generateToken($newUser);

        Response::success(
            [
                'token' => $token,
                'user'  => sanitizeUser($newUser),
            ],
            'Registration successful.',
            201
        );
    }

    // ── POST /login ───────────────────────────────────────────────────────────
    public function login(array $params): void
    {
        $data = getRequestData();

        $v = new Validator($data);
        $v->required(['email', 'password'])->email('email');

        if ($v->fails()) {
            Response::validationError($v->errors());
        }

        $user = $this->user->findByEmail(strtolower(trim($data['email'])));

        if (!$user || !verifyPassword($data['password'], $user['password_hash'])) {
            Response::error('Invalid email or password.', 401);
        }

        if (!$user['is_active']) {
            Response::error('Your account has been deactivated. Please contact support.', 403);
        }

        $this->user->recordLogin($user['id']);
        $token = $this->generateToken($user);

        Response::success([
            'token' => $token,
            'user'  => sanitizeUser($user),
        ], 'Login successful.');
    }

    // ── POST /logout ─────────────────────────────────────────────────────────
    public function logout(array $params): void
    {
        // JWT is stateless. Client should discard the token.
        // For token blacklisting, store token IDs in Redis/DB (future enhancement).
        Response::success(null, 'Logged out successfully.');
    }

    // ── GET /me ───────────────────────────────────────────────────────────────
    public function me(array $params): void
    {
        $payload = AuthMiddleware::requireAuth();
        $user    = $this->user->find($payload['sub']);

        if (!$user) {
            Response::notFound('User not found.');
        }

        // If restaurant owner, attach restaurant info
        $extra = [];
        if ($user['role'] === 'restaurant_owner') {
            $restaurant = Database::fetchOne(
                "SELECT id, name, slug, is_active FROM restaurants WHERE owner_id = ?",
                [$user['id']]
            );
            $extra['restaurant'] = $restaurant ?: null;
        }

        if ($user['role'] === 'delivery_partner') {
            $partner = Database::fetchOne(
                "SELECT id, is_verified, is_available, verification_status,
                        vehicle_type, vehicle_number, total_deliveries, total_earnings,
                        city, bank_account, ifsc_code
                 FROM delivery_partners WHERE user_id = ?",
                [$user['id']]
            );
            $extra['delivery_partner'] = $partner ?: null;
        }

        Response::success(array_merge(sanitizeUser($user), $extra));
    }

    // ── PUT /me ──────────────────────────────────────────────────────────────
    public function updateProfile(array $params): void
    {
        $auth = AuthMiddleware::requireAuth();
        $data = getRequestData();

        $updates = [];
        if (!empty($data['name']))  $updates['name']  = trim($data['name']);
        if (!empty($data['phone'])) $updates['phone'] = trim($data['phone']);

        if (!empty($data['new_password'])) {
            if (empty($data['current_password'])) {
                Response::error('Current password is required.', 400);
            }
            $user = $this->user->find($auth['sub']);
            if (!password_verify($data['current_password'], $user['password_hash'])) {
                Response::error('Current password is incorrect.', 400);
            }
            $updates['password_hash'] = password_hash($data['new_password'], PASSWORD_DEFAULT);
        }

        if (empty($updates)) Response::error('Nothing to update.', 400);

        $this->user->update($auth['sub'], $updates);
        $updated = sanitizeUser($this->user->find($auth['sub']));
        Response::success($updated, 'Profile updated.');
    }

    // ── POST /verify-otp ─────────────────────────────────────────────────────
    public function verifyOtp(array $params): void
    {
        $data = getRequestData();

        $v = new Validator($data);
        $v->required(['phone', 'otp']);
        if ($v->fails()) {
            Response::validationError($v->errors());
        }

        $user = $this->user->findByPhone($data['phone']);
        if (!$user) {
            Response::notFound('Phone number not found.');
        }

        if (!$this->user->verifyOtp($user['id'], $data['otp'])) {
            Response::error('Invalid or expired OTP.', 400);
        }

        $this->user->update($user['id'], ['phone_verified' => 1]);
        $this->user->clearOtp($user['id']);

        Response::success(null, 'Phone number verified successfully.');
    }

    // ── POST /resend-otp ─────────────────────────────────────────────────────
    public function resendOtp(array $params): void
    {
        $data = getRequestData();

        $v = new Validator($data);
        $v->required(['phone'])->phone('phone');
        if ($v->fails()) {
            Response::validationError($v->errors());
        }

        $user = $this->user->findByPhone($data['phone']);
        if (!$user) {
            Response::notFound('Phone number not found.');
        }

        $otp = generateOTP(6);
        $this->user->setOtp($user['id'], $otp, 10);

        // In production: send OTP via SMS gateway (Twilio/Fast2SMS)
        appLog('info', "OTP for {$data['phone']}: $otp"); // Dev only

        Response::success(
            APP_DEBUG ? ['otp' => $otp] : null,
            'OTP sent to your phone number.'
        );
    }

    // ── POST /forgot-password ─────────────────────────────────────────────────
    public function forgotPassword(array $params): void
    {
        $data = getRequestData();

        $v = new Validator($data);
        $v->required(['email'])->email('email');
        if ($v->fails()) {
            Response::validationError($v->errors());
        }

        $user = $this->user->findByEmail(strtolower($data['email']));
        if (!$user) {
            // Don't reveal whether email exists
            Response::success(null, 'If that email exists, a reset link has been sent.');
        }

        $otp = generateOTP(6);
        $this->user->setOtp($user['id'], $otp, 30); // 30 min expiry

        // In production: send via email (PHPMailer / SendGrid)
        appLog('info', "Password reset OTP for {$user['email']}: $otp");

        Response::success(
            APP_DEBUG ? ['otp' => $otp] : null,
            'Password reset OTP sent to your email.'
        );
    }

    // ── POST /reset-password ─────────────────────────────────────────────────
    public function resetPassword(array $params): void
    {
        $data = getRequestData();

        $v = new Validator($data);
        $v->required(['email', 'otp', 'new_password'])
          ->email('email')
          ->min('new_password', 8);
        if ($v->fails()) {
            Response::validationError($v->errors());
        }

        $user = $this->user->findByEmail(strtolower($data['email']));
        if (!$user || !$this->user->verifyOtp($user['id'], $data['otp'])) {
            Response::error('Invalid or expired OTP.', 400);
        }

        $this->user->update($user['id'], ['password_hash' => hashPassword($data['new_password'])]);
        $this->user->clearOtp($user['id']);

        Response::success(null, 'Password reset successfully. Please login.');
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function generateToken(array $user): string
    {
        return JWTService::generate([
            'sub'   => $user['id'],
            'name'  => $user['name'],
            'role'  => $user['role'],
            'email' => $user['email'],
        ]);
    }
}
