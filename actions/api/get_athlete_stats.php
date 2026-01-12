<?php
/**
 * Athlete Statistics API - Dynamic calculation from score table
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/panggil.php';
enforceAuth();

if (!checkRateLimit('api_request', 30, 60)) {
    header('HTTP/1.1 429 Too Many Requests');
    echo json_encode(['success' => false, 'message' => 'Terlalu banyak permintaan. Silakan coba lagi nanti.']);
    exit;
}

$_GET = cleanInput($_GET);

if (!isset($_GET['name']) || empty($_GET['name'])) {
    echo json_encode(['success' => false, 'message' => 'Nama atlet tidak ditemukan']);
    exit;
}

$athleteName = trim($_GET['name']);

try {
    // Get athlete basic info
    $queryInfo = "SELECT nama_peserta, nama_club, jenis_kelamin, asal_kota, sekolah
                  FROM peserta WHERE LOWER(nama_peserta) = LOWER(?) LIMIT 1";
    $stmtInfo = $conn->prepare($queryInfo);
    $stmtInfo->bind_param("s", $athleteName);
    $stmtInfo->execute();
    $resultInfo = $stmtInfo->get_result();
    $athleteInfo = $resultInfo->fetch_assoc();
    $stmtInfo->close();

    if (!$athleteInfo) {
        // Fallback: Try SOUNDEX match for minor typos
        $queryFallback = "SELECT nama_peserta, nama_club, jenis_kelamin, asal_kota, sekolah
                          FROM peserta WHERE SOUNDEX(nama_peserta) = SOUNDEX(?) LIMIT 1";
        $stmtFallback = $conn->prepare($queryFallback);
        $stmtFallback->bind_param("s", $athleteName);
        $stmtFallback->execute();
        $resultFallback = $stmtFallback->get_result();
        $athleteInfo = $resultFallback->fetch_assoc();
        $stmtFallback->close();

        if (!$athleteInfo) {
            // Fallback 2: Try LIKE match with first name
            $names = explode(' ', $athleteName);
            if (count($names) > 0) {
                $firstName = $names[0];
                $queryLike = "SELECT nama_peserta, nama_club, jenis_kelamin, asal_kota, sekolah
                             FROM peserta WHERE nama_peserta LIKE CONCAT(?, '%') 
                             AND LENGTH(nama_peserta) > 0 LIMIT 1";
                $stmtLike = $conn->prepare($queryLike);
                $stmtLike->bind_param("s", $firstName);
                $stmtLike->execute();
                $resultLike = $stmtLike->get_result();
                $athleteInfo = $resultLike->fetch_assoc();
                $stmtLike->close();
            }
        }
        
        if (!$athleteInfo) {
            echo json_encode(['success' => false, 'message' => 'Atlet tidak ditemukan dalam database']);
            exit;
        }
    }

    // Dynamic ranking calculation from score table - optimized single query
    $queryStats = "
        WITH ScoreStats AS (
            SELECT
                s.score_board_id,
                s.peserta_id,
                s.kegiatan_id,
                s.category_id,
                SUM(
                    CASE
                        WHEN LOWER(s.score) = 'x' THEN 10
                        WHEN LOWER(s.score) = 'm' THEN 0
                        ELSE CAST(s.score AS UNSIGNED)
                    END
                ) as total_score,
                SUM(CASE WHEN LOWER(s.score) = 'x' THEN 1 ELSE 0 END) as total_x
            FROM score s
            GROUP BY s.score_board_id, s.peserta_id, s.kegiatan_id, s.category_id
        ),
        RankedScores AS (
            SELECT
                ss.*,
                RANK() OVER (PARTITION BY ss.score_board_id ORDER BY ss.total_score DESC, ss.total_x DESC) as rank_pos,
                COUNT(*) OVER (PARTITION BY ss.score_board_id) as board_participants
            FROM ScoreStats ss
        )
        SELECT
            rs.score_board_id,
            rs.rank_pos as ranking,
            rs.board_participants as total_peserta,
            rs.total_score,
            k.nama_kegiatan,
            c.name as category_name,
            COALESCE(sb.created, NOW()) as tanggal
        FROM RankedScores rs
        JOIN peserta p ON rs.peserta_id = p.id
        JOIN kegiatan k ON rs.kegiatan_id = k.id
        JOIN categories c ON rs.category_id = c.id
        LEFT JOIN score_boards sb ON rs.score_board_id = sb.id
        WHERE LOWER(p.nama_peserta) = LOWER(?)
        ORDER BY tanggal DESC
    ";

    $stmtStats = $conn->prepare($queryStats);
    $stmtStats->bind_param("s", $athleteName);
    $stmtStats->execute();
    $resultStats = $stmtStats->get_result();

    $tournaments = [];
    $juara1 = 0;
    $juara2 = 0;
    $juara3 = 0;

    while ($row = $resultStats->fetch_assoc()) {
        $tournaments[] = [
            'nama_kegiatan' => $row['nama_kegiatan'],
            'category' => $row['category_name'],
            'tanggal' => date('d M Y', strtotime($row['tanggal'])),
            'ranking' => (int)$row['ranking'],
            'total_peserta' => (int)$row['total_peserta'],
            'total_score' => (int)$row['total_score']
        ];

        if ($row['ranking'] == 1) $juara1++;
        if ($row['ranking'] == 2) $juara2++;
        if ($row['ranking'] == 3) $juara3++;
    }
    $stmtStats->close();

    $response = [
        'success' => true,
        'data' => [
            'name' => $athleteInfo['nama_peserta'],
            'club' => $athleteInfo['nama_club'] ?: 'No Club',
            'gender' => $athleteInfo['jenis_kelamin'] ?? '',
            'kota' => $athleteInfo['asal_kota'] ?? '',
            'sekolah' => $athleteInfo['sekolah'] ?? '',
            'total_turnamen' => count($tournaments),
            'juara1' => $juara1,
            'juara2' => $juara2,
            'juara3' => $juara3,
            'tournaments' => $tournaments,
            'kategori_dominan' => getKategoriDominan($tournaments, $athleteInfo['nama_peserta'])
        ]
    ];

    echo json_encode($response);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Terjadi kesalahan server: ' . $e->getMessage()
    ]);
}

// Helper Functions (Ported from statistik.php)
function getKategoriFromRanking(int $ranking, int $totalPeserta): array
{
    if ($totalPeserta <= 1) {
        return ['kategori' => 'A', 'label' => 'Sangat Baik', 'color' => 'emerald', 'icon' => 'trophy', 'reason' => 'Juara Tunggal (Otomatis A)', 'tip' => 'Pertahankan!'];
    }

    // V4: Weighted Score with Linear Penalty
    $rankScore = 100 / $ranking;
    $sizeBonus = 10 * log($totalPeserta, 2);
    $finalScore = $rankScore + $sizeBonus - $ranking;

    if ($ranking === 1 || $finalScore >= 80) {
        return [
            'kategori' => 'A', 'label' => 'Sangat Baik', 'color' => 'emerald', 'icon' => 'trophy',
            'reason' => $ranking === 1 ? "Juara 1 (Otomatis Grade A)" : "Skor Performa: " . round($finalScore, 1) . " (Sangat Tinggi)",
            'tip' => "Pertahankan konsistensi podium Anda!"
        ];
    } elseif ($finalScore >= 50) {
        return [
            'kategori' => 'B', 'label' => 'Baik', 'color' => 'blue', 'icon' => 'medal',
            'reason' => "Skor Performa: " . round($finalScore, 1) . " (Baik)",
            'tip' => "Coba tingkatkan peringkat di turnamen besar untuk naik ke A."
        ];
    } elseif ($finalScore >= 30) {
        return [
            'kategori' => 'C', 'label' => 'Cukup', 'color' => 'cyan', 'icon' => 'award',
            'reason' => "Skor Performa: " . round($finalScore, 1) . " (Cukup)",
            'tip' => "Fokus latihan untuk masuk 10 besar secara konsisten."
        ];
    } else {
        return [
            'kategori' => 'D', 'label' => 'Perlu Latihan', 'color' => 'amber', 'icon' => 'trending-up',
            'reason' => "Skor Performa: " . round($finalScore, 1) . " (Perlu Boost)",
            'tip' => "Perbanyak jam terbang dan pengalaman tanding."
        ];
    }
}

function getKategoriDominan(array $rankings, string $nama_peserta = ''): array
{
    // MANUAL OVERRIDE: Priyo
    if (!empty($nama_peserta) && stripos($nama_peserta, 'priyo') !== false) {
         $reason = "Special Achievement: Dedicated Athlete (Manual Adjustment)";
         $tip = "Pertahankan status elit Anda!";
         return ['kategori' => 'A', 'label' => 'Sangat Baik', 'color' => 'emerald', 'icon' => 'trophy', 'reason' => $reason, 'tip' => $tip];
    }

    if (empty($rankings)) {
        return [
            'kategori' => 'E', 'label' => 'Belum Bertanding', 'color' => 'slate', 'icon' => 'user',
            'reason' => "Belum ada data turnamen.",
            'tip' => "Ayo mulai ikuti turnamen!"
        ];
    }

    $kategoriCount = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'E' => 0];

    foreach ($rankings as $rank) {
        $kat = getKategoriFromRanking($rank['ranking'], $rank['total_peserta']);
        $kategoriCount[$kat['kategori']]++;
    }

    $totalMain = count($rankings);
    if ($totalMain >= 10) {
        if ($kategoriCount['E'] > 0 || $kategoriCount['D'] > 0) {
            $kategoriCount['C'] += ($kategoriCount['E'] + $kategoriCount['D']);
            $kategoriCount['E'] = 0; $kategoriCount['D'] = 0;
        }
    } elseif ($totalMain >= 5) {
        if ($kategoriCount['E'] > 0) {
            $kategoriCount['D'] += $kategoriCount['E'];
            $kategoriCount['E'] = 0;
        }
    }

    $maxCount = max($kategoriCount);
    $dominan = 'E';
    foreach (['A', 'B', 'C', 'D', 'E'] as $key) {
        if ($kategoriCount[$key] === $maxCount) {
            $dominan = $key;
            break; 
        }
    }

    $reason = "Mendominasi dengan {$maxCount}x skor Grade {$dominan}.";
    $tip = "";
    switch ($dominan) {
        case 'A': $tip = "Luar biasa! Pertahankan performa elit Anda."; break;
        case 'B': $tip = "Sangat bagus! Sedikit lagi menuju dominasi Grade A."; break;
        case 'C': $tip = "Konsisten! Tingkatkan fokus untuk masuk ke Grade B."; break;
        case 'D': $tip = "Ayo semangat! Perbanyak latihan dan jam terbang."; break;
        case 'E': $tip = "Selamat datang! Nikmati setiap proses turnamen."; break;
    }

    $mapping = [
        'A' => ['kategori' => 'A', 'label' => 'Sangat Baik', 'color' => 'emerald', 'icon' => 'trophy', 'reason' => $reason, 'tip' => $tip],
        'B' => ['kategori' => 'B', 'label' => 'Baik', 'color' => 'blue', 'icon' => 'medal', 'reason' => $reason, 'tip' => $tip],
        'C' => ['kategori' => 'C', 'label' => 'Cukup', 'color' => 'cyan', 'icon' => 'award', 'reason' => $reason, 'tip' => $tip],
        'D' => ['kategori' => 'D', 'label' => 'Perlu Latihan', 'color' => 'amber', 'icon' => 'trending-up', 'reason' => $reason, 'tip' => $tip],
        'E' => ['kategori' => 'E', 'label' => 'Pemula', 'color' => 'slate', 'icon' => 'user', 'reason' => $reason, 'tip' => $tip],
    ];

    return $mapping[$dominan];
}
