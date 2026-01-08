<?php
/**
 * Peserta Lomba - Tournament Participants Management
 * UI: Intentional Minimalism with Tailwind CSS
 */
include '../config/panggil.php';
include '../includes/check_access.php';
requireAdmin();

if (!checkRateLimit('view_load', 60, 60)) {
    header('HTTP/1.1 429 Too Many Requests');
    die('Terlalu banyak permintaan. Silakan coba lagi nanti.');
}

$_GET = cleanInput($_GET);
// Get tournament ID from URL
$tournament_id = isset($_GET['tournament_id']) ? intval($_GET['tournament_id']) : 0;

if (!$tournament_id) {
    die("ID Tournament tidak valid!");
}

// Get tournament information
$stmt = $conn->prepare("SELECT * FROM tournaments WHERE id = ?");
$stmt->bind_param("i", $tournament_id);
$stmt->execute();
$tournament_result = $stmt->get_result();
if (!$tournament_result || $tournament_result->num_rows == 0) {
    die("Tournament tidak ditemukan!");
}
$tournament = $tournament_result->fetch_assoc();
$stmt->close();

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!checkRateLimit('peserta_lomba_crud', 30, 60)) {
        header('HTTP/1.1 429 Too Many Requests');
        echo json_encode(['success' => false, 'message' => 'Terlalu banyak permintaan.']);
        exit;
    }
    verify_csrf();
    $_POST = cleanInput($_POST);
    header('Content-Type: application/json');

    if ($_POST['action'] === 'add_participant') {
        $participant_id = $_POST['participant_id'];
        $category_id = $_POST['category_id'];
        $payment_status = $_POST['payment_status'];
        $status = $_POST['status'];
        $notes = $_POST['notes'];

        // Check if participant already registered
        $check_sql = "SELECT id FROM tournament_participants WHERE tournament_id = ? AND participant_id = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("ii", $tournament_id, $participant_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();

        if ($check_result->num_rows > 0) {
            echo json_encode(['success' => false, 'message' => 'Peserta sudah terdaftar di tournament ini!']);
            exit;
        }

        $sql = "INSERT INTO tournament_participants (tournament_id, participant_id, category_id, payment_status, status, notes) VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iiisss", $tournament_id, $participant_id, $category_id, $payment_status, $status, $notes);

        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Peserta berhasil ditambahkan ke tournament']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Gagal menambah peserta: ' . $conn->error]);
        }
        exit;
    }

    if ($_POST['action'] === 'update_participant') {
        $tp_id = $_POST['tp_id'];
        $category_id = $_POST['category_id'];
        $payment_status = $_POST['payment_status'];
        $status = $_POST['status'];
        $notes = $_POST['notes'];
        $seed_number = $_POST['seed_number'];

        $sql = "UPDATE tournament_participants SET category_id=?, payment_status=?, status=?, notes=?, seed_number=? WHERE id=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("isssii", $category_id, $payment_status, $status, $notes, $seed_number, $tp_id);

        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Data peserta berhasil diupdate']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Gagal update data: ' . $conn->error]);
        }
        exit;
    }

    if ($_POST['action'] === 'remove_participant') {
        $tp_id = $_POST['tp_id'];
        $sql = "DELETE FROM tournament_participants WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $tp_id);

        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Peserta berhasil dihapus dari tournament']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Gagal menghapus peserta: ' . $conn->error]);
        }
        exit;
    }

    if ($_POST['action'] === 'get_participant') {
        $tp_id = $_POST['tp_id'];
        $sql = "SELECT tp.*, p.name as participant_name, c.name as category_name
                FROM tournament_participants tp
                LEFT JOIN participants p ON tp.participant_id = p.id
                LEFT JOIN categories c ON tp.category_id = c.id
                WHERE tp.id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $tp_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->fetch_assoc();
        echo json_encode($data);
        exit;
    }
}

// Get available participants (not yet registered in this tournament)
$stmt = $conn->prepare("SELECT p.* FROM participants p
                      WHERE p.status = 'active'
                      AND p.id NOT IN (SELECT participant_id FROM tournament_participants WHERE tournament_id = ?)
                      ORDER BY p.name");
$stmt->bind_param("i", $tournament_id);
$stmt->execute();
$available_participants = $stmt->get_result();
$stmt->close();

// Get available categories
$categories = $conn->query("SELECT * FROM categories WHERE status = 'active' ORDER BY name");

// ============================================
// PAGINATION LOGIC
// ============================================
$limit = 50;
$page = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
$offset = ($page - 1) * $limit;

// Get registered participants
$search = isset($_GET['q']) ? $_GET['q'] : '';

// Build search criteria
$search_like = $search ? "%$search%" : "%";

// Count total for pagination
$count_stmt = $conn->prepare("SELECT COUNT(*) as total
              FROM tournament_participants tp
              LEFT JOIN participants p ON tp.participant_id = p.id
              LEFT JOIN categories c ON tp.category_id = c.id
              WHERE tp.tournament_id = ? AND (p.name LIKE ? OR c.name LIKE ?)");
$count_stmt->bind_param("iss", $tournament_id, $search_like, $search_like);
$count_stmt->execute();
$total_rows = $count_stmt->get_result()->fetch_assoc()['total'];
$count_stmt->close();
$total_pages = ceil($total_rows / $limit);

// Main query with LIMIT
$participants_stmt = $conn->prepare("SELECT tp.*, p.name as participant_name, p.birthdate, p.gender, p.phone,
                    c.name as category_name, YEAR(CURDATE()) - YEAR(p.birthdate) as age
                    FROM tournament_participants tp
                    LEFT JOIN participants p ON tp.participant_id = p.id
                    LEFT JOIN categories c ON tp.category_id = c.id
                    WHERE tp.tournament_id = ? AND (p.name LIKE ? OR c.name LIKE ?)
                    ORDER BY tp.registration_date DESC
                    LIMIT ? OFFSET ?");
$participants_stmt->bind_param("issii", $tournament_id, $search_like, $search_like, $limit, $offset);
$participants_stmt->execute();
$participants_result = $participants_stmt->get_result();
$participants_stmt->close();

// Helper function to build pagination URL preserving GET params
function buildPaginationUrl($page, $params = []) {
    $current = $_GET;
    $current['p'] = $page;
    foreach ($params as $key => $value) {
        $current[$key] = $value;
    }
    return '?' . http_build_query($current);
}

// Calculate statistics for metrics bar
$stats_stmt = $conn->prepare("SELECT
    COUNT(*) as total,
    SUM(CASE WHEN p.gender = 'Laki-laki' THEN 1 ELSE 0 END) as putra,
    SUM(CASE WHEN p.gender = 'Perempuan' THEN 1 ELSE 0 END) as putri,
    SUM(CASE WHEN tp.payment_status = 'paid' THEN 1 ELSE 0 END) as paid,
    SUM(CASE WHEN tp.payment_status = 'pending' THEN 1 ELSE 0 END) as pending,
    SUM(CASE WHEN tp.status = 'confirmed' THEN 1 ELSE 0 END) as confirmed,
    COUNT(DISTINCT tp.category_id) as kategori_count
    FROM tournament_participants tp
    LEFT JOIN participants p ON tp.participant_id = p.id
    WHERE tp.tournament_id = ?");
$stats_stmt->bind_param("i", $tournament_id);
$stats_stmt->execute();
$stats = $stats_stmt->get_result()->fetch_assoc();
$stats_stmt->close();

$username = $_SESSION['username'] ?? 'User';
$name = $_SESSION['name'] ?? $username;
$role = $_SESSION['role'] ?? 'user';
?>
<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Peserta Lomba - <?= htmlspecialchars($tournament['name']); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        'archery': {
                            50: '#f0fdf4', 100: '#dcfce7', 200: '#bbf7d0', 300: '#86efac',
                            400: '#4ade80', 500: '#22c55e', 600: '#16a34a', 700: '#15803d',
                            800: '#166534', 900: '#14532d',
                        }
                    }
                }
            }
        }
    </script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .custom-scrollbar::-webkit-scrollbar { width: 6px; height: 6px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 3px; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        .modal-backdrop { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 50; }
        .modal-backdrop.active { display: flex; align-items: center; justify-content: center; }
    </style>
</head>
<body class="h-full bg-slate-50">
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
                    <a href="kegiatan.view.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800 transition-colors">
                        <i class="fas fa-calendar w-5"></i>
                        <span class="text-sm">Kegiatan</span>
                    </a>
                    <a href="peserta.view.php" class="flex items-center gap-3 px-4 py-3 rounded-lg bg-archery-600/20 text-archery-400 border border-archery-600/30">
                        <i class="fas fa-user-friends w-5"></i>
                        <span class="text-sm font-medium">Peserta</span>
                    </a>
                    <a href="statistik.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800 transition-colors">
                        <i class="fas fa-chart-bar w-5"></i>
                        <span class="text-sm">Statistik</span>
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
                </div>
                <a href="../actions/logout.php" onclick="return confirm('Yakin ingin logout?')"
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
                <div class="bg-white rounded-xl border border-slate-200 shadow-sm mb-6">
                    <div class="px-6 py-4 border-b border-slate-100">
                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                            <div class="flex items-center gap-3">
                                <a href="kegiatan.view.php" class="p-2 rounded-lg text-slate-400 hover:bg-slate-100 transition-colors">
                                    <i class="fas fa-arrow-left"></i>
                                </a>
                                <div>
                                    <h1 class="text-lg font-semibold text-slate-900"><?= htmlspecialchars($tournament['name']); ?></h1>
                                    <p class="text-sm text-slate-500">Peserta Tournament</p>
                                </div>
                            </div>
                            <button onclick="openModal('addModal')" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-archery-600 text-white text-sm font-medium hover:bg-archery-700 transition-colors">
                                <i class="fas fa-plus"></i>
                                <span class="hidden sm:inline">Tambah Peserta</span>
                            </button>
                        </div>
                    </div>

                    <!-- Metrics Bar -->
                    <div class="px-6 py-3 bg-slate-50 flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
                        <div class="flex items-center gap-2">
                            <span class="text-2xl font-bold text-slate-900"><?= $stats['total'] ?? 0 ?></span>
                            <span class="text-slate-500">Total</span>
                        </div>
                        <span class="text-slate-300 hidden sm:inline">|</span>
                        <div class="flex items-center gap-1.5">
                            <i class="fas fa-mars text-blue-500 text-xs"></i>
                            <span class="font-medium text-slate-700"><?= $stats['putra'] ?? 0 ?></span>
                            <span class="text-slate-400">Putra</span>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <i class="fas fa-venus text-pink-500 text-xs"></i>
                            <span class="font-medium text-slate-700"><?= $stats['putri'] ?? 0 ?></span>
                            <span class="text-slate-400">Putri</span>
                        </div>
                        <span class="text-slate-300 hidden sm:inline">|</span>
                        <div class="flex items-center gap-1.5">
                            <i class="fas fa-check-circle text-emerald-500 text-xs"></i>
                            <span class="font-medium text-slate-700"><?= $stats['paid'] ?? 0 ?></span>
                            <span class="text-slate-400">Paid</span>
                        </div>
                        <?php if (($stats['pending'] ?? 0) > 0): ?>
                        <div class="flex items-center gap-1.5">
                            <i class="fas fa-clock text-amber-500 text-xs"></i>
                            <span class="font-medium text-amber-600"><?= $stats['pending'] ?></span>
                            <span class="text-slate-400">Pending</span>
                        </div>
                        <?php endif; ?>
                        <span class="text-slate-300 hidden sm:inline">|</span>
                        <div class="flex items-center gap-1.5">
                            <span class="font-medium text-slate-700"><?= $stats['kategori_count'] ?? 0 ?></span>
                            <span class="text-slate-400">Kategori</span>
                        </div>
                    </div>

                    <!-- Tournament Info -->
                    <div class="px-6 py-3 border-t border-slate-100 flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
                        <div class="flex items-center gap-2 text-slate-500">
                            <i class="fas fa-calendar-alt text-slate-400 text-xs"></i>
                            <span><?= date('d/m/Y', strtotime($tournament['start_date'])); ?> - <?= date('d/m/Y', strtotime($tournament['end_date'])); ?></span>
                        </div>
                        <div class="flex items-center gap-2 text-slate-500">
                            <i class="fas fa-map-marker-alt text-slate-400 text-xs"></i>
                            <span><?= htmlspecialchars($tournament['location'] ?? '-'); ?></span>
                        </div>
                        <?php
                        $statusColors = [
                            'draft' => 'bg-slate-100 text-slate-600',
                            'registration' => 'bg-cyan-50 text-cyan-700',
                            'ongoing' => 'bg-amber-50 text-amber-700',
                            'completed' => 'bg-emerald-50 text-emerald-700',
                            'cancelled' => 'bg-red-50 text-red-700'
                        ];
                        $statusColor = $statusColors[$tournament['status']] ?? 'bg-slate-100 text-slate-600';
                        ?>
                        <span class="px-2 py-0.5 rounded text-xs font-medium <?= $statusColor ?>"><?= ucfirst($tournament['status']); ?></span>
                    </div>
                </div>

                <!-- Search & Bulk Actions Bar -->
                <div class="bg-white rounded-xl border border-slate-200 shadow-sm mb-6">
                    <div class="px-4 py-3 flex flex-col sm:flex-row gap-3">
                        <form method="get" class="flex-1 flex gap-2">
                            <input type="hidden" name="tournament_id" value="<?= $tournament_id; ?>">
                            <div class="relative flex-1">
                                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                                <input type="search" name="q" value="<?= htmlspecialchars($search); ?>"
                                       class="w-full pl-9 pr-4 py-2 rounded-lg border border-slate-200 text-sm focus:ring-2 focus:ring-archery-500 focus:border-archery-500 bg-slate-50"
                                       placeholder="Cari peserta atau kategori...">
                            </div>
                            <button type="submit" class="px-4 py-2 rounded-lg bg-archery-600 text-white text-sm font-medium hover:bg-archery-700 transition-colors">
                                <i class="fas fa-search sm:hidden"></i>
                                <span class="hidden sm:inline">Cari</span>
                            </button>
                            <?php if (!empty($search)): ?>
                            <a href="?tournament_id=<?= $tournament_id ?>" class="px-3 py-2 rounded-lg border border-slate-200 text-slate-500 text-sm hover:bg-slate-50 transition-colors">
                                <i class="fas fa-times"></i>
                            </a>
                            <?php endif; ?>
                        </form>
                    </div>

                    <!-- Bulk Action Bar (hidden by default) -->
                    <div id="bulkActionBar" class="hidden px-4 py-3 bg-amber-50 border-t border-amber-100 flex items-center justify-between">
                        <div class="flex items-center gap-2 text-sm">
                            <span class="font-medium text-amber-700"><span id="selectedCount">0</span> dipilih</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <button onclick="bulkUpdateStatus('confirmed')" class="px-3 py-1.5 rounded-lg bg-emerald-600 text-white text-xs font-medium hover:bg-emerald-700 transition-colors">
                                <i class="fas fa-check mr-1"></i> Confirm
                            </button>
                            <button onclick="bulkUpdatePayment('paid')" class="px-3 py-1.5 rounded-lg bg-blue-600 text-white text-xs font-medium hover:bg-blue-700 transition-colors">
                                <i class="fas fa-dollar-sign mr-1"></i> Mark Paid
                            </button>
                            <button onclick="bulkDelete()" class="px-3 py-1.5 rounded-lg bg-red-600 text-white text-xs font-medium hover:bg-red-700 transition-colors">
                                <i class="fas fa-trash mr-1"></i> Hapus
                            </button>
                            <button onclick="clearSelection()" class="px-3 py-1.5 rounded-lg border border-slate-300 text-slate-600 text-xs font-medium hover:bg-white transition-colors">
                                Batal
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Desktop Table -->
                <div class="hidden md:block bg-white rounded-xl border border-slate-200 overflow-hidden">
                    <div class="overflow-x-auto custom-scrollbar">
                        <table class="w-full">
                            <thead class="bg-slate-100 sticky top-0 z-10">
                                <tr>
                                    <th class="px-3 py-3 text-center text-xs font-semibold text-slate-600 uppercase tracking-wider w-12">
                                        <input type="checkbox" id="selectAll" onchange="toggleSelectAll()" class="rounded border-slate-300 text-archery-600 focus:ring-archery-500">
                                    </th>
                                    <th class="px-3 py-3 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider w-12">#</th>
                                    <th class="px-3 py-3 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">Nama</th>
                                    <th class="px-3 py-3 text-center text-xs font-semibold text-slate-600 uppercase tracking-wider w-16">Umur</th>
                                    <th class="px-3 py-3 text-center text-xs font-semibold text-slate-600 uppercase tracking-wider w-12">L/P</th>
                                    <th class="px-3 py-3 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">Kategori</th>
                                    <th class="px-3 py-3 text-center text-xs font-semibold text-slate-600 uppercase tracking-wider w-20">Bayar</th>
                                    <th class="px-3 py-3 text-center text-xs font-semibold text-slate-600 uppercase tracking-wider w-24">Status</th>
                                    <th class="px-3 py-3 text-center text-xs font-semibold text-slate-600 uppercase tracking-wider w-14">Seed</th>
                                    <th class="px-3 py-3 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider w-28">Tgl Daftar</th>
                                    <th class="px-3 py-3 text-center text-xs font-semibold text-slate-600 uppercase tracking-wider w-20">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php if ($participants_result->num_rows == 0): ?>
                                    <tr>
                                        <td colspan="11" class="px-4 py-12">
                                            <div class="flex flex-col items-center text-center">
                                                <div class="w-16 h-16 rounded-full bg-slate-100 flex items-center justify-center mb-3">
                                                    <i class="fas fa-users text-slate-400 text-2xl"></i>
                                                </div>
                                                <p class="text-slate-500 font-medium">Belum ada peserta terdaftar</p>
                                                <p class="text-slate-400 text-sm mb-4">Klik tombol "Tambah Peserta" untuk mendaftarkan peserta</p>
                                                <button onclick="openModal('addModal')" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-archery-600 text-white text-sm font-medium hover:bg-archery-700 transition-colors">
                                                    <i class="fas fa-plus"></i> Tambah Peserta
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php
                                    $no = $offset + 1;
                                    while ($row = $participants_result->fetch_assoc()):
                                    ?>
                                    <tr class="hover:bg-slate-50 transition-colors">
                                        <td class="px-3 py-2.5 text-center">
                                            <input type="checkbox" name="selected[]" value="<?= $row['id']; ?>" onchange="updateSelection()" class="row-checkbox rounded border-slate-300 text-archery-600 focus:ring-archery-500">
                                        </td>
                                        <td class="px-3 py-2.5 text-sm text-slate-400"><?= $no++; ?></td>
                                        <td class="px-3 py-2.5">
                                            <p class="font-semibold text-slate-900"><?= htmlspecialchars($row['participant_name']); ?></p>
                                        </td>
                                        <td class="px-3 py-2.5 text-center text-sm text-slate-600"><?= $row['age']; ?></td>
                                        <td class="px-3 py-2.5 text-center">
                                            <span class="inline-flex items-center justify-center w-6 h-6 rounded-full text-xs font-medium <?= $row['gender'] == 'Laki-laki' ? 'bg-blue-50 text-blue-600' : 'bg-pink-50 text-pink-600' ?>">
                                                <?= $row['gender'] == 'Laki-laki' ? 'L' : 'P' ?>
                                            </span>
                                        </td>
                                        <td class="px-3 py-2.5">
                                            <span class="px-2 py-0.5 rounded text-xs font-medium bg-slate-100 text-slate-700">
                                                <?= htmlspecialchars($row['category_name']); ?>
                                            </span>
                                        </td>
                                        <td class="px-3 py-2.5 text-center">
                                            <?php if ($row['payment_status'] == 'paid'): ?>
                                            <span class="text-emerald-600"><i class="fas fa-check-circle"></i></span>
                                            <?php elseif ($row['payment_status'] == 'pending'): ?>
                                            <span class="text-amber-500"><i class="fas fa-clock"></i></span>
                                            <?php else: ?>
                                            <span class="text-slate-400"><i class="fas fa-minus-circle"></i></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-3 py-2.5 text-center">
                                            <?php
                                            $statusColors = [
                                                'registered' => 'bg-slate-100 text-slate-600',
                                                'confirmed' => 'bg-emerald-50 text-emerald-700',
                                                'withdrew' => 'bg-amber-50 text-amber-700',
                                                'disqualified' => 'bg-red-50 text-red-700'
                                            ];
                                            $statusColor = $statusColors[$row['status']] ?? 'bg-slate-100 text-slate-600';
                                            ?>
                                            <span class="px-2 py-0.5 rounded text-xs font-medium <?= $statusColor ?>">
                                                <?= htmlspecialchars(ucfirst($row['status'])); ?>
                                            </span>
                                        </td>
                                        <td class="px-3 py-2.5 text-center text-sm text-slate-600"><?= htmlspecialchars($row['seed_number'] ?? '-'); ?></td>
                                        <td class="px-3 py-2.5 text-sm text-slate-500"><?= date('d/m/Y', strtotime($row['registration_date'])); ?></td>
                                        <td class="px-3 py-2.5">
                                            <div class="flex items-center justify-center gap-1">
                                                <button onclick="editParticipant(<?= $row['id']; ?>)" class="p-1.5 rounded text-slate-400 hover:text-amber-600 hover:bg-amber-50 transition-colors" title="Edit">
                                                    <i class="fas fa-edit text-xs"></i>
                                                </button>
                                                <button onclick="removeParticipant(<?= $row['id']; ?>)" class="p-1.5 rounded text-slate-400 hover:text-red-600 hover:bg-red-50 transition-colors" title="Hapus">
                                                    <i class="fas fa-trash text-xs"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($total_rows > 0): ?>
                    <!-- Pagination Footer -->
                    <div class="px-4 py-3 bg-white border-t border-slate-100 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                        <div class="text-sm text-slate-500">
                            Menampilkan <span class="font-medium text-slate-900"><?= $offset + 1 ?></span> - <span class="font-medium text-slate-900"><?= min($offset + $limit, $total_rows) ?></span> dari <span class="font-medium text-slate-900"><?= $total_rows ?></span> peserta
                            <?php if (!empty($search)): ?><span class="text-slate-400">• filtered</span><?php endif; ?>
                        </div>
                        <?php if ($total_pages > 1): ?>
                        <nav class="flex items-center gap-1">
                            <!-- First & Prev -->
                            <?php if ($page > 1): ?>
                            <a href="<?= buildPaginationUrl(1) ?>" class="p-2 rounded-md text-slate-400 hover:text-slate-600 hover:bg-slate-100 transition-colors" title="First">
                                <i class="fas fa-angles-left text-xs"></i>
                            </a>
                            <a href="<?= buildPaginationUrl($page - 1) ?>" class="p-2 rounded-md text-slate-400 hover:text-slate-600 hover:bg-slate-100 transition-colors" title="Previous">
                                <i class="fas fa-angle-left text-xs"></i>
                            </a>
                            <?php else: ?>
                            <span class="p-2 text-slate-300"><i class="fas fa-angles-left text-xs"></i></span>
                            <span class="p-2 text-slate-300"><i class="fas fa-angle-left text-xs"></i></span>
                            <?php endif; ?>

                            <!-- Page Numbers -->
                            <?php
                            $start_page = max(1, $page - 2);
                            $end_page = min($total_pages, $page + 2);

                            if ($start_page > 1): ?>
                            <a href="<?= buildPaginationUrl(1) ?>" class="px-3 py-1.5 rounded-md text-sm text-slate-600 hover:bg-slate-100 transition-colors">1</a>
                            <?php if ($start_page > 2): ?><span class="px-1 text-slate-400">...</span><?php endif; ?>
                            <?php endif;

                            for ($i = $start_page; $i <= $end_page; $i++): ?>
                            <a href="<?= buildPaginationUrl($i) ?>" class="px-3 py-1.5 rounded-md text-sm font-medium transition-colors <?= $i === $page ? 'bg-archery-600 text-white' : 'text-slate-600 hover:bg-slate-100' ?>"><?= $i ?></a>
                            <?php endfor;

                            if ($end_page < $total_pages): ?>
                            <?php if ($end_page < $total_pages - 1): ?><span class="px-1 text-slate-400">...</span><?php endif; ?>
                            <a href="<?= buildPaginationUrl($total_pages) ?>" class="px-3 py-1.5 rounded-md text-sm text-slate-600 hover:bg-slate-100 transition-colors"><?= $total_pages ?></a>
                            <?php endif; ?>

                            <!-- Next & Last -->
                            <?php if ($page < $total_pages): ?>
                            <a href="<?= buildPaginationUrl($page + 1) ?>" class="p-2 rounded-md text-slate-400 hover:text-slate-600 hover:bg-slate-100 transition-colors" title="Next">
                                <i class="fas fa-angle-right text-xs"></i>
                            </a>
                            <a href="<?= buildPaginationUrl($total_pages) ?>" class="p-2 rounded-md text-slate-400 hover:text-slate-600 hover:bg-slate-100 transition-colors" title="Last">
                                <i class="fas fa-angles-right text-xs"></i>
                            </a>
                            <?php else: ?>
                            <span class="p-2 text-slate-300"><i class="fas fa-angle-right text-xs"></i></span>
                            <span class="p-2 text-slate-300"><i class="fas fa-angles-right text-xs"></i></span>
                            <?php endif; ?>
                        </nav>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Mobile Cards -->
                <div id="mobileCards" class="md:hidden space-y-3 p-4">
                    <?php
                    // Reset result pointer for mobile view
                    $participants_result->data_seek(0);
                    if ($participants_result->num_rows == 0):
                    ?>
                        <div class="bg-white rounded-lg border border-slate-200 p-8 text-center">
                            <div class="w-16 h-16 rounded-full bg-slate-100 flex items-center justify-center mx-auto mb-3">
                                <i class="fas fa-users text-slate-400 text-2xl"></i>
                            </div>
                            <p class="text-slate-500 font-medium mb-3">Belum ada peserta terdaftar</p>
                            <button onclick="openModal('addModal')" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-archery-600 text-white text-sm font-medium">
                                <i class="fas fa-plus"></i> Tambah Peserta
                            </button>
                        </div>
                    <?php else: ?>
                        <?php
                        $no = $offset + 1;
                        while ($row = $participants_result->fetch_assoc()):
                        ?>
                            <div class="bg-white rounded-lg border border-slate-200 p-4">
                                <div class="flex items-start gap-3 mb-3">
                                    <span class="text-sm text-slate-400 font-medium w-6"><?= $no++; ?></span>
                                    <div class="flex-1 min-w-0">
                                        <p class="font-semibold text-slate-900"><?= htmlspecialchars($row['participant_name']); ?></p>
                                        <div class="flex items-center gap-2 mt-1">
                                            <span class="inline-flex items-center justify-center w-5 h-5 rounded-full text-xs <?= $row['gender'] == 'Laki-laki' ? 'bg-blue-50 text-blue-600' : 'bg-pink-50 text-pink-600' ?>">
                                                <?= $row['gender'] == 'Laki-laki' ? 'L' : 'P' ?>
                                            </span>
                                            <span class="text-sm text-slate-500"><?= $row['age']; ?> th</span>
                                            <span class="text-slate-300">•</span>
                                            <span class="px-2 py-0.5 rounded text-xs font-medium bg-slate-100 text-slate-600"><?= htmlspecialchars($row['category_name']); ?></span>
                                        </div>
                                    </div>
                                    <div class="flex-shrink-0 flex items-center gap-2">
                                        <?php if ($row['payment_status'] == 'paid'): ?>
                                        <span class="text-emerald-600"><i class="fas fa-check-circle"></i></span>
                                        <?php elseif ($row['payment_status'] == 'pending'): ?>
                                        <span class="text-amber-500"><i class="fas fa-clock"></i></span>
                                        <?php else: ?>
                                        <span class="text-slate-400"><i class="fas fa-minus-circle"></i></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm border-t border-slate-100 pt-3">
                                    <div>
                                        <span class="text-slate-400">Status:</span>
                                        <?php
                                        $statusColors = [
                                            'registered' => 'bg-slate-100 text-slate-600',
                                            'confirmed' => 'bg-emerald-50 text-emerald-700',
                                            'withdrew' => 'bg-amber-50 text-amber-700',
                                            'disqualified' => 'bg-red-50 text-red-700'
                                        ];
                                        $statusColor = $statusColors[$row['status']] ?? 'bg-slate-100 text-slate-600';
                                        ?>
                                        <span class="ml-1 px-2 py-0.5 rounded text-xs font-medium <?= $statusColor ?>">
                                            <?= ucfirst($row['status']); ?>
                                        </span>
                                    </div>
                                    <div>
                                        <span class="text-slate-400">Seed:</span>
                                        <span class="text-slate-700 ml-1"><?= $row['seed_number'] ?? '-'; ?></span>
                                    </div>
                                    <div class="col-span-2">
                                        <span class="text-slate-400">Tgl Daftar:</span>
                                        <span class="text-slate-700 ml-1"><?= date('d/m/Y H:i', strtotime($row['registration_date'])); ?></span>
                                    </div>
                                </div>
                                <div class="flex items-center justify-end gap-2 mt-3 pt-3 border-t border-slate-100">
                                    <button onclick="editParticipant(<?= $row['id']; ?>)" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-slate-600 hover:bg-slate-100 text-xs font-medium transition-colors">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <button onclick="removeParticipant(<?= $row['id']; ?>)" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-red-600 hover:bg-red-50 text-xs font-medium transition-colors">
                                        <i class="fas fa-trash"></i> Hapus
                                    </button>
                                </div>
                            </div>
                        <?php endwhile; ?>

                        <!-- Mobile Pagination -->
                        <?php if ($total_pages > 1): ?>
                        <div class="bg-white rounded-lg border border-slate-200 p-4 mt-4">
                            <div class="flex items-center justify-between">
                                <?php if ($page > 1): ?>
                                <a href="<?= buildPaginationUrl($page - 1) ?>" class="px-4 py-2 rounded-lg bg-slate-100 text-slate-600 text-sm font-medium hover:bg-slate-200 transition-colors">
                                    <i class="fas fa-chevron-left mr-1"></i> Prev
                                </a>
                                <?php else: ?>
                                <span class="px-4 py-2 rounded-lg bg-slate-50 text-slate-300 text-sm font-medium">
                                    <i class="fas fa-chevron-left mr-1"></i> Prev
                                </span>
                                <?php endif; ?>

                                <span class="text-sm text-slate-500">
                                    <span class="font-medium text-slate-900"><?= $page ?></span> / <?= $total_pages ?>
                                </span>

                                <?php if ($page < $total_pages): ?>
                                <a href="<?= buildPaginationUrl($page + 1) ?>" class="px-4 py-2 rounded-lg bg-archery-600 text-white text-sm font-medium hover:bg-archery-700 transition-colors">
                                    Next <i class="fas fa-chevron-right ml-1"></i>
                                </a>
                                <?php else: ?>
                                <span class="px-4 py-2 rounded-lg bg-slate-50 text-slate-300 text-sm font-medium">
                                    Next <i class="fas fa-chevron-right ml-1"></i>
                                </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- Add Participant Modal -->
    <div id="addModal" class="modal-backdrop">
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-lg mx-4 max-h-[90vh] overflow-hidden flex flex-col">
            <div class="bg-gradient-to-br from-archery-600 to-archery-800 text-white px-6 py-4 flex items-center justify-between flex-shrink-0">
                <h3 class="font-semibold text-lg flex items-center gap-2">
                    <i class="fas fa-user-plus"></i> Tambah Peserta ke Tournament
                </h3>
                <button onclick="closeModal('addModal')" class="p-2 rounded-lg hover:bg-white/10 transition-colors">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form id="addParticipantForm" class="flex-1 overflow-y-auto">
                <div class="p-6 space-y-4">
                    <input type="hidden" name="action" value="add_participant">
                    <?php csrf_field(); ?>

                    <div>
                        <label for="participant_id" class="block text-sm font-medium text-slate-700 mb-1">
                            Pilih Peserta <span class="text-red-500">*</span>
                        </label>
                        <select id="participant_id" name="participant_id" required
                                class="w-full px-4 py-2 rounded-lg border border-slate-300 text-sm focus:ring-2 focus:ring-archery-500 focus:border-archery-500">
                            <option value="">-- Pilih Peserta --</option>
                            <?php while ($participant = $available_participants->fetch_assoc()): ?>
                            <option value="<?= $participant['id']; ?>">
                                <?= htmlspecialchars($participant['name']); ?>
                                (<?= $participant['gender']; ?>,
                                <?= (date('Y') - date('Y', strtotime($participant['birthdate']))); ?> tahun)
                            </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div>
                        <label for="category_id" class="block text-sm font-medium text-slate-700 mb-1">
                            Kategori <span class="text-red-500">*</span>
                        </label>
                        <select id="category_id" name="category_id" required
                                class="w-full px-4 py-2 rounded-lg border border-slate-300 text-sm focus:ring-2 focus:ring-archery-500 focus:border-archery-500">
                            <option value="">-- Pilih Kategori --</option>
                            <?php while ($category = $categories->fetch_assoc()): ?>
                            <option value="<?= $category['id']; ?>">
                                <?= htmlspecialchars($category['name']); ?>
                                (<?= $category['min_age']; ?>-<?= $category['max_age']; ?> tahun)
                            </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label for="payment_status" class="block text-sm font-medium text-slate-700 mb-1">Status Pembayaran</label>
                            <select id="payment_status" name="payment_status"
                                    class="w-full px-4 py-2 rounded-lg border border-slate-300 text-sm focus:ring-2 focus:ring-archery-500 focus:border-archery-500">
                                <option value="pending">Pending</option>
                                <option value="paid">Paid</option>
                                <option value="refunded">Refunded</option>
                            </select>
                        </div>
                        <div>
                            <label for="status" class="block text-sm font-medium text-slate-700 mb-1">Status Peserta</label>
                            <select id="status" name="status"
                                    class="w-full px-4 py-2 rounded-lg border border-slate-300 text-sm focus:ring-2 focus:ring-archery-500 focus:border-archery-500">
                                <option value="registered">Registered</option>
                                <option value="confirmed">Confirmed</option>
                                <option value="withdrew">Withdrew</option>
                                <option value="disqualified">Disqualified</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label for="notes" class="block text-sm font-medium text-slate-700 mb-1">Catatan</label>
                        <textarea id="notes" name="notes" rows="2"
                                  class="w-full px-4 py-2 rounded-lg border border-slate-300 text-sm focus:ring-2 focus:ring-archery-500 focus:border-archery-500"
                                  placeholder="Catatan tambahan..."></textarea>
                    </div>
                </div>
                <div class="px-6 py-4 bg-slate-50 border-t border-slate-200 flex gap-3 flex-shrink-0">
                    <button type="button" onclick="closeModal('addModal')" class="flex-1 px-4 py-2 rounded-lg border border-slate-300 text-slate-700 text-sm font-medium hover:bg-slate-100 transition-colors">
                        Batal
                    </button>
                    <button type="submit" class="flex-1 px-4 py-2 rounded-lg bg-archery-600 text-white text-sm font-medium hover:bg-archery-700 transition-colors">
                        <i class="fas fa-save mr-1"></i> Simpan
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Participant Modal -->
    <div id="editModal" class="modal-backdrop">
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-lg mx-4 max-h-[90vh] overflow-hidden flex flex-col">
            <div class="bg-gradient-to-br from-blue-600 to-blue-800 text-white px-6 py-4 flex items-center justify-between flex-shrink-0">
                <h3 class="font-semibold text-lg flex items-center gap-2">
                    <i class="fas fa-edit"></i> Edit Peserta Tournament
                </h3>
                <button onclick="closeModal('editModal')" class="p-2 rounded-lg hover:bg-white/10 transition-colors">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form id="editParticipantForm" class="flex-1 overflow-y-auto">
                <div class="p-6 space-y-4">
                    <input type="hidden" name="action" value="update_participant">
                    <?php csrf_field(); ?>
                    <input type="hidden" id="edit_tp_id" name="tp_id">

                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Nama Peserta</label>
                        <input type="text" id="edit_participant_name" readonly
                               class="w-full px-4 py-2 rounded-lg border border-slate-200 bg-slate-50 text-sm text-slate-600">
                    </div>

                    <div>
                        <label for="edit_category_id" class="block text-sm font-medium text-slate-700 mb-1">
                            Kategori <span class="text-red-500">*</span>
                        </label>
                        <select id="edit_category_id" name="category_id" required
                                class="w-full px-4 py-2 rounded-lg border border-slate-300 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            <?php
                            // Reset categories result
                            $categories = $conn->query("SELECT * FROM categories WHERE status = 'active' ORDER BY name");
                            while ($category = $categories->fetch_assoc()):
                            ?>
                            <option value="<?= $category['id']; ?>">
                                <?= htmlspecialchars($category['name']); ?>
                                (<?= $category['min_age']; ?>-<?= $category['max_age']; ?> tahun)
                            </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label for="edit_payment_status" class="block text-sm font-medium text-slate-700 mb-1">Status Pembayaran</label>
                            <select id="edit_payment_status" name="payment_status"
                                    class="w-full px-4 py-2 rounded-lg border border-slate-300 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                <option value="pending">Pending</option>
                                <option value="paid">Paid</option>
                                <option value="refunded">Refunded</option>
                            </select>
                        </div>
                        <div>
                            <label for="edit_status" class="block text-sm font-medium text-slate-700 mb-1">Status Peserta</label>
                            <select id="edit_status" name="status"
                                    class="w-full px-4 py-2 rounded-lg border border-slate-300 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                <option value="registered">Registered</option>
                                <option value="confirmed">Confirmed</option>
                                <option value="withdrew">Withdrew</option>
                                <option value="disqualified">Disqualified</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label for="edit_seed_number" class="block text-sm font-medium text-slate-700 mb-1">Seed Number</label>
                        <input type="number" id="edit_seed_number" name="seed_number" min="1"
                               class="w-full px-4 py-2 rounded-lg border border-slate-300 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                               placeholder="Nomor seed">
                    </div>

                    <div>
                        <label for="edit_notes" class="block text-sm font-medium text-slate-700 mb-1">Catatan</label>
                        <textarea id="edit_notes" name="notes" rows="2"
                                  class="w-full px-4 py-2 rounded-lg border border-slate-300 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                  placeholder="Catatan tambahan..."></textarea>
                    </div>
                </div>
                <div class="px-6 py-4 bg-slate-50 border-t border-slate-200 flex gap-3 flex-shrink-0">
                    <button type="button" onclick="closeModal('editModal')" class="flex-1 px-4 py-2 rounded-lg border border-slate-300 text-slate-700 text-sm font-medium hover:bg-slate-100 transition-colors">
                        Batal
                    </button>
                    <button type="submit" class="flex-1 px-4 py-2 rounded-lg bg-blue-600 text-white text-sm font-medium hover:bg-blue-700 transition-colors">
                        <i class="fas fa-sync-alt mr-1"></i> Update
                    </button>
                </div>
            </form>
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
            <a href="categori.view.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800">
                <i class="fas fa-tags w-5"></i><span class="text-sm">Kategori</span>
            </a>
            <a href="kegiatan.view.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800">
                <i class="fas fa-calendar w-5"></i><span class="text-sm">Kegiatan</span>
            </a>
            <a href="peserta.view.php" class="flex items-center gap-3 px-4 py-3 rounded-lg bg-archery-600/20 text-archery-400">
                <i class="fas fa-user-friends w-5"></i><span class="text-sm font-medium">Peserta</span>
            </a>
        </nav>
    </div>

<script>
// Modal functions
function openModal(id) {
    document.getElementById(id).classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeModal(id) {
    document.getElementById(id).classList.remove('active');
    document.body.style.overflow = '';
}

// Close modal on backdrop click
document.querySelectorAll('.modal-backdrop').forEach(modal => {
    modal.addEventListener('click', function(e) {
        if (e.target === this) {
            this.classList.remove('active');
            document.body.style.overflow = '';
        }
    });
});

// Close modal on Escape
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-backdrop.active').forEach(modal => {
            modal.classList.remove('active');
        });
        document.body.style.overflow = '';
    }
});

// Edit participant
function editParticipant(tpId) {
    fetch('', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=get_participant&tp_id=' + tpId
    })
    .then(response => response.json())
    .then(data => {
        document.getElementById('edit_tp_id').value = data.id;
        document.getElementById('edit_participant_name').value = data.participant_name;
        document.getElementById('edit_category_id').value = data.category_id;
        document.getElementById('edit_payment_status').value = data.payment_status;
        document.getElementById('edit_status').value = data.status;
        document.getElementById('edit_seed_number').value = data.seed_number || '';
        document.getElementById('edit_notes').value = data.notes || '';

        openModal('editModal');
    });
}

// Remove participant
function removeParticipant(tpId) {
    if (confirm('Yakin ingin menghapus peserta ini dari tournament?')) {
        fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=remove_participant&tp_id=' + tpId
        })
        .then(response => response.json())
        .then(result => {
            if (result.success) {
                alert(result.message);
                location.reload();
            } else {
                alert(result.message);
            }
        });
    }
}

// Add form submit
document.getElementById('addParticipantForm').addEventListener('submit', function(e) {
    e.preventDefault();

    const formData = new FormData(this);

    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(result => {
        if (result.success) {
            alert(result.message);
            closeModal('addModal');
            location.reload();
        } else {
            alert(result.message);
        }
    })
    .catch(() => {
        alert('Terjadi kesalahan saat memproses data');
    });
});

// Edit form submit
document.getElementById('editParticipantForm').addEventListener('submit', function(e) {
    e.preventDefault();

    const formData = new FormData(this);

    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(result => {
        if (result.success) {
            alert(result.message);
            closeModal('editModal');
            location.reload();
        } else {
            alert(result.message);
        }
    })
    .catch(() => {
        alert('Terjadi kesalahan saat memproses data');
    });
});

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

// Bulk selection functions
function toggleSelectAll() {
    const selectAll = document.getElementById('selectAll');
    const checkboxes = document.querySelectorAll('.row-checkbox');
    checkboxes.forEach(cb => cb.checked = selectAll.checked);
    updateSelection();
}

function updateSelection() {
    const checkboxes = document.querySelectorAll('.row-checkbox:checked');
    const count = checkboxes.length;
    const bulkBar = document.getElementById('bulkActionBar');
    const countEl = document.getElementById('selectedCount');

    if (count > 0) {
        bulkBar.classList.remove('hidden');
        countEl.textContent = count;
    } else {
        bulkBar.classList.add('hidden');
    }

    // Update select all checkbox state
    const allCheckboxes = document.querySelectorAll('.row-checkbox');
    const selectAll = document.getElementById('selectAll');
    if (selectAll) {
        selectAll.checked = count > 0 && count === allCheckboxes.length;
        selectAll.indeterminate = count > 0 && count < allCheckboxes.length;
    }
}

function clearSelection() {
    const checkboxes = document.querySelectorAll('.row-checkbox');
    checkboxes.forEach(cb => cb.checked = false);
    document.getElementById('selectAll').checked = false;
    updateSelection();
}

function getSelectedIds() {
    const checkboxes = document.querySelectorAll('.row-checkbox:checked');
    return Array.from(checkboxes).map(cb => cb.value);
}

function bulkUpdateStatus(status) {
    const ids = getSelectedIds();
    if (ids.length === 0) return;

    if (!confirm(`Ubah status ${ids.length} peserta menjadi "${status}"?`)) return;

    // For now, show message - actual implementation would need backend support
    alert(`Bulk update status to "${status}" for ${ids.length} participants - requires backend implementation`);
    clearSelection();
}

function bulkUpdatePayment(status) {
    const ids = getSelectedIds();
    if (ids.length === 0) return;

    if (!confirm(`Tandai ${ids.length} peserta sebagai "${status}"?`)) return;

    // For now, show message - actual implementation would need backend support
    alert(`Bulk update payment to "${status}" for ${ids.length} participants - requires backend implementation`);
    clearSelection();
}

function bulkDelete() {
    const ids = getSelectedIds();
    if (ids.length === 0) return;

    if (!confirm(`Hapus ${ids.length} peserta dari tournament? Aksi ini tidak dapat dibatalkan!`)) return;

    // For now, show message - actual implementation would need backend support
    alert(`Bulk delete ${ids.length} participants - requires backend implementation`);
    clearSelection();
}
</script>
</body>
</html>
