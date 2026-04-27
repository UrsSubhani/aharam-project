<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/RecommendationService.php';
require_once __DIR__ . '/../middlewares/AuthMiddleware.php';
require_once __DIR__ . '/../utils/Response.php';

class RecommendationController
{
    // GET /recommendations
    public function index(array $params): void
    {
        $auth   = AuthMiddleware::optionalAuth();
        $userId = $auth ? (int) $auth['sub'] : 0;
        $city   = trim($_GET['city'] ?? 'Chennai');

        $recs = RecommendationService::forUser($userId, $city);
        Response::success($recs);
    }

    // GET /recommendations/trending
    public function trending(array $params): void
    {
        $city  = trim($_GET['city'] ?? 'Chennai');
        $limit = min(20, max(1, (int) ($_GET['limit'] ?? 10)));
        $items = RecommendationService::getTrendingItems($city, $limit);
        Response::success($items);
    }
}
