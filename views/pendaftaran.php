<?php
/**
 * Form Pendaftaran Peserta - Turnamen Panahan
 * UI: Intentional Minimalism with Tailwind CSS
 */

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start session
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Database connection
try {
    include '../config/panggil.php';
} catch (Exception $e) {
    die("Error koneksi database: " . $e->getMessage());
}

// Handle AJAX request untuk get peserta by club (UNCHANGED)
if (isset($_GET['action']) && $_GET['action'] === 'get_peserta') {
    header('Content-Type: application/json');

    $club = isset($_GET['club']) ? trim($_GET['club']) : '';

    if (empty($club)) {
        echo json_encode([]);
        exit;
    }

    try {
        $query = "
            SELECT
                p.id,
                p.nama_peserta,
                p.tanggal_lahir,
                p.jenis_kelamin,
                p.nomor_hp,
                p.asal_kota,
                p.sekolah,
                p.kelas
            FROM peserta p
            INNER JOIN (
                SELECT
                    nama_peserta,
                    MAX(id) as max_id
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
            $pesertaList[] = [
                'id' => $row['id'],
                'nama_peserta' => $row['nama_peserta'],
                'tanggal_lahir' => $row['tanggal_lahir'],
                'jenis_kelamin' => $row['jenis_kelamin'],
                'nomor_hp' => $row['nomor_hp'] ?? '',
                'asal_kota' => $row['asal_kota'] ?? '',
                'sekolah' => $row['sekolah'] ?? '',
                'kelas' => $row['kelas'] ?? ''
            ];
        }

        $stmt->close();
        echo json_encode($pesertaList);
        exit;

    } catch (Exception $e) {
        echo json_encode(['error' => 'Gagal mengambil data: ' . $e->getMessage()]);
        exit;
    }
}

// Get kegiatan ID (UNCHANGED)
$kegiatan_id = isset($_GET['kegiatan_id']) ? intval($_GET['kegiatan_id']) : null;

if (!$kegiatan_id) {
    try {
        $queryFirstKegiatan = "SELECT id FROM kegiatan WHERE id =".$_GET['id'];
        $resultFirstKegiatan = $conn->query($queryFirstKegiatan);
        if ($resultFirstKegiatan && $resultFirstKegiatan->num_rows > 0) {
            $firstKegiatan = $resultFirstKegiatan->fetch_assoc();
            $kegiatan_id = $firstKegiatan['id'];
        }
    } catch (Exception $e) {
        die("Error mengambil kegiatan: " . $e->getMessage());
    }
}

if (!$kegiatan_id) {
    die("Tidak ada kegiatan yang tersedia. Silakan buat kegiatan terlebih dahulu.");
}

// Ambil data kegiatan dan kategorinya (UNCHANGED)
$kegiatanData = [];
try {
    $query = "
        SELECT
            k.id as kegiatan_id,
            k.nama_kegiatan,
            c.id as category_id,
            c.name as category_name,
            c.min_age,
            c.max_age,
            c.gender
        FROM kegiatan k
        LEFT JOIN kegiatan_kategori kk ON k.id = kk.kegiatan_id
        LEFT JOIN categories c ON kk.category_id = c.id
        WHERE k.id = ? AND c.status = 'active'
        ORDER BY c.min_age ASC, c.name ASC
    ";

    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $kegiatan_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        if (empty($kegiatanData)) {
            $kegiatanData['kegiatan_id'] = $row['kegiatan_id'];
            $kegiatanData['nama_kegiatan'] = $row['nama_kegiatan'];
            $kegiatanData['kategori'] = [];
        }
        if ($row['category_id']) {
            $kegiatanData['kategori'][] = [
                'id' => $row['category_id'],
                'name' => $row['category_name'],
                'min_age' => $row['min_age'],
                'max_age' => $row['max_age'],
                'gender' => $row['gender']
            ];
        }
    }
    $stmt->close();

    if (empty($kegiatanData)) {
        die("Kegiatan tidak ditemukan.");
    }

    if (empty($kegiatanData['kategori'])) {
        die("Kegiatan '{$kegiatanData['nama_kegiatan']}' belum memiliki kategori. Silakan tambahkan kategori terlebih dahulu.");
    }

} catch (Exception $e) {
    die("Error mengambil data kegiatan: " . $e->getMessage());
}

// Ambil data club untuk dropdown (UNCHANGED)
$clubList = [];
try {
    $queryClub = "SELECT DISTINCT nama_club FROM peserta WHERE nama_club IS NOT NULL AND nama_club != '' ORDER BY nama_club ASC";
    $resultClub = $conn->query($queryClub);
    while ($row = $resultClub->fetch_assoc()) {
        $clubList[] = $row['nama_club'];
    }
} catch (Exception $e) {
    die("Error mengambil data club: " . $e->getMessage());
}

// Proses insert data (UNCHANGED)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nama_peserta = trim($_POST['nama_peserta']);
    $tanggal_lahir = $_POST['tanggal_lahir'];
    $jenis_kelamin = $_POST['jenis_kelamin'];
    $asal_kota = trim($_POST['asal_kota']);

    $nama_club = trim($_POST['nama_club']);
    if ($nama_club === 'CLUB_BARU' && !empty($_POST['club_baru'])) {
        $nama_club = trim($_POST['club_baru']);
    }

    $sekolah = trim($_POST['sekolah']);
    $kelas = trim($_POST['kelas']);
    $nomor_hp = trim($_POST['nomor_hp']);
    $category_ids = isset($_POST['category_ids']) ? $_POST['category_ids'] : [];

    $peserta_id_existing = isset($_POST['peserta_id_existing']) ? intval($_POST['peserta_id_existing']) : 0;
    $is_new_peserta = ($peserta_id_existing == 0);

    $errors = [];

    if (empty($nama_club)) {
        $errors[] = "Nama club wajib dipilih";
    }

    if (empty($nama_peserta)) {
        $errors[] = "Nama peserta wajib diisi";
    }

    if (empty($tanggal_lahir)) {
        $errors[] = "Tanggal lahir wajib diisi";
    }

    if (empty($jenis_kelamin)) {
        $errors[] = "Jenis kelamin wajib dipilih";
    }

    if (empty($nomor_hp)) {
        $errors[] = "Nomor HP wajib diisi";
    }

    if (empty($category_ids)) {
        $errors[] = "Minimal pilih satu kategori";
    }

    // Validasi upload file (UNCHANGED)
    $bukti_pembayaran = '';
    if (isset($_FILES['bukti_pembayaran']) && $_FILES['bukti_pembayaran']['error'] === UPLOAD_ERR_OK) {
        $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'application/pdf'];
        $max_size = 5 * 1024 * 1024;

        $file_type = $_FILES['bukti_pembayaran']['type'];
        $file_size = $_FILES['bukti_pembayaran']['size'];
        $file_tmp = $_FILES['bukti_pembayaran']['tmp_name'];
        $file_name = $_FILES['bukti_pembayaran']['name'];

        if (!in_array($file_type, $allowed_types)) {
            $errors[] = "Tipe file tidak diizinkan. Hanya JPG, PNG, GIF, dan PDF yang diperbolehkan";
        }

        if ($file_size > $max_size) {
            $errors[] = "Ukuran file terlalu besar. Maksimal 5MB";
        }

        if (empty($errors)) {
            $upload_dir = '../assets/uploads/pembayaran/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }

            $file_extension = pathinfo($file_name, PATHINFO_EXTENSION);
            $unique_name = date('YmdHis') . '_' . uniqid() . '.' . $file_extension;
            $upload_path = $upload_dir . $unique_name;

            if (move_uploaded_file($file_tmp, $upload_path)) {
                $bukti_pembayaran = $unique_name;
            } else {
                $errors[] = "Gagal mengupload file bukti pembayaran";
            }
        }
    }

    // Validasi umur dan gender (UNCHANGED)
    if (!empty($tanggal_lahir) && !empty($category_ids) && !empty($jenis_kelamin)) {
        $birth_date = new DateTime($tanggal_lahir);
        $current_date = new DateTime();
        $age = $current_date->diff($birth_date)->y;

        $invalidCategories = [];
        foreach ($category_ids as $category_id) {
            foreach ($kegiatanData['kategori'] as $kategori) {
                if ($kategori['id'] == $category_id) {
                    if ($age < $kategori['min_age'] || $age > $kategori['max_age']) {
                        $invalidCategories[] = $kategori['name'] . " (umur {$kategori['min_age']}-{$kategori['max_age']} tahun)";
                    }

                    if ($kategori['gender'] !== 'Campuran' && $kategori['gender'] !== $jenis_kelamin) {
                        $invalidCategories[] = $kategori['name'] . " (khusus {$kategori['gender']})";
                    }

                    break;
                }
            }
        }

        if (!empty($invalidCategories)) {
            $errors[] = "Kategori tidak sesuai: " . implode(', ', $invalidCategories);
        }
    }

    if (empty($errors)) {
        try {
            $successCount = 0;
            $selectedCategoryNames = [];

            foreach ($category_ids as $category_id) {
                foreach ($kegiatanData['kategori'] as $kategori) {
                    if ($kategori['id'] == $category_id) {
                        $selectedCategoryNames[] = $kategori['name'];
                        break;
                    }
                }

                $checkStmt = $conn->prepare("SELECT id FROM peserta WHERE nama_peserta = ? AND category_id = ? AND kegiatan_id = ? LIMIT 1");
                $checkStmt->bind_param("sii", $nama_peserta, $category_id, $kegiatan_id);
                $checkStmt->execute();
                $checkResult = $checkStmt->get_result();

                if ($checkResult->num_rows > 0) {
                    $checkStmt->close();
                    continue;
                }
                $checkStmt->close();

                if (!$is_new_peserta && $peserta_id_existing > 0) {
                    $getPesertaStmt = $conn->prepare("SELECT tanggal_lahir, jenis_kelamin, asal_kota, nama_club, sekolah, kelas, nomor_hp FROM peserta WHERE id = ? LIMIT 1");
                    $getPesertaStmt->bind_param("i", $peserta_id_existing);
                    $getPesertaStmt->execute();
                    $pesertaResult = $getPesertaStmt->get_result();

                    if ($pesertaResult->num_rows > 0) {
                        $existingData = $pesertaResult->fetch_assoc();
                        $tanggal_lahir = $existingData['tanggal_lahir'];
                        $jenis_kelamin = $existingData['jenis_kelamin'];
                        $asal_kota = $existingData['asal_kota'];
                        $nama_club = $existingData['nama_club'];
                        $sekolah = $existingData['sekolah'];
                        $kelas = $existingData['kelas'];
                        $nomor_hp = $existingData['nomor_hp'];
                    }
                    $getPesertaStmt->close();
                }

                $stmt = $conn->prepare("INSERT INTO peserta (nama_peserta, tanggal_lahir, jenis_kelamin, asal_kota, nama_club, sekolah, kelas, nomor_hp, bukti_pembayaran, category_id, kegiatan_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

                $stmt->bind_param("sssssssssii",
                    $nama_peserta,
                    $tanggal_lahir,
                    $jenis_kelamin,
                    $asal_kota,
                    $nama_club,
                    $sekolah,
                    $kelas,
                    $nomor_hp,
                    $bukti_pembayaran,
                    $category_id,
                    $kegiatan_id
                );

                if ($stmt->execute()) {
                    $successCount++;
                }

                $stmt->close();
            }

            if ($successCount > 0) {
                $categoryList = implode(', ', $selectedCategoryNames);
                $pesertaType = $is_new_peserta ? "Peserta baru" : "Peserta existing";
                $_SESSION['success'] = "{$pesertaType} '{$nama_peserta}' berhasil didaftarkan untuk {$successCount} kategori ({$categoryList}) pada kegiatan " . $kegiatanData['nama_kegiatan'] . "!";
                header("Location: " . $_SERVER['PHP_SELF'] . "?kegiatan_id=" . $kegiatan_id);
                exit;
            } else {
                $errors[] = "Semua kategori yang dipilih sudah terdaftar sebelumnya";
            }

        } catch (Exception $e) {
            $errors[] = "Error: " . $e->getMessage();
        }
    }

    $_SESSION['errors'] = $errors;
}
?>
<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pendaftaran Peserta - <?= htmlspecialchars($kegiatanData['nama_kegiatan']) ?></title>
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
        .checkbox-item.disabled { opacity: 0.5; pointer-events: none; }
    </style>
</head>
<body class="min-h-full bg-gradient-to-br from-archery-600 via-archery-700 to-emerald-800">
    <div class="min-h-screen py-8 px-4 sm:px-6 lg:px-8">
        <div class="max-w-3xl mx-auto">
            <!-- Header Card -->
            <div class="bg-white/10 backdrop-blur-lg rounded-t-2xl px-6 py-8 text-center text-white">
                <div class="w-16 h-16 rounded-full bg-white/20 flex items-center justify-center mx-auto mb-4">
                    <i class="fas fa-bullseye text-3xl"></i>
                </div>
                <h1 class="text-2xl sm:text-3xl font-bold mb-2">Pendaftaran Peserta</h1>
                <p class="text-white/80 mb-4">Lengkapi data diri Anda dengan benar</p>
                <div class="inline-block bg-white/20 rounded-lg px-4 py-2">
                    <p class="font-semibold"><?= htmlspecialchars($kegiatanData['nama_kegiatan']) ?></p>
                    <p class="text-sm text-white/70"><?= count($kegiatanData['kategori']) ?> kategori tersedia</p>
                </div>
            </div>

            <!-- Main Form Container -->
            <div class="bg-white rounded-b-2xl shadow-xl">
                <div class="p-6 sm:p-8">
                    <!-- Back Link -->
                    <a href="kegiatan.view.php" class="inline-flex items-center gap-2 text-archery-600 hover:text-archery-700 font-medium text-sm mb-6">
                        <i class="fas fa-arrow-left"></i> Kembali Ke Kegiatan
                    </a>

                    <!-- Success Message -->
                    <?php if (isset($_SESSION['success'])): ?>
                        <div class="mb-6 flex items-start gap-3 px-4 py-3 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-800">
                            <i class="fas fa-check-circle text-emerald-500 mt-0.5"></i>
                            <div>
                                <p class="text-sm font-medium"><?= $_SESSION['success']; ?></p>
                            </div>
                            <button onclick="this.parentElement.remove()" class="ml-auto text-emerald-500 hover:text-emerald-700">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        <?php unset($_SESSION['success']); ?>
                    <?php endif; ?>

                    <!-- Error Messages -->
                    <?php if (isset($_SESSION['errors']) && !empty($_SESSION['errors'])): ?>
                        <div class="mb-6 px-4 py-3 rounded-lg bg-red-50 border border-red-200 text-red-800">
                            <div class="flex items-start gap-3">
                                <i class="fas fa-exclamation-circle text-red-500 mt-0.5"></i>
                                <div>
                                    <p class="text-sm font-medium mb-1">Terdapat kesalahan:</p>
                                    <ul class="text-sm list-disc list-inside space-y-1">
                                        <?php foreach ($_SESSION['errors'] as $error): ?>
                                            <li><?= $error; ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            </div>
                        </div>
                        <?php unset($_SESSION['errors']); ?>
                    <?php endif; ?>

                    <!-- Instructions -->
                    <div class="mb-6 p-4 rounded-lg bg-amber-50 border border-amber-200">
                        <p class="text-sm text-amber-800">
                            <i class="fas fa-info-circle mr-2"></i>
                            <strong>Petunjuk:</strong> Pilih club terlebih dahulu, lalu pilih peserta existing atau tambah peserta baru.
                        </p>
                    </div>

                    <!-- Existing Peserta Info -->
                    <div id="existing-peserta-info" class="hidden mb-6 p-4 rounded-lg bg-cyan-50 border border-cyan-200">
                        <p class="text-sm text-cyan-800">
                            <i class="fas fa-user-check mr-2"></i>
                            <strong>Info:</strong> Anda memilih peserta existing. Data peserta akan menggunakan data yang sudah ada.
                        </p>
                    </div>

                    <!-- FORM: method=POST, action="", enctype=multipart/form-data (UNCHANGED) -->
                    <form method="POST" action="" enctype="multipart/form-data">
                        <!-- HIDDEN: name="kegiatan_id" (UNCHANGED) -->
                        <input type="hidden" name="kegiatan_id" value="<?= $kegiatan_id ?>">
                        <!-- HIDDEN: name="peserta_id_existing" (UNCHANGED) -->
                        <input type="hidden" id="peserta_id_existing" name="peserta_id_existing" value="0">
                        <!-- HIDDEN: name="nama_peserta" (UNCHANGED) -->
                        <input type="hidden" id="nama_peserta" name="nama_peserta" required>

                        <div class="space-y-6">
                            <!-- Club Selection -->
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-2">
                                    Nama Club <span class="text-red-500">*</span>
                                </label>
                                <!-- SELECT: name="nama_club" (UNCHANGED) -->
                                <select id="nama_club" name="nama_club"
                                        class="w-full px-4 py-3 rounded-lg border border-slate-300 text-sm focus:ring-2 focus:ring-archery-500 focus:border-archery-500 transition-colors"
                                        onchange="loadPesertaByClub()" required>
                                    <option value="">-- Pilih Club --</option>
                                    <?php foreach ($clubList as $club): ?>
                                        <option value="<?= htmlspecialchars($club) ?>"><?= htmlspecialchars($club) ?></option>
                                    <?php endforeach; ?>
                                    <option value="CLUB_BARU">+ Tambah Club Baru</option>
                                </select>

                                <!-- INPUT: name="club_baru" (UNCHANGED) -->
                                <input type="text" id="club_baru" name="club_baru"
                                       class="hidden w-full mt-3 px-4 py-3 rounded-lg border border-slate-300 text-sm focus:ring-2 focus:ring-archery-500 focus:border-archery-500"
                                       placeholder="Masukkan nama club baru">
                            </div>

                            <!-- Peserta Selection -->
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-2">
                                    Nama Peserta <span class="text-red-500">*</span>
                                </label>
                                <select id="nama_peserta_select"
                                        class="w-full px-4 py-3 rounded-lg border border-slate-300 text-sm focus:ring-2 focus:ring-archery-500 focus:border-archery-500 transition-colors disabled:bg-slate-100 disabled:text-slate-500"
                                        onchange="loadPesertaData()" disabled>
                                    <option value="">-- Pilih club terlebih dahulu --</option>
                                </select>

                                <input type="text" id="nama_peserta_manual"
                                       class="hidden w-full mt-3 px-4 py-3 rounded-lg border border-slate-300 text-sm focus:ring-2 focus:ring-archery-500 focus:border-archery-500"
                                       placeholder="Masukkan nama peserta baru">
                            </div>

                            <!-- Two Column Grid -->
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                                <!-- Tanggal Lahir -->
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-2">
                                        Tanggal Lahir <span class="text-red-500">*</span>
                                    </label>
                                    <!-- INPUT: name="tanggal_lahir" (UNCHANGED) -->
                                    <input type="date" id="tanggal_lahir" name="tanggal_lahir"
                                           class="w-full px-4 py-3 rounded-lg border border-slate-300 text-sm focus:ring-2 focus:ring-archery-500 focus:border-archery-500"
                                           onchange="updateKategoriOptions()" required>
                                </div>

                                <!-- Jenis Kelamin -->
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-2">
                                        Jenis Kelamin <span class="text-red-500">*</span>
                                    </label>
                                    <div class="flex gap-4 mt-1">
                                        <!-- INPUT: name="jenis_kelamin" (UNCHANGED) -->
                                        <label class="flex items-center gap-2 px-4 py-3 rounded-lg border border-slate-300 cursor-pointer hover:bg-slate-50 transition-colors flex-1">
                                            <input type="radio" id="laki_laki" name="jenis_kelamin" value="Laki-laki"
                                                   class="text-archery-600 focus:ring-archery-500"
                                                   onchange="updateKategoriOptions()" required>
                                            <i class="fas fa-mars text-blue-500"></i>
                                            <span class="text-sm">Laki-laki</span>
                                        </label>
                                        <label class="flex items-center gap-2 px-4 py-3 rounded-lg border border-slate-300 cursor-pointer hover:bg-slate-50 transition-colors flex-1">
                                            <input type="radio" id="perempuan" name="jenis_kelamin" value="Perempuan"
                                                   class="text-archery-600 focus:ring-archery-500"
                                                   onchange="updateKategoriOptions()" required>
                                            <i class="fas fa-venus text-pink-500"></i>
                                            <span class="text-sm">Perempuan</span>
                                        </label>
                                    </div>
                                </div>

                                <!-- Nomor HP -->
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-2">
                                        Nomor HP <span class="text-red-500">*</span>
                                    </label>
                                    <!-- INPUT: name="nomor_hp" (UNCHANGED) -->
                                    <input type="tel" id="nomor_hp" name="nomor_hp"
                                           class="w-full px-4 py-3 rounded-lg border border-slate-300 text-sm focus:ring-2 focus:ring-archery-500 focus:border-archery-500"
                                           placeholder="08xxxxxxxxxx" required>
                                </div>

                                <!-- Asal Kota -->
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-2">Asal Kota</label>
                                    <!-- INPUT: name="asal_kota" (UNCHANGED) -->
                                    <input type="text" id="asal_kota" name="asal_kota"
                                           class="w-full px-4 py-3 rounded-lg border border-slate-300 text-sm focus:ring-2 focus:ring-archery-500 focus:border-archery-500"
                                           placeholder="Kota asal peserta">
                                </div>

                                <!-- Sekolah -->
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-2">Sekolah</label>
                                    <!-- INPUT: name="sekolah" (UNCHANGED) -->
                                    <input type="text" id="sekolah" name="sekolah"
                                           class="w-full px-4 py-3 rounded-lg border border-slate-300 text-sm focus:ring-2 focus:ring-archery-500 focus:border-archery-500"
                                           placeholder="Nama sekolah">
                                </div>

                                <!-- Kelas -->
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-2">Kelas</label>
                                    <!-- INPUT: name="kelas" (UNCHANGED) -->
                                    <input type="text" id="kelas" name="kelas"
                                           class="w-full px-4 py-3 rounded-lg border border-slate-300 text-sm focus:ring-2 focus:ring-archery-500 focus:border-archery-500"
                                           placeholder="Contoh: XII IPA 1">
                                </div>
                            </div>

                            <!-- Bukti Pembayaran -->
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-2">Bukti Pembayaran</label>
                                <div class="relative">
                                    <div id="file-drop-zone"
                                         class="border-2 border-dashed border-slate-300 rounded-lg p-6 text-center hover:border-archery-500 hover:bg-archery-50 transition-colors cursor-pointer"
                                         onclick="document.getElementById('bukti_pembayaran').click()">
                                        <!-- INPUT: name="bukti_pembayaran" (UNCHANGED) -->
                                        <input type="file" id="bukti_pembayaran" name="bukti_pembayaran"
                                               accept=".jpg,.jpeg,.png,.gif,.pdf"
                                               onchange="previewFile()" class="hidden">
                                        <i class="fas fa-cloud-upload-alt text-3xl text-slate-400 mb-2"></i>
                                        <p id="file-text" class="text-sm text-slate-600">Klik untuk memilih file bukti pembayaran</p>
                                        <p class="text-xs text-slate-400 mt-1">JPG, PNG, GIF, PDF (Max: 5MB)</p>
                                    </div>
                                    <div id="file-preview" class="hidden mt-3 p-3 rounded-lg bg-slate-50 border border-slate-200">
                                        <div class="flex items-center gap-3">
                                            <i class="fas fa-file text-archery-600"></i>
                                            <div class="flex-1 text-sm" id="file-info"></div>
                                            <button type="button" onclick="clearFile()" class="text-red-500 hover:text-red-700">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Kategori Selection -->
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-2">
                                    Kategori yang Diikuti <span class="text-red-500">*</span>
                                </label>
                                <div class="border border-slate-200 rounded-lg overflow-hidden">
                                    <div id="kategori-group" class="max-h-64 overflow-y-auto custom-scrollbar divide-y divide-slate-100">
                                        <?php foreach ($kegiatanData['kategori'] as $kategori): ?>
                                            <div class="checkbox-item p-4 hover:bg-slate-50 transition-colors"
                                                 data-min-age="<?= $kategori['min_age'] ?>"
                                                 data-max-age="<?= $kategori['max_age'] ?>"
                                                 data-gender="<?= $kategori['gender'] ?>">
                                                <label class="flex items-start gap-3 cursor-pointer">
                                                    <!-- INPUT: name="category_ids[]" (UNCHANGED) -->
                                                    <input type="checkbox" name="category_ids[]"
                                                           value="<?= $kategori['id'] ?>"
                                                           id="category_<?= $kategori['id'] ?>"
                                                           class="mt-1 rounded text-archery-600 focus:ring-archery-500">
                                                    <div class="flex-1">
                                                        <p class="font-medium text-slate-900"><?= htmlspecialchars($kategori['name']) ?></p>
                                                        <p class="text-xs text-slate-500 mt-0.5">
                                                            Umur: <?= $kategori['min_age'] ?>-<?= $kategori['max_age'] ?> tahun
                                                            (Lahir <?= date("Y") - $kategori['max_age'] ?>–<?= date("Y") - $kategori['min_age'] ?>)
                                                        </p>
                                                        <p class="text-xs text-blue-600 font-medium">
                                                            <?= $kategori['gender'] == 'Campuran' ? 'Putra & Putri' : 'Khusus ' . $kategori['gender'] ?>
                                                        </p>
                                                    </div>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <div id="category-info" class="mt-3 p-3 rounded-lg bg-blue-50 border border-blue-200">
                                    <p class="text-sm text-blue-800">
                                        <i class="fas fa-info-circle mr-1"></i>
                                        Pilih tanggal lahir dan jenis kelamin untuk melihat kategori yang sesuai.
                                    </p>
                                </div>
                            </div>

                            <!-- Submit Buttons -->
                            <div class="flex flex-col sm:flex-row gap-3 pt-6 border-t border-slate-200">
                                <button type="button" onclick="resetForm()"
                                        class="flex-1 px-6 py-3 rounded-lg border border-slate-300 text-slate-700 font-medium hover:bg-slate-50 transition-colors">
                                    <i class="fas fa-redo mr-2"></i> Reset
                                </button>
                                <button type="submit"
                                        class="flex-1 px-6 py-3 rounded-lg bg-archery-600 text-white font-medium hover:bg-archery-700 transition-colors">
                                    <i class="fas fa-check mr-2"></i> Daftar Sekarang
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Footer -->
            <div class="text-center mt-6 text-white/60 text-sm">
                <p>Turnamen Panahan Management System</p>
            </div>
        </div>
    </div>

    <script>
        // Load peserta by club (UNCHANGED logic)
        function loadPesertaByClub() {
            const clubSelect = document.getElementById('nama_club');
            const clubBaru = document.getElementById('club_baru');
            const pesertaSelect = document.getElementById('nama_peserta_select');
            const pesertaManual = document.getElementById('nama_peserta_manual');
            const selectedClub = clubSelect.value;

            resetFormData();

            if (selectedClub === 'CLUB_BARU') {
                clubBaru.classList.remove('hidden');
                clubBaru.required = true;
                pesertaSelect.disabled = true;
                pesertaSelect.innerHTML = '<option value="">-- Masukkan nama club baru --</option>';
                pesertaSelect.classList.remove('hidden');
                pesertaManual.classList.add('hidden');
                return;
            } else {
                clubBaru.classList.add('hidden');
                clubBaru.required = false;
                clubBaru.value = '';
                pesertaSelect.classList.remove('hidden');
                pesertaManual.classList.add('hidden');
            }

            if (selectedClub === '') {
                pesertaSelect.disabled = true;
                pesertaSelect.innerHTML = '<option value="">-- Pilih club terlebih dahulu --</option>';
                return;
            }

            pesertaSelect.disabled = true;
            pesertaSelect.innerHTML = '<option value="">Memuat data peserta...</option>';

            fetch('?action=get_peserta&club=' + encodeURIComponent(selectedClub))
                .then(response => response.json())
                .then(data => {
                    pesertaSelect.innerHTML = '<option value="">-- Pilih Nama Peserta --</option>';

                    if (data.length > 0) {
                        data.forEach(peserta => {
                            const option = document.createElement('option');
                            option.value = peserta.id;
                            option.textContent = peserta.nama_peserta;
                            option.dataset.data = JSON.stringify(peserta);
                            pesertaSelect.appendChild(option);
                        });

                        const newOption = document.createElement('option');
                        newOption.value = 'PESERTA_BARU';
                        newOption.textContent = '+ Tambah Peserta Baru';
                        pesertaSelect.appendChild(newOption);

                        pesertaSelect.disabled = false;
                    } else {
                        pesertaSelect.innerHTML = '<option value="PESERTA_BARU">+ Tambah Peserta Baru</option>';
                        pesertaSelect.disabled = false;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    pesertaSelect.innerHTML = '<option value="">Error memuat data</option>';
                });
        }

        // Load peserta data (UNCHANGED logic)
        function loadPesertaData() {
            const pesertaSelect = document.getElementById('nama_peserta_select');
            const pesertaManual = document.getElementById('nama_peserta_manual');
            const pesertaIdExisting = document.getElementById('peserta_id_existing');
            const selectedOption = pesertaSelect.options[pesertaSelect.selectedIndex];
            const existingInfo = document.getElementById('existing-peserta-info');

            if (pesertaSelect.value === 'PESERTA_BARU') {
                pesertaSelect.classList.add('hidden');
                pesertaManual.classList.remove('hidden');
                pesertaManual.required = true;
                pesertaManual.focus();

                pesertaIdExisting.value = '0';
                existingInfo.classList.add('hidden');

                enableManualPesertaInput();
                resetFormData();
                return;
            }

            if (pesertaSelect.value === '') {
                pesertaManual.classList.add('hidden');
                pesertaManual.required = false;
                pesertaIdExisting.value = '0';
                existingInfo.classList.add('hidden');
                resetFormData();
                return;
            }

            pesertaManual.classList.add('hidden');
            pesertaManual.required = false;

            const pesertaData = JSON.parse(selectedOption.dataset.data);

            pesertaIdExisting.value = pesertaData.id;

            document.getElementById('nama_peserta').value = pesertaData.nama_peserta;
            document.getElementById('tanggal_lahir').value = pesertaData.tanggal_lahir;

            const genderRadio = document.querySelector(`input[name="jenis_kelamin"][value="${pesertaData.jenis_kelamin}"]`);
            if (genderRadio) genderRadio.checked = true;

            document.getElementById('nomor_hp').value = pesertaData.nomor_hp || '';
            document.getElementById('asal_kota').value = pesertaData.asal_kota || '';
            document.getElementById('sekolah').value = pesertaData.sekolah || '';
            document.getElementById('kelas').value = pesertaData.kelas || '';

            existingInfo.classList.remove('hidden');

            updateKategoriOptions();
        }

        function enableManualPesertaInput() {
            const fields = ['tanggal_lahir', 'nomor_hp', 'asal_kota', 'sekolah', 'kelas'];
            fields.forEach(fieldId => {
                const field = document.getElementById(fieldId);
                if (field) field.disabled = false;
            });

            document.querySelectorAll('input[name="jenis_kelamin"]').forEach(radio => {
                radio.disabled = false;
            });
        }

        function resetFormData() {
            document.getElementById('nama_peserta').value = '';
            document.getElementById('nama_peserta_manual').value = '';
            document.getElementById('peserta_id_existing').value = '0';
            document.getElementById('tanggal_lahir').value = '';
            document.getElementById('nomor_hp').value = '';
            document.getElementById('asal_kota').value = '';
            document.getElementById('sekolah').value = '';
            document.getElementById('kelas').value = '';

            document.querySelectorAll('input[name="jenis_kelamin"]').forEach(radio => {
                radio.checked = false;
            });

            document.querySelectorAll('input[name="category_ids[]"]').forEach(checkbox => {
                checkbox.checked = false;
            });

            document.getElementById('existing-peserta-info').classList.add('hidden');

            updateKategoriOptions();
        }

        function resetForm() {
            if (confirm('Yakin ingin mengosongkan semua field?')) {
                document.querySelector('form').reset();
                document.getElementById('file-text').textContent = 'Klik untuk memilih file bukti pembayaran';
                document.getElementById('file-preview').classList.add('hidden');
                document.getElementById('club_baru').classList.add('hidden');
                document.getElementById('nama_peserta_manual').classList.add('hidden');
                document.getElementById('nama_peserta_select').classList.remove('hidden');
                document.getElementById('nama_peserta_select').disabled = true;
                document.getElementById('nama_peserta_select').innerHTML = '<option value="">-- Pilih club terlebih dahulu --</option>';
                document.getElementById('peserta_id_existing').value = '0';
                document.getElementById('existing-peserta-info').classList.add('hidden');
                resetFormData();
            }
        }

        function previewFile() {
            const fileInput = document.getElementById('bukti_pembayaran');
            const fileText = document.getElementById('file-text');
            const filePreview = document.getElementById('file-preview');
            const fileInfo = document.getElementById('file-info');

            if (fileInput.files && fileInput.files[0]) {
                const file = fileInput.files[0];
                const fileSize = (file.size / 1024 / 1024).toFixed(2);

                fileText.innerHTML = '<i class="fas fa-check-circle text-archery-600 mr-1"></i>' + file.name;
                fileInfo.innerHTML = `<strong>${file.name}</strong><br><span class="text-slate-500">${fileSize} MB - ${file.type}</span>`;
                filePreview.classList.remove('hidden');
            } else {
                fileText.textContent = 'Klik untuk memilih file bukti pembayaran';
                filePreview.classList.add('hidden');
            }
        }

        function clearFile() {
            document.getElementById('bukti_pembayaran').value = '';
            document.getElementById('file-text').textContent = 'Klik untuk memilih file bukti pembayaran';
            document.getElementById('file-preview').classList.add('hidden');
        }

        // Phone number auto-format
        document.getElementById('nomor_hp').addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            if (value.startsWith('0')) {
                e.target.value = value;
            } else if (value.startsWith('62')) {
                e.target.value = '0' + value.substring(2);
            }
        });

        // Update category options based on age and gender
        function updateKategoriOptions() {
            const tanggalLahir = document.getElementById('tanggal_lahir').value;
            const jenisKelamin = document.querySelector('input[name="jenis_kelamin"]:checked');
            const checkboxItems = document.querySelectorAll('.checkbox-item');
            const categoryInfo = document.getElementById('category-info');

            if (!tanggalLahir || !jenisKelamin) {
                checkboxItems.forEach(item => {
                    item.classList.remove('disabled');
                    const checkbox = item.querySelector('input[type="checkbox"]');
                    checkbox.disabled = false;
                });
                categoryInfo.innerHTML = '<p class="text-sm text-blue-800"><i class="fas fa-info-circle mr-1"></i>Pilih tanggal lahir dan jenis kelamin untuk melihat kategori yang sesuai.</p>';
                return;
            }

            const birthDate = new Date(tanggalLahir);
            const currentDate = new Date();
            const age = Math.floor((currentDate - birthDate) / (365.25 * 24 * 60 * 60 * 1000));
            const selectedGender = jenisKelamin.value;
            let availableCategories = 0;

            checkboxItems.forEach(item => {
                const minAge = parseInt(item.getAttribute('data-min-age'));
                const maxAge = parseInt(item.getAttribute('data-max-age'));
                const categoryGender = item.getAttribute('data-gender');
                const checkbox = item.querySelector('input[type="checkbox"]');

                const ageMatch = age >= minAge && age <= maxAge;
                const genderMatch = categoryGender === 'Campuran' || categoryGender === selectedGender;

                if (ageMatch && genderMatch) {
                    item.classList.remove('disabled');
                    checkbox.disabled = false;
                    availableCategories++;
                } else {
                    item.classList.add('disabled');
                    checkbox.disabled = true;
                    checkbox.checked = false;
                }
            });

            let infoHtml = `<p class="text-sm text-blue-800"><strong>Umur: ${age} tahun | ${selectedGender}</strong><br>`;
            if (availableCategories > 0) {
                infoHtml += `${availableCategories} kategori tersedia untuk Anda</p>`;
            } else {
                infoHtml += `<span class="text-red-600">Tidak ada kategori yang sesuai</span></p>`;
            }
            categoryInfo.innerHTML = infoHtml;
        }

        // Form validation (UNCHANGED)
        document.querySelector('form').addEventListener('submit', function(e) {
            const clubSelect = document.getElementById('nama_club');
            const clubBaru = document.getElementById('club_baru');
            const namaPeserta = document.getElementById('nama_peserta');
            const namaPesertaManual = document.getElementById('nama_peserta_manual');
            const pesertaSelect = document.getElementById('nama_peserta_select');

            if (clubSelect.value === 'CLUB_BARU' && !clubBaru.value.trim()) {
                e.preventDefault();
                alert('Masukkan nama club baru!');
                clubBaru.focus();
                return false;
            }

            if (pesertaSelect.value === 'PESERTA_BARU' || !namaPesertaManual.classList.contains('hidden')) {
                if (!namaPesertaManual.value.trim()) {
                    e.preventDefault();
                    alert('Masukkan nama peserta!');
                    namaPesertaManual.focus();
                    return false;
                }
                namaPeserta.value = namaPesertaManual.value.trim();
            }

            if (!namaPeserta.value.trim()) {
                e.preventDefault();
                alert('Nama peserta harus diisi!');
                return false;
            }

            const checkedCategories = document.querySelectorAll('input[name="category_ids[]"]:checked');
            if (checkedCategories.length === 0) {
                e.preventDefault();
                alert('Pilih minimal satu kategori!');
                return false;
            }
        });

        // Club baru input handler
        document.getElementById('club_baru').addEventListener('input', function() {
            if (this.value.trim()) {
                const pesertaSelect = document.getElementById('nama_peserta_select');
                const pesertaManual = document.getElementById('nama_peserta_manual');

                pesertaSelect.disabled = false;
                pesertaSelect.innerHTML = '<option value="PESERTA_BARU">+ Tambah Peserta Baru</option>';
                pesertaSelect.value = 'PESERTA_BARU';

                pesertaSelect.classList.add('hidden');
                pesertaManual.classList.remove('hidden');
                pesertaManual.required = true;

                enableManualPesertaInput();
            }
        });

        // Manual peserta input handler
        document.getElementById('nama_peserta_manual').addEventListener('input', function() {
            document.getElementById('nama_peserta').value = this.value.trim();
        });

        // Init
        document.addEventListener('DOMContentLoaded', function() {
            const tanggalLahir = document.getElementById('tanggal_lahir').value;
            const jenisKelamin = document.querySelector('input[name="jenis_kelamin"]:checked');
            if (tanggalLahir && jenisKelamin) {
                updateKategoriOptions();
            }
        });
    </script>
</body>
</html>
