<?php
/**
 * User Management View
 * UI: Calm, Clear, Fast - Aligned with categori.view.php & kegiatan.view.php
 */
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

// Toast message handling
$toast_message = '';
$toast_type = '';

// Handle CRUD operations
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if (!checkRateLimit('user_crud', 10, 60)) {
        $toast_message = "Terlalu banyak permintaan. Silakan coba lagi nanti.";
        $toast_type = 'error';
        goto skip_post;
    }
    verify_csrf();
    $_POST = cleanInput($_POST);
    switch ($_POST['action']) {
        case 'create':
            $name = mysqli_real_escape_string($conn, $_POST['name']);
            $email = mysqli_real_escape_string($conn, $_POST['email']);
            $password = $_POST['password'];
            $role = mysqli_real_escape_string($conn, $_POST['role']);
            $status = mysqli_real_escape_string($conn, $_POST['status'] ?? 'active');

            // Validation
            $checkEmail = $conn->prepare("SELECT id FROM users WHERE email = ?");
            $checkEmail->bind_param("s", $email);
            $checkEmail->execute();
            if ($checkEmail->get_result()->num_rows > 0) {
                $toast_message = "Email '$email' sudah terdaftar!";
                $toast_type = 'error';
            } else {
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("INSERT INTO users (name, email, password, role, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())");
                $stmt->bind_param("sssss", $name, $email, $hashed, $role, $status);
                if ($stmt->execute()) {
                    $toast_message = "User '$name' berhasil ditambahkan!";
                    $toast_type = 'success';
                } else {
                    $toast_message = "Gagal menambahkan user!";
                    $toast_type = 'error';
                }
                $stmt->close();
            }
            $checkEmail->close();
            break;

        case 'update':
            $id = (int)$_POST['id'];
            $name = mysqli_real_escape_string($conn, $_POST['name']);
            $email = mysqli_real_escape_string($conn, $_POST['email']);
            $role = mysqli_real_escape_string($conn, $_POST['role']);
            $status = mysqli_real_escape_string($conn, $_POST['status']);
            $password = $_POST['password'] ?? '';

            // Check if email exists for other user
            $checkEmail = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $checkEmail->bind_param("si", $email, $id);
            $checkEmail->execute();
            if ($checkEmail->get_result()->num_rows > 0) {
                $toast_message = "Email '$email' sudah digunakan user lain!";
                $toast_type = 'error';
            } else {
                if (!empty($password)) {
                    $hashed = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("UPDATE users SET name=?, email=?, password=?, role=?, status=?, updated_at=NOW() WHERE id=?");
                    $stmt->bind_param("sssssi", $name, $email, $hashed, $role, $status, $id);
                } else {
                    $stmt = $conn->prepare("UPDATE users SET name=?, email=?, role=?, status=?, updated_at=NOW() WHERE id=?");
                    $stmt->bind_param("ssssi", $name, $email, $role, $status, $id);
                }

                if ($stmt->execute()) {
                    $toast_message = "Data user '$name' berhasil diperbarui!";
                    $toast_type = 'success';
                } else {
                    $toast_message = "Gagal memperbarui user!";
                    $toast_type = 'error';
                }
                $stmt->close();
            }
            $checkEmail->close();
            break;

        case 'delete':
            $id = (int)$_POST['id'];
            // Prevent deleting self
            if ($id == ($_SESSION['user_id'] ?? 0)) {
                $toast_message = "Anda tidak bisa menghapus akun sendiri!";
                $toast_type = 'error';
            } else {
                $stmt = $conn->prepare("DELETE FROM users WHERE id=?");
                $stmt->bind_param("i", $id);
                if ($stmt->execute()) {
                    $toast_message = "User berhasil dihapus!";
                    $toast_type = 'success';
                } else {
                    $toast_message = "Gagal menghapus user!";
                    $toast_type = 'error';
                }
                $stmt->close();
            }
            break;
    }
}

// Filter by search query (UNCHANGED - same GET parameter name)
$searchQuery = isset($_GET['q']) ? trim($_GET['q']) : '';

if (!empty($searchQuery)) {
    $stmt = $conn->prepare("SELECT * FROM users WHERE name LIKE ? OR email LIKE ? ORDER BY id ASC");
    $searchLike = "%$searchQuery%";
    $stmt->bind_param("ss", $searchLike, $searchLike);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $stmt = $conn->prepare("SELECT * FROM users ORDER BY id ASC");
    $stmt->execute();
    $result = $stmt->get_result();
}

if($_SESSION['role'] != 'admin') {
    session_write_close();
    header('Location: kegiatan.view.php');
    exit;
}

// Count by role
$adminCount = 0;
$operatorCount = 0;
$petugasCount = 0;
$viewerCount = 0;
$activeCount = 0;
$inactiveCount = 0;
$users = [];
while ($row = $result->fetch_assoc()) {
    $users[] = $row;
    $rowRole = strtolower($row['role']);
    if ($rowRole === 'admin') $adminCount++;
    elseif ($rowRole === 'operator') $operatorCount++;
    elseif ($rowRole === 'petugas') $petugasCount++;
    elseif ($rowRole === 'viewer') $viewerCount++;
    
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

                    <?= getThemeToggleButton() ?>
                </div>
                <a href="../actions/logout.php" onclick="const url=this.href; showConfirmModal('Konfirmasi Logout', 'Apakah Anda yakin ingin keluar dari sistem?', () => window.location.href = url); return false;"
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
                                    <h1 class="text-lg font-semibold text-slate-900 dark:text-white">Manajemen User</h1>
                                    <p class="text-sm text-slate-500 dark:text-zinc-400">Kelola akun pengguna sistem</p>
                                </div>
                            </div>
                            <button onclick="openModal('addModal')" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-archery-600 text-white text-sm font-medium hover:bg-archery-700 transition-colors">
                                <i class="fas fa-plus"></i>
                                <span class="hidden sm:inline">Tambah User</span>
                            </button>
                        </div>
                    </div>

                    <!-- Metrics Bar -->
                    <div class="px-6 py-3 bg-slate-50 dark:bg-zinc-800/50 flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
                        <div class="flex items-center gap-2">

                            <span class="text-2xl font-bold text-slate-900 dark:text-white"><?= count($users) ?></span>
                            <span class="text-slate-500 dark:text-zinc-400">Total User</span>
                        </div>
                        <span class="text-slate-300 dark:text-zinc-600 hidden sm:inline">|</span>
                        <div class="flex items-center gap-1.5">
                            <i class="fas fa-shield-alt text-purple-500 text-xs"></i>
                            <span class="font-medium text-slate-700 dark:text-zinc-300"><?= $adminCount ?></span>
                            <span class="text-slate-400 dark:text-zinc-500">Admin</span>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <i class="fas fa-user-cog text-blue-500 text-xs"></i>
                            <span class="font-medium text-slate-700 dark:text-zinc-300"><?= $operatorCount ?></span>
                            <span class="text-slate-400 dark:text-zinc-500">Operator</span>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <i class="fas fa-user-tie text-amber-500 text-xs"></i>
                            <span class="font-medium text-slate-700 dark:text-zinc-300"><?= $petugasCount ?></span>
                            <span class="text-slate-400 dark:text-zinc-500">Petugas</span>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <i class="fas fa-eye text-slate-400 text-xs"></i>
                            <span class="font-medium text-slate-700 dark:text-zinc-300"><?= $viewerCount ?></span>
                            <span class="text-slate-400 dark:text-zinc-500">Viewer</span>
                        </div>
                        <span class="text-slate-300 dark:text-zinc-600 hidden sm:inline">|</span>
                        <div class="flex items-center gap-1.5">
                            <i class="fas fa-check-circle text-emerald-500 text-xs"></i>

                            <span class="font-medium text-slate-700 dark:text-zinc-300"><?= $activeCount ?></span>
                            <span class="text-slate-400 dark:text-zinc-500">Aktif</span>
                        </div>

                        <?php if ($inactiveCount > 0): ?>
                        <div class="flex items-center gap-1.5">
                            <i class="fas fa-pause-circle text-slate-400 dark:text-zinc-500 text-xs"></i>

                            <span class="font-medium text-slate-700 dark:text-zinc-300"><?= $inactiveCount ?></span>
                            <span class="text-slate-400 dark:text-zinc-500">Nonaktif</span>
                        </div>

                        <?php endif; ?>
                    </div>
                </div>

                <!-- Search Bar -->
                <div class="flex flex-col sm:flex-row gap-4 mb-6">
                    <!-- FORM: method=get (UNCHANGED) -->
                    <form method="get" class="flex-1 flex gap-2">
                        <div class="relative flex-1">
                            <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 dark:text-zinc-500 text-xs"></i>
                            <!-- INPUT: name="q" (UNCHANGED) -->

                            <input type="search" name="q" value="<?= htmlspecialchars($searchQuery) ?>"
                                   class="w-full pl-9 pr-4 py-2 rounded-lg border border-slate-200 dark:border-zinc-700 text-sm focus:ring-2 focus:ring-archery-500 focus:border-archery-500 bg-slate-50 dark:bg-zinc-800 text-slate-900 dark:text-white"
                                   placeholder="Cari berdasarkan nama atau email...">
                        </div>
                        <button type="submit" class="px-4 py-2 rounded-lg bg-archery-600 text-white text-sm font-medium hover:bg-archery-700 transition-colors">
                            <i class="fas fa-search sm:hidden"></i>
                            <span class="hidden sm:inline">Cari</span>
                        </button>

                        <?php if (!empty($searchQuery)): ?>
                        <a href="?" class="px-3 py-2 rounded-lg border border-slate-200 dark:border-zinc-700 text-slate-500 dark:text-zinc-400 text-sm hover:bg-slate-50 dark:hover:bg-zinc-800 transition-colors">
                            <i class="fas fa-times"></i>
                        </a>

                        <?php endif; ?>
                    </form>
                </div>

                <!-- Data Table -->
                <div class="bg-white dark:bg-zinc-900 rounded-xl border border-slate-200 dark:border-zinc-800 overflow-hidden shadow-sm">
                    <div class="overflow-x-auto custom-scrollbar">
                        <table class="w-full">
                            <thead class="bg-slate-100 dark:bg-zinc-800 sticky top-0 z-10">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 dark:text-zinc-400 uppercase tracking-wider w-12">#</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 dark:text-zinc-400 uppercase tracking-wider">User</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 dark:text-zinc-400 uppercase tracking-wider">Email</th>
                                    <th class="px-4 py-3 text-center text-xs font-semibold text-slate-600 dark:text-zinc-400 uppercase tracking-wider w-28">Role</th>
                                    <th class="px-4 py-3 text-center text-xs font-semibold text-slate-600 dark:text-zinc-400 uppercase tracking-wider w-28">Status</th>
                                    <th class="px-4 py-3 text-center text-xs font-semibold text-slate-600 dark:text-zinc-400 uppercase tracking-wider w-24">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-zinc-800">

                                <?php if (count($users) > 0): ?>

                                    <?php $no = 1; foreach ($users as $row): ?>
                                        <tr class="hover:bg-slate-50 dark:hover:bg-zinc-800 transition-colors">

                                            <td class="px-4 py-3 text-sm text-slate-500 dark:text-zinc-400"><?= $no++ ?></td>
                                            <td class="px-4 py-3">
                                                <div class="flex items-center gap-3">
                                                    <div class="w-9 h-9 rounded-full bg-gradient-to-br from-archery-500 to-emerald-600 flex items-center justify-center text-white font-bold text-sm flex-shrink-0">

                                                        <?= strtoupper(substr($row['name'], 0, 1)) ?>
                                                    </div>

                                                    <p class="font-medium text-slate-900 dark:text-white"><?= htmlspecialchars($row['name']) ?></p>
                                                </div>
                                            </td>
                                            <td class="px-4 py-3">

                                                <span class="text-sm text-slate-600 dark:text-zinc-400"><?= htmlspecialchars($row['email']) ?></span>
                                            </td>
                                            <td class="px-4 py-3 text-center">

                                                <?php 
                                                $roleLower = strtolower($row['role']);
                                                if ($roleLower === 'admin'): ?>
                                                    <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium bg-purple-50 dark:bg-purple-900/30 text-purple-700 dark:text-purple-400">
                                                        <i class="fas fa-shield-alt"></i> Admin
                                                    </span>
                                                <?php elseif ($roleLower === 'operator'): ?>
                                                    <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400">
                                                        <i class="fas fa-user-cog"></i> Operator
                                                    </span>
                                                <?php elseif ($roleLower === 'petugas'): ?>
                                                    <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium bg-amber-50 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400">
                                                        <i class="fas fa-user-tie"></i> Petugas
                                                    </span>
                                                <?php else: ?>
                                                    <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium bg-slate-100 dark:bg-zinc-700 text-slate-600 dark:text-zinc-400">
                                                        <i class="fas fa-eye"></i> <?= ucfirst($row['role']) ?>
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-4 py-3 text-center">

                                                <?php if (strtolower($row['status'] ?? 'active') === 'active'): ?>
                                                    <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium bg-emerald-50 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-400">
                                                        <i class="fas fa-check-circle"></i> Aktif
                                                    </span>

                                                <?php else: ?>
                                                    <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium bg-slate-100 dark:bg-zinc-700 text-slate-500 dark:text-zinc-400">
                                                        <i class="fas fa-pause-circle"></i> Nonaktif
                                                    </span>

                                                <?php endif; ?>
                                            </td>
                                            <td class="px-4 py-3">
                                                <div class="flex items-center justify-center gap-1">

                                                    <button onclick="editUser(<?= $row['id'] ?>, '<?= addslashes($row['name']) ?>', '<?= addslashes($row['email']) ?>', '<?= addslashes($row['role']) ?>', '<?= addslashes($row['status'] ?? 'active') ?>')"
                                                       class="p-1.5 rounded-lg text-slate-400 dark:text-zinc-500 hover:text-amber-600 dark:hover:text-amber-400 hover:bg-amber-50 dark:hover:bg-amber-900/30 transition-colors" title="Edit">
                                                        <i class="fas fa-edit text-sm"></i>
                                                    </button>

                                                    <button onclick="deleteUser(<?= $row['id'] ?>, '<?= addslashes($row['name']) ?>')"
                                                       class="p-1.5 rounded-lg text-slate-400 dark:text-zinc-500 hover:text-red-600 dark:hover:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/30 transition-colors" title="Hapus">
                                                        <i class="fas fa-trash text-sm"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>

                                    <?php endforeach; ?>

                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="px-4 py-12">
                                            <div class="flex flex-col items-center text-center">
                                                <div class="w-16 h-16 rounded-full bg-slate-100 dark:bg-zinc-800 flex items-center justify-center mb-3">
                                                    <i class="fas fa-users text-slate-400 dark:text-zinc-500 text-2xl"></i>
                                                </div>
                                                <p class="text-slate-500 dark:text-zinc-400 font-medium">Tidak ada user ditemukan</p>

                                                <?php if (!empty($searchQuery)): ?>
                                                    <p class="text-slate-400 dark:text-zinc-500 text-sm mb-4">Ubah kata kunci pencarian</p>

                                                <?php else: ?>
                                                    <p class="text-slate-400 dark:text-zinc-500 text-sm mb-4">Tambahkan user baru untuk memulai</p>
                                                    <button onclick="openModal('addModal')" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-archery-600 text-white text-sm font-medium hover:bg-archery-700 transition-colors">
                                                        <i class="fas fa-plus"></i> Tambah User
                                                    </button>

                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>

                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if (count($users) > 0): ?>
                    <div class="px-4 py-3 bg-slate-50 dark:bg-zinc-800/50 border-t border-slate-100 dark:border-zinc-800 text-sm text-slate-500 dark:text-zinc-400">

                        Menampilkan <?= count($users) ?> user<?php if (!empty($searchQuery)): ?> <span class="text-slate-400 dark:text-zinc-500">• filtered</span><?php endif; ?>
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
            <a href="../actions/logout.php" onclick="const url=this.href; showConfirmModal('Konfirmasi Logout', 'Apakah Anda yakin ingin keluar dari sistem?', () => window.location.href = url); return false;"
               class="flex items-center gap-2 w-full px-4 py-2 rounded-lg text-red-400 hover:bg-red-500/10 transition-colors text-sm">
                <i class="fas fa-sign-out-alt w-5"></i>
                <span>Logout</span>
            </a>
        </div>
    </div>

    <!-- Add User Modal -->
    <div id="addModal" class="modal-backdrop">
        <div class="bg-white dark:bg-zinc-900 rounded-2xl shadow-xl w-full max-w-md mx-4 overflow-hidden">
            <div class="bg-gradient-to-br from-archery-600 to-archery-800 text-white px-6 py-4 flex items-center justify-between">
                <h3 class="font-semibold text-lg flex items-center gap-2">
                    <i class="fas fa-user-plus"></i> Tambah User
                </h3>
                <button onclick="closeModal('addModal')" class="p-2 rounded-lg hover:bg-white/10 transition-colors">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="POST">
                <div class="p-6 space-y-4">
                    <input type="hidden" name="action" value="create">

                    <?php csrf_field(); ?>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-zinc-300 mb-1">Nama Lengkap <span class="text-red-500">*</span></label>
                        <input type="text" name="name" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-archery-500 focus:border-archery-500" placeholder="Masukkan nama" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-zinc-300 mb-1">Email <span class="text-red-500">*</span></label>
                        <input type="email" name="email" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-archery-500" placeholder="user@example.com" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-zinc-300 mb-1">Password <span class="text-red-500">*</span></label>
                        <input type="password" name="password" minlength="6" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-archery-500" required>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-zinc-300 mb-1">Role <span class="text-red-500">*</span></label>
                            <select name="role" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-archery-500" required>
                                <option value="admin">Admin</option>
                                <option value="operator">Operator</option>
                                <option value="petugas">Petugas</option>
                                <option value="viewer">Viewer</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-zinc-300 mb-1">Status <span class="text-red-500">*</span></label>
                            <select name="status" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-archery-500" required>
                                <option value="active">Aktif</option>
                                <option value="inactive">Nonaktif</option>
                            </select>
                        </div>
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

    <!-- Edit User Modal -->
    <div id="editModal" class="modal-backdrop">
        <div class="bg-white dark:bg-zinc-900 rounded-2xl shadow-xl w-full max-w-md mx-4 overflow-hidden">
            <div class="bg-gradient-to-br from-amber-500 to-amber-700 text-white px-6 py-4 flex items-center justify-between">
                <h3 class="font-semibold text-lg flex items-center gap-2">
                    <i class="fas fa-user-edit"></i> Edit User
                </h3>
                <button onclick="closeModal('editModal')" class="p-2 rounded-lg hover:bg-white/10 transition-colors">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="POST">
                <div class="p-6 space-y-4">
                    <input type="hidden" name="action" value="update">

                    <?php csrf_field(); ?>
                    <input type="hidden" name="id" id="edit_id">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-zinc-300 mb-1">Nama Lengkap <span class="text-red-500">*</span></label>
                        <input type="text" name="name" id="edit_name" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-amber-500" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-zinc-300 mb-1">Email <span class="text-red-500">*</span></label>
                        <input type="email" name="email" id="edit_email" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-amber-500" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-zinc-300 mb-1">Password Baru (Kosongkan jika tetap)</label>
                        <input type="password" name="password" minlength="6" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-amber-500">
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-zinc-300 mb-1">Role <span class="text-red-500">*</span></label>
                            <select name="role" id="edit_role" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-amber-500" required>
                                <option value="admin">Admin</option>
                                <option value="operator">Operator</option>
                                <option value="petugas">Petugas</option>
                                <option value="viewer">Viewer</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-zinc-300 mb-1">Status <span class="text-red-500">*</span></label>
                            <select name="status" id="edit_status" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-amber-500" required>
                                <option value="active">Aktif</option>
                                <option value="inactive">Nonaktif</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="px-6 py-4 bg-slate-50 dark:bg-zinc-800/50 border-t border-slate-200 dark:border-zinc-700 flex gap-3">
                    <button type="button" onclick="closeModal('editModal')" class="flex-1 px-4 py-2 rounded-lg border border-slate-300 dark:border-zinc-600 text-slate-700 dark:text-zinc-300 text-sm font-medium hover:bg-slate-100 dark:hover:bg-zinc-700 transition-colors">
                        Batal
                    </button>
                    <button type="submit" class="flex-1 px-4 py-2 rounded-lg bg-amber-600 text-white text-sm font-medium hover:bg-amber-700 transition-colors">
                        <i class="fas fa-sync-alt mr-1"></i> Update
                    </button>
                </div>
            </form>
        </div>
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

        function dismissToast() {
            const toast = document.getElementById('toast');
            if (toast) {
                toast.classList.remove('toast-enter');
                toast.classList.add('toast-exit');
                setTimeout(() => toast.remove(), 300);
            }
        }

        // Auto-dismiss toast
        setTimeout(dismissToast, 5000);

        function editUser(id, name, email, role, status) {
            document.getElementById('edit_id').value = id;
            document.getElementById('edit_name').value = name;
            document.getElementById('edit_email').value = email;
            document.getElementById('edit_role').value = role;
            document.getElementById('edit_status').value = status;
            openModal('editModal');
        }

        function deleteUser(id, name) {
            showConfirmModal(
                'Hapus User',
                `Apakah Anda yakin ingin menghapus user <strong class="text-red-600 dark:text-red-400">${name}</strong>?<br><br><span class="text-sm text-slate-500 dark:text-zinc-400">Tindakan ini tidak dapat dibatalkan!</span>`,
                () => {
                    const deleteForm = document.createElement('form');
                    deleteForm.method = 'POST';
                    deleteForm.innerHTML = `
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="${id}">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    `;
                    document.body.appendChild(deleteForm);
                    deleteForm.submit();
                },
                'danger'
            );
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
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Loading...';
                }
            });
        });

        // Theme Toggle

        <?= getThemeToggleScript() ?>
    </script>
    <?= getConfirmationModal() ?>
    <?= getUiScripts() ?>
</body>
</html>
<?php skip_post: ?>
