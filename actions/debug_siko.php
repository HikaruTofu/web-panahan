<?php
/**
 * Debug script to investigate Siko's data mismatch
 * Compare Dashboard query vs Statistik query
 */

include '../config/panggil.php';
include '../includes/check_access.php';
requireAdmin();

echo "<h1>Debug: Siko Data Investigation</h1>";
echo "<style>table{border-collapse:collapse;margin:20px 0;}th,td{border:1px solid #333;padding:8px;text-align:left;}th{background:#f0f0f0;}</style>";

// 1. Check peserta table for Siko
echo "<h2>1. Peserta Records for 'Siko'</h2>";
$stmt = $conn->prepare("SELECT * FROM peserta WHERE nama_peserta LIKE ?");
$searchLike = '%Siko%';
$stmt->bind_param("s", $searchLike);
$stmt->execute();
$result1 = $stmt->get_result();
echo "<table><tr><th>ID</th><th>Nama</th><th>Club</th><th>Gender</th></tr>";
while ($row = $result1->fetch_assoc()) {
    echo "<tr><td>{$row['id']}</td><td>{$row['nama_peserta']}</td><td>{$row['nama_club']}</td><td>{$row['jenis_kelamin']}</td></tr>";
}
echo "</table>";

// 2. Check score records for Siko's peserta IDs
echo "<h2>2. Score Records linked to 'Siko'</h2>";
$stmt = $conn->prepare("
    SELECT s.*, p.nama_peserta, sb.id as scoreboard_id
    FROM score s
    JOIN peserta p ON s.peserta_id = p.id
    JOIN score_boards sb ON s.score_board_id = sb.id
    WHERE p.nama_peserta LIKE ?
    LIMIT 50
");
$searchLike = '%Siko%';
$stmt->bind_param("s", $searchLike);
$stmt->execute();
$result2 = $stmt->get_result();
if ($result2 && $result2->num_rows > 0) {
    echo "<table><tr><th>Score ID</th><th>Peserta ID</th><th>Nama</th><th>Scoreboard ID</th><th>Score</th></tr>";
    while ($row = $result2->fetch_assoc()) {
        echo "<tr><td>{$row['id']}</td><td>{$row['peserta_id']}</td><td>{$row['nama_peserta']}</td><td>{$row['scoreboard_id']}</td><td>{$row['score']}</td></tr>";
    }
    echo "</table>";
} else {
    echo "<p style='color:red;'>No score records found for Siko!</p>";
}

// 3. Dashboard Query Result for Siko
echo "<h2>3. Dashboard Query Result (current logic)</h2>";
$stmt = $conn->prepare("
    WITH ScoreStats AS (
        SELECT
            s.score_board_id,
            s.peserta_id,
            SUM(
                CASE
                    WHEN LOWER(s.score) = 'x' THEN 10
                    WHEN LOWER(s.score) = 'm' THEN 0
                    ELSE CAST(s.score AS UNSIGNED)
                END
            ) as total_score,
            SUM(CASE WHEN LOWER(s.score) = 'x' THEN 1 ELSE 0 END) as total_x
        FROM score s
        GROUP BY s.score_board_id, s.peserta_id
    ),
    RankedScores AS (
        SELECT
            ss.*,
            RANK() OVER (PARTITION BY ss.score_board_id ORDER BY ss.total_score DESC, ss.total_x DESC) as rank_pos,
            COUNT(*) OVER (PARTITION BY ss.score_board_id) as board_participants
        FROM ScoreStats ss
    )
    SELECT
        p.nama_peserta,
        MAX(p.jenis_kelamin) as jenis_kelamin,
        MAX(p.nama_club) as nama_club,
        COUNT(DISTINCT rs.score_board_id) as total_turnamen,
        AVG(rs.rank_pos) as avg_rank,
        SUM(CASE WHEN rs.rank_pos = 1 THEN 1 ELSE 0 END) as juara1,
        SUM(CASE WHEN rs.rank_pos = 2 THEN 1 ELSE 0 END) as juara2,
        SUM(CASE WHEN rs.rank_pos = 3 THEN 1 ELSE 0 END) as juara3,
        GROUP_CONCAT(rs.rank_pos) as all_ranks,
        GROUP_CONCAT(rs.board_participants) as all_participants
    FROM RankedScores rs
    JOIN peserta p ON rs.peserta_id = p.id
    WHERE p.nama_peserta LIKE ?
    GROUP BY p.nama_peserta
");
$searchLike = '%Siko%';
$stmt->bind_param("s", $searchLike);
$stmt->execute();
$result3 = $stmt->get_result();
if ($result3 && $result3->num_rows > 0) {
    echo "<table><tr><th>Nama</th><th>Total Turnamen</th><th>Avg Rank</th><th>Juara1</th><th>Juara2</th><th>Juara3</th><th>All Ranks</th></tr>";
    while ($row = $result3->fetch_assoc()) {
        echo "<tr><td>{$row['nama_peserta']}</td><td>{$row['total_turnamen']}</td><td>{$row['avg_rank']}</td><td><strong>{$row['juara1']}</strong></td><td>{$row['juara2']}</td><td>{$row['juara3']}</td><td>{$row['all_ranks']}</td></tr>";
    }
    echo "</table>";
} else {
    echo "<p style='color:orange;'>No results from Dashboard query for Siko</p>";
}

// 4. Statistik Query Result for Siko
echo "<h2>4. Statistik Query Result (source of truth)</h2>";
$stmt = $conn->prepare("
    WITH ScoreStats AS (
        SELECT
            s.score_board_id,
            s.peserta_id,
            s.kegiatan_id,
            s.category_id,
            SUM(CASE WHEN LOWER(s.score) = 'x' THEN 10 WHEN LOWER(s.score) = 'm' THEN 0 ELSE CAST(s.score AS UNSIGNED) END) as total_score,
            SUM(CASE WHEN LOWER(s.score) = 'x' THEN 1 ELSE 0 END) as total_x
        FROM score s
        GROUP BY s.score_board_id, s.peserta_id, s.kegiatan_id, s.category_id
    ),
    RankedScores AS (
        SELECT
            ss.*,
            RANK() OVER (PARTITION BY ss.score_board_id ORDER BY ss.total_score DESC, ss.total_x DESC) as rank_pos,
            COUNT(*) OVER (PARTITION BY ss.score_board_id) as total_participants
        FROM ScoreStats ss
    )
    SELECT
        rs.peserta_id,
        p.nama_peserta,
        rs.rank_pos,
        rs.total_participants,
        rs.score_board_id
    FROM RankedScores rs
    JOIN peserta p ON rs.peserta_id = p.id
    WHERE p.nama_peserta LIKE ?
    ORDER BY rs.score_board_id DESC
");
$searchLike = '%Siko%';
$stmt->bind_param("s", $searchLike);
$stmt->execute();
$result4 = $stmt->get_result();
if ($result4 && $result4->num_rows > 0) {
    echo "<table><tr><th>Peserta ID</th><th>Nama</th><th>Scoreboard</th><th>Rank</th><th>Total Participants</th></tr>";
    $juara1Count = 0;
    while ($row = $result4->fetch_assoc()) {
        if ($row['rank_pos'] == 1) $juara1Count++;
        echo "<tr><td>{$row['peserta_id']}</td><td>{$row['nama_peserta']}</td><td>{$row['score_board_id']}</td><td>{$row['rank_pos']}</td><td>{$row['total_participants']}</td></tr>";
    }
    echo "</table>";
    echo "<p><strong>Juara 1 count from Statistik logic: {$juara1Count}</strong></p>";
} else {
    echo "<p style='color:orange;'>No results from Statistik query for Siko</p>";
}

// 5. Check if there are other athletes with similar names
echo "<h2>5. Similar Names Check</h2>";
$query5 = "SELECT DISTINCT nama_peserta FROM peserta WHERE nama_peserta LIKE '%iko%' OR nama_peserta LIKE '%Sik%'";
$result5 = $conn->query($query5);
echo "<ul>";
while ($row = $result5->fetch_assoc()) {
    echo "<li>{$row['nama_peserta']}</li>";
}
echo "</ul>";

echo "<h2>6. Raw peserta_id check in score table</h2>";
$query6 = "
    SELECT DISTINCT s.peserta_id, p.nama_peserta, p.nama_club
    FROM score s
    JOIN peserta p ON s.peserta_id = p.id
    WHERE p.nama_peserta LIKE '%Siko%'
";
$result6 = $conn->query($query6);
if ($result6 && $result6->num_rows > 0) {
    echo "<table><tr><th>Peserta ID</th><th>Nama</th><th>Club</th></tr>";
    while ($row = $result6->fetch_assoc()) {
        echo "<tr><td>{$row['peserta_id']}</td><td>{$row['nama_peserta']}</td><td>{$row['nama_club']}</td></tr>";
    }
    echo "</table>";
} else {
    echo "<p>No score entries linked to Siko</p>";
}

// 7. EXACT statistik.php allRankings query for Siko
echo "<h2>7. EXACT statistik.php allRankings Query</h2>";
$queryExactStatistik = "
    WITH ScoreStats AS (
        SELECT
            s.score_board_id,
            s.peserta_id,
            s.kegiatan_id,
            s.category_id,
            SUM(CASE WHEN LOWER(s.score) = 'x' THEN 10 WHEN LOWER(s.score) = 'm' THEN 0 ELSE CAST(s.score AS UNSIGNED) END) as total_score,
            SUM(CASE WHEN LOWER(s.score) = 'x' THEN 1 ELSE 0 END) as total_x
        FROM score s
        GROUP BY s.score_board_id, s.peserta_id, s.kegiatan_id, s.category_id
    ),
    RankedScores AS (
        SELECT
            ss.*,
            RANK() OVER (PARTITION BY ss.score_board_id ORDER BY ss.total_score DESC, ss.total_x DESC) as rank_pos,
            COUNT(*) OVER (PARTITION BY ss.score_board_id) as total_participants
        FROM ScoreStats ss
    )
    SELECT
        rs.peserta_id,
        p.nama_peserta,
        rs.rank_pos,
        rs.total_participants,
        k.nama_kegiatan,
        c.name as category_name,
        sb.created as tanggal
    FROM RankedScores rs
    JOIN kegiatan k ON rs.kegiatan_id = k.id
    JOIN categories c ON rs.category_id = c.id
    JOIN score_boards sb ON rs.score_board_id = sb.id
    JOIN peserta p ON rs.peserta_id = p.id
    WHERE p.nama_peserta LIKE '%siko%'
    ORDER BY sb.created DESC
";
$result7 = $conn->query($queryExactStatistik);
if ($result7 && $result7->num_rows > 0) {
    echo "<p style='color:green;'>Found " . $result7->num_rows . " rows</p>";
    echo "<table><tr><th>Peserta ID</th><th>Nama</th><th>Rank</th><th>Kegiatan</th><th>Category</th></tr>";
    while ($row = $result7->fetch_assoc()) {
        echo "<tr><td>{$row['peserta_id']}</td><td>{$row['nama_peserta']}</td><td>{$row['rank_pos']}</td><td>{$row['nama_kegiatan']}</td><td>{$row['category_name']}</td></tr>";
    }
    echo "</table>";
} else {
    echo "<p style='color:red;font-weight:bold;'>EXACT statistik.php query returns NO ROWS for Siko!</p>";
    echo "<p>This means the JOINs to kegiatan/categories/score_boards are FAILING.</p>";
}

// 8. Check if score records have valid kegiatan_id and category_id
echo "<h2>8. Check Siko's score records for kegiatan_id/category_id</h2>";
$query8 = "
    SELECT DISTINCT
        s.score_board_id,
        s.kegiatan_id,
        s.category_id,
        k.id as kegiatan_exists,
        c.id as category_exists,
        sb.id as scoreboard_exists
    FROM score s
    JOIN peserta p ON s.peserta_id = p.id
    LEFT JOIN kegiatan k ON s.kegiatan_id = k.id
    LEFT JOIN categories c ON s.category_id = c.id
    LEFT JOIN score_boards sb ON s.score_board_id = sb.id
    WHERE p.nama_peserta LIKE '%siko%'
";
$result8 = $conn->query($query8);
echo "<table><tr><th>Scoreboard ID</th><th>Kegiatan ID</th><th>Category ID</th><th>Kegiatan Exists?</th><th>Category Exists?</th><th>Scoreboard Exists?</th></tr>";
while ($row = $result8->fetch_assoc()) {
    $kegiatanOk = $row['kegiatan_exists'] ? '✓' : '<span style="color:red">✗ NULL</span>';
    $categoryOk = $row['category_exists'] ? '✓' : '<span style="color:red">✗ NULL</span>';
    $scoreboardOk = $row['scoreboard_exists'] ? '✓' : '<span style="color:red">✗ NULL</span>';
    echo "<tr><td>{$row['score_board_id']}</td><td>{$row['kegiatan_id']}</td><td>{$row['category_id']}</td><td>{$kegiatanOk}</td><td>{$categoryOk}</td><td>{$scoreboardOk}</td></tr>";
}
echo "</table>";

$conn->close();
?>
