<?php
include "../config/panggil.php";

if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    $id = $_GET['id'];
    $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
    $stmt->bind_param("i", $id); 
    $stmt->execute();

    if ($stmt->affected_rows > 0) {
        header("Location: ../views/users.php");
        exit();
    } else {
        echo "Data gagal dihapus atau tidak ditemukan.";
    }
}
?>