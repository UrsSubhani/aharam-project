<?php
declare(strict_types=1);

require_once __DIR__ . '/../middlewares/AuthMiddleware.php';
require_once __DIR__ . '/../utils/Response.php';
require_once __DIR__ . '/../utils/Validator.php';
require_once __DIR__ . '/../utils/Helper.php';

class AddressController
{
    public function index(array $params): void
    {
        $auth = AuthMiddleware::requireAuth();
        $addresses = Database::fetchAll(
            "SELECT * FROM user_addresses WHERE user_id = ? ORDER BY is_default DESC, id DESC",
            [$auth['sub']]
        );
        Response::success($addresses);
    }

    public function create(array $params): void
    {
        $auth = AuthMiddleware::requireAuth();
        $data = getRequestData();

        // Accept both field name styles from different frontends
        $data['address'] = $data['address'] ?? $data['address_line1'] ?? '';
        $data['latitude']  = $data['latitude']  ?? $data['lat']  ?? null;
        $data['longitude'] = $data['longitude'] ?? $data['lng']  ?? null;

        $v = new Validator($data);
        $v->required(['address', 'city', 'pincode']);
        if ($v->fails()) {
            Response::validationError($v->errors());
        }

        // If marked default, unset others
        if (!empty($data['is_default'])) {
            Database::execute(
                "UPDATE user_addresses SET is_default = 0 WHERE user_id = ?",
                [$auth['sub']]
            );
        }

        Database::execute(
            "INSERT INTO user_addresses (user_id, label, address, city, pincode, latitude, longitude, is_default)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $auth['sub'],
                $data['label'] ?? 'Home',
                $data['address'],
                $data['city'],
                $data['pincode'],
                $data['latitude'] ?? null,
                $data['longitude'] ?? null,
                !empty($data['is_default']) ? 1 : 0,
            ]
        );

        Response::success(['address_id' => (int) Database::lastInsertId()], 'Address saved.', 201);
    }

    public function update(array $params): void
    {
        $auth = AuthMiddleware::requireAuth();
        $id   = (int) ($params['id'] ?? 0);
        $data = getRequestData();

        Database::execute(
            "UPDATE user_addresses SET label=?, address=?, city=?, pincode=? WHERE id=? AND user_id=?",
            [$data['label'] ?? 'Home', $data['address'] ?? '', $data['city'] ?? '', $data['pincode'] ?? '', $id, $auth['sub']]
        );
        Response::success(null, 'Address updated.');
    }

    public function delete(array $params): void
    {
        $auth = AuthMiddleware::requireAuth();
        $id   = (int) ($params['id'] ?? 0);
        Database::execute("DELETE FROM user_addresses WHERE id = ? AND user_id = ?", [$id, $auth['sub']]);
        Response::success(null, 'Address deleted.');
    }

    public function setDefault(array $params): void
    {
        $auth = AuthMiddleware::requireAuth();
        $id   = (int) ($params['id'] ?? 0);
        Database::execute("UPDATE user_addresses SET is_default = 0 WHERE user_id = ?", [$auth['sub']]);
        Database::execute("UPDATE user_addresses SET is_default = 1 WHERE id = ? AND user_id = ?", [$id, $auth['sub']]);
        Response::success(null, 'Default address updated.');
    }
}
