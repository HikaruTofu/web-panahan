<?php
require_once __DIR__ . '/../config/panggil.php';
require_once __DIR__ . '/../includes/security.php';

// Only staff can access detailed stats via AJAX if needed, though statistics is generally open
// We reuse the session check from panggil.php implicitly

header('Content-Type: application/json');

if (!isset($_GET['nama'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Nama peserta tidak ditentukan']);
    exit;
}

$peserta_nama = $_GET['nama'];

$stats = [
    'bracket_champion' => 0,
    'bracket_runner_up' => 0,
    'bracket_third_place' => 0,
    'bracket_matches_won' => 0,
    'bracket_matches_lost' => 0
];

// 1. Champion Count
$queryChampion = "SELECT COUNT(*) as total FROM bracket_champions bc
                    INNER JOIN peserta p ON bc.champion_id = p.id
                    WHERE p.nama_peserta = ?";
$stmtChampion = $conn->prepare($queryChampion);
$stmtChampion->bind_param("s", $peserta_nama);
$stmtChampion->execute();
$resultChampion = $stmtChampion->get_result();
if ($row = $resultChampion->fetch_assoc()) {
    $stats['bracket_champion'] = $row['total'];
}
$stmtChampion->close();

// 2. Runner Up Count
$queryRunnerUp = "SELECT COUNT(*) as total FROM bracket_champions bc
                    INNER JOIN peserta p ON bc.runner_up_id = p.id
                    WHERE p.nama_peserta = ?";
$stmtRunnerUp = $conn->prepare($queryRunnerUp);
$stmtRunnerUp->bind_param("s", $peserta_nama);
$stmtRunnerUp->execute();
$resultRunnerUp = $stmtRunnerUp->get_result();
if ($row = $resultRunnerUp->fetch_assoc()) {
    $stats['bracket_runner_up'] = $row['total'];
}
$stmtRunnerUp->close();

// 3. Third Place Count
$queryThird = "SELECT COUNT(*) as total FROM bracket_champions bc
                INNER JOIN peserta p ON bc.third_place_id = p.id
                WHERE p.nama_peserta = ?";
$stmtThird = $conn->prepare($queryThird);
$stmtThird->bind_param("s", $peserta_nama);
$stmtThird->execute();
$resultThird = $stmtThird->get_result();
if ($row = $resultThird->fetch_assoc()) {
    $stats['bracket_third_place'] = $row['total'];
}
$stmtThird->close();

// Total tournament bracket participation
$stats['total_bracket'] = $stats['bracket_champion'] + $stats['bracket_runner_up'] + $stats['bracket_third_place'];

echo json_encode($stats);
