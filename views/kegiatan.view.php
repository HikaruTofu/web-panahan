<?php
include '../config/panggil.php';
include '../includes/check_access.php';
requireLogin();

// Ambil semua kategori
$kategoriResult = $conn->query("SELECT id, name, min_age, max_age FROM categories ORDER BY min_age ASC");
$kategoriList = [];
while ($row = $kategoriResult->fetch_assoc()) {
    $kategoriList[] = $row;
}

// Proses hapus data
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $kegiatanId = intval($_GET['id']);

    // Hapus dari kegiatan_kategori dulu (foreign key)
    $conn->query("DELETE FROM kegiatan_kategori WHERE kegiatan_id = $kegiatanId");

    // Kemudian hapus dari kegiatan
    $conn->query("DELETE FROM kegiatan WHERE id = $kegiatanId");

    header("Location: kegiatan.view.php");
    exit;
}

// Proses tambah/edit data
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nama = $_POST['namaKegiatan'];
    $kategoriDipilih = isset($_POST['kategori']) ? $_POST['kategori'] : [];
    $editId = isset($_POST['editId']) ? intval($_POST['editId']) : null;

    if ($editId) {
        // Update data existing
        $stmt = $conn->prepare("UPDATE kegiatan SET nama_kegiatan = ? WHERE id = ?");
        $stmt->bind_param("si", $nama, $editId);
        $stmt->execute();
        $stmt->close();

        // Hapus kategori lama
        $conn->query("DELETE FROM kegiatan_kategori WHERE kegiatan_id = $editId");

        $kegiatanId = $editId;
    } else {
        // Insert data baru
        $stmt = $conn->prepare("INSERT INTO kegiatan (nama_kegiatan) VALUES (?)");
        $stmt->bind_param("s", $nama);
        $stmt->execute();
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

    // redirect setelah simpan
    header("Location: kegiatan.view.php");
    exit;
}

// Ambil data kegiatan dengan kategorinya
$query = "
    SELECT
        k.id,
        k.nama_kegiatan AS kegiatan_nama,
        GROUP_CONCAT(kk.category_id) AS category_ids,
        GROUP_CONCAT(c.name SEPARATOR '|') AS category_names
    FROM kegiatan k
    LEFT JOIN kegiatan_kategori kk ON k.id = kk.kegiatan_id
    LEFT JOIN categories c ON kk.category_id = c.id
    GROUP BY k.id, k.nama_kegiatan
    ORDER BY k.id DESC
";

$result = $conn->query($query);
$kegiatanData = [];
while ($row = $result->fetch_assoc()) {
    $categoryIds = $row['category_ids'] ? explode(',', $row['category_ids']) : [];
    $categoryNames = $row['category_names'] ? explode('|', $row['category_names']) : [];

    $kegiatanData[] = [
        'id' => $row['id'],
        'nama' => $row['kegiatan_nama'],
        'category_ids' => array_map('intval', $categoryIds),
        'category_names' => $categoryNames
    ];
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
    <title>Data Kegiatan - Turnamen Panahan</title>
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
                                <i class="fas fa-calendar text-archery-600"></i>
                            </div>
                            <div>
                                <h1 class="text-xl font-bold text-slate-900">Data Kegiatan</h1>
                                <p class="text-sm text-slate-500">Kelola turnamen dan kegiatan</p>
                            </div>
                        </div>
                        <button onclick="openModal()" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-archery-600 text-white text-sm font-medium hover:bg-archery-700 transition-colors">
                            <i class="fas fa-plus"></i>
                            <span class="hidden sm:inline">Tambah Kegiatan</span>
                        </button>
                    </div>
                </div>
            </header>

            <div class="px-6 lg:px-8 py-6">
                <!-- Search Bar -->
                <div class="flex flex-col sm:flex-row gap-4 mb-6">
                    <div class="relative flex-1">
                        <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"></i>
                        <input type="text" id="searchInput" onkeyup="searchData()"
                               class="w-full pl-10 pr-4 py-2 rounded-lg border border-slate-300 text-sm focus:ring-2 focus:ring-archery-500 focus:border-archery-500"
                               placeholder="Cari kegiatan...">
                    </div>
                    <a href="dashboard.php" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg border border-slate-300 text-slate-700 text-sm font-medium hover:bg-slate-50 transition-colors">
                        <i class="fas fa-arrow-left"></i>
                        <span>Dashboard</span>
                    </a>
                </div>

                <!-- Desktop Table -->
                <div class="hidden md:block bg-white rounded-xl border border-slate-200 overflow-hidden">
                    <div class="overflow-x-auto custom-scrollbar">
                        <table class="w-full">
                            <thead class="bg-zinc-800 text-white sticky top-0 z-10">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider w-16">#</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider">Nama Kegiatan</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider">Kategori</th>
                                    <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider w-64">Aksi</th>
                                </tr>
                            </thead>
                            <tbody id="tableBody" class="divide-y divide-slate-100">
                                <?php if (empty($kegiatanData)): ?>
                                    <tr>
                                        <td colspan="4" class="px-4 py-12">
                                            <div class="flex flex-col items-center text-center">
                                                <div class="w-16 h-16 rounded-full bg-slate-100 flex items-center justify-center mb-3">
                                                    <i class="fas fa-calendar-times text-slate-400 text-2xl"></i>
                                                </div>
                                                <p class="text-slate-500 font-medium">Tidak ada data kegiatan</p>
                                                <p class="text-slate-400 text-sm mb-4">Silakan tambahkan kegiatan baru</p>
                                                <button onclick="openModal()" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-archery-600 text-white text-sm font-medium hover:bg-archery-700 transition-colors">
                                                    <i class="fas fa-plus"></i> Tambah Kegiatan
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($kegiatanData as $index => $item): ?>
                                        <tr class="hover:bg-slate-50 transition-colors">
                                            <td class="px-4 py-3">
                                                <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-archery-100 text-archery-700 text-sm font-semibold">
                                                    <?= $index + 1 ?>
                                                </span>
                                            </td>
                                            <td class="px-4 py-3">
                                                <p class="font-medium text-slate-900"><?= htmlspecialchars($item['nama']) ?></p>
                                            </td>
                                            <td class="px-4 py-3">
                                                <?php if (empty($item['category_names'])): ?>
                                                    <span class="text-slate-400 italic text-sm">Belum ada kategori</span>
                                                <?php else: ?>
                                                    <div class="flex flex-wrap gap-1">
                                                        <?php foreach ($item['category_names'] as $categoryName): ?>
                                                            <span class="px-2 py-1 rounded-full text-xs font-medium bg-archery-100 text-archery-700"><?= htmlspecialchars($categoryName) ?></span>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-4 py-3">
                                                <div class="flex items-center justify-center gap-2">
                                                    <a href="pendaftaran.php?id=<?php echo $item['id']?>" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-purple-100 text-purple-700 text-xs font-medium hover:bg-purple-200 transition-colors">
                                                        <i class="fas fa-user-plus"></i> Daftar
                                                    </a>
                                                    <a href="detail.php?id=<?php echo $item['id']?>" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-blue-100 text-blue-700 text-xs font-medium hover:bg-blue-200 transition-colors">
                                                        <i class="fas fa-eye"></i> Detail
                                                    </a>
                                                    <button onclick="editData(<?= $item['id'] ?>)" class="p-2 rounded-lg text-amber-600 hover:bg-amber-50 transition-colors" title="Edit">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button onclick="deleteData(<?= $item['id'] ?>)" class="p-2 rounded-lg text-red-600 hover:bg-red-50 transition-colors" title="Hapus">
                                                        <i class="fas fa-trash"></i>
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
                    <div class="px-4 py-3 bg-slate-50 border-t border-slate-200">
                        <p class="text-sm text-slate-500">Menampilkan <?= count($kegiatanData) ?> kegiatan</p>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Mobile Cards -->
                <div id="mobileCards" class="md:hidden space-y-4">
                    <?php if (empty($kegiatanData)): ?>
                        <div class="bg-white rounded-xl border border-slate-200 p-8 text-center">
                            <div class="w-16 h-16 rounded-full bg-slate-100 flex items-center justify-center mx-auto mb-3">
                                <i class="fas fa-calendar-times text-slate-400 text-2xl"></i>
                            </div>
                            <p class="text-slate-500 font-medium mb-3">Tidak ada data kegiatan</p>
                            <button onclick="openModal()" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-archery-600 text-white text-sm font-medium">
                                <i class="fas fa-plus"></i> Tambah Kegiatan
                            </button>
                        </div>
                    <?php else: ?>
                        <?php foreach ($kegiatanData as $index => $item): ?>
                            <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-4 border-l-4 border-l-archery-500" data-id="<?= $item['id'] ?>">
                                <div class="flex items-start gap-3 mb-3 pb-3 border-b border-slate-100">
                                    <div class="w-8 h-8 rounded-full bg-archery-600 text-white flex items-center justify-center text-sm font-bold flex-shrink-0">
                                        <?= $index + 1 ?>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="font-semibold text-slate-900"><?= htmlspecialchars($item['nama']) ?></p>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <p class="text-xs text-slate-400 uppercase tracking-wide mb-2">Kategori</p>
                                    <?php if (empty($item['category_names'])): ?>
                                        <span class="text-slate-400 italic text-sm">Belum ada kategori</span>
                                    <?php else: ?>
                                        <div class="flex flex-wrap gap-1">
                                            <?php foreach ($item['category_names'] as $categoryName): ?>
                                                <span class="px-2 py-1 rounded-full text-xs font-medium bg-archery-100 text-archery-700"><?= htmlspecialchars($categoryName) ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="grid grid-cols-2 gap-2">
                                    <a href="pendaftaran.php?id=<?php echo $item['id']?>" class="inline-flex items-center justify-center gap-1 px-3 py-2 rounded-lg bg-purple-100 text-purple-700 text-xs font-medium">
                                        <i class="fas fa-user-plus"></i> Daftar
                                    </a>
                                    <a href="detail.php?id=<?php echo $item['id']?>" class="inline-flex items-center justify-center gap-1 px-3 py-2 rounded-lg bg-blue-100 text-blue-700 text-xs font-medium">
                                        <i class="fas fa-eye"></i> Detail
                                    </a>
                                    <button onclick="editData(<?= $item['id'] ?>)" class="inline-flex items-center justify-center gap-1 px-3 py-2 rounded-lg bg-amber-100 text-amber-700 text-xs font-medium">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <button onclick="deleteData(<?= $item['id'] ?>)" class="inline-flex items-center justify-center gap-1 px-3 py-2 rounded-lg bg-red-100 text-red-700 text-xs font-medium">
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
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-lg mx-4 max-h-[90vh] overflow-hidden flex flex-col">
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

                    <div>
                        <label for="namaKegiatan" class="block text-sm font-medium text-slate-700 mb-1">
                            Nama Kegiatan <span class="text-red-500">*</span>
                        </label>
                        <input type="text" id="namaKegiatan" name="namaKegiatan" required
                               class="w-full px-4 py-2 rounded-lg border border-slate-300 text-sm focus:ring-2 focus:ring-archery-500 focus:border-archery-500"
                               placeholder="Masukkan nama kegiatan">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-2">
                            Pilih Kategori <span class="text-red-500">*</span>
                        </label>
                        <div class="border border-slate-200 rounded-lg max-h-64 overflow-y-auto custom-scrollbar divide-y divide-slate-100">
                            <?php foreach ($kategoriList as $kategori): ?>
                                <div class="p-3 hover:bg-slate-50 transition-colors cursor-pointer" onclick="toggleCheckbox('kategori_<?= $kategori['id']; ?>')">
                                    <label class="flex items-start gap-3 cursor-pointer">
                                        <input type="checkbox" name="kategori[]" value="<?= $kategori['id']; ?>" id="kategori_<?= $kategori['id']; ?>"
                                               class="mt-1 rounded text-archery-600 focus:ring-archery-500">
                                        <div class="flex-1">
                                            <p class="font-medium text-slate-900"><?= htmlspecialchars($kategori['name']); ?></p>
                                            <p class="text-xs text-slate-500">Lahir <?= date("Y") - $kategori['max_age']; ?> – <?= date("Y") - $kategori['min_age']; ?></p>
                                        </div>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="px-6 py-4 bg-slate-50 border-t border-slate-200 flex gap-3 flex-shrink-0">
                    <button type="button" onclick="closeModal()" class="flex-1 px-4 py-2 rounded-lg border border-slate-300 text-slate-700 text-sm font-medium hover:bg-slate-100 transition-colors">
                        Batal
                    </button>
                    <button type="submit" class="flex-1 px-4 py-2 rounded-lg bg-archery-600 text-white text-sm font-medium hover:bg-archery-700 transition-colors">
                        <i class="fas fa-save mr-1"></i> Simpan
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
            <a href="kegiatan.view.php" class="flex items-center gap-3 px-4 py-3 rounded-lg bg-archery-600/20 text-archery-400">
                <i class="fas fa-calendar w-5"></i><span class="text-sm font-medium">Kegiatan</span>
            </a>
            <a href="peserta.view.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800">
                <i class="fas fa-user-friends w-5"></i><span class="text-sm">Peserta</span>
            </a>
        </nav>
    </div>

<script>
// Data kegiatan dari PHP
const kegiatanData = <?= json_encode($kegiatanData) ?>;
const allData = [...kegiatanData];

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

function closeModal() {
    document.getElementById('myModal').classList.remove('active');
    document.body.style.overflow = '';
}

function editData(id) {
    const item = allData.find(data => data.id == id);
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

function deleteData(id) {
    if (confirm('Yakin ingin menghapus data ini?')) {
        window.location.href = `?action=delete&id=${id}`;
    }
}

function searchData() {
    const searchTerm = document.getElementById('searchInput').value.toLowerCase();

    if (searchTerm === '') {
        displayData(allData);
    } else {
        const filtered = allData.filter(item =>
            item.nama.toLowerCase().includes(searchTerm)
        );
        displayData(filtered);
    }
}

function displayData(data) {
    const tableBody = document.getElementById('tableBody');
    const mobileCards = document.getElementById('mobileCards');

    if (data.length === 0) {
        tableBody.innerHTML = `<tr><td colspan="4" class="px-4 py-12"><div class="flex flex-col items-center text-center"><div class="w-16 h-16 rounded-full bg-slate-100 flex items-center justify-center mb-3"><i class="fas fa-search text-slate-400 text-2xl"></i></div><p class="text-slate-500 font-medium">Tidak ada data yang ditemukan</p></div></td></tr>`;
        mobileCards.innerHTML = `<div class="bg-white rounded-xl border border-slate-200 p-8 text-center"><div class="w-16 h-16 rounded-full bg-slate-100 flex items-center justify-center mx-auto mb-3"><i class="fas fa-search text-slate-400 text-2xl"></i></div><p class="text-slate-500 font-medium">Tidak ada data yang ditemukan</p></div>`;
        return;
    }

    tableBody.innerHTML = data.map((item, index) => {
        let categoryBadges = '';
        if (item.category_names && item.category_names.length > 0) {
            categoryBadges = item.category_names.map(name =>
                `<span class="px-2 py-1 rounded-full text-xs font-medium bg-archery-100 text-archery-700">${name}</span>`
            ).join(' ');
        } else {
            categoryBadges = '<span class="text-slate-400 italic text-sm">Belum ada kategori</span>';
        }

        return `
            <tr class="hover:bg-slate-50 transition-colors">
                <td class="px-4 py-3"><span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-archery-100 text-archery-700 text-sm font-semibold">${index + 1}</span></td>
                <td class="px-4 py-3"><p class="font-medium text-slate-900">${item.nama}</p></td>
                <td class="px-4 py-3"><div class="flex flex-wrap gap-1">${categoryBadges}</div></td>
                <td class="px-4 py-3">
                    <div class="flex items-center justify-center gap-2">
                        <a href="pendaftaran.php?id=${item.id}" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-purple-100 text-purple-700 text-xs font-medium hover:bg-purple-200"><i class="fas fa-user-plus"></i> Daftar</a>
                        <a href="detail.php?id=${item.id}" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-blue-100 text-blue-700 text-xs font-medium hover:bg-blue-200"><i class="fas fa-eye"></i> Detail</a>
                        <button onclick="editData(${item.id})" class="p-2 rounded-lg text-amber-600 hover:bg-amber-50"><i class="fas fa-edit"></i></button>
                        <button onclick="deleteData(${item.id})" class="p-2 rounded-lg text-red-600 hover:bg-red-50"><i class="fas fa-trash"></i></button>
                    </div>
                </td>
            </tr>
        `;
    }).join('');

    mobileCards.innerHTML = data.map((item, index) => {
        let categoryBadges = '';
        if (item.category_names && item.category_names.length > 0) {
            categoryBadges = item.category_names.map(name =>
                `<span class="px-2 py-1 rounded-full text-xs font-medium bg-archery-100 text-archery-700">${name}</span>`
            ).join(' ');
        } else {
            categoryBadges = '<span class="text-slate-400 italic text-sm">Belum ada kategori</span>';
        }

        return `
            <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-4 border-l-4 border-l-archery-500" data-id="${item.id}">
                <div class="flex items-start gap-3 mb-3 pb-3 border-b border-slate-100">
                    <div class="w-8 h-8 rounded-full bg-archery-600 text-white flex items-center justify-center text-sm font-bold flex-shrink-0">${index + 1}</div>
                    <div class="flex-1 min-w-0"><p class="font-semibold text-slate-900">${item.nama}</p></div>
                </div>
                <div class="mb-3">
                    <p class="text-xs text-slate-400 uppercase tracking-wide mb-2">Kategori</p>
                    <div class="flex flex-wrap gap-1">${categoryBadges}</div>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <a href="pendaftaran.php?id=${item.id}" class="inline-flex items-center justify-center gap-1 px-3 py-2 rounded-lg bg-purple-100 text-purple-700 text-xs font-medium"><i class="fas fa-user-plus"></i> Daftar</a>
                    <a href="detail.php?id=${item.id}" class="inline-flex items-center justify-center gap-1 px-3 py-2 rounded-lg bg-blue-100 text-blue-700 text-xs font-medium"><i class="fas fa-eye"></i> Detail</a>
                    <button onclick="editData(${item.id})" class="inline-flex items-center justify-center gap-1 px-3 py-2 rounded-lg bg-amber-100 text-amber-700 text-xs font-medium"><i class="fas fa-edit"></i> Edit</button>
                    <button onclick="deleteData(${item.id})" class="inline-flex items-center justify-center gap-1 px-3 py-2 rounded-lg bg-red-100 text-red-700 text-xs font-medium"><i class="fas fa-trash"></i> Hapus</button>
                </div>
            </div>
        `;
    }).join('');
}

// Form validation
document.getElementById('kegiatanForm').addEventListener('submit', function(e) {
    const selectedCategories = document.querySelectorAll('input[name="kategori[]"]:checked');
    if (selectedCategories.length === 0) {
        e.preventDefault();
        alert('Pilih minimal satu kategori!');
        return false;
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
</script>
</body>
</html>
