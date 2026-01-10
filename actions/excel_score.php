<?php
require '../vendor/vendor/autoload.php';
include '../config/panggil.php';
$allowedRoles = ['admin', 'operator', 'petugas'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowedRoles)) {
    enforceAdmin(); 
}

if (!checkRateLimit('action_load', 30, 60)) {
    header('HTTP/1.1 429 Too Many Requests');
    die('Terlalu banyak permintaan. Silakan coba lagi nanti.');
}

$_GET = cleanInput($_GET);

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

// ALIGNMENT WITH DETAIL.PHP LOGIC:
// 1. Fetch distinct participants based on SCORES present in this board.
// 2. Group by Name to handle any duplicate participant IDs.

$peserta_query = "
    SELECT 
        MIN(p.id) AS peserta_id, -- Arbitrary ID for reference
        p.nama_peserta,
        p.jenis_kelamin,
        -- Sum scores for this Name on this Board
        SUM(
            CASE 
                WHEN LOWER(s.score) = 'm' THEN 0
                WHEN LOWER(s.score) = 'x' THEN 10
                ELSE CAST(s.score AS UNSIGNED)
            END
        ) AS total_score,
        SUM(CASE WHEN LOWER(s.score) = 'x' THEN 1 ELSE 0 END) AS jumlah_x,
        SUM(CASE WHEN LOWER(s.score) = 'x' OR s.score = '10' THEN 1 ELSE 0 END) AS jumlah_10_plus_x
    FROM score s
    JOIN peserta p ON s.peserta_id = p.id
    WHERE s.kegiatan_id = ? 
      AND s.category_id = ? 
      AND s.score_board_id = ?
    GROUP BY p.nama_peserta, p.jenis_kelamin
    HAVING total_score > 0
    ORDER BY total_score DESC, jumlah_10_plus_x DESC, jumlah_x DESC, p.nama_peserta ASC;
";

$stmtPeserta = $conn->prepare($peserta_query);
$stmtPeserta->bind_param("iii", $kegiatan_id, $category_id, $scoreboard_id);
$stmtPeserta->execute();
$peserta_result = $stmtPeserta->get_result();

$peserta = [];
while ($b = $peserta_result->fetch_assoc()) {
    $peserta[] = $b;
}
$stmtPeserta->close();

// Optimization: Pre-fetch scores KEYED BY NAME
$allScores = [];
$stmtScores = $conn->prepare("
    SELECT p.nama_peserta, s.session, s.arrow, s.score 
    FROM score s 
    JOIN peserta p ON s.peserta_id = p.id
    WHERE s.score_board_id = ?
");
$stmtScores->bind_param("i", $scoreboard_id);
$stmtScores->execute();
$scoreResult = $stmtScores->get_result();
while ($sc = $scoreResult->fetch_assoc()) {
    // Key by Name instead of ID
    $allScores[$sc['nama_peserta']][$sc['session']][$sc['arrow']] = $sc['score'];
}
$stmtScores->close();

$spreadsheet = new Spreadsheet();

// SHEET 1: DETAIL SCORECARDS (Primary Request)
$sheetDetail = $spreadsheet->getActiveSheet();
$sheetDetail->setTitle('Detail Scorecards');

// SHEET 2: RANKING (Summary)
$sheetRanking = $spreadsheet->createSheet();
$sheetRanking->setTitle('Ranking');

// --- BUILD SHEET 1 (DETAIL SCORECARDS) ---
$rowDetail = 1;
$sheetDetail->getStyle('A1')->getFont()->setSize(16)->setBold(true);
$sheetDetail->setCellValue("A{$rowDetail}", $category_fetch['name']);
$rowDetail++;
$sheetDetail->setCellValue("A{$rowDetail}", $kegiatan_fetch['nama_kegiatan']);
$rowDetail++;
$rowDetail++;

// --- BUILD SHEET 2 (RANKING HEADER) ---
$rowRanking = 1;
$colRanking = 'A';
$sheetRanking->getStyle("A{$rowRanking}")->getFont()->setSize(16)->setBold(true);
$sheetRanking->setCellValue("A{$rowRanking}", $category_fetch['name']);
$rowRanking++;
$sheetRanking->setCellValue("A{$rowRanking}", $kegiatan_fetch['nama_kegiatan']);
$rowRanking++;
$rowRanking++;

$sheetRanking->setCellValue($colRanking . $rowRanking, 'Rank');
$sheetRanking->setCellValue(++$colRanking . $rowRanking, 'Nama');

// Rambahan headers for Ranking
for ($a = 1; $a <= $scoreboard_fetch['jumlah_sesi']; $a++) {
    $sheetRanking->setCellValue(++$colRanking . $rowRanking, "Rambahan $a");
}
$sheetRanking->setCellValue(++$colRanking . $rowRanking, 'Total');
$sheetRanking->setCellValue(++$colRanking . $rowRanking, 'Jumlah X');
$sheetRanking->setCellValue(++$colRanking . $rowRanking, 'Jumlah 10+X');
$rowRanking++;

$no_rank = 1;

foreach ($peserta as $p) {
    // === POPULATE SHEET 1 (DETAIL) ===
    $sheetDetail->getStyle("A{$rowDetail}")->getFont()->setSize(14)->setBold(true);
    $sheetDetail->setCellValue("A{$rowDetail}", "Rank #{$no_rank} - {$p['nama_peserta']}");
    $rowDetail++;

    // Header per participant
    $colDetail = 'A';
    $sheetDetail->setCellValue($colDetail++ . $rowDetail, 'Rambahan');
    for ($a = 1; $a <= $scoreboard_fetch['jumlah_anak_panah']; $a++) {
        $sheetDetail->setCellValue($colDetail++ . $rowDetail, "Shot $a");
    }
    $sheetDetail->setCellValue($colDetail++ . $rowDetail, 'Total');
    $sheetDetail->setCellValue($colDetail . $rowDetail, 'End');

    // Style Header
    $lastColDetail = $colDetail;
    $sheetDetail->getStyle("A{$rowDetail}:{$lastColDetail}{$rowDetail}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheetDetail->getStyle("A{$rowDetail}:{$lastColDetail}{$rowDetail}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('CCCACA');
    $sheetDetail->getStyle("A{$rowDetail}:{$lastColDetail}{$rowDetail}")->getBorders()->getOutline()->setBorderStyle(Border::BORDER_THIN);
    $rowDetail++;
    
    $end_value_total = 0;
    $session_totals = [];

    for ($s = 1; $s <= $scoreboard_fetch['jumlah_sesi']; $s++) {
        $colDetail = 'A';
        $sheetDetail->setCellValue($colDetail++ . $rowDetail, $s);

        $session_score = 0;
        for ($a = 1; $a <= $scoreboard_fetch['jumlah_anak_panah']; $a++) {
            // Lookup by Name
            $score_char = $allScores[$p['nama_peserta']][$s][$a] ?? null;
            
            $score_value = 0;
            if ($score_char !== null) {
                if (strtolower($score_char) == "x") $score_value = 10;
                elseif (strtolower($score_char) == "m") $score_value = 0;
                else $score_value = (int)$score_char;
            }
            $session_score += $score_value;

            $sheetDetail->setCellValue($colDetail++ . $rowDetail, $score_char ?? "M");
        }

        $sheetDetail->setCellValue($colDetail++ . $rowDetail, $session_score);
        $end_value_total += $session_score;
        $sheetDetail->setCellValue($colDetail . $rowDetail, $end_value_total);

        $sheetDetail->getStyle("A{$rowDetail}:{$lastColDetail}{$rowDetail}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheetDetail->getStyle("A{$rowDetail}:{$lastColDetail}{$rowDetail}")->getBorders()->getOutline()->setBorderStyle(Border::BORDER_THIN);
        
        $session_totals[$s] = $session_score;
        $rowDetail++;
    }
    $rowDetail += 2; // Spacing

    // === POPULATE SHEET 2 (RANKING/REKAP) ===
    $colRanking = 'A';
    $sheetRanking->getStyle("A{$rowRanking}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheetRanking->setCellValue($colRanking++ . $rowRanking, $no_rank);
    $sheetRanking->setCellValue($colRanking++ . $rowRanking, $p['nama_peserta']);
    
    for ($s = 1; $s <= $scoreboard_fetch['jumlah_sesi']; $s++) {
        $sheetRanking->getStyle($colRanking . $rowRanking)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheetRanking->setCellValue($colRanking++ . $rowRanking, $session_totals[$s] ?? 0);
    }
    
    $sheetRanking->getStyle($colRanking . $rowRanking)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheetRanking->setCellValue($colRanking++ . $rowRanking, $end_value_total);
    $sheetRanking->setCellValue($colRanking++ . $rowRanking, $p['jumlah_x'] ?? 0);
    $sheetRanking->setCellValue($colRanking . $rowRanking, $p['jumlah_10_plus_x'] ?? 0);

    $rowRanking++;
    $no_rank++;
}

// Styling Sheet 2 (Ranking)
$lastColRanking = chr(ord('A') + $scoreboard_fetch['jumlah_sesi'] + 4);
$headerRange = "A4:{$lastColRanking}4";
$sheetRanking->getStyle($headerRange)->getFont()->setBold(true);
$sheetRanking->getStyle($headerRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheetRanking->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('DDDDDD');
$sheetRanking->getStyle("A4:{$lastColRanking}" . ($rowRanking - 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

foreach (range('A', $lastColRanking) as $col) $sheetRanking->getColumnDimension($col)->setAutoSize(true);

// Set Detail as Active
$spreadsheet->setActiveSheetIndex(0);

$filename = "export_score_board_" . date('Ymd_His') . ".xlsx";

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment;filename={$filename}");
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
