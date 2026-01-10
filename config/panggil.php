<?php
// Mulai session hanya jika belum ada
if (session_status() === PHP_SESSION_NONE) {
    // Gunakan nama session khusus
    session_name('PANAHAN_TERM_SESS');
    
    // Deteksi domain dan protokol
    $domain = explode(':', $_SERVER['HTTP_HOST'])[0];
    $secure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') || 
              (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    // Set parameters (Metode paling kuat untuk shared hosting)
    session_set_cookie_params([
        'lifetime' => 86400,
        'path' => '/',
        'domain' => $domain,
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    
    // Fallback redundansi dengan ini_set
    ini_set('session.cookie_path', '/');
    ini_set('session.cookie_domain', $domain);
    ini_set('session.cookie_httponly', 1);
    if ($secure) ini_set('session.cookie_secure', 1);
    
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
