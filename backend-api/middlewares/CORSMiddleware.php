<?php
/**
 * CORSMiddleware.php — Cross-Origin Resource Sharing headers
 *
 * Called once at the very top of index.php before routing.
 * Handles preflight OPTIONS requests automatically.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

class CORSMiddleware
{
    public static function handle(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

        // Always reflect the requesting origin back — we own all frontends.
        // Never combine wildcard (*) with Credentials: true (browsers block it).
        if ($origin) {
            header("Access-Control-Allow-Origin: $origin");
            header('Vary: Origin');
        } else {
            header('Access-Control-Allow-Origin: *');
        }

        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept');
        header('Access-Control-Max-Age: 86400'); // Cache preflight for 24h

        // Terminate preflight requests immediately
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }
}
