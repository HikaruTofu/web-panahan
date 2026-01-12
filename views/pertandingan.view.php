<?php
/**
 * Pertandingan / Bantalan Assignment View
 * UI: Intentional Minimalism with Tailwind CSS (consistent with Dashboard)
 */
require_once __DIR__ . '/../config/panggil.php';
require_once __DIR__ . '/../includes/check_access.php';
require_once __DIR__ . '/../includes/security.php';
requireAdmin();

if (!checkRateLimit('view_load', 60, 60)) {
    header('HTTP/1.1 429 Too Many Requests');
    die('Terlalu banyak permintaan. Silakan coba lagi nanti.');
}

$_GET = cleanInput($_GET);

if (!isset($conn) && !isset($connection)) {
    die("Error: Database connection failed. Please check your panggil.php file.");
}

$db = isset($conn) ? $conn : $connection;

// Handle Excel Export (UNCHANGED)
if (isset($_POST['export_excel'])) {
    if (!checkRateLimit('export_action', 10, 60)) {
        die('Terlalu banyak permintaan ekspor. Silakan coba lagi nanti.');
    }
    verify_csrf();
    $participants = json_decode($_POST['participants'], true);
    $categories = json_decode($_POST['categories'], true);
    $kegiatan = json_decode($_POST['kegiatan'], true);
    $selectedKegiatan = $_POST['selected_kegiatan'] ?? 'all';
    $selectedCategory = $_POST['selected_category'] ?? 'all';
    $isShuffled = $_POST['is_shuffled'] === '1';

    if (!empty($participants) && is_array($participants)) {
        $timestamp = date('Y-m-d_H-i-s');
        $filename = "Turnamen_Panahan_Bantalan_" . $timestamp . ".xlsx";

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');

        echo '<?xml version="1.0"?>
<?mso-application progid="Excel.Sheet"?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:o="urn:schemas-microsoft-com:office:office"
 xmlns:x="urn:schemas-microsoft-com:office:excel"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:html="http://www.w3.org/TR/REC-html40">
 <DocumentProperties xmlns="urn:schemas-microsoft-com:office:office">
  <Author>Turnamen Panahan System</Author>
  <Created>' . date('Y-m-d\TH:i:s\Z') . '</Created>
  <Company>Turnamen Panahan</Company>
  <Version>16.00</Version>
 </DocumentProperties>
 <ExcelWorkbook xmlns="urn:schemas-microsoft-com:office:excel">
  <WindowHeight>8640</WindowHeight>
  <WindowWidth>20250</WindowWidth>
  <WindowTopX>0</WindowTopX>
  <WindowTopY>0</WindowTopY>
  <ProtectStructure>False</ProtectStructure>
  <ProtectWindows>False</ProtectWindows>
 </ExcelWorkbook>
 <Styles>
  <Style ss:ID="Default" ss:Name="Normal">
   <Alignment ss:Vertical="Bottom"/>
   <Borders/>
   <Font ss:FontName="Calibri" x:Family="Swiss" ss:Size="11" ss:Color="#000000"/>
   <Interior/>
   <NumberFormat/>
   <Protection/>
  </Style>
  <Style ss:ID="Header">
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
   <Font ss:FontName="Calibri" x:Family="Swiss" ss:Size="12" ss:Color="#FFFFFF" ss:Bold="1"/>
   <Interior ss:Color="#4472C4" ss:Pattern="Solid"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
   </Borders>
  </Style>
  <Style ss:ID="Data">
   <Alignment ss:Vertical="Center"/>
   <Font ss:FontName="Calibri" x:Family="Swiss" ss:Size="11"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#D1D1D1"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#D1D1D1"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#D1D1D1"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#D1D1D1"/>
   </Borders>
  </Style>
  <Style ss:ID="BantalanNumber">
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
   <Font ss:FontName="Calibri" x:Family="Swiss" ss:Size="11" ss:Bold="1" ss:Color="#FFFFFF"/>
   <Interior ss:Color="#5B9BD5" ss:Pattern="Solid"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
   </Borders>
  </Style>
  <Style ss:ID="BantalanLetter">
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
   <Font ss:FontName="Calibri" x:Family="Swiss" ss:Size="11" ss:Bold="1" ss:Color="#FFFFFF"/>
   <Interior ss:Color="#70AD47" ss:Pattern="Solid"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
   </Borders>
  </Style>
  <Style ss:ID="Title">
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
   <Font ss:FontName="Calibri" x:Family="Swiss" ss:Size="16" ss:Bold="1"/>
  </Style>
  <Style ss:ID="Subtitle">
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
   <Font ss:FontName="Calibri" x:Family="Swiss" ss:Size="12"/>
  </Style>
 </Styles>
 <Worksheet ss:Name="Daftar Bantalan">
  <Table ss:ExpandedColumnCount="9" ss:ExpandedRowCount="' . (count($participants) + 10) . '" x:FullColumns="1" x:FullRows="1" ss:DefaultRowHeight="15">
   <Column ss:AutoFitWidth="0" ss:Width="40"/>
   <Column ss:AutoFitWidth="0" ss:Width="150"/>
   <Column ss:AutoFitWidth="0" ss:Width="120"/>
   <Column ss:AutoFitWidth="0" ss:Width="120"/>
   <Column ss:AutoFitWidth="0" ss:Width="100"/>
   <Column ss:AutoFitWidth="0" ss:Width="120"/>
   <Column ss:AutoFitWidth="0" ss:Width="120"/>
   <Column ss:AutoFitWidth="0" ss:Width="60"/>
   <Column ss:AutoFitWidth="0" ss:Width="60"/>

   <!-- Title -->
   <Row ss:Height="25">
    <Cell ss:MergeAcross="8" ss:StyleID="Title">
     <Data ss:Type="String">TURNAMEN PANAHAN - DAFTAR BANTALAN</Data>
    </Cell>
   </Row>

   <!-- Export Info -->
   <Row ss:Height="20">
    <Cell ss:MergeAcross="8" ss:StyleID="Subtitle">
     <Data ss:Type="String">Diekspor pada: ' . date('d/m/Y H:i:s') . '</Data>
    </Cell>
   </Row>';

        if ($selectedKegiatan !== 'all' || $selectedCategory !== 'all') {
            echo '
   <Row ss:Height="20">
    <Cell ss:MergeAcross="8" ss:StyleID="Subtitle">
     <Data ss:Type="String">Filter: ';

            if ($selectedKegiatan !== 'all') {
                $kegiatanName = $kegiatan[$selectedKegiatan] ?? "Kegiatan $selectedKegiatan";
                echo "Kegiatan: $kegiatanName";
            }

            if ($selectedCategory !== 'all') {
                $categoryName = $categories[$selectedCategory] ?? "Kategori $selectedCategory";
                if ($selectedKegiatan !== 'all') {
                    echo " | ";
                }
                echo "Kategori: $categoryName";
            }

            echo '</Data>
    </Cell>
   </Row>';
        }

        if ($isShuffled) {
            echo '
   <Row ss:Height="20">
    <Cell ss:MergeAcross="8" ss:StyleID="Subtitle">
     <Data ss:Type="String">Catatan: Urutan peserta telah diacak</Data>
    </Cell>
   </Row>';
        }

        echo '

   <!-- Empty Row -->
   <Row ss:Height="15"/>

   <!-- Headers -->
   <Row ss:Height="20">
    <Cell ss:StyleID="Header"><Data ss:Type="String">No.</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Nama Peserta</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Nama Club</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Asal Kota</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Jenis Kelamin</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Kategori</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Kegiatan</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Bantalan No.</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Bantalan Huruf</Data></Cell>
   </Row>';

        foreach ($participants as $index => $participant) {
            $no = $index + 1;
            $nama = htmlspecialchars($participant['nama_peserta'] ?? '', ENT_XML1);
            $club = htmlspecialchars($participant['nama_club'] ?? '', ENT_XML1);
            $kota = htmlspecialchars($participant['asal_kota'] ?? '', ENT_XML1);
            $jenisKelamin = htmlspecialchars($participant['jenis_kelamin'] ?? '', ENT_XML1);
            $kategoriName = htmlspecialchars($categories[$participant['category_id']] ?? "Kategori {$participant['category_id']}", ENT_XML1);
            $kegiatanName = htmlspecialchars($kegiatan[$participant['kegiatan_id']] ?? "Kegiatan {$participant['kegiatan_id']}", ENT_XML1);
            $bantalanNo = $participant['randomNumber'];
            $bantalanHuruf = $participant['randomLetter'];

            echo '
   <Row>
    <Cell ss:StyleID="Data"><Data ss:Type="Number">' . $no . '</Data></Cell>
    <Cell ss:StyleID="Data"><Data ss:Type="String">' . $nama . '</Data></Cell>
    <Cell ss:StyleID="Data"><Data ss:Type="String">' . $club . '</Data></Cell>
    <Cell ss:StyleID="Data"><Data ss:Type="String">' . $kota . '</Data></Cell>
    <Cell ss:StyleID="Data"><Data ss:Type="String">' . $jenisKelamin . '</Data></Cell>
    <Cell ss:StyleID="Data"><Data ss:Type="String">' . $kategoriName . '</Data></Cell>
    <Cell ss:StyleID="Data"><Data ss:Type="String">' . $kegiatanName . '</Data></Cell>
    <Cell ss:StyleID="BantalanNumber"><Data ss:Type="Number">' . $bantalanNo . '</Data></Cell>
    <Cell ss:StyleID="BantalanLetter"><Data ss:Type="String">' . $bantalanHuruf . '</Data></Cell>
   </Row>';
        }

        echo '
  </Table>
 </Worksheet>
</Workbook>';
        exit;
    }
}

// Enhanced shuffle function (UNCHANGED)
function betterShuffle(&$array) {
    mt_srand(microtime(true) * 1000000);
    for ($i = 0; $i < 5; $i++) {
        shuffle($array);
        usleep(1000);
        mt_srand(microtime(true) * 1000000);
    }
}

// Fetch data from database (UNCHANGED LOGIC)
$pesertaData = [];
$categoriesData = [];
$kegiatanData = [];

try {
    $stmt = $db->prepare("SELECT * FROM peserta WHERE category_id IS NOT NULL AND kegiatan_id IS NOT NULL");
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $pesertaData[] = $row;
    }
    $stmt->close();

    $stmt = $db->prepare("SELECT * FROM categories");
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $categoriesData[$row['id']] = $row['nama_kategori'] ?? $row['name'] ?? "Kategori {$row['id']}";
    }
    $stmt->close();

    $stmt = $db->prepare("SELECT * FROM kegiatan");
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $kegiatanData[$row['id']] = $row['nama_kegiatan'] ?? $row['name'] ?? "Kegiatan {$row['id']}";
    }
    $stmt->close();

} catch(Exception $e) {
    die("Database Error: " . $e->getMessage());
}

// Filter logic (UNCHANGED - same GET parameter names)
$selectedKegiatan = $_GET['kegiatan'] ?? 'all';
$selectedCategory = $_GET['kategori'] ?? 'all';
$isShuffled = isset($_GET['shuffle']) && $_GET['shuffle'] == '1';

// Filter participants (UNCHANGED LOGIC)
$filteredParticipants = $pesertaData;

if ($selectedKegiatan !== 'all') {
    $filteredParticipants = array_filter($filteredParticipants, function($p) use ($selectedKegiatan) {
        return $p['kegiatan_id'] == $selectedKegiatan;
    });
}

if ($selectedCategory !== 'all') {
    $filteredParticipants = array_filter($filteredParticipants, function($p) use ($selectedCategory) {
        return $p['category_id'] == $selectedCategory;
    });
}

$filteredParticipants = array_values($filteredParticipants);

if ($isShuffled) {
    betterShuffle($filteredParticipants);
}

// Assign bantalan (UNCHANGED)
$letters = ['A', 'B', 'C'];
foreach ($filteredParticipants as $index => &$participant) {
    $participant['randomNumber'] = floor($index / 3) + 1;
    $participant['randomLetter'] = $letters[$index % 3];
}

$availableKegiatan = array_unique(array_column($pesertaData, 'kegiatan_id'));
$availableCategories = array_unique(array_column(
    array_filter($pesertaData, function($p) use ($selectedKegiatan) {
        return $selectedKegiatan === 'all' || $p['kegiatan_id'] == $selectedKegiatan;
    }),
    'category_id'
));

$username = $_SESSION['username'] ?? 'User';
$name = $_SESSION['name'] ?? $username;
$role = $_SESSION['role'] ?? 'user';
?>
<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pertandingan & Bantalan - Turnamen Panahan</title>
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
    <script><?= getThemeInitScript() ?></script>
    <script><?= getUiScripts() ?></script>
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
                    <a href="peserta.view.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800 transition-colors">
                        <i class="fas fa-user-friends w-5"></i>
                        <span class="text-sm">Peserta</span>
                    </a>
                    <a href="pertandingan.view.php" class="flex items-center gap-3 px-4 py-3 rounded-lg bg-archery-600/20 text-archery-400 border border-archery-600/30">
                        <i class="fas fa-random w-5"></i>
                        <span class="text-sm font-medium">Pertandingan</span>
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
            <!-- Header -->
            <header class="sticky top-0 z-40 bg-white/80 backdrop-blur-sm border-b border-slate-200">
                <div class="px-6 lg:px-8 py-4">
                    <div class="flex items-center justify-between">
                        <div class="pl-12 lg:pl-0">
                            <h1 class="text-xl font-semibold text-slate-900">Pertandingan & Bantalan</h1>
                            <p class="text-sm text-slate-500">Pengaturan bantalan dan pengundian posisi peserta</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <!-- Export Excel Button -->
                            <?php if (count($filteredParticipants) > 0): ?>
                            <!-- FORM: method=POST (UNCHANGED) -->
                            <form method="POST" class="inline" onsubmit="event.preventDefault(); showConfirmModal('Export Data', 'Download daftar bantalan ke Excel (.xlsx)?', () => { const h = document.createElement('input'); h.type='hidden'; h.name='export_excel'; h.value='1'; this.appendChild(h); this.submit(); }, 'info')">
                                <?php csrf_field(); ?>
                                <!-- INPUT: name="participants" (UNCHANGED) -->
                                <input type="hidden" name="participants" value="<?php echo htmlspecialchars(json_encode($filteredParticipants)); ?>">
                                <!-- INPUT: name="categories" (UNCHANGED) -->
                                <input type="hidden" name="categories" value="<?php echo htmlspecialchars(json_encode($categoriesData)); ?>">
                                <!-- INPUT: name="kegiatan" (UNCHANGED) -->
                                <input type="hidden" name="kegiatan" value="<?php echo htmlspecialchars(json_encode($kegiatanData)); ?>">
                                <!-- INPUT: name="selected_kegiatan" (UNCHANGED) -->
                                <input type="hidden" name="selected_kegiatan" value="<?php echo htmlspecialchars($selectedKegiatan); ?>">
                                <!-- INPUT: name="selected_category" (UNCHANGED) -->
                                <input type="hidden" name="selected_category" value="<?php echo htmlspecialchars($selectedCategory); ?>">
                                <!-- INPUT: name="is_shuffled" (UNCHANGED) -->
                                <input type="hidden" name="is_shuffled" value="<?php echo $isShuffled ? '1' : '0'; ?>">
                                <!-- BUTTON: (Changed to show modal) -->
                                <button type="submit"
                                        class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-emerald-600 text-white text-sm font-medium hover:bg-emerald-700 transition-colors">
                                    <i class="fas fa-file-excel"></i>
                                    <span class="hidden sm:inline">Export Excel</span>
                                </button>
                            </form>
                            <?php endif; ?>
                            <div class="flex items-center gap-2 px-3 py-1.5 rounded-lg bg-archery-50 text-archery-700">
                                <i class="fas fa-users text-sm"></i>
                                <span class="text-sm font-medium"><?php echo count($filteredParticipants); ?> Peserta</span>
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            <div class="px-6 lg:px-8 py-6">
                <!-- Stats Cards -->
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
                    <div class="bg-white rounded-xl border-l-4 border-archery-500 p-4 shadow-sm">
                        <p class="text-2xl font-bold text-archery-600"><?php echo count($filteredParticipants); ?></p>
                        <p class="text-xs text-slate-500 mt-1">Total Peserta</p>
                    </div>
                    <div class="bg-white rounded-xl border-l-4 border-blue-500 p-4 shadow-sm">
                        <p class="text-2xl font-bold text-blue-600">
                            <?php echo count(array_unique(array_column($filteredParticipants, 'category_id'))); ?>
                        </p>
                        <p class="text-xs text-slate-500 mt-1">Kategori Aktif</p>
                    </div>
                    <div class="bg-white rounded-xl border-l-4 border-purple-500 p-4 shadow-sm">
                        <p class="text-2xl font-bold text-purple-600">
                            <?php echo count(array_unique(array_column($filteredParticipants, 'asal_kota'))); ?>
                        </p>
                        <p class="text-xs text-slate-500 mt-1">Kota Asal</p>
                    </div>
                    <div class="bg-white rounded-xl border-l-4 border-amber-500 p-4 shadow-sm">
                        <p class="text-2xl font-bold text-amber-600">
                            <?php echo ceil(count($filteredParticipants) / 3); ?>
                        </p>
                        <p class="text-xs text-slate-500 mt-1">Total Bantalan</p>
                    </div>
                </div>

                <!-- Filter Form -->
                <div class="bg-white rounded-xl border border-slate-200 p-5 mb-6">
                    <h3 class="font-semibold text-slate-900 mb-4 flex items-center gap-2">
                        <i class="fas fa-filter text-slate-400"></i>
                        Filter & Pengaturan
                    </h3>
                    <!-- FORM: method=GET (UNCHANGED) -->
                    <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Kegiatan</label>
                            <!-- SELECT: name="kegiatan" (UNCHANGED) -->
                            <select name="kegiatan" onchange="this.form.submit()"
                                    class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:ring-2 focus:ring-archery-500 focus:border-archery-500">
                                <option value="all" <?php echo $selectedKegiatan === 'all' ? 'selected' : ''; ?>>Semua Kegiatan</option>
                                <?php foreach ($availableKegiatan as $kegiatanId): ?>
                                    <option value="<?php echo $kegiatanId; ?>" <?php echo $selectedKegiatan == $kegiatanId ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($kegiatanData[$kegiatanId] ?? "Kegiatan $kegiatanId"); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Kategori</label>
                            <!-- SELECT: name="kategori" (UNCHANGED) -->
                            <select name="kategori" onchange="this.form.submit()"
                                    class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:ring-2 focus:ring-archery-500 focus:border-archery-500">
                                <option value="all" <?php echo $selectedCategory === 'all' ? 'selected' : ''; ?>>Semua Kategori</option>
                                <?php foreach ($categoriesData as $categoryId => $categoryName): ?>
                                    <option value="<?php echo $categoryId; ?>" <?php echo $selectedCategory == $categoryId ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($categoryName); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="flex items-end">
                            <!-- BUTTON: name="shuffle" (UNCHANGED) -->
                            <button type="submit" name="shuffle" value="<?php echo $isShuffled ? '0' : '1'; ?>"
                                    class="w-full px-4 py-2 rounded-lg <?php echo $isShuffled ? 'bg-amber-600 hover:bg-amber-700' : 'bg-archery-600 hover:bg-archery-700'; ?> text-white text-sm font-medium transition-colors flex items-center justify-center gap-2">
                                <i class="fas fa-random"></i>
                                <span><?php echo $isShuffled ? 'Reset Urutan' : 'Acak Bantalan'; ?></span>
                            </button>
                        </div>

                        <div class="flex items-end">
                            <a href="<?php echo $_SERVER['PHP_SELF']; ?>"
                               class="w-full px-4 py-2 rounded-lg border border-slate-300 text-slate-600 text-sm font-medium hover:bg-slate-50 transition-colors flex items-center justify-center gap-2">
                                <i class="fas fa-sync-alt"></i>
                                <span>Refresh</span>
                            </a>
                        </div>

                        <!-- Hidden inputs to maintain filters (UNCHANGED) -->
                        <?php if ($selectedKegiatan !== 'all'): ?>
                            <input type="hidden" name="kegiatan" value="<?php echo htmlspecialchars($selectedKegiatan); ?>">
                        <?php endif; ?>
                        <?php if ($selectedCategory !== 'all'): ?>
                            <input type="hidden" name="kategori" value="<?php echo htmlspecialchars($selectedCategory); ?>">
                        <?php endif; ?>
                    </form>
                </div>

                <!-- Shuffle Status -->
                <?php if ($isShuffled): ?>
                    <div class="mb-6 flex items-center gap-3 px-4 py-3 rounded-lg bg-amber-50 border border-amber-200 text-amber-800">
                        <i class="fas fa-random text-amber-500"></i>
                        <p class="text-sm font-medium">Urutan peserta telah diacak (5x shuffle untuk randomisasi maksimal)</p>
                    </div>
                <?php endif; ?>

                <!-- Data Table -->
                <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
                    <div class="overflow-x-auto custom-scrollbar" style="max-height: 60vh;">
                        <table class="w-full">
                            <thead class="bg-zinc-800 text-white sticky top-0 z-10">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider w-12">#</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider">Nama Peserta</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider">Club</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider">Asal Kota</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider">Kategori</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider">Kegiatan</th>
                                    <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider">Bantalan</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php if (count($filteredParticipants) > 0): ?>
                                    <?php foreach ($filteredParticipants as $index => $participant): ?>
                                        <tr class="hover:bg-slate-50 transition-colors">
                                            <td class="px-4 py-3 text-sm text-slate-500"><?php echo $index + 1; ?></td>
                                            <td class="px-4 py-3">
                                                <p class="font-medium text-slate-900"><?php echo htmlspecialchars($participant['nama_peserta'] ?? ''); ?></p>
                                                <p class="text-xs text-slate-500">
                                                    <i class="fas <?php echo ($participant['jenis_kelamin'] ?? '') == 'Laki-laki' ? 'fa-mars text-blue-500' : 'fa-venus text-pink-500'; ?> mr-1"></i>
                                                    <?php echo htmlspecialchars($participant['jenis_kelamin'] ?? ''); ?>
                                                </p>
                                            </td>
                                            <td class="px-4 py-3 text-sm text-slate-600"><?php echo htmlspecialchars($participant['nama_club'] ?? '-'); ?></td>
                                            <td class="px-4 py-3 text-sm text-slate-600"><?php echo htmlspecialchars($participant['asal_kota'] ?? '-'); ?></td>
                                            <td class="px-4 py-3">
                                                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                                    <?php echo htmlspecialchars($categoriesData[$participant['category_id']] ?? "Kategori {$participant['category_id']}"); ?>
                                                </span>
                                            </td>
                                            <td class="px-4 py-3 text-sm text-slate-600">
                                                <?php echo htmlspecialchars($kegiatanData[$participant['kegiatan_id']] ?? "Kegiatan {$participant['kegiatan_id']}"); ?>
                                            </td>
                                            <td class="px-4 py-3">
                                                <div class="flex items-center justify-center gap-1">
                                                    <span class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-archery-600 text-white text-sm font-bold shadow-sm">
                                                        <?php echo $participant['randomNumber']; ?>
                                                    </span>
                                                    <span class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-emerald-600 text-white text-sm font-bold shadow-sm">
                                                        <?php echo $participant['randomLetter']; ?>
                                                    </span>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7" class="px-4 py-12 text-center">
                                            <div class="flex flex-col items-center">
                                                <div class="w-16 h-16 rounded-full bg-slate-100 flex items-center justify-center mb-3">
                                                    <i class="fas fa-users text-slate-400 text-2xl"></i>
                                                </div>
                                                <p class="text-slate-500 font-medium">Tidak ada peserta yang sesuai dengan filter</p>
                                                <p class="text-slate-400 text-sm">Ubah filter untuk melihat peserta lainnya</p>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if (!empty($filteredParticipants)): ?>
                        <div class="px-4 py-3 bg-slate-50 border-t border-slate-200 flex items-center justify-between">
                            <p class="text-sm text-slate-500">Menampilkan <?= count($filteredParticipants) ?> peserta</p>
                            <?php if ($isShuffled): ?>
                                <span class="px-2 py-1 rounded-full bg-amber-100 text-amber-700 text-xs font-medium">Urutan Diacak</span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Instructions -->
                <div class="bg-white rounded-xl border border-slate-200 p-5 mt-6">
                    <h3 class="font-semibold text-slate-900 mb-4 flex items-center gap-2">
                        <i class="fas fa-info-circle text-blue-500"></i>
                        Panduan Sistem Bantalan
                    </h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <h4 class="font-medium text-slate-800 mb-2">Sistem Bantalan:</h4>
                            <ul class="space-y-1.5 text-sm text-slate-600">
                                <li class="flex items-start gap-2">
                                    <i class="fas fa-check text-archery-500 mt-0.5"></i>
                                    <span>Setiap bantalan terdiri dari 3 peserta (A, B, C)</span>
                                </li>
                                <li class="flex items-start gap-2">
                                    <i class="fas fa-check text-archery-500 mt-0.5"></i>
                                    <span>Bantalan 1: 1A, 1B, 1C</span>
                                </li>
                                <li class="flex items-start gap-2">
                                    <i class="fas fa-check text-archery-500 mt-0.5"></i>
                                    <span>Bantalan 2: 2A, 2B, 2C</span>
                                </li>
                                <li class="flex items-start gap-2">
                                    <i class="fas fa-check text-archery-500 mt-0.5"></i>
                                    <span>Dan seterusnya...</span>
                                </li>
                            </ul>
                        </div>
                        <div>
                            <h4 class="font-medium text-slate-800 mb-2">Fitur:</h4>
                            <ul class="space-y-1.5 text-sm text-slate-600">
                                <li class="flex items-start gap-2">
                                    <i class="fas fa-filter text-blue-500 mt-0.5"></i>
                                    <span>Filter berdasarkan kegiatan dan kategori</span>
                                </li>
                                <li class="flex items-start gap-2">
                                    <i class="fas fa-random text-purple-500 mt-0.5"></i>
                                    <span>Acak bantalan untuk pengundian (5x shuffle)</span>
                                </li>
                                <li class="flex items-start gap-2">
                                    <i class="fas fa-sync-alt text-slate-500 mt-0.5"></i>
                                    <span>Reset urutan ke posisi awal</span>
                                </li>
                                <li class="flex items-start gap-2">
                                    <i class="fas fa-file-excel text-emerald-500 mt-0.5"></i>
                                    <span>Export ke Excel dengan format lengkap</span>
                                </li>
                            </ul>
                        </div>
                    </div>
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
            <a href="pertandingan.view.php" class="flex items-center gap-3 px-4 py-3 rounded-lg bg-archery-600/20 text-archery-400">
                <i class="fas fa-random w-5"></i><span class="text-sm font-medium">Pertandingan</span>
            </a>
            <a href="peserta.view.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800">
                <i class="fas fa-user-friends w-5"></i><span class="text-sm">Peserta</span>
            </a>
            <a href="kegiatan.view.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800">
                <i class="fas fa-calendar w-5"></i><span class="text-sm">Kegiatan</span>
            </a>
            <a href="statistik.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800">
                <i class="fas fa-chart-bar w-5"></i><span class="text-sm">Statistik</span>
            </a>
        </nav>
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
        
        // Theme Toggle Functionality
        <?= getThemeToggleScript() ?>
    </script>
    <?= getConfirmationModal() ?>
</body>
</html>
<?php skip_post: ?>
