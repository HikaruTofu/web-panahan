<?php
/**
 * Peserta Lomba - Tournament Participants Management
 * UI: Intentional Minimalism with Tailwind CSS
 */
include '../config/panggil.php';
include '../includes/check_access.php';
requireLogin();

// Get tournament ID from URL
$tournament_id = isset($_GET['tournament_id']) ? intval($_GET['tournament_id']) : 0;

if (!$tournament_id) {
    die("ID Tournament tidak valid!");
}

// Get tournament information
$tournament_result = $conn->query("SELECT * FROM tournaments WHERE id = $tournament_id");
if (!$tournament_result || $tournament_result->num_rows == 0) {
    die("Tournament tidak ditemukan!");
}
$tournament = $tournament_result->fetch_assoc();

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
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
$available_participants_sql = "SELECT p.* FROM participants p
                              WHERE p.status = 'active'
                              AND p.id NOT IN (SELECT participant_id FROM tournament_participants WHERE tournament_id = $tournament_id)
                              ORDER BY p.name";
$available_participants = $conn->query($available_participants_sql);

// Get available categories
$categories = $conn->query("SELECT * FROM categories WHERE status = 'active' ORDER BY name");

// Get registered participants
$search = isset($_GET['q']) ? $_GET['q'] : '';
if ($search) {
    $participants_sql = "SELECT tp.*, p.name as participant_name, p.birthdate, p.gender, p.phone,
                        c.name as category_name, YEAR(CURDATE()) - YEAR(p.birthdate) as age
                        FROM tournament_participants tp
                        LEFT JOIN participants p ON tp.participant_id = p.id
                        LEFT JOIN categories c ON tp.category_id = c.id
                        WHERE tp.tournament_id = $tournament_id
                        AND (p.name LIKE '%$search%' OR c.name LIKE '%$search%')
                        ORDER BY tp.registration_date DESC";
} else {
    $participants_sql = "SELECT tp.*, p.name as participant_name, p.birthdate, p.gender, p.phone,
                        c.name as category_name, YEAR(CURDATE()) - YEAR(p.birthdate) as age
                        FROM tournament_participants tp
                        LEFT JOIN participants p ON tp.participant_id = p.id
                        LEFT JOIN categories c ON tp.category_id = c.id
                        WHERE tp.tournament_id = $tournament_id
                        ORDER BY tp.registration_date DESC";
}
$participants_result = $conn->query($participants_sql);

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
            <!-- Header -->
            <header class="sticky top-0 z-40 bg-white/80 backdrop-blur-sm border-b border-slate-200">
                <div class="px-6 lg:px-8 py-4">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-lg bg-archery-100 flex items-center justify-center">
                                <i class="fas fa-users text-archery-600"></i>
                            </div>
                            <div>
                                <h1 class="text-xl font-bold text-slate-900">Peserta Lomba</h1>
                                <p class="text-sm text-slate-500">Kelola peserta tournament</p>
                            </div>
                        </div>
                        <button onclick="openModal('addModal')" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-archery-600 text-white text-sm font-medium hover:bg-archery-700 transition-colors">
                            <i class="fas fa-plus"></i>
                            <span class="hidden sm:inline">Tambah Peserta</span>
                        </button>
                    </div>
                </div>
            </header>

            <div class="px-6 lg:px-8 py-6">
                <!-- Tournament Info Card -->
                <div class="bg-white rounded-xl border border-slate-200 p-5 mb-6">
                    <div class="flex items-start gap-4">
                        <div class="w-12 h-12 rounded-lg bg-amber-100 flex items-center justify-center flex-shrink-0">
                            <i class="fas fa-trophy text-amber-600 text-xl"></i>
                        </div>
                        <div class="flex-1">
                            <h3 class="font-semibold text-lg text-slate-900"><?= htmlspecialchars($tournament['name']); ?></h3>
                            <div class="mt-2 grid grid-cols-1 sm:grid-cols-3 gap-3 text-sm">
                                <div class="flex items-center gap-2 text-slate-600">
                                    <i class="fas fa-calendar-alt text-slate-400"></i>
                                    <span><?= date('d/m/Y', strtotime($tournament['start_date'])); ?> - <?= date('d/m/Y', strtotime($tournament['end_date'])); ?></span>
                                </div>
                                <div class="flex items-center gap-2 text-slate-600">
                                    <i class="fas fa-map-marker-alt text-slate-400"></i>
                                    <span><?= htmlspecialchars($tournament['location'] ?? '-'); ?></span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <?php
                                    $statusColors = [
                                        'draft' => 'bg-slate-100 text-slate-700',
                                        'registration' => 'bg-cyan-100 text-cyan-700',
                                        'ongoing' => 'bg-amber-100 text-amber-700',
                                        'completed' => 'bg-emerald-100 text-emerald-700',
                                        'cancelled' => 'bg-red-100 text-red-700'
                                    ];
                                    $statusColor = $statusColors[$tournament['status']] ?? 'bg-slate-100 text-slate-700';
                                    ?>
                                    <span class="px-2.5 py-1 rounded-full text-xs font-medium <?= $statusColor ?>"><?= ucfirst($tournament['status']); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Search & Actions Bar -->
                <div class="flex flex-col sm:flex-row gap-4 mb-6">
                    <form method="get" class="flex-1 flex gap-2">
                        <input type="hidden" name="tournament_id" value="<?= $tournament_id; ?>">
                        <div class="relative flex-1">
                            <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"></i>
                            <input type="search" name="q" value="<?= htmlspecialchars($search); ?>"
                                   class="w-full pl-10 pr-4 py-2 rounded-lg border border-slate-300 text-sm focus:ring-2 focus:ring-archery-500 focus:border-archery-500"
                                   placeholder="Cari peserta...">
                        </div>
                        <button type="submit" class="px-4 py-2 rounded-lg bg-slate-100 text-slate-700 text-sm font-medium hover:bg-slate-200 transition-colors">
                            Cari
                        </button>
                        <?php if (!empty($search)): ?>
                        <a href="?tournament_id=<?= $tournament_id ?>" class="px-3 py-2 rounded-lg border border-slate-300 text-slate-600 text-sm hover:bg-slate-50 transition-colors">
                            <i class="fas fa-times"></i>
                        </a>
                        <?php endif; ?>
                    </form>
                    <a href="kegiatan.view.php" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg border border-slate-300 text-slate-700 text-sm font-medium hover:bg-slate-50 transition-colors">
                        <i class="fas fa-arrow-left"></i>
                        <span>Kegiatan</span>
                    </a>
                </div>

                <!-- Desktop Table -->
                <div class="hidden md:block bg-white rounded-xl border border-slate-200 overflow-hidden">
                    <div class="overflow-x-auto custom-scrollbar">
                        <table class="w-full">
                            <thead class="bg-zinc-800 text-white sticky top-0 z-10">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider w-16">#</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider">Nama Peserta</th>
                                    <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider w-20">Umur</th>
                                    <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider w-24">Gender</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider">Kategori</th>
                                    <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider w-28">Pembayaran</th>
                                    <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider w-28">Status</th>
                                    <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider w-20">Seed</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider w-36">Tgl Daftar</th>
                                    <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider w-24">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php if ($participants_result->num_rows == 0): ?>
                                    <tr>
                                        <td colspan="10" class="px-4 py-12">
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
                                    $no = 1;
                                    while ($row = $participants_result->fetch_assoc()):
                                    ?>
                                    <tr class="hover:bg-slate-50 transition-colors">
                                        <td class="px-4 py-3">
                                            <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-archery-100 text-archery-700 text-sm font-semibold">
                                                <?= $no++; ?>
                                            </span>
                                        </td>
                                        <td class="px-4 py-3">
                                            <p class="font-medium text-slate-900"><?= htmlspecialchars($row['participant_name']); ?></p>
                                        </td>
                                        <td class="px-4 py-3 text-center">
                                            <span class="text-sm text-slate-600"><?= $row['age']; ?> th</span>
                                        </td>
                                        <td class="px-4 py-3 text-center">
                                            <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium <?= $row['gender'] == 'Laki-laki' ? 'bg-blue-100 text-blue-700' : 'bg-pink-100 text-pink-700' ?>">
                                                <i class="fas <?= $row['gender'] == 'Laki-laki' ? 'fa-mars' : 'fa-venus' ?> text-xs"></i>
                                                <?= $row['gender'] == 'Laki-laki' ? 'L' : 'P' ?>
                                            </span>
                                        </td>
                                        <td class="px-4 py-3">
                                            <span class="px-2 py-1 rounded-full text-xs font-medium bg-archery-100 text-archery-700">
                                                <?= htmlspecialchars($row['category_name']); ?>
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-center">
                                            <?php
                                            $paymentColors = [
                                                'pending' => 'bg-amber-100 text-amber-700',
                                                'paid' => 'bg-emerald-100 text-emerald-700',
                                                'refunded' => 'bg-slate-100 text-slate-700'
                                            ];
                                            $paymentColor = $paymentColors[$row['payment_status']] ?? 'bg-slate-100 text-slate-700';
                                            ?>
                                            <span class="px-2 py-1 rounded-full text-xs font-medium <?= $paymentColor ?>">
                                                <?= ucfirst($row['payment_status']); ?>
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-center">
                                            <?php
                                            $statusColors = [
                                                'registered' => 'bg-cyan-100 text-cyan-700',
                                                'confirmed' => 'bg-emerald-100 text-emerald-700',
                                                'withdrew' => 'bg-amber-100 text-amber-700',
                                                'disqualified' => 'bg-red-100 text-red-700'
                                            ];
                                            $statusColor = $statusColors[$row['status']] ?? 'bg-slate-100 text-slate-700';
                                            ?>
                                            <span class="px-2 py-1 rounded-full text-xs font-medium <?= $statusColor ?>">
                                                <?= ucfirst($row['status']); ?>
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-center">
                                            <span class="text-sm text-slate-600"><?= $row['seed_number'] ?? '-'; ?></span>
                                        </td>
                                        <td class="px-4 py-3">
                                            <span class="text-sm text-slate-500"><?= date('d/m/Y H:i', strtotime($row['registration_date'])); ?></span>
                                        </td>
                                        <td class="px-4 py-3">
                                            <div class="flex items-center justify-center gap-2">
                                                <button onclick="editParticipant(<?= $row['id']; ?>)" class="p-2 rounded-lg text-amber-600 hover:bg-amber-50 transition-colors" title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button onclick="removeParticipant(<?= $row['id']; ?>)" class="p-2 rounded-lg text-red-600 hover:bg-red-50 transition-colors" title="Hapus">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php
                    // Reset result pointer for count
                    $participants_result->data_seek(0);
                    $count = $participants_result->num_rows;
                    if ($count > 0):
                    ?>
                    <div class="px-4 py-3 bg-slate-50 border-t border-slate-200">
                        <p class="text-sm text-slate-500">Menampilkan <?= $count ?> peserta</p>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Mobile Cards -->
                <div id="mobileCards" class="md:hidden space-y-4">
                    <?php
                    // Reset result pointer for mobile view
                    $participants_result->data_seek(0);
                    if ($participants_result->num_rows == 0):
                    ?>
                        <div class="bg-white rounded-xl border border-slate-200 p-8 text-center">
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
                        $no = 1;
                        while ($row = $participants_result->fetch_assoc()):
                        ?>
                            <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-4 border-l-4 border-l-archery-500">
                                <div class="flex items-start gap-3 mb-3 pb-3 border-b border-slate-100">
                                    <div class="w-8 h-8 rounded-full bg-archery-600 text-white flex items-center justify-center text-sm font-bold flex-shrink-0">
                                        <?= $no++; ?>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="font-semibold text-slate-900"><?= htmlspecialchars($row['participant_name']); ?></p>
                                        <p class="text-sm text-slate-500"><?= $row['age']; ?> tahun - <?= $row['gender']; ?></p>
                                    </div>
                                    <?php
                                    $statusColors = [
                                        'registered' => 'bg-cyan-100 text-cyan-700',
                                        'confirmed' => 'bg-emerald-100 text-emerald-700',
                                        'withdrew' => 'bg-amber-100 text-amber-700',
                                        'disqualified' => 'bg-red-100 text-red-700'
                                    ];
                                    $statusColor = $statusColors[$row['status']] ?? 'bg-slate-100 text-slate-700';
                                    ?>
                                    <span class="px-2 py-1 rounded-full text-xs font-medium <?= $statusColor ?>">
                                        <?= ucfirst($row['status']); ?>
                                    </span>
                                </div>
                                <div class="grid grid-cols-2 gap-2 mb-3 text-sm">
                                    <div>
                                        <p class="text-xs text-slate-400 uppercase tracking-wide">Kategori</p>
                                        <p class="text-slate-700"><?= htmlspecialchars($row['category_name']); ?></p>
                                    </div>
                                    <div>
                                        <p class="text-xs text-slate-400 uppercase tracking-wide">Pembayaran</p>
                                        <?php
                                        $paymentColors = [
                                            'pending' => 'bg-amber-100 text-amber-700',
                                            'paid' => 'bg-emerald-100 text-emerald-700',
                                            'refunded' => 'bg-slate-100 text-slate-700'
                                        ];
                                        $paymentColor = $paymentColors[$row['payment_status']] ?? 'bg-slate-100 text-slate-700';
                                        ?>
                                        <span class="px-2 py-1 rounded-full text-xs font-medium <?= $paymentColor ?>">
                                            <?= ucfirst($row['payment_status']); ?>
                                        </span>
                                    </div>
                                    <div>
                                        <p class="text-xs text-slate-400 uppercase tracking-wide">Seed</p>
                                        <p class="text-slate-700"><?= $row['seed_number'] ?? '-'; ?></p>
                                    </div>
                                    <div>
                                        <p class="text-xs text-slate-400 uppercase tracking-wide">Tgl Daftar</p>
                                        <p class="text-slate-700"><?= date('d/m/Y', strtotime($row['registration_date'])); ?></p>
                                    </div>
                                </div>
                                <div class="grid grid-cols-2 gap-2">
                                    <button onclick="editParticipant(<?= $row['id']; ?>)" class="inline-flex items-center justify-center gap-1 px-3 py-2 rounded-lg bg-amber-100 text-amber-700 text-xs font-medium">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <button onclick="removeParticipant(<?= $row['id']; ?>)" class="inline-flex items-center justify-center gap-1 px-3 py-2 rounded-lg bg-red-100 text-red-700 text-xs font-medium">
                                        <i class="fas fa-trash"></i> Hapus
                                    </button>
                                </div>
                            </div>
                        <?php endwhile; ?>
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
    <div id="mobile-sidebar" class="fixed inset-y-0 left-0 w-72 bg-zinc-900 text-white z-50 transform -translate-x-full transition-transform lg:hidden">
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
</script>
</body>
</html>
