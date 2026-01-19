<?php
// Aktifkan error reporting untuk debuggin
require_once __DIR__ . '/../config/panggil.php';
require_once __DIR__ . '/../includes/check_access.php';
require_once __DIR__ . '/../includes/theme.php';

// Public access for ranking view only (read-only, no sensitive data)
$isPublicRankingView = isset($_GET['action']) && $_GET['action'] === 'scorecard'
                    && isset($_GET['rangking'])
                    && isset($_GET['scoreboard'])
                    && isset($_GET['kegiatan_id'])
                    && isset($_GET['category_id']);

// Allow public access for ranking, require login for everything else
if (!$isPublicRankingView) {
    requireLogin();
}

// Set guest defaults if not logged in (for public ranking view)
$isGuest = !isLoggedIn();
if ($isGuest) {
    $username = 'Guest';
    $name = 'Guest';
    $role = 'viewer';

    // STRICT: Guests can ONLY access the ranking view - nothing else
    // If they try to manipulate URL params, show 403 page
    if (!$isPublicRankingView) {
        http_response_code(403);
        ?>
        <!DOCTYPE html>
        <html lang="id">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Akses Ditolak - Turnamen Panahan</title>
            <script src="https://cdn.tailwindcss.com"></script>
            <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
        </head>
        <body class="min-h-screen bg-zinc-950 flex items-center justify-center p-4">
            <div class="text-center max-w-md">
                <div class="w-20 h-20 rounded-full bg-red-500/20 flex items-center justify-center mx-auto mb-6">
                    <i class="fas fa-lock text-red-500 text-3xl"></i>
                </div>
                <h1 class="text-2xl font-bold text-white mb-2">Akses Ditolak</h1>
                <p class="text-zinc-400 mb-6">Halaman ini memerlukan login. Anda hanya dapat melihat halaman ranking publik.</p>
                <div class="flex flex-col sm:flex-row gap-3 justify-center">
                    <a href="../index.php" class="inline-flex items-center justify-center gap-2 px-6 py-3 rounded-lg bg-green-600 text-white font-medium hover:bg-green-700 transition-colors">
                        <i class="fas fa-sign-in-alt"></i> Login
                    </a>
                    <button onclick="history.back()" class="inline-flex items-center justify-center gap-2 px-6 py-3 rounded-lg bg-zinc-800 text-zinc-300 font-medium hover:bg-zinc-700 transition-colors">
                        <i class="fas fa-arrow-left"></i> Kembali
                    </button>
                </div>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
}

// Handle export to Excel
if (isset($_GET['export']) && $_GET['export'] == 'excel') {
    // Izinkan petugas/operator untu export
    if (!canInputScore()) {
       enforceAdmin();
    }
    require_once __DIR__ . '/../vendor/vendor/autoload.php';
    
    // use PhpOffice\PhpSpreadsheet\Spreadsheet;
    // use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
    // use PhpOffice\PhpSpreadsheet\Style\Alignment;

    $kegiatan_id_export = trim($_GET['kegiatan_id'] ?? '');
    $filter_kategori_export = trim($_GET['filter_kategori'] ?? '');
    $filter_gender_export = trim($_GET['filter_gender'] ?? '');
    $search_export = trim($_GET['search'] ?? '');

    // Reuse the main query logic (simplified for export)
    // We need to build a query that matches the view's data
    // Use robust filter logic
    
    $query = "SELECT DISTINCT p.*, c.name AS category_name
              FROM peserta p
              LEFT JOIN categories c ON p.category_id = c.id
              LEFT JOIN score sc ON sc.peserta_id = p.id AND sc.kegiatan_id = p.kegiatan_id
              WHERE 1=1";
    
    $params = [];
    $types = '';
    
    // Filter by Kegiatan (Critical for Detail View)
    if (!empty($kegiatan_id_export)) {
         $query .= " AND (p.kegiatan_id = ? OR sc.kegiatan_id = ?)";
         $params[] = $kegiatan_id_export;
         $params[] = $kegiatan_id_export;
         $types .= "ii";
    }

    if (!empty($filter_kategori_export)) {
        $query .= " AND p.category_id = ?";
        $params[] = $filter_kategori_export;
        $types .= "i";
    }

    if (!empty($filter_gender_export)) {
        $query .= " AND p.jenis_kelamin = ?";
        $params[] = $filter_gender_export;
        $types .= "s";
    }

    if (!empty($search_export)) {
        $query .= " AND (LOWER(p.nama_peserta) LIKE LOWER(?) OR LOWER(p.nama_club) LIKE LOWER(?) OR LOWER(p.asal_kota) LIKE LOWER(?))";
        $searchTerm = "%$search_export%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $types .= "sss";
    }

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
    $sheet->setTitle('Data Peserta Detail');

    // Headers
    $headers = [
        'No', 'Nama Peserta', 'Kategori', 'Tanggal Lahir', 'Umur', 
        'Jenis Kelamin', 'Asal Kota', 'Nama Club', 'Sekolah', 'Kelas', 
        'Nomor HP', 'Status Pembayaran', 'Tanggal Daftar'
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
    while ($item = $result->fetch_assoc()) {
        $umur = "-";
        if (!empty($item['tanggal_lahir'])) {
            $dob = new DateTime($item['tanggal_lahir']);
            $today = new DateTime();
            $umur = $today->diff($dob)->y . " tahun";
        }

        $statusBayar = !empty($item['bukti_pembayaran']) ? 'Sudah Bayar' : 'Belum Bayar';

        $col = 'A';
        $sheet->setCellValue($col++ . $rowIdx, $no++);
        $sheet->setCellValue($col++ . $rowIdx, $item['nama_peserta']);
        $sheet->setCellValue($col++ . $rowIdx, $item['category_name'] ?? '-');
        $sheet->setCellValue($col++ . $rowIdx, $item['tanggal_lahir'] ?? '-');
        $sheet->setCellValue($col++ . $rowIdx, $umur);
        $sheet->setCellValue($col++ . $rowIdx, $item['jenis_kelamin']);
        $sheet->setCellValue($col++ . $rowIdx, $item['asal_kota'] ?? '-');
        $sheet->setCellValue($col++ . $rowIdx, $item['nama_club'] ?? '-');
        $sheet->setCellValue($col++ . $rowIdx, $item['sekolah'] ?? '-');
        $sheet->setCellValue($col++ . $rowIdx, $item['kelas'] ?? '-');
        $sheet->setCellValue($col++ . $rowIdx, $item['nomor_hp'] ?? '-'); 
        $sheet->getStyle($col . $rowIdx)->getNumberFormat()->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_TEXT);

        $sheet->setCellValue($col++ . $rowIdx, $statusBayar);
        
        $created_at = $item['created_at'] ?? '-';
        $sheet->setCellValue($col++ . $rowIdx, $created_at);

        $rowIdx++;
    }

    // Auto-size columns
    foreach (range('A', $col) as $columnID) {
        $sheet->getColumnDimension($columnID)->setAutoSize(true);
    }

    $filename = "detail_peserta_" . date('Y-m-d_His') . ".xlsx";

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    
    if (ob_get_length()) ob_clean();

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}


if (!checkRateLimit('view_load', 60, 60)) {
    header('HTTP/1.1 429 Too Many Requests');
    die('Terlalu banyak permintaan. Silakan coba lagi nanti.');
}

$_GET = cleanInput($_GET);

// Mulai session jika belum
if (session_status() == PHP_SESSION_NONE) {
    // session_start();
}

// Get user info from session (or use guest defaults for public ranking)
if (!$isGuest) {
    $username = $_SESSION['username'] ?? 'User';
    $name = $_SESSION['name'] ?? $username;
    $role = $_SESSION['role'] ?? 'user';
}

// ============================================
// HANDLER UNTUK BRACKET TOURNAMENT (ADUAN)
// ============================================
if (isset($_GET['aduan']) && $_GET['aduan'] == 'true') {
    $kegiatan_id = isset($_GET['kegiatan_id']) ? intval($_GET['kegiatan_id']) : null;
    $category_id = isset($_GET['category_id']) ? intval($_GET['category_id']) : null;
    $scoreboard_id = isset($_GET['scoreboard']) ? intval($_GET['scoreboard']) : null;

    if (!$kegiatan_id || !$category_id || !$scoreboard_id) {
        die("Parameter tidak lengkap.");
    }

    // Handler untuk menyimpan hasil match
    if (isset($_POST['save_match_result'])) {
        if (!checkRateLimit('aduan_action', 30, 60)) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Terlalu banyak permintaan.']);
            exit;
        }
        verify_csrf();
        $_POST = cleanInput($_POST);
        header('Content-Type: application/json');

        $match_id = $_POST['match_id'] ?? '';
        $winner_id = intval($_POST['winner_id'] ?? 0);
        $loser_id = intval($_POST['loser_id'] ?? 0);
        $bracket_size = intval($_POST['bracket_size'] ?? 0);

        try {
            // Check if match result already exists
            $checkQuery = "SELECT id FROM bracket_matches WHERE kegiatan_id = ? AND category_id = ? AND scoreboard_id = ? AND match_id = ?";
            $checkStmt = $conn->prepare($checkQuery);
            $checkStmt->bind_param("iiis", $kegiatan_id, $category_id, $scoreboard_id, $match_id);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();

            if ($checkResult->num_rows > 0) {
                // Update existing record
                $updateQuery = "UPDATE bracket_matches SET winner_id = ?, loser_id = ?, updated_at = NOW() WHERE kegiatan_id = ? AND category_id = ? AND scoreboard_id = ? AND match_id = ?";
                $updateStmt = $conn->prepare($updateQuery);
                $updateStmt->bind_param("iiiiss", $winner_id, $loser_id, $kegiatan_id, $category_id, $scoreboard_id, $match_id);
                $updateStmt->execute();
                $updateStmt->close();
            } else {
                // Insert new record
                $insertQuery = "INSERT INTO bracket_matches (kegiatan_id, category_id, scoreboard_id, match_id, winner_id, loser_id, bracket_size, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
                $insertStmt = $conn->prepare($insertQuery);
                $insertStmt->bind_param("iiisiii", $kegiatan_id, $category_id, $scoreboard_id, $match_id, $winner_id, $loser_id, $bracket_size);
                $insertStmt->execute();
                $insertStmt->close();
            }

            $checkStmt->close();

            echo json_encode(['status' => 'success', 'message' => 'Match result saved']);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }

        $conn->close();
        exit;
    }

    // Handler untuk menyimpan champion
    if (isset($_POST['save_champion'])) {
        if (!checkRateLimit('aduan_action', 30, 60)) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Terlalu banyak permintaan.']);
            exit;
        }
        verify_csrf();
        $_POST = cleanInput($_POST);
        header('Content-Type: application/json');

        $champion_id = intval($_POST['champion_id'] ?? 0);
        $runner_up_id = intval($_POST['runner_up_id'] ?? 0);
        $third_place_id = !empty($_POST['third_place_id']) ? intval($_POST['third_place_id']) : null;
        $bracket_size = intval($_POST['bracket_size'] ?? 0);

        try {
            // Check if champion record already exists
            $checkQuery = "SELECT id FROM bracket_champions WHERE kegiatan_id = ? AND category_id = ? AND scoreboard_id = ?";
            $checkStmt = $conn->prepare($checkQuery);
            $checkStmt->bind_param("iii", $kegiatan_id, $category_id, $scoreboard_id);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();

            if ($checkResult->num_rows > 0) {
                // Update existing record
                if ($third_place_id !== null) {
                    $updateQuery = "UPDATE bracket_champions SET champion_id = ?, runner_up_id = ?, third_place_id = ?, bracket_size = ?, updated_at = NOW() WHERE kegiatan_id = ? AND category_id = ? AND scoreboard_id = ?";
                    $updateStmt = $conn->prepare($updateQuery);
                    $updateStmt->bind_param("iiiiii", $champion_id, $runner_up_id, $third_place_id, $bracket_size, $kegiatan_id, $category_id, $scoreboard_id);
                } else {
                    $updateQuery = "UPDATE bracket_champions SET champion_id = ?, runner_up_id = ?, bracket_size = ?, updated_at = NOW() WHERE kegiatan_id = ? AND category_id = ? AND scoreboard_id = ?";
                    $updateStmt = $conn->prepare($updateQuery);
                    $updateStmt->bind_param("iiiiii", $champion_id, $runner_up_id, $bracket_size, $kegiatan_id, $category_id, $scoreboard_id);
                }
                $updateStmt->execute();
                $updateStmt->close();
            } else {
                // Insert new record
                if ($third_place_id !== null) {
                    $insertQuery = "INSERT INTO bracket_champions (kegiatan_id, category_id, scoreboard_id, champion_id, runner_up_id, third_place_id, bracket_size, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
                    $insertStmt = $conn->prepare($insertQuery);
                    $insertStmt->bind_param("iiiiiii", $kegiatan_id, $category_id, $scoreboard_id, $champion_id, $runner_up_id, $third_place_id, $bracket_size);
                } else {
                    $insertQuery = "INSERT INTO bracket_champions (kegiatan_id, category_id, scoreboard_id, champion_id, runner_up_id, bracket_size, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())";
                    $insertStmt = $conn->prepare($insertQuery);
                    $insertStmt->bind_param("iiiiii", $kegiatan_id, $category_id, $scoreboard_id, $champion_id, $runner_up_id, $bracket_size);
                }
                $insertStmt->execute();
                $insertStmt->close();
            }

            $checkStmt->close();

            echo json_encode(['status' => 'success', 'message' => 'Champion saved']);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }

        $conn->close();
        exit;
    }

    // Ambil data kegiatan
    $kegiatanData = [];
    try {
        $queryKegiatan = "SELECT id, nama_kegiatan FROM kegiatan WHERE id = ?";
        $stmtKegiatan = $conn->prepare($queryKegiatan);
        $stmtKegiatan->bind_param("i", $kegiatan_id);
        $stmtKegiatan->execute();
        $resultKegiatan = $stmtKegiatan->get_result();

        if ($resultKegiatan->num_rows > 0) {
            $kegiatanData = $resultKegiatan->fetch_assoc();
        }
        $stmtKegiatan->close();
    } catch (Exception $e) {
        die("Error mengambil data kegiatan: " . $e->getMessage());
    }

    // Ambil data kategori
    $kategoriData = [];
    try {
        $queryKategori = "SELECT id, name FROM categories WHERE id = ?";
        $stmtKategori = $conn->prepare($queryKategori);
        $stmtKategori->bind_param("i", $category_id);
        $stmtKategori->execute();
        $resultKategori = $stmtKategori->get_result();

        if ($resultKategori->num_rows > 0) {
            $kategoriData = $resultKategori->fetch_assoc();
        }
        $stmtKategori->close();
    } catch (Exception $e) {
        die("Error mengambil data kategori: " . $e->getMessage());
    }

    // Ambil data peserta berdasarkan ranking
    $pesertaList = [];
    try {
        // Ambil data peserta berdasarkan ranking (OPTIMIZED)
        $pesertaList = [];
        try {
            $queryPeserta = "
            SELECT 
                MAX(p.id) as id,
                p.nama_peserta,
                p.jenis_kelamin,
                COALESCE(SUM(
                    CASE 
                        WHEN LOWER(s.score) = 'x' THEN 10 
                        WHEN LOWER(s.score) = 'm' THEN 0 
                        ELSE CAST(s.score AS UNSIGNED) 
                    END
                ), 0) as total_score,
                COUNT(CASE WHEN LOWER(s.score) = 'x' THEN 1 END) as total_x,
                COUNT(CASE WHEN LOWER(s.score) = 'x' OR s.score = '10' THEN 1 END) as total_10_plus_x
            FROM peserta p
            LEFT JOIN score s ON p.id = s.peserta_id 
                AND s.kegiatan_id = ? 
                AND s.category_id = ? 
                AND s.score_board_id = ?
            WHERE p.category_id = ? AND p.nama_peserta IN (SELECT nama_peserta FROM peserta WHERE kegiatan_id = ?)
            GROUP BY p.nama_peserta, p.jenis_kelamin
            ORDER BY total_score DESC, total_10_plus_x DESC, total_x DESC, p.nama_peserta ASC
        ";
            $stmtPeserta = $conn->prepare($queryPeserta);
            // Bind params: kegiatan_id, category_id, scoreboard_id (for JOIN), then category_id, kegiatan_id (for inclusive WHERE)
            $stmtPeserta->bind_param("iiiii", $kegiatan_id, $category_id, $scoreboard_id, $category_id, $kegiatan_id);
            $stmtPeserta->execute();
            $resultPeserta = $stmtPeserta->get_result();

            while ($row = $resultPeserta->fetch_assoc()) {
                $pesertaList[] = $row;
            }

            $stmtPeserta->close();
        } catch (Exception $e) {
            die("Error mengambil data peserta: " . $e->getMessage());
        }
    } catch (Exception $e) {
        die("Error mengambil data peserta: " . $e->getMessage());
    }

    $conn->close();
    ?>
    <!DOCTYPE html>
    <html lang="id" class="h-full">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Bracket <?= htmlspecialchars($kategoriData['name']) ?></title>
        <script src="https://cdn.tailwindcss.com"></script>
        <script><?= getThemeTailwindConfig() ?></script>
        <script><?= getThemeInitScript() ?></script>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <style>
            .custom-scrollbar::-webkit-scrollbar { width: 6px; height: 6px; }
            .custom-scrollbar::-webkit-scrollbar-track { background: rgba(255,255,255,0.05); border-radius: 3px; }
            .custom-scrollbar::-webkit-scrollbar-thumb { background: rgba(100,116,139,0.5); border-radius: 3px; }

            .player-card { transition: all 0.15s ease; }
            .player-card:hover:not(.empty):not(.winner):not(.eliminated) { background: #e2e8f0 !important; }
            .dark .player-card:hover:not(.empty):not(.winner):not(.eliminated) { background: #3f3f46 !important; }
            .player-card.winner { background: #dcfce7 !important; border-color: #16a34a !important; color: #15803d !important; }
            .player-card.eliminated { opacity: 0.4; text-decoration: line-through; }
            .player-card.empty { background: #1e293b !important; color: #64748b !important; cursor: default; }
            .player-card.ready { background: #fef3c7 !important; border-color: #f59e0b !important; }

            .size-btn.active { background: #16a34a !important; border-color: #16a34a !important; }

            @media print { .no-print { display: none !important; } }
        </style>
    </head>

    <body class="h-full bg-slate-50 dark:bg-zinc-950 transition-colors">
        <div class="flex h-full">
            <!-- Sidebar -->
            <aside class="hidden lg:flex lg:flex-col w-72 bg-zinc-900 text-white flex-shrink-0">
                <div class="flex items-center gap-3 px-6 py-5 border-b border-zinc-800">
                    <div class="w-10 h-10 rounded-lg bg-archery-600 flex items-center justify-center">
                        <i class="fas fa-bullseye text-white"></i>
                    </div>
                    <div>
                        <h1 class="font-semibold text-sm">Turnamen Panahan</h1>
                        <p class="text-xs text-zinc-400">Management System</p>
                    </div>
                </div>
                <nav class="flex-1 px-4 py-6 space-y-1 overflow-y-auto">
                    <a href="dashboard.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800 transition-colors">
                        <i class="fas fa-home w-5"></i><span class="text-sm">Dashboard</span>
                    </a>
                    <div class="pt-4">
                        <p class="px-4 text-xs font-semibold text-zinc-500 uppercase tracking-wider mb-2">Master Data</p>
                        <a href="users.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800 transition-colors">
                            <i class="fas fa-users w-5"></i><span class="text-sm">Users</span>
                        </a>
                        <a href="categori.view.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800 transition-colors">
                            <i class="fas fa-tags w-5"></i><span class="text-sm">Kategori</span>
                        </a>
                    </div>
                    <div class="pt-4">
                        <p class="px-4 text-xs font-semibold text-zinc-500 uppercase tracking-wider mb-2">Tournament</p>
                        <a href="kegiatan.view.php" class="flex items-center gap-3 px-4 py-3 rounded-lg bg-archery-600/20 text-archery-400 border border-archery-600/30 transition-colors">
                            <i class="fas fa-calendar w-5"></i><span class="text-sm font-medium">Kegiatan</span>
                        </a>
                        <a href="peserta.view.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800 transition-colors">
                            <i class="fas fa-user-friends w-5"></i><span class="text-sm">Peserta</span>
                        </a>
                        <a href="statistik.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800 transition-colors">
                            <i class="fas fa-chart-bar w-5"></i><span class="text-sm">Statistik</span>
                        </a>
                    </div>

                    <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                    <div class="pt-4">
                        <p class="px-4 text-xs font-semibold text-zinc-500 uppercase tracking-wider mb-2">System</p>
                        <a href="recovery.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800 transition-colors">
                            <i class="fas fa-trash-restore w-5"></i>
                            <span class="text-sm">Data Recovery</span>
                        </a>
                    </div>
                    <?php endif; ?>
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
                        <i class="fas fa-sign-out-alt w-5"></i><span>Logout</span>
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
                    <!-- Breadcrumb -->
                    <nav class="flex items-center gap-2 text-sm text-slate-500 dark:text-zinc-400 mb-4 no-print">
                        <a href="dashboard.php" class="hover:text-archery-600 transition-colors">Dashboard</a>
                        <i class="fas fa-chevron-right text-xs text-slate-300 dark:text-zinc-600"></i>
                        <a href="kegiatan.view.php" class="hover:text-archery-600 transition-colors">Kegiatan</a>
                        <i class="fas fa-chevron-right text-xs text-slate-300 dark:text-zinc-600"></i>
                        <a href="detail.php?id=<?= $kegiatan_id ?>" class="hover:text-archery-600 transition-colors"><?= htmlspecialchars($kegiatanData['nama_kegiatan']) ?></a>
                        <i class="fas fa-chevron-right text-xs text-slate-300 dark:text-zinc-600"></i>
                        <span class="text-slate-900 dark:text-white font-medium">Bracket</span>
                    </nav>

                    <!-- Bracket Container with Dark Theme -->
                    <div class="bg-zinc-900 rounded-xl border border-zinc-800 p-4 md:p-6 text-white">
                        <!-- Header Bar -->
                        <div class="flex items-center justify-between mb-6">
                            <div class="flex items-center gap-4">
                                <a href="detail.php?id=<?= $kegiatan_id ?>"
                                   class="p-2 rounded-lg text-slate-400 hover:bg-white/10 transition-colors no-print">
                                    <i class="fas fa-arrow-left"></i>
                                </a>
                                <div>
                                    <h1 class="font-semibold text-white">Bracket Eliminasi</h1>
                                    <p class="text-sm text-slate-400"><?= htmlspecialchars($kategoriData['name']) ?> • <?= count($pesertaList) ?> peserta</p>
                                </div>
                            </div>
                        </div>

                        <!-- Setup Container -->
            <div class="bg-zinc-800 rounded-xl p-8 text-center max-w-md mx-auto border border-zinc-700" id="setupContainer">
                <p class="text-slate-400 mb-6">Pilih ukuran bracket</p>

                <div class="flex gap-3 justify-center mb-6">
                    <button class="size-btn px-8 py-4 rounded-lg text-xl font-bold text-white border-2 border-zinc-600 hover:border-zinc-500 transition-colors"
                            onclick="selectBracketSize(16)" id="size16">16</button>
                    <button class="size-btn px-8 py-4 rounded-lg text-xl font-bold text-white border-2 border-zinc-600 hover:border-zinc-500 transition-colors"
                            onclick="selectBracketSize(32)" id="size32">32</button>
                </div>

                <button class="w-full px-6 py-3 rounded-lg text-base font-medium bg-archery-600 text-white hover:bg-archery-700 transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                        id="startBracketBtn" onclick="startBracket()" disabled>
                    Mulai Bracket
                </button>
            </div>

            <!-- Bracket Container -->
            <div class="hidden mt-6 overflow-x-auto custom-scrollbar" id="bracketContainer">
                <div class="flex items-center justify-between mb-6 no-print">
                    <button class="px-4 py-2 rounded-lg text-sm font-medium bg-archery-600 text-white hover:bg-archery-700 transition-colors"
                            id="generateBtn" onclick="generateBracket()">
                        <i class="fas fa-random mr-2"></i> Acak Bracket
                    </button>
                    <button class="px-4 py-2 rounded-lg text-sm font-medium text-slate-400 hover:bg-white/10 transition-colors"
                            onclick="backToSetup()">
                        <i class="fas fa-redo mr-2"></i> Reset
                    </button>
                </div>

                <div id="bracketContent">
                    <!-- Bracket akan di-generate di sini -->
                </div>

                <!-- Third Place Section -->
                <div class="hidden bg-zinc-800 border border-zinc-700 rounded-xl p-6 mt-8 text-center max-w-sm mx-auto" id="thirdPlaceSection">
                    <p class="text-sm text-slate-400 mb-4">Perebutan Juara 3</p>
                    <div id="thirdPlaceMatch">
                        <div class="match flex flex-col gap-2">
                            <div class="player-card empty px-4 py-3 rounded-lg text-sm font-medium text-center">Menunggu SF</div>
                            <div class="player-card empty px-4 py-3 rounded-lg text-sm font-medium text-center">Menunggu SF</div>
                        </div>
                    </div>
                        </div>
                    </div>
                </div>
            </main>
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
            <nav class="flex-1 px-4 py-6 space-y-1 overflow-y-auto">
                <a href="dashboard.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800 transition-colors">
                    <i class="fas fa-home w-5"></i><span class="text-sm">Dashboard</span>
                </a>

                <div class="pt-4">
                    <p class="px-4 text-xs font-semibold text-zinc-500 uppercase tracking-wider mb-2">Master Data</p>
                    <a href="users.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800 transition-colors">
                        <i class="fas fa-users w-5"></i><span class="text-sm">Users</span>
                    </a>
                    <a href="categori.view.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800 transition-colors">
                        <i class="fas fa-tags w-5"></i><span class="text-sm">Kategori</span>
                    </a>
                </div>

                <div class="pt-4">
                    <p class="px-4 text-xs font-semibold text-zinc-500 uppercase tracking-wider mb-2">Tournament</p>
                    <a href="kegiatan.view.php" class="flex items-center gap-3 px-4 py-3 rounded-lg bg-archery-600/20 text-archery-400 border border-archery-600/30 transition-colors">
                        <i class="fas fa-calendar w-5"></i><span class="text-sm font-medium">Kegiatan</span>
                    </a>
                    <a href="peserta.view.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800 transition-colors">
                        <i class="fas fa-user-friends w-5"></i><span class="text-sm">Peserta</span>
                    </a>
                    <a href="statistik.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800 transition-colors">
                        <i class="fas fa-chart-bar w-5"></i><span class="text-sm">Statistik</span>
                    </a>
                </div>

                <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                <div class="pt-4">
                    <p class="px-4 text-xs font-semibold text-zinc-500 uppercase tracking-wider mb-2">System</p>
                    <a href="recovery.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800 transition-colors">
                        <i class="fas fa-trash-restore w-5"></i>
                        <span class="text-sm">Data Recovery</span>
                    </a>
                </div>
                <?php endif; ?>
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
            const pesertaData = <?= json_encode($pesertaList) ?>;
            let selectedSize = 0;
            let shuffledPeserta = [];
            let bracketData = {};
            let semifinalLosers = [];

            function selectBracketSize(size) {
                selectedSize = size;

                document.querySelectorAll('.size-btn').forEach(btn => {
                    btn.classList.remove('active');
                });
                document.getElementById('size' + size).classList.add('active');

                document.getElementById('startBracketBtn').disabled = false;
            }

            function startBracket() {
                if (selectedSize === 0) {
                    alert('Pilih jumlah peserta terlebih dahulu!');
                    return;
                }

                if (pesertaData.length < 2) {
                    alert('Minimal 2 peserta diperlukan untuk membuat bracket!');
                    return;
                }

                document.getElementById('setupContainer').style.display = 'none';
                document.getElementById('bracketContainer').classList.remove('hidden');

                showPlaceholderBracket();
            }

            function backToSetup() {
                showConfirmModal('Reset Bracket', 'Kembali ke setup akan mereset semua data bracket. Lanjutkan?', () => {
                    document.getElementById('setupContainer').style.display = 'block';
                    document.getElementById('bracketContainer').classList.add('hidden');

                    document.getElementById('bracketContent').innerHTML = '';
                    document.getElementById('thirdPlaceMatch').innerHTML = '';
                    document.getElementById('thirdPlaceSection').classList.add('hidden');
                    bracketData = {};
                    shuffledPeserta = [];
                    semifinalLosers = [];
                }, 'warning');
            }

            function showPlaceholderBracket() {
                if (selectedSize === 16) {
                    showPlaceholder16Bracket();
                } else {
                    showPlaceholder32Bracket();
                }
            }

            function showPlaceholder16Bracket() {
                const bracketHTML = `
                    <div class="flex gap-6 min-w-fit py-4">
                        <div class="flex flex-col justify-around flex-1 min-w-[160px]">
                            <div class="text-xs font-medium text-slate-500 uppercase tracking-wide mb-3 text-center">Round of 16</div>
                            ${generatePlaceholderMatches(8)}
                        </div>
                        <div class="flex flex-col justify-around flex-1 min-w-[160px]">
                            <div class="text-xs font-medium text-slate-500 uppercase tracking-wide mb-3 text-center">Quarter</div>
                            ${generatePlaceholderMatches(4)}
                        </div>
                        <div class="flex flex-col justify-around flex-1 min-w-[160px]">
                            <div class="text-xs font-medium text-slate-500 uppercase tracking-wide mb-3 text-center">Semi</div>
                            ${generatePlaceholderMatches(2)}
                        </div>
                        <div class="flex flex-col items-center justify-center flex-1 min-w-[160px]">
                            <div class="text-xs font-medium text-slate-500 uppercase tracking-wide mb-3 text-center">Final</div>
                            <div class="text-4xl mb-4">🏆</div>
                            <div class="flex flex-col gap-2">
                                <div class="player-card empty px-4 py-2 rounded-lg min-w-[140px] text-sm font-medium text-center">Finalist 1</div>
                                <div class="player-card empty px-4 py-2 rounded-lg min-w-[140px] text-sm font-medium text-center">Finalist 2</div>
                            </div>
                            <div class="hidden mt-6 px-6 py-3 rounded-lg text-lg font-bold bg-amber-400 text-zinc-900" id="champion">Champion</div>
                        </div>
                    </div>
                `;
                document.getElementById('bracketContent').innerHTML = bracketHTML;
            }

            function showPlaceholder32Bracket() {
                const bracketHTML = `
                    <div class="flex gap-6 min-w-fit py-4">
                        <div class="flex flex-col justify-around flex-1 min-w-[160px]">
                            <div class="text-xs font-medium text-slate-500 uppercase tracking-wide mb-3 text-center">Round of 32</div>
                            ${generatePlaceholderMatches(16)}
                        </div>
                        <div class="flex flex-col justify-around flex-1 min-w-[160px]">
                            <div class="text-xs font-medium text-slate-500 uppercase tracking-wide mb-3 text-center">Round of 16</div>
                            ${generatePlaceholderMatches(8)}
                        </div>
                        <div class="flex flex-col justify-around flex-1 min-w-[160px]">
                            <div class="text-xs font-medium text-slate-500 uppercase tracking-wide mb-3 text-center">Quarter</div>
                            ${generatePlaceholderMatches(4)}
                        </div>
                        <div class="flex flex-col justify-around flex-1 min-w-[160px]">
                            <div class="text-xs font-medium text-slate-500 uppercase tracking-wide mb-3 text-center">Semi</div>
                            ${generatePlaceholderMatches(2)}
                        </div>
                        <div class="flex flex-col items-center justify-center flex-1 min-w-[160px]">
                            <div class="text-xs font-medium text-slate-500 uppercase tracking-wide mb-3 text-center">Final</div>
                            <div class="text-4xl mb-4">🏆</div>
                            <div class="flex flex-col gap-2">
                                <div class="player-card empty px-4 py-2 rounded-lg min-w-[140px] text-sm font-medium text-center">Finalist 1</div>
                                <div class="player-card empty px-4 py-2 rounded-lg min-w-[140px] text-sm font-medium text-center">Finalist 2</div>
                            </div>
                            <div class="hidden mt-6 px-6 py-3 rounded-lg text-lg font-bold bg-amber-400 text-zinc-900" id="champion">Champion</div>
                        </div>
                    </div>
                `;
                document.getElementById('bracketContent').innerHTML = bracketHTML;
            }

            function generatePlaceholderMatches(numMatches) {
                let html = '';
                for (let i = 0; i < numMatches; i++) {
                    html += `
                        <div class="flex flex-col gap-1 my-2">
                            <div class="player-card empty px-3 py-2 rounded min-w-[140px] text-sm font-medium text-center">TBD</div>
                            <div class="player-card empty px-3 py-2 rounded min-w-[140px] text-sm font-medium text-center">TBD</div>
                        </div>
                    `;
                }
                return html;
            }

            function shuffleArray(array) {
                const newArray = [...array];
                for (let i = newArray.length - 1; i > 0; i--) {
                    const j = Math.floor(Math.random() * (i + 1));
                    [newArray[i], newArray[j]] = [newArray[j], newArray[i]];
                }
                return newArray;
            }

            function generateBracket() {
                if (selectedSize === 0) {
                    alert('Pilih jumlah peserta terlebih dahulu!');
                    return;
                }

                if (pesertaData.length < 2) {
                    alert('Minimal 2 peserta diperlukan untuk membuat bracket!');
                    return;
                }

                shuffledPeserta = shuffleArray(pesertaData).slice(0, selectedSize);

                while (shuffledPeserta.length < selectedSize) {
                    shuffledPeserta.push({ id: null, nama_peserta: 'BYE', empty: true });
                }

                bracketData = {};
                semifinalLosers = [];
                shuffledPeserta.forEach((player, index) => {
                    bracketData[index] = {
                        player: player,
                        round: 1,
                        position: index
                    };
                });

                if (selectedSize === 16) {
                    generate16Bracket();
                } else {
                    generate32Bracket();
                }

                document.getElementById('thirdPlaceSection').classList.remove('hidden');
            }

            function generate16Bracket() {
                const bracketHTML = `
                    <div class="flex gap-6 min-w-fit py-4">
                        <div class="flex flex-col justify-around flex-1 min-w-[160px]">
                            <div class="text-xs font-medium text-slate-500 uppercase tracking-wide mb-3 text-center">Round of 16</div>
                            ${generateMatches(0, 16, 1, 'r16')}
                        </div>
                        <div class="flex flex-col justify-around flex-1 min-w-[160px]">
                            <div class="text-xs font-medium text-slate-500 uppercase tracking-wide mb-3 text-center">Quarter</div>
                            ${generateEmptyMatches(4, 2, 'qf')}
                        </div>
                        <div class="flex flex-col justify-around flex-1 min-w-[160px]">
                            <div class="text-xs font-medium text-slate-500 uppercase tracking-wide mb-3 text-center">Semi</div>
                            ${generateEmptyMatches(2, 3, 'sf')}
                        </div>
                        <div class="flex flex-col items-center justify-center flex-1 min-w-[160px]">
                            <div class="text-xs font-medium text-slate-500 uppercase tracking-wide mb-3 text-center">Final</div>
                            <div class="text-4xl mb-4">🏆</div>
                            <div class="flex flex-col gap-2" data-match="final">
                                <div class="player-card empty px-3 py-2 rounded min-w-[140px] text-sm font-medium text-center border-2 border-transparent" data-slot="final-1">Finalist 1</div>
                                <div class="player-card empty px-3 py-2 rounded min-w-[140px] text-sm font-medium text-center border-2 border-transparent" data-slot="final-2">Finalist 2</div>
                            </div>
                            <div class="hidden mt-6 px-6 py-3 rounded-lg text-lg font-bold bg-amber-400 text-zinc-900" id="champion">Champion</div>
                        </div>
                    </div>
                `;
                document.getElementById('bracketContent').innerHTML = bracketHTML;
            }

            function generate32Bracket() {
                const bracketHTML = `
                    <div class="flex gap-6 min-w-fit py-4">
                        <div class="flex flex-col justify-around flex-1 min-w-[160px]">
                            <div class="text-xs font-medium text-slate-500 uppercase tracking-wide mb-3 text-center">Round of 32</div>
                            ${generateMatches(16, 1, 'r32')}
                        </div>
                        <div class="flex flex-col justify-around flex-1 min-w-[160px]">
                            <div class="text-xs font-medium text-slate-500 uppercase tracking-wide mb-3 text-center">Round of 16</div>
                            ${generateEmptyMatches(8, 2, 'r16')}
                        </div>
                        <div class="flex flex-col justify-around flex-1 min-w-[160px]">
                            <div class="text-xs font-medium text-slate-500 uppercase tracking-wide mb-3 text-center">Quarter</div>
                            ${generateEmptyMatches(4, 3, 'qf')}
                        </div>
                        <div class="flex flex-col justify-around flex-1 min-w-[160px]">
                            <div class="text-xs font-medium text-slate-500 uppercase tracking-wide mb-3 text-center">Semi</div>
                            ${generateEmptyMatches(2, 4, 'sf')}
                        </div>
                        <div class="flex flex-col items-center justify-center flex-1 min-w-[160px]">
                            <div class="text-xs font-medium text-slate-500 uppercase tracking-wide mb-3 text-center">Final</div>
                            <div class="text-4xl mb-4">🏆</div>
                            <div class="flex flex-col gap-2" data-match="final">
                                <div class="player-card empty px-3 py-2 rounded min-w-[140px] text-sm font-medium text-center border-2 border-transparent" data-slot="final-1">Finalist 1</div>
                                <div class="player-card empty px-3 py-2 rounded min-w-[140px] text-sm font-medium text-center border-2 border-transparent" data-slot="final-2">Finalist 2</div>
                            </div>
                            <div class="hidden mt-6 px-6 py-3 rounded-lg text-lg font-bold bg-amber-400 text-zinc-900" id="champion">Champion</div>
                        </div>
                    </div>
                `;
                document.getElementById('bracketContent').innerHTML = bracketHTML;
            }

            function generateMatches(start, end, round, prefix) {
                let html = '';
                let matchIndex = 0;

                for (let i = start; i < end; i += 2) {
                    const player1 = shuffledPeserta[i];
                    const player2 = shuffledPeserta[i + 1];
                    const matchId = `${prefix}-m${matchIndex}`;

                    html += `
                        <div class="flex flex-col gap-1 my-2" data-match="${matchId}">
                            <div class="player-card ${player1.empty ? 'empty' : 'bg-zinc-700 hover:bg-zinc-600'} px-3 py-2 rounded min-w-[140px] text-sm font-medium text-center text-white border-2 border-transparent cursor-pointer transition-colors"
                                 data-slot="${matchId}-1"
                                 data-player-index="${i}"
                                 data-player-id="${player1.id || ''}"
                                 onclick="${player1.empty ? '' : `selectWinner('${matchId}', 1, ${i})`}">
                                ${player1.nama_peserta}
                            </div>
                            <div class="player-card ${player2.empty ? 'empty' : 'bg-zinc-700 hover:bg-zinc-600'} px-3 py-2 rounded min-w-[140px] text-sm font-medium text-center text-white border-2 border-transparent cursor-pointer transition-colors"
                                 data-slot="${matchId}-2"
                                 data-player-index="${i + 1}"
                                 data-player-id="${player2.id || ''}"
                                 onclick="${player2.empty ? '' : `selectWinner('${matchId}', 2, ${i + 1})`}">
                                ${player2.nama_peserta}
                            </div>
                        </div>
                    `;
                    matchIndex++;
                }
                return html;
            }

            function generateEmptyMatches(count, round, prefix) {
                let html = '';
                for (let i = 0; i < count; i++) {
                    const matchId = `${prefix}-m${i}`;
                    html += `
                        <div class="flex flex-col gap-1 my-2" data-match="${matchId}">
                            <div class="player-card empty px-3 py-2 rounded min-w-[140px] text-sm font-medium text-center border-2 border-transparent" data-slot="${matchId}-1">TBD</div>
                            <div class="player-card empty px-3 py-2 rounded min-w-[140px] text-sm font-medium text-center border-2 border-transparent" data-slot="${matchId}-2">TBD</div>
                        </div>
                    `;
                }
                return html;
            }

            function selectWinner(matchId, slot, playerIndex) {
                const player = shuffledPeserta[playerIndex];

                if (player.empty) return;

                const matchElement = document.querySelector(`[data-match="${matchId}"]`);
                const player1Element = matchElement.querySelector(`[data-slot="${matchId}-1"]`);
                const player2Element = matchElement.querySelector(`[data-slot="${matchId}-2"]`);

                player1Element.classList.remove('winner');
                player2Element.classList.remove('winner');

                const winnerElement = slot === 1 ? player1Element : player2Element;
                winnerElement.classList.add('winner');

                advanceWinner(matchId, player, playerIndex);
            }

            function selectWinnerNext(matchId, slot) {
                const matchElement = document.querySelector(`[data-match="${matchId}"]`);
                const slotElement = matchElement.querySelector(`[data-slot="${matchId}-${slot}"]`);

                if (slotElement.classList.contains('empty')) {
                    alert('Pemain belum ditentukan untuk slot ini!');
                    return;
                }

                const player1Element = matchElement.querySelector(`[data-slot="${matchId}-1"]`);
                const player2Element = matchElement.querySelector(`[data-slot="${matchId}-2"]`);
                player1Element.classList.remove('winner');
                player2Element.classList.remove('winner');

                slotElement.classList.add('winner');

                const playerName = slotElement.textContent.trim();
                const playerIndex = slotElement.getAttribute('data-player-index');
                const playerId = slotElement.getAttribute('data-player-id');

                if (playerIndex) {
                    const player = shuffledPeserta[parseInt(playerIndex)];
                    advanceWinner(matchId, player, parseInt(playerIndex));
                } else {
                    const player = {
                        id: playerId,
                        nama_peserta: playerName
                    };
                    advanceWinner(matchId, player, null);
                }
            }

            function advanceWinner(matchId, player, playerIndex) {
                let nextMatchId, nextSlot;

                if (matchId.startsWith('r16-m')) {
                    const matchNum = parseInt(matchId.split('m')[1]);
                    nextMatchId = `qf-m${Math.floor(matchNum / 2)}`;
                    nextSlot = (matchNum % 2) + 1;
                } else if (matchId.startsWith('qf-m')) {
                    const matchNum = parseInt(matchId.split('m')[1]);
                    nextMatchId = `sf-m${Math.floor(matchNum / 2)}`;
                    nextSlot = (matchNum % 2) + 1;
                } else if (matchId.startsWith('sf-m')) {
                    const matchNum = parseInt(matchId.split('m')[1]);

                    const matchElement = document.querySelector(`[data-match="${matchId}"]`);
                    const player1Element = matchElement.querySelector(`[data-slot="${matchId}-1"]`);
                    const player2Element = matchElement.querySelector(`[data-slot="${matchId}-2"]`);

                    const loserElement = player1Element.classList.contains('winner') ? player2Element : player1Element;
                    const loserName = loserElement.textContent.trim();
                    const loserId = loserElement.getAttribute('data-player-id');

                    if (loserName !== 'TBD' && !semifinalLosers.some(l => l.id === loserId)) {
                        semifinalLosers.push({
                            id: loserId,
                            nama_peserta: loserName,
                            index: loserElement.getAttribute('data-player-index')
                        });

                        updateThirdPlaceMatch();
                    }

                    nextMatchId = 'final';
                    nextSlot = matchNum + 1;
                } else if (matchId.startsWith('r32-m')) {
                    const matchNum = parseInt(matchId.split('m')[1]);
                    nextMatchId = `r16-m${Math.floor(matchNum / 2)}`;
                    nextSlot = (matchNum % 2) + 1;
                }

                if (nextMatchId) {
                    const nextSlotElement = document.querySelector(`[data-slot="${nextMatchId}-${nextSlot}"]`);
                    if (nextSlotElement) {
                        nextSlotElement.textContent = player.nama_peserta;
                        nextSlotElement.classList.remove('empty');
                        nextSlotElement.setAttribute('data-player-index', playerIndex !== null ? playerIndex : '');
                        nextSlotElement.setAttribute('data-player-id', player.id || '');

                        nextSlotElement.onclick = function () {
                            selectWinnerNext(nextMatchId, nextSlot);
                        };

                        if (nextMatchId === 'final') {
                            const finalMatch = document.querySelector(`[data-match="final"]`);
                            const finalist1 = finalMatch.querySelector(`[data-slot="final-1"]`);
                            const finalist2 = finalMatch.querySelector(`[data-slot="final-2"]`);

                            if (!finalist1.classList.contains('empty') && !finalist2.classList.contains('empty')) {
                                finalist1.onclick = function () {
                                    selectFinalWinner(1);
                                };
                                finalist2.onclick = function () {
                                    selectFinalWinner(2);
                                };
                            }
                        }
                    }
                }
            }

            function selectFinalWinner(slot) {
                const finalMatch = document.querySelector(`[data-match="final"]`);
                const finalist1 = finalMatch.querySelector(`[data-slot="final-1"]`);
                const finalist2 = finalMatch.querySelector(`[data-slot="final-2"]`);

                if (finalist1.classList.contains('empty') || finalist2.classList.contains('empty')) {
                    alert('Kedua finalist harus sudah ditentukan!');
                    return;
                }

                finalist1.classList.remove('winner');
                finalist2.classList.remove('winner');

                const winnerElement = slot === 1 ? finalist1 : finalist2;
                winnerElement.classList.add('winner');

                const championName = winnerElement.textContent.trim();
                declareChampion(championName);
            }

            function updateThirdPlaceMatch() {
                if (semifinalLosers.length === 2) {
                    const thirdPlaceMatch = document.getElementById('thirdPlaceMatch');
                    thirdPlaceMatch.innerHTML = `
                        <div class="flex flex-col gap-2" data-match="third-place">
                            <div class="player-card bg-zinc-700 hover:bg-zinc-600 px-3 py-2 rounded min-w-[140px] text-sm font-medium text-center text-white border-2 border-transparent cursor-pointer transition-colors mx-auto"
                                 data-slot="third-1"
                                 data-player-id="${semifinalLosers[0].id}"
                                 data-player-index="${semifinalLosers[0].index}"
                                 onclick="selectThirdPlace(0)">
                                ${semifinalLosers[0].nama_peserta}
                            </div>
                            <div class="player-card bg-zinc-700 hover:bg-zinc-600 px-3 py-2 rounded min-w-[140px] text-sm font-medium text-center text-white border-2 border-transparent cursor-pointer transition-colors mx-auto"
                                 data-slot="third-2"
                                 data-player-id="${semifinalLosers[1].id}"
                                 data-player-index="${semifinalLosers[1].index}"
                                 onclick="selectThirdPlace(1)">
                                ${semifinalLosers[1].nama_peserta}
                            </div>
                        </div>
                    `;

                    console.log('Third place match updated:', semifinalLosers);
                }
            }

            function selectThirdPlace(index) {
                const matchElement = document.querySelector(`[data-match="third-place"]`);
                if (!matchElement) {
                    alert('Match element tidak ditemukan!');
                    return;
                }

                const player1Element = matchElement.querySelector(`[data-slot="third-1"]`);
                const player2Element = matchElement.querySelector(`[data-slot="third-2"]`);

                if (!player1Element || !player2Element) {
                    alert('Player elements tidak ditemukan!');
                    return;
                }

                player1Element.classList.remove('winner');
                player2Element.classList.remove('winner');

                const winnerElement = index === 0 ? player1Element : player2Element;
                const loserElement = index === 0 ? player2Element : player1Element;
                winnerElement.classList.add('winner');

                const thirdPlaceWinner = semifinalLosers[index];
                const thirdPlaceLoser = semifinalLosers[index === 0 ? 1 : 0];

                // Save to database
                saveMatchResult('third-place', thirdPlaceWinner.id, thirdPlaceLoser.id);

                setTimeout(() => {
                    alert('🥉 Juara 3: ' + thirdPlaceWinner.nama_peserta + '\n\nSelamat atas pencapaian luar biasa!');
                }, 300);
            }

            function saveMatchResult(matchId, winnerId, loserId) {
                if (!winnerId || !loserId) {
                    console.log('Skipping save - missing IDs:', { matchId, winnerId, loserId });
                    return;
                }

                const formData = new FormData();
                formData.append('save_match_result', '1');
                formData.append('match_id', matchId);
                formData.append('winner_id', winnerId);
                formData.append('loser_id', loserId);
                formData.append('kegiatan_id', <?= $kegiatan_id ?>);
                formData.append('category_id', <?= $category_id ?>);
                formData.append('scoreboard_id', <?= $scoreboard_id ?>);
                formData.append('bracket_size', selectedSize);

                fetch('', {
                    method: 'POST',
                    body: formData
                })
                    .then(response => response.json())
                    .then(data => {
                        console.log('Match result saved:', data);
                    })
                    .catch(error => {
                        console.error('Error saving match result:', error);
                    });
            }

            function saveChampion(championId, runnerUpId, thirdPlaceId) {
                const formData = new FormData();
                formData.append('save_champion', '1');
                formData.append('champion_id', championId);
                formData.append('runner_up_id', runnerUpId);
                if (thirdPlaceId) {
                    formData.append('third_place_id', thirdPlaceId);
                }
                formData.append('kegiatan_id', <?= $kegiatan_id ?>);
                formData.append('category_id', <?= $category_id ?>);
                formData.append('scoreboard_id', <?= $scoreboard_id ?>);
                formData.append('bracket_size', selectedSize);

                fetch('', {
                    method: 'POST',
                    body: formData
                })
                    .then(response => response.json())
                    .then(data => {
                        console.log('Champion saved:', data);
                    })
                    .catch(error => {
                        console.error('Error saving champion:', error);
                    });
            }

            function declareChampion(championName) {
                const championElement = document.getElementById('champion');
                championElement.textContent = '🏆 ' + championName + ' 🏆';
                championElement.classList.remove('hidden');

                // Get champion and runner-up IDs
                const finalMatch = document.querySelector(`[data-match="final"]`);
                const finalist1 = finalMatch.querySelector(`[data-slot="final-1"]`);
                const finalist2 = finalMatch.querySelector(`[data-slot="final-2"]`);

                const championId = finalist1.classList.contains('winner') ?
                    finalist1.getAttribute('data-player-id') :
                    finalist2.getAttribute('data-player-id');

                const runnerUpId = finalist1.classList.contains('winner') ?
                    finalist2.getAttribute('data-player-id') :
                    finalist1.getAttribute('data-player-id');

                // Get third place if exists
                let thirdPlaceId = null;
                const thirdPlaceMatch = document.querySelector(`[data-match="third-place"]`);
                if (thirdPlaceMatch) {
                    const thirdWinner = thirdPlaceMatch.querySelector('.player-card.winner');
                    if (thirdWinner) {
                        thirdPlaceId = thirdWinner.getAttribute('data-player-id');
                    }
                }

                // Save to database
                saveMatchResult('final', championId, runnerUpId);
                saveChampion(championId, runnerUpId, thirdPlaceId);

                setTimeout(() => {
                    let message = '🎉 Selamat kepada juara: ' + championName + '! 🎉';
                    if (thirdPlaceId) {
                        const thirdPlaceName = semifinalLosers.find(p => p.id == thirdPlaceId)?.nama_peserta;
                        if (thirdPlaceName) {
                            const runnerUpName = finalist1.classList.contains('winner') ?
                                finalist2.textContent.trim() :
                                finalist1.textContent.trim();
                            message += '\n\n🥈 Juara 2: ' + runnerUpName;
                            message += '\n🥉 Juara 3: ' + thirdPlaceName;
                        }
                    }
                    alert(message);
                }, 500);
            }

            // Mobile menu functionality
            const mobileMenuBtn = document.getElementById('mobile-menu-btn');
            const mobileOverlay = document.getElementById('mobile-overlay');
            const mobileSidebar = document.getElementById('mobile-sidebar');
            const closeMobileMenu = document.getElementById('close-mobile-menu');

            function openMobileMenu() {
                mobileOverlay.classList.remove('hidden');
                mobileSidebar.classList.remove('-translate-x-full');
                document.body.style.overflow = 'hidden';
            }

            function closeMobileMenuFn() {
                mobileOverlay.classList.add('hidden');
                mobileSidebar.classList.add('-translate-x-full');
                document.body.style.overflow = '';
            }

            if (mobileMenuBtn) mobileMenuBtn.addEventListener('click', openMobileMenu);
            if (mobileOverlay) mobileOverlay.addEventListener('click', closeMobileMenuFn);
            if (closeMobileMenu) closeMobileMenu.addEventListener('click', closeMobileMenuFn);

            // Theme Toggle
            <?= getThemeToggleScript() ?>
        </script>
    </body>

    </html>
    <?php
    exit;
}


// ============================================
// HANDLER UNTUK SCORECARD SETUP
// ============================================
if (isset($_GET['action']) && $_GET['action'] == 'scorecard') {
    // Allow public access for ranking view, require auth for everything else
    if (!$isPublicRankingView && !canInputScore()) {
        header("Location: detail.php?id=" . intval($_GET['kegiatan_id'] ?? 0));
        exit;
    }

    // Only verify CSRF for authenticated users (not guests viewing rankings)
    if (!$isGuest) {
        verify_csrf();
    }
    $_POST = cleanInput($_POST);

    $kegiatan_id = isset($_GET['kegiatan_id']) ? intval($_GET['kegiatan_id']) : null;
    $category_id = isset($_GET['category_id']) ? intval($_GET['category_id']) : null;

    if (!$kegiatan_id || !$category_id) {
        die("Parameter kegiatan_id dan category_id harus diisi.");
    }
    // ... (rest of the logic remains valid as I've already refactored some parts above)
    // Wait, I need to be careful with the lines I'm replacing.

    // Handler untuk get scores via AJAX
    if (isset($_GET['action']) && $_GET['action'] == 'get_scores') {
        header('Content-Type: application/json');

        $peserta_id = isset($_GET['peserta_id']) ? intval($_GET['peserta_id']) : 0;
        $kegiatan_id = isset($_GET['kegiatan_id']) ? intval($_GET['kegiatan_id']) : 0;
        $category_id = isset($_GET['category_id']) ? intval($_GET['category_id']) : 0;
        $scoreboard_id = isset($_GET['scoreboard']) ? intval($_GET['scoreboard']) : 0;

        $scores = [];

        try {
            $queryScores = "SELECT peserta_id, arrow, session, score 
                        FROM score 
                        WHERE kegiatan_id = ? 
                        AND category_id = ? 
                        AND score_board_id = ? 
                        AND peserta_id = ?
                        ORDER BY session ASC, arrow ASC";

            $stmtScores = $conn->prepare($queryScores);
            $stmtScores->bind_param("iiii", $kegiatan_id, $category_id, $scoreboard_id, $peserta_id);
            $stmtScores->execute();
            $resultScores = $stmtScores->get_result();

            while ($row = $resultScores->fetch_assoc()) {
                $scores[] = $row;
            }

            $stmtScores->close();

            echo json_encode($scores);
        } catch (Exception $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }

        $conn->close();
        exit;
    }

    $stmtSb = $conn->prepare("SELECT * FROM score_boards WHERE kegiatan_id=? AND category_id=? ORDER BY created DESC");
    $stmtSb->bind_param("ii", $kegiatan_id, $category_id);
    $stmtSb->execute();
    $mysql_table_score_board = $stmtSb->get_result();
    $stmtSb->close();

    if (isset($_GET['scoreboard'])) {
        $sb_id = intval($_GET['scoreboard']);
        $stmtDs = $conn->prepare("SELECT * FROM score WHERE kegiatan_id=? AND category_id=? AND score_board_id=? ");
        $stmtDs->bind_param("iii", $kegiatan_id, $category_id, $sb_id);
        $stmtDs->execute();
        $mysql_data_score = $stmtDs->get_result();
        $stmtDs->close();
    }

    // Ambil data kegiatan
    $kegiatanData = [];
    try {
        $queryKegiatan = "SELECT id, nama_kegiatan FROM kegiatan WHERE id = ?";
        $stmtKegiatan = $conn->prepare($queryKegiatan);
        $stmtKegiatan->bind_param("i", $kegiatan_id);
        $stmtKegiatan->execute();
        $resultKegiatan = $stmtKegiatan->get_result();

        if ($resultKegiatan->num_rows > 0) {
            $kegiatanData = $resultKegiatan->fetch_assoc();
        } else {
            die("Kegiatan tidak ditemukan.");
        }
        $stmtKegiatan->close();
    } catch (Exception $e) {
        die("Error mengambil data kegiatan: " . $e->getMessage());
    }

    // Ambil data kategori
    $kategoriData = [];
    try {
        $queryKategori = "SELECT id, name FROM categories WHERE id = ?";
        $stmtKategori = $conn->prepare($queryKategori);
        $stmtKategori->bind_param("i", $category_id);
        $stmtKategori->execute();
        $resultKategori = $stmtKategori->get_result();

        if ($resultKategori->num_rows > 0) {
            $kategoriData = $resultKategori->fetch_assoc();
        } else {
            die("Kategori tidak ditemukan.");
        }
        $stmtKategori->close();
    } catch (Exception $e) {
        die("Error mengambil data kategori: " . $e->getMessage());
    }

    // Ambil data peserta berdasarkan kegiatan dan kategori
    $pesertaList = [];
    $peserta_score = [];
    try {
        $queryPeserta = "
            SELECT 
                MAX(p.id) as id,
                p.nama_peserta,
                p.jenis_kelamin,
                MAX(c.name) as category_name
            FROM peserta p
            LEFT JOIN categories c ON p.category_id = c.id
            WHERE p.category_id = ? AND p.nama_peserta IN (SELECT nama_peserta FROM peserta WHERE kegiatan_id = ?)
            GROUP BY p.nama_peserta, p.jenis_kelamin
            ORDER BY p.nama_peserta ASC
        ";
        $stmtPeserta = $conn->prepare($queryPeserta);
        $stmtPeserta->bind_param("ii", $category_id, $kegiatan_id);
        $stmtPeserta->execute();
        $resultPeserta = $stmtPeserta->get_result();

        $nameToRepId = [];
        while ($row = $resultPeserta->fetch_assoc()) {
            $pesertaList[] = $row;
            $nameToRepId[$row['nama_peserta']] = $row['id'];
        }


        if (isset($_GET['scoreboard'])) {
            $stmtTotal = $conn->prepare("SELECT s.* FROM score s JOIN peserta p ON s.peserta_id = p.id WHERE s.kegiatan_id=? AND s.category_id=? AND s.score_board_id =? AND p.nama_peserta = ?");
            foreach ($pesertaList as $a) {
                $sb_id = intval($_GET['scoreboard']);
                $stmtTotal->bind_param("iiis", $kegiatan_id, $category_id, $sb_id, $a['nama_peserta']);
                $stmtTotal->execute();
                $mysql_score_total = $stmtTotal->get_result();
                $score = 0;
                $x_score = 0;
                while ($b = $mysql_score_total->fetch_assoc()) {
                    if ($b['score'] == 'm') {
                        $score = $score + 0;
                    } else if ($b['score'] == 'x') {
                        $score = $score + 10;
                        $x_score = $x_score + 1;
                    } else {
                        $score = $score + (int) $b['score'];
                    }
                }
                $peserta_score[] = ['id' => $a['id'], 'total_score' => $score, 'total_x' => $x_score];
            }
            $stmtTotal->close();
        }

            // Fetch all individual scores for detailed view (ranking mode)
            if (isset($_GET['rangking'])) {
                $allScores = [];
                $queryAllScores = "SELECT s.peserta_id, s.arrow, s.session, s.score, p.nama_peserta
                                   FROM score s
                                   JOIN peserta p ON s.peserta_id = p.id
                                   WHERE s.kegiatan_id = ? AND s.category_id = ? AND s.score_board_id = ?
                                   ORDER BY p.nama_peserta, s.session, s.arrow";
                $stmtAllScores = $conn->prepare($queryAllScores);
                $stmtAllScores->bind_param("iii", $kegiatan_id, $category_id, $_GET['scoreboard']);
                $stmtAllScores->execute();
                $resultAllScores = $stmtAllScores->get_result();
                while ($row = $resultAllScores->fetch_assoc()) {
                    $pid = $nameToRepId[$row['nama_peserta']] ?? $row['peserta_id'];
                    if (!isset($allScores[$pid])) {
                        $allScores[$pid] = [];
                    }
                    $allScores[$pid][] = [
                        'arrow' => $row['arrow'],
                        'session' => $row['session'],
                        'score' => $row['score']
                    ];
                }
                $stmtAllScores->close();
            }

        $stmtPeserta->close();
    } catch (Exception $e) {
        die("Error mengambil data peserta: " . $e->getMessage());
    }

    if (isset($_POST['create'])) {
        // Izinkan admin, operator, dan petugas untuk membuat scorecard
        $allowedRoles = ['admin', 'operator', 'petugas'];
        if (!in_array($_SESSION['role'], $allowedRoles)) {
            enforceAdmin(); // Fallback to enforceAdmin which will handle the redirect
        }
        
        if (!checkRateLimit('create_scoreboard', 10, 60)) {
            die('Terlalu banyak permintaan.');
        }
        verify_csrf();
        $_POST = cleanInput($_POST);
        security_log("New score board created for activity $kegiatan_id, category $category_id");
        $stmtC = $conn->prepare("INSERT INTO `score_boards` (`kegiatan_id`, `category_id`, `jumlah_sesi`, `jumlah_anak_panah`, `created`) VALUES (?, ?, ?, ?, ?)");
        $stmtC->bind_param("iiiis", $kegiatan_id, $category_id, $_POST['jumlahSesi'], $_POST['jumlahPanah'], $_POST['local_time']);
        $stmtC->execute();
        $stmtC->close();
        header("Location: detail.php?action=scorecard&resource=index&kegiatan_id=" . $kegiatan_id . "&category_id=" . $category_id);
    }

    if (isset($_POST['save_score'])) {
        header("Content-Type: application/json; charset=UTF-8");
        
        if (!checkRateLimit('save_score', 120, 60)) {
            echo json_encode(['status' => 'error', 'message' => 'Rate limit exceeded']);
            exit;
        }
        // CSRF Verification for AJAX
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
             echo json_encode(['status' => 'error', 'message' => 'CSRF Invalid']);
             exit;
        }
        $_POST = cleanInput($_POST);

        $sb_id = intval($_GET['scoreboard'] ?? 0);
        $peserta_id = intval($_POST['peserta_id'] ?? 0);
        $arrow = intval($_POST['arrow'] ?? 0);
        $session = intval($_POST['session'] ?? 0);
        $score_val = $_POST['score'] ?? '';

        $stmtCheck = $conn->prepare("SELECT * FROM score WHERE kegiatan_id=? AND category_id=? AND score_board_id=? AND peserta_id=? AND arrow=? AND session=?");
        $stmtCheck->bind_param("iiiiii", $kegiatan_id, $category_id, $sb_id, $peserta_id, $arrow, $session);
        $stmtCheck->execute();
        $fetch_checkScore = $stmtCheck->get_result()->fetch_assoc();
        $stmtCheck->close();

        if ($fetch_checkScore) {
            $message = "Score updated";
            if (empty($score_val)) {
                $stmtOp = $conn->prepare("DELETE FROM score WHERE id=?");
                $stmtOp->bind_param("i", $fetch_checkScore['id']);
            } else {
                $stmtOp = $conn->prepare("UPDATE score SET score=? WHERE id=?");
                $stmtOp->bind_param("si", $score_val, $fetch_checkScore['id']);
            }
        } else {
            if (!empty($score_val)) {
                $stmtOp = $conn->prepare("INSERT INTO `score` (`kegiatan_id`, `category_id`, `score_board_id`, `peserta_id`, `arrow`, `session`, `score`) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmtOp->bind_param("iiiiiis", $kegiatan_id, $category_id, $sb_id, $peserta_id, $arrow, $session, $score_val);
                $message = "Score added";
            } else {
                $message = "Empty score - no action";
            }
        }
        
        if (isset($stmtOp)) {
            $stmtOp->execute();
            $stmtOp->close();
        }

        echo json_encode(["status" => "success", "message" => $message]);
        exit;
    }

    if (isset($_GET['delete_score_board'])) {
        // Izinkan admin, operator, dan petugas untuk menghapus scorecard
        $allowedRoles = ['admin', 'operator', 'petugas'];
        if (!in_array($_SESSION['role'], $allowedRoles)) {
            enforceAdmin(); 
        }
        $ds_id = intval($_GET['delete_score_board']);
        
        // Backup before deletion (Recover Mode)
        backup_deleted_record($conn, 'score_boards', $ds_id);
        
        // Hapus score terkait dulu (mencegah data ghoib)
        $stmtDelScores = $conn->prepare("DELETE FROM `score` WHERE `score_board_id` = ?");
        $stmtDelScores->bind_param("i", $ds_id);
        $stmtDelScores->execute();
        $stmtDelScores->close();

        $stmtDel = $conn->prepare("DELETE FROM `score_boards` WHERE `id` = ?");
        security_log("Score board $ds_id deleted", 'WARNING');
        $stmtDel->bind_param("i", $ds_id);
        $stmtDel->execute();
        $stmtDel->close();
        header("Location: detail.php?action=scorecard&resource=index&kegiatan_id=" . $kegiatan_id . "&category_id=" . $category_id);
    }

    if (isset($_GET['scoreboard'])) {
        $sb_id = intval($_GET['scoreboard']);
        $stmtShow = $conn->prepare("SELECT * FROM `score_boards` WHERE `id` = ?");
        $stmtShow->bind_param("i", $sb_id);
        $stmtShow->execute();
        $show_score_board = $stmtShow->get_result()->fetch_assoc();
        $stmtShow->close();
    }

    $conn->close();

    // BAGIAN SCORECARD SETUP
    ?>
    <!DOCTYPE html>
    <html lang="id" class="h-full">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Setup Scorecard Panahan - <?= htmlspecialchars($kategoriData['name']) ?></title>
        <script src="https://cdn.tailwindcss.com"></script>
        <script><?= getThemeTailwindConfig() ?></script>
        <script><?= getThemeInitScript() ?></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <style>
            .custom-scrollbar::-webkit-scrollbar { width: 6px; height: 6px; }
            .custom-scrollbar::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 3px; }
            .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
            .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
            .dark .custom-scrollbar::-webkit-scrollbar-track { background: #27272a; }
            .dark .custom-scrollbar::-webkit-scrollbar-thumb { background: #52525b; }
            .dark .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #71717a; }
            .dropdown-menu { display: none; }
            #dropdownMenu.show {
                display: block !important;
                opacity: 1 !important;
                scale: 100% !important;
                pointer-events: auto !important;
                transform: translateY(0) !important;
            }
            #dropdownMenu {
                transform: translateY(-10px);
                transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
            }
            /* Removed .hidden override - conflicts with Tailwind responsive classes */

            /* Score input styling */
            .arrow-input { transition: all 0.2s ease; }
            .arrow-input:focus { outline: none; box-shadow: 0 0 0 2px rgba(22, 163, 74, 0.3); }
            .arrow-input.score-x { background: #dcfce7 !important; border-color: #16a34a !important; color: #15803d !important; }
            .arrow-input.score-m { background: #fee2e2 !important; border-color: #dc2626 !important; color: #dc2626 !important; }
            .arrow-input.score-10 { background: #dcfce7 !important; border-color: #16a34a !important; color: #15803d !important; }
            .arrow-input.score-high { background: #fef3c7 !important; border-color: #f59e0b !important; color: #92400e !important; }
            .arrow-input.saving { border-color: #f59e0b !important; opacity: 0.7; }
            .arrow-input.saved { border-color: #16a34a !important; }
            .arrow-input.error { border-color: #dc2626 !important; }

            @media print {
                .no-print { display: none !important; }
            }

            /* Mobile Score Keyboard - Floating Style */
            .mobile-score-keyboard {
                position: fixed;
                bottom: 1rem;
                left: 0.75rem;
                right: 0.75rem;
                z-index: 9999;
                transform: translateY(calc(100% + 2rem));
                transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.3s ease;
                opacity: 0;
                pointer-events: none;
            }
            .mobile-score-keyboard.active {
                transform: translateY(0);
                opacity: 1;
                pointer-events: auto;
            }
            .mobile-score-keyboard .keyboard-inner {
                background: rgba(24, 24, 27, 0.95);
                backdrop-filter: blur(12px);
                -webkit-backdrop-filter: blur(12px);
                border-radius: 1rem;
                border: 1px solid rgba(63, 63, 70, 0.5);
                box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5), 0 0 0 1px rgba(255, 255, 255, 0.05);
                overflow: hidden;
            }
            .mobile-score-keyboard .keyboard-btn {
                min-width: 2.5rem;
                height: 2.75rem;
                font-weight: 600;
                border-radius: 0.5rem;
                transition: all 0.15s ease;
                user-select: none;
                -webkit-tap-highlight-color: transparent;
            }
            .mobile-score-keyboard .keyboard-btn:active {
                transform: scale(0.92);
                opacity: 0.8;
            }
            .keyboard-btn.score-x { background: #16a34a; color: white; }
            .keyboard-btn.score-10 { background: #22c55e; color: white; }
            .keyboard-btn.score-high { background: #f59e0b; color: white; }
            .keyboard-btn.score-mid { background: #64748b; color: white; }
            .keyboard-btn.score-low { background: #94a3b8; color: white; }
            .keyboard-btn.score-m { background: #dc2626; color: white; }
            .keyboard-btn.action-save { background: #16a34a; color: white; }
            .keyboard-btn.action-close { background: #475569; color: white; }
            .keyboard-btn.action-clear { background: #ef4444; color: white; }

            /* Add bottom padding when keyboard is active on mobile */
            body.keyboard-active {
                padding-bottom: 200px;
            }

            /* Hide on desktop/tablet */
            @media (min-width: 768px) {
                .mobile-score-keyboard { display: none !important; }
                body.keyboard-active { padding-bottom: 0; }
            }
        </style>
    </head>
    <body class="h-full bg-slate-50 dark:bg-zinc-950 transition-colors">
        <div class="flex h-full">
            <?php if (!$isGuest): ?>
            <!-- Sidebar -->
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
                        <i class="fas fa-home w-5"></i><span class="text-sm">Dashboard</span>
                    </a>
                    <div class="pt-4">
                        <p class="px-4 text-xs font-semibold text-zinc-500 uppercase tracking-wider mb-2">Master Data</p>
                        <a href="users.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800 transition-colors">
                            <i class="fas fa-users w-5"></i><span class="text-sm">Users</span>
                        </a>
                        <a href="categori.view.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800 transition-colors">
                            <i class="fas fa-tags w-5"></i><span class="text-sm">Kategori</span>
                        </a>
                    </div>
                    <div class="pt-4">
                        <p class="px-4 text-xs font-semibold text-zinc-500 uppercase tracking-wider mb-2">Tournament</p>
                        <a href="kegiatan.view.php" class="flex items-center gap-3 px-4 py-3 rounded-lg bg-archery-600/20 text-archery-400 border border-archery-600/30 transition-colors">
                            <i class="fas fa-calendar w-5"></i><span class="text-sm font-medium">Kegiatan</span>
                        </a>
                        <a href="peserta.view.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800 transition-colors">
                            <i class="fas fa-user-friends w-5"></i><span class="text-sm">Peserta</span>
                        </a>
                        <a href="statistik.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800 transition-colors">
                            <i class="fas fa-chart-bar w-5"></i><span class="text-sm">Statistik</span>
                        </a>
                    </div>

                    <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                    <div class="pt-4">
                        <p class="px-4 text-xs font-semibold text-zinc-500 uppercase tracking-wider mb-2">System</p>
                        <a href="recovery.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800 transition-colors">
                            <i class="fas fa-trash-restore w-5"></i>
                            <span class="text-sm">Data Recovery</span>
                        </a>
                    </div>
                    <?php endif; ?>
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
                        <i class="fas fa-sign-out-alt w-5"></i><span>Logout</span>
                    </a>
                </div>
            </aside>

            <!-- Mobile Menu Button -->
            <button id="mobile-menu-btn" class="lg:hidden fixed top-4 left-4 z-50 p-2 rounded-lg bg-zinc-900 text-white shadow-lg">
                <i class="fas fa-bars"></i>
            </button>
            <?php endif; ?>

            <!-- Main Content -->
            <main class="flex-1 overflow-auto">
                <?php if ($isGuest): ?>
                <!-- Guest Header for Public Ranking -->
                <div class="bg-zinc-900 text-white px-4 py-3 flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-lg bg-archery-600 flex items-center justify-center">
                            <i class="fas fa-bullseye text-white text-sm"></i>
                        </div>
                        <div>
                            <h1 class="font-semibold text-sm">Turnamen Panahan</h1>
                            <p class="text-xs text-zinc-400">Live Ranking</p>
                        </div>
                    </div>
                    <?= getThemeToggleButton() ?>
                </div>
                <?php endif; ?>
                <div class="px-4 sm:px-6 lg:px-8 py-4 sm:py-6">
                    <?php if (!$isGuest): ?>
                    <!-- Breadcrumb -->
                    <nav class="flex items-center gap-2 text-sm text-slate-500 dark:text-zinc-400 mb-4 no-print">
                        <a href="dashboard.php" class="hover:text-archery-600 transition-colors">Dashboard</a>
                        <i class="fas fa-chevron-right text-xs text-slate-300 dark:text-zinc-600"></i>
                        <a href="kegiatan.view.php" class="hover:text-archery-600 transition-colors">Kegiatan</a>
                        <i class="fas fa-chevron-right text-xs text-slate-300 dark:text-zinc-600"></i>
                        <a href="detail.php?kegiatan_id=<?= $kegiatan_id ?>" class="hover:text-archery-600 transition-colors"><?= htmlspecialchars($kegiatanData['nama_kegiatan']) ?></a>
                        <i class="fas fa-chevron-right text-xs text-slate-300 dark:text-zinc-600"></i>
                        <span class="text-slate-900 dark:text-white font-medium">Scorecard</span>
                    </nav>
                    <?php endif; ?>

            <?php if (isset($_GET['resource'])) { ?>
                <?php if ($_GET['resource'] == 'form') { ?>
                    <!-- Scorecard Setup Form -->
                    <a href="detail.php?action=scorecard&resource=index&kegiatan_id=<?= $kegiatan_id ?>&category_id=<?= $category_id ?>"
                       class="inline-flex items-center gap-2 text-archery-600 hover:text-archery-700 font-medium text-sm mb-6 no-print">
                        <i class="fas fa-arrow-left"></i> Kembali
                    </a>

                    <div class="bg-white dark:bg-zinc-900 rounded-xl border border-slate-200 dark:border-zinc-800 shadow-sm overflow-hidden">
                        <div class="bg-gradient-to-br from-archery-600 to-archery-800 px-6 py-5 text-white text-center">
                            <div class="w-14 h-14 rounded-xl bg-white/20 flex items-center justify-center mx-auto mb-3">
                                <i class="fas fa-bullseye text-2xl"></i>
                            </div>
                            <h2 class="text-xl font-bold">Setup Scorecard</h2>
                            <p class="text-white/80 text-sm">Atur jumlah sesi dan anak panah</p>
                        </div>

                        <form action="" method="post" class="p-6">
                            <?php csrf_field(); ?>
                            <input type="hidden" id="local_time" name="local_time">

                            <div class="bg-archery-50 dark:bg-archery-900/30 border border-archery-200 dark:border-archery-800 rounded-xl p-4 mb-6 text-center">
                                <p class="font-semibold text-archery-700 dark:text-archery-400"><?= htmlspecialchars($kategoriData['name']) ?></p>
                                <p class="text-sm text-slate-600 dark:text-zinc-400"><?= htmlspecialchars($kegiatanData['nama_kegiatan']) ?></p>
                                <p class="text-lg font-bold text-amber-600 dark:text-amber-400 mt-2"><?= count($pesertaList) ?> Peserta Terdaftar</p>
                            </div>

                            <?php if (count($pesertaList) == 0): ?>
                                <div class="bg-amber-50 dark:bg-amber-900/30 border border-amber-200 dark:border-amber-800 text-amber-700 dark:text-amber-400 rounded-lg p-4 mb-6">
                                    <i class="fas fa-exclamation-triangle mr-2"></i>
                                    <strong>Peringatan:</strong> Tidak ada peserta yang terdaftar dalam kategori ini.
                                </div>
                            <?php endif; ?>

                            <div class="space-y-5">
                                <div>
                                    <label for="jumlahSesi" class="block text-sm font-medium text-slate-700 dark:text-zinc-300 mb-2">Jumlah Sesi</label>
                                    <input type="number" id="jumlahSesi" name="jumlahSesi" min="1" value="9"
                                           class="w-full px-4 py-3 rounded-lg border border-slate-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-slate-900 dark:text-white text-center text-lg font-semibold focus:ring-2 focus:ring-archery-500 focus:border-archery-500">
                                </div>

                                <div>
                                    <label for="jumlahPanah" class="block text-sm font-medium text-slate-700 dark:text-zinc-300 mb-2">Jumlah Anak Panah per Sesi</label>
                                    <input type="number" id="jumlahPanah" name="jumlahPanah" min="1" value="3"
                                           class="w-full px-4 py-3 rounded-lg border border-slate-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-slate-900 dark:text-white text-center text-lg font-semibold focus:ring-2 focus:ring-archery-500 focus:border-archery-500">
                                </div>
                            </div>

                            <button type="submit" name="create"
                                    class="w-full mt-6 px-6 py-3 rounded-xl bg-archery-600 text-white font-semibold hover:bg-archery-700 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                                    <?= count($pesertaList) == 0 ? 'disabled' : '' ?>>
                                <i class="fas fa-plus mr-2"></i> Buat Scorecard
                            </button>
                        </form>
                    </div>
                <?php } ?>

                <?php if ($_GET['resource'] == 'index') { ?>
                    <?php if (!isset($_GET['scoreboard'])) { ?>
                        <!-- Scorecard List -->
                        <div class="bg-white dark:bg-zinc-900 rounded-xl border border-slate-200 dark:border-zinc-800 shadow-sm overflow-hidden">
                            <div class="px-6 py-4 border-b border-slate-200 dark:border-zinc-800 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                                <div class="flex items-center gap-3">
                                    <button onclick="goBack()" class="p-2 rounded-lg text-slate-500 dark:text-zinc-400 hover:bg-slate-100 dark:hover:bg-zinc-800 transition-colors no-print">
                                        <i class="fas fa-arrow-left"></i>
                                    </button>
                                    <div>
                                        <h2 class="font-semibold text-slate-900 dark:text-white">Daftar Scorecard</h2>
                                        <p class="text-sm text-slate-500 dark:text-zinc-400"><?= htmlspecialchars($kategoriData['name']) ?> - <?= htmlspecialchars($kegiatanData['nama_kegiatan']) ?></p>
                                    </div>
                                </div>
                                <?php if (canInputScore()): ?>
                                <div class="flex gap-2 no-print">
                                    <a href="detail.php?action=scorecard&resource=form&kegiatan_id=<?= $kegiatan_id ?>&category_id=<?= $category_id ?>"
                                       class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-archery-600 text-white text-sm font-medium hover:bg-archery-700 transition-colors">
                                        <i class="fas fa-plus"></i> Tambah
                                    </a>
                                <?php else: ?>
                                <div class="flex gap-2 no-print">
                                <?php endif; ?>
                                    <button onclick="exportTableToExcel()" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-emerald-600 text-white text-sm font-medium hover:bg-emerald-700 transition-colors">
                                        <i class="fas fa-file-excel"></i> Export
                                    </button>
                                </div>
                            </div>

                            <div class="overflow-x-auto custom-scrollbar">
                                <table id="scorecardTable" class="w-full">
                                    <thead class="bg-zinc-800 text-white">
                                        <tr>
                                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider w-16">No</th>
                                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider">Tanggal</th>
                                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider">Jumlah Sesi</th>
                                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider">Jumlah Anak Panah</th>
                                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider w-48 no-print">Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100 dark:divide-zinc-800">
                                        <?php
                                        $loopNumber = 1;
                                        $hasData = false;
                                        while ($a = mysqli_fetch_array($mysql_table_score_board)) {
                                            $hasData = true;
                                        ?>
                                            <tr class="hover:bg-slate-50 dark:hover:bg-zinc-800 transition-colors">
                                                <td class="px-4 py-3">
                                                    <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-archery-100 dark:bg-archery-900/30 text-archery-700 dark:text-archery-400 text-sm font-semibold">
                                                        <?= $loopNumber++ ?>
                                                    </span>
                                                </td>
                                                <td class="px-4 py-3 text-sm text-slate-600 dark:text-zinc-400"><?= $a['created'] ?></td>
                                                <td class="px-4 py-3 text-center">
                                                    <span class="px-2.5 py-1 rounded-full text-xs font-medium bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400"><?= $a['jumlah_sesi'] ?> Sesi</span>
                                                </td>
                                                <td class="px-4 py-3 text-center">
                                                    <span class="px-2.5 py-1 rounded-full text-xs font-medium bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400"><?= $a['jumlah_anak_panah'] ?> Panah</span>
                                                </td>
                                                <td class="px-4 py-3 no-print">
                                                    <div class="flex items-center justify-center gap-1 flex-wrap">
                                                        <a href="detail.php?action=scorecard&resource=index&kegiatan_id=<?= $kegiatan_id ?>&category_id=<?= $category_id ?>&scoreboard=<?= $a['id'] ?>&rangking=true"
                                                           class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-slate-100 dark:bg-zinc-800 text-slate-700 dark:text-zinc-300 text-xs font-medium hover:bg-slate-200 dark:hover:bg-zinc-700 transition-colors">
                                                            <i class="fas fa-trophy text-xs"></i> Ranking
                                                        </a>
                                                        <a href="detail.php?action=scorecard&resource=index&kegiatan_id=<?= $kegiatan_id ?>&category_id=<?= $category_id ?>&scoreboard=<?= $a['id'] ?>"
                                                           class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-archery-600 text-white text-xs font-medium hover:bg-archery-700 transition-colors">
                                                            <i class="fas fa-edit text-xs"></i> Input
                                                        </a>
                                                        <a href="detail.php?aduan=true&kegiatan_id=<?= $kegiatan_id ?>&category_id=<?= $category_id ?>&scoreboard=<?= $a['id'] ?>"
                                                           class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-slate-100 dark:bg-zinc-800 text-slate-700 dark:text-zinc-300 text-xs font-medium hover:bg-slate-200 dark:hover:bg-zinc-700 transition-colors">
                                                            <i class="fas fa-sitemap text-xs"></i> Aduan
                                                        </a>
                                                        <?php if (canInputScore()): ?>
                                                        <button onclick="delete_score_board('<?= $kegiatan_id ?>', '<?= $category_id ?>', '<?= $a['id'] ?>')"
                                                                class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-slate-400 dark:text-zinc-500 hover:bg-red-50 dark:hover:bg-red-900/30 hover:text-red-600 dark:hover:text-red-400 text-xs font-medium transition-colors">
                                                            <i class="fas fa-trash text-xs"></i>
                                                        </button>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php } ?>
                                        <?php if (!$hasData): ?>
                                            <tr>
                                                <td colspan="5" class="px-4 py-12">
                                                    <div class="flex flex-col items-center text-center">
                                                        <div class="w-16 h-16 rounded-full bg-slate-100 dark:bg-zinc-800 flex items-center justify-center mb-3">
                                                            <i class="fas fa-clipboard-list text-slate-400 dark:text-zinc-500 text-2xl"></i>
                                                        </div>
                                                        <p class="text-slate-500 dark:text-zinc-400 font-medium">Belum ada scorecard</p>
                                                        <p class="text-slate-400 dark:text-zinc-500 text-sm mb-4">Klik tombol "Tambah" untuk membuat scorecard baru</p>
                                                        <a href="detail.php?action=scorecard&resource=form&kegiatan_id=<?= $kegiatan_id ?>&category_id=<?= $category_id ?>"
                                                           class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-archery-600 text-white text-sm font-medium hover:bg-archery-700 transition-colors">
                                                            <i class="fas fa-plus"></i> Buat Scorecard
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php } ?>
                <?php } ?>
            <?php } ?>

            <!-- Scorecard Detail / Input Mode -->
            <?php if (isset($_GET['scoreboard']) && !isset($_GET['rangking'])) { ?>
                <div id="scorecardContainer" class="bg-white dark:bg-zinc-900 rounded-xl border border-slate-200 dark:border-zinc-800 shadow-sm">
                    <div class="px-6 py-4 border-b border-slate-200 dark:border-zinc-800 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                        <div class="flex items-center gap-3">
                            <a href="detail.php?action=scorecard&resource=index&kegiatan_id=<?= $kegiatan_id ?>&category_id=<?= $category_id ?>"
                               class="p-2 rounded-lg text-slate-500 dark:text-zinc-400 hover:bg-slate-100 dark:hover:bg-zinc-800 transition-colors no-print">
                                <i class="fas fa-arrow-left"></i>
                            </a>
                            <div>
                                <h2 class="font-semibold text-slate-900 dark:text-white">Score Board - Input Skor</h2>
                                <p class="text-sm text-slate-500 dark:text-zinc-400"><?= htmlspecialchars($kategoriData['name']) ?></p>
                            </div>
                        </div>
                        <button onclick="exportScorecardToExcel()" id="exportBtn" class="hidden inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-emerald-600 text-white text-sm font-medium hover:bg-emerald-700 transition-colors no-print">
                            <i class="fas fa-file-excel"></i> Export
                        </button>
                    </div>

                    <!-- Stats Header - Minimal -->
                    <div class="px-6 py-3 bg-slate-50 dark:bg-zinc-800/50 border-b border-slate-200 dark:border-zinc-800">
                        <div class="flex items-center justify-between text-sm">
                            <div class="flex items-center gap-4">
                                <span class="text-slate-500 dark:text-zinc-400"><?= htmlspecialchars($kategoriData['name']) ?></span>
                                <span class="text-slate-300 dark:text-zinc-600">•</span>
                                <span class="text-slate-500 dark:text-zinc-400"><?= htmlspecialchars($kegiatanData['nama_kegiatan']) ?></span>
                            </div>
                            <div class="flex items-center gap-4 text-slate-500 dark:text-zinc-400">
                                <span><span class="font-medium text-slate-700 dark:text-zinc-300" id="pesertaCount"><?= count($pesertaList) ?></span> Peserta</span>
                                <span><span class="font-medium text-slate-700 dark:text-zinc-300" id="panahCount">-</span> Panah</span>
                            </div>
                        </div>
                    </div>

                    <!-- Peserta Selector - Improved -->
                    <div id="pesertaSelectorInline" class="p-4 sm:p-6">
                        <div class="bg-white dark:bg-zinc-800 border border-slate-200 dark:border-zinc-700 rounded-xl overflow-hidden">
                            <!-- Header with Search -->
                            <div class="px-4 py-3 border-b border-slate-200 dark:border-zinc-700 bg-slate-50 dark:bg-zinc-900">
                                <div class="flex flex-col sm:flex-row sm:items-center gap-3">
                                    <div class="flex-1">
                                        <div class="relative">
                                            <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 dark:text-zinc-500"></i>
                                            <input type="text"
                                                   id="pesertaSearchInput"
                                                   placeholder="Cari nama peserta..."
                                                   class="w-full pl-10 pr-4 py-2.5 rounded-lg border border-slate-200 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-slate-900 dark:text-white placeholder-slate-400 dark:placeholder-zinc-500 focus:outline-none focus:ring-2 focus:ring-archery-500 focus:border-transparent text-sm"
                                                   oninput="filterPesertaList(this.value)">
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-2 text-sm text-slate-500 dark:text-zinc-400">
                                        <span id="filteredCount"><?= count($pesertaList) ?></span>
                                        <span>peserta</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Peserta Grid -->
                            <div id="pesertaGrid" class="p-3 max-h-[60vh] overflow-y-auto">
                                <div id="pesertaGridInner" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2">
                                    <!-- Populated by JavaScript -->
                                </div>
                                <!-- Empty State -->
                                <div id="pesertaEmptyState" class="hidden py-12 text-center">
                                    <i class="fas fa-search text-4xl text-slate-300 dark:text-zinc-600 mb-3"></i>
                                    <p class="text-slate-500 dark:text-zinc-400">Peserta tidak ditemukan</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Selected Peserta Info -->
                    <div id="selectedPesertaInfo" class="hidden px-6 pb-4">
                        <div class="flex items-center justify-between bg-slate-50 dark:bg-zinc-800 border border-slate-200 dark:border-zinc-700 rounded-lg px-4 py-3">
                            <div class="flex items-center gap-3">
                                <span class="text-sm text-slate-500 dark:text-zinc-400">Peserta:</span>
                                <span class="font-semibold text-slate-900 dark:text-white" id="selectedPesertaName"></span>
                            </div>
                            <button onclick="changePeserta()" class="px-3 py-1.5 rounded-lg text-slate-500 dark:text-zinc-400 hover:bg-slate-200 dark:hover:bg-zinc-700 text-sm font-medium transition-colors no-print">
                                <i class="fas fa-exchange-alt mr-1"></i> Ganti
                            </button>
                        </div>
                    </div>

                    <div id="playersContainer" class="px-6 pb-6"></div>
                </div>

                <!-- Mobile Score Keyboard (only visible on mobile when input is focused) -->
                <div id="mobileScoreKeyboard" class="mobile-score-keyboard md:hidden">
                    <div class="keyboard-inner">
                        <!-- Current Input Indicator & Actions -->
                        <div class="flex items-center justify-between px-4 py-2.5 border-b border-zinc-700/50">
                            <div class="flex items-center gap-2">
                                <span class="text-xs text-zinc-400">Sesi</span>
                                <span id="keyboardCurrentSession" class="text-sm font-semibold text-white bg-zinc-700 px-2 py-0.5 rounded">-</span>
                                <span class="text-xs text-zinc-400">Panah</span>
                                <span id="keyboardCurrentArrow" class="text-sm font-semibold text-white bg-zinc-700 px-2 py-0.5 rounded">-</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <button onclick="mobileKeyboardClear()" class="keyboard-btn action-clear px-3 text-sm">
                                    <i class="fas fa-backspace"></i>
                                </button>
                                <button onclick="closeMobileKeyboard()" class="keyboard-btn action-close px-3 text-sm">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <!-- Score Buttons -->
                        <div class="p-3">
                            <div class="grid grid-cols-4 gap-2">
                                <button onclick="mobileKeyboardInput('X')" class="keyboard-btn score-x text-lg">X</button>
                                <button onclick="mobileKeyboardInput('10')" class="keyboard-btn score-10">10</button>
                                <button onclick="mobileKeyboardInput('9')" class="keyboard-btn score-high">9</button>
                                <button onclick="mobileKeyboardInput('8')" class="keyboard-btn score-high">8</button>
                                <button onclick="mobileKeyboardInput('7')" class="keyboard-btn score-mid">7</button>
                                <button onclick="mobileKeyboardInput('6')" class="keyboard-btn score-mid">6</button>
                                <button onclick="mobileKeyboardInput('5')" class="keyboard-btn score-mid">5</button>
                                <button onclick="mobileKeyboardInput('4')" class="keyboard-btn score-low">4</button>
                                <button onclick="mobileKeyboardInput('3')" class="keyboard-btn score-low">3</button>
                                <button onclick="mobileKeyboardInput('2')" class="keyboard-btn score-low">2</button>
                                <button onclick="mobileKeyboardInput('1')" class="keyboard-btn score-low">1</button>
                                <button onclick="mobileKeyboardInput('M')" class="keyboard-btn score-m text-lg">M</button>
                            </div>
                        </div>
                    </div>
                </div>
            <?php } ?>

            <!-- Ranking Mode -->
            <?php if (isset($_GET['scoreboard']) && isset($_GET['rangking'])) { ?>
                <div id="scorecardContainer" class="bg-white dark:bg-zinc-900 rounded-xl border border-slate-200 dark:border-zinc-800 shadow-sm overflow-hidden">
                    <div class="px-4 sm:px-6 py-4 border-b border-slate-200 dark:border-zinc-800 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                        <div class="flex items-center gap-3">
                            <?php if (!$isGuest): ?>
                            <a href="detail.php?action=scorecard&resource=index&kegiatan_id=<?= $kegiatan_id ?>&category_id=<?= $category_id ?>"
                               class="p-2 rounded-lg text-slate-500 dark:text-zinc-400 hover:bg-slate-100 dark:hover:bg-zinc-800 transition-colors no-print">
                                <i class="fas fa-arrow-left"></i>
                            </a>
                            <?php endif; ?>
                            <div>
                                <h2 class="font-semibold text-slate-900 dark:text-white">Ranking</h2>
                                <p class="text-sm text-slate-500 dark:text-zinc-400"><?= htmlspecialchars($kategoriData['name']) ?> • <?= htmlspecialchars($kegiatanData['nama_kegiatan']) ?></p>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 no-print">
                            <!-- View Toggle -->
                            <div class="flex items-center bg-slate-100 dark:bg-zinc-800 rounded-lg p-1">
                                <button onclick="setRankingView('leaderboard')" id="viewLeaderboard"
                                    class="px-3 py-1.5 rounded-md text-xs font-medium transition-colors bg-white dark:bg-zinc-700 text-slate-900 dark:text-white shadow-sm">
                                    <i class="fas fa-trophy mr-1"></i> Ringkas
                                </button>
                                <button onclick="setRankingView('detail')" id="viewDetail"
                                    class="px-3 py-1.5 rounded-md text-xs font-medium transition-colors text-slate-500 dark:text-zinc-400 hover:text-slate-700 dark:hover:text-zinc-300">
                                    <i class="fas fa-table mr-1"></i> Detail
                                </button>
                            </div>
                            <?php if (!$isGuest && canInputScore()): ?>
                            <a href="detail.php?action=scorecard&resource=index&kegiatan_id=<?= $kegiatan_id ?>&category_id=<?= $category_id ?>&scoreboard=<?= $_GET['scoreboard'] ?>"
                               class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-archery-600 text-white text-sm font-medium hover:bg-archery-700 transition-colors">
                                <i class="fas fa-edit"></i> <span class="hidden sm:inline">Input Skor</span>
                            </a>
                            <button onclick="exportScorecardToExcel()" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-slate-100 dark:bg-zinc-800 text-slate-700 dark:text-zinc-300 text-sm font-medium hover:bg-slate-200 dark:hover:bg-zinc-700 transition-colors">
                                <i class="fas fa-file-excel"></i> <span class="hidden sm:inline">Export</span>
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Leaderboard Container -->
                    <div class="p-4 sm:p-6">
                        <!-- PHP DEBUG: pesertaList count = <?= count($pesertaList) ?>, peserta_score count = <?= count($peserta_score) ?> -->
                        <div id="playersContainer"></div>
                    </div>
                </div>
            <?php } ?>
        </div>

        <script>
            <?php if (isset($_GET['resource']) && $_GET['resource'] == 'form') { ?>
                let now = new Date();
                let formatted = now.getFullYear() + "-"
                    + String(now.getMonth() + 1).padStart(2, '0') + "-"
                    + String(now.getDate()).padStart(2, '0') + " "
                    + String(now.getHours()).padStart(2, '0') + ":"
                    + String(now.getMinutes()).padStart(2, '0') + ":"
                    + String(now.getSeconds()).padStart(2, '0');

                document.getElementById("local_time").value = formatted;
            <?php } ?>

            const pesertaData = <?= json_encode($pesertaList) ?>;
            const CSRF_TOKEN = "<?= get_csrf_token() ?>";
            let selectedPesertaId = null;
            let saveTimeout = null;
            let inputTimeout = null;
            const SAVE_DELAY = 500;
            const INPUT_DELAY = 500;

            <?php if (isset($_GET['rangking'])) { ?>
                const peserta_score = <?= json_encode($peserta_score) ?>;
                const allScoresData = <?= json_encode($allScores ?? []) ?>;
                const jumlahSesiRanking = <?= $show_score_board['jumlah_sesi'] ?? 9 ?>;
                const jumlahPanahRanking = <?= $show_score_board['jumlah_anak_panah'] ?? 3 ?>;
                let currentRankingView = 'leaderboard';

                // Debug output
                console.log('=== RANKING DEBUG ===');
                console.log('pesertaData:', pesertaData);
                console.log('peserta_score:', peserta_score);
                console.log('allScoresData:', allScoresData);

                function tambahAtributById(id, key, value) {
                    // Use == for type coercion (PHP may encode IDs as strings or integers)
                    const peserta = pesertaData.find(p => p.id == id);
                    if (peserta) {
                        peserta[key] = value;
                    } else {
                        console.warn('Could not find peserta with id:', id, typeof id);
                    }
                }

                for (let i = 0; i < peserta_score.length; i++) {
                    tambahAtributById(peserta_score[i]['id'], "total_score", peserta_score[i]['total_score']);
                    tambahAtributById(peserta_score[i]['id'], "x_score", peserta_score[i]['total_x']);
                    tambahAtributById(peserta_score[i]['id'], "ten_plus_x_score", peserta_score[i]['total_10_plus_x']);
                }

                pesertaData.sort((a, b) => {
                    // Handle undefined scores
                    const scoreA = a.total_score || 0;
                    const scoreB = b.total_score || 0;
                    const xA = a.x_score || 0;
                    const xB = b.x_score || 0;
                    const tenPlusXA = a.ten_plus_x_score || 0;
                    const tenPlusXB = b.ten_plus_x_score || 0;

                    if (scoreB !== scoreA) {
                        return scoreB - scoreA;
                    }
                    if (tenPlusXB !== tenPlusXA) {
                        return tenPlusXB - tenPlusXA;
                    }
                    if (xB !== xA) {
                        return xB - xA;
                    }
                    // Final stable tie-break by name
                    const nameA = a.nama_peserta || "";
                    const nameB = b.nama_peserta || "";
                    return nameA.localeCompare(nameB);
                });

                console.log('pesertaData after merge:', pesertaData);

                function setRankingView(view) {
                    currentRankingView = view;
                    const leaderboardBtn = document.getElementById('viewLeaderboard');
                    const detailBtn = document.getElementById('viewDetail');

                    if (view === 'leaderboard') {
                        leaderboardBtn.className = 'px-3 py-1.5 rounded-md text-xs font-medium transition-colors bg-white dark:bg-zinc-700 text-slate-900 dark:text-white shadow-sm';
                        detailBtn.className = 'px-3 py-1.5 rounded-md text-xs font-medium transition-colors text-slate-500 dark:text-zinc-400 hover:text-slate-700 dark:hover:text-zinc-300';
                        generatePlayerSections(jumlahSesiRanking, jumlahPanahRanking);
                    } else {
                        leaderboardBtn.className = 'px-3 py-1.5 rounded-md text-xs font-medium transition-colors text-slate-500 dark:text-zinc-400 hover:text-slate-700 dark:hover:text-zinc-300';
                        detailBtn.className = 'px-3 py-1.5 rounded-md text-xs font-medium transition-colors bg-white dark:bg-zinc-700 text-slate-900 dark:text-white shadow-sm';
                        generateDetailedView(jumlahSesiRanking, jumlahPanahRanking);
                    }
                }

                function generateDetailedView(jumlahSesi, jumlahPanah) {
                    const playersContainer = document.getElementById('playersContainer');
                    if (!playersContainer) return;
                    playersContainer.innerHTML = '';

                    if (pesertaData.length === 0) {
                        playersContainer.innerHTML = `
                            <div class="text-center py-12">
                                <div class="w-16 h-16 rounded-full bg-slate-100 dark:bg-zinc-800 flex items-center justify-center mx-auto mb-4">
                                    <i class="fas fa-users text-slate-400 dark:text-zinc-500 text-2xl"></i>
                                </div>
                                <p class="text-slate-500 dark:text-zinc-400 font-medium">Tidak ada data peserta</p>
                            </div>
                        `;
                        return;
                    }

                    let html = '<div class="space-y-6">';

                    pesertaData.forEach((peserta, index) => {
                        const playerId = `peserta_${peserta.id}`;
                        const medals = ['🥇', '🥈', '🥉'];
                        const rankDisplay = index < 3 ? medals[index] : `#${index + 1}`;
                        const isTop3 = index < 3;
                        const headerBg = index === 0 ? 'bg-archery-600' : (isTop3 ? 'bg-zinc-700' : 'bg-zinc-800');

                        // Get this peserta's scores
                        const pesertaScores = allScoresData[peserta.id] || [];

                        html += `
                            <div class="border border-slate-200 dark:border-zinc-700 rounded-xl overflow-hidden">
                                <div class="${headerBg} px-4 py-3 flex items-center justify-between text-white">
                                    <div class="flex items-center gap-3">
                                        <span class="text-xl">${rankDisplay}</span>
                                        <span class="font-semibold">${peserta.nama_peserta}</span>
                                    </div>
                                    <div class="flex items-center gap-4 text-sm">
                                        <div class="flex flex-col items-end opacity-75">
                                            <span>${peserta.ten_plus_x_score || 0}× 10+X</span>
                                            <span>${peserta.x_score || 0}× X</span>
                                        </div>
                                        <span class="font-bold text-lg">${peserta.total_score || 0} pts</span>
                                    </div>
                                </div>
                                <div class="overflow-x-auto">
                                    <table class="w-full min-w-[400px]">
                                        <thead>
                                            <tr class="bg-slate-100 dark:bg-zinc-800">
                                                <th class="px-3 py-2 text-xs font-semibold text-slate-600 dark:text-zinc-400 text-center w-14">Sesi</th>
                                                ${generateArrowHeaders(jumlahPanah)}
                                                <th class="px-3 py-2 text-xs font-semibold text-slate-600 dark:text-zinc-400 text-center w-14">Sub</th>
                                                <th class="px-3 py-2 text-xs font-semibold text-slate-600 dark:text-zinc-400 text-center w-14">Total</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-slate-100 dark:divide-zinc-700 bg-white dark:bg-zinc-900">
                                            ${generateDetailRows(peserta.id, jumlahSesi, jumlahPanah, pesertaScores)}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        `;
                    });

                    html += '</div>';
                    playersContainer.innerHTML = html;
                }

                function generateArrowHeaders(jumlahPanah) {
                    let headers = '';
                    for (let a = 1; a <= jumlahPanah; a++) {
                        headers += `<th class="px-2 py-2 text-xs font-semibold text-slate-600 dark:text-zinc-400 text-center w-10">${a}</th>`;
                    }
                    return headers;
                }

                function generateDetailRows(pesertaId, jumlahSesi, jumlahPanah, pesertaScores) {
                    let rows = '';
                    let runningTotal = 0;

                    for (let s = 1; s <= jumlahSesi; s++) {
                        let sessionTotal = 0;
                        let arrowCells = '';

                        for (let a = 1; a <= jumlahPanah; a++) {
                            // Find score for this session/arrow
                            const scoreEntry = pesertaScores.find(sc => sc.session == s && sc.arrow == a);
                            const scoreVal = scoreEntry ? scoreEntry.score : '';

                            // Calculate numeric value
                            let numericScore = 0;
                            let cellClass = 'text-slate-700 dark:text-zinc-300';

                            if (scoreVal) {
                                const lowerScore = scoreVal.toLowerCase();
                                if (lowerScore === 'x') {
                                    numericScore = 10;
                                    cellClass = 'text-archery-600 dark:text-archery-400 font-bold';
                                } else if (lowerScore === 'm') {
                                    numericScore = 0;
                                    cellClass = 'text-red-500 dark:text-red-400';
                                } else {
                                    numericScore = parseInt(scoreVal) || 0;
                                    if (numericScore >= 9) cellClass = 'text-amber-600 dark:text-amber-400 font-semibold';
                                }
                                sessionTotal += numericScore;
                            }

                            arrowCells += `<td class="px-2 py-2 text-center text-sm ${cellClass}">${scoreVal.toUpperCase()}</td>`;
                        }

                        runningTotal += sessionTotal;

                        rows += `
                            <tr class="hover:bg-slate-50 dark:hover:bg-zinc-800">
                                <td class="px-3 py-2 text-center text-sm font-medium text-slate-500 dark:text-zinc-400 bg-slate-50 dark:bg-zinc-800">${s}</td>
                                ${arrowCells}
                                <td class="px-2 py-2 text-center text-sm font-semibold text-slate-700 dark:text-zinc-300 bg-slate-50 dark:bg-zinc-800">${sessionTotal}</td>
                                <td class="px-2 py-2 text-center text-sm font-bold text-slate-900 dark:text-white bg-slate-100 dark:bg-zinc-700">${runningTotal}</td>
                            </tr>
                        `;
                    }

                    return rows;
                }
            <?php } ?>

            <?php if (isset($_GET['scoreboard'])) { ?>
                <?php if (isset($_GET['rangking'])) { ?>
                    openScoreBoard("<?= $show_score_board['jumlah_sesi'] ?>", "<?= $show_score_board['jumlah_anak_panah'] ?>");
                <?php } else { ?>
                    document.addEventListener('DOMContentLoaded', function () {
                        init();
                    });
                <?php } ?>
            <?php } ?>

            function delete_score_board(kegiatan_id, category_id, id) {
                showConfirmModal('Hapus Data', 'Apakah Anda yakin ingin menghapus data ini?', () => {
                   window.location.href = `detail.php?action=scorecard&resource=index&kegiatan_id=${kegiatan_id}&category_id=${category_id}&delete_score_board=${id}`;
                }, 'danger');
            }

            <?php
            if (isset($mysql_data_score)) {
                while ($jatuh = mysqli_fetch_array($mysql_data_score)) { ?>
                    if (document.getElementById("peserta_<?= $jatuh['peserta_id'] ?>_a<?= $jatuh['arrow'] ?>_s<?= $jatuh['session'] ?>")) {
                        document.getElementById("peserta_<?= $jatuh['peserta_id'] ?>_a<?= $jatuh['arrow'] ?>_s<?= $jatuh['session'] ?>").value = "<?= $jatuh['score'] ?>";
                        hitungPerArrow('peserta_<?= $jatuh['peserta_id'] ?>', '<?= $jatuh['arrow'] ?>', '<?= $jatuh['session'] ?>', '<?= $show_score_board['jumlah_anak_panah'] ?>');
                    }
                <?php } ?>
            <?php }
            ?>

            function init() {
                renderPesertaGrid();

                // Focus search input on load
                const searchInput = document.getElementById('pesertaSearchInput');
                if (searchInput) {
                    setTimeout(() => searchInput.focus(), 100);
                }
            }

            function renderPesertaGrid(filter = '') {
                const grid = document.getElementById('pesertaGridInner');
                const emptyState = document.getElementById('pesertaEmptyState');
                const countEl = document.getElementById('filteredCount');
                if (!grid) return;

                grid.innerHTML = '';
                const filterLower = filter.toLowerCase().trim();

                const filtered = pesertaData.filter(p => {
                    if (!filterLower) return true;
                    const name = (p.nama_peserta || '').toLowerCase();
                    const club = (p.nama_club || p.club || '').toLowerCase();
                    return name.includes(filterLower) || club.includes(filterLower);
                });

                if (countEl) countEl.textContent = filtered.length;

                if (filtered.length === 0) {
                    if (emptyState) emptyState.classList.remove('hidden');
                    return;
                } else {
                    if (emptyState) emptyState.classList.add('hidden');
                }

                filtered.forEach(peserta => {
                    const card = document.createElement('div');
                    card.className = 'peserta-card flex items-center gap-3 p-3 rounded-lg border border-slate-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 hover:border-archery-500 hover:bg-archery-50 dark:hover:bg-archery-900/20 cursor-pointer transition-all';
                    card.onclick = () => selectPeserta(peserta.id);

                    const genderIcon = peserta.jenis_kelamin === 'P' ? 'fa-venus' : 'fa-mars';
                    const genderColor = peserta.jenis_kelamin === 'P'
                        ? 'bg-pink-100 dark:bg-pink-900/30 text-pink-600 dark:text-pink-400'
                        : 'bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400';
                    const genderLabel = peserta.jenis_kelamin === 'P' ? 'Putri' : 'Putra';
                    const clubName = peserta.nama_club || peserta.club || '';

                    card.innerHTML = `
                        <div class="w-10 h-10 rounded-full ${genderColor} flex items-center justify-center flex-shrink-0">
                            <i class="fas ${genderIcon}"></i>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="font-medium text-slate-900 dark:text-white text-sm truncate">${peserta.nama_peserta}</p>
                            <p class="text-xs text-slate-500 dark:text-zinc-400 truncate">${clubName ? clubName : genderLabel}</p>
                        </div>
                        <i class="fas fa-chevron-right text-slate-300 dark:text-zinc-600 text-xs"></i>
                    `;

                    grid.appendChild(card);
                });
            }

            function filterPesertaList(query) {
                renderPesertaGrid(query);
            }

            // Keep old functions for compatibility but they now use the grid
            function renderDropdownMenu() {
                renderPesertaGrid();
            }

            function toggleDropdown() {
                // No longer needed but kept for compatibility
            }

            function selectPeserta(pesertaId) {
                selectedPesertaId = pesertaId;
                const peserta = pesertaData.find(p => p.id === pesertaId);

                if (peserta) {
                    const dropdown = document.getElementById('dropdownMenu');
                    const dropdownBtn = document.getElementById('dropdownBtn');
                    if (dropdown) dropdown.classList.remove('show');
                    if (dropdownBtn) dropdownBtn.querySelector('.dropdown-arrow').style.transform = 'rotate(0deg)';

                    const selectedName = document.getElementById('selectedPesertaName');
                    const selectedInfo = document.getElementById('selectedPesertaInfo');
                    if (selectedName) selectedName.textContent = peserta.nama_peserta;
                    if (selectedInfo) selectedInfo.classList.remove('hidden');

                    const selectorInline = document.getElementById('pesertaSelectorInline');
                    const exportBtn = document.getElementById('exportBtn');

                    if (selectorInline) selectorInline.style.display = 'none';
                    if (exportBtn) exportBtn.classList.remove('hidden');

                    const jumlahSesi = parseInt("<?= $show_score_board['jumlah_sesi'] ?? 9 ?>");
                    const jumlahPanah = parseInt("<?= $show_score_board['jumlah_anak_panah'] ?? 3 ?>");
                    document.getElementById('panahCount').textContent = jumlahSesi * jumlahPanah;
                    generatePlayerSection(peserta, jumlahSesi, jumlahPanah);

                    setTimeout(() => {
                        loadExistingScores(pesertaId, jumlahPanah);
                    }, 100);
                }
            }

            function loadExistingScores(pesertaId, jumlahPanah) {
                const playerId = `peserta_${pesertaId}`;

                <?php
                if (isset($mysql_data_score)) {
                    mysqli_data_seek($mysql_data_score, 0);

                    while ($jatuh = mysqli_fetch_array($mysql_data_score)) { ?>
                        if (<?= $jatuh['peserta_id'] ?> == pesertaId) {
                            const inputElement = document.getElementById("peserta_<?= $jatuh['peserta_id'] ?>_a<?= $jatuh['arrow'] ?>_s<?= $jatuh['session'] ?>");
                            if (inputElement) {
                                inputElement.value = "<?= $jatuh['score'] ?>";
                                validateArrowInput(inputElement);
                                hitungPerArrow('peserta_<?= $jatuh['peserta_id'] ?>', '<?= $jatuh['arrow'] ?>', '<?= $jatuh['session'] ?>', jumlahPanah, null);
                            }
                        }
                    <?php } ?>
                <?php }
                ?>
                console.log("Data loaded for peserta:", pesertaId);
            }

            function changePeserta() {
                // Show selector again without page reload
                const selectorInline = document.getElementById('pesertaSelectorInline');
                const selectedInfo = document.getElementById('selectedPesertaInfo');
                const playersContainer = document.getElementById('playersContainer');
                const exportBtn = document.getElementById('exportBtn');
                const searchInput = document.getElementById('pesertaSearchInput');

                if (selectorInline) selectorInline.style.display = 'block';
                if (selectedInfo) selectedInfo.classList.add('hidden');
                if (playersContainer) playersContainer.innerHTML = '';
                if (exportBtn) exportBtn.classList.add('hidden');

                // Clear search and re-render grid
                if (searchInput) {
                    searchInput.value = '';
                    searchInput.focus();
                }
                renderPesertaGrid();

                selectedPesertaId = null;
            }

            function goBack() {
                window.location.href = 'detail.php?id=<?= $kegiatan_id ?>';
            }

            function openScoreBoard(jumlahSesi_data, jumlahPanah_data) {
                console.log('openScoreBoard called with:', jumlahSesi_data, jumlahPanah_data);
                console.log('pesertaData.length:', pesertaData.length);

                const jumlahSesi = parseInt(jumlahSesi_data);
                const jumlahPanah = parseInt(jumlahPanah_data);

                const panahCountEl = document.getElementById('panahCount');
                if (panahCountEl) {
                    panahCountEl.textContent = jumlahSesi * jumlahPanah;
                }

                generatePlayerSections(jumlahSesi, jumlahPanah);
            }

            function generatePlayerSections(jumlahSesi, jumlahPanah) {
                const playersContainer = document.getElementById('playersContainer');
                if (!playersContainer) {
                    console.error('playersContainer not found!');
                    return;
                }
                playersContainer.innerHTML = '';

                console.log('generatePlayerSections - pesertaData:', pesertaData);

                // Empty state
                if (pesertaData.length === 0) {
                    playersContainer.innerHTML = `
                        <div class="text-center py-12">
                            <div class="w-16 h-16 rounded-full bg-slate-100 dark:bg-zinc-800 flex items-center justify-center mx-auto mb-4">
                                <i class="fas fa-users text-slate-400 dark:text-zinc-500 text-2xl"></i>
                            </div>
                            <p class="text-slate-500 dark:text-zinc-400 font-medium">Tidak ada data peserta</p>
                            <p class="text-slate-400 dark:text-zinc-500 text-sm mt-1">Pastikan peserta sudah terdaftar di kategori ini</p>
                        </div>
                    `;
                    return;
                }

                // Build Top 3 Hero Section
                const top3 = pesertaData.slice(0, 3);
                const rest = pesertaData.slice(3);

                if (top3.length > 0) {
                    const heroSection = document.createElement('div');
                    heroSection.className = 'mb-6';

                    let heroHTML = '<div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">';

                    top3.forEach((peserta, index) => {
                        const hasScore = (peserta.total_score || 0) > 0;
                        const medals = ['🥇', '🥈', '🥉'];
                        const bgColors = ['bg-archery-50 dark:bg-archery-900/30 border-archery-200 dark:border-archery-700', 'bg-slate-50 dark:bg-zinc-800 border-slate-200 dark:border-zinc-700', 'bg-amber-50 dark:bg-amber-900/30 border-amber-200 dark:border-amber-700'];
                        const textColors = ['text-archery-700 dark:text-archery-400', 'text-slate-700 dark:text-zinc-300', 'text-amber-700 dark:text-amber-400'];
                        
                        // Use neutral styling if no score
                        const bgColor = hasScore ? bgColors[index] : 'bg-slate-50 dark:bg-zinc-800 border-slate-200 dark:border-zinc-700';
                        const textColor = hasScore ? textColors[index] : 'text-slate-400 dark:text-zinc-500';
                        const medalDisplay = hasScore ? medals[index] : `<div class="w-8 h-8 rounded-full bg-slate-200 dark:bg-zinc-700 mx-auto flex items-center justify-center text-xs font-bold text-slate-500">${index + 1}</div>`;

                        heroHTML += `
                            <div class="border ${bgColor} rounded-xl p-4 text-center ${hasScore && index === 0 ? 'ring-2 ring-archery-500 ring-offset-2 dark:ring-offset-zinc-900' : ''}">
                                <div class="text-3xl mb-2">${medalDisplay}</div>
                                <p class="font-bold text-lg text-slate-900 dark:text-white mb-1 overflow-hidden text-ellipsis whitespace-nowrap">${peserta.nama_peserta}</p>
                                <p class="text-3xl font-bold ${textColor}">${peserta.total_score || 0}</p>
                                <div class="flex items-center justify-center gap-2 mt-1">
                                    <span class="text-xs text-slate-500 dark:text-zinc-400">10+X: ${peserta.ten_plus_x_score || 0}</span>
                                    <span class="text-xs text-slate-500 dark:text-zinc-400">•</span>
                                    <span class="text-xs text-slate-500 dark:text-zinc-400">X: ${peserta.x_score || 0}</span>
                                </div>
                            </div>
                        `;
                    });

                    heroHTML += '</div>';
                    heroSection.innerHTML = heroHTML;
                    playersContainer.appendChild(heroSection);
                }

                // Build compact table for all participants
                if (pesertaData.length > 0) {
                    const tableSection = document.createElement('div');
                    tableSection.className = 'border border-slate-200 dark:border-zinc-700 rounded-xl overflow-hidden';

                    let tableHTML = `
                        <div class="overflow-x-auto">
                            <table class="w-full min-w-[320px]">
                                <thead class="bg-zinc-800 text-white">
                                    <tr>
                                        <th class="px-2 sm:px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider w-12 sm:w-16">#</th>
                                        <th class="px-2 sm:px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider">Nama</th>
                                        <th class="px-2 sm:px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider w-16 sm:w-20 hidden sm:table-cell">10+X</th>
                                        <th class="px-2 sm:px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider w-12 sm:w-16 hidden sm:table-cell">X</th>
                                        <th class="px-2 sm:px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider w-20 sm:w-24">Total</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 dark:divide-zinc-700 bg-white dark:bg-zinc-900">
                    `;

                    pesertaData.forEach((peserta, index) => {
                        const isTop3 = index < 3;
                        const rowClass = index === 0 ? 'bg-archery-50 dark:bg-archery-900/20' : (isTop3 ? 'bg-slate-50 dark:bg-zinc-800' : 'bg-white dark:bg-zinc-900 hover:bg-slate-50 dark:hover:bg-zinc-800');
                        const hasScore = (peserta.total_score || 0) > 0;
                        const rankDisplay = (hasScore && isTop3) ? ['🥇', '🥈', '🥉'][index] : (index + 1);
                        const nameWeight = isTop3 ? 'font-semibold' : 'font-medium';
                        const scoreWeight = isTop3 ? 'font-bold text-base sm:text-lg' : 'font-semibold';
                        const scoreColor = index === 0 ? 'text-archery-700 dark:text-archery-400' : 'text-slate-900 dark:text-white';

                        tableHTML += `
                            <tr class="${rowClass} transition-colors">
                                <td class="px-2 sm:px-4 py-3 text-center text-base sm:text-lg text-slate-900 dark:text-white">${rankDisplay}</td>
                                <td class="px-2 sm:px-4 py-3">
                                    <div>
                                        <span class="${nameWeight} text-slate-900 dark:text-white text-sm sm:text-base block truncate max-w-[120px] sm:max-w-none">${peserta.nama_peserta}</span>
                                        <span class="text-xs text-slate-500 dark:text-zinc-400 sm:hidden">${peserta.ten_plus_x_score || 0}× 10+X • ${peserta.x_score || 0}× X</span>
                                    </div>
                                </td>
                                <td class="px-2 sm:px-4 py-3 text-right text-slate-500 dark:text-zinc-400 text-sm hidden sm:table-cell">${peserta.ten_plus_x_score || 0}</td>
                                <td class="px-2 sm:px-4 py-3 text-right text-slate-500 dark:text-zinc-400 text-sm hidden sm:table-cell">${peserta.x_score || 0}</td>
                                <td class="px-2 sm:px-4 py-3 text-right ${scoreWeight} ${scoreColor}">${peserta.total_score || 0}</td>
                            </tr>
                        `;
                    });

                    tableHTML += `
                                </tbody>
                            </table>
                        </div>
                        <div class="bg-slate-50 dark:bg-zinc-800 px-4 py-3 text-sm text-slate-500 dark:text-zinc-400 border-t border-slate-200 dark:border-zinc-700">
                            ${pesertaData.length} peserta • ${jumlahSesi} sesi × ${jumlahPanah} panah
                        </div>
                    `;

                    tableSection.innerHTML = tableHTML;
                    playersContainer.appendChild(tableSection);
                }
            }

            function generatePlayerSection(peserta, jumlahSesi, jumlahPanah) {
                const playerId = `peserta_${peserta.id}`;
                const playerName = peserta.nama_peserta;

                const playersContainer = document.getElementById('playersContainer');
                if (!playersContainer) return;

                playersContainer.innerHTML = `
                    <div class="border border-slate-200 dark:border-zinc-700 rounded-xl overflow-hidden">
                        <div class="overflow-x-auto custom-scrollbar">
                            <table class="w-full min-w-[500px]">
                                <thead>
                                    <tr class="bg-zinc-800 text-white">
                                        <th rowspan="2" class="px-3 py-2 text-xs font-semibold text-center w-16">Sesi</th>
                                        <th colspan="${jumlahPanah}" class="px-3 py-2 text-xs font-semibold text-center">Anak Panah</th>
                                        <th rowspan="2" class="px-3 py-2 text-xs font-semibold text-center w-14">Sub</th>
                                        <th rowspan="2" class="px-3 py-2 text-xs font-semibold text-center w-14">Total</th>
                                    </tr>
                                    <tr class="bg-zinc-800 text-white">
                                        ${Array.from({ length: jumlahPanah }, (_, i) => `<th class="px-2 py-1 text-xs font-medium text-center w-12 border-t border-zinc-700">${i + 1}</th>`).join('')}
                                    </tr>
                                </thead>
                                <tbody class="bg-white dark:bg-zinc-900 divide-y divide-slate-100 dark:divide-zinc-700">
                                    ${generateTableRows(playerId, jumlahSesi, jumlahPanah)}
                                </tbody>
                            </table>
                        </div>
                        <div class="bg-zinc-900 px-6 py-5 text-center">
                            <p class="text-xs text-zinc-400 uppercase tracking-wide mb-1">Total Skor</p>
                            <p class="text-3xl font-bold text-white" id="${playerId}_grand_total">0</p>
                        </div>
                    </div>
                `;
            }

            function generateTableRows(playerId, jumlahSesi, jumlahPanah) {
                let rowsHtml = '';

                for (let session = 1; session <= jumlahSesi; session++) {
                    const arrowInputs = Array.from({ length: jumlahPanah }, (_, arrow) => `
                        <td class="px-1 py-2 text-center">
                            <input type="text"
                                   class="arrow-input w-10 h-8 text-center text-sm font-semibold rounded border border-slate-300 dark:border-zinc-600 focus:border-archery-500 bg-white dark:bg-zinc-800 text-slate-900 dark:text-white"
                                   <?= (isset($_GET['rangking'])) ? 'disabled' : '' ?>
                                   id="${playerId}_a${arrow + 1}_s${session}"
                                   placeholder=""
                                   data-player-id="${playerId}"
                                   data-arrow="${arrow + 1}"
                                   data-session="${session}"
                                   data-total-arrow="${jumlahPanah}"
                                   oninput="handleArrowInput(this)"
                                   onkeydown="handleArrowKeydown(event, this)">
                        </td>
                    `).join('');

                    rowsHtml += `
                        <tr class="hover:bg-slate-50 dark:hover:bg-zinc-800 transition-colors">
                            <td class="px-3 py-2 text-center font-medium text-slate-500 dark:text-zinc-400 bg-slate-50 dark:bg-zinc-900 border-r border-slate-100 dark:border-zinc-800 text-sm">${session}</td>
                            ${arrowInputs}
                            <td class="px-1 py-2 text-center">
                                <input type="text"
                                       class="w-10 h-8 text-center text-sm font-semibold rounded bg-slate-100 dark:bg-zinc-800 border border-slate-200 dark:border-zinc-700 text-slate-700 dark:text-zinc-300"
                                       id="${playerId}_total_a${session}"
                                       readonly>
                            </td>
                            <td class="px-1 py-2 text-center">
                                <input type="text"
                                       class="w-10 h-8 text-center text-sm font-semibold rounded bg-slate-100 dark:bg-zinc-700 border border-slate-200 dark:border-zinc-600 text-slate-900 dark:text-white"
                                       id="${playerId}_end_a${session}"
                                       readonly>
                            </td>
                        </tr>
                    `;
                }

                return rowsHtml;
            }

            function handleArrowInput(el) {
                const playerId = el.getAttribute('data-player-id');
                const arrow = el.getAttribute('data-arrow');
                const session = el.getAttribute('data-session');
                const totalArrow = parseInt(el.getAttribute('data-total-arrow'));

                validateArrowInput(el);
                hitungPerArrow(playerId, arrow, session, totalArrow, el);

                if (inputTimeout) {
                    clearTimeout(inputTimeout);
                }

                const val = el.value.trim().toLowerCase();
                if (val !== '') {
                    inputTimeout = setTimeout(() => {
                        moveToNextInput(el, playerId, arrow, session, totalArrow);
                    }, INPUT_DELAY);
                }
            }

            function hitungPerArrow(playerId, arrow, session, totalArrow, el) {
                let sessionTotal = 0;

                for (let a = 1; a <= totalArrow; a++) {
                    const input = document.getElementById(`${playerId}_a${a}_s${session}`);
                    if (input && input.value) {
                        let val = input.value.trim().toLowerCase();
                        let score = 0;
                        if (val === "x") {
                            score = 10;
                        } else if (val === "m") {
                            score = 0;
                        } else if (!isNaN(val) && val !== "") {
                            score = parseInt(val);
                        }
                        sessionTotal += score;
                    }
                }

                const totalInput = document.getElementById(`${playerId}_total_a${session}`);
                if (totalInput) {
                    totalInput.value = sessionTotal;
                }

                let maxSession = 20;
                let runningTotal = 0;

                for (let s = 1; s <= maxSession; s++) {
                    const sessionTotalInput = document.getElementById(`${playerId}_total_a${s}`);
                    const sessionEndInput = document.getElementById(`${playerId}_end_a${s}`);

                    if (sessionTotalInput && sessionEndInput) {
                        if (sessionTotalInput.value && sessionTotalInput.value !== '') {
                            runningTotal += parseInt(sessionTotalInput.value) || 0;
                        }
                        sessionEndInput.value = runningTotal;
                    } else {
                        break;
                    }
                }

                const grandTotalElement = document.getElementById(`${playerId}_grand_total`);
                if (grandTotalElement) {
                    grandTotalElement.innerText = runningTotal;
                }

                if (el != null) {
                    if (saveTimeout) {
                        clearTimeout(saveTimeout);
                    }

                    el.classList.add('saving');

                    saveTimeout = setTimeout(() => {
                        saveScoreToDatabase(playerId, arrow, session, el);
                    }, SAVE_DELAY);
                }

                return 0;
            }

            function saveScoreToDatabase(playerId, arrow, session, el) {
                let arr_playerID = playerId.split("_");
                let scoreValue = el.value.trim();

                fetch("", {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/x-www-form-urlencoded"
                    },
                    body: "save_score=1" +
                        "&csrf_token=" + encodeURIComponent(CSRF_TOKEN) +
                        "&peserta_id=" + encodeURIComponent(arr_playerID[1]) +
                        "&arrow=" + encodeURIComponent(arrow) +
                        "&session=" + encodeURIComponent(session) +
                        "&score=" + encodeURIComponent(scoreValue)
                })
                    .then(response => response.text())
                    .then(text => {
                        try {
                            return JSON.parse(text);
                        } catch (e) {
                            return { status: 'success', message: text };
                        }
                    })
                    .then(data => {
                        console.log("Score saved:", data);
                        el.classList.remove('saving');
                        el.classList.add('saved');

                        setTimeout(() => {
                            el.classList.remove('saved');
                            validateArrowInput(el);
                        }, 1000);
                    })
                    .catch(err => {
                        console.error("Save error:", err);
                        el.classList.remove('saving');
                        el.classList.add('error');

                        setTimeout(() => {
                            el.classList.remove('error');
                            validateArrowInput(el);
                        }, 2000);
                    });
            }

            function moveToNextInput(currentElement, playerId, currentArrow, currentSession, totalArrow) {
                let nextArrow = parseInt(currentArrow);
                let nextSession = parseInt(currentSession);

                if (nextArrow < totalArrow) {
                    nextArrow++;
                } else {
                    nextArrow = 1;
                    nextSession++;
                }

                const nextInput = document.getElementById(`${playerId}_a${nextArrow}_s${nextSession}`);

                if (nextInput && !nextInput.disabled) {
                    setTimeout(() => {
                        nextInput.focus();
                        nextInput.select();
                    }, 100);
                }
            }

            function validateArrowInput(el) {
                let val = el.value.trim().toLowerCase();

                if (!/^(10|[0-9]|x|m)?$/i.test(val)) {
                    el.value = "";
                    return;
                }

                el.classList.remove('score-x', 'score-m', 'score-10', 'score-high');

                if (val === 'x' || val === 'X') {
                    el.classList.add('score-x');
                } else if (val === 'm' || val === 'M') {
                    el.classList.add('score-m');
                } else if (val === '10') {
                    el.classList.add('score-10');
                } else if (val === '9' || val === '8') {
                    el.classList.add('score-high');
                }
            }

            // ========================================
            // Mobile Score Keyboard Functions
            // ========================================
            let currentFocusedInput = null;
            const isMobileDevice = () => window.innerWidth < 768;

            function showMobileKeyboard(inputEl) {
                if (!isMobileDevice()) return;

                currentFocusedInput = inputEl;
                const keyboard = document.getElementById('mobileScoreKeyboard');
                if (keyboard) {
                    keyboard.classList.add('active');
                    document.body.classList.add('keyboard-active');

                    // Update indicator
                    const session = inputEl.getAttribute('data-session');
                    const arrow = inputEl.getAttribute('data-arrow');
                    document.getElementById('keyboardCurrentSession').textContent = session || '-';
                    document.getElementById('keyboardCurrentArrow').textContent = arrow || '-';

                    // Scroll input into view
                    setTimeout(() => {
                        inputEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }, 100);
                }
            }

            function closeMobileKeyboard() {
                const keyboard = document.getElementById('mobileScoreKeyboard');
                if (keyboard) {
                    keyboard.classList.remove('active');
                    document.body.classList.remove('keyboard-active');
                }
                if (currentFocusedInput) {
                    currentFocusedInput.blur();
                }
                currentFocusedInput = null;
            }

            function mobileKeyboardInput(value) {
                if (!currentFocusedInput) return;

                // Set the value
                currentFocusedInput.value = value;

                // Trigger the existing input handling
                handleArrowInput(currentFocusedInput);

                // Move to next input after a short delay
                const playerId = currentFocusedInput.getAttribute('data-player-id');
                const arrow = currentFocusedInput.getAttribute('data-arrow');
                const session = currentFocusedInput.getAttribute('data-session');
                const totalArrow = parseInt(currentFocusedInput.getAttribute('data-total-arrow'));

                setTimeout(() => {
                    moveToNextInput(currentFocusedInput, playerId, arrow, session, totalArrow);
                    // Find and focus the next input for the keyboard
                    const nextInput = findNextArrowInput(playerId, parseInt(arrow), parseInt(session), totalArrow);
                    if (nextInput) {
                        currentFocusedInput = nextInput;
                        nextInput.focus();
                        // Update indicator
                        document.getElementById('keyboardCurrentSession').textContent = nextInput.getAttribute('data-session') || '-';
                        document.getElementById('keyboardCurrentArrow').textContent = nextInput.getAttribute('data-arrow') || '-';
                        // Scroll to next input
                        nextInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                }, 150);
            }

            function mobileKeyboardClear() {
                if (!currentFocusedInput) return;
                currentFocusedInput.value = '';
                handleArrowInput(currentFocusedInput);
            }

            function findNextArrowInput(playerId, currentArrow, currentSession, totalArrow) {
                let nextArrow = currentArrow;
                let nextSession = currentSession;

                if (nextArrow < totalArrow) {
                    nextArrow++;
                } else {
                    nextArrow = 1;
                    nextSession++;
                }

                const nextId = `${playerId}_a${nextArrow}_s${nextSession}`;
                return document.getElementById(nextId);
            }

            // Attach focus/blur handlers to arrow inputs on mobile
            function attachMobileKeyboardHandlers() {
                if (!isMobileDevice()) return;

                document.querySelectorAll('.arrow-input').forEach(input => {
                    // Prevent native keyboard on mobile
                    input.setAttribute('inputmode', 'none');
                    input.setAttribute('readonly', 'readonly');

                    input.addEventListener('focus', function(e) {
                        showMobileKeyboard(this);
                    });

                    input.addEventListener('click', function(e) {
                        e.preventDefault();
                        this.focus();
                        showMobileKeyboard(this);
                    });
                });
            }

            // Re-attach handlers when new score cards are rendered
            const originalRenderPlayerScoreCard = typeof renderPlayerScoreCard === 'function' ? renderPlayerScoreCard : null;

            // Watch for new arrow inputs being added
            const observeMobileKeyboard = new MutationObserver((mutations) => {
                if (isMobileDevice()) {
                    mutations.forEach(mutation => {
                        mutation.addedNodes.forEach(node => {
                            if (node.nodeType === 1) {
                                const inputs = node.querySelectorAll ? node.querySelectorAll('.arrow-input') : [];
                                inputs.forEach(input => {
                                    input.setAttribute('inputmode', 'none');
                                    input.setAttribute('readonly', 'readonly');
                                    input.addEventListener('focus', function() {
                                        showMobileKeyboard(this);
                                    });
                                    input.addEventListener('click', function(e) {
                                        e.preventDefault();
                                        this.focus();
                                        showMobileKeyboard(this);
                                    });
                                });
                            }
                        });
                    });
                }
            });

            // Start observing when DOM is ready
            document.addEventListener('DOMContentLoaded', function() {
                attachMobileKeyboardHandlers();
                const playersContainer = document.getElementById('playersContainer');
                if (playersContainer) {
                    observeMobileKeyboard.observe(playersContainer, { childList: true, subtree: true });
                }
            });

            // Close keyboard when clicking outside
            document.addEventListener('click', function(e) {
                if (!isMobileDevice()) return;
                const keyboard = document.getElementById('mobileScoreKeyboard');
                if (!keyboard) return;

                const isKeyboardClick = keyboard.contains(e.target);
                const isInputClick = e.target.classList.contains('arrow-input');

                if (!isKeyboardClick && !isInputClick && keyboard.classList.contains('active')) {
                    closeMobileKeyboard();
                }
            });
            // ========================================

            function editScorecard() {
                window.location.href = 'detail.php?action=scorecard&resource=form&kegiatan_id=<?= $kegiatan_id ?>&category_id=<?= $category_id ?>';
            }

            // Updated: Use backend export instead of Client-side HTML blob
            // Updated: Use backend export and showConfirmModal
            function exportTableToExcel() {
                const urlParams = new URLSearchParams(window.location.search);
                urlParams.set('export', 'excel');
                const url = '?' + urlParams.toString();
                
                showConfirmModal(
                    'Export Data', 
                    'Download data ke Excel (.xlsx)?', 
                    () => window.location.href = url, 
                    'info'
                );
            }

            // Updated: Use actions/excel_score.php and showConfirmModal
            function exportScorecardToExcel() {
                const categoryId = '<?= $category_id ?>';
                const kegiatanId = '<?= $kegiatan_id ?>';
                
                // Get scoreboard ID from URL if exists
                const urlParams = new URLSearchParams(window.location.search);
                const scoreboardId = urlParams.get('scoreboard');
                
                if (!categoryId || !kegiatanId || !scoreboardId) {
                     showConfirmModal('Export Gagal', 'Data ID tidak lengkap (scoreboard ID dibutuhkan).', null, 'error');
                     return;
                }

                showConfirmModal(
                    'Export Scorecard', 
                    'Download data scorecard ke Excel (.xlsx)?', 
                    function() {
                        window.location.href = `../actions/excel_score.php?kegiatan_id=${kegiatanId}&category_id=${categoryId}&scoreboard=${scoreboardId}`;
                    },
                    'info'
                );
            }

            // Auto-Capitalize Inputs
            document.addEventListener('DOMContentLoaded', function() {
                const upperFields = ['nama_peserta', 'club', 'kota', 'sekolah', 'search'];
                
                upperFields.forEach(id => {
                    const el = document.getElementById(id);
                    if (el) {
                        // Force CSS uppercase
                        el.style.textTransform = 'uppercase';
                        
                        // Force Value uppercase on input
                        el.addEventListener('input', function() {
                            let start = this.selectionStart;
                            let end = this.selectionEnd;
                            this.value = this.value.toUpperCase();
                            this.setSelectionRange(start, end);
                        });
                        
                        // Also on blur to be safe
                        el.addEventListener('blur', function() {
                            this.value = this.value.toUpperCase();
                        });
                    }
                });
            });

            // Mobile menu functionality
            const mobileMenuBtn = document.getElementById('mobile-menu-btn');
            const mobileOverlay = document.getElementById('mobile-overlay');
            const mobileSidebar = document.getElementById('mobile-sidebar');
            const closeMobileMenu = document.getElementById('close-mobile-menu');

            function openMobileMenu() {
                mobileOverlay.classList.remove('hidden');
                mobileSidebar.classList.remove('-translate-x-full');
                document.body.style.overflow = 'hidden';
            }

            function closeMobileMenuFn() {
                mobileOverlay.classList.add('hidden');
                mobileSidebar.classList.add('-translate-x-full');
                document.body.style.overflow = '';
            }

            if (mobileMenuBtn) mobileMenuBtn.addEventListener('click', openMobileMenu);
            if (mobileOverlay) mobileOverlay.addEventListener('click', closeMobileMenuFn);
            if (closeMobileMenu) closeMobileMenu.addEventListener('click', closeMobileMenuFn);
        </script>

                </div>
            </main>
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
            <nav class="flex-1 px-4 py-6 space-y-1">
                <a href="dashboard.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800">
                    <i class="fas fa-home w-5"></i><span class="text-sm">Dashboard</span>
                </a>
                <a href="users.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800">
                    <i class="fas fa-users w-5"></i><span class="text-sm">Users</span>
                </a>
                <a href="categori.view.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800">
                    <i class="fas fa-tags w-5"></i><span class="text-sm">Kategori</span>
                </a>
                <a href="kegiatan.view.php" class="flex items-center gap-3 px-4 py-3 rounded-lg bg-archery-600/20 text-archery-400">
                    <i class="fas fa-calendar w-5"></i><span class="text-sm font-medium">Kegiatan</span>
                </a>
                <a href="peserta.view.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800">
                    <i class="fas fa-user-friends w-5"></i><span class="text-sm">Peserta</span>
                </a>
                <a href="statistik.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800">
                    <i class="fas fa-chart-bar w-5"></i><span class="text-sm">Statistik</span>
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
            // Mobile menu functionality (after DOM elements exist)
            (function() {
                const mobileMenuBtn = document.getElementById('mobile-menu-btn');
                const mobileOverlay = document.getElementById('mobile-overlay');
                const mobileSidebar = document.getElementById('mobile-sidebar');
                const closeMobileMenu = document.getElementById('close-mobile-menu');

                function openMobileMenu() {
                    if (mobileOverlay) mobileOverlay.classList.remove('hidden');
                    if (mobileSidebar) mobileSidebar.classList.remove('-translate-x-full');
                    document.body.style.overflow = 'hidden';
                }

                function closeMobileMenuFn() {
                    if (mobileOverlay) mobileOverlay.classList.add('hidden');
                    if (mobileSidebar) mobileSidebar.classList.add('-translate-x-full');
                    document.body.style.overflow = '';
                }

                if (mobileMenuBtn) mobileMenuBtn.addEventListener('click', openMobileMenu);
                if (mobileOverlay) mobileOverlay.addEventListener('click', closeMobileMenuFn);
                if (closeMobileMenu) closeMobileMenu.addEventListener('click', closeMobileMenuFn);
            })();

            // Theme Toggle
            <?= getThemeToggleScript() ?>
        </script>
    <?= getConfirmationModal() ?>
    <?= getUiScripts() ?>
    </body>
    </html>
    <?php
        exit;
}

// ============================================
// BAGIAN TAMPILAN NORMAL (DAFTAR PESERTA)
// ============================================

require_once __DIR__ . '/../config/panggil.php';

$kegiatan_id = isset($_GET['kegiatan_id']) ? intval($_GET['kegiatan_id']) : null;

if (!$kegiatan_id) {
    try {
        $id_param = isset($_GET['POST']) ? intval($_GET['POST']) : (isset($_GET['id']) ? intval($_GET['id']) : 0);
        if ($id_param > 0) {
            $queryFirstKegiatan = "SELECT id FROM kegiatan WHERE id = ?";
            $stmtFirst = $conn->prepare($queryFirstKegiatan);
            $stmtFirst->bind_param("i", $id_param);
            $stmtFirst->execute();
            $resultFirstKegiatan = $stmtFirst->get_result();
            if ($resultFirstKegiatan && $resultFirstKegiatan->num_rows > 0) {
                $firstKegiatan = $resultFirstKegiatan->fetch_assoc();
                $kegiatan_id = $firstKegiatan['id'];
            }
            $stmtFirst->close();
        }
    } catch (Exception $e) {
        die("Error mengambil kegiatan: " . $e->getMessage());
    }
}

if (!$kegiatan_id) {
    die("Tidak ada kegiatan yang tersedia.");
}

$kegiatanData = [];
try {
    $queryKegiatan = "SELECT id, nama_kegiatan FROM kegiatan WHERE id = ?";
    $stmtKegiatan = $conn->prepare($queryKegiatan);
    $stmtKegiatan->bind_param("i", $kegiatan_id);
    $stmtKegiatan->execute();
    $resultKegiatan = $stmtKegiatan->get_result();

    if ($resultKegiatan->num_rows > 0) {
        $kegiatanData = $resultKegiatan->fetch_assoc();
    } else {
        die("Kegiatan tidak ditemukan.");
    }
    $stmtKegiatan->close();
} catch (Exception $e) {
    die("Error mengambil data kegiatan: " . $e->getMessage());
}

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_kategori = isset($_GET['filter_kategori']) ? intval($_GET['filter_kategori']) : 0;
$filter_gender = isset($_GET['filter_gender']) ? $_GET['filter_gender'] : '';

$whereConditions = ["p.nama_peserta IN (SELECT nama_peserta FROM peserta WHERE kegiatan_id = ?)"];
$params = [$kegiatan_id];
$types = "i";

if (!empty($search)) {
    $whereConditions[] = "(p.nama_peserta LIKE ? OR p.asal_kota LIKE ? OR p.nama_club LIKE ? OR p.sekolah LIKE ?)";
    $searchParam = "%$search%";
    $params = array_merge($params, [$searchParam, $searchParam, $searchParam, $searchParam]);
    $types .= "ssss";
}

if ($filter_kategori > 0) {
    $whereConditions[] = "p.category_id = ?";
    $params[] = $filter_kategori;
    $types .= "i";
}

if (!empty($filter_gender)) {
    $whereConditions[] = "p.jenis_kelamin = ?";
    $params[] = $filter_gender;
    $types .= "s";
}

$whereClause = implode(" AND ", $whereConditions);

$queryPeserta = "
    SELECT 
        MAX(p.id) as id,
        p.nama_peserta,
        MAX(p.tanggal_lahir) as tanggal_lahir,
        p.jenis_kelamin,
        MAX(p.asal_kota) as asal_kota,
        MAX(p.nama_club) as nama_club,
        MAX(p.sekolah) as sekolah,
        MAX(p.kelas) as kelas,
        MAX(p.nomor_hp) as nomor_hp,
        MAX(p.bukti_pembayaran) as bukti_pembayaran,
        MAX(c.name) as category_name,
        MAX(c.min_age) as min_age,
        MAX(c.max_age) as max_age,
        MAX(c.gender) as category_gender,
        MAX(TIMESTAMPDIFF(YEAR, p.tanggal_lahir, CURDATE())) as umur
    FROM peserta p
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE $whereClause
    GROUP BY p.nama_peserta, p.jenis_kelamin
    ORDER BY p.nama_peserta ASC
";

$pesertaList = [];
$totalPeserta = 0;

try {
    $stmtPeserta = $conn->prepare($queryPeserta);
    if (!empty($params)) {
        $stmtPeserta->bind_param($types, ...$params);
    }
    $stmtPeserta->execute();
    $resultPeserta = $stmtPeserta->get_result();

    while ($row = $resultPeserta->fetch_assoc()) {
        $pesertaList[] = $row;
    }
    $totalPeserta = count($pesertaList);
    $stmtPeserta->close();
} catch (Exception $e) {
    die("Error mengambil data peserta: " . $e->getMessage());
}

$kategoriesList = [];
try {
    $queryKategori = "
        SELECT DISTINCT c.id, c.name 
        FROM categories c 
        INNER JOIN kegiatan_kategori kk ON c.id = kk.category_id 
        WHERE kk.kegiatan_id = ? AND c.status = 'active'
        ORDER BY c.name ASC
    ";
    $stmtKategori = $conn->prepare($queryKategori);
    $stmtKategori->bind_param("i", $kegiatan_id);
    $stmtKategori->execute();
    $resultKategori = $stmtKategori->get_result();

    while ($row = $resultKategori->fetch_assoc()) {
        $kategoriesList[] = $row;
    }
    $stmtKategori->close();
} catch (Exception $e) {
    // Biarkan kosong jika error
}

$statistik = [
    'total' => $totalPeserta,
    'laki_laki' => 0,
    'perempuan' => 0,
    'kategori' => [],
    'sudah_bayar' => 0,
    'belum_bayar' => 0
];

foreach ($pesertaList as $peserta) {
    if ($peserta['jenis_kelamin'] == 'Laki-laki') {
        $statistik['laki_laki']++;
    } else {
        $statistik['perempuan']++;
    }

    if (!empty($peserta['bukti_pembayaran'])) {
        $statistik['sudah_bayar']++;
    } else {
        $statistik['belum_bayar']++;
    }

    $kategori = $peserta['category_name'];
    if (!isset($statistik['kategori'][$kategori])) {
        $statistik['kategori'][$kategori] = 0;
    }
    $statistik['kategori'][$kategori]++;
}

// ============================================
// PAGINATION LOGIC
// ============================================
$limit = 50;
$page = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
$total_rows = $totalPeserta;
$total_pages = ceil($total_rows / $limit);
$offset = ($page - 1) * $limit;

// Slice the array for current page
$pesertaListPaginated = array_slice($pesertaList, $offset, $limit);

// Helper function to build pagination URL preserving GET params
function buildPaginationUrl($page, $params = []) {
    $current = $_GET;
    $current['p'] = $page;
    foreach ($params as $key => $value) {
        $current[$key] = $value;
    }
    return '?' . http_build_query($current);
}
?>
<!DOCTYPE html>
<html lang="id" class="h-full">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daftar Peserta - <?= htmlspecialchars($kegiatanData['nama_kegiatan']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script><?= getThemeTailwindConfig() ?></script>
    <script><?= getThemeInitScript() ?></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .custom-scrollbar::-webkit-scrollbar { width: 6px; height: 6px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 3px; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        .dark .custom-scrollbar::-webkit-scrollbar-track { background: #27272a; }
        .dark .custom-scrollbar::-webkit-scrollbar-thumb { background: #52525b; }
        .dark .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #71717a; }
        .btn-input { display: none; }
        .btn-input.show { display: inline-block; }
        .payment-icon { cursor: pointer; transition: transform 0.2s; }
        .payment-icon:hover { transform: scale(1.2); }
        .payment-tooltip { position: relative; display: inline-block; }
        .payment-tooltip .tooltip-text {
            visibility: hidden; width: 140px; background: #333; color: #fff;
            text-align: center; border-radius: 6px; padding: 8px; position: absolute;
            z-index: 50; bottom: 125%; left: 50%; margin-left: -70px; opacity: 0;
            transition: opacity 0.3s; font-size: 12px;
        }
        .payment-tooltip:hover .tooltip-text { visibility: visible; opacity: 1; }
        /* Mobile card view */
        .mobile-card-view { display: none; }
        @media (max-width: 768px) {
            .table-container { display: none; }
            .mobile-card-view { display: block; }
        }

        /* Modal - minimal styles needed */
        .modal { display: none; position: fixed; z-index: 1000; inset: 0; background: rgba(0,0,0,0.8); }
        .modal-content { position: relative; margin: 5% auto; width: 90%; max-width: 700px; background: white; border-radius: 12px; overflow: hidden; }
        .dark .modal-content { background: #18181b; }
        .modal-close { position: absolute; right: 1rem; top: 0.75rem; color: white; font-size: 1.5rem; cursor: pointer; z-index: 1001; }
        .modal-close:hover { opacity: 0.7; }
    </style>
</head>

<body class="h-full bg-slate-50 dark:bg-zinc-950 transition-colors">
    <div class="flex h-full">
        <!-- Sidebar -->
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
                    <a href="kegiatan.view.php" class="flex items-center gap-3 px-4 py-3 rounded-lg bg-archery-600/20 text-archery-400 border border-archery-600/30 transition-colors">
                        <i class="fas fa-calendar w-5"></i>
                        <span class="text-sm font-medium">Kegiatan</span>
                    </a>
                    <a href="peserta.view.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800 transition-colors">
                        <i class="fas fa-user-friends w-5"></i>
                        <span class="text-sm">Peserta</span>
                    </a>
                    <a href="statistik.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800 transition-colors">
                        <i class="fas fa-chart-bar w-5"></i>
                        <span class="text-sm">Statistik</span>
                    </a>
                </div>

                <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                <div class="pt-4">
                    <p class="px-4 text-xs font-semibold text-zinc-500 uppercase tracking-wider mb-2">System</p>
                    <a href="recovery.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800 transition-colors">
                        <i class="fas fa-trash-restore w-5"></i>
                        <span class="text-sm">Data Recovery</span>
                    </a>
                </div>
                <?php endif; ?>
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
                <!-- Breadcrumb -->
                <nav class="flex items-center gap-2 text-sm text-slate-500 dark:text-zinc-400 mb-4">
                    <a href="dashboard.php" class="hover:text-archery-600 transition-colors">Dashboard</a>
                    <i class="fas fa-chevron-right text-xs text-slate-300 dark:text-zinc-600"></i>
                    <a href="kegiatan.view.php" class="hover:text-archery-600 transition-colors">Kegiatan</a>
                    <i class="fas fa-chevron-right text-xs text-slate-300 dark:text-zinc-600"></i>
                    <span class="text-slate-900 dark:text-white font-medium"><?= htmlspecialchars($kegiatanData['nama_kegiatan']) ?></span>
                </nav>

                <!-- Compact Header with Metrics -->
        <div class="bg-white dark:bg-zinc-900 rounded-xl border border-slate-200 dark:border-zinc-800 shadow-sm mb-6">
            <div class="px-6 py-4 border-b border-slate-100 dark:border-zinc-800">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <div class="flex items-center gap-3">
                        <a href="kegiatan.view.php" class="p-2 rounded-lg text-slate-400 dark:text-zinc-500 hover:bg-slate-100 dark:hover:bg-zinc-800 transition-colors">
                            <i class="fas fa-arrow-left"></i>
                        </a>
                        <div>
                            <h1 class="text-lg font-semibold text-slate-900 dark:text-white"><?= htmlspecialchars($kegiatanData['nama_kegiatan']) ?></h1>
                            <p class="text-sm text-slate-500 dark:text-zinc-400">Daftar Peserta Terdaftar</p>
                        </div>
                    </div>
                    <?php if ($totalPeserta > 0): ?>
                    <a href="?export=excel&kegiatan_id=<?= $kegiatan_id ?>&search=<?= urlencode($search) ?>&filter_kategori=<?= $filter_kategori ?>&filter_gender=<?= urlencode($filter_gender) ?>"
                       onclick="event.preventDefault(); const url = this.href; showConfirmModal('Export Data', 'Download daftar peserta ke Excel (.xlsx)?', () => window.location.href = url, 'info')"
                       class="inline-flex items-center gap-2 px-4 py-2 rounded-lg border border-slate-300 dark:border-zinc-700 text-slate-700 dark:text-zinc-300 text-sm font-medium hover:bg-slate-50 dark:hover:bg-zinc-800 transition-colors">
                        <i class="fas fa-file-excel text-emerald-600 dark:text-emerald-400"></i> Export Excel
                    </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Metrics Bar -->
            <div class="px-6 py-3 bg-slate-50 dark:bg-zinc-800/50 flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
                <div class="flex items-center gap-2">
                    <span class="text-2xl font-bold text-slate-900 dark:text-white"><?= $statistik['total'] ?></span>
                    <span class="text-slate-500 dark:text-zinc-400">Total</span>
                </div>
                <span class="text-slate-300 dark:text-zinc-600 hidden sm:inline">|</span>
                <div class="flex items-center gap-1.5">
                    <i class="fas fa-mars text-blue-500 text-xs"></i>
                    <span class="font-medium text-slate-700 dark:text-zinc-300"><?= $statistik['laki_laki'] ?></span>
                    <span class="text-slate-400 dark:text-zinc-500">Putra</span>
                </div>
                <div class="flex items-center gap-1.5">
                    <i class="fas fa-venus text-pink-500 text-xs"></i>
                    <span class="font-medium text-slate-700 dark:text-zinc-300"><?= $statistik['perempuan'] ?></span>
                    <span class="text-slate-400 dark:text-zinc-500">Putri</span>
                </div>
                <span class="text-slate-300 dark:text-zinc-600 hidden sm:inline">|</span>
                <div class="flex items-center gap-1.5">
                    <span class="text-emerald-600 dark:text-emerald-400">✓</span>
                    <span class="font-medium text-slate-700 dark:text-zinc-300"><?= $statistik['sudah_bayar'] ?></span>
                    <span class="text-slate-400 dark:text-zinc-500">Paid</span>
                </div>
                <?php if ($statistik['belum_bayar'] > 0): ?>
                <div class="flex items-center gap-1.5">
                    <span class="text-red-500 dark:text-red-400">✗</span>
                    <span class="font-medium text-red-600 dark:text-red-400"><?= $statistik['belum_bayar'] ?></span>
                    <span class="text-slate-400 dark:text-zinc-500">Unpaid</span>
                </div>
                <?php endif; ?>
                <span class="text-slate-300 dark:text-zinc-600 hidden sm:inline">|</span>
                <div class="flex items-center gap-1.5">
                    <span class="font-medium text-slate-700 dark:text-zinc-300"><?= count($statistik['kategori']) ?></span>
                    <span class="text-slate-400 dark:text-zinc-500">Kategori</span>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="bg-white dark:bg-zinc-900 rounded-xl border border-slate-200 dark:border-zinc-800 shadow-sm">
            <!-- Filter Bar - Compact -->
            <div class="px-6 py-4 border-b border-slate-100 dark:border-zinc-800">
                <form method="GET" action="">
                    <input type="hidden" name="kegiatan_id" value="<?= $kegiatan_id ?>">

                    <div class="flex flex-col lg:flex-row gap-3">
                        <div class="flex-1 flex flex-col sm:flex-row gap-3">
                            <div class="flex-1 min-w-0">
                                <input type="text" id="search" name="search"
                                    class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-zinc-700 text-sm focus:ring-2 focus:ring-archery-500 focus:border-archery-500 bg-slate-50 dark:bg-zinc-800 text-slate-900 dark:text-white placeholder-slate-400 dark:placeholder-zinc-500"
                                    placeholder="Cari nama, kota, club, sekolah..."
                                    value="<?= htmlspecialchars($search) ?>">
                            </div>
                            <select id="filter_kategori" name="filter_kategori" class="px-3 py-2 rounded-lg border border-slate-200 dark:border-zinc-700 text-sm focus:ring-2 focus:ring-archery-500 bg-slate-50 dark:bg-zinc-800 text-slate-900 dark:text-white min-w-[160px]">
                                <option value="">Semua Kategori</option>
                                <?php foreach ($kategoriesList as $kategori): ?>
                                <option value="<?= $kategori['id'] ?>" <?= $filter_kategori == $kategori['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($kategori['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <select id="filter_gender" name="filter_gender" class="px-3 py-2 rounded-lg border border-slate-200 dark:border-zinc-700 text-sm focus:ring-2 focus:ring-archery-500 bg-slate-50 dark:bg-zinc-800 text-slate-900 dark:text-white min-w-[120px]">
                                <option value="">Semua Gender</option>
                                <option value="Laki-laki" <?= $filter_gender == 'Laki-laki' ? 'selected' : '' ?>>Laki-laki</option>
                                <option value="Perempuan" <?= $filter_gender == 'Perempuan' ? 'selected' : '' ?>>Perempuan</option>
                            </select>
                        </div>
                        <div class="flex gap-2">
                            <button type="submit" class="px-4 py-2 rounded-lg bg-archery-600 text-white text-sm font-medium hover:bg-archery-700 transition-colors">
                                <i class="fas fa-search mr-1.5"></i> Filter
                            </button>
                            <?php if (canInputScore()): ?>
                            <a href="#" id="inputBtn" class="btn-input <?= $filter_kategori > 0 ? 'show' : '' ?> px-3 py-2 rounded-lg border border-amber-400 dark:border-amber-600 text-amber-600 dark:text-amber-400 text-sm font-medium hover:bg-amber-50 dark:hover:bg-amber-900/30 transition-colors"
                                onclick="goToInput(event)">
                                <i class="fas fa-edit mr-1"></i> Input
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Desktop Table View -->
            <div class="hidden md:block">
                <?php if ($totalPeserta > 0): ?>
                <div class="overflow-x-auto custom-scrollbar" style="max-height: 65vh;">
                    <table class="w-full">
                        <thead class="bg-slate-100 dark:bg-zinc-800 sticky top-0 z-10">
                            <tr>
                                <th class="px-3 py-3 text-left text-xs font-semibold text-slate-600 dark:text-zinc-400 uppercase tracking-wider w-12">#</th>
                                <th class="px-3 py-3 text-left text-xs font-semibold text-slate-600 dark:text-zinc-400 uppercase tracking-wider">Nama</th>
                                <th class="px-3 py-3 text-left text-xs font-semibold text-slate-600 dark:text-zinc-400 uppercase tracking-wider w-16">Umur</th>
                                <th class="px-3 py-3 text-left text-xs font-semibold text-slate-600 dark:text-zinc-400 uppercase tracking-wider w-16">L/P</th>
                                <th class="px-3 py-3 text-left text-xs font-semibold text-slate-600 dark:text-zinc-400 uppercase tracking-wider">Kategori</th>
                                <th class="px-3 py-3 text-left text-xs font-semibold text-slate-600 dark:text-zinc-400 uppercase tracking-wider">Kota</th>
                                <th class="px-3 py-3 text-left text-xs font-semibold text-slate-600 dark:text-zinc-400 uppercase tracking-wider">Club</th>
                                <th class="px-3 py-3 text-left text-xs font-semibold text-slate-600 dark:text-zinc-400 uppercase tracking-wider">Sekolah</th>
                                <th class="px-3 py-3 text-left text-xs font-semibold text-slate-600 dark:text-zinc-400 uppercase tracking-wider w-14">Kelas</th>
                                <th class="px-3 py-3 text-left text-xs font-semibold text-slate-600 dark:text-zinc-400 uppercase tracking-wider">No. HP</th>
                                <th class="px-3 py-3 text-center text-xs font-semibold text-slate-600 dark:text-zinc-400 uppercase tracking-wider w-14">Bayar</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-zinc-800 bg-white dark:bg-zinc-900">
                            <?php foreach ($pesertaListPaginated as $index => $peserta): ?>
                            <tr class="hover:bg-slate-50 dark:hover:bg-zinc-800 transition-colors">
                                <td class="px-3 py-2.5 text-sm text-slate-400 dark:text-zinc-500"><?= $offset + $index + 1 ?></td>
                                <td class="px-3 py-2.5">
                                    <p class="font-semibold text-slate-900 dark:text-white"><?= htmlspecialchars($peserta['nama_peserta']) ?></p>
                                </td>
                                <td class="px-3 py-2.5 text-sm text-slate-600 dark:text-zinc-400"><?= $peserta['umur'] ?></td>
                                <td class="px-3 py-2.5">
                                    <span class="inline-flex items-center justify-center w-6 h-6 rounded-full text-xs font-medium <?= $peserta['jenis_kelamin'] == 'Laki-laki' ? 'bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400' : 'bg-pink-50 dark:bg-pink-900/30 text-pink-600 dark:text-pink-400' ?>">
                                        <?= $peserta['jenis_kelamin'] == 'Laki-laki' ? 'L' : 'P' ?>
                                    </span>
                                </td>
                                <td class="px-3 py-2.5">
                                    <span class="px-2 py-0.5 rounded text-xs font-medium bg-slate-100 dark:bg-zinc-800 text-slate-700 dark:text-zinc-300"><?= htmlspecialchars($peserta['category_name']) ?></span>
                                </td>
                                <td class="px-3 py-2.5 text-sm text-slate-600 dark:text-zinc-400"><?= htmlspecialchars($peserta['asal_kota'] ?: '-') ?></td>
                                <td class="px-3 py-2.5 text-sm text-slate-600 dark:text-zinc-400 max-w-28 truncate"><?= htmlspecialchars($peserta['nama_club'] ?: '-') ?></td>
                                <td class="px-3 py-2.5 text-sm text-slate-600 dark:text-zinc-400 max-w-28 truncate"><?= htmlspecialchars($peserta['sekolah'] ?: '-') ?></td>
                                <td class="px-3 py-2.5 text-sm text-slate-600 dark:text-zinc-400"><?= htmlspecialchars($peserta['kelas'] ?: '-') ?></td>
                                <td class="px-3 py-2.5">
                                    <a href="tel:<?= htmlspecialchars($peserta['nomor_hp'] ?? '') ?>" class="text-sm text-slate-600 dark:text-zinc-400 hover:text-archery-600 dark:hover:text-archery-400"><?= htmlspecialchars($peserta['nomor_hp'] ?? '-') ?></a>
                                </td>
                                <td class="px-3 py-2.5 text-center">
                                    <?php if (!empty($peserta['bukti_pembayaran'])): ?>
                                    <button class="payment-icon text-emerald-600 dark:text-emerald-400 hover:text-emerald-700 dark:hover:text-emerald-300" onclick="showPaymentModal('<?= htmlspecialchars($peserta['nama_peserta']) ?>', '<?= $peserta['bukti_pembayaran'] ?>')" title="Lihat bukti">
                                        <i class="fas fa-check-circle"></i>
                                    </button>
                                    <?php else: ?>
                                    <span class="text-red-400 dark:text-red-500" title="Belum bayar"><i class="fas fa-times-circle"></i></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <!-- Pagination Footer -->
                <div class="px-4 py-3 bg-white dark:bg-zinc-900 border-t border-slate-100 dark:border-zinc-800 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                    <div class="text-sm text-slate-500 dark:text-zinc-400">
                        Menampilkan <span class="font-medium text-slate-900 dark:text-white"><?= $offset + 1 ?></span> - <span class="font-medium text-slate-900 dark:text-white"><?= min($offset + $limit, $total_rows) ?></span> dari <span class="font-medium text-slate-900 dark:text-white"><?= $total_rows ?></span> peserta
                        <?php if (!empty($search) || $filter_kategori > 0 || !empty($filter_gender)): ?>
                        <span class="text-slate-400 dark:text-zinc-500">• filtered</span>
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
                        <span class="p-2 text-slate-300 dark:text-zinc-600"><i class="fas fa-angles-left text-xs"></i></span>
                        <span class="p-2 text-slate-300 dark:text-zinc-600"><i class="fas fa-angle-left text-xs"></i></span>
                        <?php endif; ?>

                        <!-- Page Numbers -->
                        <?php
                        $start_page = max(1, $page - 2);
                        $end_page = min($total_pages, $page + 2);

                        if ($start_page > 1): ?>
                        <a href="<?= buildPaginationUrl(1) ?>" class="px-3 py-1.5 rounded-md text-sm text-slate-600 dark:text-zinc-400 hover:bg-slate-100 dark:hover:bg-zinc-800 transition-colors">1</a>
                        <?php if ($start_page > 2): ?><span class="px-1 text-slate-400 dark:text-zinc-500">...</span><?php endif; ?>
                        <?php endif;

                        for ($i = $start_page; $i <= $end_page; $i++): ?>
                        <a href="<?= buildPaginationUrl($i) ?>" class="px-3 py-1.5 rounded-md text-sm font-medium transition-colors <?= $i === $page ? 'bg-archery-600 text-white' : 'text-slate-600 dark:text-zinc-400 hover:bg-slate-100 dark:hover:bg-zinc-800' ?>"><?= $i ?></a>
                        <?php endfor;

                        if ($end_page < $total_pages): ?>
                        <?php if ($end_page < $total_pages - 1): ?><span class="px-1 text-slate-400 dark:text-zinc-500">...</span><?php endif; ?>
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
                        <span class="p-2 text-slate-300 dark:text-zinc-600"><i class="fas fa-angle-right text-xs"></i></span>
                        <span class="p-2 text-slate-300 dark:text-zinc-600"><i class="fas fa-angles-right text-xs"></i></span>
                        <?php endif; ?>
                    </nav>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <div class="py-12 text-center">
                    <div class="w-16 h-16 rounded-full bg-slate-100 dark:bg-zinc-800 flex items-center justify-center mx-auto mb-3">
                        <i class="fas fa-inbox text-slate-400 dark:text-zinc-500 text-2xl"></i>
                    </div>
                    <?php if (!empty($search) || $filter_kategori > 0 || !empty($filter_gender)): ?>
                    <p class="text-slate-500 dark:text-zinc-400 font-medium">Tidak ada peserta yang sesuai filter</p>
                    <a href="?kegiatan_id=<?= $kegiatan_id ?>" class="inline-flex items-center gap-2 mt-3 px-4 py-2 rounded-lg bg-slate-200 dark:bg-zinc-700 text-slate-700 dark:text-zinc-300 text-sm font-medium hover:bg-slate-300 dark:hover:bg-zinc-600 transition-colors">
                        <i class="fas fa-redo"></i> Reset Filter
                    </a>
                    <?php else: ?>
                    <p class="text-slate-500 dark:text-zinc-400 font-medium">Belum ada peserta terdaftar</p>
                    <a href="peserta.view.php?add_peserta=1&kegiatan_id=<?= $kegiatan_id ?>" class="inline-flex items-center gap-2 mt-3 px-4 py-2 rounded-lg bg-archery-600 text-white text-sm font-medium hover:bg-archery-700 transition-colors">
                        <i class="fas fa-plus"></i> Daftarkan Peserta
                    </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Mobile Card View -->
            <div class="md:hidden space-y-3 p-4">
                <?php if ($totalPeserta > 0): ?>
                <?php foreach ($pesertaListPaginated as $index => $peserta): ?>
                <div class="bg-white dark:bg-zinc-800 rounded-lg border border-slate-200 dark:border-zinc-700 p-4">
                    <div class="flex items-start gap-3 mb-3">
                        <span class="text-sm text-slate-400 dark:text-zinc-500 font-medium w-6"><?= $offset + $index + 1 ?></span>
                        <div class="flex-1 min-w-0">
                            <p class="font-semibold text-slate-900 dark:text-white"><?= htmlspecialchars($peserta['nama_peserta']) ?></p>
                            <div class="flex items-center gap-2 mt-1">
                                <span class="inline-flex items-center justify-center w-5 h-5 rounded-full text-xs <?= $peserta['jenis_kelamin'] == 'Laki-laki' ? 'bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400' : 'bg-pink-50 dark:bg-pink-900/30 text-pink-600 dark:text-pink-400' ?>">
                                    <?= $peserta['jenis_kelamin'] == 'Laki-laki' ? 'L' : 'P' ?>
                                </span>
                                <span class="text-sm text-slate-500 dark:text-zinc-400"><?= $peserta['umur'] ?> th</span>
                                <span class="text-slate-300 dark:text-zinc-600">•</span>
                                <span class="px-2 py-0.5 rounded text-xs font-medium bg-slate-100 dark:bg-zinc-700 text-slate-600 dark:text-zinc-300"><?= htmlspecialchars($peserta['category_name']) ?></span>
                            </div>
                        </div>
                        <div class="flex-shrink-0">
                            <?php if (!empty($peserta['bukti_pembayaran'])): ?>
                            <button class="text-emerald-600 dark:text-emerald-400" onclick="showPaymentModal('<?= htmlspecialchars($peserta['nama_peserta']) ?>', '<?= $peserta['bukti_pembayaran'] ?>')">
                                <i class="fas fa-check-circle"></i>
                            </button>
                            <?php else: ?>
                            <span class="text-red-400 dark:text-red-500"><i class="fas fa-times-circle"></i></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm border-t border-slate-100 dark:border-zinc-700 pt-3">
                        <div>
                            <span class="text-slate-400 dark:text-zinc-500">Kota:</span>
                            <span class="text-slate-700 dark:text-zinc-300 ml-1"><?= htmlspecialchars($peserta['asal_kota'] ?: '-') ?></span>
                        </div>
                        <div>
                            <span class="text-slate-400 dark:text-zinc-500">Kelas:</span>
                            <span class="text-slate-700 dark:text-zinc-300 ml-1"><?= htmlspecialchars($peserta['kelas'] ?: '-') ?></span>
                        </div>
                        <div class="truncate">
                            <span class="text-slate-400 dark:text-zinc-500">Club:</span>
                            <span class="text-slate-700 dark:text-zinc-300 ml-1"><?= htmlspecialchars($peserta['nama_club'] ?: '-') ?></span>
                        </div>
                        <div class="truncate">
                            <span class="text-slate-400 dark:text-zinc-500">Sekolah:</span>
                            <span class="text-slate-700 dark:text-zinc-300 ml-1"><?= htmlspecialchars($peserta['sekolah'] ?: '-') ?></span>
                        </div>
                    </div>
                    <div class="flex items-center justify-between mt-3 pt-3 border-t border-slate-100 dark:border-zinc-700">
                        <a href="tel:<?= htmlspecialchars($peserta['nomor_hp'] ?? '') ?>" class="inline-flex items-center gap-1.5 text-slate-600 dark:text-zinc-400 text-sm hover:text-archery-600 dark:hover:text-archery-400">
                            <i class="fas fa-phone text-xs"></i> <?= htmlspecialchars($peserta['nomor_hp'] ?? '-') ?>
                        </a>
                        <span class="text-xs text-slate-400 dark:text-zinc-500"><?= date('d/m/Y', strtotime($peserta['tanggal_lahir'])) ?></span>
                    </div>
                </div>
                <?php endforeach; ?>

                <!-- Mobile Pagination -->
                <?php if ($total_pages > 1): ?>
                <div class="bg-white dark:bg-zinc-800 rounded-lg border border-slate-200 dark:border-zinc-700 p-4 mt-4">
                    <div class="flex items-center justify-between">
                        <?php if ($page > 1): ?>
                        <a href="<?= buildPaginationUrl($page - 1) ?>" class="px-4 py-2 rounded-lg bg-slate-100 dark:bg-zinc-700 text-slate-600 dark:text-zinc-300 text-sm font-medium hover:bg-slate-200 dark:hover:bg-zinc-600 transition-colors">
                            <i class="fas fa-chevron-left mr-1"></i> Prev
                        </a>
                        <?php else: ?>
                        <span class="px-4 py-2 rounded-lg bg-slate-50 dark:bg-zinc-900 text-slate-300 dark:text-zinc-600 text-sm font-medium">
                            <i class="fas fa-chevron-left mr-1"></i> Prev
                        </span>
                        <?php endif; ?>

                        <span class="text-sm text-slate-500 dark:text-zinc-400">
                            <span class="font-medium text-slate-900 dark:text-white"><?= $page ?></span> / <?= $total_pages ?>
                        </span>

                        <?php if ($page < $total_pages): ?>
                        <a href="<?= buildPaginationUrl($page + 1) ?>" class="px-4 py-2 rounded-lg bg-archery-600 text-white text-sm font-medium hover:bg-archery-700 transition-colors">
                            Next <i class="fas fa-chevron-right ml-1"></i>
                        </a>
                        <?php else: ?>
                        <span class="px-4 py-2 rounded-lg bg-slate-50 dark:bg-zinc-900 text-slate-300 dark:text-zinc-600 text-sm font-medium">
                            Next <i class="fas fa-chevron-right ml-1"></i>
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                <?php else: ?>
                <div class="bg-white dark:bg-zinc-800 rounded-lg border border-slate-200 dark:border-zinc-700 p-8 text-center">
                    <div class="w-16 h-16 rounded-full bg-slate-100 dark:bg-zinc-700 flex items-center justify-center mx-auto mb-3">
                        <i class="fas fa-inbox text-slate-400 dark:text-zinc-500 text-2xl"></i>
                    </div>
                    <?php if (!empty($search) || $filter_kategori > 0 || !empty($filter_gender)): ?>
                    <p class="text-slate-500 dark:text-zinc-400 font-medium mb-3">Tidak ada peserta yang sesuai filter</p>
                    <a href="?kegiatan_id=<?= $kegiatan_id ?>" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-slate-200 dark:bg-zinc-700 text-slate-700 dark:text-zinc-300 text-sm font-medium">Reset Filter</a>
                    <?php else: ?>
                    <p class="text-slate-500 dark:text-zinc-400 font-medium mb-3">Belum ada peserta terdaftar</p>
                    <a href="peserta.view.php?add_peserta=1&kegiatan_id=<?= $kegiatan_id ?>" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-archery-600 text-white text-sm font-medium">Daftarkan Peserta</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Category Distribution -->
            <?php if (!empty($statistik['kategori'])): ?>
            <div class="mt-6 bg-slate-50 dark:bg-zinc-800/50 rounded-xl border border-slate-200 dark:border-zinc-800 p-5">
                <h4 class="font-semibold text-slate-900 dark:text-white mb-4 flex items-center gap-2">
                    <i class="fas fa-chart-pie text-archery-600 dark:text-archery-400"></i> Distribusi per Kategori
                </h4>
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
                    <?php foreach ($statistik['kategori'] as $kategori => $jumlah): ?>
                    <div class="bg-white dark:bg-zinc-800 rounded-lg p-3 text-center shadow-sm">
                        <p class="font-medium text-slate-700 dark:text-zinc-300 text-sm"><?= htmlspecialchars($kategori ?? '') ?></p>
                        <p class="text-lg font-bold text-archery-600 dark:text-archery-400"><?= $jumlah ?></p>
                        <p class="text-xs text-slate-400 dark:text-zinc-500">orang</p>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            </div>
        </main>
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
        <nav class="px-4 py-6 space-y-1 overflow-y-auto">
            <a href="dashboard.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800 transition-colors">
                <i class="fas fa-home w-5"></i><span class="text-sm">Dashboard</span>
            </a>

            <div class="pt-4">
                <p class="px-4 text-xs font-semibold text-zinc-500 uppercase tracking-wider mb-2">Master Data</p>
                <a href="users.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800 transition-colors">
                    <i class="fas fa-users w-5"></i><span class="text-sm">Users</span>
                </a>
                <a href="categori.view.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800 transition-colors">
                    <i class="fas fa-tags w-5"></i><span class="text-sm">Kategori</span>
                </a>
            </div>

            <div class="pt-4">
                <p class="px-4 text-xs font-semibold text-zinc-500 uppercase tracking-wider mb-2">Tournament</p>
                <a href="kegiatan.view.php" class="flex items-center gap-3 px-4 py-3 rounded-lg bg-archery-600/20 text-archery-400 border border-archery-600/30 transition-colors">
                    <i class="fas fa-calendar w-5"></i><span class="text-sm font-medium">Kegiatan</span>
                </a>
                <a href="peserta.view.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800 transition-colors">
                    <i class="fas fa-user-friends w-5"></i><span class="text-sm">Peserta</span>
                </a>
                <a href="statistik.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800 transition-colors">
                    <i class="fas fa-chart-bar w-5"></i><span class="text-sm">Statistik</span>
                </a>
            </div>

            <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
            <div class="pt-4">
                <p class="px-4 text-xs font-semibold text-zinc-500 uppercase tracking-wider mb-2">System</p>
                <a href="recovery.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800 transition-colors">
                    <i class="fas fa-trash-restore w-5"></i>
                    <span class="text-sm">Data Recovery</span>
                </a>
            </div>
            <?php endif; ?>
        </nav>
        <div class="px-4 py-4 border-t border-zinc-800 mt-auto">
            <div class="flex items-center gap-3 px-2">
                <div class="w-9 h-9 rounded-full bg-zinc-700 flex items-center justify-center">
                    <i class="fas fa-user text-zinc-400 text-sm"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium truncate"><?= htmlspecialchars($name ?? '') ?></p>
                    <p class="text-xs text-zinc-500 capitalize"><?= htmlspecialchars($role ?? '') ?></p>
                </div>
                <?= getThemeToggleButton() ?>
            </div>
            <a href="../actions/logout.php" onclick="event.preventDefault(); const url = this.href; showConfirmModal('Logout', 'Yakin ingin logout?', () => window.location.href = url, 'danger')"
               class="flex items-center gap-2 w-full mt-3 px-4 py-2 rounded-lg text-red-400 hover:bg-red-500/10 transition-colors text-sm">
                <i class="fas fa-sign-out-alt w-5"></i>
                <span>Logout</span>
            </a>
        </div>
    </div>

    <!-- Payment Modal - Tailwind styled -->
    <div id="paymentModal" class="modal">
        <div class="modal-content">
            <div class="bg-gradient-to-br from-archery-600 to-archery-800 text-white px-6 py-4 relative">
                <button class="modal-close" onclick="closePaymentModal()">&times;</button>
                <h3 id="modal-title" class="font-semibold text-lg pr-8">Bukti Pembayaran</h3>
            </div>
            <div class="p-6 text-center">
                <div id="modal-image-container"></div>
            </div>
        </div>
    </div>

    <?= getConfirmationModal() ?>
    <script>
        document.getElementById('filter_kategori').addEventListener('change', function () {
            updateInputButton();
        });

        document.getElementById('filter_gender').addEventListener('change', function () {
            updateInputButton();
        });

        document.getElementById('search').addEventListener('keypress', function (e) {
            if (e.key === 'Enter') {
                this.form.submit();
            }
        });

        document.getElementById('search').addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                this.value = '';
                this.form.submit();
            }
        });

        function updateInputButton() {
            const kategoriSelect = document.getElementById('filter_kategori');
            const inputBtn = document.getElementById('inputBtn');

            if (kategoriSelect.value && kategoriSelect.value !== '') {
                inputBtn.classList.add('show');
                inputBtn.href = 'detail.php?action=scorecard&resource=index&kegiatan_id=<?= $kegiatan_id ?>&category_id=' + kategoriSelect.value;
            } else {
                inputBtn.classList.remove('show');
            }
        }

        function goToInput(e) {
            const kategoriSelect = document.getElementById('filter_kategori');

            if (!kategoriSelect.value || kategoriSelect.value === '') {
                e.preventDefault();
                alert('Silakan pilih kategori terlebih dahulu!');
                return false;
            }

            window.location.href = 'detail.php?action=scorecard&resource=index&kegiatan_id=<?= $kegiatan_id ?>&category_id=' + kategoriSelect.value;
        }

        document.addEventListener('DOMContentLoaded', function () {
            updateInputButton();
        });

        function showPaymentModal(namaPeserta, fileName) {
            const modal = document.getElementById('paymentModal');
            const modalTitle = document.getElementById('modal-title');
            const imageContainer = document.getElementById('modal-image-container');

            modalTitle.textContent = 'Bukti Pembayaran - ' + namaPeserta;

            const fileExtension = fileName.toLowerCase().split('.').pop();
            const imagePath = '../assets/uploads/pembayaran/' + fileName;

            if (['jpg', 'jpeg', 'png', 'gif'].includes(fileExtension)) {
                imageContainer.innerHTML = `
                    <img src="${imagePath}" alt="Bukti Pembayaran" style="max-width: 100%; max-height: 500px; border-radius: 8px;">
                    <div style="margin-top: 15px; padding: 10px; background: #f8f9fa; border-radius: 6px; font-size: 14px; color: #666;">
                        <strong>File:</strong> ${fileName}<br>
                        <strong>Peserta:</strong> ${namaPeserta}
                    </div>
                `;
            } else if (fileExtension === 'pdf') {
                imageContainer.innerHTML = `
                    <div style="text-align: center; padding: 40px;">
                        <div style="font-size: 48px; color: #dc3545; margin-bottom: 20px;">📄</div>
                        <h4>File PDF</h4>
                        <p style="margin: 15px 0; color: #666;">File bukti pembayaran dalam format PDF</p>
                        <a href="${imagePath}" target="_blank" class="btn btn-primary" style="margin: 10px;">Buka PDF</a>
                        <a href="${imagePath}" download="${fileName}" class="btn btn-success" style="margin: 10px;">Download</a>
                        <div style="margin-top: 20px; padding: 10px; background: #f8f9fa; border-radius: 6px; font-size: 14px; color: #666;">
                            <strong>File:</strong> ${fileName}<br>
                            <strong>Peserta:</strong> ${namaPeserta}
                        </div>
                    </div>
                `;
            } else {
                imageContainer.innerHTML = `
                    <div style="text-align: center; padding: 40px;">
                        <div style="font-size: 48px; color: #ffc107; margin-bottom: 20px;">⚠️</div>
                        <h4>File tidak dapat ditampilkan</h4>
                        <p style="margin: 15px 0; color: #666;">Format file tidak didukung untuk preview</p>
                        <a href="${imagePath}" target="_blank" class="btn btn-primary" style="margin: 10px;">Buka File</a>
                        <a href="${imagePath}" download="${fileName}" class="btn btn-success" style="margin: 10px;">Download</a>
                        <div style="margin-top: 20px; padding: 10px; background: #f8f9fa; border-radius: 6px; font-size: 14px; color: #666;">
                            <strong>File:</strong> ${fileName}<br>
                            <strong>Peserta:</strong> ${namaPeserta}
                        </div>
                    </div>
                `;
            }

            modal.style.display = 'block';
        }

        function closePaymentModal() {
            document.getElementById('paymentModal').style.display = 'none';
        }

        window.onclick = function (event) {
            const modal = document.getElementById('paymentModal');
            if (event.target == modal) {
                modal.style.display = 'none';
            }
        }

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closePaymentModal();
            }
        });

        // Mobile menu functionality
        const mobileMenuBtn = document.getElementById('mobile-menu-btn');
        const mobileOverlay = document.getElementById('mobile-overlay');
        const mobileSidebar = document.getElementById('mobile-sidebar');
        const closeMobileMenu = document.getElementById('close-mobile-menu');

        function openMobileMenu() {
            mobileOverlay.classList.remove('hidden');
            mobileSidebar.classList.remove('-translate-x-full');
            document.body.style.overflow = 'hidden';
        }

        function closeMobileMenuFn() {
            mobileOverlay.classList.add('hidden');
            mobileSidebar.classList.add('-translate-x-full');
            document.body.style.overflow = '';
        }

        mobileMenuBtn.addEventListener('click', openMobileMenu);
        mobileOverlay.addEventListener('click', closeMobileMenuFn);
        closeMobileMenu.addEventListener('click', closeMobileMenuFn);

        // Theme Toggle
        <?= getThemeToggleScript() ?>
    </script>
    <?= getUiScripts() ?>
</body>

</html>
<?php
$conn->close();
?>