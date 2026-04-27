<?php
// Run once: http://localhost/aharam/add-pickup-location.php
// DELETE after running!
$pdo = new PDO('mysql:host=127.0.0.1;port=3307;dbname=aharam_db', 'root', '');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
    $pdo->exec("ALTER TABLE orders
        ADD COLUMN pickup_address TEXT NULL AFTER delivery_lng,
        ADD COLUMN pickup_lat DECIMAL(10,7) NULL AFTER pickup_address,
        ADD COLUMN pickup_lng DECIMAL(10,7) NULL AFTER pickup_lat");
    echo "<h2 style='color:green'>✅ Columns added! Delete this file now.</h2>";
} catch (Exception $e) {
    if (str_contains($e->getMessage(), 'Duplicate column')) {
        echo "<h2 style='color:orange'>⚠️ Columns already exist.</h2>";
    } else {
        echo "<h2 style='color:red'>❌ " . htmlspecialchars($e->getMessage()) . "</h2>";
    }
}
