<?php
/**
 * Data Peserta - Turnamen Panahan
 * UI: Intentional Minimalism with Tailwind CSS
 */
include '../config/panggil.php';
include '../includes/check_access.php';
requireAdmin();

// Handle update request (UNCHANGED)
if (isset($_POST['update_id'])) {
    $update_id = intval($_POST['update_id']);
    $nama_peserta = $_POST['nama_peserta'];
    $category_id = intval($_POST['category_id']);
    $kegiatan_id = intval($_POST['kegiatan_id']);
    $tanggal_lahir = $_POST['tanggal_lahir'];
    $jenis_kelamin = $_POST['jenis_kelamin'];
    $asal_kota = $_POST['asal_kota'];
    $nama_club = $_POST['nama_club'];
    $sekolah = $_POST['sekolah'];
    $kelas = $_POST['kelas'];
    $nomor_hp = $_POST['nomor_hp'];

    $stmt = $conn->prepare("UPDATE peserta SET nama_peserta=?, category_id=?, kegiatan_id=?, tanggal_lahir=?, jenis_kelamin=?, asal_kota=?, nama_club=?, sekolah=?, kelas=?, nomor_hp=? WHERE id=?");
    $stmt->bind_param("siisssssssi", $nama_peserta, $category_id, $kegiatan_id, $tanggal_lahir, $jenis_kelamin, $asal_kota, $nama_club, $sekolah, $kelas, $nomor_hp, $update_id);

    if ($stmt->execute()) {
        $success_message = "Data peserta berhasil diperbarui!";
    } else {
        $error_message = "Gagal memperbarui data peserta!";
    }
}

// Handle delete request (UNCHANGED)
if (isset($_POST['delete_id'])) {
    $delete_id = intval($_POST['delete_id']);

    $stmt = $conn->prepare("SELECT bukti_pembayaran FROM peserta WHERE id = ?");
    $stmt->bind_param("i", $delete_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $peserta_data = $result->fetch_assoc();

    if ($peserta_data) {
        if (!empty($peserta_data['bukti_pembayaran'])) {
            $file_path = '../assets/uploads/' . $peserta_data['bukti_pembayaran'];
            if (file_exists($file_path)) {
                unlink($file_path);
            }
        }

        $stmt = $conn->prepare("DELETE FROM peserta WHERE id = ?");
        $stmt->bind_param("i", $delete_id);

        if ($stmt->execute()) {
            $success_message = "Data peserta berhasil dihapus!";
        } else {
            $error_message = "Gagal menghapus data peserta!";
        }
    } else {
        $error_message = "Data peserta tidak ditemukan!";
    }
}

// Handle export to Excel (UNCHANGED)
if (isset($_GET['export']) && $_GET['export'] == 'excel') {
    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename=data_peserta_" . date('Y-m-d') . ".xls");
    header("Pragma: no-cache");
    header("Expires: 0");

    $category_id = trim($_GET['category_id'] ?? '');
    $kegiatan_id = trim($_GET['kegiatan_id'] ?? '');
    $gender = trim($_GET['gender'] ?? '');
    $nama = trim($_GET['nama'] ?? '');
    $club = trim($_GET['club'] ?? '');

    $query = "SELECT p.*, c.name AS category_name, k.nama_kegiatan
              FROM peserta p
              LEFT JOIN categories c ON p.category_id = c.id
              LEFT JOIN kegiatan k ON p.kegiatan_id = k.id
              WHERE 1=1";

    $params = [];
    $types = '';

    if (!empty($category_id)) {
        $query .= " AND p.category_id = ?";
        $params[] = $category_id;
        $types .= "i";
    }

    if (!empty($kegiatan_id)) {
        $query .= " AND p.kegiatan_id = ?";
        $params[] = $kegiatan_id;
        $types .= "i";
    }

    if (!empty($gender)) {
        $query .= " AND p.jenis_kelamin = ?";
        $params[] = $gender;
        $types .= "s";
    }

    if (!empty($nama)) {
        $query .= " AND LOWER(p.nama_peserta) LIKE LOWER(?)";
        $params[] = "%$nama%";
        $types .= "s";
    }

    if (!empty($club)) {
        $query .= " AND LOWER(p.nama_club) LIKE LOWER(?)";
        $params[] = "%$club%";
        $types .= "s";
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

    $groupedData = [];
    while ($row = $result->fetch_assoc()) {
        $nama_display = $row['nama_peserta'];
        $nama_key = strtolower(trim($nama_display));

        if (!isset($groupedData[$nama_key])) {
            $groupedData[$nama_key] = $row;
            $groupedData[$nama_key]['categories'] = [];
            $groupedData[$nama_key]['kegiatan'] = [];
            $groupedData[$nama_key]['ids'] = [];
        }
        $groupedData[$nama_key]['ids'][] = $row['id'];
        if (!empty($row['category_name']) && !in_array($row['category_name'], $groupedData[$nama_key]['categories'])) {
            $groupedData[$nama_key]['categories'][] = $row['category_name'];
        }
        if (!empty($row['nama_kegiatan']) && !in_array($row['nama_kegiatan'], $groupedData[$nama_key]['kegiatan'])) {
            $groupedData[$nama_key]['kegiatan'][] = $row['nama_kegiatan'];
        }
    }

    echo "<table border='1'>";
    echo "<tr>";
    echo "<th>No</th>";
    echo "<th>ID(s)</th>";
    echo "<th>Nama Peserta</th>";
    echo "<th>Kategori</th>";
    echo "<th>Kegiatan</th>";
    echo "<th>Tanggal Lahir</th>";
    echo "<th>Umur</th>";
    echo "<th>Jenis Kelamin</th>";
    echo "<th>Asal Kota</th>";
    echo "<th>Nama Club</th>";
    echo "<th>Sekolah</th>";
    echo "<th>Kelas</th>";
    echo "<th>Nomor HP</th>";
    echo "<th>Status Pembayaran</th>";
    echo "<th>Tanggal Daftar</th>";
    echo "</tr>";

    $no = 1;
    foreach ($groupedData as $row) {
        $umur = "-";
        if (!empty($row['tanggal_lahir'])) {
            $dob = new DateTime($row['tanggal_lahir']);
            $today = new DateTime();
            $umur = $today->diff($dob)->y . " tahun";
        }

        $statusBayar = !empty($row['bukti_pembayaran']) ? 'Sudah Bayar' : 'Belum Bayar';

        echo "<tr>";
        echo "<td>" . $no++ . "</td>";
        echo "<td>" . implode(', ', $row['ids']) . "</td>";
        echo "<td>" . htmlspecialchars($row['nama_peserta']) . "</td>";
        echo "<td>" . htmlspecialchars(implode(', ', $row['categories']) ?: '-') . "</td>";
        echo "<td>" . htmlspecialchars(implode(', ', $row['kegiatan']) ?: '-') . "</td>";
        echo "<td>" . htmlspecialchars($row['tanggal_lahir'] ?? '-') . "</td>";
        echo "<td>" . $umur . "</td>";
        echo "<td>" . htmlspecialchars($row['jenis_kelamin']) . "</td>";
        echo "<td>" . htmlspecialchars($row['asal_kota'] ?? '-') . "</td>";
        echo "<td>" . htmlspecialchars($row['nama_club'] ?? '-') . "</td>";
        echo "<td>" . htmlspecialchars($row['sekolah'] ?? '-') . "</td>";
        echo "<td>" . htmlspecialchars($row['kelas'] ?? '-') . "</td>";
        echo "<td>" . htmlspecialchars($row['nomor_hp'] ?? '-') . "</td>";
        echo "<td>" . $statusBayar . "</td>";
        echo "<td>" . htmlspecialchars($row['created_at'] ?? '-') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    exit();
}

// --- Ambil kategori untuk dropdown (UNCHANGED) ---
$kategoriResult = $conn->query("SELECT id, name FROM categories ORDER BY name ASC");
$kategoriList = [];
while ($row = $kategoriResult->fetch_assoc()) {
    $kategoriList[] = $row;
}

// --- Ambil kegiatan untuk dropdown (UNCHANGED) ---
$kegiatanResult = $conn->query("SELECT id, nama_kegiatan FROM kegiatan ORDER BY nama_kegiatan ASC");
$kegiatanList = [];
while ($row = $kegiatanResult->fetch_assoc()) {
    $kegiatanList[] = $row;
}

// --- GET filter parameters (TRIMMED) ---
$category_id = trim($_GET['category_id'] ?? '');
$kegiatan_id = trim($_GET['kegiatan_id'] ?? '');
$gender = trim($_GET['gender'] ?? '');
$nama = trim($_GET['nama'] ?? '');
$club = trim($_GET['club'] ?? '');

// --- Query peserta (UNCHANGED) ---
$query = "SELECT p.*, c.name AS category_name, k.nama_kegiatan
          FROM peserta p
          LEFT JOIN categories c ON p.category_id = c.id
          LEFT JOIN kegiatan k ON p.kegiatan_id = k.id
          WHERE 1=1";

$params = [];
$types = '';

if (!empty($category_id)) {
    $query .= " AND p.category_id = ?";
    $params[] = $category_id;
    $types .= "i";
}

if (!empty($kegiatan_id)) {
    $query .= " AND p.kegiatan_id = ?";
    $params[] = $kegiatan_id;
    $types .= "i";
}

if (!empty($gender)) {
    $query .= " AND p.jenis_kelamin = ?";
    $params[] = $gender;
    $types .= "s";
}

if (!empty($nama)) {
    $query .= " AND LOWER(p.nama_peserta) LIKE LOWER(?)";
    $params[] = "%$nama%";
    $types .= "s";
}

if (!empty($club)) {
    $query .= " AND LOWER(p.nama_club) LIKE LOWER(?)";
    $params[] = "%$club%";
    $types .= "s";
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

// Group peserta by nama_peserta (UNCHANGED)
$pesertaGrouped = [];
$totalPeserta = 0;
$totalLaki = 0;
$totalPerempuan = 0;
$totalBayar = 0;

while ($row = $result->fetch_assoc()) {
    $totalPeserta++;
    if ($row['jenis_kelamin'] == 'Laki-laki') $totalLaki++;
    if ($row['jenis_kelamin'] == 'Perempuan') $totalPerempuan++;
    if (!empty($row['bukti_pembayaran'])) $totalBayar++;

    $nama_display = $row['nama_peserta'];
    $nama_key = strtolower(trim($nama_display));

    if (!isset($pesertaGrouped[$nama_key])) {
        $pesertaGrouped[$nama_key] = [
            'display_name' => $nama_display,
            'data' => $row,
            'ids' => [$row['id']],
            'categories' => [],
            'kegiatan' => [],
            'all_records' => [$row]
        ];
    } else {
        $pesertaGrouped[$nama_key]['ids'][] = $row['id'];
        $pesertaGrouped[$nama_key]['all_records'][] = $row;
    }

    if (!empty($row['category_name']) && !in_array($row['category_name'], $pesertaGrouped[$nama_key]['categories'])) {
        $pesertaGrouped[$nama_key]['categories'][] = $row['category_name'];
    }

    if (!empty($row['nama_kegiatan']) && !in_array($row['nama_kegiatan'], $pesertaGrouped[$nama_key]['kegiatan'])) {
        $pesertaGrouped[$nama_key]['kegiatan'][] = $row['nama_kegiatan'];
    }
}

$uniqueCount = count($pesertaGrouped);

$username = $_SESSION['username'] ?? 'User';
$name = $_SESSION['name'] ?? $username;
$role = $_SESSION['role'] ?? 'user';
?>
<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Peserta - Turnamen Panahan</title>
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
        <button id="mobile-menu-btn" onclick="toggleMobileMenu()" class="lg:hidden fixed top-4 left-4 z-[100] p-2 rounded-lg bg-zinc-900 text-white shadow-lg">
            <i class="fas fa-bars"></i>
        </button>

        <!-- Main Content -->
        <main class="flex-1 overflow-auto">
            <div class="px-6 lg:px-8 py-6">
                <!-- Toast Messages -->
                <?php if (isset($success_message)): ?>
                    <div id="toast-success" class="mb-4 flex items-center gap-3 px-4 py-3 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-800">
                        <i class="fas fa-check-circle text-emerald-500"></i>
                        <span class="text-sm"><?= $success_message ?></span>
                        <button onclick="this.parentElement.remove()" class="ml-auto text-emerald-500 hover:text-emerald-700">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                <?php endif; ?>

                <?php if (isset($error_message)): ?>
                    <div id="toast-error" class="mb-4 flex items-center gap-3 px-4 py-3 rounded-lg bg-red-50 border border-red-200 text-red-800">
                        <i class="fas fa-exclamation-circle text-red-500"></i>
                        <span class="text-sm"><?= $error_message ?></span>
                        <button onclick="this.parentElement.remove()" class="ml-auto text-red-500 hover:text-red-700">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                <?php endif; ?>

                <!-- Compact Header with Metrics -->
                <div class="bg-white rounded-xl border border-slate-200 shadow-sm mb-6">
                    <div class="px-6 py-4 border-b border-slate-100">
                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                            <div class="flex items-center gap-3">
                                <a href="dashboard.php" class="p-2 rounded-lg text-slate-400 hover:bg-slate-100 transition-colors">
                                    <i class="fas fa-arrow-left"></i>
                                </a>
                                <div>
                                    <h1 class="text-lg font-semibold text-slate-900">Data Peserta</h1>
                                    <p class="text-sm text-slate-500">Kelola data peserta turnamen panahan</p>
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <a href="pendaftaran.php"
                                   class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-archery-600 text-white text-sm font-medium hover:bg-archery-700 transition-colors">
                                    <i class="fas fa-user-plus"></i>
                                    <span class="hidden sm:inline">Tambah Peserta</span>
                                </a>
                                <?php
                                $exportParams = [];
                                if (!empty($category_id)) $exportParams['category_id'] = $category_id;
                                if (!empty($kegiatan_id)) $exportParams['kegiatan_id'] = $kegiatan_id;
                                if (!empty($gender)) $exportParams['gender'] = $gender;
                                if (!empty($nama)) $exportParams['nama'] = $nama;
                                if (!empty($club)) $exportParams['club'] = $club;
                                $exportParams['export'] = 'excel';
                                $exportUrl = '?' . http_build_query($exportParams);
                                ?>
                                <a href="<?= $exportUrl ?>"
                                   class="inline-flex items-center gap-2 px-4 py-2 rounded-lg border border-slate-300 text-slate-700 text-sm font-medium hover:bg-slate-50 transition-colors"
                                   onclick="return confirm('Export data peserta ke Excel?')">
                                    <i class="fas fa-file-excel text-emerald-600"></i>
                                    <span class="hidden sm:inline">Export</span>
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Metrics Bar -->
                    <div class="px-6 py-3 bg-slate-50 flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
                        <div class="flex items-center gap-2">
                            <span class="text-2xl font-bold text-slate-900"><?= $uniqueCount ?></span>
                            <span class="text-slate-500">Peserta Unik</span>
                        </div>
                        <span class="text-slate-300 hidden sm:inline">|</span>
                        <div class="flex items-center gap-1.5">
                            <span class="text-slate-400 text-xs">Total Entri:</span>
                            <span class="font-medium text-slate-700"><?= $totalPeserta ?></span>
                        </div>
                        <span class="text-slate-300 hidden sm:inline">|</span>
                        <div class="flex items-center gap-1.5">
                            <i class="fas fa-mars text-blue-500 text-xs"></i>
                            <span class="font-medium text-slate-700"><?= $totalLaki ?></span>
                            <span class="text-slate-400">Laki-laki</span>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <i class="fas fa-venus text-pink-500 text-xs"></i>
                            <span class="font-medium text-slate-700"><?= $totalPerempuan ?></span>
                            <span class="text-slate-400">Perempuan</span>
                        </div>
                        <span class="text-slate-300 hidden sm:inline">|</span>
                        <div class="flex items-center gap-1.5">
                            <i class="fas fa-check-circle text-emerald-500 text-xs"></i>
                            <span class="font-medium text-slate-700"><?= $totalBayar ?></span>
                            <span class="text-slate-400">Sudah Bayar</span>
                        </div>
                    </div>
                </div>

                <!-- Filter Form -->
                <div class="bg-white rounded-xl border border-slate-200 p-5 mb-6">
                    <h3 class="font-semibold text-slate-900 mb-4 flex items-center gap-2">
                        <i class="fas fa-filter text-slate-400"></i>
                        Filter Pencarian
                    </h3>
                    <!-- FORM: method=get, no action (UNCHANGED) -->
                    <form method="get" id="filterForm">
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1">Kategori</label>
                                <!-- SELECT: name="category_id" (UNCHANGED) -->
                                <select class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:ring-2 focus:ring-archery-500 focus:border-archery-500" name="category_id">
                                    <option value="">Semua Kategori</option>
                                    <?php foreach ($kategoriList as $kat): ?>
                                        <option value="<?= $kat['id'] ?>" <?= $category_id==$kat['id']?'selected':'' ?>>
                                            <?= htmlspecialchars($kat['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1">Kegiatan</label>
                                <!-- SELECT: name="kegiatan_id" (UNCHANGED) -->
                                <select class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:ring-2 focus:ring-archery-500 focus:border-archery-500" name="kegiatan_id">
                                    <option value="">Semua Kegiatan</option>
                                    <?php foreach ($kegiatanList as $keg): ?>
                                        <option value="<?= $keg['id'] ?>" <?= $kegiatan_id==$keg['id']?'selected':'' ?>>
                                            <?= htmlspecialchars($keg['nama_kegiatan']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1">Gender</label>
                                <!-- SELECT: name="gender" (UNCHANGED) -->
                                <select class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:ring-2 focus:ring-archery-500 focus:border-archery-500" name="gender">
                                    <option value="">Semua</option>
                                    <option value="Laki-laki" <?= $gender=="Laki-laki"?'selected':'' ?>>Laki-laki</option>
                                    <option value="Perempuan" <?= $gender=="Perempuan"?'selected':'' ?>>Perempuan</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1">Nama</label>
                                <!-- INPUT: name="nama" (UNCHANGED) -->
                                <input type="text" class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:ring-2 focus:ring-archery-500 focus:border-archery-500" name="nama" value="<?= htmlspecialchars($nama) ?>" placeholder="Cari nama...">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1">Club</label>
                                <!-- INPUT: name="club" (UNCHANGED) -->
                                <input type="text" class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:ring-2 focus:ring-archery-500 focus:border-archery-500" name="club" value="<?= htmlspecialchars($club) ?>" placeholder="Nama club...">
                            </div>
                            <div class="flex items-end gap-2">
                                <button type="submit" class="flex-1 px-4 py-2 rounded-lg bg-archery-600 text-white text-sm font-medium hover:bg-archery-700 transition-colors">
                                    <i class="fas fa-search mr-1"></i> Cari
                                </button>
                                <a href="?" class="px-3 py-2 rounded-lg border border-slate-300 text-slate-600 text-sm hover:bg-slate-50 transition-colors">
                                    <i class="fas fa-redo"></i>
                                </a>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Info Alert for Duplicates -->
                <?php if ($totalPeserta > $uniqueCount): ?>
                <div class="mb-4 flex items-center gap-3 px-4 py-3 rounded-lg bg-blue-50 border border-blue-200 text-blue-800">
                    <i class="fas fa-info-circle text-blue-500"></i>
                    <span class="text-sm">Ditemukan <?= $totalPeserta - $uniqueCount ?> peserta dengan nama yang sama. Data telah digabungkan.</span>
                </div>
                <?php endif; ?>

                <!-- Data Table -->
                <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
                    <div class="overflow-x-auto custom-scrollbar" style="max-height: 65vh;">
                        <table class="w-full">
                            <thead class="bg-slate-100 sticky top-0 z-10">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider w-12">#</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">Nama</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">Kategori</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">Kegiatan</th>
                                    <th class="px-4 py-3 text-center text-xs font-semibold text-slate-600 uppercase tracking-wider">Umur</th>
                                    <th class="px-4 py-3 text-center text-xs font-semibold text-slate-600 uppercase tracking-wider">Gender</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">Club</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">Sekolah</th>
                                    <th class="px-4 py-3 text-center text-xs font-semibold text-slate-600 uppercase tracking-wider">Status</th>
                                    <th class="px-4 py-3 text-center text-xs font-semibold text-slate-600 uppercase tracking-wider">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php if (empty($pesertaGrouped)): ?>
                                    <tr>
                                        <td colspan="10" class="px-4 py-12 text-center">
                                            <div class="flex flex-col items-center">
                                                <div class="w-16 h-16 rounded-full bg-slate-100 flex items-center justify-center mb-3">
                                                    <i class="fas fa-inbox text-slate-400 text-2xl"></i>
                                                </div>
                                                <p class="text-slate-500 font-medium">Tidak ada data peserta</p>
                                                <p class="text-slate-400 text-sm">Ubah filter pencarian atau tambah peserta baru</p>
                                            </div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php
                                    $no = 1;
                                    foreach ($pesertaGrouped as $key => $group):
                                        $nama = $group['display_name'];
                                        $p = $group['data'];
                                        $recordCount = count($group['all_records']);

                                        $umur = "-";
                                        if (!empty($p['tanggal_lahir'])) {
                                            $dob = new DateTime($p['tanggal_lahir']);
                                            $today = new DateTime();
                                            $umur = $today->diff($dob)->y . " th";
                                        }

                                        $hasBayar = !empty($p['bukti_pembayaran']);
                                    ?>
                                        <tr class="hover:bg-slate-50 transition-colors">
                                            <td class="px-4 py-3 text-sm text-slate-500"><?= $no++ ?></td>
                                            <td class="px-4 py-3">
                                                <div class="flex items-center gap-2">
                                                    <p class="font-medium text-slate-900"><?= htmlspecialchars($nama) ?></p>
                                                    <?php if ($recordCount > 1): ?>
                                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-700">
                                                            x<?= $recordCount ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                                <p class="text-xs text-slate-400">ID: <?= implode(', ', $group['ids']) ?></p>
                                            </td>
                                            <td class="px-4 py-3">
                                                <?php if (!empty($group['categories'])): ?>
                                                    <div class="flex flex-wrap gap-1">
                                                        <?php foreach ($group['categories'] as $cat): ?>
                                                            <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-cyan-100 text-cyan-700"><?= htmlspecialchars($cat) ?></span>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="text-slate-400 text-xs">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-4 py-3">
                                                <?php if (!empty($group['kegiatan'])): ?>
                                                    <div class="flex flex-wrap gap-1">
                                                        <?php foreach ($group['kegiatan'] as $keg): ?>
                                                            <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-700"><?= htmlspecialchars($keg) ?></span>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="text-slate-400 text-xs">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-4 py-3 text-center text-sm text-slate-600"><?= $umur ?></td>
                                            <td class="px-4 py-3 text-center">
                                                <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium <?= $p['jenis_kelamin'] == 'Laki-laki' ? 'bg-blue-100 text-blue-700' : 'bg-pink-100 text-pink-700' ?>">
                                                    <i class="fas <?= $p['jenis_kelamin'] == 'Laki-laki' ? 'fa-mars' : 'fa-venus' ?> text-xs"></i>
                                                    <?= $p['jenis_kelamin'] == 'Laki-laki' ? 'L' : 'P' ?>
                                                </span>
                                            </td>
                                            <td class="px-4 py-3 text-sm text-slate-600 max-w-32 truncate" title="<?= htmlspecialchars($p['nama_club'] ?? '') ?>">
                                                <?= htmlspecialchars($p['nama_club'] ?? '-') ?>
                                            </td>
                                            <td class="px-4 py-3 text-sm text-slate-600 max-w-32 truncate" title="<?= htmlspecialchars($p['sekolah'] ?? '') ?>">
                                                <?= htmlspecialchars($p['sekolah'] ?? '-') ?>
                                            </td>
                                            <td class="px-4 py-3 text-center">
                                                <?php if ($hasBayar): ?>
                                                    <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium bg-emerald-100 text-emerald-700">
                                                        <i class="fas fa-check-circle"></i> Lunas
                                                    </span>
                                                    <button onclick="showImage('<?= htmlspecialchars($p['bukti_pembayaran']) ?>', '<?= htmlspecialchars($nama) ?>')" class="block mx-auto mt-1 text-xs text-blue-600 hover:underline">
                                                        Lihat Bukti
                                                    </button>
                                                <?php else: ?>
                                                    <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium bg-amber-100 text-amber-700">
                                                        <i class="fas fa-clock"></i> Pending
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-4 py-3 text-center">
                                                <div class="flex items-center justify-center gap-1">
                                                    <?php if ($recordCount > 1): ?>
                                                        <button type="button" onclick="showDetails(<?= htmlspecialchars(json_encode($group['all_records'])) ?>)"
                                                                class="p-1.5 rounded-lg text-cyan-600 hover:bg-cyan-50 transition-colors" title="Lihat Detail">
                                                            <i class="fas fa-eye"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                    <button type="button" onclick="editPeserta(<?= htmlspecialchars(json_encode($p)) ?>)"
                                                            class="p-1.5 rounded-lg text-amber-600 hover:bg-amber-50 transition-colors" title="Edit">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button type="button" onclick="confirmDelete(<?= $p['id'] ?>, '<?= htmlspecialchars($nama, ENT_QUOTES) ?>')"
                                                            class="p-1.5 rounded-lg text-red-600 hover:bg-red-50 transition-colors" title="Hapus">
                                                        <i class="fas fa-trash-alt"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if (!empty($pesertaGrouped)): ?>
                        <div class="px-4 py-3 bg-slate-50 border-t border-slate-200 flex items-center justify-between">
                            <p class="text-sm text-slate-500">Menampilkan <?= $uniqueCount ?> peserta unik dari <?= $totalPeserta ?> total entri</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- Detail Modal -->
    <div id="detailModal" class="fixed inset-0 z-50 hidden">
        <div class="absolute inset-0 bg-black/50" onclick="closeDetailModal()"></div>
        <div class="absolute inset-4 sm:inset-auto sm:top-1/2 sm:left-1/2 sm:-translate-x-1/2 sm:-translate-y-1/2 sm:w-full sm:max-w-5xl bg-white rounded-2xl shadow-xl overflow-hidden max-h-[90vh] flex flex-col">
            <div class="bg-gradient-to-br from-cyan-600 to-cyan-800 text-white px-6 py-4 flex items-center justify-between flex-shrink-0">
                <h3 class="font-semibold text-lg">
                    <i class="fas fa-info-circle mr-2"></i>Detail Pendaftaran Peserta
                </h3>
                <button onclick="closeDetailModal()" class="p-2 rounded-lg hover:bg-white/10 transition-colors">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="p-6 overflow-y-auto custom-scrollbar flex-1" id="detailContent">
                <!-- Content loaded by JS -->
            </div>
        </div>
    </div>

    <!-- Edit Modal -->
    <div id="editModal" class="fixed inset-0 z-50 hidden">
        <div class="absolute inset-0 bg-black/50" onclick="closeEditModal()"></div>
        <div class="absolute inset-4 sm:inset-auto sm:top-1/2 sm:left-1/2 sm:-translate-x-1/2 sm:-translate-y-1/2 sm:w-full sm:max-w-2xl bg-white rounded-2xl shadow-xl overflow-hidden max-h-[90vh] flex flex-col">
            <div class="bg-gradient-to-br from-amber-500 to-amber-700 text-white px-6 py-4 flex items-center justify-between flex-shrink-0">
                <h3 class="font-semibold text-lg">
                    <i class="fas fa-edit mr-2"></i>Edit Data Peserta
                </h3>
                <button onclick="closeEditModal()" class="p-2 rounded-lg hover:bg-white/10 transition-colors">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <!-- FORM: method=POST, no action (UNCHANGED) -->
            <form method="POST" id="editForm" class="flex flex-col flex-1 overflow-hidden">
                <div class="p-6 overflow-y-auto custom-scrollbar flex-1">
                    <!-- INPUT: name="update_id" (UNCHANGED) -->
                    <input type="hidden" name="update_id" id="edit_id">

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Nama Peserta <span class="text-red-500">*</span></label>
                            <!-- INPUT: name="nama_peserta" (UNCHANGED) -->
                            <input type="text" class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:ring-2 focus:ring-archery-500 focus:border-archery-500" name="nama_peserta" id="edit_nama_peserta" required>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Kategori <span class="text-red-500">*</span></label>
                            <!-- SELECT: name="category_id" (UNCHANGED) -->
                            <select class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:ring-2 focus:ring-archery-500 focus:border-archery-500" name="category_id" id="edit_category_id" required>
                                <option value="">Pilih Kategori</option>
                                <?php foreach ($kategoriList as $kat): ?>
                                    <option value="<?= $kat['id'] ?>"><?= htmlspecialchars($kat['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Kegiatan <span class="text-red-500">*</span></label>
                            <!-- SELECT: name="kegiatan_id" (UNCHANGED) -->
                            <select class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:ring-2 focus:ring-archery-500 focus:border-archery-500" name="kegiatan_id" id="edit_kegiatan_id" required>
                                <option value="">Pilih Kegiatan</option>
                                <?php foreach ($kegiatanList as $keg): ?>
                                    <option value="<?= $keg['id'] ?>"><?= htmlspecialchars($keg['nama_kegiatan']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Tanggal Lahir <span class="text-red-500">*</span></label>
                            <!-- INPUT: name="tanggal_lahir" (UNCHANGED) -->
                            <input type="date" class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:ring-2 focus:ring-archery-500 focus:border-archery-500" name="tanggal_lahir" id="edit_tanggal_lahir" required>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Jenis Kelamin <span class="text-red-500">*</span></label>
                            <!-- SELECT: name="jenis_kelamin" (UNCHANGED) -->
                            <select class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:ring-2 focus:ring-archery-500 focus:border-archery-500" name="jenis_kelamin" id="edit_jenis_kelamin" required>
                                <option value="">Pilih Jenis Kelamin</option>
                                <option value="Laki-laki">Laki-laki</option>
                                <option value="Perempuan">Perempuan</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Asal Kota</label>
                            <!-- INPUT: name="asal_kota" (UNCHANGED) -->
                            <input type="text" class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:ring-2 focus:ring-archery-500 focus:border-archery-500" name="asal_kota" id="edit_asal_kota">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Nama Club</label>
                            <!-- INPUT: name="nama_club" (UNCHANGED) -->
                            <input type="text" class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:ring-2 focus:ring-archery-500 focus:border-archery-500" name="nama_club" id="edit_nama_club">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Sekolah</label>
                            <!-- INPUT: name="sekolah" (UNCHANGED) -->
                            <input type="text" class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:ring-2 focus:ring-archery-500 focus:border-archery-500" name="sekolah" id="edit_sekolah">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Kelas</label>
                            <!-- INPUT: name="kelas" (UNCHANGED) -->
                            <input type="text" class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:ring-2 focus:ring-archery-500 focus:border-archery-500" name="kelas" id="edit_kelas">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Nomor HP</label>
                            <!-- INPUT: name="nomor_hp" (UNCHANGED) -->
                            <input type="text" class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:ring-2 focus:ring-archery-500 focus:border-archery-500" name="nomor_hp" id="edit_nomor_hp" placeholder="Contoh: 08123456789">
                        </div>
                    </div>

                    <div class="mt-4 p-3 rounded-lg bg-blue-50 border border-blue-200">
                        <p class="text-sm text-blue-800">
                            <i class="fas fa-info-circle mr-1"></i>
                            Field yang bertanda <span class="text-red-500">*</span> wajib diisi.
                        </p>
                    </div>
                </div>
                <div class="px-6 py-4 bg-slate-50 border-t border-slate-200 flex justify-end gap-2">
                    <button type="button" onclick="closeEditModal()" class="px-4 py-2 rounded-lg border border-slate-300 text-slate-700 text-sm font-medium hover:bg-slate-100 transition-colors">
                        Batal
                    </button>
                    <button type="submit" class="px-4 py-2 rounded-lg bg-amber-600 text-white text-sm font-medium hover:bg-amber-700 transition-colors">
                        <i class="fas fa-save mr-1"></i> Simpan
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="fixed inset-0 z-50 hidden">
        <div class="absolute inset-0 bg-black/50" onclick="closeDeleteModal()"></div>
        <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-full max-w-md bg-white rounded-2xl shadow-xl overflow-hidden">
            <div class="bg-gradient-to-br from-red-500 to-red-700 text-white px-6 py-4 flex items-center justify-between">
                <h3 class="font-semibold text-lg">
                    <i class="fas fa-exclamation-triangle mr-2"></i>Konfirmasi Hapus
                </h3>
                <button onclick="closeDeleteModal()" class="p-2 rounded-lg hover:bg-white/10 transition-colors">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="p-6 text-center">
                <div class="w-16 h-16 rounded-full bg-red-100 flex items-center justify-center mx-auto mb-4">
                    <i class="fas fa-user-times text-red-500 text-2xl"></i>
                </div>
                <h4 class="text-lg font-semibold text-slate-900 mb-2">Hapus peserta ini?</h4>
                <p class="text-sm text-slate-500 mb-2">Nama Peserta:</p>
                <p class="font-bold text-slate-900 mb-4" id="deletePesertaName"></p>
                <div class="p-3 rounded-lg bg-amber-50 border border-amber-200 text-left">
                    <p class="text-sm text-amber-800">
                        <i class="fas fa-warning mr-1"></i>
                        Data yang dihapus tidak dapat dikembalikan!
                    </p>
                </div>
            </div>
            <div class="px-6 py-4 bg-slate-50 border-t border-slate-200 flex justify-end gap-2">
                <button type="button" onclick="closeDeleteModal()" class="px-4 py-2 rounded-lg border border-slate-300 text-slate-700 text-sm font-medium hover:bg-slate-100 transition-colors">
                    Batal
                </button>
                <!-- FORM: method=POST (UNCHANGED) -->
                <form method="POST" id="deleteForm" class="inline">
                    <!-- INPUT: name="delete_id" (UNCHANGED) -->
                    <input type="hidden" name="delete_id" id="deleteIdInput">
                    <button type="submit" class="px-4 py-2 rounded-lg bg-red-600 text-white text-sm font-medium hover:bg-red-700 transition-colors">
                        <i class="fas fa-trash-alt mr-1"></i> Ya, Hapus
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Image Modal -->
    <div id="imageModal" class="fixed inset-0 z-50 hidden">
        <div class="absolute inset-0 bg-black/50" onclick="closeImageModal()"></div>
        <div class="absolute inset-4 sm:inset-auto sm:top-1/2 sm:left-1/2 sm:-translate-x-1/2 sm:-translate-y-1/2 sm:w-full sm:max-w-2xl bg-white rounded-2xl shadow-xl overflow-hidden max-h-[90vh] flex flex-col">
            <div class="bg-gradient-to-br from-slate-700 to-slate-900 text-white px-6 py-4 flex items-center justify-between flex-shrink-0">
                <h3 class="font-semibold text-lg" id="imageModalLabel">Bukti Pembayaran</h3>
                <button onclick="closeImageModal()" class="p-2 rounded-lg hover:bg-white/10 transition-colors">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="p-6 overflow-y-auto custom-scrollbar flex-1 text-center" id="imageModalBody">
                <!-- Content loaded by JS -->
            </div>
            <div class="px-6 py-4 bg-slate-50 border-t border-slate-200 flex justify-end gap-2">
                <button type="button" onclick="closeImageModal()" class="px-4 py-2 rounded-lg border border-slate-300 text-slate-700 text-sm font-medium hover:bg-slate-100 transition-colors">
                    Tutup
                </button>
                <a id="downloadImage" href="" download class="px-4 py-2 rounded-lg bg-archery-600 text-white text-sm font-medium hover:bg-archery-700 transition-colors">
                    <i class="fas fa-download mr-1"></i> Download
                </a>
            </div>
        </div>
    </div>

    <!-- Mobile Sidebar -->
    <div id="mobile-overlay" onclick="toggleMobileMenu()" class="fixed inset-0 bg-black/50 z-40 hidden lg:hidden"></div>
    <div id="mobile-sidebar" class="fixed inset-y-0 left-0 w-72 bg-zinc-900 text-white z-50 transform -translate-x-full transition-transform lg:hidden flex flex-col">
        <div class="flex items-center gap-3 px-6 py-5 border-b border-zinc-800">
            <div class="w-10 h-10 rounded-lg bg-archery-600 flex items-center justify-center">
                <i class="fas fa-bullseye text-white"></i>
            </div>
            <div class="flex-1">
                <h1 class="font-semibold text-sm">Turnamen Panahan</h1>
            </div>
            <button id="close-mobile-menu" onclick="toggleMobileMenu()" class="p-2 rounded-lg hover:bg-zinc-800">
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
            <a href="kegiatan.view.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800">
                <i class="fas fa-calendar w-5"></i><span class="text-sm">Kegiatan</span>
            </a>
            <a href="peserta.view.php" class="flex items-center gap-3 px-4 py-3 rounded-lg bg-archery-600/20 text-archery-400">
                <i class="fas fa-user-friends w-5"></i><span class="text-sm font-medium">Peserta</span>
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
        // Mobile menu toggle logic (Global scope)
        function toggleMobileMenu() {
            const mobileSidebar = document.getElementById('mobile-sidebar');
            const mobileOverlay = document.getElementById('mobile-overlay');
            console.log('Toggle called', { sidebar: !!mobileSidebar, overlay: !!mobileOverlay });
            
            if (mobileSidebar && mobileOverlay) {
                mobileSidebar.classList.toggle('-translate-x-full');
                mobileOverlay.classList.toggle('hidden');
                console.log('Classes toggled');
            } else {
                console.error('Mobile menu elements not found');
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            console.log('DOM Content Loaded - Mobile Menu check');
        });

        // Detail Modal
        function showDetails(records) {
            const detailContent = document.getElementById('detailContent');

            let html = '<div class="mb-4 p-3 rounded-lg bg-blue-50 border border-blue-200">';
            html += '<p class="text-sm text-blue-800"><i class="fas fa-info-circle mr-2"></i>';
            html += '<strong>Peserta ini memiliki ' + records.length + ' pendaftaran dengan kategori/kegiatan yang berbeda</strong></p></div>';

            html += '<div class="overflow-x-auto">';
            html += '<table class="w-full text-sm">';
            html += '<thead class="bg-slate-100">';
            html += '<tr>';
            html += '<th class="px-3 py-2 text-left text-xs font-semibold text-slate-600">No</th>';
            html += '<th class="px-3 py-2 text-left text-xs font-semibold text-slate-600">ID</th>';
            html += '<th class="px-3 py-2 text-left text-xs font-semibold text-slate-600">Kategori</th>';
            html += '<th class="px-3 py-2 text-left text-xs font-semibold text-slate-600">Kegiatan</th>';
            html += '<th class="px-3 py-2 text-center text-xs font-semibold text-slate-600">Gender</th>';
            html += '<th class="px-3 py-2 text-center text-xs font-semibold text-slate-600">Status</th>';
            html += '<th class="px-3 py-2 text-center text-xs font-semibold text-slate-600">Aksi</th>';
            html += '</tr>';
            html += '</thead>';
            html += '<tbody class="divide-y divide-slate-100">';

            records.forEach(function(record, index) {
                const statusBadge = record.bukti_pembayaran ?
                    '<span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-100 text-emerald-700">Lunas</span>' :
                    '<span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-700">Pending</span>';

                html += '<tr class="hover:bg-slate-50">';
                html += '<td class="px-3 py-2 text-slate-500">' + (index + 1) + '</td>';
                html += '<td class="px-3 py-2 font-medium text-slate-900">' + record.id + '</td>';
                html += '<td class="px-3 py-2 text-slate-600">' + (record.category_name || '-') + '</td>';
                html += '<td class="px-3 py-2 text-slate-600">' + (record.nama_kegiatan || '-') + '</td>';
                html += '<td class="px-3 py-2 text-center text-slate-600">' + record.jenis_kelamin + '</td>';
                html += '<td class="px-3 py-2 text-center">' + statusBadge + '</td>';
                html += '<td class="px-3 py-2 text-center">';
                html += '<button class="p-1.5 rounded-lg text-amber-600 hover:bg-amber-50" onclick="editPeserta(' + JSON.stringify(record).replace(/"/g, '&quot;') + ')" title="Edit"><i class="fas fa-edit"></i></button>';
                html += '<button class="p-1.5 rounded-lg text-red-600 hover:bg-red-50" onclick="confirmDelete(' + record.id + ', \'' + record.nama_peserta.replace(/'/g, "\\'") + '\')" title="Hapus"><i class="fas fa-trash-alt"></i></button>';
                html += '</td>';
                html += '</tr>';
            });

            html += '</tbody>';
            html += '</table>';
            html += '</div>';

            detailContent.innerHTML = html;
            document.getElementById('detailModal').classList.remove('hidden');
        }

        function closeDetailModal() {
            document.getElementById('detailModal').classList.add('hidden');
        }

        // Edit Modal
        function editPeserta(data) {
            document.getElementById('edit_id').value = data.id;
            document.getElementById('edit_nama_peserta').value = data.nama_peserta || '';
            document.getElementById('edit_category_id').value = data.category_id || '';
            document.getElementById('edit_kegiatan_id').value = data.kegiatan_id || '';
            document.getElementById('edit_tanggal_lahir').value = data.tanggal_lahir || '';
            document.getElementById('edit_jenis_kelamin').value = data.jenis_kelamin || '';
            document.getElementById('edit_asal_kota').value = data.asal_kota || '';
            document.getElementById('edit_nama_club').value = data.nama_club || '';
            document.getElementById('edit_sekolah').value = data.sekolah || '';
            document.getElementById('edit_kelas').value = data.kelas || '';
            document.getElementById('edit_nomor_hp').value = data.nomor_hp || '';

            closeDetailModal();
            document.getElementById('editModal').classList.remove('hidden');
        }

        function closeEditModal() {
            document.getElementById('editModal').classList.add('hidden');
        }

        // Delete Modal
        function confirmDelete(id, nama) {
            document.getElementById('deleteIdInput').value = id;
            document.getElementById('deletePesertaName').textContent = nama;

            closeDetailModal();
            document.getElementById('deleteModal').classList.remove('hidden');
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').classList.add('hidden');
        }

        // Image Modal
        function showImage(filename, pesertaName) {
            const modalBody = document.getElementById('imageModalBody');
            const downloadLink = document.getElementById('downloadImage');
            document.getElementById('imageModalLabel').textContent = 'Bukti Pembayaran - ' + pesertaName;

            const fileExtension = filename.toLowerCase().split('.').pop();
            const imagePath = '../assets/uploads/' + filename;

            if (['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'].includes(fileExtension)) {
                modalBody.innerHTML = '<img src="' + imagePath + '" alt="Bukti Pembayaran" class="max-w-full max-h-96 mx-auto rounded-lg" onerror="this.onerror=null;this.parentElement.innerHTML=\'<div class=&quot;p-8 text-center&quot;><i class=&quot;fas fa-image text-slate-400 text-4xl mb-3&quot;></i><p class=&quot;text-slate-500&quot;>Gambar tidak dapat dimuat</p></div>\'">';
                downloadLink.href = imagePath;
                downloadLink.download = 'bukti_' + pesertaName.replace(/[^a-zA-Z0-9]/g, '_') + '.' + fileExtension;
            } else if (fileExtension === 'pdf') {
                modalBody.innerHTML = '<div class="p-8"><i class="fas fa-file-pdf text-red-500 text-5xl mb-4"></i><p class="text-slate-700 font-medium">File PDF</p><a href="' + imagePath + '" target="_blank" class="inline-block mt-4 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">Buka PDF</a></div>';
                downloadLink.href = imagePath;
            } else {
                modalBody.innerHTML = '<div class="p-8"><i class="fas fa-file text-slate-400 text-5xl mb-4"></i><p class="text-slate-500">Format tidak didukung</p></div>';
                downloadLink.href = imagePath;
            }

            document.getElementById('imageModal').classList.remove('hidden');
        }

        function closeImageModal() {
            document.getElementById('imageModal').classList.add('hidden');
        }

        // Close modals on Escape
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                closeDetailModal();
                closeEditModal();
                closeDeleteModal();
                closeImageModal();
            }
        });

        // Auto-submit on select change (Scoped to filter form only)
        document.addEventListener('DOMContentLoaded', function() {
            const filterForm = document.getElementById('filterForm');
            if (filterForm) {
                filterForm.querySelectorAll('select').forEach(function(select) {
                    select.addEventListener('change', function() {
                        filterForm.submit();
                    });
                });
            }
        });

        // Auto dismiss toasts after 5 seconds
        setTimeout(function() {
            const toasts = document.querySelectorAll('#toast-success, #toast-error');
            toasts.forEach(function(toast) {
                toast.style.transition = 'opacity 0.3s';
                toast.style.opacity = '0';
                setTimeout(() => toast.remove(), 300);
            });
        }, 5000);
    </script>
</body>
</html>
