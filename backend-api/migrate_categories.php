<?php
require_once __DIR__ . '/config/database.php';

try {
    // Find the actual FK name
    $fks = Database::fetchAll(
        "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'menu_categories'
         AND COLUMN_NAME = 'restaurant_id' AND REFERENCED_TABLE_NAME IS NOT NULL"
    );

    foreach ($fks as $fk) {
        Database::execute("ALTER TABLE menu_categories DROP FOREIGN KEY `{$fk['CONSTRAINT_NAME']}`");
        echo "Dropped FK: {$fk['CONSTRAINT_NAME']}<br>";
    }

    Database::execute("ALTER TABLE menu_categories MODIFY COLUMN restaurant_id INT(11) NULL DEFAULT NULL");
    echo "Column altered to allow NULL.<br>";

    Database::execute("ALTER TABLE menu_categories ADD CONSTRAINT fk_mcat_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE");
    echo "<br><b style='color:green'>✓ Done! Global categories are now supported.</b>";
    echo "<br><br>You can now delete this file: <code>backend-api/migrate_categories.php</code>";
} catch (Exception $e) {
    echo "<b style='color:red'>Error: " . htmlspecialchars($e->getMessage()) . "</b>";
}
