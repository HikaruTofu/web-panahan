<?php
// Mulai session hanya jika belum ada
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Koneksi database
$sname    = "db";
$uname    = "root";
$pwd      = "root";
$database = "panahan_turnament_new";

$conn = new mysqli($sname, $uname, $pwd, $database);
// Cek koneksi
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>
