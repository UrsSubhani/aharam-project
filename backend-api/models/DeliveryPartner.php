<?php
declare(strict_types=1);

require_once __DIR__ . '/BaseModel.php';

class DeliveryPartner extends BaseModel
{
    protected string $table = 'delivery_partners';
    protected array $fillable = [
        'user_id', 'vehicle_type', 'vehicle_number', 'license_number',
        'aadhar_number', 'is_verified', 'verification_status', 'is_available',
        'current_lat', 'current_lng', 'location_updated_at',
        'city', 'bank_account', 'ifsc_code',
    ];

    /**
     * Get partner with user info.
     */
    public function getWithUser(int $partnerId): array|false
    {
        return $this->rawOne(
            "SELECT dp.*, u.name, u.email, u.phone, u.profile_image
             FROM delivery_partners dp
             JOIN users u ON u.id = dp.user_id
             WHERE dp.id = ?",
            [$partnerId]
        );
    }

    /**
     * Get partner by user ID.
     */
    public function getByUserId(int $userId): array|false
    {
        return $this->findBy('user_id', $userId);
    }

    /**
     * Find nearest available partner using Haversine in SQL.
     *
     * @param float  $lat       Delivery location latitude
     * @param float  $lng       Delivery location longitude
     * @param float  $radiusKm  Search radius (default 10km)
     */
    public function findNearest(float $lat, float $lng, float $radiusKm = 10.0): array|false
    {
        return $this->rawOne(
            "SELECT dp.*, u.name, u.phone,
                    (6371 * ACOS(
                        COS(RADIANS(?)) * COS(RADIANS(dp.current_lat)) *
                        COS(RADIANS(dp.current_lng) - RADIANS(?)) +
                        SIN(RADIANS(?)) * SIN(RADIANS(dp.current_lat))
                    )) AS distance_km
             FROM delivery_partners dp
             JOIN users u ON u.id = dp.user_id
             WHERE dp.is_available = 1
               AND dp.is_verified  = 1
               AND dp.current_lat IS NOT NULL
               AND dp.current_lng IS NOT NULL
               AND NOT EXISTS (
                   SELECT 1 FROM delivery_orders dod
                   WHERE dod.partner_id = dp.id
                     AND dod.status IN ('assigned','accepted','picked','on_the_way')
               )
             HAVING distance_km <= ?
             ORDER BY distance_km ASC
             LIMIT 1",
            [$lat, $lng, $lat, $radiusKm]
        );
    }

    /**
     * Update partner's GPS location.
     */
    public function updateLocation(int $partnerId, float $lat, float $lng): void
    {
        $this->execute(
            "UPDATE delivery_partners
             SET current_lat = ?, current_lng = ?, location_updated_at = NOW()
             WHERE id = ?",
            [$lat, $lng, $partnerId]
        );
    }

    /**
     * Toggle online/offline status.
     */
    public function setAvailability(int $partnerId, bool $available): void
    {
        $this->execute(
            "UPDATE delivery_partners SET is_available = ? WHERE id = ?",
            [(int) $available, $partnerId]
        );
    }

    /**
     * Increment total deliveries and earnings.
     */
    public function recordDelivery(int $partnerId, float $earnings): void
    {
        $this->execute(
            "UPDATE delivery_partners
             SET total_deliveries = total_deliveries + 1,
                 total_earnings   = total_earnings + ?
             WHERE id = ?",
            [$earnings, $partnerId]
        );
    }
}
