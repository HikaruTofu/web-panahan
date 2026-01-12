<?php
// Mulai session hanya jika belum ada
if (session_status() === PHP_SESSION_NONE) {
    // Jalur absolut untuk folder sessions (Nuclear Fix untuk shared hosting)
    $sessionPath = dirname(__DIR__) . '/sessions';
    if (!is_dir($sessionPath)) {
        @mkdir($sessionPath, 0777, true);
        @file_put_contents($sessionPath . '/.htaccess', "Deny from all");
    }
    
    // Paksa PHP menggunakan folder ini agar session sinkron antar folder
    if (is_writable($sessionPath)) {
        session_save_path($sessionPath);
        ini_set('session.save_path', $sessionPath);
    }

    // Konfigurasi cookie standar
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

// Data Recovery Configuration
if (!defined('RECOVERY_BACKUP_FILE')) {
    define('RECOVERY_BACKUP_FILE', dirname(__DIR__) . '/sessions/recovery_backups.json');
}
require_once __DIR__ . '/../includes/recovery.php';

// Auto-cleanup old backups (10% chance to run on page load to save resources)
if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin' && rand(1, 100) <= 10) {
    cleanup_old_backups();
}
