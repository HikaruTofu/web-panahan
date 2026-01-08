<?php
/**
 * Dashboard - Turnamen Panahan
 *
 * Dynamic ranking calculation from score table
 * UI: Intentional Minimalism with Tailwind CSS
 */

set_time_limit(300);
ini_set('memory_limit', '512M');
include '../config/panggil.php';
include '../includes/check_access.php';
include '../includes/theme.php';
requireAdmin();

if (!checkRateLimit('view_load', 60, 60)) {
    header('HTTP/1.1 429 Too Many Requests');
    die('Terlalu banyak permintaan. Silakan coba lagi nanti.');
}

$_GET = cleanInput($_GET);
$_POST = cleanInput($_POST);

if (!isset($_SESSION['login']) || $_SESSION['login'] !== true) {
    header('Location: ../index.php');
    exit;
}

$username = $_SESSION['username'] ?? 'User';
$name = $_SESSION['name'] ?? $username;
$role = $_SESSION['role'] ?? 'user';

// ============================================
// HELPER FUNCTIONS
// ============================================

function getKategoriFromRanking(int $ranking, int $totalPeserta): array
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

function getKategoriDominan(array $rankings): array
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

// ============================================
// DYNAMIC RANKING CALCULATION FROM SCORE TABLE
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

$dataError = null;
$atletBerprestasi = [];
$atletKurangPrestasi = [];
$totalAtlet = 0;
$totalClub = 0;
$daftarAtlet = [];
$daftarClub = [];

try {
    // 1. Count unique athletes (Filtered by kegiatan if not all)
    $whereAtlet = ($kegiatan_id !== 'all') ? " WHERE kegiatan_id = $kegiatan_id" : "";
    $queryTotalAtlet = "SELECT DISTINCT nama_peserta FROM peserta $whereAtlet ORDER BY nama_peserta ASC";
    $resultTotalAtlet = $conn->query($queryTotalAtlet);
    if ($resultTotalAtlet) {
        while ($row = $resultTotalAtlet->fetch_assoc()) {
            $daftarAtlet[] = $row['nama_peserta'];
        }
        $totalAtlet = count($daftarAtlet);
    }

    // 2. Count unique clubs (Filtered by kegiatan if not all)
    $whereClub = ($kegiatan_id !== 'all') ? " AND kegiatan_id = $kegiatan_id" : "";
    $queryTotalClub = "SELECT DISTINCT nama_club FROM peserta WHERE (nama_club IS NOT NULL AND nama_club != '') $whereClub ORDER BY nama_club ASC";
    $resultTotalClub = $conn->query($queryTotalClub);
    if ($resultTotalClub) {
        while ($row = $resultTotalClub->fetch_assoc()) {
            $daftarClub[] = $row['nama_club'];
        }
        $totalClub = count($daftarClub);
    }

    // 3. DYNAMIC RANKING: Hybrid system with Activity Filtering
    $keg_filter_scores = ($kegiatan_id !== 'all') ? " AND s.kegiatan_id = $kegiatan_id" : "";
    $keg_filter_official = ($kegiatan_id !== 'all') ? " WHERE kegiatan_id = $kegiatan_id" : "";
    $keg_filter_peserta = ($kegiatan_id !== 'all') ? " WHERE kegiatan_id = $kegiatan_id" : "";

    $queryDynamicRanking = "
        WITH 
        -- 1. Calculate Dynamic Rankings from score table as fallback
        ScoreStats AS (
            SELECT
                s.kegiatan_id,
                s.score_board_id,
                s.peserta_id,
                MAX(p.nama_peserta) as nama_peserta,
                SUM(CASE WHEN LOWER(s.score) = 'x' THEN 10 WHEN LOWER(s.score) = 'm' THEN 0 ELSE CAST(s.score AS UNSIGNED) END) as total_score,
                SUM(CASE WHEN LOWER(s.score) = 'x' THEN 1 ELSE 0 END) as total_x
            FROM score s
            JOIN peserta p ON s.peserta_id = p.id
            WHERE 1=1 $keg_filter_scores
            GROUP BY s.kegiatan_id, s.score_board_id, s.peserta_id
        ),
        CalculatedRanks AS (
            SELECT
                nama_peserta,
                kegiatan_id,
                RANK() OVER (PARTITION BY kegiatan_id, score_board_id ORDER BY total_score DESC, total_x DESC) as ranking,
                COUNT(*) OVER (PARTITION BY kegiatan_id, score_board_id) as board_participants
            FROM ScoreStats
        ),
        -- 2. Official Rankings from databaru.txt (rankings_source table) - Assumed Activity 11
        OfficialRanks AS (
            SELECT 
                nama_peserta COLLATE utf8mb4_general_ci as nama_peserta, 
                ranking, 
                total_participants as board_participants
            FROM rankings_source
            $keg_filter_official
        ),
        -- 3. Unified dataset: Merge Official (Act 11) + Calculated (Others)
        UnifiedRankings AS (
            SELECT nama_peserta, ranking, board_participants FROM OfficialRanks
            UNION ALL
            SELECT cr.nama_peserta COLLATE utf8mb4_general_ci as nama_peserta, cr.ranking, cr.board_participants 
            FROM CalculatedRanks cr
            WHERE cr.kegiatan_id != 11 
            OR NOT EXISTS (
                SELECT 1 FROM OfficialRanks orf 
                WHERE LOWER(TRIM(orf.nama_peserta)) = LOWER(TRIM(cr.nama_peserta COLLATE utf8mb4_general_ci))
            )
        ),
        -- 4. Deduplicated Participant Details to prevent win count multiplication
        UniquePeserta AS (
            SELECT 
                LOWER(TRIM(nama_peserta)) as normalized_name,
                MAX(nama_peserta) as display_name,
                MAX(jenis_kelamin) as jenis_kelamin,
                MAX(nama_club) as nama_club
            FROM peserta
            $keg_filter_peserta
            GROUP BY normalized_name
        )
        SELECT
            MAX(COALESCE(up.display_name, ur.nama_peserta)) as nama_peserta,
            MAX(up.jenis_kelamin) as jenis_kelamin,
            MAX(up.nama_club) as nama_club,
            COUNT(*) as total_turnamen,
            AVG(ur.ranking) as avg_rank,
            SUM(CASE WHEN ur.ranking = 1 THEN 1 ELSE 0 END) as juara1,
            SUM(CASE WHEN ur.ranking = 2 THEN 1 ELSE 0 END) as juara2,
            SUM(CASE WHEN ur.ranking = 3 THEN 1 ELSE 0 END) as juara3,
            GROUP_CONCAT(ur.ranking) as all_ranks,
            GROUP_CONCAT(ur.board_participants) as all_participants
        FROM UnifiedRankings ur
        LEFT JOIN UniquePeserta up ON LOWER(TRIM(ur.nama_peserta COLLATE utf8mb4_general_ci)) = up.normalized_name
        GROUP BY COALESCE(up.normalized_name, LOWER(TRIM(ur.nama_peserta COLLATE utf8mb4_general_ci)))
    ";

    $resultStats = $conn->query($queryDynamicRanking);

    if ($resultStats) {
        while ($row = $resultStats->fetch_assoc()) {
            $ranks = explode(',', $row['all_ranks']);
            $participants = explode(',', $row['all_participants']);

            $rankings = [];
            for ($i = 0; $i < count($ranks); $i++) {
                $rankings[] = [
                    'ranking' => (int)$ranks[$i],
                    'total_peserta' => (int)($participants[$i] ?? 1)
                ];
            }

            $kategoriDominan = getKategoriDominan($rankings);

            $atletData = [
                'nama' => $row['nama_peserta'],
                'gender' => $row['jenis_kelamin'],
                'club' => $row['nama_club'],
                'kategori' => $kategoriDominan['kategori'],
                'kategori_label' => $kategoriDominan['label'],
                'kategori_icon' => $kategoriDominan['icon'],
                'kategori_color' => $kategoriDominan['color'],
                'total_turnamen' => $row['total_turnamen'],
                'avg_ranking' => round($row['avg_rank'], 2),
                'juara1' => (int)$row['juara1'],
                'juara2' => (int)$row['juara2'],
                'juara3' => (int)$row['juara3']
            ];

            // Category A & B = Berprestasi (as per requirements)
            if (in_array($kategoriDominan['kategori'], ['A', 'B'])) {
                $atletBerprestasi[] = $atletData;
            } elseif (in_array($kategoriDominan['kategori'], ['D', 'E'])) {
                $atletKurangPrestasi[] = $atletData;
            }
        }
    } else {
        $dataError = "Gagal mengambil data ranking: " . $conn->error;
    }

    // Sort athletes by achievements
    usort($atletBerprestasi, function ($a, $b) {
        if ($b['juara1'] != $a['juara1']) return $b['juara1'] - $a['juara1'];
        if ($b['juara2'] != $a['juara2']) return $b['juara2'] - $a['juara2'];
        return $b['juara3'] - $a['juara3'];
    });

    usort($atletKurangPrestasi, function ($a, $b) {
        $kategoriOrder = ['D' => 1, 'E' => 2];
        $aOrder = $kategoriOrder[$a['kategori']] ?? 3;
        $bOrder = $kategoriOrder[$b['kategori']] ?? 3;
        if ($aOrder != $bOrder) return $aOrder - $bOrder;
        return $b['avg_ranking'] - $a['avg_ranking'];
    });



} catch (Exception $e) {
    $dataError = "Error: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Turnamen Panahan</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script><?= getThemeTailwindConfig() ?></script>
    <script><?= getThemeInitScript() ?></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Skeleton Animation */
        @keyframes shimmer {
            0% { background-position: -200% 0; }
            100% { background-position: 200% 0; }
        }
        .skeleton {
            background: linear-gradient(90deg, #e2e8f0 25%, #f1f5f9 50%, #e2e8f0 75%);
            background-size: 200% 100%;
            animation: shimmer 1.5s infinite;
        }

        /* Smooth transitions */
        .card-enter {
            opacity: 0;
            transform: translateY(10px);
        }
        .card-enter-active {
            opacity: 1;
            transform: translateY(0);
            transition: opacity 0.3s ease, transform 0.3s ease;
        }

        /* Custom scrollbar */
        .custom-scrollbar::-webkit-scrollbar {
            width: 6px;
        }
        .custom-scrollbar::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 3px;
        }
        .custom-scrollbar::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 3px;
        }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }

        /* Dark mode scrollbar */
        .dark .custom-scrollbar::-webkit-scrollbar-track {
            background: #27272a;
        }
        .dark .custom-scrollbar::-webkit-scrollbar-thumb {
            background: #52525b;
        }
        .dark .custom-scrollbar::-webkit-scrollbar-thumb:hover {
            background: #71717a;
        }

        /* Dark mode skeleton */
        .dark .skeleton {
            background: linear-gradient(90deg, #3f3f46 25%, #52525b 50%, #3f3f46 75%);
            background-size: 200% 100%;
            animation: shimmer 1.5s infinite;
        }
    </style>
</head>
<body class="h-full bg-slate-50 dark:bg-zinc-950 transition-colors">
    <!-- Toast Container -->
    <div id="toast-container" class="fixed top-4 right-4 z-50 flex flex-col gap-2"></div>

    <div class="flex h-full">
        <!-- Sidebar -->
        <aside class="hidden lg:flex lg:flex-col w-72 bg-zinc-900 text-white">
            <!-- Logo -->
            <div class="flex items-center gap-3 px-6 py-5 border-b border-zinc-800">
                <div class="w-10 h-10 rounded-lg bg-archery-600 flex items-center justify-center">
                    <i class="fas fa-bullseye text-white"></i>
                </div>
                <div>
                    <h1 class="font-semibold text-sm">Turnamen Panahan</h1>
                    <p class="text-xs text-zinc-400">Management System</p>
                </div>
            </div>

            <!-- Navigation -->
            <nav class="flex-1 px-4 py-6 space-y-1">
                <a href="dashboard.php" class="flex items-center gap-3 px-4 py-3 rounded-lg bg-archery-600/20 text-archery-400 border border-archery-600/30">
                    <i class="fas fa-home w-5"></i>
                    <span class="text-sm font-medium">Dashboard</span>
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
                    <a href="statistik.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800 transition-colors">
                        <i class="fas fa-chart-bar w-5"></i>
                        <span class="text-sm">Statistik</span>
                    </a>
                </div>
            </nav>

            <!-- User Section -->
            <div class="px-4 py-4 border-t border-zinc-800">
                <div class="flex items-center gap-3 px-2">
                    <div class="w-9 h-9 rounded-full bg-zinc-700 flex items-center justify-center">
                        <i class="fas fa-user text-zinc-400 text-sm"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-medium truncate"><?= htmlspecialchars($name) ?></p>
                        <p class="text-xs text-zinc-500 capitalize"><?= htmlspecialchars($role) ?></p>
                    </div>
                    <!-- Theme Toggle -->
                    <?= getThemeToggleButton() ?>
                </div>
                <a href="../actions/logout.php"
                   onclick="return confirm('Yakin ingin logout?')"
                   class="flex items-center gap-2 w-full mt-3 px-4 py-2 rounded-lg text-red-400 hover:bg-red-500/10 transition-colors text-sm">
                    <i class="fas fa-sign-out-alt w-5"></i>
                    <span>Logout</span>
                </a>
            </div>
        </aside>

        <!-- Mobile Sidebar Toggle -->
        <button id="mobile-menu-btn" class="lg:hidden fixed top-4 left-4 z-50 p-2 rounded-lg bg-zinc-900 text-white shadow-lg">
            <i class="fas fa-bars"></i>
        </button>

        <!-- Main Content -->
        <main class="flex-1 overflow-auto">
            <div class="px-6 lg:px-8 py-6">
                <!-- Compact Header with Metrics -->
                <div class="bg-white dark:bg-zinc-900 rounded-xl border border-slate-200 dark:border-zinc-800 shadow-sm mb-6 transition-colors">
                    <div class="px-6 py-4 border-b border-slate-100 dark:border-zinc-800">
                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                            <div>
                                <h1 class="text-lg font-semibold text-slate-900 dark:text-white">Dashboard</h1>
                                <p class="text-sm text-slate-500 dark:text-zinc-400">Ringkasan performa atlet dan statistik turnamen</p>
                            </div>
                            <div class="flex items-center gap-3">
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
                                <span class="hidden sm:inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-archery-50 dark:bg-archery-900/30 text-archery-700 dark:text-archery-400 text-sm font-medium">
                                    <span class="w-2 h-2 rounded-full bg-archery-500 animate-pulse"></span>
                                    Live
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- KPI Bar: 4 Core Metrics -->
                    <div class="px-6 py-4 grid grid-cols-2 lg:grid-cols-4 gap-6">
                        <!-- Total Atlet -->
                        <div class="flex items-start gap-4">
                            <div class="flex-1 min-w-0">
                                <p class="text-3xl font-bold text-slate-900 dark:text-white"><?= $totalAtlet ?></p>
                                <p class="text-sm font-medium text-slate-500 dark:text-zinc-400 mt-0.5">Total Atlet</p>
                                <p class="text-xs text-slate-400 dark:text-zinc-500 mt-1">Terdaftar dalam sistem</p>
                            </div>
                        </div>

                        <!-- Total Club -->
                        <div class="flex items-start gap-4">
                            <div class="flex-1 min-w-0">
                                <p class="text-3xl font-bold text-slate-900 dark:text-white"><?= $totalClub ?></p>
                                <p class="text-sm font-medium text-slate-500 dark:text-zinc-400 mt-0.5">Total Club</p>
                                <p class="text-xs text-slate-400 dark:text-zinc-500 mt-1">Club aktif</p>
                            </div>
                        </div>

                        <!-- Atlet Berprestasi (A & B) -->
                        <div class="flex items-start gap-4">
                            <div class="flex-1 min-w-0">
                                <p class="text-3xl font-bold text-emerald-600 dark:text-emerald-400"><?= count($atletBerprestasi) ?></p>
                                <p class="text-sm font-medium text-slate-500 dark:text-zinc-400 mt-0.5">Atlet Berprestasi</p>
                                <p class="text-xs text-emerald-600 dark:text-emerald-400 mt-1">Kategori A & B</p>
                            </div>
                        </div>

                        <!-- Perlu Peningkatan (D & E) -->
                        <div class="flex items-start gap-4">
                            <div class="flex-1 min-w-0">
                                <p class="text-3xl font-bold text-amber-600 dark:text-amber-400"><?= count($atletKurangPrestasi) ?></p>
                                <p class="text-sm font-medium text-slate-500 dark:text-zinc-400 mt-0.5">Perlu Peningkatan</p>
                                <p class="text-xs text-amber-600 dark:text-amber-400 mt-1">Kategori D & E</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Top 3 Hero Cards (Podium) -->
                <?php
                // Get top 3 athletes for podium display
                $top3 = array_slice($atletBerprestasi, 0, 3);
                $hasTop3 = count($top3) >= 3;
                ?>
                <?php if ($hasTop3): ?>
                <div class="mb-6">
                    <h3 class="text-sm font-semibold text-slate-500 dark:text-zinc-400 uppercase tracking-wider mb-4">Top 3 Atlet Berprestasi</h3>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <!-- 2nd Place (Silver) -->
                        <div class="bg-white dark:bg-zinc-900 rounded-xl border border-slate-200 dark:border-zinc-800 p-5 relative overflow-hidden order-2 md:order-1">
                            <div class="absolute top-0 right-0 w-16 h-16 bg-slate-100 dark:bg-zinc-800 rounded-bl-full flex items-end justify-start pb-2 pl-2">
                                <span class="text-xl">🥈</span>
                            </div>
                            <div class="flex items-center gap-3 mb-4">
                                <div class="w-12 h-12 rounded-full bg-gradient-to-br from-slate-300 to-slate-400 flex items-center justify-center text-white font-bold text-lg">
                                    <?= strtoupper(substr($top3[1]['nama'], 0, 1)) ?>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="font-semibold text-slate-900 dark:text-white truncate"><?= htmlspecialchars($top3[1]['nama']) ?></p>
                                    <p class="text-xs text-slate-500 dark:text-zinc-400 truncate"><?= htmlspecialchars($top3[1]['club'] ?: 'No Club') ?></p>
                                </div>
                            </div>
                            <div class="flex items-center gap-4 text-sm">
                                <?php if ($top3[1]['juara1'] > 0): ?>
                                    <span class="flex items-center gap-1 text-yellow-600 dark:text-yellow-400"><i class="fas fa-trophy text-xs"></i> <?= $top3[1]['juara1'] ?></span>
                                <?php endif; ?>
                                <?php if ($top3[1]['juara2'] > 0): ?>
                                    <span class="flex items-center gap-1 text-slate-500 dark:text-zinc-400"><i class="fas fa-medal text-xs"></i> <?= $top3[1]['juara2'] ?></span>
                                <?php endif; ?>
                                <span class="ml-auto px-2 py-0.5 rounded-full text-xs font-medium bg-slate-100 dark:bg-zinc-800 text-slate-600 dark:text-zinc-300">Kat. <?= $top3[1]['kategori'] ?></span>
                            </div>
                        </div>

                        <!-- 1st Place (Gold) - Featured -->
                        <div class="bg-gradient-to-br from-yellow-50 to-amber-50 dark:from-yellow-900/20 dark:to-amber-900/20 rounded-xl border-2 border-yellow-200 dark:border-yellow-700/50 p-5 relative overflow-hidden order-1 md:order-2 ring-2 ring-yellow-100 dark:ring-yellow-800/30">
                            <div class="absolute top-0 right-0 w-20 h-20 bg-yellow-100 dark:bg-yellow-800/30 rounded-bl-full flex items-end justify-start pb-3 pl-3">
                                <span class="text-2xl">🥇</span>
                            </div>
                            <div class="flex items-center gap-3 mb-4">
                                <div class="w-14 h-14 rounded-full bg-gradient-to-br from-yellow-400 to-amber-500 flex items-center justify-center text-white font-bold text-xl shadow-lg">
                                    <?= strtoupper(substr($top3[0]['nama'], 0, 1)) ?>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="font-bold text-slate-900 dark:text-white truncate text-lg"><?= htmlspecialchars($top3[0]['nama']) ?></p>
                                    <p class="text-sm text-slate-600 dark:text-zinc-400 truncate"><?= htmlspecialchars($top3[0]['club'] ?: 'No Club') ?></p>
                                </div>
                            </div>
                            <div class="flex items-center gap-4 text-sm">
                                <?php if ($top3[0]['juara1'] > 0): ?>
                                    <span class="flex items-center gap-1 text-yellow-700 dark:text-yellow-400 font-semibold"><i class="fas fa-trophy"></i> <?= $top3[0]['juara1'] ?> Emas</span>
                                <?php endif; ?>
                                <?php if ($top3[0]['juara2'] > 0): ?>
                                    <span class="flex items-center gap-1 text-slate-600 dark:text-zinc-400"><i class="fas fa-medal text-xs"></i> <?= $top3[0]['juara2'] ?></span>
                                <?php endif; ?>
                                <span class="ml-auto px-2 py-0.5 rounded-full text-xs font-semibold bg-yellow-200 dark:bg-yellow-700/50 text-yellow-800 dark:text-yellow-300">Kat. <?= $top3[0]['kategori'] ?></span>
                            </div>
                        </div>

                        <!-- 3rd Place (Bronze) -->
                        <div class="bg-white dark:bg-zinc-900 rounded-xl border border-slate-200 dark:border-zinc-800 p-5 relative overflow-hidden order-3">
                            <div class="absolute top-0 right-0 w-16 h-16 bg-amber-50 dark:bg-amber-900/20 rounded-bl-full flex items-end justify-start pb-2 pl-2">
                                <span class="text-xl">🥉</span>
                            </div>
                            <div class="flex items-center gap-3 mb-4">
                                <div class="w-12 h-12 rounded-full bg-gradient-to-br from-amber-400 to-orange-500 flex items-center justify-center text-white font-bold text-lg">
                                    <?= strtoupper(substr($top3[2]['nama'], 0, 1)) ?>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="font-semibold text-slate-900 dark:text-white truncate"><?= htmlspecialchars($top3[2]['nama']) ?></p>
                                    <p class="text-xs text-slate-500 dark:text-zinc-400 truncate"><?= htmlspecialchars($top3[2]['club'] ?: 'No Club') ?></p>
                                </div>
                            </div>
                            <div class="flex items-center gap-4 text-sm">
                                <?php if ($top3[2]['juara1'] > 0): ?>
                                    <span class="flex items-center gap-1 text-yellow-600 dark:text-yellow-400"><i class="fas fa-trophy text-xs"></i> <?= $top3[2]['juara1'] ?></span>
                                <?php endif; ?>
                                <?php if ($top3[2]['juara2'] > 0): ?>
                                    <span class="flex items-center gap-1 text-slate-500 dark:text-zinc-400"><i class="fas fa-medal text-xs"></i> <?= $top3[2]['juara2'] ?></span>
                                <?php endif; ?>
                                <span class="ml-auto px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400">Kat. <?= $top3[2]['kategori'] ?></span>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Two Column Layout for Lists -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <!-- Top Performing Athletes -->
                    <div class="bg-white dark:bg-zinc-900 rounded-xl border border-slate-200 dark:border-zinc-800 overflow-hidden">
                        <div class="px-5 py-4 border-b border-slate-100 dark:border-zinc-800 flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 rounded-lg bg-archery-50 dark:bg-archery-900/30 flex items-center justify-center">
                                    <i class="fas fa-trophy text-archery-600 dark:text-archery-400 text-sm"></i>
                                </div>
                                <div>
                                    <h3 class="font-semibold text-slate-900 dark:text-white">Atlet Berprestasi</h3>
                                    <p class="text-xs text-slate-500 dark:text-zinc-400">Kategori A & B - Top performers</p>
                                </div>
                            </div>
                            <span class="px-2.5 py-1 rounded-full bg-archery-50 dark:bg-archery-900/30 text-archery-700 dark:text-archery-400 text-xs font-medium">
                                <?= count($atletBerprestasi) ?> atlet
                            </span>
                        </div>
                        <div class="max-h-96 overflow-y-auto custom-scrollbar" id="prestasi-list">
                            <?php if (empty($atletBerprestasi)): ?>
                                <div class="p-8 text-center">
                                    <div class="w-16 h-16 rounded-full bg-slate-100 dark:bg-zinc-800 flex items-center justify-center mx-auto mb-3">
                                        <i class="fas fa-medal text-slate-400 dark:text-zinc-500 text-2xl"></i>
                                    </div>
                                    <p class="text-slate-500 dark:text-zinc-400 text-sm">Belum ada data atlet berprestasi</p>
                                    <p class="text-slate-400 dark:text-zinc-500 text-xs mt-1">Data akan muncul setelah turnamen berlangsung</p>
                                </div>
                            <?php else: ?>
                                <ul class="divide-y divide-slate-100 dark:divide-zinc-800">
                                    <?php foreach (array_slice($atletBerprestasi, 0, 200) as $index => $atlet): ?>
                                        <li class="px-5 py-3 hover:bg-slate-50 dark:hover:bg-zinc-800 transition-colors cursor-pointer group"
                                            onclick="showAthleteDetail('<?= htmlspecialchars($atlet['nama'], ENT_QUOTES) ?>')">
                                            <div class="flex items-center gap-3">
                                                <div class="w-8 h-8 rounded-full bg-gradient-to-br from-archery-500 to-archery-600 flex items-center justify-center text-white text-sm font-bold flex-shrink-0">
                                                    <?= $index + 1 ?>
                                                </div>
                                                <div class="flex-1 min-w-0">
                                                    <p class="font-medium text-slate-900 dark:text-white truncate group-hover:text-archery-600 dark:group-hover:text-archery-400 transition-colors">
                                                        <?= htmlspecialchars($atlet['nama']) ?>
                                                    </p>
                                                    <p class="text-xs text-slate-500 dark:text-zinc-400 truncate">
                                                        <?= htmlspecialchars($atlet['club'] ?: 'No Club') ?>
                                                    </p>
                                                </div>
                                                <div class="flex items-center gap-2 flex-shrink-0">
                                                    <?php if ($atlet['juara1'] > 0): ?>
                                                        <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full bg-yellow-50 dark:bg-yellow-900/30 text-yellow-700 dark:text-yellow-400 text-xs font-medium">
                                                            <i class="fas fa-trophy text-yellow-500"></i>
                                                            <?= $atlet['juara1'] ?>
                                                        </span>
                                                    <?php endif; ?>
                                                    <span class="px-2 py-1 rounded-full text-xs font-medium <?= $atlet['kategori'] === 'A' ? 'bg-emerald-50 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-400' : 'bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400' ?>">
                                                        Kat. <?= $atlet['kategori'] ?>
                                                    </span>
                                                </div>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                                <?php if (count($atletBerprestasi) > 20): ?>
                                    <div class="px-5 py-3 bg-slate-50 dark:bg-zinc-800 text-center">
                                        <a href="statistik.php?kategori=A" class="text-sm text-archery-600 dark:text-archery-400 hover:text-archery-700 dark:hover:text-archery-300 font-medium">
                                            Lihat semua <?= count($atletBerprestasi) ?> atlet <i class="fas fa-arrow-right ml-1"></i>
                                        </a>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Athletes Needing Improvement -->
                    <div class="bg-white dark:bg-zinc-900 rounded-xl border border-slate-200 dark:border-zinc-800 overflow-hidden">
                        <div class="px-5 py-4 border-b border-slate-100 dark:border-zinc-800 flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 rounded-lg bg-amber-50 dark:bg-amber-900/30 flex items-center justify-center">
                                    <i class="fas fa-chart-line text-amber-600 dark:text-amber-400 text-sm"></i>
                                </div>
                                <div>
                                    <h3 class="font-semibold text-slate-900 dark:text-white">Perlu Peningkatan</h3>
                                    <p class="text-xs text-slate-500 dark:text-zinc-400">Kategori D & E - Butuh latihan</p>
                                </div>
                            </div>
                            <span class="px-2.5 py-1 rounded-full bg-amber-50 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400 text-xs font-medium">
                                <?= count($atletKurangPrestasi) ?> atlet
                            </span>
                        </div>
                        <div class="max-h-96 overflow-y-auto custom-scrollbar" id="improve-list">
                            <?php if (empty($atletKurangPrestasi)): ?>
                                <div class="p-8 text-center">
                                    <div class="w-16 h-16 rounded-full bg-emerald-100 dark:bg-emerald-900/30 flex items-center justify-center mx-auto mb-3">
                                        <i class="fas fa-check-circle text-emerald-500 dark:text-emerald-400 text-2xl"></i>
                                    </div>
                                    <p class="text-slate-500 dark:text-zinc-400 text-sm">Semua atlet berprestasi!</p>
                                    <p class="text-slate-400 dark:text-zinc-500 text-xs mt-1">Tidak ada atlet di kategori D atau E</p>
                                </div>
                            <?php else: ?>
                                <ul class="divide-y divide-slate-100 dark:divide-zinc-800">
                                    <?php foreach (array_slice($atletKurangPrestasi, 0, 200) as $index => $atlet): ?>
                                        <li class="px-5 py-3 hover:bg-slate-50 dark:hover:bg-zinc-800 transition-colors cursor-pointer group"
                                            onclick="showAthleteDetail('<?= htmlspecialchars($atlet['nama'], ENT_QUOTES) ?>')">
                                            <div class="flex items-center gap-3">
                                                <div class="w-8 h-8 rounded-full bg-gradient-to-br from-amber-400 to-amber-500 flex items-center justify-center text-white text-sm font-bold flex-shrink-0">
                                                    <?= $index + 1 ?>
                                                </div>
                                                <div class="flex-1 min-w-0">
                                                    <p class="font-medium text-slate-900 dark:text-white truncate group-hover:text-amber-600 dark:group-hover:text-amber-400 transition-colors">
                                                        <?= htmlspecialchars($atlet['nama']) ?>
                                                    </p>
                                                    <p class="text-xs text-slate-500 dark:text-zinc-400 truncate">
                                                        <?= htmlspecialchars($atlet['club'] ?: 'No Club') ?>
                                                    </p>
                                                </div>
                                                <div class="flex items-center gap-2 flex-shrink-0">
                                                    <span class="text-xs text-slate-500 dark:text-zinc-400">
                                                        Avg #<?= $atlet['avg_ranking'] ?>
                                                    </span>
                                                    <span class="px-2 py-1 rounded-full text-xs font-medium <?= $atlet['kategori'] === 'D' ? 'bg-amber-50 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400' : 'bg-slate-100 dark:bg-zinc-800 text-slate-600 dark:text-zinc-400' ?>">
                                                        Kat. <?= $atlet['kategori'] ?>
                                                    </span>
                                                </div>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                                <?php if (count($atletKurangPrestasi) > 20): ?>
                                    <div class="px-5 py-3 bg-slate-50 dark:bg-zinc-800 text-center">
                                        <a href="statistik.php?kategori=D" class="text-sm text-amber-600 dark:text-amber-400 hover:text-amber-700 dark:hover:text-amber-300 font-medium">
                                            Lihat semua <?= count($atletKurangPrestasi) ?> atlet <i class="fas fa-arrow-right ml-1"></i>
                                        </a>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Bottom Row: Clubs & Athletes Full List -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mt-6">
                    <!-- All Athletes -->
                    <div class="bg-white dark:bg-zinc-900 rounded-xl border border-slate-200 dark:border-zinc-800 overflow-hidden">
                        <div class="px-5 py-4 border-b border-slate-100 dark:border-zinc-800 flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 rounded-lg bg-blue-50 dark:bg-blue-900/30 flex items-center justify-center">
                                    <i class="fas fa-users text-blue-600 dark:text-blue-400 text-sm"></i>
                                </div>
                                <h3 class="font-semibold text-slate-900 dark:text-white">Daftar Atlet</h3>
                            </div>
                            <a href="peserta.view.php" class="text-xs text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 font-medium">
                                Lihat Semua <i class="fas fa-arrow-right ml-1"></i>
                            </a>
                        </div>
                        <div class="max-h-64 overflow-y-auto custom-scrollbar">
                            <?php if (empty($daftarAtlet)): ?>
                                <div class="p-6 text-center text-slate-500 dark:text-zinc-400 text-sm">
                                    Belum ada atlet terdaftar
                                </div>
                            <?php else: ?>
                                <ul class="divide-y divide-slate-100 dark:divide-zinc-800">
                                    <?php foreach (array_slice($daftarAtlet, 0, 8) as $index => $namaAtlet): ?>
                                        <li class="px-5 py-2.5 hover:bg-slate-50 dark:hover:bg-zinc-800 transition-colors cursor-pointer"
                                            onclick="showAthleteDetail('<?= htmlspecialchars($namaAtlet, ENT_QUOTES) ?>')">
                                            <div class="flex items-center gap-3">
                                                <span class="w-6 h-6 rounded-full bg-slate-100 dark:bg-zinc-800 flex items-center justify-center text-xs text-slate-600 dark:text-zinc-400 font-medium">
                                                    <?= $index + 1 ?>
                                                </span>
                                                <span class="text-sm text-slate-700 dark:text-zinc-300"><?= htmlspecialchars($namaAtlet) ?></span>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- All Clubs -->
                    <div class="bg-white dark:bg-zinc-900 rounded-xl border border-slate-200 dark:border-zinc-800 overflow-hidden">
                        <div class="px-5 py-4 border-b border-slate-100 dark:border-zinc-800 flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 rounded-lg bg-emerald-50 dark:bg-emerald-900/30 flex items-center justify-center">
                                    <i class="fas fa-building text-emerald-600 dark:text-emerald-400 text-sm"></i>
                                </div>
                                <h3 class="font-semibold text-slate-900 dark:text-white">Daftar Club</h3>
                            </div>
                            <span class="px-2 py-1 rounded-full bg-emerald-50 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-400 text-xs font-medium">
                                <?= $totalClub ?> club
                            </span>
                        </div>
                        <div class="max-h-64 overflow-y-auto custom-scrollbar">
                            <?php if (empty($daftarClub)): ?>
                                <div class="p-6 text-center text-slate-500 dark:text-zinc-400 text-sm">
                                    Belum ada club terdaftar
                                </div>
                            <?php else: ?>
                                <ul class="divide-y divide-slate-100 dark:divide-zinc-800">
                                    <?php foreach (array_slice($daftarClub, 0, 8) as $index => $namaClub): ?>
                                        <li class="px-5 py-2.5 hover:bg-slate-50 dark:hover:bg-zinc-800 transition-colors cursor-pointer"
                                            onclick="showClubDetail('<?= htmlspecialchars($namaClub, ENT_QUOTES) ?>')">
                                            <div class="flex items-center gap-3">
                                                <span class="w-6 h-6 rounded-full bg-emerald-100 dark:bg-emerald-900/30 flex items-center justify-center text-xs text-emerald-700 dark:text-emerald-400 font-medium">
                                                    <?= $index + 1 ?>
                                                </span>
                                                <span class="text-sm text-slate-700 dark:text-zinc-300"><?= htmlspecialchars($namaClub) ?></span>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Mobile Sidebar Overlay -->
    <div id="mobile-overlay" class="fixed inset-0 bg-black/50 z-40 hidden lg:hidden"></div>

    <!-- Mobile Sidebar -->
    <div id="mobile-sidebar" class="fixed inset-y-0 left-0 w-72 bg-zinc-900 text-white z-50 transform -translate-x-full transition-transform lg:hidden">
        <!-- Same content as desktop sidebar -->
        <div class="flex items-center gap-3 px-6 py-5 border-b border-zinc-800">
            <div class="w-10 h-10 rounded-lg bg-archery-600 flex items-center justify-center">
                <i class="fas fa-bullseye text-white"></i>
            </div>
            <div>
                <h1 class="font-semibold text-sm">Turnamen Panahan</h1>
                <p class="text-xs text-zinc-400">Management System</p>
            </div>
            <button id="close-mobile-menu" class="ml-auto p-2 rounded-lg hover:bg-zinc-800">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <nav class="px-4 py-6 space-y-1">
            <a href="dashboard.php" class="flex items-center gap-3 px-4 py-3 rounded-lg bg-archery-600/20 text-archery-400">
                <i class="fas fa-home w-5"></i>
                <span class="text-sm font-medium">Dashboard</span>
            </a>
            <a href="users.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800">
                <i class="fas fa-users w-5"></i>
                <span class="text-sm">Users</span>
            </a>
            <a href="categori.view.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800">
                <i class="fas fa-tags w-5"></i>
                <span class="text-sm">Kategori</span>
            </a>
            <a href="kegiatan.view.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800">
                <i class="fas fa-calendar w-5"></i>
                <span class="text-sm">Kegiatan</span>
            </a>
            <a href="peserta.view.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800">
                <i class="fas fa-user-friends w-5"></i>
                <span class="text-sm">Peserta</span>
            </a>
            <a href="statistik.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800">
                <i class="fas fa-chart-bar w-5"></i>
                <span class="text-sm">Statistik</span>
            </a>
        </nav>
        <div class="absolute bottom-0 left-0 right-0 px-4 py-4 border-t border-zinc-800">
            <a href="../actions/logout.php" onclick="return confirm('Yakin ingin logout?')"
               class="flex items-center gap-2 w-full px-4 py-2 rounded-lg text-red-400 hover:bg-red-500/10 text-sm">
                <i class="fas fa-sign-out-alt w-5"></i>
                <span>Logout</span>
            </a>
        </div>
    </div>

    <!-- Athlete Detail Modal -->
    <div id="athlete-modal" class="fixed inset-0 z-50 hidden">
        <div class="absolute inset-0 bg-black/50" onclick="closeAthleteModal()"></div>
        <div class="absolute inset-4 sm:inset-auto sm:top-1/2 sm:left-1/2 sm:-translate-x-1/2 sm:-translate-y-1/2 sm:w-full sm:max-w-2xl bg-white dark:bg-zinc-900 rounded-2xl shadow-xl overflow-hidden">
            <div class="bg-gradient-to-br from-archery-600 to-archery-800 text-white px-6 py-4 flex items-center justify-between">
                <h3 class="font-semibold text-lg" id="modal-title">Detail Atlet</h3>
                <button onclick="closeAthleteModal()" class="p-2 rounded-lg hover:bg-white/10 transition-colors">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="p-6 max-h-[70vh] overflow-y-auto" id="modal-content">
                <!-- Skeleton Loader -->
                <div id="modal-skeleton" class="space-y-4">
                    <div class="skeleton h-6 w-48 rounded"></div>
                    <div class="skeleton h-4 w-32 rounded"></div>
                    <div class="grid grid-cols-3 gap-4 mt-6">
                        <div class="skeleton h-20 rounded-lg"></div>
                        <div class="skeleton h-20 rounded-lg"></div>
                        <div class="skeleton h-20 rounded-lg"></div>
                    </div>
                </div>
                <!-- Actual Content -->
                <div id="modal-data" class="hidden"></div>
            </div>
        </div>
    </div>

    <script>
        // Toast Notification System
        function showToast(message, type = 'info') {
            const container = document.getElementById('toast-container');
            const toast = document.createElement('div');

            const colors = {
                success: 'bg-emerald-500',
                error: 'bg-red-500',
                warning: 'bg-amber-500',
                info: 'bg-blue-500'
            };

            const icons = {
                success: 'fa-check-circle',
                error: 'fa-exclamation-circle',
                warning: 'fa-exclamation-triangle',
                info: 'fa-info-circle'
            };

            toast.className = `${colors[type]} text-white px-4 py-3 rounded-lg shadow-lg flex items-center gap-3 transform translate-x-full transition-transform duration-300`;
            toast.innerHTML = `
                <i class="fas ${icons[type]}"></i>
                <span class="text-sm font-medium">${message}</span>
                <button onclick="this.parentElement.remove()" class="ml-2 hover:opacity-70">
                    <i class="fas fa-times text-xs"></i>
                </button>
            `;

            container.appendChild(toast);

            // Animate in
            requestAnimationFrame(() => {
                toast.classList.remove('translate-x-full');
            });

            // Auto remove after 5 seconds
            setTimeout(() => {
                toast.classList.add('translate-x-full');
                setTimeout(() => toast.remove(), 300);
            }, 5000);
        }

        // Mobile Menu Toggle
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

        // Athlete Detail Modal
        function showAthleteDetail(athleteName) {
            const modal = document.getElementById('athlete-modal');
            const skeleton = document.getElementById('modal-skeleton');
            const dataContainer = document.getElementById('modal-data');
            const modalTitle = document.getElementById('modal-title');

            modal.classList.remove('hidden');
            skeleton.classList.remove('hidden');
            dataContainer.classList.add('hidden');
            modalTitle.textContent = 'Memuat...';

            // Fetch athlete data
            fetch('../actions/api/get_athlete_stats.php?name=' + encodeURIComponent(athleteName))
                .then(response => response.json())
                .then(data => {
                    skeleton.classList.add('hidden');
                    dataContainer.classList.remove('hidden');

                    if (data.success) {
                        modalTitle.textContent = athleteName;
                        dataContainer.innerHTML = renderAthleteData(data.data, athleteName);
                    } else {
                        dataContainer.innerHTML = `
                            <div class="text-center py-8">
                                <div class="w-16 h-16 rounded-full bg-red-100 flex items-center justify-center mx-auto mb-4">
                                    <i class="fas fa-exclamation-circle text-red-500 text-2xl"></i>
                                </div>
                                <p class="text-slate-600">${data.message || 'Data tidak ditemukan'}</p>
                            </div>
                        `;
                        showToast(data.message || 'Gagal memuat data atlet', 'error');
                    }
                })
                .catch(error => {
                    skeleton.classList.add('hidden');
                    dataContainer.classList.remove('hidden');
                    dataContainer.innerHTML = `
                        <div class="text-center py-8">
                            <div class="w-16 h-16 rounded-full bg-red-100 flex items-center justify-center mx-auto mb-4">
                                <i class="fas fa-exclamation-triangle text-red-500 text-2xl"></i>
                            </div>
                            <p class="text-slate-600">Terjadi kesalahan saat memuat data</p>
                        </div>
                    `;
                    showToast('Gagal terhubung ke server', 'error');
                });
        }

        function renderAthleteData(data, name) {
            return `
                <div class="space-y-6">
                    <!-- Profile -->
                    <div class="flex items-center gap-4">
                        <div class="w-14 h-14 rounded-full bg-gradient-to-br from-archery-500 to-archery-700 flex items-center justify-center text-white text-xl font-bold">
                            ${name.charAt(0).toUpperCase()}
                        </div>
                        <div>
                            <h4 class="font-semibold text-lg text-slate-900 dark:text-white">${name}</h4>
                            <p class="text-sm text-slate-500 dark:text-zinc-400">${data.club || 'No Club'}</p>
                        </div>
                    </div>

                    <!-- Stats Grid -->
                    <div class="grid grid-cols-4 gap-3">
                        <div class="bg-slate-50 dark:bg-zinc-800 rounded-lg p-3 text-center">
                            <p class="text-2xl font-bold text-slate-900 dark:text-white">${data.total_turnamen || 0}</p>
                            <p class="text-xs text-slate-500 dark:text-zinc-400">Turnamen</p>
                        </div>
                        <div class="bg-yellow-50 dark:bg-yellow-900/30 rounded-lg p-3 text-center">
                            <p class="text-2xl font-bold text-yellow-600 dark:text-yellow-400">${data.juara1 || 0}</p>
                            <p class="text-xs text-slate-500 dark:text-zinc-400">Juara 1</p>
                        </div>
                        <div class="bg-slate-100 dark:bg-zinc-700 rounded-lg p-3 text-center">
                            <p class="text-2xl font-bold text-slate-600 dark:text-zinc-300">${data.juara2 || 0}</p>
                            <p class="text-xs text-slate-500 dark:text-zinc-400">Juara 2</p>
                        </div>
                        <div class="bg-amber-50 dark:bg-amber-900/30 rounded-lg p-3 text-center">
                            <p class="text-2xl font-bold text-amber-600 dark:text-amber-400">${data.juara3 || 0}</p>
                            <p class="text-xs text-slate-500 dark:text-zinc-400">Juara 3</p>
                        </div>
                    </div>

                    <!-- Tournament History -->
                    ${data.tournaments && data.tournaments.length > 0 ? `
                        <div>
                            <h5 class="font-semibold text-slate-900 dark:text-white mb-3">Riwayat Turnamen</h5>
                            <div class="space-y-2 max-h-48 overflow-y-auto">
                                ${data.tournaments.map((t, i) => `
                                    <div class="flex items-center justify-between p-3 bg-slate-50 dark:bg-zinc-800 rounded-lg">
                                        <div class="flex items-center gap-3">
                                            <span class="w-8 h-8 rounded-full ${t.ranking <= 3 ? 'bg-yellow-100 dark:bg-yellow-900/40 text-yellow-700 dark:text-yellow-400' : 'bg-slate-200 dark:bg-zinc-700 text-slate-600 dark:text-zinc-400'} flex items-center justify-center text-sm font-bold">
                                                #${t.ranking}
                                            </span>
                                            <div>
                                                <p class="text-sm font-medium text-slate-900 dark:text-white">${t.nama_kegiatan || 'Turnamen'}</p>
                                                <p class="text-xs text-slate-500 dark:text-zinc-400">${t.category || ''} - ${t.total_peserta || 0} peserta</p>
                                            </div>
                                        </div>
                                    </div>
                                `).join('')}
                            </div>
                        </div>
                    ` : `
                        <div class="text-center py-4 text-slate-500 dark:text-zinc-400 text-sm">
                            Belum ada riwayat turnamen
                        </div>
                    `}
                </div>
            `;
        }

        function closeAthleteModal() {
            document.getElementById('athlete-modal').classList.add('hidden');
        }

        function showClubDetail(clubName) {
            showToast(`Memuat data club: ${clubName}`, 'info');
            // Could implement similar modal for club details
        }

        // Show error toast if there was a data error
        <?php if ($dataError): ?>
            showToast(<?= json_encode($dataError) ?>, 'error');
        <?php endif; ?>

        // Close modal on Escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                closeAthleteModal();
            }
        });

        // Theme Toggle Functionality
        <?= getThemeToggleScript() ?>
    </script>
</body>
</html>
