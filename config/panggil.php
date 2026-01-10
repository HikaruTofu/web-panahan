<?php
// Mulai session hanya jika belum ada
// Mulai session dengan konfigurasi standar
if (session_status() === PHP_SESSION_NONE) {
    if (!headers_sent()) {
        session_set_cookie_params([
            'path' => '/',
            'samesite' => 'Lax'
        ]);
    }
    session_start();
}

// Koneksi database
$sname    = "db";
$uname    = "panahan_app";
$pwd      = "s3cur3_v4ult_P@nahan";
$database = "panahan_turnament_new";

$conn = new mysqli($sname, $uname, $pwd, $database);
// Cek koneksi
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Include Security Helpers
require_once __DIR__ . '/../includes/security.php';
?>
