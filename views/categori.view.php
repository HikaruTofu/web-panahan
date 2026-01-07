<?php
include '../config/panggil.php';
include '../includes/check_access.php';
include '../includes/theme.php';
requireAdmin();

// Toast message handling
$toast_message = '';
$toast_type = '';

// Handle CRUD operations
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'create':
                $name = mysqli_real_escape_string($conn, $_POST['name']);
                $min_age = (int)$_POST['min_age'];
                $max_age = (int)$_POST['max_age'];
                $gender = mysqli_real_escape_string($conn, $_POST['gender'] ?? 'Campuran');
                $max_participants = (int)($_POST['quota'] ?? 0);

                $stmt = $conn->prepare("INSERT INTO categories (name, min_age, max_age, gender, max_participants) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param("siisi", $name, $min_age, $max_age, $gender, $max_participants);
                if ($stmt->execute()) {
                    $toast_message = "Kategori '$name' berhasil ditambahkan!";
                    $toast_type = 'success';
                } else {
                    $toast_message = "Gagal menambahkan kategori!";
                    $toast_type = 'error';
                }
                $stmt->close();
                break;

            case 'update':
                $id = (int)$_POST['id'];
                $name = mysqli_real_escape_string($conn, $_POST['name']);
                $min_age = (int)$_POST['min_age'];
                $max_age = (int)$_POST['max_age'];
                $gender = mysqli_real_escape_string($conn, $_POST['gender'] ?? 'Campuran');
                $max_participants = (int)($_POST['quota'] ?? 0);

                $stmt = $conn->prepare("UPDATE categories SET name=?, min_age=?, max_age=?, gender=?, max_participants=? WHERE id=?");
                $stmt->bind_param("siisii", $name, $min_age, $max_age, $gender, $max_participants, $id);
                if ($stmt->execute()) {
                    $toast_message = "Kategori '$name' berhasil diperbarui!";
                    $toast_type = 'success';
                } else {
                    $toast_message = "Gagal memperbarui kategori!";
                    $toast_type = 'error';
                }
                $stmt->close();
                break;

            case 'delete':
                $id = (int)$_POST['id'];
                // Get name first for toast
                $nameResult = $conn->query("SELECT name FROM categories WHERE id = $id");
                $catName = $nameResult->fetch_assoc()['name'] ?? 'Kategori';

                $stmt = $conn->prepare("DELETE FROM categories WHERE id=?");
                $stmt->bind_param("i", $id);
                if ($stmt->execute()) {
                    $toast_message = "Kategori '$catName' berhasil dihapus!";
                    $toast_type = 'success';
                } else {
                    $toast_message = "Gagal menghapus kategori!";
                    $toast_type = 'error';
                }
                $stmt->close();
                break;
        }
    }
}

// Search functionality
$search = '';
$sql = "SELECT c.*,
        COALESCE((SELECT COUNT(*) FROM peserta p WHERE p.category_id = c.id), 0) as registered_count
        FROM categories c";
if (isset($_GET['q']) && !empty($_GET['q'])) {
    $search = mysqli_real_escape_string($conn, $_GET['q']);
    $sql .= " WHERE c.name LIKE '%$search%' OR c.min_age LIKE '%$search%' OR c.max_age LIKE '%$search%'";
}
$sql .= " ORDER BY c.min_age ASC, c.name ASC";
$result = $conn->query($sql);

// Calculate statistics for metrics bar
$statsQuery = "SELECT
    COUNT(*) as total_categories,
    SUM(COALESCE(c.max_participants, 0)) as total_quota,
    (SELECT COUNT(*) FROM peserta) as total_registered,
    SUM(CASE WHEN c.gender = 'Laki-laki' THEN 1 ELSE 0 END) as male_categories,
    SUM(CASE WHEN c.gender = 'Perempuan' THEN 1 ELSE 0 END) as female_categories,
    SUM(CASE WHEN c.gender = 'Campuran' THEN 1 ELSE 0 END) as mixed_categories
    FROM categories c";
$statsResult = $conn->query($statsQuery);
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
    <title>Data Categories - Turnamen Panahan</title>
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
        /* Modal backdrop */
        .modal-backdrop { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 40; }
        .modal-backdrop.active { display: flex; align-items: center; justify-content: center; }
        /* Toast animation */
        .toast-enter { animation: slideIn 0.3s ease-out; }
        .toast-exit { animation: slideOut 0.3s ease-in forwards; }
        @keyframes slideIn { from { transform: translateY(-100%); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        @keyframes slideOut { from { transform: translateY(0); opacity: 1; } to { transform: translateY(-100%); opacity: 0; } }
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
                    <a href="categori.view.php" class="flex items-center gap-3 px-4 py-3 rounded-lg bg-archery-600/20 text-archery-400 border border-archery-600/30">
                        <i class="fas fa-tags w-5"></i>
                        <span class="text-sm font-medium">Kategori</span>
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
                <!-- Compact Header with Metrics -->
                <div class="bg-white dark:bg-zinc-900 rounded-xl border border-slate-200 dark:border-zinc-800 shadow-sm mb-6">
                    <div class="px-6 py-4 border-b border-slate-100 dark:border-zinc-800">
                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                            <div class="flex items-center gap-3">
                                <a href="dashboard.php" class="p-2 rounded-lg text-slate-400 dark:text-zinc-500 hover:bg-slate-100 dark:hover:bg-zinc-800 transition-colors">
                                    <i class="fas fa-arrow-left"></i>
                                </a>
                                <div>
                                    <h1 class="text-lg font-semibold text-slate-900 dark:text-white">Data Kategori</h1>
                                    <p class="text-sm text-slate-500 dark:text-zinc-400">Kelola kategori umur & kuota peserta</p>
                                </div>
                            </div>
                            <button onclick="openModal('addModal')" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-archery-600 text-white text-sm font-medium hover:bg-archery-700 transition-colors">
                                <i class="fas fa-plus"></i>
                                <span class="hidden sm:inline">Tambah Kategori</span>
                            </button>
                        </div>
                    </div>

                    <!-- Metrics Bar -->
                    <div class="px-6 py-3 bg-slate-50 dark:bg-zinc-800/50 flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
                        <div class="flex items-center gap-2">
                            <span class="text-2xl font-bold text-slate-900 dark:text-white"><?= $stats['total_categories'] ?? 0 ?></span>
                            <span class="text-slate-500 dark:text-zinc-400">Kategori</span>
                        </div>
                        <span class="text-slate-300 dark:text-zinc-600 hidden sm:inline">|</span>
                        <div class="flex items-center gap-1.5">
                            <i class="fas fa-mars text-blue-500 text-xs"></i>
                            <span class="font-medium text-slate-700 dark:text-zinc-300"><?= $stats['male_categories'] ?? 0 ?></span>
                            <span class="text-slate-400 dark:text-zinc-500">Putra</span>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <i class="fas fa-venus text-pink-500 text-xs"></i>
                            <span class="font-medium text-slate-700 dark:text-zinc-300"><?= $stats['female_categories'] ?? 0 ?></span>
                            <span class="text-slate-400 dark:text-zinc-500">Putri</span>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <i class="fas fa-venus-mars text-purple-500 text-xs"></i>
                            <span class="font-medium text-slate-700 dark:text-zinc-300"><?= $stats['mixed_categories'] ?? 0 ?></span>
                            <span class="text-slate-400 dark:text-zinc-500">Campuran</span>
                        </div>
                        <span class="text-slate-300 dark:text-zinc-600 hidden sm:inline">|</span>
                        <div class="flex items-center gap-1.5">
                            <i class="fas fa-users text-archery-500 text-xs"></i>
                            <span class="font-medium text-slate-700 dark:text-zinc-300"><?= $stats['total_registered'] ?? 0 ?></span>
                            <span class="text-slate-400 dark:text-zinc-500">Terdaftar</span>
                        </div>
                        <?php if (($stats['total_quota'] ?? 0) > 0): ?>
                        <div class="flex items-center gap-1.5">
                            <span class="text-slate-400 dark:text-zinc-500">/</span>
                            <span class="font-medium text-slate-700 dark:text-zinc-300"><?= $stats['total_quota'] ?></span>
                            <span class="text-slate-400 dark:text-zinc-500">Kuota</span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Search Bar -->
                <div class="flex flex-col sm:flex-row gap-4 mb-6">
                    <form method="get" class="flex-1 flex gap-2">
                        <div class="relative flex-1">
                            <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 dark:text-zinc-500 text-xs"></i>
                            <input type="search" name="q" value="<?= htmlspecialchars($search) ?>"
                                   class="w-full pl-9 pr-4 py-2 rounded-lg border border-slate-200 dark:border-zinc-700 text-sm focus:ring-2 focus:ring-archery-500 focus:border-archery-500 bg-slate-50 dark:bg-zinc-800 text-slate-900 dark:text-white"
                                   placeholder="Cari kategori...">
                        </div>
                        <button type="submit" class="px-4 py-2 rounded-lg bg-archery-600 text-white text-sm font-medium hover:bg-archery-700 transition-colors">
                            <i class="fas fa-search sm:hidden"></i>
                            <span class="hidden sm:inline">Cari</span>
                        </button>
                        <?php if (!empty($search)): ?>
                        <a href="?" class="px-3 py-2 rounded-lg border border-slate-200 dark:border-zinc-700 text-slate-500 dark:text-zinc-400 text-sm hover:bg-slate-50 dark:hover:bg-zinc-800 transition-colors">
                            <i class="fas fa-times"></i>
                        </a>
                        <?php endif; ?>
                    </form>
                </div>

                <!-- Data Table -->
                <div class="bg-white dark:bg-zinc-900 rounded-xl border border-slate-200 dark:border-zinc-800 overflow-hidden">
                    <div class="overflow-x-auto custom-scrollbar">
                        <table class="w-full">
                            <thead class="bg-slate-100 dark:bg-zinc-800 sticky top-0 z-10">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 dark:text-zinc-400 uppercase tracking-wider w-12">#</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 dark:text-zinc-400 uppercase tracking-wider">Kategori</th>
                                    <th class="px-4 py-3 text-center text-xs font-semibold text-slate-600 dark:text-zinc-400 uppercase tracking-wider w-28">Rentang Umur</th>
                                    <th class="px-4 py-3 text-center text-xs font-semibold text-slate-600 dark:text-zinc-400 uppercase tracking-wider w-24">Gender</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 dark:text-zinc-400 uppercase tracking-wider w-48">Kuota & Kapasitas</th>
                                    <th class="px-4 py-3 text-center text-xs font-semibold text-slate-600 dark:text-zinc-400 uppercase tracking-wider w-24">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-zinc-800">
                                <?php
                                if ($result->num_rows > 0):
                                    $no = 1;
                                    while ($row = $result->fetch_assoc()):
                                        $quota = (int)($row['max_participants'] ?? 0);
                                        $registered = (int)($row['registered_count'] ?? 0);
                                        $percentage = $quota > 0 ? min(100, round(($registered / $quota) * 100)) : 0;
                                        $isOverCapacity = $quota > 0 && $registered > $quota;
                                        $gender = $row['gender'] ?? 'Campuran';
                                ?>
                                <tr class="hover:bg-slate-50 dark:hover:bg-zinc-800 transition-colors">
                                    <td class="px-4 py-3 text-sm text-slate-500 dark:text-zinc-400"><?= $no++; ?></td>
                                    <td class="px-4 py-3">
                                        <p class="font-medium text-slate-900 dark:text-white"><?= htmlspecialchars($row['name']); ?></p>
                                        <p class="text-xs text-slate-400 dark:text-zinc-500">Lahir <?= date("Y") - $row['max_age']; ?> – <?= date("Y") - $row['min_age']; ?></p>
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-slate-100 dark:bg-zinc-700 text-slate-700 dark:text-zinc-300">
                                            <?= $row['min_age']; ?> – <?= $row['max_age']; ?> th
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        <?php if ($gender === 'Laki-laki'): ?>
                                            <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400">
                                                <i class="fas fa-mars"></i> Putra
                                            </span>
                                        <?php elseif ($gender === 'Perempuan'): ?>
                                            <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium bg-pink-50 dark:bg-pink-900/30 text-pink-700 dark:text-pink-400">
                                                <i class="fas fa-venus"></i> Putri
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium bg-purple-50 dark:bg-purple-900/30 text-purple-700 dark:text-purple-400">
                                                <i class="fas fa-venus-mars"></i> Campuran
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3">
                                        <?php if ($quota > 0): ?>
                                            <!-- Usage Bar -->
                                            <div class="flex items-center gap-3">
                                                <div class="flex-1">
                                                    <div class="h-2 bg-slate-200 dark:bg-zinc-700 rounded-full overflow-hidden">
                                                        <div class="h-full rounded-full transition-all duration-300 <?= $isOverCapacity ? 'bg-red-500' : ($percentage >= 80 ? 'bg-amber-500' : 'bg-archery-500') ?>"
                                                             style="width: <?= min(100, $percentage) ?>%"></div>
                                                    </div>
                                                </div>
                                                <span class="text-xs font-medium <?= $isOverCapacity ? 'text-red-600 dark:text-red-400' : 'text-slate-600 dark:text-zinc-400' ?> whitespace-nowrap">
                                                    <?= $registered ?>/<?= $quota ?>
                                                </span>
                                            </div>
                                            <p class="text-xs <?= $isOverCapacity ? 'text-red-500 dark:text-red-400' : ($percentage >= 80 ? 'text-amber-500 dark:text-amber-400' : 'text-slate-400 dark:text-zinc-500') ?> mt-1">
                                                <?php if ($isOverCapacity): ?>
                                                    <i class="fas fa-exclamation-triangle"></i> Melebihi kuota!
                                                <?php elseif ($percentage >= 80): ?>
                                                    <i class="fas fa-info-circle"></i> Hampir penuh (<?= $percentage ?>%)
                                                <?php else: ?>
                                                    Tersedia <?= $quota - $registered ?> slot
                                                <?php endif; ?>
                                            </p>
                                        <?php else: ?>
                                            <div class="flex items-center gap-2">
                                                <span class="text-sm text-slate-600 dark:text-zinc-400"><?= $registered ?> terdaftar</span>
                                                <span class="text-xs text-slate-400 dark:text-zinc-500">(tanpa batas)</span>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="flex items-center justify-center gap-1">
                                            <button onclick="editData(<?= $row['id'] ?>, '<?= addslashes($row['name']) ?>', <?= $row['min_age'] ?>, <?= $row['max_age'] ?>, '<?= addslashes($gender) ?>', <?= $quota ?>)"
                                                    class="p-1.5 rounded-lg text-slate-400 dark:text-zinc-500 hover:text-amber-600 dark:hover:text-amber-400 hover:bg-amber-50 dark:hover:bg-amber-900/30 transition-colors" title="Edit">
                                                <i class="fas fa-edit text-sm"></i>
                                            </button>
                                            <button onclick="deleteData(<?= $row['id'] ?>, '<?= addslashes($row['name']) ?>')"
                                                    class="p-1.5 rounded-lg text-slate-400 dark:text-zinc-500 hover:text-red-600 dark:hover:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/30 transition-colors" title="Hapus">
                                                <i class="fas fa-trash text-sm"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php
                                    endwhile;
                                else:
                                ?>
                                <tr>
                                    <td colspan="6" class="px-4 py-12">
                                        <div class="flex flex-col items-center text-center">
                                            <div class="w-16 h-16 rounded-full bg-slate-100 dark:bg-zinc-800 flex items-center justify-center mb-3">
                                                <i class="fas fa-inbox text-slate-400 dark:text-zinc-500 text-2xl"></i>
                                            </div>
                                            <p class="text-slate-500 dark:text-zinc-400 font-medium">Tidak ada data kategori</p>
                                            <p class="text-slate-400 dark:text-zinc-500 text-sm mb-4">Silakan tambahkan kategori baru</p>
                                            <button onclick="openModal('addModal')" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-archery-600 text-white text-sm font-medium hover:bg-archery-700 transition-colors">
                                                <i class="fas fa-plus"></i> Tambah Kategori
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($result->num_rows > 0): ?>
                    <div class="px-4 py-3 bg-slate-50 dark:bg-zinc-800/50 border-t border-slate-100 dark:border-zinc-800 text-sm text-slate-500 dark:text-zinc-400">
                        Menampilkan <?= $result->num_rows ?> kategori<?php if (!empty($search)): ?> <span class="text-slate-400 dark:text-zinc-500">• filtered</span><?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- Add Modal -->
    <div id="addModal" class="modal-backdrop">
        <div class="bg-white dark:bg-zinc-900 rounded-2xl shadow-xl w-full max-w-md mx-4 overflow-hidden">
            <div class="bg-gradient-to-br from-archery-600 to-archery-800 text-white px-6 py-4 flex items-center justify-between">
                <h3 class="font-semibold text-lg flex items-center gap-2">
                    <i class="fas fa-plus-circle"></i> Tambah Kategori
                </h3>
                <button onclick="closeModal('addModal')" class="p-2 rounded-lg hover:bg-white/10 transition-colors">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="POST">
                <div class="p-6 space-y-4">
                    <input type="hidden" name="action" value="create">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-zinc-300 mb-1">Nama Kategori <span class="text-red-500">*</span></label>
                        <input type="text" name="name" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-archery-500 focus:border-archery-500" placeholder="Contoh: Junior A" required>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-zinc-300 mb-1">Min Age <span class="text-red-500">*</span></label>
                            <input type="number" name="min_age" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-archery-500" placeholder="0" required>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-zinc-300 mb-1">Max Age <span class="text-red-500">*</span></label>
                            <input type="number" name="max_age" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-archery-500" placeholder="99" required>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-zinc-300 mb-1">Gender</label>
                        <select name="gender" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-archery-500">
                            <option value="Campuran">Campuran (Putra & Putri)</option>
                            <option value="Laki-laki">Putra</option>
                            <option value="Perempuan">Putri</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-zinc-300 mb-1">Kuota Peserta</label>
                        <input type="number" name="quota" min="0" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-archery-500" placeholder="0 = Tanpa Batas">
                        <p class="text-xs text-slate-400 dark:text-zinc-500 mt-1">Kosongkan atau isi 0 untuk tanpa batas kuota</p>
                    </div>
                </div>
                <div class="px-6 py-4 bg-slate-50 dark:bg-zinc-800/50 border-t border-slate-200 dark:border-zinc-700 flex gap-3">
                    <button type="button" onclick="closeModal('addModal')" class="flex-1 px-4 py-2 rounded-lg border border-slate-300 dark:border-zinc-600 text-slate-700 dark:text-zinc-300 text-sm font-medium hover:bg-slate-100 dark:hover:bg-zinc-700 transition-colors">
                        Batal
                    </button>
                    <button type="submit" class="flex-1 px-4 py-2 rounded-lg bg-archery-600 text-white text-sm font-medium hover:bg-archery-700 transition-colors">
                        <i class="fas fa-save mr-1"></i> Simpan
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Modal -->
    <div id="editModal" class="modal-backdrop">
        <div class="bg-white dark:bg-zinc-900 rounded-2xl shadow-xl w-full max-w-md mx-4 overflow-hidden">
            <div class="bg-gradient-to-br from-blue-600 to-blue-800 text-white px-6 py-4 flex items-center justify-between">
                <h3 class="font-semibold text-lg flex items-center gap-2">
                    <i class="fas fa-edit"></i> Edit Kategori
                </h3>
                <button onclick="closeModal('editModal')" class="p-2 rounded-lg hover:bg-white/10 transition-colors">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="POST">
                <div class="p-6 space-y-4">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="id" id="edit_id">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-zinc-300 mb-1">Nama Kategori <span class="text-red-500">*</span></label>
                        <input type="text" name="name" id="edit_name" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" required>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-zinc-300 mb-1">Min Age <span class="text-red-500">*</span></label>
                            <input type="number" name="min_age" id="edit_min_age" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-blue-500" required>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-zinc-300 mb-1">Max Age <span class="text-red-500">*</span></label>
                            <input type="number" name="max_age" id="edit_max_age" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-blue-500" required>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-zinc-300 mb-1">Gender</label>
                        <select name="gender" id="edit_gender" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-blue-500">
                            <option value="Campuran">Campuran (Putra & Putri)</option>
                            <option value="Laki-laki">Putra</option>
                            <option value="Perempuan">Putri</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-zinc-300 mb-1">Kuota Peserta</label>
                        <input type="number" name="quota" id="edit_quota" min="0" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-blue-500" placeholder="0 = Tanpa Batas">
                    </div>
                </div>
                <div class="px-6 py-4 bg-slate-50 dark:bg-zinc-800/50 border-t border-slate-200 dark:border-zinc-700 flex gap-3">
                    <button type="button" onclick="closeModal('editModal')" class="flex-1 px-4 py-2 rounded-lg border border-slate-300 dark:border-zinc-600 text-slate-700 dark:text-zinc-300 text-sm font-medium hover:bg-slate-100 dark:hover:bg-zinc-700 transition-colors">
                        Batal
                    </button>
                    <button type="submit" class="flex-1 px-4 py-2 rounded-lg bg-blue-600 text-white text-sm font-medium hover:bg-blue-700 transition-colors">
                        <i class="fas fa-sync-alt mr-1"></i> Update
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Modal -->
    <div id="deleteModal" class="modal-backdrop">
        <div class="bg-white dark:bg-zinc-900 rounded-2xl shadow-xl w-full max-w-md mx-4 overflow-hidden">
            <div class="bg-gradient-to-br from-red-600 to-red-800 text-white px-6 py-4 flex items-center justify-between">
                <h3 class="font-semibold text-lg flex items-center gap-2">
                    <i class="fas fa-exclamation-triangle"></i> Hapus Kategori
                </h3>
                <button onclick="closeModal('deleteModal')" class="p-2 rounded-lg hover:bg-white/10 transition-colors">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="p-6">
                <p class="text-slate-700 dark:text-zinc-300">Apakah Anda yakin ingin menghapus kategori <strong id="delete_name" class="text-red-600 dark:text-red-400"></strong>?</p>
                <p class="text-sm text-slate-500 dark:text-zinc-400 mt-2">
                    <i class="fas fa-info-circle mr-1"></i> Tindakan ini tidak dapat dibatalkan!
                </p>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" id="delete_id">
                <div class="px-6 py-4 bg-slate-50 dark:bg-zinc-800/50 border-t border-slate-200 dark:border-zinc-700 flex gap-3">
                    <button type="button" onclick="closeModal('deleteModal')" class="flex-1 px-4 py-2 rounded-lg border border-slate-300 dark:border-zinc-600 text-slate-700 dark:text-zinc-300 text-sm font-medium hover:bg-slate-100 dark:hover:bg-zinc-700 transition-colors">
                        Batal
                    </button>
                    <button type="submit" class="flex-1 px-4 py-2 rounded-lg bg-red-600 text-white text-sm font-medium hover:bg-red-700 transition-colors">
                        <i class="fas fa-trash mr-1"></i> Hapus
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
            <a href="users.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800">
                <i class="fas fa-users w-5"></i><span class="text-sm">Users</span>
            </a>
            <a href="categori.view.php" class="flex items-center gap-3 px-4 py-3 rounded-lg bg-archery-600/20 text-archery-400">
                <i class="fas fa-tags w-5"></i><span class="text-sm font-medium">Kategori</span>
            </a>
            <a href="kegiatan.view.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800">
                <i class="fas fa-calendar w-5"></i><span class="text-sm">Kegiatan</span>
            </a>
            <a href="peserta.view.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800">
                <i class="fas fa-user-friends w-5"></i><span class="text-sm">Peserta</span>
            </a>
            <a href="statistik.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800">
                <i class="fas fa-chart-bar w-5"></i><span class="text-sm">Statistik</span>
            </a>
        </nav>
        <div class="px-4 py-4 border-t border-zinc-800 mt-auto">
            <a href="../actions/logout.php" onclick="return confirm('Yakin ingin logout?')"
               class="flex items-center gap-2 w-full px-4 py-2 rounded-lg text-red-400 hover:bg-red-500/10 transition-colors text-sm">
                <i class="fas fa-sign-out-alt w-5"></i>
                <span>Logout</span>
            </a>
        </div>
    </div>

    <script>
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

        function editData(id, name, minAge, maxAge, gender, quota) {
            document.getElementById('edit_id').value = id;
            document.getElementById('edit_name').value = name;
            document.getElementById('edit_min_age').value = minAge;
            document.getElementById('edit_max_age').value = maxAge;
            document.getElementById('edit_gender').value = gender;
            document.getElementById('edit_quota').value = quota;
            openModal('editModal');
        }

        function deleteData(id, name) {
            document.getElementById('delete_id').value = id;
            document.getElementById('delete_name').textContent = name;
            openModal('deleteModal');
        }

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

        // Prevent double submission
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function() {
                const submitBtn = this.querySelector('button[type="submit"]');
                if (submitBtn) {
                    submitBtn.disabled = true;
                    const originalHtml = submitBtn.innerHTML;
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Loading...';
                }
            });
        });

        // Theme Toggle
        <?= getThemeToggleScript() ?>
    </script>
</body>
</html>
