<?php
declare(strict_types=1);

require_once __DIR__ . '/../utils/Response.php';
require_once __DIR__ . '/../config/config.php';

class HealthController
{
    public function check(array $params): void
    {
        try {
            Database::fetchOne("SELECT 1");
            $dbStatus = 'ok';
        } catch (\Exception $e) {
            $dbStatus = 'error';
        }

        Response::success([
            'api'       => 'Aharam Food Delivery API',
            'version'   => '1.0.0',
            'status'    => 'running',
            'database'  => $dbStatus,
            'timestamp' => date('c'),
            'timezone'  => date_default_timezone_get(),
        ]);
    }
}
