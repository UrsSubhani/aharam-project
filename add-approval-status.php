<?php
require_once __DIR__ . '/backend-api/config/database.php';

try {
    // Add approval_status column
    Database::execute("ALTER TABLE restaurants ADD COLUMN approval_status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending' AFTER is_active");
    echo "Column added.\n";
} catch (Exception $e) {
    echo "Column may already exist: " . $e->getMessage() . "\n";
}

// Set approved for all currently active restaurants
Database::execute("UPDATE restaurants SET approval_status = 'approved' WHERE is_active = 1");
echo "Marked active restaurants as approved.\n";

// Restaurants that are inactive but have orders = were previously active = approved+deactivated
Database::execute("UPDATE restaurants SET approval_status = 'approved' WHERE is_active = 0 AND total_orders > 0");
echo "Marked inactive restaurants with orders as approved (deactivated).\n";

echo "Done.\n";
