<?php
declare(strict_types=1);

require_once __DIR__ . '/../middlewares/AuthMiddleware.php';
require_once __DIR__ . '/../utils/Response.php';

class NotificationController
{
    // GET /notifications
    public function index(array $params): void
    {
        $auth = AuthMiddleware::requireAuth();

        $notifications = Database::fetchAll(
            "SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 30",
            [$auth['sub']]
        );

        $unread = Database::fetchOne(
            "SELECT COUNT(*) AS n FROM notifications WHERE user_id = ? AND is_read = 0",
            [$auth['sub']]
        )['n'] ?? 0;

        Response::success([
            'notifications' => $notifications,
            'unread_count'  => (int) $unread,
        ]);
    }

    // PATCH /notifications/read
    public function markRead(array $params): void
    {
        $auth = AuthMiddleware::requireAuth();
        $data = getRequestData();

        if (!empty($data['id'])) {
            Database::execute(
                "UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?",
                [(int) $data['id'], $auth['sub']]
            );
        } else {
            // Mark all as read
            Database::execute(
                "UPDATE notifications SET is_read = 1 WHERE user_id = ?",
                [$auth['sub']]
            );
        }

        Response::success(null, 'Notifications marked as read.');
    }
}
