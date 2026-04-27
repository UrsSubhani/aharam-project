<?php
require_once __DIR__ . '/config/database.php';

echo "<h3>Coupons table columns:</h3><pre>";
$cols = Database::fetchAll("SHOW COLUMNS FROM coupons");
foreach ($cols as $col) {
    echo $col['Field'] . " — " . $col['Type'] . " (null: " . $col['Null'] . ", default: " . $col['Default'] . ")\n";
}
echo "</pre>";
