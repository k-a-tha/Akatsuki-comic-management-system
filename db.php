<?php
// db.php — database connection + session. Every page includes this first.
// Change these four values if your MySQL user/password is different.
$host     = 'localhost';
$dbname   = 'Anya_Forger';
$username = 'root';
$password = '';

// Keep PHP and MySQL on the same clock so NOW(), "x minutes ago"
// and live-stream start/end times always agree.
date_default_timezone_set('Asia/Dhaka');

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec("SET time_zone = '" . date('P') . "'");
} catch (PDOException $e) {
    http_response_code(500);
    die('<div style="font-family:sans-serif;max-width:640px;margin:60px auto;padding:24px;border:1px solid #e74c3c;border-radius:10px;">'
      . '<h2 style="color:#e74c3c;margin-top:0">Database connection failed</h2>'
      . '<p>' . htmlspecialchars($e->getMessage()) . '</p>'
      . '<p>Check that MySQL is running in XAMPP and that you imported <b>setup_database.sql</b> '
      . 'in phpMyAdmin (it creates the <b>Anya_Forger</b> database).</p></div>');
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/functions.php';
