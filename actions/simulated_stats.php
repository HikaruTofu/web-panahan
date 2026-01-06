<?php
$conn = new mysqli("db", "root", "root", "panahan_turnament_new");

function getKategoriFromRanking($ranking, $totalPeserta) {
    if ($totalPeserta <= 1) return 'A';
    $persentase = ($ranking / $totalPeserta) * 100;
    if ($ranking <= 3 && $persentase <= 30) return 'A';
    if ($ranking <= 10 && $persentase <= 40) return 'B';
    if ($persentase <= 60) return 'C';
    if ($persentase <= 80) return 'D';
    return 'E';
}

// 1. Get stats for each entry
$query = "SELECT nama_peserta, ranking, category FROM rankings_source";
$res = $conn->query($query);
$categories_total = [];
$res_totals = $conn->query("SELECT category, COUNT(*) as total FROM rankings_source GROUP BY category");
while($row = $res_totals->fetch_assoc()) {
    $categories_total[$row['category']] = $row['total'];
}

$participant_ranks = [];
$res->data_seek(0);
while($row = $res->fetch_assoc()) {
    $kat = getKategoriFromRanking($row['ranking'], $categories_total[$row['category']]);
    $participant_ranks[$row['nama_peserta']][] = $kat;
}

// 2. Determine dominant category for each unique participant
$stats = ['A'=>0, 'B'=>0, 'C'=>0, 'D'=>0, 'E'=>0];
foreach ($participant_ranks as $name => $kats) {
    $counts = array_count_values($kats);
    arsort($counts);
    $dominant = key($counts);
    $stats[$dominant]++;
}

echo "=== OFFICIAL STATS (From rankings_source) ===\n";
foreach($stats as $k => $v) {
    echo "Kategori $k: $v\n";
}
echo "Total Peserta: " . count($participant_ranks) . "\n";
?>
