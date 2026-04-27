<?php
/**
 * JWTService.php — JSON Web Token generation and validation
 *
 * Pure PHP implementation — no external dependencies.
 * Algorithm: HS256 (HMAC-SHA256)
 *
 * Token payload structure:
 *   {
 *     "sub":   <user_id>,
 *     "name":  <user_name>,
 *     "role":  <user_role>,
 *     "email": <email>,
 *     "iat":   <issued_at>,
 *     "exp":   <expires_at>
 *   }
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

class JWTService
{
    private static string $algo = 'sha256';

    /**
     * Generate a signed JWT token.
     *
     * @param array $payload  Must contain at minimum: sub, role
     * @param int   $expiry   Lifetime in seconds (default: JWT_EXPIRY constant)
     */
    public static function generate(array $payload, int $expiry = JWT_EXPIRY): string
    {
        $header = self::base64UrlEncode(json_encode([
            'typ' => 'JWT',
            'alg' => 'HS256',
        ]));

        $now = time();
        $payload = array_merge($payload, [
            'iat' => $now,
            'exp' => $now + $expiry,
            'jti' => bin2hex(random_bytes(8)), // unique token ID
        ]);

        $encodedPayload = self::base64UrlEncode(json_encode($payload));
        $signature      = self::sign("$header.$encodedPayload");

        return "$header.$encodedPayload.$signature";
    }

    /**
     * Verify a token and return decoded payload.
     *
     * @throws RuntimeException  on invalid token structure
     * @throws RuntimeException  on signature mismatch
     * @throws RuntimeException  on expired token
     */
    public static function verify(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new RuntimeException('Invalid token structure.');
        }

        [$header, $payload, $signature] = $parts;

        // Verify signature
        $expectedSig = self::sign("$header.$payload");
        if (!hash_equals($expectedSig, $signature)) {
            throw new RuntimeException('Token signature is invalid.');
        }

        // Decode payload
        $decoded = json_decode(self::base64UrlDecode($payload), true);
        if (!$decoded) {
            throw new RuntimeException('Token payload is malformed.');
        }

        // Check expiry
        if (isset($decoded['exp']) && $decoded['exp'] < time()) {
            throw new RuntimeException('Token has expired.');
        }

        return $decoded;
    }

    /**
     * Decode token without verification (for inspection only — never for auth).
     */
    public static function decode(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        $decoded = json_decode(self::base64UrlDecode($parts[1]), true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Extract the Bearer token from the Authorization header.
     * Returns null if no token found.
     */
    public static function fromHeader(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '';

        // Fallback: getallheaders() covers Apache CGI / FastCGI setups
        if (empty($header) && function_exists('getallheaders')) {
            $all = getallheaders();
            $header = $all['Authorization'] ?? $all['authorization'] ?? '';
        }

        if (empty($header)) {
            return null;
        }

        if (preg_match('/Bearer\s+(.+)/i', $header, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private static function sign(string $data): string
    {
        return self::base64UrlEncode(
            hash_hmac(self::$algo, $data, JWT_SECRET, true)
        );
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4));
    }
}
