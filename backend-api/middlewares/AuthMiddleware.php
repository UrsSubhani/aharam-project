<?php
/**
 * AuthMiddleware.php — JWT authentication guard
 *
 * Usage:
 *   $user = AuthMiddleware::requireAuth();           // any authenticated user
 *   $user = AuthMiddleware::requireRole('admin');    // specific role
 *   $user = AuthMiddleware::requireAnyRole(['admin','restaurant_owner']);
 *
 * Each method returns the decoded JWT payload (assoc array) on success
 * or terminates with a 401/403 JSON response on failure.
 */

declare(strict_types=1);

require_once __DIR__ . '/../services/JWTService.php';
require_once __DIR__ . '/../utils/Response.php';

class AuthMiddleware
{
    /**
     * Require a valid JWT. Returns decoded payload or exits with 401.
     */
    public static function requireAuth(): array
    {
        $token = JWTService::fromHeader();

        if (!$token) {
            Response::unauthorized('No authentication token provided.');
        }

        try {
            $payload = JWTService::verify($token);
        } catch (RuntimeException $e) {
            Response::unauthorized($e->getMessage());
        }

        return $payload;
    }

    /**
     * Require auth AND a specific role.
     *
     * @param string $role  One of: customer | restaurant_owner | delivery_partner | admin
     */
    public static function requireRole(string $role): array
    {
        $payload = self::requireAuth();

        if (($payload['role'] ?? '') !== $role) {
            Response::forbidden("This action requires the '$role' role.");
        }

        return $payload;
    }

    /**
     * Require auth AND one of several roles.
     *
     * @param string[] $roles
     */
    public static function requireAnyRole(array $roles): array
    {
        $payload = self::requireAuth();

        if (!in_array($payload['role'] ?? '', $roles, true)) {
            Response::forbidden('You do not have permission to perform this action.');
        }

        return $payload;
    }

    /**
     * Optional auth — returns payload if valid token found, null otherwise.
     * Use for endpoints that behave differently for logged-in users
     * (e.g., personalised restaurant listings).
     */
    public static function optionalAuth(): ?array
    {
        $token = JWTService::fromHeader();
        if (!$token) {
            return null;
        }
        try {
            return JWTService::verify($token);
        } catch (RuntimeException) {
            return null;
        }
    }
}
