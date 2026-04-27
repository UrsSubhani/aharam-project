<?php
/**
 * Response.php — Standardised JSON API response helper
 *
 * Every API endpoint returns JSON through this class to ensure
 * a consistent envelope:
 *
 *   {
 *     "success": true|false,
 *     "message": "...",
 *     "data": {...} | null,
 *     "errors": {...} | null,
 *     "meta": {...} | null      ← pagination, timestamps, etc.
 *   }
 */

declare(strict_types=1);

class Response
{
    /**
     * Send a success response.
     *
     * @param mixed       $data    Payload to return (array, object, or null)
     * @param string      $message Human-readable message
     * @param int         $code    HTTP status code (default 200)
     * @param array|null  $meta    Pagination or extra metadata
     */
    public static function success(
        mixed  $data    = null,
        string $message = 'Success',
        int    $code    = 200,
        ?array $meta    = null
    ): void {
        self::send([
            'success' => true,
            'message' => $message,
            'data'    => $data,
            'meta'    => $meta,
        ], $code);
    }

    /**
     * Send an error response.
     *
     * @param string     $message Human-readable error description
     * @param int        $code    HTTP status code (default 400)
     * @param array|null $errors  Field-level validation errors
     */
    public static function error(
        string $message = 'An error occurred',
        int    $code    = 400,
        ?array $errors  = null
    ): void {
        self::send([
            'success' => false,
            'message' => $message,
            'errors'  => $errors,
        ], $code);
    }

    /**
     * 401 Unauthorized — invalid or missing token
     */
    public static function unauthorized(string $message = 'Unauthorized. Please login.'): void
    {
        self::error($message, 401);
    }

    /**
     * 403 Forbidden — authenticated but insufficient permissions
     */
    public static function forbidden(string $message = 'Access denied.'): void
    {
        self::error($message, 403);
    }

    /**
     * 404 Not Found
     */
    public static function notFound(string $message = 'Resource not found.'): void
    {
        self::error($message, 404);
    }

    /**
     * 422 Unprocessable Entity — validation errors
     */
    public static function validationError(array $errors, string $message = 'Validation failed.'): void
    {
        self::send([
            'success' => false,
            'message' => $message,
            'errors'  => $errors,
        ], 422);
    }

    /**
     * 500 Internal Server Error
     */
    public static function serverError(string $message = 'Internal server error.'): void
    {
        self::error($message, 500);
    }

    /**
     * Build paginated response meta block.
     *
     * Usage in controllers:
     *   Response::success($items, 'OK', 200, Response::paginate($total, $page, $limit));
     */
    public static function paginate(int $total, int $page, int $limit): array
    {
        $totalPages = (int) ceil($total / max($limit, 1));
        return [
            'total'        => $total,
            'per_page'     => $limit,
            'current_page' => $page,
            'total_pages'  => $totalPages,
            'has_next'     => $page < $totalPages,
            'has_prev'     => $page > 1,
        ];
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Internal: actually send the JSON response
    // ──────────────────────────────────────────────────────────────────────────

    private static function send(array $body, int $code): void
    {
        // Remove null keys for a cleaner payload
        $body = array_filter($body, fn($v) => $v !== null);

        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');

        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        if (APP_DEBUG) {
            $flags |= JSON_PRETTY_PRINT;
        }

        echo json_encode($body, $flags);
        exit;
    }
}
