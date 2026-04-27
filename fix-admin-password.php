<?php
// Run once to fix admin password: http://localhost/aharam/fix-admin-password.php
// DELETE THIS FILE after running!

$host = '127.0.0.1';
$port = 3307;
$db   = 'aharam_db';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$db", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $newPassword = 'Admin@1234';
    $hash = password_hash($newPassword, PASSWORD_BCRYPT);

    $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE email = 'admin@aharam.in'");
    $stmt->execute([$hash]);

    if ($stmt->rowCount() > 0) {
        echo "<h2 style='color:green'>✅ Admin password updated!</h2>";
        echo "<p><strong>Email:</strong> admin@aharam.in</p>";
        echo "<p><strong>Password:</strong> Admin@1234</p>";
        echo "<p style='color:red'><strong>Delete this file now!</strong></p>";
    } else {
        echo "<h2 style='color:orange'>⚠️ Admin user not found in database.</h2>";
        echo "<p>The seed.sql may not have been imported yet.</p>";
        echo "<p>Go to <a href='http://localhost/phpmyadmin'>phpMyAdmin</a> → Import → select <code>database/seed.sql</code></p>";
    }
} catch (Exception $e) {
    echo "<h2 style='color:red'>❌ DB Error: " . htmlspecialchars($e->getMessage()) . "</h2>";
    echo "<p>Check DB_PORT in backend-api/.env — try 3306 or 3307</p>";
}
