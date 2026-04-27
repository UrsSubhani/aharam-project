<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/WhatsAppService.php';
require_once __DIR__ . '/../utils/Response.php';
require_once __DIR__ . '/../utils/Validator.php';
require_once __DIR__ . '/../utils/Helper.php';

class WhatsAppController
{
    // POST /whatsapp/order
    public function simulateOrder(array $params): void
    {
        $data = getRequestData();

        $v = new Validator($data);
        $v->required(['phone', 'message'])->phone('phone');
        if ($v->fails()) {
            Response::validationError($v->errors());
        }

        $result = WhatsAppService::processOrder(
            preg_replace('/[^0-9]/', '', $data['phone']),
            $data['message'],
            !empty($data['restaurant_id']) ? (int) $data['restaurant_id'] : null
        );

        Response::success($result, 'WhatsApp order received and parsed.', 201);
    }
}
