<?php
/**
 * Data Peserta - Turnamen Panahan
 * UI: Intentional Minimalism with Tailwind CSS
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

// Toast message handling - check session first for flash messages
$toast_message = $_SESSION['toast_message'] ?? '';
$toast_type = $_SESSION['toast_type'] ?? '';
unset($_SESSION['toast_message'], $_SESSION['toast_type']);

// Handle AJAX request for getting peserta by club (from pendaftaran.php)
if (isset($_GET['action']) && $_GET['action'] === 'get_peserta') {
    header('Content-Type: application/json');
    $club = isset($_GET['club']) ? trim($_GET['club']) : '';
    if (empty($club)) {
        echo json_encode([]);
        exit;
    }
    try {
        $query = "
            SELECT p.id, p.nama_peserta, p.tanggal_lahir, p.jenis_kelamin, p.nomor_hp, p.asal_kota, p.sekolah, p.kelas
            FROM peserta p
            INNER JOIN (
                SELECT nama_peserta, MAX(id) as max_id
                FROM peserta
                WHERE nama_club = ?
                GROUP BY nama_peserta
            ) latest ON p.id = latest.max_id
            ORDER BY p.nama_peserta ASC
        ";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("s", $club);
        $stmt->execute();
        $result = $stmt->get_result();
        $pesertaList = [];
        while ($row = $result->fetch_assoc()) {
            $pesertaList[] = $row;
        }
        $stmt->close();
        echo json_encode($pesertaList);
        exit;
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
}

// Handle AJAX request for getting categories by kegiatan (for add modal)
if (isset($_GET['action']) && $_GET['action'] === 'get_categories') {
    header('Content-Type: application/json');
    $kegiatan_id = isset($_GET['kegiatan_id']) ? intval($_GET['kegiatan_id']) : 0;
    if (!$kegiatan_id) {
        echo json_encode([]);
        exit;
    }
    try {
        $query = "
            SELECT c.id, c.name, c.min_age, c.max_age, c.gender 
            FROM categories c 
            JOIN kegiatan_kategori kk ON c.id = kk.category_id 
            WHERE kk.kegiatan_id = ? AND c.status = 'active'
            ORDER BY c.name ASC
        ";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $kegiatan_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $categories = [];
        while ($row = $result->fetch_assoc()) {
            $categories[] = $row;
        }
        $stmt->close();
        echo json_encode($categories);
        exit;
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
}

// Handle CRUD Operations
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if (!checkRateLimit('peserta_crud', 10, 60)) {
        $toast_message = "Terlalu banyak permintaan. Silakan coba lagi dalam satu menit.";
        $toast_type = 'error';
    } else {
        verify_csrf();
        $_POST = cleanInput($_POST);
        $action = $_POST['action'];
        
        switch ($action) {
        case 'create':
            $nama_peserta = $_POST['nama_peserta'] ?? '';
            $tanggal_lahir = $_POST['tanggal_lahir'] ?? '';
            $jenis_kelamin = $_POST['jenis_kelamin'] ?? '';
            $asal_kota = $_POST['asal_kota'] ?? '';
            $nama_club = $_POST['nama_club'] ?? '';
            if ($nama_club === 'CLUB_BARU' && !empty($_POST['club_baru'])) {
                $nama_club = $_POST['club_baru'];
            }
            $sekolah = $_POST['sekolah'] ?? '';
            $kelas = $_POST['kelas'] ?? '';
            $nomor_hp = $_POST['nomor_hp'] ?? '';
            $category_ids = isset($_POST['category_ids']) ? (is_array($_POST['category_ids']) ? $_POST['category_ids'] : [$_POST['category_ids']]) : [];
            $kegiatan_id = intval($_POST['kegiatan_id'] ?? 0);

            // Handle file upload
            $bukti_pembayaran = '';
            if (isset($_FILES['bukti_pembayaran']) && $_FILES['bukti_pembayaran']['error'] === UPLOAD_ERR_OK) {
                // Validate File Size (2MB)
                if ($_FILES['bukti_pembayaran']['size'] > 2 * 1024 * 1024) {
                    $toast_message = "File bukti pembayaran terlalu besar! Maksimal 2MB.";
                    $toast_type = 'error';
                    break;
                }

                // Validate MIME type
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($_FILES['bukti_pembayaran']['tmp_name']);
                $allowed_mimes = ['image/jpeg', 'image/png', 'application/pdf'];
                
                if (!in_array($mime, $allowed_mimes)) {
                    $toast_message = "Format file tidak didukung! Gunakan JPG, PNG, atau PDF.";
                    $toast_type = 'error';
                    break;
                }

                $upload_dir = '../assets/uploads/pembayaran/';
                if (!file_exists($upload_dir)) mkdir($upload_dir, 0755, true);
                
                $file_name = $_FILES['bukti_pembayaran']['name'];
                $file_extension = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                $allowed_extensions = ['jpg', 'jpeg', 'png', 'pdf'];

                if (!in_array($file_extension, $allowed_extensions)) {
                    $toast_message = "Ekstensi file tidak didukung!";
                    $toast_type = 'error';
                    break;
                }

                $unique_name = date('YmdHis') . '_' . bin2hex(random_bytes(8)) . '.' . $file_extension;
                
                if (move_uploaded_file($_FILES['bukti_pembayaran']['tmp_name'], $upload_dir . $unique_name)) {
                    $bukti_pembayaran = 'pembayaran/' . $unique_name;
                }
            }

            if (empty($category_ids)) {
                $toast_message = "Minimal pilih satu kategori!";
                $toast_type = 'error';
                break;
            }

            $successCount = 0;
            foreach ($category_ids as $category_id) {
                // Check duplicate
                $check = $conn->prepare("SELECT id FROM peserta WHERE nama_peserta = ? AND category_id = ? AND kegiatan_id = ?");
                $check->bind_param("sii", $nama_peserta, $category_id, $kegiatan_id);
                $check->execute();
                if ($check->get_result()->num_rows > 0) { $check->close(); continue; }
                $check->close();

                $stmt = $conn->prepare("INSERT INTO peserta (nama_peserta, tanggal_lahir, jenis_kelamin, asal_kota, nama_club, sekolah, kelas, nomor_hp, bukti_pembayaran, category_id, kegiatan_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                $stmt->bind_param("sssssssssii", $nama_peserta, $tanggal_lahir, $jenis_kelamin, $asal_kota, $nama_club, $sekolah, $kelas, $nomor_hp, $bukti_pembayaran, $category_id, $kegiatan_id);
                if ($stmt->execute()) $successCount++;
                $stmt->close();
            }

            if ($successCount > 0) {
                $_SESSION['toast_message'] = "$successCount pendaftaran berhasil ditambahkan!";
                $_SESSION['toast_type'] = 'success';
                
                // Redirect to clean URL while preserving relevant filters
                $params = $_GET;
                unset($params['add_peserta']); // Stop modal from reopening
                if ($kegiatan_id) $params['kegiatan_id'] = $kegiatan_id; // Show new activity
                
                header("Location: ?" . http_build_query($params));
                exit;
            } else {
                $toast_message = "Kategori yang dipilih sudah terdaftar!";
                $toast_type = 'error';
            }
            break;

        case 'update':
            $id = intval($_POST['id']);
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

            $stmt = $conn->prepare("UPDATE peserta SET nama_peserta=?, category_id=?, kegiatan_id=?, tanggal_lahir=?, jenis_kelamin=?, asal_kota=?, nama_club=?, sekolah=?, kelas=?, nomor_hp=?, updated_at=NOW() WHERE id=?");
            $stmt->bind_param("siisssssssi", $nama_peserta, $category_id, $kegiatan_id, $tanggal_lahir, $jenis_kelamin, $asal_kota, $nama_club, $sekolah, $kelas, $nomor_hp, $id);
            if ($stmt->execute()) {
                $_SESSION['toast_message'] = "Data peserta berhasil diperbarui!";
                $_SESSION['toast_type'] = 'success';
                
                $params = $_GET;
                if ($kegiatan_id) $params['kegiatan_id'] = $kegiatan_id;
                
                header("Location: ?" . http_build_query($params));
                exit;
            } else {
                $toast_message = "Gagal memperbarui data!";
                $toast_type = 'error';
            }
            $stmt->close();
            break;

        case 'delete':
            $id = intval($_POST['id']);
            $stmt = $conn->prepare("SELECT bukti_pembayaran FROM peserta WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $peserta_data = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($peserta_data) {
                if (!empty($peserta_data['bukti_pembayaran'])) {
                    $file_path = '../assets/uploads/' . $peserta_data['bukti_pembayaran'];
                    if (file_exists($file_path)) unlink($file_path);
                }
                $stmt = $conn->prepare("DELETE FROM peserta WHERE id = ?");
                $stmt->bind_param("i", $id);
                if ($stmt->execute()) {
                    $_SESSION['toast_message'] = "Data peserta berhasil dihapus!";
                    $_SESSION['toast_type'] = 'success';
                    
                    header("Location: ?" . http_build_query($_GET));
                    exit;
                } else {
                    $toast_message = "Gagal menghapus data!";
                    $toast_type = 'error';
                }
                $stmt->close();
            }
            break;
    }
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

// --- Ambil daftar club unik untuk dropdown (NEW) ---
$clubResult = $conn->query("SELECT DISTINCT nama_club FROM peserta WHERE nama_club IS NOT NULL AND nama_club != '' ORDER BY nama_club ASC");
$clubList = [];
while ($row = $clubResult->fetch_assoc()) {
    $clubList[] = $row['nama_club'];
}

// ============================================
// PAGINATION LOGIC
// ============================================
$limit = 50;
$page = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
$total_rows = $uniqueCount;
$total_pages = ceil($total_rows / $limit);
$offset = ($page - 1) * $limit;

// Slice the array for current page
$pesertaGroupedPaginated = array_slice($pesertaGrouped, $offset, $limit, true);

// Helper function to build pagination URL preserving GET params
function buildPaginationUrl($page, $params = []) {
    $current = $_GET;
    $current['p'] = $page;
    foreach ($params as $key => $value) {
        $current[$key] = $value;
    }
    return '?' . http_build_query($current);
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
    <title>Data Peserta - Turnamen Panahan</title>
    <script src="https://cdn.tailwindcss.com"></script>

    <script><?= getThemeTailwindConfig() ?></script>

    <script><?= getThemeInitScript() ?></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .custom-scrollbar::-webkit-scrollbar { width: 6px; height: 6px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 3px; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #94a3b8; }

        /* Dark mode scrollbar */
        .dark .custom-scrollbar::-webkit-scrollbar-track { background: #27272a; }
        .dark .custom-scrollbar::-webkit-scrollbar-thumb { background: #52525b; }
        .dark .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #71717a; }
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
                    <!-- Theme Toggle -->

                    <?= getThemeToggleButton() ?>
                </div>
                <a href="../actions/logout.php" onclick="event.preventDefault(); const url = this.href; showConfirmModal('Logout', 'Yakin ingin logout?', () => window.location.href = url, 'danger')"
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
                <!-- Toast Notification -->

                <?php if (!empty($toast_message)): ?>
                <div id="toast" class="fixed top-4 right-4 z-[200]">

                    <div class="flex items-center gap-3 px-4 py-3 rounded-lg shadow-lg <?= $toast_type === 'success' ? 'bg-emerald-50 border border-emerald-200 text-emerald-800' : 'bg-red-50 border border-red-200 text-red-800' ?>">

                        <i class="fas <?= $toast_type === 'success' ? 'fa-check-circle text-emerald-500' : 'fa-exclamation-circle text-red-500' ?>"></i>

                        <span class="text-sm font-medium"><?= htmlspecialchars($toast_message) ?></span>

                        <button onclick="dismissToast()" class="ml-2 <?= $toast_type === 'success' ? 'text-emerald-500 hover:text-emerald-700' : 'text-red-500 hover:text-red-700' ?>">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>

                <?php endif; ?>

                <!-- Compact Header with Metrics -->
                <div class="bg-white dark:bg-zinc-900 rounded-xl border border-slate-200 dark:border-zinc-800 shadow-sm mb-6 transition-colors">
                    <div class="px-6 py-4 border-b border-slate-100 dark:border-zinc-800">
                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                            <div class="flex items-center gap-3">
                                <a href="dashboard.php" class="p-2 rounded-lg text-slate-400 dark:text-zinc-500 hover:bg-slate-100 dark:hover:bg-zinc-800 transition-colors">
                                    <i class="fas fa-arrow-left"></i>
                                </a>
                                <div>
                                    <h1 class="text-lg font-semibold text-slate-900 dark:text-white">Data Peserta</h1>
                                    <p class="text-sm text-slate-500 dark:text-zinc-400">Kelola data peserta turnamen panahan</p>
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <button onclick="openAddModal()"
                                   class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-archery-600 text-white text-sm font-medium hover:bg-archery-700 transition-colors shadow-sm">
                                    <i class="fas fa-user-plus"></i>
                                    <span class="hidden sm:inline">Tambah Peserta</span>
                                </button>
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
                                   onclick="event.preventDefault(); const url = this.href; showConfirmModal('Export Data', 'Export data peserta ke Excel?', () => window.location.href = url)">
                                    <i class="fas fa-file-excel text-emerald-600"></i>
                                    <span class="hidden sm:inline">Export</span>
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Metrics Bar -->
                    <div class="px-6 py-3 bg-slate-50 dark:bg-zinc-800 flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
                        <div class="flex items-center gap-2">

                            <span class="text-2xl font-bold text-slate-900 dark:text-white"><?= $uniqueCount ?></span>
                            <span class="text-slate-500 dark:text-zinc-400">Peserta Unik</span>
                        </div>
                        <span class="text-slate-300 dark:text-zinc-600 hidden sm:inline">|</span>
                        <div class="flex items-center gap-1.5">
                            <span class="text-slate-400 dark:text-zinc-500 text-xs">Total Entri:</span>

                            <span class="font-medium text-slate-700 dark:text-zinc-300"><?= $totalPeserta ?></span>
                        </div>
                        <span class="text-slate-300 dark:text-zinc-600 hidden sm:inline">|</span>
                        <div class="flex items-center gap-1.5">
                            <i class="fas fa-mars text-blue-500 text-xs"></i>

                            <span class="font-medium text-slate-700 dark:text-zinc-300"><?= $totalLaki ?></span>
                            <span class="text-slate-400 dark:text-zinc-500">Laki-laki</span>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <i class="fas fa-venus text-pink-500 text-xs"></i>

                            <span class="font-medium text-slate-700 dark:text-zinc-300"><?= $totalPerempuan ?></span>
                            <span class="text-slate-400 dark:text-zinc-500">Perempuan</span>
                        </div>
                        <span class="text-slate-300 dark:text-zinc-600 hidden sm:inline">|</span>
                        <div class="flex items-center gap-1.5">
                            <i class="fas fa-check-circle text-emerald-500 text-xs"></i>

                            <span class="font-medium text-slate-700 dark:text-zinc-300"><?= $totalBayar ?></span>
                            <span class="text-slate-400 dark:text-zinc-500">Sudah Bayar</span>
                        </div>
                    </div>
                </div>

                <!-- Filter Form -->
                <div class="bg-white dark:bg-zinc-900 rounded-xl border border-slate-200 dark:border-zinc-800 p-5 mb-6 transition-colors">
                    <h3 class="font-semibold text-slate-900 dark:text-white mb-4 flex items-center gap-2">
                        <i class="fas fa-filter text-slate-400 dark:text-zinc-500"></i>
                        Filter Pencarian
                    </h3>
                    <!-- FORM: method=get, no action (UNCHANGED) -->
                    <form method="get" id="filterForm">
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-zinc-300 mb-1">Kategori</label>
                                <!-- SELECT: name="category_id" (UNCHANGED) -->
                                <select class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-sm text-slate-900 dark:text-white focus:ring-2 focus:ring-archery-500 focus:border-archery-500" name="category_id">
                                    <option value="">Semua Kategori</option>

                                    <?php foreach ($kategoriList as $kat): ?>

                                        <option value="<?= $kat['id'] ?>" <?= $category_id==$kat['id']?'selected':'' ?>>

                                            <?= htmlspecialchars($kat['name']) ?>
                                        </option>

                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-zinc-300 mb-1">Kegiatan</label>
                                <!-- SELECT: name="kegiatan_id" (UNCHANGED) -->
                                <select class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-sm text-slate-900 dark:text-white focus:ring-2 focus:ring-archery-500 focus:border-archery-500" name="kegiatan_id">
                                    <option value="">Semua Kegiatan</option>

                                    <?php foreach ($kegiatanList as $keg): ?>

                                        <option value="<?= $keg['id'] ?>" <?= $kegiatan_id==$keg['id']?'selected':'' ?>>

                                            <?= htmlspecialchars($keg['nama_kegiatan']) ?>
                                        </option>

                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-zinc-300 mb-1">Gender</label>
                                <!-- SELECT: name="gender" (UNCHANGED) -->
                                <select class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-sm text-slate-900 dark:text-white focus:ring-2 focus:ring-archery-500 focus:border-archery-500" name="gender">
                                    <option value="">Semua</option>

                                    <option value="Laki-laki" <?= $gender=="Laki-laki"?'selected':'' ?>>Laki-laki</option>

                                    <option value="Perempuan" <?= $gender=="Perempuan"?'selected':'' ?>>Perempuan</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-zinc-300 mb-1">Nama</label>
                                <!-- INPUT: name="nama" (UNCHANGED) -->

                                <input type="text" class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-sm text-slate-900 dark:text-white focus:ring-2 focus:ring-archery-500 focus:border-archery-500" name="nama" value="<?= htmlspecialchars($nama) ?>" placeholder="Cari nama...">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-zinc-300 mb-1">Club</label>
                                <!-- INPUT: name="club" (UNCHANGED) -->

                                <input type="text" class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-sm text-slate-900 dark:text-white focus:ring-2 focus:ring-archery-500 focus:border-archery-500" name="club" value="<?= htmlspecialchars($club) ?>" placeholder="Nama club...">
                            </div>
                            <div class="flex items-end gap-2">
                                <button type="submit" class="flex-1 px-4 py-2 rounded-lg bg-archery-600 text-white text-sm font-medium hover:bg-archery-700 transition-colors">
                                    <i class="fas fa-search mr-1"></i> Cari
                                </button>
                                <a href="?" class="px-3 py-2 rounded-lg border border-slate-300 dark:border-zinc-700 text-slate-600 dark:text-zinc-400 text-sm hover:bg-slate-50 dark:hover:bg-zinc-800 transition-colors">
                                    <i class="fas fa-redo"></i>
                                </a>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Info Alert for Duplicates -->

                <?php if ($totalPeserta > $uniqueCount): ?>
                <div class="mb-4 flex items-center gap-3 px-4 py-3 rounded-lg bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 text-blue-800 dark:text-blue-300">
                    <i class="fas fa-info-circle text-blue-500"></i>

                    <span class="text-sm">Ditemukan <?= $totalPeserta - $uniqueCount ?> peserta dengan nama yang sama. Data telah digabungkan.</span>
                </div>

                <?php endif; ?>

                <!-- Data Table -->
                <div class="bg-white dark:bg-zinc-900 rounded-xl border border-slate-200 dark:border-zinc-800 overflow-hidden transition-colors">
                    <div class="overflow-x-auto custom-scrollbar" style="max-height: 65vh;">
                        <table class="w-full">
                            <thead class="bg-slate-100 dark:bg-zinc-800 sticky top-0 z-10">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 dark:text-zinc-400 uppercase tracking-wider w-12">#</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 dark:text-zinc-400 uppercase tracking-wider">Nama</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 dark:text-zinc-400 uppercase tracking-wider">Kategori</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 dark:text-zinc-400 uppercase tracking-wider">Kegiatan</th>
                                    <th class="px-4 py-3 text-center text-xs font-semibold text-slate-600 dark:text-zinc-400 uppercase tracking-wider">Umur</th>
                                    <th class="px-4 py-3 text-center text-xs font-semibold text-slate-600 dark:text-zinc-400 uppercase tracking-wider">Gender</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 dark:text-zinc-400 uppercase tracking-wider">Club</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 dark:text-zinc-400 uppercase tracking-wider">Sekolah</th>
                                    <th class="px-4 py-3 text-center text-xs font-semibold text-slate-600 dark:text-zinc-400 uppercase tracking-wider">Status</th>
                                    <th class="px-4 py-3 text-center text-xs font-semibold text-slate-600 dark:text-zinc-400 uppercase tracking-wider">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-zinc-800">

                                <?php if (empty($pesertaGrouped)): ?>
                                    <tr>
                                        <td colspan="10" class="px-4 py-12 text-center">
                                            <div class="flex flex-col items-center">
                                                <div class="w-16 h-16 rounded-full bg-slate-100 dark:bg-zinc-800 flex items-center justify-center mb-3">
                                                    <i class="fas fa-inbox text-slate-400 dark:text-zinc-500 text-2xl"></i>
                                                </div>
                                                <p class="text-slate-500 dark:text-zinc-400 font-medium">Tidak ada data peserta</p>
                                                <p class="text-slate-400 dark:text-zinc-500 text-sm">Ubah filter pencarian atau tambah peserta baru</p>
                                            </div>
                                        </td>
                                    </tr>

                                <?php else: ?>
                                    <?php
                                    $no = $offset + 1;
                                    foreach ($pesertaGroupedPaginated as $key => $group):
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
                                        <tr class="hover:bg-slate-50 dark:hover:bg-zinc-800 transition-colors">

                                            <td class="px-4 py-3 text-sm text-slate-500 dark:text-zinc-400"><?= $no++ ?></td>
                                            <td class="px-4 py-3">
                                                <div class="flex items-center gap-2">

                                                    <p class="font-medium text-slate-900 dark:text-white"><?= htmlspecialchars($nama) ?></p>

                                                    <?php if ($recordCount > 1): ?>
                                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-xs font-medium bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400">

                                                            x<?= $recordCount ?>
                                                        </span>

                                                    <?php endif; ?>
                                                </div>

                                                <p class="text-xs text-slate-400 dark:text-zinc-500">ID: <?= implode(', ', $group['ids']) ?></p>
                                            </td>
                                            <td class="px-4 py-3">

                                                <?php if (!empty($group['categories'])): ?>
                                                    <div class="flex flex-wrap gap-1">

                                                        <?php foreach ($group['categories'] as $cat): ?>

                                                            <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-cyan-100 dark:bg-cyan-900/30 text-cyan-700 dark:text-cyan-400"><?= htmlspecialchars($cat) ?></span>

                                                        <?php endforeach; ?>
                                                    </div>

                                                <?php else: ?>
                                                    <span class="text-slate-400 dark:text-zinc-500 text-xs">-</span>

                                                <?php endif; ?>
                                            </td>
                                            <td class="px-4 py-3">

                                                <?php if (!empty($group['kegiatan'])): ?>
                                                    <div class="flex flex-wrap gap-1">

                                                        <?php foreach ($group['kegiatan'] as $keg): ?>

                                                            <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400"><?= htmlspecialchars($keg) ?></span>

                                                        <?php endforeach; ?>
                                                    </div>

                                                <?php else: ?>
                                                    <span class="text-slate-400 dark:text-zinc-500 text-xs">-</span>

                                                <?php endif; ?>
                                            </td>

                                            <td class="px-4 py-3 text-center text-sm text-slate-600 dark:text-zinc-400"><?= $umur ?></td>
                                            <td class="px-4 py-3 text-center">

                                                <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium <?= $p['jenis_kelamin'] == 'Laki-laki' ? 'bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400' : 'bg-pink-100 dark:bg-pink-900/30 text-pink-700 dark:text-pink-400' ?>">

                                                    <i class="fas <?= $p['jenis_kelamin'] == 'Laki-laki' ? 'fa-mars' : 'fa-venus' ?> text-xs"></i>

                                                    <?= $p['jenis_kelamin'] == 'Laki-laki' ? 'L' : 'P' ?>
                                                </span>
                                            </td>

                                            <td class="px-4 py-3 text-sm text-slate-600 dark:text-zinc-400 max-w-32 truncate" title="<?= htmlspecialchars($p['nama_club'] ?? '') ?>">

                                                <?= htmlspecialchars($p['nama_club'] ?? '-') ?>
                                            </td>

                                            <td class="px-4 py-3 text-sm text-slate-600 dark:text-zinc-400 max-w-32 truncate" title="<?= htmlspecialchars($p['sekolah'] ?? '') ?>">

                                                <?= htmlspecialchars($p['sekolah'] ?? '-') ?>
                                            </td>
                                            <td class="px-4 py-3 text-center">

                                                <?php if ($hasBayar): ?>
                                                    <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-400">
                                                        <i class="fas fa-check-circle"></i> Lunas
                                                    </span>

                                                    <button onclick="showImage('payment', '../assets/uploads/<?= htmlspecialchars($p['bukti_pembayaran']) ?>', '<?= htmlspecialchars($nama) ?>')" class="block mx-auto mt-1 text-xs text-blue-600 dark:text-blue-400 hover:underline">
                                                        Lihat Bukti
                                                    </button>

                                                <?php else: ?>
                                                    <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400">
                                                        <i class="fas fa-clock"></i> Pending
                                                    </span>

                                                <?php endif; ?>
                                            </td>
                                            <td class="px-4 py-3 text-center">
                                                <div class="flex items-center justify-center gap-1">

                                                    <?php if ($recordCount > 1): ?>

                                                        <button type="button" onclick="showDetails(<?= htmlspecialchars(json_encode($group['all_records'])) ?>)"
                                                                class="p-1.5 rounded-lg text-cyan-600 dark:text-cyan-400 hover:bg-cyan-50 dark:hover:bg-cyan-900/30 transition-colors" title="Lihat Detail">
                                                            <i class="fas fa-eye"></i>
                                                        </button>

                                                    <?php endif; ?>

                                                    <button type="button" onclick="editPeserta(<?= htmlspecialchars(json_encode($p)) ?>)"
                                                            class="p-1.5 rounded-lg text-amber-600 dark:text-amber-400 hover:bg-amber-50 dark:hover:bg-amber-900/30 transition-colors" title="Edit">
                                                        <i class="fas fa-edit"></i>
                                                    </button>

                                                    <button type="button" onclick="confirmDelete(<?= $p['id'] ?>, '<?= htmlspecialchars($nama, ENT_QUOTES) ?>')"
                                                            class="p-1.5 rounded-lg text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/30 transition-colors" title="Hapus">
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

                    <?php if (!empty($pesertaGroupedPaginated)): ?>
                        <!-- Pagination Footer -->
                        <div class="px-4 py-3 bg-white dark:bg-zinc-900 border-t border-slate-100 dark:border-zinc-800 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                            <p class="text-sm text-slate-500 dark:text-zinc-400">

                                Menampilkan <span class="font-medium text-slate-900 dark:text-white"><?= $offset + 1 ?></span> - <span class="font-medium text-slate-900 dark:text-white"><?= min($offset + $limit, $total_rows) ?></span> dari <span class="font-medium text-slate-900 dark:text-white"><?= $total_rows ?></span> peserta unik (<span class="text-slate-400 dark:text-zinc-500"><?= $totalPeserta ?> total entri</span>)
                            </p>

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
                                <span class="p-2 text-slate-300 dark:text-zinc-700"><i class="fas fa-angles-left text-xs"></i></span>
                                <span class="p-2 text-slate-300 dark:text-zinc-700"><i class="fas fa-angle-left text-xs"></i></span>

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
                                <span class="p-2 text-slate-300 dark:text-zinc-700"><i class="fas fa-angle-right text-xs"></i></span>
                                <span class="p-2 text-slate-300 dark:text-zinc-700"><i class="fas fa-angles-right text-xs"></i></span>

                                <?php endif; ?>
                            </nav>

                            <?php endif; ?>
                        </div>

                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- Add Participant Modal -->
    <div id="addModal" class="fixed inset-0 z-50 hidden">
        <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeAddModal()"></div>
        <div class="absolute inset-4 sm:inset-auto sm:top-1/2 sm:left-1/2 sm:-translate-x-1/2 sm:-translate-y-1/2 sm:w-full sm:max-w-3xl bg-white dark:bg-zinc-900 rounded-2xl shadow-2xl overflow-hidden max-h-[90vh] flex flex-col transition-colors">
            <div class="bg-gradient-to-br from-archery-600 to-archery-800 text-white px-6 py-4 flex items-center justify-between flex-shrink-0">
                <h3 class="font-semibold text-lg flex items-center gap-2">
                    <i class="fas fa-user-plus"></i> Tambah Peserta
                </h3>
                <button onclick="closeAddModal()" class="p-2 rounded-lg hover:bg-white/10 transition-colors">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <form method="POST" enctype="multipart/form-data" id="addPesertaForm" class="flex flex-col flex-1 overflow-hidden">

                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="create">
                <input type="hidden" id="peserta_id_existing" name="peserta_id_existing" value="0">
                <input type="hidden" id="nama_peserta_hidden" name="nama_peserta">

                <div class="p-6 overflow-y-auto custom-scrollbar flex-1 space-y-6">
                    <!-- Step 1: Club & Name Selection -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-zinc-300 mb-2">Nama Club <span class="text-red-500">*</span></label>
                            <select id="add_nama_club" name="nama_club" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-archery-500" onchange="loadPesertaByClub('add')" required>
                                <option value="">-- Pilih Club --</option>

                                <?php foreach ($clubList as $club): ?>

                                    <option value="<?= htmlspecialchars($club) ?>"><?= htmlspecialchars($club) ?></option>

                                <?php endforeach; ?>
                                <option value="CLUB_BARU">+ Tambah Club Baru</option>
                            </select>
                            <input type="text" id="add_club_baru" name="club_baru" class="hidden mt-2 w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-slate-900 dark:text-white text-sm" placeholder="Nama club baru">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-zinc-300 mb-2">Nama Peserta <span class="text-red-500">*</span></label>
                            <select id="add_nama_peserta_select" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-archery-500 disabled:opacity-50" onchange="loadPesertaData('add')" disabled>
                                <option value="">-- Pilih club dahulu --</option>
                            </select>
                            <input type="text" id="add_nama_peserta_manual" class="hidden mt-2 w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-slate-900 dark:text-white text-sm" placeholder="Nama peserta baru">
                        </div>
                    </div>

                    <!-- Step 2: Personal Info -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-6 pt-4 border-t border-slate-100 dark:border-zinc-800">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-zinc-300 mb-2">Tanggal Lahir <span class="text-red-500">*</span></label>
                            <input type="text" id="add_tanggal_lahir" name="tanggal_lahir" class="datepicker w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-slate-900 dark:text-white text-sm" placeholder="Pilih Tanggal" onchange="updateKategoriOptions('add')" required>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-zinc-300 mb-2">Jenis Kelamin <span class="text-red-500">*</span></label>
                            <div class="flex gap-4">
                                <label class="flex items-center gap-2 cursor-pointer text-sm dark:text-zinc-400">
                                    <input type="radio" name="jenis_kelamin" value="Laki-laki" onchange="updateKategoriOptions('add')" required> Laki-laki
                                </label>
                                <label class="flex items-center gap-2 cursor-pointer text-sm dark:text-zinc-400">
                                    <input type="radio" name="jenis_kelamin" value="Perempuan" onchange="updateKategoriOptions('add')" required> Perempuan
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-zinc-300 mb-2">Kegiatan <span class="text-red-500">*</span></label>
                            <select name="kegiatan_id" id="add_kegiatan_id" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-slate-900 dark:text-white text-sm" onchange="loadKegiatanCategories()" required>
                                <option value="">-- Pilih Kegiatan --</option>

                                <?php foreach ($kegiatanList as $keg): ?>

                                    <option value="<?= $keg['id'] ?>"><?= htmlspecialchars($keg['nama_kegiatan']) ?></option>

                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-zinc-300 mb-2">Nomor HP <span class="text-red-500">*</span></label>
                            <input type="tel" id="add_nomor_hp" name="nomor_hp" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-slate-900 dark:text-white text-sm" placeholder="08xxxxxxxx" required>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-zinc-300 mb-2">Asal Kota</label>
                            <input type="text" id="add_asal_kota" name="asal_kota" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-slate-900 dark:text-white text-sm">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-zinc-300 mb-2">Sekolah</label>
                            <input type="text" id="add_sekolah" name="sekolah" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-slate-900 dark:text-white text-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-zinc-300 mb-2">Kelas</label>
                            <input type="text" id="add_kelas" name="kelas" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-slate-900 dark:text-white text-sm">
                        </div>
                    </div>

                    <!-- Step 3: Kategori -->
                    <div class="pt-4 border-t border-slate-100 dark:border-zinc-800">
                        <label class="block text-sm font-medium text-slate-700 dark:text-zinc-300 mb-2">Pilih Kategori <span class="text-red-500">*</span></label>
                        <div id="add_categories_list" class="grid grid-cols-1 sm:grid-cols-2 gap-3 max-h-48 overflow-y-auto custom-scrollbar p-1">
                            <!-- Populated by JS -->
                            <div class="col-span-2 text-center py-4 text-slate-400 dark:text-zinc-500 text-sm">
                                Silakan pilih kegiatan, tanggal lahir, dan jenis kelamin dahulu.
                            </div>
                        </div>
                    </div>

                    <!-- Step 4: Bukti Bayar -->
                    <div class="pt-4 border-t border-slate-100 dark:border-zinc-800">
                        <label class="block text-sm font-medium text-slate-700 dark:text-zinc-300 mb-2">Bukti Pembayaran</label>
                        <input type="file" name="bukti_pembayaran" class="w-full text-sm text-slate-500 dark:text-zinc-400 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-archery-50 file:text-archery-700 dark:file:bg-archery-900/30 dark:file:text-archery-400 hover:file:bg-archery-100 transition-all">
                    </div>
                </div>

                <div class="px-6 py-4 bg-slate-50 dark:bg-zinc-800/50 border-t border-slate-200 dark:border-zinc-700 flex gap-3 flex-shrink-0">
                    <button type="button" onclick="closeAddModal()" class="flex-1 px-4 py-2 rounded-lg border border-slate-300 dark:border-zinc-600 text-slate-700 dark:text-zinc-300 text-sm font-medium hover:bg-slate-100 dark:hover:bg-zinc-700 transition-colors">Batal</button>
                    <button type="submit" class="flex-1 px-4 py-2 rounded-lg bg-archery-600 text-white text-sm font-medium hover:bg-archery-700 transition-transform active:scale-95">Simpan Pendaftaran</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Detail Modal -->
    <div id="detailModal" class="fixed inset-0 z-50 hidden">
        <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeDetailModal()"></div>
        <div class="absolute inset-4 sm:inset-auto sm:top-1/2 sm:left-1/2 sm:-translate-x-1/2 sm:-translate-y-1/2 sm:w-full sm:max-w-5xl bg-white dark:bg-zinc-900 rounded-2xl shadow-xl overflow-hidden max-h-[90vh] flex flex-col transition-colors">
            <div class="bg-gradient-to-br from-cyan-600 to-cyan-800 text-white px-6 py-4 flex items-center justify-between flex-shrink-0">
                <h3 class="font-semibold text-lg flex items-center gap-2">
                    <i class="fas fa-info-circle"></i> Detail Pendaftaran Peserta
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
        <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeEditModal()"></div>
        <div class="absolute inset-4 sm:inset-auto sm:top-1/2 sm:left-1/2 sm:-translate-x-1/2 sm:-translate-y-1/2 sm:w-full sm:max-w-2xl bg-white dark:bg-zinc-900 rounded-2xl shadow-xl overflow-hidden max-h-[90vh] flex flex-col transition-colors">
            <div class="bg-gradient-to-br from-amber-500 to-amber-700 text-white px-6 py-4 flex items-center justify-between flex-shrink-0">
                <h3 class="font-semibold text-lg flex items-center gap-2">
                    <i class="fas fa-edit"></i> Edit Data Peserta
                </h3>
                <button onclick="closeEditModal()" class="p-2 rounded-lg hover:bg-white/10 transition-colors">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="POST" class="flex flex-col flex-1 overflow-hidden">

                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" id="edit_id">

                <div class="p-6 overflow-y-auto custom-scrollbar flex-1 space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-zinc-300 mb-1">Nama Peserta <span class="text-red-500">*</span></label>
                            <input type="text" class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-slate-900 dark:text-white text-sm" name="nama_peserta" id="edit_nama_peserta" required>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-zinc-300 mb-1">Kategori <span class="text-red-500">*</span></label>
                            <select class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-slate-900 dark:text-white text-sm" name="category_id" id="edit_category_id" required>

                                <?php foreach ($kategoriList as $kat): ?>

                                    <option value="<?= $kat['id'] ?>"><?= htmlspecialchars($kat['name']) ?></option>

                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-zinc-300 mb-1">Kegiatan <span class="text-red-500">*</span></label>
                            <select class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-slate-900 dark:text-white text-sm" name="kegiatan_id" id="edit_kegiatan_id" required>

                                <?php foreach ($kegiatanList as $keg): ?>

                                    <option value="<?= $keg['id'] ?>"><?= htmlspecialchars($keg['nama_kegiatan']) ?></option>

                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-zinc-300 mb-1">Tanggal Lahir <span class="text-red-500">*</span></label>
                            <input type="text" class="datepicker w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-slate-900 dark:text-white text-sm" name="tanggal_lahir" id="edit_tanggal_lahir" placeholder="Pilih Tanggal" required>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-zinc-300 mb-1">Jenis Kelamin <span class="text-red-500">*</span></label>
                            <select class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-slate-900 dark:text-white text-sm" name="jenis_kelamin" id="edit_jenis_kelamin" required>
                                <option value="Laki-laki">Laki-laki</option>
                                <option value="Perempuan">Perempuan</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-zinc-300 mb-1">Nomor HP</label>
                            <input type="text" class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-slate-900 dark:text-white text-sm" name="nomor_hp" id="edit_nomor_hp">
                        </div>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-2">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-zinc-300 mb-1">Asal Kota</label>
                            <input type="text" class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-slate-900 dark:text-white text-sm" name="asal_kota" id="edit_asal_kota">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-zinc-300 mb-1">Nama Club</label>
                            <input type="text" class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-slate-900 dark:text-white text-sm" name="nama_club" id="edit_nama_club">
                        </div>
                    </div>
                </div>

                <div class="px-6 py-4 bg-slate-50 dark:bg-zinc-800/50 border-t border-slate-200 dark:border-zinc-700 flex justify-end gap-2 flex-shrink-0">
                    <button type="button" onclick="closeEditModal()" class="px-4 py-2 rounded-lg border border-slate-300 dark:border-zinc-600 text-slate-700 dark:text-zinc-300 text-sm font-medium hover:bg-slate-100 dark:hover:bg-zinc-700 transition-colors">Batal</button>
                    <button type="submit" class="px-4 py-2 rounded-lg bg-amber-600 text-white text-sm font-medium hover:bg-amber-700 transition-colors">Simpan</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="fixed inset-0 z-50 hidden">
        <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeDeleteModal()"></div>
        <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-full max-w-md bg-white dark:bg-zinc-900 rounded-2xl shadow-xl overflow-hidden transition-colors">
            <div class="bg-gradient-to-br from-red-500 to-red-700 text-white px-6 py-4 flex items-center justify-between">
                <h3 class="font-semibold text-lg flex items-center gap-2">
                    <i class="fas fa-trash"></i> Konfirmasi Hapus
                </h3>
                <button onclick="closeDeleteModal()" class="p-2 rounded-lg hover:bg-white/10 transition-colors">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="p-6 text-center">
                <div class="w-16 h-16 rounded-full bg-red-100 dark:bg-red-900/30 flex items-center justify-center mx-auto mb-4">
                    <i class="fas fa-user-times text-red-500 dark:text-red-400 text-2xl"></i>
                </div>
                <h4 class="text-lg font-semibold text-slate-900 dark:text-white mb-2">Hapus peserta ini?</h4>
                <p class="font-bold text-red-600 dark:text-red-400 mb-4" id="deletePesertaName"></p>
                <div class="p-3 rounded-lg bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 text-left">
                    <p class="text-sm text-amber-800 dark:text-amber-400">
                        <i class="fas fa-exclamation-triangle mr-1"></i> Data yang dihapus tidak dapat dikembalikan!
                    </p>
                </div>
            </div>
            <div class="px-6 py-4 bg-slate-50 dark:bg-zinc-800/50 border-t border-slate-200 dark:border-zinc-700 flex justify-end gap-2">
                <button type="button" onclick="closeDeleteModal()" class="px-4 py-2 rounded-lg border border-slate-300 dark:border-zinc-600 text-slate-700 dark:text-zinc-300 text-sm font-medium hover:bg-slate-100 dark:hover:bg-zinc-700 transition-colors">Batal</button>
                <form method="POST" class="inline">

                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" id="deleteIdInput">
                    <button type="submit" class="px-4 py-2 rounded-lg bg-red-600 text-white text-sm font-medium hover:bg-red-700 transition-colors">Hapus</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Image Modal -->
    <div id="imageModal" class="fixed inset-0 z-50 hidden">
        <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeImageModal()"></div>
        <div class="absolute inset-4 sm:inset-auto sm:top-1/2 sm:left-1/2 sm:-translate-x-1/2 sm:-translate-y-1/2 sm:w-full sm:max-w-2xl bg-white dark:bg-zinc-900 rounded-2xl shadow-xl overflow-hidden max-h-[90vh] flex flex-col transition-colors">
            <div class="bg-gradient-to-br from-zinc-700 to-zinc-900 text-white px-6 py-4 flex items-center justify-between flex-shrink-0">
                <h3 class="font-semibold text-lg" id="imageModalLabel">Bukti Pembayaran</h3>
                <button onclick="closeImageModal()" class="p-2 rounded-lg hover:bg-white/10 transition-colors">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="p-6 overflow-y-auto custom-scrollbar flex-1 text-center bg-slate-50 dark:bg-zinc-950" id="imageModalBody">
                <!-- Content loaded by JS -->
            </div>
            <div class="px-6 py-4 bg-white dark:bg-zinc-900 border-t border-slate-200 dark:border-zinc-800 flex justify-end gap-2">
                <button type="button" onclick="closeImageModal()" class="px-4 py-2 rounded-lg border border-slate-300 dark:border-zinc-700 text-slate-700 dark:text-zinc-300 text-sm font-medium hover:bg-slate-100 dark:hover:bg-zinc-800 transition-colors">Tutup</button>
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
            <a href="../actions/logout.php" onclick="event.preventDefault(); const url = this.href; showConfirmModal('Logout', 'Yakin ingin logout?', () => window.location.href = url, 'danger')"
               class="flex items-center gap-2 w-full px-4 py-2 rounded-lg text-red-400 hover:bg-red-500/10 transition-colors text-sm">
                <i class="fas fa-sign-out-alt w-5"></i>
                <span>Logout</span>
            </a>
        </div>
    </div>

    <script>
        <?= getConfirmationModal() ?>

        // Mobile menu toggle logic
        function toggleMobileMenu() {
            const mobileSidebar = document.getElementById('mobile-sidebar');
            const mobileOverlay = document.getElementById('mobile-overlay');
            if (mobileSidebar && mobileOverlay) {
                mobileSidebar.classList.toggle('-translate-x-full');
                mobileOverlay.classList.toggle('hidden');
            }
        }

        // Modal Management
        function openAddModal() {
            document.getElementById('addPesertaForm').reset();
            resetAddModalFields();
            document.getElementById('addModal').classList.remove('hidden');
        }

        function closeAddModal() {
            document.getElementById('addModal').classList.add('hidden');
        }

        function resetAddModalFields() {
            document.getElementById('add_club_baru').classList.add('hidden');
            document.getElementById('add_nama_peserta_select').disabled = true;
            document.getElementById('add_nama_peserta_select').innerHTML = '<option value="">-- Pilih club dahulu --</option>';
            document.getElementById('add_nama_peserta_manual').classList.add('hidden');
            document.getElementById('peserta_id_existing').value = '0';
            document.getElementById('add_categories_list').innerHTML = '<div class="col-span-2 text-center py-4 text-slate-400 dark:text-zinc-500 text-sm">Silakan pilih kegiatan, tanggal lahir, dan jenis kelamin dahulu.</div>';
        }

        let allCategories = [];

        function loadKegiatanCategories() {
            const kegiatanId = document.getElementById('add_kegiatan_id').value;
            if (!kegiatanId) {
                allCategories = [];
                updateKategoriOptions('add');
                return;
            }

            fetch(`?action=get_categories&kegiatan_id=${kegiatanId}`)
                .then(res => res.json())
                .then(data => {
                    allCategories = data;
                    updateKategoriOptions('add');
                });
        }

        function loadPesertaByClub(prefix) {
            const clubSelect = document.getElementById(prefix + '_nama_club');
            const clubBaru = document.getElementById(prefix + '_club_baru');
            const pesertaSelect = document.getElementById(prefix + '_nama_peserta_select');
            const selectedClub = clubSelect.value;

            if (selectedClub === 'CLUB_BARU') {
                clubBaru.classList.remove('hidden');
                pesertaSelect.disabled = false;
                pesertaSelect.innerHTML = '<option value="PESERTA_BARU" selected>+ Tambah Peserta Baru</option>';
                loadPesertaData(prefix);
                return;
            } else {
                clubBaru.classList.add('hidden');
            }

            if (!selectedClub) {
                pesertaSelect.disabled = true;
                pesertaSelect.innerHTML = '<option value="">-- Pilih club dahulu --</option>';
                return;
            }

            pesertaSelect.disabled = true;
            pesertaSelect.innerHTML = '<option value="">Memuat...</option>';

            fetch(`?action=get_peserta&club=${encodeURIComponent(selectedClub)}`)
                .then(res => res.json())
                .then(data => {
                    pesertaSelect.innerHTML = '<option value="">-- Pilih Peserta --</option>';
                    data.forEach(p => {
                        const opt = document.createElement('option');
                        opt.value = p.id;
                        opt.textContent = p.nama_peserta;
                        opt.dataset.peserta = JSON.stringify(p);
                        pesertaSelect.appendChild(opt);
                    });
                    const newOpt = document.createElement('option');
                    newOpt.value = 'PESERTA_BARU';
                    newOpt.textContent = '+ Tambah Peserta Baru';
                    pesertaSelect.appendChild(newOpt);
                    pesertaSelect.disabled = false;
                });
        }

        function loadPesertaData(prefix) {
            const select = document.getElementById(prefix + '_nama_peserta_select');
            const manual = document.getElementById(prefix + '_nama_peserta_manual');
            const existingId = document.getElementById('peserta_id_existing');
            const nameHidden = document.getElementById('nama_peserta_hidden');
            
            if (select.value === 'PESERTA_BARU') {
                manual.classList.remove('hidden');
                manual.required = true;
                existingId.value = '0';
                nameHidden.value = manual.value;
                manual.oninput = () => { nameHidden.value = manual.value; };
            } else if (select.value) {
                manual.classList.add('hidden');
                manual.required = false;
                const data = JSON.parse(select.selectedOptions[0].dataset.peserta);
                existingId.value = data.id;
                nameHidden.value = data.nama_peserta;
                
                document.getElementById(prefix + '_tanggal_lahir').value = data.tanggal_lahir;
                const genderRadio = document.querySelector(`input[name="jenis_kelamin"][value="${data.jenis_kelamin}"]`);
                if (genderRadio) genderRadio.checked = true;
                document.getElementById(prefix + '_nomor_hp').value = data.nomor_hp || '';
                document.getElementById(prefix + '_asal_kota').value = data.asal_kota || '';
                document.getElementById(prefix + '_sekolah').value = data.sekolah || '';
                document.getElementById(prefix + '_kelas').value = data.kelas || '';
                
                updateKategoriOptions(prefix);
            }
        }

        function updateKategoriOptions(prefix) {
            const dob = document.getElementById(prefix + '_tanggal_lahir').value;
            const gender = document.querySelector('input[name="jenis_kelamin"]:checked')?.value;
            const container = document.getElementById(prefix + '_categories_list');
            
            if (!dob || !gender || allCategories.length === 0) {
                return;
            }

            const birthYear = new Date(dob).getFullYear();
            const currentYear = new Date().getFullYear();
            const age = currentYear - birthYear;

            container.innerHTML = allCategories.map(c => {
                const ageMatch = age >= c.min_age && age <= c.max_age;
                const genderMatch = c.gender === 'Campuran' || c.gender === gender;
                const isEligible = ageMatch && genderMatch;
                
                let reason = '';
                if (!ageMatch) reason = `Umur ${age} th tidak cocok (${c.min_age}-${c.max_age} th)`;
                else if (!genderMatch) reason = `Hanya untuk ${c.gender}`;

                return `
                    <label class="flex items-start gap-3 p-3 rounded-xl border ${isEligible ? 'border-slate-200 dark:border-zinc-800 hover:bg-slate-50 dark:hover:bg-zinc-800 cursor-pointer' : 'border-slate-100 dark:border-zinc-800/50 bg-slate-50/50 dark:bg-zinc-900/30 opacity-60 cursor-not-allowed'} transition-colors">
                        <input type="checkbox" name="category_ids[]" value="${c.id}" ${isEligible ? '' : 'disabled'} class="mt-1 rounded ${isEligible ? 'text-archery-600 focus:ring-archery-500' : 'text-slate-300'}">
                        <div class="flex-1">
                            <p class="text-sm font-semibold ${isEligible ? 'text-slate-900 dark:text-white' : 'text-slate-500 dark:text-zinc-500'} capitalize">${c.name}</p>
                            <p class="text-[10px] text-slate-500 dark:text-zinc-400">${c.min_age}-${c.max_age} th • ${c.gender}</p>
                            ${isEligible ? '' : `<p class="text-[10px] text-red-500 dark:text-red-400 mt-1"><i class="fas fa-info-circle mr-1"></i>${reason}</p>`}
                        </div>
                    </label>
                `;
            }).join('');
        }

        // Show Detail Modal
        function showDetails(records) {
            const detailContent = document.getElementById('detailContent');
            let html = `
                <div class="space-y-6">
                    <div class="flex flex-col sm:flex-row gap-6 p-4 rounded-2xl bg-slate-50 dark:bg-zinc-800/50 border border-slate-100 dark:border-zinc-800">
                        <div class="w-20 h-20 rounded-2xl bg-archery-100 dark:bg-archery-900/30 flex items-center justify-center flex-shrink-0">
                            <i class="fas fa-user text-3xl text-archery-600 dark:text-archery-400"></i>
                        </div>
                        <div class="flex-1 space-y-2">
                            <h4 class="text-xl font-bold text-slate-900 dark:text-white">${records[0].nama_peserta}</h4>
                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-6 gap-y-1 text-sm text-slate-500 dark:text-zinc-400">
                                <p><i class="fas fa-birthday-cake mr-2 w-4"></i>${records[0].tanggal_lahir}</p>
                                <p><i class="fas fa-phone mr-2 w-4"></i>${records[0].nomor_hp || '-'}</p>
                                <p><i class="fas fa-users mr-2 w-4"></i>${records[0].nama_club || '-'}</p>
                                <p><i class="fas fa-map-marker-alt mr-2 w-4"></i>${records[0].asal_kota || '-'}</p>
                                <p><i class="fas fa-school mr-2 w-4"></i>${records[0].sekolah || '-'}</p>
                                <p><i class="fas fa-graduation-cap mr-2 w-4"></i>Kelas ${records[0].kelas || '-'}</p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-slate-200 dark:border-zinc-800 overflow-hidden shadow-sm">
                        <div class="px-5 py-3 bg-slate-50 dark:bg-zinc-800/50 border-b border-slate-100 dark:border-zinc-800">
                            <p class="text-xs font-bold text-slate-500 dark:text-zinc-400 uppercase tracking-widest">Pendaftaran Aktif (${records.length})</p>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="text-left bg-slate-100/50 dark:bg-zinc-800/20">
                                        <th class="px-5 py-3 font-semibold text-slate-600 dark:text-zinc-400">ID</th>
                                        <th class="px-5 py-3 font-semibold text-slate-600 dark:text-zinc-400">Kategori & Kegiatan</th>
                                        <th class="px-5 py-3 font-semibold text-slate-600 dark:text-zinc-400 text-center">Status</th>
                                        <th class="px-5 py-3 font-semibold text-slate-600 dark:text-zinc-400 text-right">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 dark:divide-zinc-800">
            `;

            records.forEach(r => {
                const status = r.bukti_pembayaran ? 
                    '<span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-400 ring-1 ring-emerald-200 dark:ring-emerald-800">LUNAS</span>' : 
                    '<span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400 ring-1 ring-amber-200 dark:ring-amber-800">PENDING</span>';

                html += `
                    <tr class="hover:bg-slate-50 dark:hover:bg-zinc-800/40 transition-colors">
                        <td class="px-5 py-3 text-slate-500 dark:text-zinc-500 font-mono text-xs">#${r.id}</td>
                        <td class="px-5 py-3">
                            <p class="font-bold text-slate-900 dark:text-white">${r.category_name}</p>
                            <p class="text-[10px] text-slate-500 dark:text-zinc-400">${r.nama_kegiatan}</p>
                        </td>
                        <td class="px-5 py-3 text-center">${status}</td>
                        <td class="px-5 py-3 text-right space-x-1">
                            <button onclick='editPeserta(${JSON.stringify(r).replace(/'/g, "&apos;")})' class="p-2 rounded-lg text-amber-600 dark:text-amber-400 hover:bg-amber-50 dark:hover:bg-amber-900/30 transition-colors"><i class="fas fa-edit"></i></button>
                            <button onclick="confirmDelete(${r.id}, '${r.nama_peserta.replace(/'/g, "\\'")}')" class="p-2 rounded-lg text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/30 transition-colors text-xs font-bold ring-1 ring-red-200 dark:ring-red-800">HAPUS</button>
                        </td>
                    </tr>
                `;
            });

            html += `</tbody></table></div></div></div>`;
            detailContent.innerHTML = html;
            document.getElementById('detailModal').classList.remove('hidden');
        }

        function closeDetailModal() { document.getElementById('detailModal').classList.add('hidden'); }

        function editPeserta(data) {
            document.getElementById('edit_id').value = data.id;
            document.getElementById('edit_nama_peserta').value = data.nama_peserta || '';
            document.getElementById('edit_category_id').value = data.category_id || '';
            document.getElementById('edit_kegiatan_id').value = data.kegiatan_id || '';
            document.getElementById('edit_tanggal_lahir').value = data.tanggal_lahir || '';
            document.getElementById('edit_jenis_kelamin').value = data.jenis_kelamin || '';
            document.getElementById('edit_asal_kota').value = data.asal_kota || '';
            document.getElementById('edit_nama_club').value = data.nama_club || '';
            document.getElementById('edit_nomor_hp').value = data.nomor_hp || '';
            closeDetailModal();
            document.getElementById('editModal').classList.remove('hidden');
        }

        function closeEditModal() { document.getElementById('editModal').classList.add('hidden'); }

        function confirmDelete(id, name) {
            document.getElementById('deleteIdInput').value = id;
            document.getElementById('deletePesertaName').textContent = name;
            closeDetailModal();
            document.getElementById('deleteModal').classList.remove('hidden');
        }

        function closeDeleteModal() { document.getElementById('deleteModal').classList.add('hidden'); }

        function showImage(type, url, name) {
            const body = document.getElementById('imageModalBody');
            const label = document.getElementById('imageModalLabel');
            const download = document.getElementById('downloadImage');
            label.textContent = type === 'payment' ? 'Bukti Pembayaran: ' + name : 'Profile: ' + name;
            download.href = url;
            if (url.toLowerCase().endsWith('.pdf')) {
                body.innerHTML = `<iframe src="${url}" class="w-full h-[60vh] rounded-lg"></iframe>`;
            } else {
                body.innerHTML = `<img src="${url}" class="max-w-full max-h-[60vh] mx-auto rounded-lg shadow-lg border border-slate-200 dark:border-zinc-800">`;
            }
            document.getElementById('imageModal').classList.remove('hidden');
        }

        function closeImageModal() { document.getElementById('imageModal').classList.add('hidden'); }

        function dismissToast() {
            const toast = document.getElementById('toast');
            if (toast) {
                toast.style.opacity = '0';
                toast.style.transform = 'translateY(-20px)';
                toast.style.transition = 'all 0.3s ease';
                setTimeout(() => toast.remove(), 300);
            }
        }
        
        setTimeout(dismissToast, 5000);

        window.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                closeAddModal();
                closeDetailModal();
                closeEditModal();
                closeDeleteModal();
                closeImageModal();
            }
        });

        document.addEventListener('DOMContentLoaded', function() {
            const filterForm = document.getElementById('filterForm');
            if (filterForm) {
                filterForm.querySelectorAll('select').forEach(s => {
                    s.addEventListener('change', () => filterForm.submit());
                });
            }

            // Handle auto-open Add Participant modal via URL parameters
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('add_peserta') === '1') {
                const kegiatanId = urlParams.get('kegiatan_id');
                openAddModal();
                if (kegiatanId) {
                    const kegiatanSelect = document.getElementById('add_kegiatan_id');
                    if (kegiatanSelect) {
                        kegiatanSelect.value = kegiatanId;
                        loadKegiatanCategories();
                    }
                }
            }
        });


        <?= getThemeToggleScript() ?>
        <?= getUiScripts() ?>
    </script>
</body>
</html>
<?php skip_post: ?>
