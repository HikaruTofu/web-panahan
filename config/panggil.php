<?php
// Mulai session hanya jika belum ada
if (session_status() === PHP_SESSION_NONE) {
    // Gunakan pengaturan paling standar tapi pastikan path ke root (/)
    if (!headers_sent()) {
        session_set_cookie_params([
            'path' => '/',
            'samesite' => 'Lax'
        ]);
        
        // Cek jika HTTPS untuk secure cookie
        if ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') || 
            (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')) {
            ini_set('session.cookie_secure', 1);
        }
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
