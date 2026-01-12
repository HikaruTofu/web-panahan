<?php
/**
 * Data Recovery Panel
 * View and manage 24-hour backups of deleted records.
 */
require_once __DIR__ . '/../config/panggil.php';
require_once __DIR__ . '/../includes/check_access.php';
require_once __DIR__ . '/../includes/theme.php';
requireAdmin();

$username = $_SESSION['username'] ?? 'User';
$name = $_SESSION['name'] ?? $username;
$role = $_SESSION['role'] ?? 'user';

// Handle delete backup entry
if (isset($_GET['delete_id']) && file_exists(RECOVERY_BACKUP_FILE)) {
    $deleteId = $_GET['delete_id'];
    $data = json_decode(file_get_contents(RECOVERY_BACKUP_FILE), true);
    if (is_array($data)) {
        $filtered = array_filter($data, function($b) use ($deleteId) {
            return ($b['id'] ?? '') !== $deleteId;
        });
        file_put_contents(RECOVERY_BACKUP_FILE, json_encode(array_values($filtered), JSON_PRETTY_PRINT));
        header("Location: recovery.php?deleted=1");
        exit;
    }
}

// Handle restore record
if (isset($_GET['restore_id'])) {
    if (restore_record($conn, $_GET['restore_id'])) {
        $_SESSION['toast_message'] = "Data berhasil dikembalikan!";
        $_SESSION['toast_type'] = 'success';
    } else {
        $error = $_SESSION['recovery_error'] ?? 'Gagal mengembalikan data!';
        $_SESSION['toast_message'] = "Restore Gagal: " . $error;
        $_SESSION['toast_type'] = 'error';
        unset($_SESSION['recovery_error']);
    }
    header("Location: recovery.php");
    exit;
}

// Get backups from single file
$backups = [];
if (file_exists(RECOVERY_BACKUP_FILE)) {
    $all = json_decode(file_get_contents(RECOVERY_BACKUP_FILE), true);
    if (is_array($all)) {
        foreach ($all as $b) {
            $backups[] = [
                'id' => $b['id'] ?? '',
                'table' => $b['table'] ?? 'Unknown',
                'deleted_at' => $b['deleted_at'] ?? 'Unknown',
                'content' => $b['data'] ?? [],
                'file_time' => $b['timestamp'] ?? strtotime($b['deleted_at'])
            ];
        }
    }
}

// Sort by time descending
usort($backups, function($a, $b) {
    return $b['file_time'] - $a['file_time'];
});
?>
<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Recovery - Turnamen Panahan</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script><?= getThemeTailwindConfig() ?></script>
    <script><?= getThemeInitScript() ?></script>
    <script><?= getUiScripts() ?></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
                    <p class="text-xs text-zinc-400">Recovery Panel</p>
                </div>
            </div>

            <nav class="flex-1 px-4 py-6 space-y-1">
                <a href="dashboard.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800 transition-colors">
                    <i class="fas fa-home w-5"></i>
                    <span class="text-sm">Dashboard</span>
                </a>
                <div class="pt-4">
                    <p class="px-4 text-xs font-semibold text-zinc-500 uppercase tracking-wider mb-2">System</p>
                    <a href="recovery.php" class="flex items-center gap-3 px-4 py-3 rounded-lg bg-red-600/20 text-red-400 border border-red-600/30">
                        <i class="fas fa-trash-restore w-5"></i>
                        <span class="text-sm font-medium">Data Recovery</span>
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
            </div>
        </aside>

        <!-- Mobile Sidebar Toggle -->
        <button id="mobile-menu-btn" class="lg:hidden fixed top-4 left-4 z-50 p-2 rounded-lg bg-zinc-900 text-white shadow-lg">
            <i class="fas fa-bars"></i>
        </button>

        <!-- Main Content -->
        <main class="flex-1 overflow-auto">
            <div class="px-6 lg:px-8 py-6">
                <div class="mb-8">
                    <h1 class="text-2xl font-bold text-slate-900 dark:text-white">Data Recovery Mode</h1>
                    <p class="text-slate-500 dark:text-zinc-400">Data yang baru dihapus akan tersimpan di sini selama 24 jam.</p>
                </div>
            </div>

            <div class="px-6 lg:px-8">
                <?php if (empty($backups)): ?>
                    <div class="bg-white dark:bg-zinc-900 rounded-xl border border-slate-200 dark:border-zinc-800 p-12 text-center">
                        <div class="w-16 h-16 bg-slate-100 dark:bg-zinc-800 rounded-full flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-box-open text-slate-400 text-2xl"></i>
                        </div>
                        <h3 class="text-lg font-medium text-slate-900 dark:text-white mb-1">Tidak Ada Data Terhapus</h3>
                        <p class="text-slate-500 dark:text-zinc-400">Belum ada rekaman data yang dihapus dalam 24 jam terakhir.</p>
                    </div>
                <?php else: ?>
                    <div class="grid grid-cols-1 gap-4">
                        <?php foreach ($backups as $b): ?>
                            <div class="bg-white dark:bg-zinc-900 rounded-xl border border-slate-200 dark:border-zinc-800 overflow-hidden shadow-sm hover:shadow-md transition-shadow">
                                <div class="px-6 py-4 bg-slate-50/50 dark:bg-zinc-800/50 border-b border-slate-200 dark:border-zinc-800 flex items-center justify-between">
                                    <div class="flex items-center gap-3">
                                        <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400">
                                            <?= htmlspecialchars($b['table']) ?>
                                        </span>
                                        <span class="text-sm font-medium text-slate-900 dark:text-white">
                                            ID: <?= $b['content']['id'] ?? '?' ?> - <?= htmlspecialchars($b['content']['nama_kegiatan'] ?? $b['content']['nama_peserta'] ?? $b['content']['name'] ?? 'Record') ?>
                                        </span>
                                    </div>
                                    <div class="text-xs text-slate-500">
                                        <i class="far fa-clock mr-1"></i> Dihapus: <?= $b['deleted_at'] ?>
                                    </div>
                                </div>
                                <div class="p-6">
                                    <div class="flex flex-col lg:flex-row gap-6">
                                        <div class="flex-1">
                                            <h4 class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">Data Cuplikan (JSON)</h4>
                                            <pre class="bg-slate-900 text-slate-300 p-4 rounded-lg text-[11px] overflow-auto max-h-48 custom-scrollbar"><?= htmlspecialchars(json_encode($b['content'], JSON_PRETTY_PRINT)) ?></pre>
                                        </div>
                                        <div class="w-full lg:w-64 space-y-3">
                                            <h4 class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">Aksi</h4>
                                             <a href="recovery.php?restore_id=<?= urlencode($b['id']) ?>" 
                                                onclick="const url=this.href; showConfirmModal('Konfirmasi Restore', 'Apakah Anda yakin ingin mengembalikan data ini ke database?', () => window.location.href = url, 'primary'); return false;"
                                                class="w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-lg bg-archery-600 text-white text-sm font-medium hover:bg-archery-700 transition-colors">
                                                 <i class="fas fa-undo"></i> Kembalikan Data
                                             </a>
                                             <button onclick='copyToClipboard(<?= json_encode($b['content']) ?>)'
                                                     class="w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-lg bg-slate-100 dark:bg-zinc-800 text-slate-700 dark:text-zinc-300 text-sm font-medium hover:bg-slate-200 dark:hover:bg-zinc-700 transition-colors">
                                                 <i class="fas fa-copy"></i> Salin Data
                                             </button>
                                             <a href="recovery.php?delete_id=<?= urlencode($b['id']) ?>" 
                                                onclick="const url=this.href; showConfirmModal('Konfirmasi Hapus Permanen', 'Hapus backup ini sekarang? Tindakan ini tidak dapat dibatalkan.', () => window.location.href = url, 'danger'); return false;"
                                                class="w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-lg text-red-600 hover:bg-red-50 dark:hover:bg-red-900/10 text-sm font-medium transition-colors">
                                                 <i class="fas fa-trash"></i> Bersihkan
                                             </a>
                                            <p class="text-[10px] text-slate-500 italic text-center">Data ini akan otomatis terhapus dalam 24 jam sejak waktu penghapusan.</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Mobile Sidebar -->
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
                <a href="kegiatan.view.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800 transition-colors">
                    <i class="fas fa-calendar w-5"></i><span class="text-sm">Kegiatan</span>
                </a>
                <a href="peserta.view.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800 transition-colors">
                    <i class="fas fa-user-friends w-5"></i><span class="text-sm">Peserta</span>
                </a>
                <a href="statistik.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800 transition-colors">
                    <i class="fas fa-chart-bar w-5"></i><span class="text-sm">Statistik</span>
                </a>
            </div>

            <div class="pt-4">
                <p class="px-4 text-xs font-semibold text-zinc-500 uppercase tracking-wider mb-2">System</p>
                <a href="recovery.php" class="flex items-center gap-3 px-4 py-3 rounded-lg bg-red-600/20 text-red-400 border border-red-600/30">
                    <i class="fas fa-trash-restore w-5"></i>
                    <span class="text-sm font-medium">Data Recovery</span>
                </a>
            </div>
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
        // Mobile menu functionality
        const mobileMenuBtn = document.getElementById('mobile-menu-btn');
        const mobileSidebar = document.getElementById('mobile-sidebar');
        const closeMobileMenu = document.getElementById('close-mobile-menu');

        mobileMenuBtn?.addEventListener('click', () => {
            mobileSidebar?.classList.remove('-translate-x-full');
        });

        closeMobileMenu?.addEventListener('click', () => {
            mobileSidebar?.classList.add('-translate-x-full');
        });
        function copyToClipboard(text) {
            const jsonStr = typeof text === 'string' ? text : JSON.stringify(text, null, 2);
            navigator.clipboard.writeText(jsonStr).then(() => {
                showToast('JSON berhasil disalin!', 'success');
            }).catch(err => {
                showToast('Gagal menyalin data', 'error');
            });
        }
    </script>

    <!-- Modal Infrastructure -->
    <?= getConfirmationModal() ?>
    <?= getToastContainer() ?>

    <?php if (isset($_SESSION['toast_message'])): ?>
    <script>
        showToast("<?= $_SESSION['toast_message'] ?>", "<?= $_SESSION['toast_type'] ?? 'info' ?>");
    </script>
    <?php unset($_SESSION['toast_message'], $_SESSION['toast_type']); endif; ?>
</body>
</html>
