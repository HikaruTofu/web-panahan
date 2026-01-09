<?php
/**
 * Statistik & Penilaian Peserta
 * UI: Intentional Minimalism with Tailwind CSS (consistent with Dashboard)
 */
include '../config/panggil.php';
include '../includes/check_access.php';
include '../includes/theme.php';
require_once '../includes/security.php';
requireLogin(); // Ganti dari requireAdmin agar user biasa bisa melihat statistik

if (!checkRateLimit('view_load', 60, 60)) {
    header('HTTP/1.1 429 Too Many Requests');
    die('Terlalu banyak permintaan. Silakan coba lagi nanti.');
}

$_GET = cleanInput($_GET);
$_POST = cleanInput($_POST);

// ============================================
// HELPER FUNCTIONS (UNCHANGED)
// ============================================

function getKategoriFromRanking($ranking, $totalPeserta)
{
    if ($totalPeserta <= 1) {
        return ['kategori' => 'A', 'label' => 'Sangat Baik', 'color' => 'emerald', 'icon' => 'trophy'];
    }

    $persentase = ($ranking / $totalPeserta) * 100;

    if ($ranking <= 3 && $persentase <= 30) {
        return ['kategori' => 'A', 'label' => 'Sangat Baik', 'color' => 'emerald', 'icon' => 'trophy'];
    } elseif ($ranking <= 10 && $persentase <= 40) {
        return ['kategori' => 'B', 'label' => 'Baik', 'color' => 'blue', 'icon' => 'medal'];
    } elseif ($persentase <= 60) {
        return ['kategori' => 'C', 'label' => 'Cukup', 'color' => 'cyan', 'icon' => 'award'];
    } elseif ($persentase <= 80) {
        return ['kategori' => 'D', 'label' => 'Perlu Latihan', 'color' => 'amber', 'icon' => 'trending-up'];
    } else {
        return ['kategori' => 'E', 'label' => 'Pemula', 'color' => 'slate', 'icon' => 'user'];
    }
}

function getKategoriDominan($rankings)
{
    if (empty($rankings)) {
        return ['kategori' => 'E', 'label' => 'Belum Bertanding', 'color' => 'slate', 'icon' => 'user'];
    }

    $kategoriCount = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'E' => 0];

    foreach ($rankings as $rank) {
        $kat = getKategoriFromRanking($rank['ranking'], $rank['total_peserta']);
        $kategoriCount[$kat['kategori']]++;
    }

    arsort($kategoriCount);
    $dominan = key($kategoriCount);

    $mapping = [
        'A' => ['kategori' => 'A', 'label' => 'Sangat Baik', 'color' => 'emerald', 'icon' => 'trophy'],
        'B' => ['kategori' => 'B', 'label' => 'Baik', 'color' => 'blue', 'icon' => 'medal'],
        'C' => ['kategori' => 'C', 'label' => 'Cukup', 'color' => 'cyan', 'icon' => 'award'],
        'D' => ['kategori' => 'D', 'label' => 'Perlu Latihan', 'color' => 'amber', 'icon' => 'trending-up'],
        'E' => ['kategori' => 'E', 'label' => 'Pemula', 'color' => 'slate', 'icon' => 'user'],
    ];

    return $mapping[$dominan];
}

function getBracketStatistics($conn, $peserta_nama)
{
    $stats = [
        'total_bracket' => 0,
        'bracket_champion' => 0,
        'bracket_runner_up' => 0,
        'bracket_third_place' => 0,
        'bracket_matches_won' => 0,
        'bracket_matches_lost' => 0,
        'bracket_history' => []
    ];

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

    $queryWon = "SELECT COUNT(*) as total FROM bracket_matches bm
                 INNER JOIN peserta p ON bm.winner_id = p.id
                 WHERE p.nama_peserta = ?";
    $stmtWon = $conn->prepare($queryWon);
    $stmtWon->bind_param("s", $peserta_nama);
    $stmtWon->execute();
    $resultWon = $stmtWon->get_result();
    if ($row = $resultWon->fetch_assoc()) {
        $stats['bracket_matches_won'] = $row['total'];
    }
    $stmtWon->close();

    $queryLost = "SELECT COUNT(*) as total FROM bracket_matches bm
                  INNER JOIN peserta p ON bm.loser_id = p.id
                  WHERE p.nama_peserta = ?";
    $stmtLost = $conn->prepare($queryLost);
    $stmtLost->bind_param("s", $peserta_nama);
    $stmtLost->execute();
    $resultLost = $stmtLost->get_result();
    if ($row = $resultLost->fetch_assoc()) {
        $stats['bracket_matches_lost'] = $row['total'];
    }
    $stmtLost->close();

    $queryHistory = "
        SELECT DISTINCT
            bc.kegiatan_id,
            bc.category_id,
            bc.scoreboard_id,
            k.nama_kegiatan,
            c.name as category_name,
            bc.champion_id,
            bc.runner_up_id,
            bc.third_place_id,
            bc.bracket_size,
            bc.created_at,
            CASE
                WHEN p1.nama_peserta = ? THEN 'champion'
                WHEN p2.nama_peserta = ? THEN 'runner_up'
                WHEN p3.nama_peserta = ? THEN 'third_place'
                ELSE 'participant'
            END as position
        FROM bracket_champions bc
        INNER JOIN kegiatan k ON bc.kegiatan_id = k.id
        INNER JOIN categories c ON bc.category_id = c.id
        LEFT JOIN peserta p1 ON bc.champion_id = p1.id
        LEFT JOIN peserta p2 ON bc.runner_up_id = p2.id
        LEFT JOIN peserta p3 ON bc.third_place_id = p3.id
        WHERE p1.nama_peserta = ? OR p2.nama_peserta = ? OR p3.nama_peserta = ?
        ORDER BY bc.created_at DESC
    ";

    $stmtHistory = $conn->prepare($queryHistory);
    $stmtHistory->bind_param("ssssss", $peserta_nama, $peserta_nama, $peserta_nama, $peserta_nama, $peserta_nama, $peserta_nama);
    $stmtHistory->execute();
    $resultHistory = $stmtHistory->get_result();

    while ($row = $resultHistory->fetch_assoc()) {
        $stats['bracket_history'][] = $row;
    }
    $stmtHistory->close();

    $stats['total_bracket'] = count($stats['bracket_history']);

    return $stats;
}

// ============================================
// DATA FETCHING & FILTERING
// ============================================

// Activity Filter Logic
$kegiatan_id = isset($_GET['kegiatan_id']) ? ($_GET['kegiatan_id'] === 'all' ? 'all' : intval($_GET['kegiatan_id'])) : 'all';

// Fetch all activities for dropdown
$kegiatanList = [];
$queryKegiatan = "SELECT id, nama_kegiatan FROM kegiatan ORDER BY id DESC";
$resultKegiatan = $conn->query($queryKegiatan);
if ($resultKegiatan) {
    while ($row = $resultKegiatan->fetch_assoc()) {
        $kegiatanList[] = $row;
    }
}

$queryClubs = "SELECT DISTINCT nama_club FROM peserta WHERE nama_club IS NOT NULL AND nama_club != ''" . (($kegiatan_id !== 'all') ? " AND kegiatan_id = $kegiatan_id" : "") . " ORDER BY nama_club ASC";
$resultClubs = $conn->query($queryClubs);
$clubs = [];
while ($row = $resultClubs->fetch_assoc()) {
    $clubs[] = $row['nama_club'];
}

// DYNAMIC RANKING: Shift authority to rankings_source with Activity Filtering
$allRankings = [];

$keg_filter_scores = ($kegiatan_id !== 'all') ? " AND s.kegiatan_id = $kegiatan_id" : "";
    $keg_filter_official = "";
    if ($kegiatan_id !== 'all' && $kegiatan_id != 11) {
        // rankings_source table has no kegiatan_id column and covers only Activity 11
        // If filtering for another specific activity, official ranks should be empty
        $keg_filter_official = " WHERE 1=0 ";
    }

$queryAllRanks = "
    WITH 
    -- 1. Calculate Dynamic Rankings from score table as fallback
    ScoreStats AS (
        SELECT
            s.kegiatan_id,
            s.score_board_id,
            s.category_id,
            s.peserta_id,
            MAX(p.nama_peserta) as nama_peserta,
            SUM(CASE WHEN LOWER(s.score) = 'x' THEN 10 WHEN LOWER(s.score) = 'm' THEN 0 ELSE CAST(s.score AS UNSIGNED) END) as total_score,
            SUM(CASE WHEN LOWER(s.score) = 'x' THEN 1 ELSE 0 END) as total_x
        FROM score s
        JOIN peserta p ON s.peserta_id = p.id
        WHERE 1=1 $keg_filter_scores
        GROUP BY s.kegiatan_id, s.score_board_id, s.category_id, s.peserta_id
    ),
    CalculatedRanks AS (
        SELECT
            ss.nama_peserta,
            k.nama_kegiatan,
            COALESCE(c.name, c2.name) as category_name,
            ss.kegiatan_id,
            RANK() OVER (PARTITION BY ss.kegiatan_id, ss.score_board_id ORDER BY ss.total_score DESC, ss.total_x DESC) as ranking,
            COUNT(*) OVER (PARTITION BY ss.kegiatan_id, ss.score_board_id) as board_participants
        FROM ScoreStats ss
        JOIN kegiatan k ON ss.kegiatan_id = k.id
        LEFT JOIN score_boards sb ON ss.score_board_id = sb.id
        LEFT JOIN categories c ON sb.category_id = c.id
        LEFT JOIN categories c2 ON ss.category_id = c2.id
    ),
    -- 2. Official Rankings from databaru.txt (rankings_source table)
    OfficialRanks AS (
        SELECT 
            nama_peserta COLLATE utf8mb4_general_ci as nama_peserta, 
            'Turnamen' as nama_kegiatan,
            category as category_name,
            ranking, 
            total_participants as board_participants
        FROM rankings_source
        $keg_filter_official
    ),
    -- 3. Unified dataset: Merge Official (Act 11) + Calculated (Others + Missing Act 11)
    UnifiedRankings AS (
        SELECT nama_peserta, nama_kegiatan, category_name, ranking as rank_pos, board_participants FROM OfficialRanks
        UNION ALL
        SELECT cr.nama_peserta COLLATE utf8mb4_general_ci as nama_peserta, cr.nama_kegiatan, cr.category_name, cr.ranking as rank_pos, cr.board_participants 
        FROM CalculatedRanks cr
        WHERE cr.kegiatan_id != 11 
        OR NOT EXISTS (
            SELECT 1 FROM OfficialRanks orf 
            WHERE LOWER(TRIM(orf.nama_peserta)) = LOWER(TRIM(cr.nama_peserta COLLATE utf8mb4_general_ci))
        )
    )
    SELECT
        ur.nama_peserta,
        ur.rank_pos,
        ur.board_participants as total_participants,
        ur.nama_kegiatan,
        ur.category_name,
        NOW() as tanggal
    FROM UnifiedRankings ur
    ORDER BY ur.rank_pos ASC
";

$resultAllRanks = $conn->query($queryAllRanks);
if ($resultAllRanks) {
    while ($row = $resultAllRanks->fetch_assoc()) {
        $allRankings[strtolower(trim($row['nama_peserta']))][] = [
            'ranking' => $row['rank_pos'],
            'turnamen' => $row['nama_kegiatan'],
            'kategori' => $row['category_name'],
            'tanggal' => $row['tanggal'],
            'kategori_ranking' => getKategoriFromRanking($row['rank_pos'], $row['total_participants']),
            'total_peserta' => $row['total_participants']
        ];
    }
}

// Excel Export
if (isset($_GET['export']) && $_GET['export'] == 'excel') {
    require '../vendor/vendor/autoload.php';
    
    // use PhpOffice\PhpSpreadsheet\Spreadsheet;
    // use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
    // use PhpOffice\PhpSpreadsheet\Style\Alignment;

    // Filter params
    $kategori_filter = isset($_GET['kategori']) ? $_GET['kategori'] : '';
    $gender = isset($_GET['gender']) ? $_GET['gender'] : '';
    $nama = isset($_GET['nama']) ? $_GET['nama'] : '';
    $club = isset($_GET['club']) ? $_GET['club'] : '';
    $kegiatan_id = isset($_GET['kegiatan_id']) ? $_GET['kegiatan_id'] : 'all';

    // 1. Fetch ALL Data first (to calculate rankings/stats accurately)
    $query = "SELECT
                MIN(p.id) as id,
                p.nama_peserta,
                p.jenis_kelamin,
                p.asal_kota,
                p.nama_club,
                p.sekolah,
                MAX(p.tanggal_lahir) as tanggal_lahir,
                p.id as peserta_id
              FROM peserta p
              WHERE 1=1";
    
    // Base filters for query optimization
    $params = [];
    $types = '';
    if (!empty($gender)) { $query .= " AND p.jenis_kelamin = ?"; $params[] = $gender; $types .= "s"; }
    if (!empty($nama)) { $query .= " AND p.nama_peserta LIKE ?"; $params[] = "%$nama%"; $types .= "s"; }
    if (!empty($club)) { $query .= " AND p.nama_club = ?"; $params[] = $club; $types .= "s"; }

    $query .= " GROUP BY p.nama_peserta, p.jenis_kelamin, p.asal_kota, p.nama_club, p.sekolah";
    $query .= " ORDER BY p.nama_peserta ASC";

    if (!empty($params)) {
        $stmt = $conn->prepare($query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $result = $conn->query($query);
    }

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Statistik Peserta');

    // Headers
    $headers = [
        'No', 'Nama Peserta', 'Club', 'Total Scoreboard', 'Games Played', 
        'Avg Ranking', 'Best Rank', 'Kategori', 'Status'
    ];

    $col = 'A';
    $rowIdx = 1;
    foreach ($headers as $header) {
        $sheet->setCellValue($col . $rowIdx, $header);
        $sheet->getStyle($col . $rowIdx)->getFont()->setBold(true);
        $sheet->getStyle($col . $rowIdx)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $col++;
    }
    $rowIdx++;

    $no = 1;
    while ($p = $result->fetch_assoc()) {
        $stats = getPesertaStats($conn, $p['peserta_id'], $kegiatan_id);
        $dominan = getKategoriDominan($stats['rankings']);
        
        // PHP-side filter for Category Dominance
        if ($kategori_filter && $dominan['kategori'] !== $kategori_filter) continue;

        $avgRank = count($stats['rankings']) > 0 ? number_format(array_sum(array_column($stats['rankings'], 'ranking')) / count($stats['rankings']), 1) : '-';
        $bestRank = count($stats['rankings']) > 0 ? min(array_column($stats['rankings'], 'ranking')) : '-';

        $col = 'A';
        $sheet->setCellValue($col++ . $rowIdx, $no++);
        $sheet->setCellValue($col++ . $rowIdx, $p['nama_peserta']);
        $sheet->setCellValue($col++ . $rowIdx, $p['nama_club']);
        $sheet->setCellValue($col++ . $rowIdx, $stats['total_scoreboards']);
        $sheet->setCellValue($col++ . $rowIdx, count($stats['rankings']));
        $sheet->setCellValue($col++ . $rowIdx, $avgRank);
        $sheet->setCellValue($col++ . $rowIdx, $bestRank);
        $sheet->setCellValue($col++ . $rowIdx, $dominan['label']);
        $sheet->setCellValue($col++ . $rowIdx, $dominan['kategori']);

        $rowIdx++;
    }

    // Auto-size columns
    foreach (range('A', $col) as $columnID) {
        $sheet->getColumnDimension($columnID)->setAutoSize(true);
    }

    $filename = "statistik_peserta_" . date('Y-m-d_His') . ".xlsx";

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    
    if (ob_get_length()) ob_clean();

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

// GET parameters (UNCHANGED - same names)
$gender = $_GET['gender'] ?? '';
$nama = $_GET['nama'] ?? '';
$club = $_GET['club'] ?? '';
$kategori_filter = $_GET['kategori'] ?? '';
$sortByKategori = isset($_GET['sortByKategori']) && $_GET['sortByKategori'] == '1';

// Build query (UNCHANGED LOGIC)
$query = "SELECT
            MIN(p.id) as id,
            p.nama_peserta,
            p.jenis_kelamin,
            p.asal_kota,
            p.nama_club,
            p.sekolah,
            MAX(p.tanggal_lahir) as tanggal_lahir
          FROM peserta p
          WHERE 1=1";

$params = [];
$types = '';

if (!empty($gender)) {
    $query .= " AND p.jenis_kelamin = ?";
    $params[] = $gender;
    $types .= "s";
}

if (!empty($nama)) {
    $query .= " AND p.nama_peserta LIKE ?";
    $params[] = "%$nama%";
    $types .= "s";
}

if (!empty($club)) {
    $query .= " AND p.nama_club = ?";
    $params[] = $club;
    $types .= "s";
}

$query .= " GROUP BY p.nama_peserta, p.jenis_kelamin, p.asal_kota, p.nama_club, p.sekolah";
$query .= " ORDER BY p.nama_peserta ASC";

if (!empty($params)) {
    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query($query);
}

$pesertaData = [];
$totalKategoriA = 0;
$totalKategoriB = 0;
$totalKategoriC = 0;
$totalKategoriD = 0;
$totalKategoriE = 0;

$processedPeserta = [];

while ($peserta = $result->fetch_assoc()) {
    if (isset($processedPeserta[$peserta['id']])) {
        continue;
    }

    $processedPeserta[$peserta['id']] = true;

    $rankings = $allRankings[strtolower(trim($peserta['nama_peserta']))] ?? [];

    $juara1 = 0;
    $juara2 = 0;
    $juara3 = 0;
    $top10 = 0;

    foreach ($rankings as $r) {
        if ($r['ranking'] == 1) $juara1++;
        if ($r['ranking'] == 2) $juara2++;
        if ($r['ranking'] == 3) $juara3++;
        if ($r['ranking'] <= 10) $top10++;
    }

    $bracketStats = getBracketStatistics($conn, $peserta['nama_peserta']);

    $kategoriDominan = getKategoriDominan($rankings);

    if (!empty($kategori_filter) && $kategoriDominan['kategori'] != $kategori_filter) {
        continue;
    }

    $totalTurnamen = count($rankings);
    $avgRanking = $totalTurnamen > 0 ? round(array_sum(array_column($rankings, 'ranking')) / $totalTurnamen, 2) : 0;

    $umur = 0;
    if (!empty($peserta['tanggal_lahir'])) {
        $dob = new DateTime($peserta['tanggal_lahir']);
        $today = new DateTime();
        $umur = $today->diff($dob)->y;
    }

    $pesertaData[] = [
        'id' => $peserta['id'],
        'nama' => $peserta['nama_peserta'],
        'gender' => $peserta['jenis_kelamin'],
        'umur' => $umur,
        'kota' => $peserta['asal_kota'],
        'club' => $peserta['nama_club'],
        'sekolah' => $peserta['sekolah'],
        'total_turnamen' => $totalTurnamen,
        'kategori_dominan' => $kategoriDominan,
        'avg_ranking' => $avgRanking,
        'juara1' => $juara1,
        'juara2' => $juara2,
        'juara3' => $juara3,
        'top10' => $top10,
        'rankings' => $rankings,
        'bracket_stats' => $bracketStats
    ];

    switch ($kategoriDominan['kategori']) {
        case 'A': $totalKategoriA++; break;
        case 'B': $totalKategoriB++; break;
        case 'C': $totalKategoriC++; break;
        case 'D': $totalKategoriD++; break;
        case 'E': $totalKategoriE++; break;
    }
}

// Sorting logic (UNCHANGED)
if ($sortByKategori) {
    usort($pesertaData, function ($a, $b) {
        $kategoriOrder = ['A' => 1, 'B' => 2, 'C' => 3, 'D' => 4, 'E' => 5];
        $aKat = $kategoriOrder[$a['kategori_dominan']['kategori']];
        $bKat = $kategoriOrder[$b['kategori_dominan']['kategori']];

        if ($aKat != $bKat) return $aKat - $bKat;
        if ($a['avg_ranking'] != $b['avg_ranking']) return $a['avg_ranking'] - $b['avg_ranking'];
        return $b['total_turnamen'] - $a['total_turnamen'];
    });
} else {
    usort($pesertaData, function ($a, $b) {
        if ($b['juara1'] != $a['juara1']) return $b['juara1'] - $a['juara1'];
        if ($b['juara2'] != $a['juara2']) return $b['juara2'] - $a['juara2'];
        if ($b['juara3'] != $a['juara3']) return $b['juara3'] - $a['juara3'];
        if ($a['avg_ranking'] != $b['avg_ranking']) return $a['avg_ranking'] - $b['avg_ranking'];
        return strcmp($a['nama'], $b['nama']);
    });
}

// ============================================
// PAGINATION LOGIC
// ============================================
$limit = 50;
$page = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
$total_rows = count($pesertaData);
$total_pages = ceil($total_rows / $limit);
$offset = ($page - 1) * $limit;

// Slice the array for current page
$pesertaDataPaginated = array_slice($pesertaData, $offset, $limit);

// Helper function to build pagination URL preserving GET params
function buildPaginationUrl($page, $params = []) {
    $current = $_GET;
    $current['p'] = $page;
    foreach ($params as $key => $value) {
        $current[$key] = $value;
    }
    return '?' . http_build_query($current);
}

// Color mapping for Tailwind
$colorMap = [
    'emerald' => ['bg' => 'bg-emerald-500', 'text' => 'text-emerald-600', 'light' => 'bg-emerald-50'],
    'blue' => ['bg' => 'bg-blue-500', 'text' => 'text-blue-600', 'light' => 'bg-blue-50'],
    'cyan' => ['bg' => 'bg-cyan-500', 'text' => 'text-cyan-600', 'light' => 'bg-cyan-50'],
    'amber' => ['bg' => 'bg-amber-500', 'text' => 'text-amber-600', 'light' => 'bg-amber-50'],
    'slate' => ['bg' => 'bg-slate-500', 'text' => 'text-slate-600', 'light' => 'bg-slate-100'],
];

$username = $_SESSION['username'] ?? 'User';
$name = $_SESSION['name'] ?? $username;
$role = $_SESSION['role'] ?? 'user';
?>
<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Statistik & Penilaian Peserta - Turnamen Panahan</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script><?= getThemeTailwindConfig() ?></script>
    <script><?= getThemeInitScript() ?></script>
    <script><?= getUiScripts() ?></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .custom-scrollbar::-webkit-scrollbar { width: 6px; height: 6px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 3px; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        .dark .custom-scrollbar::-webkit-scrollbar-track { background: #27272a; }
        .dark .custom-scrollbar::-webkit-scrollbar-thumb { background: #52525b; }
        .dark .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #71717a; }
    </style>
</head>
<body class="h-full bg-slate-50 dark:bg-zinc-950 transition-colors">
    <div class="flex h-full">
        <!-- Sidebar (consistent with Dashboard) -->
        <aside class="hidden lg:flex lg:flex-col w-72 bg-zinc-900 text-white">
            <div class="flex items-center gap-3 px-6 py-5 border-b border-zinc-800">
                <div class="w-10 h-10 rounded-lg bg-archery-600 flex items-center justify-center">
                    <i class="fas fa-bullseye text-white"></i>
                </div>
                <div>
                    <h1 class="font-semibold text-sm">Turnamen Panahan</h1>
                    <p class="text-xs text-zinc-400">Management System</p>
                </div>
            </div>

            <nav class="flex-1 px-4 py-6 space-y-1">
                <a href="dashboard.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800 transition-colors">
                    <i class="fas fa-home w-5"></i>
                    <span class="text-sm">Dashboard</span>
                </a>

                <div class="pt-4">
                    <p class="px-4 text-xs font-semibold text-zinc-500 uppercase tracking-wider mb-2">Master Data</p>
                    <a href="users.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800 transition-colors">
                        <i class="fas fa-users w-5"></i>
                        <span class="text-sm">Users</span>
                    </a>
                    <a href="categori.view.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800 transition-colors">
                        <i class="fas fa-tags w-5"></i>
                        <span class="text-sm">Kategori</span>
                    </a>
                </div>

                <div class="pt-4">
                    <p class="px-4 text-xs font-semibold text-zinc-500 uppercase tracking-wider mb-2">Tournament</p>
                    <a href="kegiatan.view.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800 transition-colors">
                        <i class="fas fa-calendar w-5"></i>
                        <span class="text-sm">Kegiatan</span>
                    </a>
                    <a href="peserta.view.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800 transition-colors">
                        <i class="fas fa-user-friends w-5"></i>
                        <span class="text-sm">Peserta</span>
                    </a>
                    <a href="statistik.php" class="flex items-center gap-3 px-4 py-3 rounded-lg bg-archery-600/20 text-archery-400 border border-archery-600/30">
                        <i class="fas fa-chart-bar w-5"></i>
                        <span class="text-sm font-medium">Statistik</span>
                    </a>
                </div>
            </nav>

            <div class="px-4 py-4 border-t border-zinc-800">
                <div class="flex items-center gap-3 px-2">
                    <div class="w-9 h-9 rounded-full bg-zinc-700 flex items-center justify-center">
                        <i class="fas fa-user text-zinc-400 text-sm"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-medium truncate"><?= htmlspecialchars($name) ?></p>
                        <p class="text-xs text-zinc-500 capitalize"><?= htmlspecialchars($role) ?></p>
                    </div>
                    <?= getThemeToggleButton() ?>
                </div>
                <a href="../actions/logout.php" onclick="const url=this.href; showConfirmModal('Konfirmasi Logout', 'Apakah Anda yakin ingin keluar dari sistem?', () => window.location.href = url, 'danger'); return false;"
                   class="flex items-center gap-2 w-full mt-3 px-4 py-2 rounded-lg text-red-400 hover:bg-red-500/10 transition-colors text-sm">
                    <i class="fas fa-sign-out-alt w-5"></i>
                    <span>Logout</span>
                </a>
            </div>
        </aside>

        <!-- Mobile Menu Button -->
        <button id="mobile-menu-btn" class="lg:hidden fixed top-4 left-4 z-50 p-2 rounded-lg bg-zinc-900 text-white shadow-lg">
            <i class="fas fa-bars"></i>
        </button>

        <!-- Main Content -->
        <main class="flex-1 overflow-auto">
            <div class="px-6 lg:px-8 py-6">
                <!-- Compact Header with Metrics -->
                <div class="bg-white dark:bg-zinc-900 rounded-xl border border-slate-200 dark:border-zinc-800 shadow-sm mb-6">
                    <div class="px-6 py-4 border-b border-slate-100 dark:border-zinc-800">
                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                            <div class="flex items-center gap-3">
                                <a href="dashboard.php" class="p-2 rounded-lg text-slate-400 dark:text-zinc-500 hover:bg-slate-100 dark:hover:bg-zinc-800 transition-colors">
                                    <i class="fas fa-arrow-left"></i>
                                </a>
                                <div>
                                    <h1 class="text-lg font-semibold text-slate-900 dark:text-white">Statistik & Penilaian</h1>
                                    <p class="text-sm text-slate-500 dark:text-zinc-400">Analisis performa peserta turnamen</p>
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <!-- Kegiatan Filter -->
                                <form method="GET" class="flex items-center gap-2">
                                    <select name="kegiatan_id" onchange="this.form.submit()" class="bg-slate-100 dark:bg-zinc-800 border-none rounded-lg px-3 py-2 text-sm font-medium text-slate-900 dark:text-white focus:ring-2 focus:ring-archery-500">
                                        <option value="all" <?= $kegiatan_id === 'all' ? 'selected' : '' ?>>Semua Kegiatan</option>
                                        <?php foreach ($kegiatanList as $keg): ?>
                                            <option value="<?= $keg['id'] ?>" <?= $kegiatan_id == $keg['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($keg['nama_kegiatan']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                                <a href="?export=excel<?= !empty($gender) ? '&gender=' . $gender : '' ?><?= !empty($nama) ? '&nama=' . $nama : '' ?><?= !empty($club) ? '&club=' . $club : '' ?><?= !empty($kategori_filter) ? '&kategori=' . $kategori_filter : '' ?>"
                                   class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-emerald-600 text-white text-sm font-medium hover:bg-emerald-700 transition-colors"
                                   onclick="const url=this.href; showConfirmModal('Export Data', 'Download data statistik ke Excel (.xlsx)?', () => window.location.href = url, 'info'); return false;">
                                    <i class="fas fa-file-excel"></i>
                                    <span class="hidden sm:inline">Export</span>
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Metrics Bar -->
                    <div class="px-6 py-3 bg-slate-50 dark:bg-zinc-800/50 flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
                        <div class="flex items-center gap-2">
                            <span class="text-2xl font-bold text-slate-900 dark:text-white"><?= count($pesertaData) ?></span>
                            <span class="text-slate-500 dark:text-zinc-400">Total Peserta</span>
                        </div>
                        <span class="text-slate-300 dark:text-zinc-600 hidden sm:inline">|</span>
                        <div class="flex items-center gap-1.5">
                            <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                            <span class="font-medium text-slate-700 dark:text-zinc-300"><?= $totalKategoriA ?></span>
                            <span class="text-slate-400 dark:text-zinc-500">Kat. A</span>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <span class="w-2 h-2 rounded-full bg-blue-500"></span>
                            <span class="font-medium text-slate-700 dark:text-zinc-300"><?= $totalKategoriB ?></span>
                            <span class="text-slate-400 dark:text-zinc-500">Kat. B</span>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <span class="w-2 h-2 rounded-full bg-cyan-500"></span>
                            <span class="font-medium text-slate-700 dark:text-zinc-300"><?= $totalKategoriC ?></span>
                            <span class="text-slate-400 dark:text-zinc-500">Kat. C</span>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <span class="w-2 h-2 rounded-full bg-amber-500"></span>
                            <span class="font-medium text-slate-700 dark:text-zinc-300"><?= $totalKategoriD ?></span>
                            <span class="text-slate-400 dark:text-zinc-500">Kat. D</span>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <span class="w-2 h-2 rounded-full bg-slate-400"></span>
                            <span class="font-medium text-slate-700 dark:text-zinc-300"><?= $totalKategoriE ?></span>
                            <span class="text-slate-400 dark:text-zinc-500">Kat. E</span>
                        </div>
                    </div>
                </div>

                <!-- Category Legend -->
                <div class="bg-white dark:bg-zinc-900 rounded-xl border border-slate-200 dark:border-zinc-800 p-5 mb-6">
                    <h3 class="font-semibold text-slate-900 dark:text-white mb-3 flex items-center gap-2">
                        <i class="fas fa-info-circle text-blue-500"></i>
                        Sistem Kategorisasi
                    </h3>
                    <div class="flex flex-wrap gap-2">
                        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-emerald-50 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-400 text-sm font-medium">
                            <i class="fas fa-trophy"></i> A: Top 30% & Rank 1-3
                        </span>
                        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400 text-sm font-medium">
                            <i class="fas fa-medal"></i> B: Top 40% & Rank 4-10
                        </span>
                        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-cyan-50 dark:bg-cyan-900/30 text-cyan-700 dark:text-cyan-400 text-sm font-medium">
                            <i class="fas fa-award"></i> C: Top 41-60%
                        </span>
                        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-amber-50 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400 text-sm font-medium">
                            <i class="fas fa-chart-line"></i> D: Top 61-80%
                        </span>
                        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-slate-100 dark:bg-zinc-800 text-slate-700 dark:text-zinc-300 text-sm font-medium">
                            <i class="fas fa-user"></i> E: Bottom 20%
                        </span>
                    </div>
                </div>

                <!-- Filter Form -->
                <div class="bg-white dark:bg-zinc-900 rounded-xl border border-slate-200 dark:border-zinc-800 p-5 mb-6">
                    <h3 class="font-semibold text-slate-900 dark:text-white mb-4 flex items-center gap-2">
                        <i class="fas fa-filter text-slate-400 dark:text-zinc-500"></i>
                        Filter Pencarian
                    </h3>
                    <!-- FORM: method=get, no action (UNCHANGED) -->
                    <form method="get">
                        <?php if ($sortByKategori): ?>
                            <input type="hidden" name="sortByKategori" value="1">
                        <?php endif; ?>
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-zinc-300 mb-1">Nama Peserta</label>
                                <!-- INPUT: name="nama" (UNCHANGED) -->
                                <input type="text" name="nama" value="<?= htmlspecialchars($nama) ?>"
                                       class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-archery-500 focus:border-archery-500"
                                       placeholder="Cari nama...">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-zinc-300 mb-1">Gender</label>
                                <!-- SELECT: name="gender" (UNCHANGED) -->
                                <select name="gender" class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-archery-500">
                                    <option value="">Semua</option>
                                    <option value="Laki-laki" <?= $gender == "Laki-laki" ? 'selected' : '' ?>>Laki-laki</option>
                                    <option value="Perempuan" <?= $gender == "Perempuan" ? 'selected' : '' ?>>Perempuan</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-zinc-300 mb-1">Club</label>
                                <!-- SELECT: name="club" (UNCHANGED) -->
                                <select name="club" class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-archery-500">
                                    <option value="">Semua Club</option>
                                    <?php foreach ($clubs as $clubName): ?>
                                        <option value="<?= htmlspecialchars($clubName) ?>" <?= $club == $clubName ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($clubName) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-zinc-300 mb-1">Kategori</label>
                                <!-- SELECT: name="kategori" (UNCHANGED) -->
                                <select name="kategori" class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-archery-500">
                                    <option value="">Semua Kategori</option>
                                    <option value="A" <?= $kategori_filter == "A" ? 'selected' : '' ?>>Kategori A</option>
                                    <option value="B" <?= $kategori_filter == "B" ? 'selected' : '' ?>>Kategori B</option>
                                    <option value="C" <?= $kategori_filter == "C" ? 'selected' : '' ?>>Kategori C</option>
                                    <option value="D" <?= $kategori_filter == "D" ? 'selected' : '' ?>>Kategori D</option>
                                    <option value="E" <?= $kategori_filter == "E" ? 'selected' : '' ?>>Kategori E</option>
                                </select>
                            </div>
                            <div class="flex items-end gap-2">
                                <button type="submit" class="flex-1 px-4 py-2 rounded-lg bg-archery-600 text-white text-sm font-medium hover:bg-archery-700 transition-colors">
                                    <i class="fas fa-search mr-1"></i> Cari
                                </button>
                                <a href="?" class="px-3 py-2 rounded-lg border border-slate-300 dark:border-zinc-700 text-slate-600 dark:text-zinc-400 text-sm hover:bg-slate-50 dark:hover:bg-zinc-800 transition-colors">
                                    <i class="fas fa-redo"></i>
                                </a>
                                <?php if ($sortByKategori): ?>
                                    <a href="?<?= !empty($gender) ? 'gender=' . $gender . '&' : '' ?><?= !empty($nama) ? 'nama=' . $nama . '&' : '' ?><?= !empty($club) ? 'club=' . $club . '&' : '' ?><?= !empty($kategori_filter) ? 'kategori=' . $kategori_filter : '' ?>"
                                       class="px-3 py-2 rounded-lg bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400 text-sm hover:bg-amber-200 dark:hover:bg-amber-900/50 transition-colors" title="Urutan Default">
                                        <i class="fas fa-sort-alpha-down"></i>
                                    </a>
                                <?php else: ?>
                                    <a href="?sortByKategori=1<?= !empty($gender) ? '&gender=' . $gender : '' ?><?= !empty($nama) ? '&nama=' . $nama : '' ?><?= !empty($club) ? '&club=' . $club : '' ?><?= !empty($kategori_filter) ? '&kategori=' . $kategori_filter : '' ?>"
                                       class="px-3 py-2 rounded-lg bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400 text-sm hover:bg-blue-200 dark:hover:bg-blue-900/50 transition-colors" title="Urutkan per Kategori">
                                        <i class="fas fa-layer-group"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Data Table -->
                <div class="bg-white dark:bg-zinc-900 rounded-xl border border-slate-200 dark:border-zinc-800 overflow-hidden">
                    <div class="overflow-x-auto custom-scrollbar" style="max-height: 65vh;">
                        <table class="w-full">
                            <thead class="bg-slate-100 dark:bg-zinc-800 sticky top-0 z-10">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 dark:text-zinc-400 uppercase tracking-wider w-12">#</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 dark:text-zinc-400 uppercase tracking-wider">Nama</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 dark:text-zinc-400 uppercase tracking-wider">Gender</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 dark:text-zinc-400 uppercase tracking-wider">Umur</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 dark:text-zinc-400 uppercase tracking-wider">Club</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 dark:text-zinc-400 uppercase tracking-wider">Kategori</th>
                                    <th class="px-4 py-3 text-center text-xs font-semibold text-slate-600 dark:text-zinc-400 uppercase tracking-wider">Turnamen</th>
                                    <th class="px-4 py-3 text-center text-xs font-semibold text-slate-600 dark:text-zinc-400 uppercase tracking-wider">Avg Rank</th>
                                    <th class="px-4 py-3 text-center text-xs font-semibold text-slate-600 dark:text-zinc-400 uppercase tracking-wider">J1</th>
                                    <th class="px-4 py-3 text-center text-xs font-semibold text-slate-600 dark:text-zinc-400 uppercase tracking-wider">J2</th>
                                    <th class="px-4 py-3 text-center text-xs font-semibold text-slate-600 dark:text-zinc-400 uppercase tracking-wider">J3</th>
                                    <th class="px-4 py-3 text-center text-xs font-semibold text-slate-600 dark:text-zinc-400 uppercase tracking-wider">Top10</th>
                                    <th class="px-4 py-3 text-center text-xs font-semibold text-slate-600 dark:text-zinc-400 uppercase tracking-wider">Bracket</th>
                                    <th class="px-4 py-3 text-center text-xs font-semibold text-slate-600 dark:text-zinc-400 uppercase tracking-wider">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-zinc-800">
                                <?php if (empty($pesertaData)): ?>
                                    <tr>
                                        <td colspan="14" class="px-4 py-12 text-center">
                                            <div class="flex flex-col items-center">
                                                <div class="w-16 h-16 rounded-full bg-slate-100 dark:bg-zinc-800 flex items-center justify-center mb-3">
                                                    <i class="fas fa-inbox text-slate-400 dark:text-zinc-500 text-2xl"></i>
                                                </div>
                                                <p class="text-slate-500 dark:text-zinc-400 font-medium">Tidak ada data peserta</p>
                                                <p class="text-slate-400 dark:text-zinc-500 text-sm">Ubah filter atau pastikan peserta telah mengikuti turnamen</p>
                                            </div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php $no = $offset + 1; foreach ($pesertaDataPaginated as $p): ?>
                                        <tr class="hover:bg-slate-50 dark:hover:bg-zinc-800 transition-colors">
                                            <td class="px-4 py-3 text-sm text-slate-500 dark:text-zinc-400"><?= $no++ ?></td>
                                            <td class="px-4 py-3">
                                                <p class="font-medium text-slate-900 dark:text-white"><?= htmlspecialchars($p['nama']) ?></p>
                                                <p class="text-xs text-slate-500 dark:text-zinc-500"><?= htmlspecialchars($p['sekolah'] ?? '-') ?></p>
                                            </td>
                                            <td class="px-4 py-3">
                                                <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium <?= $p['gender'] == 'Laki-laki' ? 'bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400' : 'bg-pink-100 dark:bg-pink-900/30 text-pink-700 dark:text-pink-400' ?>">
                                                    <i class="fas <?= $p['gender'] == 'Laki-laki' ? 'fa-mars' : 'fa-venus' ?> text-xs"></i>
                                                    <?= $p['gender'] == 'Laki-laki' ? 'L' : 'P' ?>
                                                </span>
                                            </td>
                                            <td class="px-4 py-3 text-sm text-slate-600 dark:text-zinc-400"><?= $p['umur'] > 0 ? $p['umur'] . ' th' : '-' ?></td>
                                            <td class="px-4 py-3 text-sm text-slate-600 dark:text-zinc-400 max-w-32 truncate"><?= htmlspecialchars($p['club'] ?? '-') ?></td>
                                            <td class="px-4 py-3">
                                                <?php $c = $colorMap[$p['kategori_dominan']['color']] ?? $colorMap['slate']; ?>
                                                <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold <?= $c['bg'] ?> text-white">
                                                    <?= $p['kategori_dominan']['kategori'] ?>
                                                </span>
                                                <p class="text-xs text-slate-500 dark:text-zinc-500 mt-0.5"><?= $p['kategori_dominan']['label'] ?></p>
                                            </td>
                                            <td class="px-4 py-3 text-center">
                                                <span class="font-semibold text-archery-600 dark:text-archery-400"><?= $p['total_turnamen'] ?></span>
                                            </td>
                                            <td class="px-4 py-3 text-center">
                                                <?php if ($p['avg_ranking'] > 0): ?>
                                                    <span class="text-sm text-slate-600 dark:text-zinc-400">#<?= $p['avg_ranking'] ?></span>
                                                <?php else: ?>
                                                    <span class="text-slate-400 dark:text-zinc-600">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-4 py-3 text-center">
                                                <?php if ($p['juara1'] > 0): ?>
                                                    <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-yellow-100 dark:bg-yellow-900/30 text-yellow-700 dark:text-yellow-400 text-xs font-bold"><?= $p['juara1'] ?></span>
                                                <?php else: ?>
                                                    <span class="text-slate-300 dark:text-zinc-600">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-4 py-3 text-center">
                                                <?php if ($p['juara2'] > 0): ?>
                                                    <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-slate-200 dark:bg-zinc-700 text-slate-700 dark:text-zinc-300 text-xs font-bold"><?= $p['juara2'] ?></span>
                                                <?php else: ?>
                                                    <span class="text-slate-300 dark:text-zinc-600">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-4 py-3 text-center">
                                                <?php if ($p['juara3'] > 0): ?>
                                                    <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400 text-xs font-bold"><?= $p['juara3'] ?></span>
                                                <?php else: ?>
                                                    <span class="text-slate-300 dark:text-zinc-600">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-4 py-3 text-center">
                                                <?php if ($p['top10'] > 0): ?>
                                                    <span class="text-sm text-blue-600 dark:text-blue-400 font-medium"><?= $p['top10'] ?>x</span>
                                                <?php else: ?>
                                                    <span class="text-slate-300 dark:text-zinc-600">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-4 py-3 text-center">
                                                <?php if ($p['bracket_stats']['total_bracket'] > 0): ?>
                                                    <div class="flex flex-col items-center gap-0.5">
                                                        <?php if ($p['bracket_stats']['bracket_champion'] > 0): ?>
                                                            <span class="text-xs text-yellow-600">🏆<?= $p['bracket_stats']['bracket_champion'] ?></span>
                                                        <?php endif; ?>
                                                        <?php if ($p['bracket_stats']['bracket_runner_up'] > 0): ?>
                                                            <span class="text-xs text-slate-500">🥈<?= $p['bracket_stats']['bracket_runner_up'] ?></span>
                                                        <?php endif; ?>
                                                        <?php if ($p['bracket_stats']['bracket_third_place'] > 0): ?>
                                                            <span class="text-xs text-amber-600">🥉<?= $p['bracket_stats']['bracket_third_place'] ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="text-slate-300">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-4 py-3 text-center">
                                                <button onclick="showDetail(<?= htmlspecialchars(json_encode($p)) ?>)"
                                                        class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-archery-50 dark:bg-archery-900/30 text-archery-700 dark:text-archery-400 text-xs font-medium hover:bg-archery-100 dark:hover:bg-archery-900/50 transition-colors">
                                                    <i class="fas fa-eye"></i> Detail
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if (!empty($pesertaDataPaginated)): ?>
                        <!-- Pagination Footer -->
                        <div class="px-4 py-3 bg-white dark:bg-zinc-900 border-t border-slate-100 dark:border-zinc-800 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                            <div class="flex items-center gap-3">
                                <p class="text-sm text-slate-500 dark:text-zinc-400">
                                    Menampilkan <span class="font-medium text-slate-900 dark:text-white"><?= $offset + 1 ?></span> - <span class="font-medium text-slate-900 dark:text-white"><?= min($offset + $limit, $total_rows) ?></span> dari <span class="font-medium text-slate-900 dark:text-white"><?= $total_rows ?></span> peserta
                                </p>
                                <?php if ($sortByKategori): ?>
                                    <span class="px-2 py-1 rounded-full bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400 text-xs font-medium">Diurutkan per Kategori</span>
                                <?php endif; ?>
                            </div>
                            <?php if ($total_pages > 1): ?>
                            <nav class="flex items-center gap-1">
                                <!-- First & Prev -->
                                <?php if ($page > 1): ?>
                                <a href="<?= buildPaginationUrl(1) ?>" class="p-2 rounded-md text-slate-400 dark:text-zinc-500 hover:text-slate-600 dark:hover:text-zinc-300 hover:bg-slate-100 dark:hover:bg-zinc-800 transition-colors" title="First">
                                    <i class="fas fa-angles-left text-xs"></i>
                                </a>
                                <a href="<?= buildPaginationUrl($page - 1) ?>" class="p-2 rounded-md text-slate-400 dark:text-zinc-500 hover:text-slate-600 dark:hover:text-zinc-300 hover:bg-slate-100 dark:hover:bg-zinc-800 transition-colors" title="Previous">
                                    <i class="fas fa-angle-left text-xs"></i>
                                </a>
                                <?php else: ?>
                                <span class="p-2 text-slate-300 dark:text-zinc-700"><i class="fas fa-angles-left text-xs"></i></span>
                                <span class="p-2 text-slate-300 dark:text-zinc-700"><i class="fas fa-angle-left text-xs"></i></span>
                                <?php endif; ?>

                                <!-- Page Numbers -->
                                <?php
                                $start_page = max(1, $page - 2);
                                $end_page = min($total_pages, $page + 2);

                                if ($start_page > 1): ?>
                                <a href="<?= buildPaginationUrl(1) ?>" class="px-3 py-1.5 rounded-md text-sm text-slate-600 dark:text-zinc-400 hover:bg-slate-100 dark:hover:bg-zinc-800 transition-colors">1</a>
                                <?php if ($start_page > 2): ?><span class="px-1 text-slate-400 dark:text-zinc-600">...</span><?php endif; ?>
                                <?php endif;

                                for ($i = $start_page; $i <= $end_page; $i++): ?>
                                <a href="<?= buildPaginationUrl($i) ?>" class="px-3 py-1.5 rounded-md text-sm font-medium transition-colors <?= $i === $page ? 'bg-archery-600 text-white' : 'text-slate-600 dark:text-zinc-400 hover:bg-slate-100 dark:hover:bg-zinc-800' ?>"><?= $i ?></a>
                                <?php endfor;

                                if ($end_page < $total_pages): ?>
                                <?php if ($end_page < $total_pages - 1): ?><span class="px-1 text-slate-400 dark:text-zinc-600">...</span><?php endif; ?>
                                <a href="<?= buildPaginationUrl($total_pages) ?>" class="px-3 py-1.5 rounded-md text-sm text-slate-600 dark:text-zinc-400 hover:bg-slate-100 dark:hover:bg-zinc-800 transition-colors"><?= $total_pages ?></a>
                                <?php endif; ?>

                                <!-- Next & Last -->
                                <?php if ($page < $total_pages): ?>
                                <a href="<?= buildPaginationUrl($page + 1) ?>" class="p-2 rounded-md text-slate-400 dark:text-zinc-500 hover:text-slate-600 dark:hover:text-zinc-300 hover:bg-slate-100 dark:hover:bg-zinc-800 transition-colors" title="Next">
                                    <i class="fas fa-angle-right text-xs"></i>
                                </a>
                                <a href="<?= buildPaginationUrl($total_pages) ?>" class="p-2 rounded-md text-slate-400 dark:text-zinc-500 hover:text-slate-600 dark:hover:text-zinc-300 hover:bg-slate-100 dark:hover:bg-zinc-800 transition-colors" title="Last">
                                    <i class="fas fa-angles-right text-xs"></i>
                                </a>
                                <?php else: ?>
                                <span class="p-2 text-slate-300 dark:text-zinc-700"><i class="fas fa-angle-right text-xs"></i></span>
                                <span class="p-2 text-slate-300 dark:text-zinc-700"><i class="fas fa-angles-right text-xs"></i></span>
                                <?php endif; ?>
                            </nav>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- Detail Modal -->
    <div id="detailModal" class="fixed inset-0 z-50 hidden">
        <div class="absolute inset-0 bg-black/50" onclick="closeModal()"></div>
        <div class="absolute inset-4 sm:inset-auto sm:top-1/2 sm:left-1/2 sm:-translate-x-1/2 sm:-translate-y-1/2 sm:w-full sm:max-w-4xl bg-white dark:bg-zinc-900 rounded-2xl shadow-xl overflow-hidden max-h-[90vh] flex flex-col">
            <div class="bg-gradient-to-br from-archery-600 to-archery-800 text-white px-6 py-4 flex items-center justify-between flex-shrink-0">
                <h3 class="font-semibold text-lg" id="modalNama">Detail Peserta</h3>
                <button onclick="closeModal()" class="p-2 rounded-lg hover:bg-white/10 transition-colors">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="p-6 overflow-y-auto custom-scrollbar flex-1" id="modalContent">
                <!-- Content loaded by JS -->
            </div>
        </div>
    </div>

    <!-- Mobile Sidebar -->
    <div id="mobile-overlay" class="fixed inset-0 bg-black/50 z-40 hidden lg:hidden"></div>
    <div id="mobile-sidebar" class="fixed inset-y-0 left-0 w-72 bg-zinc-900 text-white z-50 transform -translate-x-full transition-transform lg:hidden flex flex-col">
        <div class="flex items-center gap-3 px-6 py-5 border-b border-zinc-800">
            <div class="w-10 h-10 rounded-lg bg-archery-600 flex items-center justify-center">
                <i class="fas fa-bullseye text-white"></i>
            </div>
            <div class="flex-1">
                <h1 class="font-semibold text-sm">Turnamen Panahan</h1>
            </div>
            <button id="close-mobile-menu" class="p-2 rounded-lg hover:bg-zinc-800">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <nav class="px-4 py-6 space-y-1">
            <a href="dashboard.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800">
                <i class="fas fa-home w-5"></i><span class="text-sm">Dashboard</span>
            </a>
            <a href="users.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800">
                <i class="fas fa-users w-5"></i><span class="text-sm">Users</span>
            </a>
            <a href="categori.view.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800">
                <i class="fas fa-tags w-5"></i><span class="text-sm">Kategori</span>
            </a>
            <a href="kegiatan.view.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800">
                <i class="fas fa-calendar w-5"></i><span class="text-sm">Kegiatan</span>
            </a>
            <a href="peserta.view.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800">
                <i class="fas fa-user-friends w-5"></i><span class="text-sm">Peserta</span>
            </a>
            <a href="statistik.php" class="flex items-center gap-3 px-4 py-3 rounded-lg bg-archery-600/20 text-archery-400">
                <i class="fas fa-chart-bar w-5"></i><span class="text-sm font-medium">Statistik</span>
            </a>
        </nav>
        <div class="px-4 py-4 border-t border-zinc-800 mt-auto">
            <a href="../actions/logout.php" onclick="const url=this.href; showConfirmModal('Konfirmasi Logout', 'Apakah Anda yakin ingin keluar dari sistem?', () => window.location.href = url, 'danger'); return false;"
               class="flex items-center gap-2 w-full px-4 py-2 rounded-lg text-red-400 hover:bg-red-500/10 transition-colors text-sm">
                <i class="fas fa-sign-out-alt w-5"></i>
                <span>Logout</span>
            </a>
        </div>
    </div>

    <script>
        // Mobile menu toggle
        const mobileMenuBtn = document.getElementById('mobile-menu-btn');
        const mobileOverlay = document.getElementById('mobile-overlay');
        const mobileSidebar = document.getElementById('mobile-sidebar');
        const closeMobileMenu = document.getElementById('close-mobile-menu');

        function toggleMobileMenu() {
            mobileSidebar.classList.toggle('-translate-x-full');
            mobileOverlay.classList.toggle('hidden');
        }

        mobileMenuBtn?.addEventListener('click', toggleMobileMenu);
        mobileOverlay?.addEventListener('click', toggleMobileMenu);
        closeMobileMenu?.addEventListener('click', toggleMobileMenu);

        // Detail Modal
        function showDetail(data) {
            const modal = document.getElementById('detailModal');
            const content = document.getElementById('modalContent');
            document.getElementById('modalNama').textContent = data.nama;

            const colorMap = {
                'emerald': 'bg-emerald-500',
                'blue': 'bg-blue-500',
                'cyan': 'bg-cyan-500',
                'amber': 'bg-amber-500',
                'slate': 'bg-slate-500'
            };
            const katColor = colorMap[data.kategori_dominan.color] || 'bg-slate-500';

            let html = `
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Profile Info -->
                    <div class="bg-slate-50 dark:bg-zinc-800 rounded-xl p-4">
                        <h4 class="font-semibold text-slate-900 dark:text-white mb-3">Informasi Peserta</h4>
                        <div class="space-y-2 text-sm">
                            <div class="flex justify-between"><span class="text-slate-500 dark:text-zinc-400">Gender</span><span class="font-medium text-slate-900 dark:text-white">${data.gender || '-'}</span></div>
                            <div class="flex justify-between"><span class="text-slate-500 dark:text-zinc-400">Umur</span><span class="font-medium text-slate-900 dark:text-white">${data.umur > 0 ? data.umur + ' tahun' : '-'}</span></div>
                            <div class="flex justify-between"><span class="text-slate-500 dark:text-zinc-400">Kota</span><span class="font-medium text-slate-900 dark:text-white">${data.kota || '-'}</span></div>
                            <div class="flex justify-between"><span class="text-slate-500 dark:text-zinc-400">Club</span><span class="font-medium text-slate-900 dark:text-white">${data.club || '-'}</span></div>
                            <div class="flex justify-between"><span class="text-slate-500 dark:text-zinc-400">Sekolah</span><span class="font-medium text-slate-900 dark:text-white">${data.sekolah || '-'}</span></div>
                        </div>
                    </div>

                    <!-- Category -->
                    <div class="bg-slate-50 dark:bg-zinc-800 rounded-xl p-4 text-center">
                        <h4 class="font-semibold text-slate-900 dark:text-white mb-3">Kategori Dominan</h4>
                        <div class="inline-flex items-center gap-2 px-4 py-2 rounded-full ${katColor} text-white text-lg font-bold">
                            Kategori ${data.kategori_dominan.kategori}
                        </div>
                        <p class="text-slate-600 dark:text-zinc-400 mt-2">${data.kategori_dominan.label}</p>
                    </div>
                </div>

                <!-- Stats -->
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mt-6">
                    <div class="bg-archery-50 dark:bg-archery-900/30 rounded-xl p-4 text-center">
                        <p class="text-2xl font-bold text-archery-600 dark:text-archery-400">${data.total_turnamen}</p>
                        <p class="text-xs text-slate-500 dark:text-zinc-400">Total Turnamen</p>
                    </div>
                    <div class="bg-yellow-50 dark:bg-yellow-900/30 rounded-xl p-4 text-center">
                        <p class="text-2xl font-bold text-yellow-600 dark:text-yellow-400">${data.juara1}</p>
                        <p class="text-xs text-slate-500 dark:text-zinc-400">Juara 1</p>
                    </div>
                    <div class="bg-slate-100 dark:bg-zinc-700 rounded-xl p-4 text-center">
                        <p class="text-2xl font-bold text-slate-600 dark:text-zinc-300">${data.juara2}</p>
                        <p class="text-xs text-slate-500 dark:text-zinc-400">Juara 2</p>
                    </div>
                    <div class="bg-amber-50 dark:bg-amber-900/30 rounded-xl p-4 text-center">
                        <p class="text-2xl font-bold text-amber-600 dark:text-amber-400">${data.juara3}</p>
                        <p class="text-xs text-slate-500 dark:text-zinc-400">Juara 3</p>
                    </div>
                </div>

                <!-- Bracket Stats -->
                ${data.bracket_stats.total_bracket > 0 ? `
                    <div class="mt-6 bg-amber-50 dark:bg-amber-900/30 rounded-xl p-4">
                        <h4 class="font-semibold text-slate-900 dark:text-white mb-3">Statistik Bracket</h4>
                        <div class="grid grid-cols-3 gap-4 text-center">
                            <div><p class="text-xl font-bold text-yellow-600 dark:text-yellow-400">${data.bracket_stats.bracket_champion}</p><p class="text-xs text-slate-500 dark:text-zinc-400">Champion</p></div>
                            <div><p class="text-xl font-bold text-slate-600 dark:text-zinc-300">${data.bracket_stats.bracket_runner_up}</p><p class="text-xs text-slate-500 dark:text-zinc-400">Runner Up</p></div>
                            <div><p class="text-xl font-bold text-amber-600 dark:text-amber-400">${data.bracket_stats.bracket_third_place}</p><p class="text-xs text-slate-500 dark:text-zinc-400">3rd Place</p></div>
                        </div>
                    </div>
                ` : ''}

                <!-- Tournament History -->
                <div class="mt-6">
                    <h4 class="font-semibold text-slate-900 dark:text-white mb-3">Riwayat Turnamen</h4>
                    ${data.rankings.length > 0 ? `
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead class="bg-slate-100 dark:bg-zinc-800">
                                    <tr>
                                        <th class="px-3 py-2 text-left text-xs font-semibold text-slate-600 dark:text-zinc-400">#</th>
                                        <th class="px-3 py-2 text-left text-xs font-semibold text-slate-600 dark:text-zinc-400">Turnamen</th>
                                        <th class="px-3 py-2 text-left text-xs font-semibold text-slate-600 dark:text-zinc-400">Kategori</th>
                                        <th class="px-3 py-2 text-center text-xs font-semibold text-slate-600 dark:text-zinc-400">Rank</th>
                                        <th class="px-3 py-2 text-center text-xs font-semibold text-slate-600 dark:text-zinc-400">Peserta</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 dark:divide-zinc-700">
                                    ${data.rankings.map((r, i) => `
                                        <tr>
                                            <td class="px-3 py-2 text-slate-500 dark:text-zinc-400">${i + 1}</td>
                                            <td class="px-3 py-2 font-medium text-slate-900 dark:text-white">${r.turnamen}</td>
                                            <td class="px-3 py-2 text-slate-600 dark:text-zinc-400">${r.kategori}</td>
                                            <td class="px-3 py-2 text-center"><span class="inline-flex items-center justify-center w-6 h-6 rounded-full ${r.ranking <= 3 ? 'bg-yellow-100 dark:bg-yellow-900/30 text-yellow-700 dark:text-yellow-400' : 'bg-slate-100 dark:bg-zinc-700 text-slate-600 dark:text-zinc-400'} text-xs font-bold">${r.ranking}</span></td>
                                            <td class="px-3 py-2 text-center text-slate-500 dark:text-zinc-400">${r.total_peserta}</td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        </div>
                    ` : '<p class="text-slate-500 dark:text-zinc-400 text-center py-4">Belum ada riwayat turnamen</p>'}
                </div>
            `;

            content.innerHTML = html;
            modal.classList.remove('hidden');
        }

        function closeModal() {
            document.getElementById('detailModal').classList.add('hidden');
        }

        // Close on Escape
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') closeModal();
        });

        // Auto-submit on select change
        document.querySelectorAll('select[name="gender"], select[name="kategori"]').forEach(select => {
            select.addEventListener('change', function() {
                this.form.submit();
            });
        });

        // Theme Toggle
        <?= getThemeToggleScript() ?>
    </script>
    </script>
    <?= getConfirmationModal() ?>
</body>
</html>
