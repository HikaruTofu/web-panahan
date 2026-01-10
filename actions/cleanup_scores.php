<?php
/**
 * Database Cleanup Script
 * Removes score records that don't match databaru.txt source
 */
include '../config/panggil.php';
include_once '../includes/theme.php';
enforceAdmin();

if (!checkRateLimit('action_load', 60, 60)) {
    header('HTTP/1.1 429 Too Many Requests');
    die('Terlalu banyak permintaan. Silakan coba lagi nanti.');
}

$_GET = cleanInput($_GET);
$_POST = cleanInput($_POST);

$executeCleanup = isset($_GET['execute']) && $_GET['execute'] === 'yes';

echo "<h1>Database Score Cleanup</h1>";
echo "<style>
    body { font-family: Arial, sans-serif; margin: 20px; }
    table { border-collapse: collapse; margin: 20px 0; width: 100%; }
    th, td { border: 1px solid #333; padding: 8px; text-align: left; }
    th { background: #f0f0f0; }
    .warning { background: #fff3cd; padding: 15px; border: 1px solid #ffc107; border-radius: 8px; margin: 20px 0; }
    .danger { background: #f8d7da; padding: 15px; border: 1px solid #dc3545; border-radius: 8px; margin: 20px 0; }
    .success { background: #d4edda; padding: 15px; border: 1px solid #28a745; border-radius: 8px; margin: 20px 0; }
    .info { background: #d1ecf1; padding: 15px; border: 1px solid #17a2b8; border-radius: 8px; margin: 20px 0; }
    .btn { padding: 10px 20px; margin: 5px; cursor: pointer; border: none; border-radius: 5px; font-size: 14px; }
    .btn-danger { background: #dc3545; color: white; }
    .btn-secondary { background: #6c757d; color: white; }
</style>";
echo "
<script src=\"https://cdn.tailwindcss.com\"></script>
<script>" . getThemeTailwindConfig() . "</script>
<link rel=\"stylesheet\" href=\"https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css\">";

// Step 1: Get list of athletes NOT in databaru.txt but have scores in DB
echo "<h2>Step 1: Identify Athletes with Scores NOT in databaru.txt</h2>";

// Parse databaru.txt to get valid athlete names
$fileContent = file_get_contents('../databaru.txt');
$lines = explode("\n", $fileContent);
$validAthletes = [];

$inDataSection = false;
foreach ($lines as $line) {
    $line = trim($line);
    if (strpos($line, 'Peringkat,Nama Peserta') !== false) {
        $inDataSection = true;
        continue;
    }
    if (preg_match('/^\d+\.\s+Kategori/i', $line)) {
        $inDataSection = false;
        continue;
    }
    if ($inDataSection && !empty($line) && strpos($line, ',') !== false) {
        $parts = str_getcsv($line);
        if (count($parts) >= 2 && is_numeric($parts[0]) && !empty(trim($parts[1]))) {
            $validAthletes[strtolower(trim($parts[1]))] = trim($parts[1]);
        }
    }
}

echo "<div class='info'>";
echo "<strong>Valid athletes in databaru.txt:</strong> " . count($validAthletes);
echo "</div>";

// Get athletes in DB that are NOT in source file
$queryInvalidAthletes = "
    SELECT DISTINCT
        p.id as peserta_id,
        p.nama_peserta,
        p.nama_club,
        COUNT(DISTINCT s.score_board_id) as scoreboard_count,
        COUNT(s.id) as score_count
    FROM peserta p
    JOIN score s ON s.peserta_id = p.id
    WHERE LOWER(p.nama_peserta) NOT IN ('" . implode("','", array_map(fn($n) => $conn->real_escape_string($n), array_keys($validAthletes))) . "')
    GROUP BY p.id, p.nama_peserta, p.nama_club
    ORDER BY score_count DESC
";

$resultInvalid = $conn->query($queryInvalidAthletes);
$invalidAthletes = [];
$totalScoresToDelete = 0;

if ($resultInvalid && $resultInvalid->num_rows > 0) {
    echo "<table>";
    echo "<tr><th>Peserta ID</th><th>Nama</th><th>Club</th><th>Scoreboards</th><th>Score Records</th></tr>";
    while ($row = $resultInvalid->fetch_assoc()) {
        $invalidAthletes[] = $row;
        $totalScoresToDelete += $row['score_count'];
        echo "<tr>";
        echo "<td>{$row['peserta_id']}</td>";
        echo "<td><strong>{$row['nama_peserta']}</strong></td>";
        echo "<td>{$row['nama_club']}</td>";
        echo "<td>{$row['scoreboard_count']}</td>";
        echo "<td>{$row['score_count']}</td>";
        echo "</tr>";
    }
    echo "</table>";

    echo "<div class='warning'>";
    echo "<strong>Found " . count($invalidAthletes) . " athletes</strong> with scores in DB but NOT in databaru.txt.<br>";
    echo "<strong>Total score records to delete:</strong> " . $totalScoresToDelete;
    echo "</div>";
} else {
    echo "<div class='success'>All athletes with scores are in databaru.txt!</div>";
}

// Step 2: Show scoreboards that might need cleanup
echo "<h2>Step 2: Scoreboards with Invalid Data</h2>";

$queryScoreboards = "
    SELECT
        sb.id as scoreboard_id,
        k.nama_kegiatan,
        c.name as category_name,
        COUNT(DISTINCT s.peserta_id) as total_peserta,
        sb.created
    FROM score s
    LEFT JOIN score_boards sb ON s.score_board_id = sb.id
    LEFT JOIN kegiatan k ON s.kegiatan_id = k.id
    LEFT JOIN categories c ON s.category_id = c.id
    GROUP BY s.score_board_id, sb.id, k.nama_kegiatan, c.name, sb.created
    ORDER BY s.score_board_id
";

$resultSB = $conn->query($queryScoreboards);
echo "<table>";
echo "<tr><th>Scoreboard ID</th><th>Kegiatan</th><th>Category</th><th>Peserta</th><th>Created</th><th>Status</th></tr>";
while ($row = $resultSB->fetch_assoc()) {
    $status = '';
    if (empty($row['nama_kegiatan'])) {
        $status = '<span style="color:orange;">⚠️ No kegiatan</span>';
    } elseif (empty($row['created'])) {
        $status = '<span style="color:orange;">⚠️ No scoreboard record</span>';
    } else {
        $status = '<span style="color:green;">✓ OK</span>';
    }
    echo "<tr>";
    echo "<td>{$row['scoreboard_id']}</td>";
    echo "<td>" . ($row['nama_kegiatan'] ?: '<em>NULL</em>') . "</td>";
    echo "<td>" . ($row['category_name'] ?: '<em>NULL</em>') . "</td>";
    echo "<td>{$row['total_peserta']}</td>";
    echo "<td>" . ($row['created'] ?: '<em>NULL</em>') . "</td>";
    echo "<td>$status</td>";
    echo "</tr>";
}
echo "</table>";

// Execute cleanup if requested
if ($executeCleanup && !empty($invalidAthletes)) {
    echo "<h2>Executing Cleanup...</h2>";

    $deletedScores = 0;
    $stmtDel = $conn->prepare("DELETE FROM score WHERE peserta_id = ?");
    foreach ($invalidAthletes as $athlete) {
        $stmtDel->bind_param("i", $athlete['peserta_id']);
        if ($stmtDel->execute()) {
            $deletedScores += $conn->affected_rows;
            echo "<p>✓ Deleted scores for: " . htmlspecialchars($athlete['nama_peserta']) . " ({$conn->affected_rows} records)</p>";
        } else {
            echo "<p style='color:red;'>✗ Failed to delete scores for: " . htmlspecialchars($athlete['nama_peserta']) . "</p>";
        }
    }
    $stmtDel->close();

    echo "<div class='success'>";
    echo "<strong>Cleanup Complete!</strong><br>";
    echo "Deleted $deletedScores score records from " . count($invalidAthletes) . " athletes.";
    echo "</div>";

    echo "<p><a href='cleanup_scores.php' class='btn btn-secondary'>← Back to Report</a></p>";
} else {
    // Show action buttons
    echo "<h2>Actions</h2>";

    if (!empty($invalidAthletes)) {
        echo "<div class='danger'>";
        echo "<strong>⚠️ WARNING:</strong> Clicking 'Execute Cleanup' will permanently delete $totalScoresToDelete score records for " . count($invalidAthletes) . " athletes who are NOT in databaru.txt.<br><br>";
        echo "<a href='cleanup_scores.php?execute=yes' class='btn btn-danger' onclick=\"const url=this.href; showConfirmModal('Hapus Data', 'Are you sure? This will DELETE $totalScoresToDelete score records!', () => window.location.href = url, 'danger'); return false;\">🗑️ Execute Cleanup</a>";
        echo "<a href='../views/dashboard.php' class='btn btn-secondary'>Cancel</a>";
        echo "</div>";
    } else {
        echo "<div class='success'>No cleanup needed! All score data matches databaru.txt.</div>";
    }
}

$conn->close();

echo getConfirmationModal();
echo getUiScripts();
?>
