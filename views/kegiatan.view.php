<?php
include '../config/panggil.php';
include '../includes/check_access.php';
include '../includes/theme.php';
require_once '../includes/security.php';
requireAdmin();

if (!checkRateLimit('view_load', 60, 60)) {
    header('HTTP/1.1 429 Too Many Requests');
    die('Terlalu banyak permintaan. Silakan coba lagi nanti.');
}

$_GET = cleanInput($_GET);
$searchQuery = $_GET['q'] ?? '';

// Toast message handling
$toast_message = '';
$toast_type = '';

// Ambil semua kategori aktif
$kategoriResult = $conn->query("SELECT id, name, min_age, max_age, gender FROM categories WHERE status = 'active' ORDER BY min_age ASC, name ASC");
$kategoriList = [];
while ($row = $kategoriResult->fetch_assoc()) {
    $kategoriList[] = $row;
}

// Note: DELETE is now handled via POST for security

// Proses tambah/edit data
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!checkRateLimit('kegiatan_crud', 20, 60)) {
        $toast_message = "Terlalu banyak permintaan. Silakan coba lagi nanti.";
        $toast_type = 'error';
        goto skip_post;
    }
    verify_csrf();
    $_POST = cleanInput($_POST);

    if (isset($_POST['action']) && $_POST['action'] === 'delete') {
        $kegiatanId = intval($_POST['id']);

        // Get name for toast - Prepared Statement
        $stmt_n = $conn->prepare("SELECT nama_kegiatan FROM kegiatan WHERE id = ?");
        $stmt_n->bind_param("i", $kegiatanId);
        $stmt_n->execute();
        $nameResult = $stmt_n->get_result();
        $kegiatanName = $nameResult->fetch_assoc()['nama_kegiatan'] ?? 'Kegiatan';
        $stmt_n->close();

        // Start exhaustive cascading deletion
        $conn->begin_transaction();

        try {
            // 1. Delete deeply nested related data first
            // Delete scores (using kegiatan_id directly if possible, or via participants)
            $conn->query("DELETE FROM score WHERE kegiatan_id = $kegiatanId");
            
            // Delete qualification scores
            $conn->query("DELETE FROM qualification_scores WHERE kegiatan_id = $kegiatanId");
            
            // Delete peserta rounds (must be done via subquery since it doesn't have kegiatan_id)
            $conn->query("DELETE FROM peserta_rounds WHERE peserta_id IN (SELECT id FROM peserta WHERE kegiatan_id = $kegiatanId)");
            
            // Delete tournament participants (if table exists)
            // Delete tournament participants (skipped: table uses tournament_id, not kegiatan_id)
            // $conn->query("DELETE FROM tournament_participants WHERE kegiatan_id = $kegiatanId");
            
            // Delete bracket related data
            $conn->query("DELETE FROM bracket_matches WHERE kegiatan_id = $kegiatanId");
            $conn->query("DELETE FROM bracket_champions WHERE kegiatan_id = $kegiatanId");
            $conn->query("DELETE FROM tournament_settings WHERE kegiatan_id = $kegiatanId");

            // 2. Delete main relations
            // Delete participants
            $conn->query("DELETE FROM peserta WHERE kegiatan_id = $kegiatanId");

            // Delete categories linkage
            $conn->query("DELETE FROM kegiatan_kategori WHERE kegiatan_id = $kegiatanId");

            // 3. Final: Delete the activity itself
            $stmt = $conn->prepare("DELETE FROM kegiatan WHERE id = ?");
            $stmt->bind_param("i", $kegiatanId);
            
            if ($stmt->execute()) {
                $conn->commit();
                $toast_message = "Kegiatan '$kegiatanName' berhasil dihapus beserta seluruh data terkait (peserta, skor, bracket, dsb)";
                $toast_type = "success";
            } else {
                throw new Exception($stmt->error);
            }
            $stmt->close();
        } catch (Exception $e) {
            $conn->rollback();
            $toast_message = "Gagal menghapus kegiatan: " . $e->getMessage();
            $toast_type = "error";
        }
    } else {
        $nama = $_POST['namaKegiatan'];
        $kategoriDipilih = isset($_POST['kategori']) ? $_POST['kategori'] : [];
        $editId = isset($_POST['editId']) ? intval($_POST['editId']) : null;

        if ($editId) {
            // Update data existing
            $stmt = $conn->prepare("UPDATE kegiatan SET nama_kegiatan = ? WHERE id = ?");
            $stmt->bind_param("si", $nama, $editId);
            if ($stmt->execute()) {
                $toast_message = "Kegiatan '$nama' berhasil diperbarui!";
                $toast_type = 'success';
            } else {
                $toast_message = "Gagal memperbarui kegiatan!";
                $toast_type = 'error';
            }
            $stmt->close();
        }

        // Hapus kategori lama if editing
        if ($editId) {
            $conn->query("DELETE FROM kegiatan_kategori WHERE kegiatan_id = $editId");
            $kegiatanId = $editId;
        } else {
        // Insert data baru
        $stmt = $conn->prepare("INSERT INTO kegiatan (nama_kegiatan) VALUES (?)");
        $stmt->bind_param("s", $nama);
        if ($stmt->execute()) {
            $toast_message = "Kegiatan '$nama' berhasil ditambahkan!";
            $toast_type = 'success';
        } else {
            $toast_message = "Gagal menambahkan kegiatan!";
            $toast_type = 'error';
        }
        $kegiatanId = $stmt->insert_id;
        $stmt->close();
    }

    // Simpan kategori terpilih
    if (!empty($kategoriDipilih)) {
        foreach ($kategoriDipilih as $kategoriId) {
            $stmt = $conn->prepare("INSERT INTO kegiatan_kategori (kegiatan_id, category_id) VALUES (?, ?)");
            $stmt->bind_param("ii", $kegiatanId, $kategoriId);
            $stmt->execute();
            $stmt->close();
        }
    }
    }
}
skip_post:

// Ambil data kegiatan dengan kategorinya dan statistik peserta
$searchCondition = "";
if (!empty($searchQuery)) {
    $searchCondition = " WHERE k.nama_kegiatan LIKE ? ";
}

$query = "
    SELECT
        k.id,
        k.nama_kegiatan AS kegiatan_nama,
        GROUP_CONCAT(kk.category_id) AS category_ids,
        GROUP_CONCAT(c.name SEPARATOR '|') AS category_names,
        (SELECT COUNT(*) FROM peserta p WHERE p.kegiatan_id = k.id) AS peserta_count
    FROM kegiatan k
    LEFT JOIN kegiatan_kategori kk ON k.id = kk.kegiatan_id
    LEFT JOIN categories c ON kk.category_id = c.id
    $searchCondition
    GROUP BY k.id, k.nama_kegiatan
    ORDER BY k.id DESC
";

$stmt = $conn->prepare($query);
if (!empty($searchQuery)) {
    $searchWildcard = "%$searchQuery%";
    $stmt->bind_param("s", $searchWildcard);
}
$stmt->execute();
$result = $stmt->get_result();
$kegiatanData = [];
while ($row = $result->fetch_assoc()) {
    $categoryIds = $row['category_ids'] ? explode(',', $row['category_ids']) : [];
    $categoryNames = $row['category_names'] ? explode('|', $row['category_names']) : [];

    $kegiatanData[] = [
        'id' => $row['id'],
        'nama' => $row['kegiatan_nama'],
        'category_ids' => array_map('intval', $categoryIds),
        'category_names' => $categoryNames,
        'peserta_count' => (int)$row['peserta_count']
    ];
}

// Calculate statistics
$statsQuery = "SELECT
    COUNT(*) as total_kegiatan,
    (SELECT COUNT(*) FROM peserta) as total_peserta,
    (SELECT COUNT(DISTINCT category_id) FROM kegiatan_kategori) as total_kategori_used
    FROM kegiatan";
$stmt = $conn->prepare($statsQuery);
$stmt->execute();
$statsResult = $stmt->get_result();
$stats = $statsResult->fetch_assoc();

$username = $_SESSION['username'] ?? 'User';
$name = $_SESSION['name'] ?? $username;
$role = $_SESSION['role'] ?? 'user';

?>
<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Kegiatan - Turnamen Panahan</title>
    <script src="https://cdn.tailwindcss.com"></script>

    <script><?= getThemeTailwindConfig() ?></script>

    <script><?= getThemeInitScript() ?></script>
    <script><?= getUiScripts() ?></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .custom-scrollbar::-webkit-scrollbar { width: 6px; height: 6px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 3px; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        .dark .custom-scrollbar::-webkit-scrollbar-track { background: #27272a; }
        .dark .custom-scrollbar::-webkit-scrollbar-thumb { background: #52525b; }
        .dark .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #71717a; }
        .modal-backdrop { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 50; }
        .modal-backdrop.active { display: flex; align-items: center; justify-content: center; }
        /* Toast animation */
        .toast-enter { animation: slideIn 0.3s ease-out; }
        .toast-exit { animation: slideOut 0.3s ease-in forwards; }
        @keyframes slideIn { from { transform: translateY(-100%); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        @keyframes slideOut { from { transform: translateY(0); opacity: 1; } to { transform: translateY(-100%); opacity: 0; } }
        /* Monospaced dates */
        .date-mono { font-family: ui-monospace, SFMono-Regular, "SF Mono", Consolas, "Liberation Mono", Menlo, monospace; }
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
                    <a href="kegiatan.view.php" class="flex items-center gap-3 px-4 py-3 rounded-lg bg-archery-600/20 text-archery-400 border border-archery-600/30">
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
            <!-- Toast Notification -->

            <?php if (!empty($toast_message)): ?>
            <div id="toast" class="fixed top-4 right-4 z-50 toast-enter">

                <div class="flex items-center gap-3 px-4 py-3 rounded-lg shadow-lg <?= $toast_type === 'success' ? 'bg-emerald-50 border border-emerald-200 text-emerald-800' : 'bg-red-50 border border-red-200 text-red-800' ?>">

                    <i class="fas <?= $toast_type === 'success' ? 'fa-check-circle text-emerald-500' : 'fa-exclamation-circle text-red-500' ?>"></i>

                    <span class="text-sm font-medium"><?= htmlspecialchars($toast_message) ?></span>

                    <button onclick="dismissToast()" class="ml-2 <?= $toast_type === 'success' ? 'text-emerald-500 hover:text-emerald-700' : 'text-red-500 hover:text-red-700' ?>">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>

            <?php endif; ?>

            <div class="px-6 lg:px-8 py-6">
                <!-- Precisely aligned header with users.php -->
                <div class="bg-white dark:bg-zinc-900 rounded-xl border border-slate-200 dark:border-zinc-800 shadow-sm mb-6">
                    <div class="px-6 py-4 border-b border-slate-100 dark:border-zinc-800">
                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                            <div class="flex items-center gap-3">
                                <a href="dashboard.php" class="p-2 rounded-lg text-slate-400 dark:text-zinc-500 hover:bg-slate-100 dark:hover:bg-zinc-800 transition-colors">
                                    <i class="fas fa-arrow-left"></i>
                                </a>
                                <div>
                                    <h1 class="text-lg font-semibold text-slate-900 dark:text-white">Data Kegiatan</h1>
                                    <p class="text-sm text-slate-500 dark:text-zinc-400">Kelola turnamen dan event panahan</p>
                                </div>
                            </div>
                            <button onclick="openModal()" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-archery-600 text-white text-sm font-medium hover:bg-archery-700 transition-colors">
                                <i class="fas fa-plus"></i>
                                <span class="hidden sm:inline">Tambah Kegiatan</span>
                            </button>
                        </div>
                    </div>

                    <!-- Metrics Bar Precisely Aligned -->
                    <div class="px-6 py-3 bg-slate-50 dark:bg-zinc-800/50 flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
                        <div class="flex items-center gap-2">
                            <span class="text-2xl font-bold text-slate-900 dark:text-white"><?= number_format($stats['total_kegiatan'] ?? 0) ?></span>
                            <span class="text-slate-500 dark:text-zinc-400">Kegiatan</span>
                        </div>
                        <span class="text-slate-300 dark:text-zinc-600 hidden sm:inline">|</span>
                        <div class="flex items-center gap-1.5">
                            <i class="fas fa-users text-purple-500 text-xs"></i>
                            <span class="font-medium text-slate-700 dark:text-zinc-300"><?= number_format($stats['total_peserta'] ?? 0) ?></span>
                            <span class="text-slate-400 dark:text-zinc-500">Total Peserta</span>
                        </div>
                        <span class="text-slate-300 dark:text-zinc-600 hidden sm:inline">|</span>
                        <div class="flex items-center gap-1.5">
                            <i class="fas fa-tags text-amber-500 text-xs"></i>
                            <span class="font-medium text-slate-700 dark:text-zinc-300"><?= number_format($stats['total_kategori_used'] ?? 0) ?></span>
                            <span class="text-slate-400 dark:text-zinc-500">Kategori Terpakai</span>
                        </div>
                    </div>
                </div>

                <!-- Search Bar -->
                <div class="flex flex-col sm:flex-row gap-4 mb-6">
                    <form method="get" class="flex-1 flex gap-2">
                        <div class="relative flex-1">
                            <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 dark:text-zinc-500 text-xs"></i>
                            <input type="search" name="q" value="<?= htmlspecialchars($searchQuery) ?>"
                                   class="w-full pl-9 pr-4 py-2 rounded-lg border border-slate-200 dark:border-zinc-700 text-sm focus:ring-2 focus:ring-archery-500 focus:border-archery-500 bg-slate-50 dark:bg-zinc-800 text-slate-900 dark:text-white"
                                   placeholder="Cari nama kegiatan...">
                        </div>
                        <button type="submit" class="px-4 py-2 rounded-lg bg-archery-600 text-white text-sm font-medium hover:bg-archery-700 transition-colors">
                            Cari
                        </button>
                        <?php if (!empty($searchQuery)): ?>
                        <a href="?" class="px-3 py-2 rounded-lg border border-slate-200 dark:border-zinc-700 text-slate-500 dark:text-zinc-400 text-sm hover:bg-slate-50 dark:hover:bg-zinc-800 transition-colors flex items-center">
                            <i class="fas fa-times"></i>
                        </a>
                        <?php endif; ?>
                    </form>
                </div>

                <!-- Desktop Table -->
                <div class="hidden md:block bg-white dark:bg-zinc-900 rounded-xl border border-slate-200 dark:border-zinc-800 overflow-hidden">
                    <div class="overflow-x-auto custom-scrollbar">
                        <table class="w-full">
                            <thead class="bg-slate-100 dark:bg-zinc-800 sticky top-0 z-10">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 dark:text-zinc-400 uppercase tracking-wider w-12">#</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 dark:text-zinc-400 uppercase tracking-wider">Nama Kegiatan</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 dark:text-zinc-400 uppercase tracking-wider">Kategori</th>
                                    <th class="px-4 py-3 text-center text-xs font-semibold text-slate-600 dark:text-zinc-400 uppercase tracking-wider w-24">Peserta</th>
                                    <th class="px-4 py-3 text-center text-xs font-semibold text-slate-600 dark:text-zinc-400 uppercase tracking-wider w-48">Aksi</th>
                                </tr>
                            </thead>
                            <tbody id="tableBody" class="divide-y divide-slate-100 dark:divide-zinc-800">

                                <?php if (empty($kegiatanData)): ?>
                                    <tr>
                                        <td colspan="5" class="px-4 py-12">
                                            <div class="flex flex-col items-center text-center">
                                                <div class="w-16 h-16 rounded-full bg-slate-100 dark:bg-zinc-800 flex items-center justify-center mb-3">
                                                    <i class="fas fa-calendar-times text-slate-400 dark:text-zinc-500 text-2xl"></i>
                                                </div>
                                                <p class="text-slate-500 dark:text-zinc-400 font-medium">Tidak ada data kegiatan</p>
                                                <p class="text-slate-400 dark:text-zinc-500 text-sm mb-4">Silakan tambahkan kegiatan baru</p>
                                                <button onclick="openModal()" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-archery-600 text-white text-sm font-medium hover:bg-archery-700 transition-colors">
                                                    <i class="fas fa-plus"></i> Tambah Kegiatan
                                                </button>
                                            </div>
                                        </td>
                                    </tr>

                                <?php else: ?>

                                    <?php foreach ($kegiatanData as $index => $item): ?>
                                        <tr class="hover:bg-slate-50 dark:hover:bg-zinc-800 transition-colors">

                                            <td class="px-4 py-3 text-sm text-slate-500 dark:text-zinc-400"><?= $index + 1 ?></td>
                                            <td class="px-4 py-3">

                                                <p class="font-medium text-slate-900 dark:text-white"><?= htmlspecialchars($item['nama']) ?></p>
                                            </td>
                                            <td class="px-4 py-3">

                                                <?php if (empty($item['category_names'])): ?>
                                                    <span class="text-slate-400 dark:text-zinc-500 italic text-sm">Belum ada kategori</span>

                                                <?php else: ?>
                                                    <div class="flex flex-wrap gap-1">

                                                        <?php foreach ($item['category_names'] as $categoryName): ?>

                                                            <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-cyan-50 dark:bg-cyan-900/30 text-cyan-700 dark:text-cyan-400"><?= htmlspecialchars($categoryName) ?></span>

                                                        <?php endforeach; ?>
                                                    </div>

                                                <?php endif; ?>
                                            </td>
                                            <td class="px-4 py-3 text-center">

                                                <?php if ($item['peserta_count'] > 0): ?>
                                                    <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium bg-archery-50 dark:bg-archery-900/30 text-archery-700 dark:text-archery-400">
                                                        <i class="fas fa-users text-xs"></i>

                                                        <?= $item['peserta_count'] ?>
                                                    </span>

                                                <?php else: ?>
                                                    <span class="text-slate-400 dark:text-zinc-500 text-sm">-</span>

                                                <?php endif; ?>
                                            </td>
                                            <td class="px-4 py-3">
                                                <div class="flex items-center justify-center gap-1">

                                                    <a href="peserta.view.php?add_peserta=1&kegiatan_id=<?php echo $item['id']?>" class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-purple-50 dark:bg-purple-900/30 text-purple-700 dark:text-purple-400 text-xs font-medium hover:bg-purple-100 dark:hover:bg-purple-900/50 transition-colors">
                                                        <i class="fas fa-user-plus text-xs"></i> Daftar
                                                    </a>

                                                    <a href="detail.php?id=<?php echo $item['id']?>" class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400 text-xs font-medium hover:bg-blue-100 dark:hover:bg-blue-900/50 transition-colors">
                                                        <i class="fas fa-eye text-xs"></i> Detail
                                                    </a>

                                                    <button onclick="editData(<?= $item['id'] ?>)" class="p-1.5 rounded-lg text-slate-400 dark:text-zinc-500 hover:text-amber-600 dark:hover:text-amber-400 hover:bg-amber-50 dark:hover:bg-amber-900/30 transition-colors" title="Edit">
                                                        <i class="fas fa-edit text-sm"></i>
                                                    </button>

                                                    <button onclick="deleteData(<?= $item['id'] ?>, '<?= addslashes(htmlspecialchars($item['nama'])) ?>')" class="p-1.5 rounded-lg text-slate-400 dark:text-zinc-500 hover:text-red-600 dark:hover:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/30 transition-colors" title="Hapus">
                                                        <i class="fas fa-trash text-sm"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>

                                    <?php endforeach; ?>

                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if (!empty($kegiatanData)): ?>
                    <div class="px-4 py-3 bg-slate-50 dark:bg-zinc-800/50 border-t border-slate-100 dark:border-zinc-800 text-sm text-slate-500 dark:text-zinc-400">

                        Menampilkan <?= count($kegiatanData) ?> kegiatan
                    </div>

                    <?php endif; ?>
                </div>

                <!-- Mobile Cards -->
                <div id="mobileCards" class="md:hidden space-y-3">

                    <?php if (empty($kegiatanData)): ?>
                        <div class="bg-white dark:bg-zinc-900 rounded-xl border border-slate-200 dark:border-zinc-800 p-8 text-center">
                            <div class="w-16 h-16 rounded-full bg-slate-100 dark:bg-zinc-800 flex items-center justify-center mx-auto mb-3">
                                <i class="fas fa-calendar-times text-slate-400 dark:text-zinc-500 text-2xl"></i>
                            </div>
                            <p class="text-slate-500 font-medium mb-3">Tidak ada data kegiatan</p>
                            <button onclick="openModal()" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-archery-600 text-white text-sm font-medium">
                                <i class="fas fa-plus"></i> Tambah Kegiatan
                            </button>
                        </div>

                    <?php else: ?>

                        <?php foreach ($kegiatanData as $index => $item): ?>

                            <div class="bg-white dark:bg-zinc-900 rounded-xl border border-slate-200 dark:border-zinc-800 shadow-sm p-4" data-id="<?= $item['id'] ?>">
                                <div class="flex items-start gap-3 mb-3">

                                    <span class="text-sm text-slate-400 dark:text-zinc-500 font-medium w-6"><?= $index + 1 ?></span>
                                    <div class="flex-1 min-w-0">

                                        <p class="font-semibold text-slate-900 dark:text-white"><?= htmlspecialchars($item['nama']) ?></p>
                                        <div class="flex items-center gap-2 mt-1 text-xs text-slate-500 dark:text-zinc-400">

                                            <?php if ($item['peserta_count'] > 0): ?>
                                                <span class="inline-flex items-center gap-1">
                                                    <i class="fas fa-users text-archery-500"></i>

                                                    <?= $item['peserta_count'] ?> peserta
                                                </span>

                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="mb-3">

                                    <?php if (empty($item['category_names'])): ?>
                                        <span class="text-slate-400 dark:text-zinc-500 italic text-sm">Belum ada kategori</span>

                                    <?php else: ?>
                                        <div class="flex flex-wrap gap-1">

                                            <?php foreach ($item['category_names'] as $categoryName): ?>

                                                <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-cyan-50 dark:bg-cyan-900/30 text-cyan-700 dark:text-cyan-400"><?= htmlspecialchars($categoryName) ?></span>

                                            <?php endforeach; ?>
                                        </div>

                                    <?php endif; ?>
                                </div>
                                <div class="grid grid-cols-2 gap-2 pt-3 border-t border-slate-100 dark:border-zinc-800">

                                    <a href="peserta.view.php?add_peserta=1&kegiatan_id=<?php echo $item['id']?>" class="inline-flex items-center justify-center gap-1 px-3 py-2 rounded-lg bg-purple-50 dark:bg-purple-900/30 text-purple-700 dark:text-purple-400 text-xs font-medium">
                                        <i class="fas fa-user-plus"></i> Daftar
                                    </a>

                                    <a href="detail.php?id=<?php echo $item['id']?>" class="inline-flex items-center justify-center gap-1 px-3 py-2 rounded-lg bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400 text-xs font-medium">
                                        <i class="fas fa-eye"></i> Detail
                                    </a>

                                    <button onclick="editData(<?= $item['id'] ?>)" class="inline-flex items-center justify-center gap-1 px-3 py-2 rounded-lg bg-amber-50 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400 text-xs font-medium">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>

                                    <button onclick="deleteData(<?= $item['id'] ?>, '<?= addslashes(htmlspecialchars($item['nama'] ?? '')) ?>')" class="inline-flex items-center justify-center gap-1 px-3 py-2 rounded-lg bg-red-50 dark:bg-red-900/30 text-red-700 dark:text-red-400 text-xs font-medium">
                                        <i class="fas fa-trash"></i> Hapus
                                    </button>
                                </div>
                            </div>

                        <?php endforeach; ?>

                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- Add/Edit Modal -->
    <div id="myModal" class="modal-backdrop">
        <div class="bg-white dark:bg-zinc-900 rounded-2xl shadow-xl w-full max-w-lg mx-4 max-h-[90vh] overflow-hidden flex flex-col">
            <div class="bg-gradient-to-br from-archery-600 to-archery-800 text-white px-6 py-4 flex items-center justify-between flex-shrink-0">
                <h3 id="modalTitle" class="font-semibold text-lg flex items-center gap-2">
                    <i class="fas fa-calendar-plus"></i> Tambah Data Kegiatan
                </h3>
                <button onclick="closeModal()" class="p-2 rounded-lg hover:bg-white/10 transition-colors">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form id="kegiatanForm" method="POST" class="flex-1 overflow-y-auto">
                <div class="p-6 space-y-4">
                    <input type="hidden" id="editId" name="editId" value="">

                    <?php csrf_field(); ?>

                    <div>
                        <label for="namaKegiatan" class="block text-sm font-medium text-slate-700 dark:text-zinc-300 mb-1">
                            Nama Kegiatan <span class="text-red-500">*</span>
                        </label>
                        <input type="text" id="namaKegiatan" name="namaKegiatan" required
                               class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-archery-500 focus:border-archery-500"
                               placeholder="Masukkan nama kegiatan">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-zinc-300 mb-2">
                            Pilih Kategori <span class="text-red-500">*</span>
                        </label>
                        <div class="border border-slate-200 dark:border-zinc-700 rounded-lg max-h-64 overflow-y-auto custom-scrollbar divide-y divide-slate-100 dark:divide-zinc-800">

                            <?php foreach ($kategoriList as $kategori): ?>

                                <div class="p-3 hover:bg-slate-50 dark:hover:bg-zinc-800 transition-colors cursor-pointer" onclick="toggleCheckbox('kategori_<?= $kategori['id']; ?>')">
                                    <label class="flex items-start gap-3 cursor-pointer">

                                        <input type="checkbox" name="kategori[]" value="<?= $kategori['id']; ?>" id="kategori_<?= $kategori['id']; ?>"
                                               class="mt-1 rounded text-archery-600 focus:ring-archery-500">
                                        <div class="flex-1">

                                            <p class="font-medium text-slate-900 dark:text-white capitalize"><?= htmlspecialchars($kategori['name']); ?></p>
                                            <div class="flex items-center gap-2 mt-0.5">

                                                <span class="text-[10px] px-1.5 py-0.5 rounded bg-slate-100 dark:bg-zinc-700 text-slate-500 dark:text-zinc-400 font-medium">Lahir <?= date("Y") - $kategori['max_age']; ?> – <?= date("Y") - $kategori['min_age']; ?></span>

                                                <span class="text-[10px] px-1.5 py-0.5 rounded bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400 font-medium"><?= htmlspecialchars($kategori['gender']); ?></span>
                                            </div>
                                        </div>
                                    </label>
                                </div>

                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="px-6 py-4 bg-slate-50 dark:bg-zinc-800/50 border-t border-slate-200 dark:border-zinc-700 flex gap-3 flex-shrink-0">
                    <button type="button" onclick="closeModal()" class="flex-1 px-4 py-2 rounded-lg border border-slate-300 dark:border-zinc-600 text-slate-700 dark:text-zinc-300 text-sm font-medium hover:bg-slate-100 dark:hover:bg-zinc-700 transition-colors">
                        Batal
                    </button>
                    <button type="submit" class="flex-1 px-4 py-2 rounded-lg bg-archery-600 text-white text-sm font-medium hover:bg-archery-700 transition-colors">
                        <i class="fas fa-save mr-1"></i> Simpan
                    </button>
                </div>
            </form>
        </div>
    </div>
    <!-- Delete Modal replaced by showConfirmModal -->
    <form id="deleteForm" method="POST" style="display: none;">
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" id="delete_id">
    </form>

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
// Data kegiatan dari PHP

const kegiatanData = <?= json_encode($kegiatanData) ?>;

function closeModal() {
    document.getElementById('myModal').classList.remove('active');
    document.body.style.overflow = 'auto';
}

// Toast functions
function dismissToast() {
    const toast = document.getElementById('toast');
    if (toast) {
        toast.classList.remove('toast-enter');
        toast.classList.add('toast-exit');
        setTimeout(() => toast.remove(), 300);
    }
}

// Auto dismiss toast after 5 seconds
setTimeout(dismissToast, 5000);

function toggleCheckbox(id) {
    const checkbox = document.getElementById(id);
    checkbox.checked = !checkbox.checked;
}

function openModal() {
    document.getElementById('myModal').classList.add('active');
    document.getElementById('modalTitle').innerHTML = '<i class="fas fa-calendar-plus"></i> Tambah Data Kegiatan';
    document.getElementById('namaKegiatan').value = '';
    document.getElementById('editId').value = '';
    document.body.style.overflow = 'hidden';

    const checkboxes = document.querySelectorAll('input[name="kategori[]"]');
    checkboxes.forEach(checkbox => checkbox.checked = false);
}

function editData(id) {
    const item = kegiatanData.find(data => data.id == id);
    if (item) {
        document.getElementById('myModal').classList.add('active');
        document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Data Kegiatan';
        document.getElementById('namaKegiatan').value = item.nama;
        document.getElementById('editId').value = item.id;
        document.body.style.overflow = 'hidden';

        const checkboxes = document.querySelectorAll('input[name="kategori[]"]');
        checkboxes.forEach(checkbox => checkbox.checked = false);

        if (item.category_ids && item.category_ids.length > 0) {
            item.category_ids.forEach(categoryId => {
                const checkbox = document.querySelector(`input[name="kategori[]"][value="${categoryId}"]`);
                if (checkbox) {
                    checkbox.checked = true;
                }
            });
        }
    }
}

function deleteData(id, name) {
    showConfirmModal(
        'Hapus Kegiatan',
        `Apakah Anda yakin ingin menghapus kegiatan <strong class="text-red-600 dark:text-red-400">${name}</strong>?<br><br><span class="text-xs text-red-500 italic font-semibold">PERINGATAN: Semua data PESERTA dan SKOR dalam kegiatan ini juga akan dihapus permanen!</span>`,
        () => {
            document.getElementById('delete_id').value = id;
            document.getElementById('deleteForm').submit();
        },
        'danger'
    );
}

// Form validation
document.getElementById('kegiatanForm').addEventListener('submit', function(e) {
    const selectedCategories = document.querySelectorAll('input[name="kategori[]"]:checked');
    if (selectedCategories.length === 0) {
        e.preventDefault();
        alert('Pilih minimal satu kategori!');
        return false;
    }

    // Show loading state
    const submitBtn = this.querySelector('button[type="submit"]');
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Loading...';
    }
});

// Close modal on backdrop click
document.getElementById('myModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeModal();
    }
});

// Close modal on Escape
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeModal();
});

// Prevent checkbox click bubbling
document.querySelectorAll('input[type="checkbox"]').forEach(checkbox => {
    checkbox.addEventListener('click', function(e) {
        e.stopPropagation();
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

// Theme Toggle

<?= getThemeToggleScript() ?>
</script>
    <?= getConfirmationModal() ?>
    <?= getUiScripts() ?>
</body>
</html>
<?php if (isset($conn)) $conn->close(); ?>
