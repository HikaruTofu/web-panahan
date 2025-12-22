<?php include '../config/panggil.php'; ?>
<?php   
    $category = mysqli_query($conn, "SELECT * FROM `categories` WHERE id = ".$_GET['category_id']);
    $category_fetch = mysqli_fetch_assoc($category);

    $kegiatan = mysqli_query($conn, "SELECT * FROM `kegiatan` WHERE id = ".$_GET['kegiatan_id']);
    $kegiatan_fetch = mysqli_fetch_assoc($kegiatan);

    $scoreboard = mysqli_query($conn, "SELECT * FROM `score_boards` WHERE id = ".$_GET['scoreboard']);
    $scoreboard_fetch = mysqli_fetch_assoc($scoreboard);
    
    $peserta_query_value = mysqli_query($conn, "
        SELECT 
            p.id AS peserta_id,
            p.nama_peserta,
            p.jenis_kelamin,
            p.kegiatan_id,
            p.category_id,
            COALESCE(SUM(
                CASE 
                    WHEN s.score = 'm' THEN 0
                    WHEN s.score = 'x' THEN 10
                    ELSE CAST(s.score AS UNSIGNED)
                END
            ), 0) AS total_score,
            COALESCE(SUM(CASE WHEN s.score = 'x' THEN 1 ELSE 0 END), 0) AS jumlah_x
        FROM peserta p
        LEFT JOIN score s 
            ON p.id = s.peserta_id 
            WHERE s.kegiatan_id = ".$_GET['kegiatan_id']."
            AND s.category_id = ".$_GET['category_id']."
            AND s.score_board_id = ".$_GET['scoreboard']."
        GROUP BY p.id, p.nama_peserta
        ORDER BY total_score DESC, jumlah_x DESC;
    ");

    $peserta = [];

    while($b = mysqli_fetch_array($peserta_query_value)) {
        $peserta[] = $b;
    }

    $total_score_peserta = [];
?>
<?php
// export_score.php

$filename = "export_score_board_" . date('Ymd_His') . ".xls";
ob_end_clean();
ini_set('zlib.output_compression', 'Off');
header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
header("Content-Disposition: attachment; filename=\"{$filename}\"");

echo "\xEF\xBB\xBF"; // BOM UTF-8

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:o="urn:schemas-microsoft-com:office:office"
 xmlns:x="urn:schemas-microsoft-com:office:excel"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">' . "\n";

// ==== definisi style
echo " <Styles>\n";
echo '  <Style ss:ID="HeaderStyle">' . "\n";
echo '   <Font ss:Bold="1"/>' . "\n";
echo '   <Interior ss:Color="#D9D9D9" ss:Pattern="Solid"/>' . "\n"; // abu abu
echo '  </Style>' . "\n";
echo '  <Style ss:ID="RankTitle">' . "\n";
echo '   <Font ss:Bold="1" ss:Size="14"/>' . "\n"; // bold + font size 14
echo '  </Style>' . "\n";
echo '  </Style>' . "\n";
echo '  <Style ss:ID="KegiatanTitle">' . "\n";
echo '   <Font ss:Bold="1" ss:Size="14"/>' . "\n"; // bold + font size 14
echo '  </Style>' . "\n";
echo " </Styles>\n";

// ==== mulai worksheet
echo ' <Worksheet ss:Name="Training">' . "\n";
echo "  <Table>\n";

$no_rank = 1;
    echo "   <Row>\n";
    echo '    <Cell ss:StyleID="KegiatanTitle"><Data ss:Type="String">' . $category_fetch['name'] . '</Data></Cell>' . "\n";
    echo "   </Row>\n";
    echo "   <Row>\n";
    echo '    <Cell><Data ss:Type="String">' . $kegiatan_fetch['nama_kegiatan'] . '</Data></Cell>' . "\n";
    echo "   </Row>\n";
    echo "   <Row/>\n";
$total_score_peserta_index = 0;
foreach ($peserta as $p) {
    $total_score_peserta[] = ['nama' => $p['nama_peserta']];
    // Tampilkan judul Rank
    echo "   <Row>\n";
    echo '    <Cell ss:StyleID="RankTitle"><Data ss:Type="String">Rank#' . $no_rank++ . ' ' . htmlspecialchars($p['nama_peserta']) . '</Data></Cell>' . "\n";
    echo "   </Row>\n";

    // Header table
    echo "   <Row>\n";
    echo '    <Cell ss:StyleID="HeaderStyle"><Data ss:Type="String">Rambahan</Data></Cell>' . "\n";
    for ($a = 1; $a <= $scoreboard_fetch['jumlah_anak_panah']; $a++) {
        echo '    <Cell ss:StyleID="HeaderStyle"><Data ss:Type="String">Shot ' . $a . '</Data></Cell>' . "\n";
    }
    echo '    <Cell ss:StyleID="HeaderStyle"><Data ss:Type="String">Total</Data></Cell>' . "\n";
    echo '    <Cell ss:StyleID="HeaderStyle"><Data ss:Type="String">End</Data></Cell>' . "\n";
    echo "   </Row>\n";

    $end_value_total = [];

    for ($s = 1; $s <= $scoreboard_fetch['jumlah_sesi']; $s++) {
        $total_score = 0;

        echo "   <Row>\n";
        echo '    <Cell><Data ss:Type="Number">' . $s . '</Data></Cell>' . "\n";

        for ($a = 1; $a <= $scoreboard_fetch['jumlah_anak_panah']; $a++) {
            $score_query = mysqli_query($conn, "SELECT * FROM score WHERE category_id=" . $_GET['category_id'] . " AND kegiatan_id =" . $_GET['kegiatan_id'] . " AND score_board_id=" . $_GET['scoreboard'] . " AND peserta_id=" . $p['peserta_id'] . " AND session=" . $s . " AND arrow=" . $a);
            $score_fetch = mysqli_fetch_assoc($score_query);

            if (isset($score_fetch)) {
                if ($score_fetch['score'] == "x") {
                    $score_value = 10;
                } elseif ($score_fetch['score'] == "m") {
                    $score_value = 0;
                } else {
                    $score_value = $score_fetch['score'] ?? 0;
                }
            } else {
                $score_value = 0;
            }
            $total_score += $score_value;

            echo '    <Cell><Data ss:Type="String">' . ($score_fetch['score'] ?? "m") . '</Data></Cell>' . "\n";
        }

        // total
        echo '    <Cell><Data ss:Type="Number">' . $total_score . '</Data></Cell>' . "\n";

        // end (cumulative)
        $total_score_peserta[$total_score_peserta_index]['rambahan_'.$s] = $total_score;

        if (empty($end_value_total)) {
            $end_value = $total_score;
            $end_value_total[] = $total_score;
        } else {
            $end_value_total_loop = array_sum($end_value_total);
            $end_value = $end_value_total_loop + $total_score;
            $end_value_total[] = $total_score;
        }

        echo '    <Cell><Data ss:Type="Number">' . $end_value . '</Data></Cell>' . "\n";

        echo "   </Row>\n";
    }
    $total_score_peserta_index = $total_score_peserta_index + 1;

    // spacing antar peserta
    echo "   <Row/>\n";
}

echo "  </Table>\n";
echo " </Worksheet>\n";

echo ' <Worksheet ss:Name="Rekap Skor">' . "\n";
echo "  <Table>\n";
    echo "   <Row>\n";
    echo '    <Cell ss:StyleID="KegiatanTitle"><Data ss:Type="String">' . $category_fetch['name'] . '</Data></Cell>' . "\n";
    echo "   </Row>\n";
    echo "   <Row>\n";
    echo '    <Cell><Data ss:Type="String">' . $kegiatan_fetch['nama_kegiatan'] . '</Data></Cell>' . "\n";
    echo "   </Row>\n";
    echo "   <Row/>\n";

// ===== Header row
echo "   <Row>\n";
echo '    <Cell ss:StyleID="HeaderStyle"><Data ss:Type="String">No</Data></Cell>' . "\n";
echo '    <Cell ss:StyleID="HeaderStyle"><Data ss:Type="String">Nama</Data></Cell>' . "\n";

for ($a = 1; $a <= $scoreboard_fetch['jumlah_sesi']; $a++) {
    echo '    <Cell ss:StyleID="HeaderStyle"><Data ss:Type="String">Rambahan ' . $a . '</Data></Cell>' . "\n";
}
echo '    <Cell ss:StyleID="HeaderStyle"><Data ss:Type="String">Total</Data></Cell>' . "\n";
echo "   </Row>\n";

// ===== Isi row
foreach ($total_score_peserta as $i_tsp => $tsp) {
    $total_tsp = 0;
    echo "   <Row>\n";
    echo '    <Cell><Data ss:Type="Number">' . ($i_tsp + 1) . '</Data></Cell>' . "\n";
    echo '    <Cell><Data ss:Type="String">' . htmlspecialchars($tsp['nama']) . '</Data></Cell>' . "\n";

    for ($a = 1; $a <= $scoreboard_fetch['jumlah_sesi']; $a++) {
        $val = $tsp['rambahan_'.$a] ?? 0;
        $total_tsp += $val;
        echo '    <Cell><Data ss:Type="Number">' . $val . '</Data></Cell>' . "\n";
    }

    echo '    <Cell><Data ss:Type="Number">' . $total_tsp . '</Data></Cell>' . "\n";
    echo "   </Row>\n";
}

echo "  </Table>\n";
echo " </Worksheet>\n";

echo "</Workbook>\n";
exit;
