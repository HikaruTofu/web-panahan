<?php
include '../config/panggil.php';
enforceAdmin();

if (!checkRateLimit('action_load', 60, 60)) {
    header('HTTP/1.1 429 Too Many Requests');
    die('Terlalu banyak permintaan. Silakan coba lagi nanti.');
}

$_GET = cleanInput($_GET);

$kegiatan_id = 11;
$category_id = 4;

// From detail.php line 1730
$pesertaList = [];
$peserta_score = [];

$queryPeserta = "
    SELECT 
        p.id,
        p.nama_peserta,
        p.jenis_kelamin,
        c.name as category_name
    FROM peserta p
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE p.kegiatan_id = ? AND p.category_id = ?
    ORDER BY p.nama_peserta ASC
";
$stmtPeserta = $conn->prepare($queryPeserta);
$stmtPeserta->bind_param("ii", $kegiatan_id, $category_id);
$stmtPeserta->execute();
$resultPeserta = $stmtPeserta->get_result();

while ($row = $resultPeserta->fetch_assoc()) {
    $pesertaList[] = $row;
}
$stmtPeserta->close();

echo "pesertaList count: " . count($pesertaList) . "\n";

// Now calculate scores like in line 1751
$scoreboard_id = 53;
$stmtScore = $conn->prepare("SELECT * FROM score WHERE kegiatan_id=? AND category_id=? AND score_board_id =? AND peserta_id=?");
foreach ($pesertaList as $a) {
    $stmtScore->bind_param("iiii", $kegiatan_id, $category_id, $scoreboard_id, $a['id']);
    $stmtScore->execute();
    $mysql_score_total = $stmtScore->get_result();
    $score = 0;
    $x_score = 0;
    while ($b = $mysql_score_total->fetch_assoc()) {
        if ($b['score'] == 'm' || $b['score'] == 'M') {
            $score = $score + 0;
        } else if ($b['score'] == 'x' || $b['score'] == 'X') {
            $score = $score + 10;
            $x_score = $x_score + 1;
        } else {
            $score = $score + (int) $b['score'];
        }
    }
    $peserta_score[] = ['id' => $a['id'], 'total_score' => $score, 'total_x' => $x_score];
}
$stmtScore->close();

echo "peserta_score count: " . count($peserta_score) . "\n\n";
echo "Top 5 scores:\n";
usort($peserta_score, function ($a, $b) {
    return $b['total_score'] - $a['total_score']; });
for ($i = 0; $i < min(5, count($peserta_score)); $i++) {
    $p = $peserta_score[$i];
    $name = '';
    foreach ($pesertaList as $pl) {
        if ($pl['id'] == $p['id']) {
            $name = $pl['nama_peserta'];
            break;
        }
    }
    echo ($i + 1) . ". $name - " . $p['total_score'] . " points, X: " . $p['total_x'] . "\n";
}
