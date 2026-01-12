<?php
require_once __DIR__ . '/../config/panggil.php';

// Hapus semua data session
$_SESSION = array();

// Hapus session cookie jika ada
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy session
session_destroy();

// Redirect ke halaman login dengan pesan sukses
session_write_close();
header('Location: ../index.php?message=logout_success');
exit;
?>