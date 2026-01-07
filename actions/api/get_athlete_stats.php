<?php
/**
 * Athlete Statistics API - Dynamic calculation from score table
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

include '../../config/panggil.php';

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
            'tournaments' => $tournaments
        ]
    ];

    echo json_encode($response);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Terjadi kesalahan server: ' . $e->getMessage()
    ]);
}
?>
