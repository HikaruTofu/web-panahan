<?php
/**
 * User Management View
 * UI: Calm, Clear, Fast - Aligned with categori.view.php & kegiatan.view.php
 */
include '../config/panggil.php';
include '../includes/check_access.php';
requireAdmin();

// Filter by search query (UNCHANGED - same GET parameter name)
$searchQuery = isset($_GET['q']) ? trim($_GET['q']) : '';

if (!empty($searchQuery)) {
    $stmt = $conn->prepare("SELECT * FROM users WHERE name LIKE ? OR email LIKE ? ORDER BY id ASC");
    $searchLike = "%$searchQuery%";
    $stmt->bind_param("ss", $searchLike, $searchLike);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query("SELECT * FROM users ORDER BY id ASC");
}

if($_SESSION['role'] != 'admin') {
    header('Location: kegiatan.view.php');
    exit;
}

// Count by role
$adminCount = 0;
$userCount = 0;
$activeCount = 0;
$inactiveCount = 0;
$users = [];
while ($row = $result->fetch_assoc()) {
    $users[] = $row;
    if (strtolower($row['role']) === 'admin') $adminCount++;
    else $userCount++;
    if (strtolower($row['status'] ?? 'active') === 'active') $activeCount++;
    else $inactiveCount++;
}

$username = $_SESSION['username'] ?? 'User';
$name = $_SESSION['name'] ?? $username;
$role = $_SESSION['role'] ?? 'user';
?>
<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manajemen User - Turnamen Panahan</title>
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
    </style>
</head>
<body class="h-full bg-slate-50">
    <div class="flex h-full">
        <!-- Sidebar (consistent with Dashboard) -->
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
                    <a href="users.php" class="flex items-center gap-3 px-4 py-3 rounded-lg bg-archery-600/20 text-archery-400 border border-archery-600/30">
                        <i class="fas fa-users w-5"></i>
                        <span class="text-sm font-medium">Users</span>
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
                                <a href="dashboard.php" class="p-2 rounded-lg text-slate-400 hover:bg-slate-100 transition-colors">
                                    <i class="fas fa-arrow-left"></i>
                                </a>
                                <div>
                                    <h1 class="text-lg font-semibold text-slate-900">Manajemen User</h1>
                                    <p class="text-sm text-slate-500">Kelola akun pengguna sistem</p>
                                </div>
                            </div>
                            <a href="tambah-user.php" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-archery-600 text-white text-sm font-medium hover:bg-archery-700 transition-colors">
                                <i class="fas fa-plus"></i>
                                <span class="hidden sm:inline">Tambah User</span>
                            </a>
                        </div>
                    </div>

                    <!-- Metrics Bar -->
                    <div class="px-6 py-3 bg-slate-50 flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
                        <div class="flex items-center gap-2">
                            <span class="text-2xl font-bold text-slate-900"><?= count($users) ?></span>
                            <span class="text-slate-500">Total User</span>
                        </div>
                        <span class="text-slate-300 hidden sm:inline">|</span>
                        <div class="flex items-center gap-1.5">
                            <i class="fas fa-shield-alt text-purple-500 text-xs"></i>
                            <span class="font-medium text-slate-700"><?= $adminCount ?></span>
                            <span class="text-slate-400">Admin</span>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <i class="fas fa-user text-blue-500 text-xs"></i>
                            <span class="font-medium text-slate-700"><?= $userCount ?></span>
                            <span class="text-slate-400">User</span>
                        </div>
                        <span class="text-slate-300 hidden sm:inline">|</span>
                        <div class="flex items-center gap-1.5">
                            <i class="fas fa-check-circle text-emerald-500 text-xs"></i>
                            <span class="font-medium text-slate-700"><?= $activeCount ?></span>
                            <span class="text-slate-400">Aktif</span>
                        </div>
                        <?php if ($inactiveCount > 0): ?>
                        <div class="flex items-center gap-1.5">
                            <i class="fas fa-pause-circle text-slate-400 text-xs"></i>
                            <span class="font-medium text-slate-700"><?= $inactiveCount ?></span>
                            <span class="text-slate-400">Nonaktif</span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Search Bar -->
                <div class="flex flex-col sm:flex-row gap-4 mb-6">
                    <!-- FORM: method=get (UNCHANGED) -->
                    <form method="get" class="flex-1 flex gap-2">
                        <div class="relative flex-1">
                            <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                            <!-- INPUT: name="q" (UNCHANGED) -->
                            <input type="search" name="q" value="<?= htmlspecialchars($searchQuery) ?>"
                                   class="w-full pl-9 pr-4 py-2 rounded-lg border border-slate-200 text-sm focus:ring-2 focus:ring-archery-500 focus:border-archery-500 bg-slate-50"
                                   placeholder="Cari berdasarkan nama atau email...">
                        </div>
                        <button type="submit" class="px-4 py-2 rounded-lg bg-archery-600 text-white text-sm font-medium hover:bg-archery-700 transition-colors">
                            <i class="fas fa-search sm:hidden"></i>
                            <span class="hidden sm:inline">Cari</span>
                        </button>
                        <?php if (!empty($searchQuery)): ?>
                        <a href="?" class="px-3 py-2 rounded-lg border border-slate-200 text-slate-500 text-sm hover:bg-slate-50 transition-colors">
                            <i class="fas fa-times"></i>
                        </a>
                        <?php endif; ?>
                    </form>
                </div>

                <!-- Data Table -->
                <div class="bg-white rounded-xl border border-slate-200 overflow-hidden shadow-sm">
                    <div class="overflow-x-auto custom-scrollbar">
                        <table class="w-full">
                            <thead class="bg-slate-100 sticky top-0 z-10">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider w-12">#</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">User</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">Email</th>
                                    <th class="px-4 py-3 text-center text-xs font-semibold text-slate-600 uppercase tracking-wider w-28">Role</th>
                                    <th class="px-4 py-3 text-center text-xs font-semibold text-slate-600 uppercase tracking-wider w-28">Status</th>
                                    <th class="px-4 py-3 text-center text-xs font-semibold text-slate-600 uppercase tracking-wider w-24">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php if (count($users) > 0): ?>
                                    <?php $no = 1; foreach ($users as $row): ?>
                                        <tr class="hover:bg-slate-50 transition-colors">
                                            <td class="px-4 py-3 text-sm text-slate-500"><?= $no++ ?></td>
                                            <td class="px-4 py-3">
                                                <div class="flex items-center gap-3">
                                                    <div class="w-9 h-9 rounded-full bg-gradient-to-br from-archery-500 to-emerald-600 flex items-center justify-center text-white font-bold text-sm flex-shrink-0">
                                                        <?= strtoupper(substr($row['name'], 0, 1)) ?>
                                                    </div>
                                                    <p class="font-medium text-slate-900"><?= htmlspecialchars($row['name']) ?></p>
                                                </div>
                                            </td>
                                            <td class="px-4 py-3">
                                                <span class="text-sm text-slate-600"><?= htmlspecialchars($row['email']) ?></span>
                                            </td>
                                            <td class="px-4 py-3 text-center">
                                                <?php if (strtolower($row['role']) === 'admin'): ?>
                                                    <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium bg-purple-50 text-purple-700">
                                                        <i class="fas fa-shield-alt"></i> Admin
                                                    </span>
                                                <?php else: ?>
                                                    <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium bg-slate-100 text-slate-600">
                                                        <i class="fas fa-user"></i> User
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-4 py-3 text-center">
                                                <?php if (strtolower($row['status'] ?? 'active') === 'active'): ?>
                                                    <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium bg-emerald-50 text-emerald-700">
                                                        <i class="fas fa-check-circle"></i> Aktif
                                                    </span>
                                                <?php else: ?>
                                                    <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium bg-slate-100 text-slate-500">
                                                        <i class="fas fa-pause-circle"></i> Nonaktif
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-4 py-3">
                                                <div class="flex items-center justify-center gap-1">
                                                    <!-- LINK: edit-user.php?id=... (UNCHANGED) -->
                                                    <a href="edit-user.php?id=<?= $row['id'] ?>"
                                                       class="p-1.5 rounded-lg text-slate-400 hover:text-amber-600 hover:bg-amber-50 transition-colors" title="Edit">
                                                        <i class="fas fa-edit text-sm"></i>
                                                    </a>
                                                    <!-- LINK: hapus-user.php?id=... (UNCHANGED) -->
                                                    <a href="hapus-user.php?id=<?= $row['id'] ?>"
                                                       onclick="return confirm('Yakin ingin menghapus user <?= htmlspecialchars($row['name']) ?>?')"
                                                       class="p-1.5 rounded-lg text-slate-400 hover:text-red-600 hover:bg-red-50 transition-colors" title="Hapus">
                                                        <i class="fas fa-trash text-sm"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="px-4 py-12">
                                            <div class="flex flex-col items-center text-center">
                                                <div class="w-16 h-16 rounded-full bg-slate-100 flex items-center justify-center mb-3">
                                                    <i class="fas fa-users text-slate-400 text-2xl"></i>
                                                </div>
                                                <p class="text-slate-500 font-medium">Tidak ada user ditemukan</p>
                                                <?php if (!empty($searchQuery)): ?>
                                                    <p class="text-slate-400 text-sm mb-4">Ubah kata kunci pencarian</p>
                                                <?php else: ?>
                                                    <p class="text-slate-400 text-sm mb-4">Tambahkan user baru untuk memulai</p>
                                                    <a href="tambah-user.php" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-archery-600 text-white text-sm font-medium hover:bg-archery-700 transition-colors">
                                                        <i class="fas fa-plus"></i> Tambah User
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if (count($users) > 0): ?>
                    <div class="px-4 py-3 bg-slate-50 border-t border-slate-100 text-sm text-slate-500">
                        Menampilkan <?= count($users) ?> user<?php if (!empty($searchQuery)): ?> <span class="text-slate-400">• filtered</span><?php endif; ?>
                    </div>
                    <?php endif; ?>
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
        <nav class="px-4 py-6 space-y-1">
            <a href="dashboard.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800">
                <i class="fas fa-home w-5"></i><span class="text-sm">Dashboard</span>
            </a>
            <a href="users.php" class="flex items-center gap-3 px-4 py-3 rounded-lg bg-archery-600/20 text-archery-400">
                <i class="fas fa-users w-5"></i><span class="text-sm font-medium">Users</span>
            </a>
            <a href="categori.view.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800">
                <i class="fas fa-tags w-5"></i><span class="text-sm">Kategori</span>
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
    </script>
</body>
</html>
