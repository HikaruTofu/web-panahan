<?php
require '../vendor/vendor/autoload.php';
include '../config/panggil.php';
enforceAdmin();

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Fill;

$category_id = intval($_GET['category_id'] ?? 0);
$kegiatan_id = intval($_GET['kegiatan_id'] ?? 0);
$scoreboard_id = intval($_GET['scoreboard'] ?? 0);

// Prepared Statements for metadata
$stmtCat = $conn->prepare("SELECT * FROM `categories` WHERE id = ?");
$stmtCat->bind_param("i", $category_id);
$stmtCat->execute();
$category_fetch = $stmtCat->get_result()->fetch_assoc();
$stmtCat->close();

$stmtKeg = $conn->prepare("SELECT * FROM `kegiatan` WHERE id = ?");
$stmtKeg->bind_param("i", $kegiatan_id);
$stmtKeg->execute();
$kegiatan_fetch = $stmtKeg->get_result()->fetch_assoc();
$stmtKeg->close();

$stmtSb = $conn->prepare("SELECT * FROM `score_boards` WHERE id = ?");
$stmtSb->bind_param("i", $scoreboard_id);
$stmtSb->execute();
$scoreboard_fetch = $stmtSb->get_result()->fetch_assoc();
$stmtSb->close();

if (!$category_fetch || !$kegiatan_fetch || !$scoreboard_fetch) {
    die("Data tidak ditemukan.");
}

$peserta_query = "
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
        AND s.kegiatan_id = ?
        AND s.category_id = ?
        AND s.score_board_id = ?
    WHERE p.kegiatan_id = ? AND p.category_id = ?
    GROUP BY p.id, p.nama_peserta, p.jenis_kelamin, p.kegiatan_id, p.category_id
    ORDER BY total_score DESC, jumlah_x DESC;
";

$stmtPeserta = $conn->prepare($peserta_query);
$stmtPeserta->bind_param("iiiii", $kegiatan_id, $category_id, $scoreboard_id, $kegiatan_id, $category_id);
$stmtPeserta->execute();
$peserta_result = $stmtPeserta->get_result();

$peserta = [];
while ($b = $peserta_result->fetch_assoc()) {
    $peserta[] = $b;
}
$stmtPeserta->close();

// OPTIMIZATION: Pre-fetch all scores for this scoreboard
$allScores = [];
$stmtScores = $conn->prepare("SELECT peserta_id, session, arrow, score FROM score WHERE score_board_id = ?");
$stmtScores->bind_param("i", $scoreboard_id);
$stmtScores->execute();
$scoreResult = $stmtScores->get_result();
while ($sc = $scoreResult->fetch_assoc()) {
    $allScores[$sc['peserta_id']][$sc['session']][$sc['arrow']] = $sc['score'];
}
$stmtScores->close();

$total_score_peserta = [];

// // siapkan data (array of arrays)
// $rows = [
//     ['Nama','Umur','Kota'],
//     ['Budi', 30, 'Jakarta'],
//     ['Siti', 27, 'Bandung'],
// ];

// buat spreadsheet
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Training');



$row = 1; // posisi baris awal di Excel
$no_rank = 1;
$total_score_peserta_index = 0;

$sheet->getStyle('A1')->getFont()->setSize(16)->setBold(true);
$sheet->setCellValue("A{$row}", $category_fetch['name']);
$row++;
$sheet->setCellValue("A{$row}", $kegiatan_fetch['nama_kegiatan']);
$row++;
$row++;
foreach ($peserta as $p) {
    // Judul rank
    $total_score_peserta[] = ['nama' => $p['nama_peserta']];

    $sheet->getStyle("A{$row}")->getFont()->setSize(14)->setBold(true);
    $sheet->setCellValue("A{$row}", "Rank#{$no_rank} {$p['nama_peserta']}");
    $row++;

    // Header tabel
    $col = 'A';
    $sheet->setCellValue($col . $row, 'Rambahan');
    $col++;
    for ($a = 1; $a <= $scoreboard_fetch['jumlah_anak_panah']; $a++) {
        $sheet->setCellValue($col . $row, "Shot $a");
        $col++;
    }
    // 
    $sheet->setCellValue($col . $row, 'Total');
    $col++;
    $sheet->setCellValue($col . $row, 'End');
    $sheet->getStyle('A' . $row . ':' . $col . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('A' . $row . ':' . $col . $row)->getFill()->setFillType(Fill::FILL_SOLID)
        ->getStartColor()->setARGB('CCCACA'); // HEX: kuning
    $sheet->getStyle('A' . $row . ':' . $col . $row)->getBorders()->getOutline()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB(Color::COLOR_BLACK);

    $row++;
    // $sheet->getStyle('A1')->getFont()->setSize(16)->setBold(true);

    // Data tiap sesi
    $end_value_total = [];
    for ($s = 1; $s <= $scoreboard_fetch['jumlah_sesi']; $s++) {
        $col = 'A';
        $sheet->setCellValue($col . $row, $s);
        $col++;

        $total_score = 0;

        for ($a = 1; $a <= $scoreboard_fetch['jumlah_anak_panah']; $a++) {
            // OPTIMIZED: Array lookup instead of query
            $score_char = $allScores[$p['peserta_id']][$s][$a] ?? null;

            $score_value = 0;
            if ($score_char !== null) {
                if ($score_char == "x") {
                    $score_value = 10;
                } elseif ($score_char == "m") {
                    $score_value = 0;
                } else {
                    $score_value = $score_char;
                }
            }
            $total_score += (int) $score_value;

            // tulis nilai score (aslinya X/M/angka)
            $sheet->setCellValue($col . $row, $score_char ?? "m");
            $col++;
        }

        // tulis total
        $sheet->setCellValue($col . $row, $total_score);
        $col++;

        // hitung end value
        $total_score_peserta[$total_score_peserta_index] += ['rambahan_' . $s => $total_score];
        $end_value = 0;
        if (empty($end_value_total)) {
            $end_value = $total_score;
            $end_value_total[] = $total_score;
        } else {
            $end_value = array_sum($end_value_total) + $total_score;
            $end_value_total[] = $total_score;
        }
        $sheet->setCellValue($col . $row, $end_value);
        $sheet->getStyle('A' . $row . ':' . $col . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('A' . $row . ':' . $col . $row)->getBorders()->getOutline()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB(Color::COLOR_BLACK);

        $row++;
    }

    $row += 2; // kasih spasi antar peserta
    $no_rank++;
    $total_score_peserta_index++;
}


// Buat worksheet kedua
$spreadsheet->createSheet();
$spreadsheet->setActiveSheetIndex(1);
$sheet2 = $spreadsheet->getActiveSheet();
$sheet2->setTitle('Rekap Total');

// Header
$row = 1;
$col = 'A';
$sheet2->getStyle("A{$row}")->getFont()->setSize(16)->setBold(true);
$sheet2->setCellValue("A{$row}", $category_fetch['name']);
$row++;
$sheet2->setCellValue("A{$row}", $kegiatan_fetch['nama_kegiatan']);
$row++;
$row++;

$sheet2->setCellValue($col . $row, 'No');
$col++;
$sheet2->setCellValue($col . $row, 'Nama');
$col++;

// Rambahan headers
for ($a = 1; $a <= $scoreboard_fetch['jumlah_sesi']; $a++) {
    $sheet2->setCellValue($col . $row, "Rambahan $a");
    $col++;
}

// Kolom total
$sheet2->setCellValue($col . $row, 'Total');
$row++;

// Data peserta
foreach ($total_score_peserta as $i_tsp => $tsp) {
    $col = 'A';
    $sheet2->getStyle($col . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet2->setCellValue($col . $row, $i_tsp + 1);
    $col++;
    $sheet2->setCellValue($col . $row, $tsp['nama']);
    $col++;

    $total_tsp = 0;
    for ($a = 1; $a <= $scoreboard_fetch['jumlah_sesi']; $a++) {
        $nilai = $tsp['rambahan_' . $a] ?? 0;
        $sheet2->getStyle($col . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet2->setCellValue($col . $row, $nilai);
        $total_tsp += $nilai;
        $col++;
    }

    // tulis total
    $sheet2->getStyle($col . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet2->setCellValue($col . $row, $total_tsp);
    $row++;
}

// Contoh styling: header bold + background abu
$lastCol = chr(ord('A') + $scoreboard_fetch['jumlah_sesi'] + 2);
$headerRange = "A4:{$lastCol}4";

$sheet2->getStyle($headerRange)->getFont()->setBold(true);
$sheet2->getStyle($headerRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet2->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID)
    ->getStartColor()->setARGB('DDDDDD');

// Border seluruh tabel
$tableRange = "A4:{$lastCol}" . ($row - 1);
$sheet2->getStyle($tableRange)->getBorders()->getAllBorders()
    ->setBorderStyle(Border::BORDER_THIN);






// header untuk download
$filename = "export_score_board_" . date('Ymd_His') . ".xlsx";

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment;filename={$filename}");
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
