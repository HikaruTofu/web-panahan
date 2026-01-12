<?php
/**
 * Data Peserta - Turnamen Panahan
 * UI: Intentional Minimalism with Tailwind CSS
 */
require_once __DIR__ . '/../config/panggil.php';
require_once __DIR__ . '/../includes/check_access.php';
require_once __DIR__ . '/../includes/theme.php';
require_once __DIR__ . '/../includes/security.php';
requireLogin(); 

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

// Handle AJAX participant search for Merge Tool
if (isset($_GET['action']) && $_GET['action'] === 'search_peserta') {
    header('Content-Type: application/json');
    $query_str = isset($_GET['q']) ? trim($_GET['q']) : '';
    if (strlen($query_str) < 2) {
        echo json_encode([]);
        exit;
    }
    try {
        $query = "
            SELECT p.id, p.nama_peserta, p.tanggal_lahir, p.nama_club, p.jenis_kelamin, p.kegiatan_id, p.category_id, c.name as category_name, k.nama_kegiatan
            FROM peserta p
            LEFT JOIN categories c ON p.category_id = c.id
            LEFT JOIN kegiatan k ON p.kegiatan_id = k.id
            WHERE p.nama_peserta LIKE ? 
            OR p.nama_club LIKE ?
            LIMIT 10
        ";
        $stmt = $conn->prepare($query);
        $search_param = "%$query_str%";
        $stmt->bind_param("ss", $search_param, $search_param);
        $stmt->execute();
        $result = $stmt->get_result();
        $results = [];
        while ($row = $result->fetch_assoc()) {
            $results[] = $row;
        }
        $stmt->close();
        echo json_encode($results);
        exit;
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
}

// Handle CRUD Operations
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $staffActions = ['create', 'update'];
    
    if (in_array($action, $staffActions)) {
        if (!canInputScore()) {
            $_SESSION['toast_message'] = "Akses ditolak. Staf tidak memiliki izin untuk melakukan tindakan ini.";
            $_SESSION['toast_type'] = 'error';
            header("Location: ?" . http_build_query($_GET));
            exit;
        }
    } else {
        if (!isAdmin()) {
            $_SESSION['toast_message'] = "Akses ditolak. Hanya Admin yang dapat melakukan tindakan ini.";
            $_SESSION['toast_type'] = 'error';
            header("Location: ?" . http_build_query($_GET));
            exit;
        }
    }
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

                $tanggal_lahir_db = !empty($tanggal_lahir) ? $tanggal_lahir : null;
                $stmt = $conn->prepare("INSERT INTO peserta (nama_peserta, tanggal_lahir, jenis_kelamin, asal_kota, nama_club, sekolah, kelas, nomor_hp, bukti_pembayaran, category_id, kegiatan_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                $stmt->bind_param("sssssssssii", $nama_peserta, $tanggal_lahir_db, $jenis_kelamin, $asal_kota, $nama_club, $sekolah, $kelas, $nomor_hp, $bukti_pembayaran, $category_id, $kegiatan_id);
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
            $old_name = $_POST['old_nama_peserta'] ?? '';
            $nama_peserta = $_POST['nama_peserta'];
            $category_ids = isset($_POST['category_ids']) ? $_POST['category_ids'] : [];
            $kegiatan_id = intval($_POST['kegiatan_id']);
            $tanggal_lahir = $_POST['tanggal_lahir'];
            $jenis_kelamin = $_POST['jenis_kelamin'];
            $asal_kota = $_POST['asal_kota'];
            $nama_club = $_POST['nama_club'];
            $sekolah = $_POST['sekolah'];
            $kelas = $_POST['kelas'];
            $nomor_hp = $_POST['nomor_hp'];

            // Start transaction for data integrity
            $conn->begin_transaction();

            try {
                // 1. Update shared info for ALL rows matching old name + DOB
                // This keeps the participant details in sync across different category/activity rows
                $tanggal_lahir_db = !empty($tanggal_lahir) ? $tanggal_lahir : null;
                $stmt = $conn->prepare("UPDATE peserta SET nama_peserta=?, tanggal_lahir=?, jenis_kelamin=?, asal_kota=?, nama_club=?, sekolah=?, kelas=?, nomor_hp=?, updated_at=NOW() WHERE nama_peserta=? AND (tanggal_lahir <=> ? OR tanggal_lahir IS NULL)");
                $stmt->bind_param("ssssssssss", $nama_peserta, $tanggal_lahir_db, $jenis_kelamin, $asal_kota, $nama_club, $sekolah, $kelas, $nomor_hp, $old_name, $tanggal_lahir_db);
                $stmt->execute();
                $stmt->close();

                // 2. Sync categories for the SELECTED kegiatan_id
                // Get currently registered categories for this person in this activity
                $existing_cats = [];
                $q = "SELECT id, category_id FROM peserta WHERE nama_peserta=? AND kegiatan_id=?";
                $stmt = $conn->prepare($q);
                $stmt->bind_param("si", $nama_peserta, $kegiatan_id);
                $stmt->execute();
                $res = $stmt->get_result();
                while($row = $res->fetch_assoc()) {
                    $existing_cats[$row['category_id']] = $row['id'];
                }
                $stmt->close();

                // Add new category registrations
                foreach ($category_ids as $cat_id) {
                    $cat_id = intval($cat_id);
                    if (!isset($existing_cats[$cat_id])) {
                        $tanggal_lahir_db = !empty($tanggal_lahir) ? $tanggal_lahir : null;
                        $ins = "INSERT INTO peserta (nama_peserta, category_id, kegiatan_id, tanggal_lahir, jenis_kelamin, asal_kota, nama_club, sekolah, kelas, nomor_hp, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                        $stmt = $conn->prepare($ins);
                        $stmt->bind_param("siisssssss", $nama_peserta, $cat_id, $kegiatan_id, $tanggal_lahir_db, $jenis_kelamin, $asal_kota, $nama_club, $sekolah, $kelas, $nomor_hp);
                        $stmt->execute();
                        $stmt->close();
                    }
                }

                // Remove unselected category registrations (only if no scores exist to prevent data loss)
                foreach ($existing_cats as $cat_id => $row_id) {
                    if (!in_array($cat_id, $category_ids)) {
                        $check_score = $conn->prepare("SELECT id FROM score WHERE peserta_id = ? LIMIT 1");
                        $check_score->bind_param("i", $row_id);
                        $check_score->execute();
                        $has_score = $check_score->get_result()->num_rows > 0;
                        $check_score->close();

                        if (!$has_score) {
                            // Backup before deletion (Recover Mode)
                            backup_deleted_record($conn, 'peserta', $row_id);
                            $conn->query("DELETE FROM peserta WHERE id = $row_id");
                        }
                    }
                }

                $conn->commit();
                $_SESSION['toast_message'] = "Data peserta berhasil diperbarui!";
                $_SESSION['toast_type'] = 'success';

                $params = $_GET;
                if ($kegiatan_id) $params['kegiatan_id'] = $kegiatan_id;
                header("Location: ?" . http_build_query($params));
                exit;

            } catch (Exception $e) {
                $conn->rollback();
                $_SESSION['toast_message'] = "Gagal memperbarui data: " . $e->getMessage();
                $_SESSION['toast_type'] = 'error';
                header("Location: ?" . http_build_query($_GET));
                exit;
            }
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
                // Backup before deletion (Recover Mode)
                backup_deleted_record($conn, 'peserta', $id);
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

        case 'merge':
            $masterId = intval($_POST['master_id']);
            $duplicateIds = isset($_POST['duplicate_ids']) ? $_POST['duplicate_ids'] : [];
            
            if (empty($duplicateIds) || $masterId == 0) {
                 $_SESSION['toast_message'] = "Data utama atau duplikat tidak valid.";
                 $_SESSION['toast_type'] = 'error';
                 header("Location: " . $_SERVER['PHP_SELF'] . "?" . http_build_query($_GET));
                 exit;
            }

            $conn->begin_transaction();
            try {
                // 1. Fetch Master Data
                $stmt = $conn->prepare("SELECT nama_peserta, tanggal_lahir, jenis_kelamin, asal_kota, nama_club, sekolah, kelas, nomor_hp, bukti_pembayaran FROM peserta WHERE id = ?");
                $stmt->bind_param("i", $masterId);
                $stmt->execute();
                $masterData = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$masterData) throw new Exception("Data utama tidak ditemukan.");

                foreach ($duplicateIds as $dupId) {
                    $dupId = intval($dupId);
                    if ($dupId === $masterId) continue;

                    // 2. Fetch Duplicate Data context
                    $stmt = $conn->prepare("SELECT category_id, kegiatan_id FROM peserta WHERE id = ?");
                    $stmt->bind_param("i", $dupId);
                    $stmt->execute();
                    $dupCtx = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    if (!$dupCtx) continue;

                    // 3. Check for Collision (Master Identity in Duplicate's Context)
                    // We check if a participant with Master's Name AND Duplicate's Category/Kegiatan ALREADY EXISTS (excluding the duplicate row itself)
                    // Note: We ignore date of birth in collision check to be broader? No, let's stick to name + category context as primarily defining the collision.
                    // Actually, if we are merging "Husin" to "Muhammad Husin", we want to know if "Muhammad Husin" is ALREADY registered in "Husin's" category.
                    
                    $checkQ = "SELECT id FROM peserta WHERE nama_peserta = ? AND category_id = ? AND kegiatan_id = ? AND id != ?";
                    $stmtCheck = $conn->prepare($checkQ);
                    $stmtCheck->bind_param("siis", $masterData['nama_peserta'], $dupCtx['category_id'], $dupCtx['kegiatan_id'], $dupId);
                    $stmtCheck->execute();
                    $collision = $stmtCheck->get_result()->fetch_assoc();
                    $stmtCheck->close();

                    if ($collision) {
                        // CASE A: Collision Found (Redundant Registration)
                        // "Muhammad Husin" is already in this category.
                        // Move scores from "Husin" (dupId) to "Muhammad Husin" (collision['id'])
                        $targetId = $collision['id'];

                        // Relink scores
                        $stmtScore = $conn->prepare("UPDATE score SET peserta_id = ? WHERE peserta_id = ?");
                        $stmtScore->bind_param("ii", $targetId, $dupId);
                        $stmtScore->execute();
                        $stmtScore->close();

                        // Delete the duplicate row
                        // Backup before deletion (Recover Mode)
                        backup_deleted_record($conn, 'peserta', $dupId);
                        $stmtDel = $conn->prepare("DELETE FROM peserta WHERE id = ?");
                        $stmtDel->bind_param("i", $dupId);
                        $stmtDel->execute();
                        $stmtDel->close();

                    } else {
                        // CASE B: No Collision (Unique Registration)
                        // "Muhammad Husin" is NOT in this category.
                        // Update "Husin" (dupId) to become "Muhammad Husin"
                        
                        $updateQ = "UPDATE peserta SET nama_peserta=?, tanggal_lahir=?, jenis_kelamin=?, asal_kota=?, nama_club=?, sekolah=?, kelas=?, nomor_hp=?, updated_at=NOW() WHERE id=?";
                        $stmtUpd = $conn->prepare($updateQ);
                        $tanggal_lahir_master = !empty($masterData['tanggal_lahir']) ? $masterData['tanggal_lahir'] : null;
                        $stmtUpd->bind_param("ssssssssi", 
                            $masterData['nama_peserta'], 
                            $tanggal_lahir_master, 
                            $masterData['jenis_kelamin'], 
                            $masterData['asal_kota'], 
                            $masterData['nama_club'], 
                            $masterData['sekolah'], 
                            $masterData['kelas'], 
                            $masterData['nomor_hp'], 
                            $dupId
                        );
                        $stmtUpd->execute();
                        $stmtUpd->close();
                    }
                }

                $conn->commit();
                $_SESSION['toast_message'] = "Data berhasil digabungkan!";
                $_SESSION['toast_type'] = 'success';
            } catch (Exception $e) {
                $conn->rollback();
                $_SESSION['toast_message'] = "Gagal menggabungkan data: " . $e->getMessage();
                $_SESSION['toast_type'] = 'error';
            }
            header("Location: " . $_SERVER['PHP_SELF'] . "?" . http_build_query($_GET));
            exit;
            break;
    }
    }
}

// Handle export to Excel
if (isset($_GET['export']) && $_GET['export'] == 'excel') {
    require_once __DIR__ . '/../vendor/vendor/autoload.php';
    
    // Use FQCN instead of 'use' inside block
    // use PhpOffice\PhpSpreadsheet\Spreadsheet;
    // use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
    // use PhpOffice\PhpSpreadsheet\Style\Alignment;

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

    // Grouping by Name logic
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

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Data Peserta');

    // Headers
    $headers = [
        'No', 'ID(s)', 'Nama Peserta', 'Kategori', 'Kegiatan', 
        'Tanggal Lahir', 'Umur', 'Jenis Kelamin', 'Asal Kota', 
        'Nama Club', 'Sekolah', 'Kelas', 'Nomor HP', 'Status Pembayaran', 'Tanggal Daftar'
    ];

    $col = 'A';
    $rowIdx = 1;
    foreach ($headers as $header) {
        $sheet->setCellValue($col . $rowIdx, $header);
        $sheet->getStyle($col . $rowIdx)->getFont()->setBold(true);
        $sheet->getStyle($col . $rowIdx)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $col++;
    }
    $rowIdx++;

    $no = 1;
    foreach ($groupedData as $item) {
        $umur = "-";
        if (!empty($item['tanggal_lahir'])) {
            $dob = new DateTime($item['tanggal_lahir']);
            $today = new DateTime();
            $umur = $today->diff($dob)->y . " tahun";
        }

        $statusBayar = !empty($item['bukti_pembayaran']) ? 'Sudah Bayar' : 'Belum Bayar';

        $col = 'A';
        $sheet->setCellValue($col++ . $rowIdx, $no++);
        $sheet->setCellValue($col++ . $rowIdx, implode(', ', $item['ids']));
        $sheet->setCellValue($col++ . $rowIdx, $item['nama_peserta']);
        $sheet->setCellValue($col++ . $rowIdx, implode(', ', $item['categories']) ?: '-');
        $sheet->setCellValue($col++ . $rowIdx, implode(', ', $item['kegiatan']) ?: '-');
        $sheet->setCellValue($col++ . $rowIdx, $item['tanggal_lahir'] ?? '-');
        $sheet->setCellValue($col++ . $rowIdx, $umur);
        $sheet->setCellValue($col++ . $rowIdx, $item['jenis_kelamin']);
        $sheet->setCellValue($col++ . $rowIdx, $item['asal_kota'] ?? '-');
        $sheet->setCellValue($col++ . $rowIdx, $item['nama_club'] ?? '-');
        $sheet->setCellValue($col++ . $rowIdx, $item['sekolah'] ?? '-');
        $sheet->setCellValue($col++ . $rowIdx, $item['kelas'] ?? '-');
        $sheet->setCellValue($col++ . $rowIdx, $item['nomor_hp'] ?? '-'); // Ensure string format
        $sheet->getStyle($col . $rowIdx)->getNumberFormat()->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_TEXT);
        
        $sheet->setCellValue($col++ . $rowIdx, $statusBayar);
        
        // Fix for long dates or empty
        $created_at = $item['created_at'] ?? '-';
        $sheet->setCellValue($col++ . $rowIdx, $created_at);

        $rowIdx++;
    }

    // Auto-size columns
    foreach (range('A', $col) as $columnID) {
        $sheet->getColumnDimension($columnID)->setAutoSize(true);
    }

    $filename = "data_peserta_" . date('Y-m-d_His') . ".xlsx";

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    
    // Clear any previous output
    if (ob_get_length()) ob_clean();

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
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
    if (!empty($row['jenis_kelamin']) && $row['jenis_kelamin'] == 'Perempuan') $totalPerempuan++;
    if (!empty($row['bukti_pembayaran'])) $totalBayar++;

    $nama_display = $row['nama_peserta'];
    $nama_key = strtolower(trim($nama_display));

    if (!isset($pesertaGrouped[$nama_key])) {
        $pesertaGrouped[$nama_key] = [
            'display_name' => $nama_display,
            'data' => $row,
            'ids' => [$row['id']],
            'category_ids' => [$row['category_id']],
            'categories' => [],
            'kegiatan' => [],
            'all_records' => [$row]
        ];
    } else {
        $pesertaGrouped[$nama_key]['ids'][] = $row['id'];
        $pesertaGrouped[$nama_key]['category_ids'][] = $row['category_id'];
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

                <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                <div class="pt-4">
                    <p class="px-4 text-xs font-semibold text-zinc-500 uppercase tracking-wider mb-2">System</p>
                    <a href="recovery.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800 transition-colors">
                        <i class="fas fa-trash-restore w-5"></i>
                        <span class="text-sm">Data Recovery</span>
                    </a>
                </div>
                <?php endif; ?>
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
                                <?php if (canInputScore()): ?>
                                <button onclick="openAddModal()"
                                   class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-archery-600 text-white text-sm font-medium hover:bg-archery-700 transition-colors shadow-sm">
                                    <i class="fas fa-user-plus"></i>
                                    <span class="hidden sm:inline">Tambah Peserta</span>
                                </button>
                                <?php endif; ?>
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
                                   onclick="event.preventDefault(); const url = this.href; showConfirmModal('Export Data', 'Download data peserta ke Excel (.xlsx)?', () => window.location.href = url, 'info')">
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

                                                    <?php if (isAdmin() && count($group['ids']) > 1): ?>
                                                        <button type="button" onclick="openMergeModal(<?= htmlspecialchars(json_encode($group)) ?>)"
                                                                class="p-1.5 rounded-lg text-indigo-600 dark:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-900/30 transition-colors" title="Gabungkan Duplikat">
                                                            <i class="fas fa-compress-alt"></i>
                                                        </button>
                                                    <?php endif; ?>

                                                    <?php if (canInputScore()): ?>
                                                    <button type="button" onclick="editPeserta(<?= htmlspecialchars(json_encode($p)) ?>)"
                                                            class="p-1.5 rounded-lg text-amber-600 dark:text-amber-400 hover:bg-amber-50 dark:hover:bg-amber-900/30 transition-colors" title="Edit">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <?php endif; ?>
                                                    <?php if (isAdmin()): ?>
                                                    <button type="button" onclick="confirmDelete(<?= $p['id'] ?>, '<?= htmlspecialchars($nama, ENT_QUOTES) ?>')"
                                                            class="p-1.5 rounded-lg text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/30 transition-colors" title="Hapus">
                                                        <i class="fas fa-trash-alt"></i>
                                                    </button>
                                                    <?php endif; ?>
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
                            <input type="text" id="add_tanggal_lahir" name="tanggal_lahir" class="datepicker w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-slate-900 dark:text-white text-sm" placeholder="Pilih Tanggal (Opsional)" onchange="updateKategoriOptions('add')">
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

                <input type="hidden" name="old_nama_peserta" id="edit_old_name">

                <div class="p-6 overflow-y-auto custom-scrollbar flex-1 space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-zinc-300 mb-1">Nama Peserta <span class="text-red-500">*</span></label>
                            <input type="text" class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-slate-900 dark:text-white text-sm" name="nama_peserta" id="edit_nama_peserta" required>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-zinc-300 mb-1">Kegiatan <span class="text-red-500">*</span></label>
                            <select class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-slate-900 dark:text-white text-sm" name="kegiatan_id" id="edit_kegiatan_id" onchange="loadEditCategories()" required>
                                <?php foreach ($kegiatanList as $keg): ?>
                                    <option value="<?= $keg['id'] ?>"><?= htmlspecialchars($keg['nama_kegiatan']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-zinc-300 mb-1">Tanggal Lahir <span class="text-red-500">*</span></label>
                            <input type="text" class="datepicker w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-slate-900 dark:text-white text-sm" name="tanggal_lahir" id="edit_tanggal_lahir" placeholder="Pilih Tanggal (Opsional)" onchange="updateKategoriOptions('edit')">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-zinc-300 mb-1">Jenis Kelamin <span class="text-red-500">*</span></label>
                            <select class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-slate-900 dark:text-white text-sm" name="jenis_kelamin" id="edit_jenis_kelamin" onchange="updateKategoriOptions('edit')" required>
                                <option value="Laki-laki">Laki-laki</option>
                                <option value="Perempuan">Perempuan</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-zinc-300 mb-1">Nomor HP</label>
                            <input type="text" class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-slate-900 dark:text-white text-sm" name="nomor_hp" id="edit_nomor_hp">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-zinc-300 mb-1">Asal Kota</label>
                            <input type="text" class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-slate-900 dark:text-white text-sm" name="asal_kota" id="edit_asal_kota">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-zinc-300 mb-1">Nama Club</label>
                            <input type="text" class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-slate-900 dark:text-white text-sm" name="nama_club" id="edit_nama_club">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-zinc-300 mb-1">Sekolah</label>
                            <input type="text" class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-slate-900 dark:text-white text-sm" name="sekolah" id="edit_sekolah">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-zinc-300 mb-1">Kelas</label>
                            <input type="text" class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-slate-900 dark:text-white text-sm" name="kelas" id="edit_kelas">
                        </div>
                    </div>

                    <div class="pt-4 border-t border-slate-100 dark:border-zinc-800">
                        <label class="block text-sm font-medium text-slate-700 dark:text-zinc-300 mb-2">Pilih Kategori <span class="text-red-500">*</span></label>
                        <div id="edit_categories_list" class="grid grid-cols-1 sm:grid-cols-2 gap-3 max-h-48 overflow-y-auto custom-scrollbar p-1">
                            <!-- Populated by JS -->
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

    <!-- Merge Modal -->
    <div id="mergeModal" class="fixed inset-0 z-50 hidden">
        <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeMergeModal()"></div>
        <div class="absolute inset-4 sm:inset-auto sm:top-1/2 sm:left-1/2 sm:-translate-x-1/2 sm:-translate-y-1/2 sm:w-full sm:max-w-2xl bg-white dark:bg-zinc-900 rounded-2xl shadow-xl overflow-hidden max-h-[90vh] flex flex-col transition-colors">
            <div class="bg-gradient-to-br from-indigo-600 to-indigo-800 text-white px-6 py-4 flex items-center justify-between flex-shrink-0">
                <div class="flex items-center gap-2">
                    <i class="fas fa-compress-alt"></i>
                    <h3 class="font-semibold text-lg">Gabungkan Data Duplikat</h3>
                </div>
                <button onclick="closeMergeModal()" class="p-2 rounded-lg hover:bg-white/10 transition-colors">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form action="" method="post" class="flex flex-col flex-1 overflow-hidden" onsubmit="return validateMerge()">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="merge">
                
                <div class="p-6 overflow-y-auto custom-scrollbar flex-1 bg-slate-50 dark:bg-zinc-950">
                    <div class="bg-amber-50 dark:bg-amber-900/30 border border-amber-200 dark:border-amber-800 rounded-xl p-4 mb-6">
                        <div class="flex gap-3">
                            <i class="fas fa-exclamation-triangle text-amber-600 dark:text-amber-400 mt-1"></i>
                            <div class="text-sm">
                                <p class="font-bold text-amber-800 dark:text-amber-300">Konfirmasi Penggabungan</p>
                                <ul class="list-disc list-inside text-amber-700 dark:text-amber-400 text-xs mt-1 space-y-1">
                                    <li>Data dengan kategori yang <strong>sama</strong> akan disatukan (skor dipindah, duplikat dihapus).</li>
                                    <li>Data dengan kategori <strong>berbeda</strong> akan <strong>disimpan</strong> & diubah namanya mengikuti Data Utama (tidak dihapus).</li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <h4 class="text-sm font-semibold text-slate-700 dark:text-zinc-300 mb-3">Pilih Data Utama (Master)</h4>
                    <p class="text-xs text-slate-500 mb-4 items-list-hint">Data ini akan menjadi satu-satunya yang tersimpan setelah penggabungan.</p>
                    <div id="merge_items_list" class="space-y-3 mb-6">
                        <!-- Populated by JS -->
                    </div>

                    <div class="pt-6 border-t border-slate-200 dark:border-zinc-800">
                        <h4 class="text-sm font-semibold text-slate-700 dark:text-zinc-300 mb-3">Cari & Tambah Peserta Lain</h4>
                        <div class="relative mb-4">
                            <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"></i>
                            <input type="text" id="merge_search_input" 
                                   class="w-full pl-10 pr-4 py-2 rounded-lg border border-slate-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-indigo-500 transition-all" 
                                   placeholder="Cari nama atau club untuk digabungkan..."
                                   onkeyup="debounce(searchMergeParticipants, 500)()">
                        </div>
                        <div id="merge_search_results" class="space-y-2 max-h-48 overflow-y-auto custom-scrollbar">
                            <!-- Results will appear here -->
                        </div>
                    </div>
                </div>

                <div class="px-6 py-4 bg-white dark:bg-zinc-900 border-t border-slate-200 dark:border-zinc-800 flex justify-end gap-2 flex-shrink-0">
                    <button type="button" onclick="closeMergeModal()" class="px-4 py-2 rounded-lg border border-slate-300 dark:border-zinc-600 text-slate-700 dark:text-zinc-300 text-sm font-medium hover:bg-slate-100 dark:hover:bg-zinc-700 transition-colors">Batal</button>
                    <button type="submit" class="px-4 py-2 rounded-lg bg-indigo-600 text-white text-sm font-medium hover:bg-indigo-700 transition-colors shadow-lg">Gabungkan Sekarang</button>
                </div>
            </form>
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
                <a href="peserta.view.php" class="flex items-center gap-3 px-4 py-3 rounded-lg bg-archery-600/20 text-archery-400 border border-archery-600/30">
                    <i class="fas fa-user-friends w-5"></i><span class="text-sm font-medium">Peserta</span>
                </a>
                <a href="statistik.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800 transition-colors">
                    <i class="fas fa-chart-bar w-5"></i><span class="text-sm">Statistik</span>
                </a>
            </div>

            <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
            <div class="pt-4">
                <p class="px-4 text-xs font-semibold text-zinc-500 uppercase tracking-wider mb-2">System</p>
                <a href="recovery.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800 transition-colors">
                    <i class="fas fa-trash-restore w-5"></i>
                    <span class="text-sm">Data Recovery</span>
                </a>
            </div>
            <?php endif; ?>
        </nav>
        <div class="px-4 py-4 border-t border-zinc-800 mt-auto">
            <a href="../actions/logout.php" onclick="event.preventDefault(); const url = this.href; showConfirmModal('Logout', 'Yakin ingin logout?', () => window.location.href = url, 'danger')"
               class="flex items-center gap-2 w-full px-4 py-2 rounded-lg text-red-400 hover:bg-red-500/10 transition-colors text-sm">
                <i class="fas fa-sign-out-alt w-5"></i>
                <span>Logout</span>
            </a>
        </div>
    </div>

    <?= getConfirmationModal() ?>
    <script>

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
        let currentEditingCategoryIds = [];

        function loadCategories(prefix) {
            const kegiatanId = document.getElementById(prefix + '_kegiatan_id').value;
            if (!kegiatanId) {
                allCategories = [];
                updateKategoriOptions(prefix);
                return;
            }

            fetch(`?action=get_categories&kegiatan_id=${kegiatanId}`)
                .then(res => res.json())
                .then(data => {
                    allCategories = data;
                    updateKategoriOptions(prefix);
                });
        }

        // Keep old name for backward compatibility if needed, but point to new function
        function loadKegiatanCategories() { loadCategories('add'); }
        function loadEditCategories() { loadCategories('edit'); }

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
                // Sync flatpickr if available
                if (document.getElementById(prefix + '_tanggal_lahir')._flatpickr) {
                    document.getElementById(prefix + '_tanggal_lahir')._flatpickr.setDate(data.tanggal_lahir);
                }
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
            let gender = '';
            
            if (prefix === 'add') {
                gender = document.querySelector('input[name="jenis_kelamin"]:checked')?.value;
            } else {
                gender = document.getElementById('edit_jenis_kelamin').value;
            }

            // Fallback for flatpickr value if needed (get directly from input or flatpickr instance)
            const birthDateValue = dob || document.getElementById(prefix + '_tanggal_lahir')._flatpickr?.input.value;
            const container = document.getElementById(prefix + '_categories_list');
            
            if (!birthDateValue || !gender || allCategories.length === 0) {
                if (container) container.innerHTML = '<div class="col-span-2 text-center py-4 text-slate-400 dark:text-zinc-500 text-sm">Silakan pilih kegiatan, tanggal lahir, dan jenis kelamin dahulu.</div>';
                return;
            }

            const birthDate = new Date(birthDateValue);
            if (isNaN(birthDate.getTime())) {
                if (container) container.innerHTML = '<div class="col-span-2 text-center py-4 text-red-400 text-sm italic">Format tanggal lahir tidak valid.</div>';
                return;
            }

            const birthYear = birthDate.getFullYear();
            const currentYear = new Date().getFullYear();
            const age = currentYear - birthYear;

            container.innerHTML = allCategories.map(c => {
                const ageMatch = age >= c.min_age && age <= c.max_age;
                const genderMatch = c.gender === 'Campuran' || c.gender === gender;
                const isEligible = ageMatch && genderMatch;
                
                let reason = '';
                if (!ageMatch) reason = `Umur ${age} th tidak cocok (${c.min_age}-${c.max_age} th)`;
                else if (!genderMatch) reason = `Hanya untuk ${c.gender}`;

                const isChecked = prefix === 'edit' && currentEditingCategoryIds.includes(parseInt(c.id));

                return `
                    <label class="flex items-start gap-3 p-3 rounded-xl border ${isEligible ? 'border-slate-200 dark:border-zinc-800 hover:bg-slate-50 dark:hover:bg-zinc-800 cursor-pointer' : 'border-slate-100 dark:border-zinc-800/50 bg-slate-50/50 dark:bg-zinc-900/30 opacity-60 cursor-not-allowed'} transition-colors">
                        <input type="checkbox" name="category_ids[]" value="${c.id}" ${isEligible ? '' : 'disabled'} ${isChecked ? 'checked' : ''} class="mt-1 rounded ${isEligible ? 'text-archery-600 focus:ring-archery-500' : 'text-slate-300'}">
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
            // Handle both single row and group data
            const mainData = data.data || data;
            const ids = data.ids || [data.id];
            currentEditingCategoryIds = data.category_ids || [data.category_id];

            document.getElementById('edit_id').value = mainData.id;
            document.getElementById('edit_old_name').value = mainData.nama_peserta || '';
            document.getElementById('edit_nama_peserta').value = mainData.nama_peserta || '';
            document.getElementById('edit_kegiatan_id').value = mainData.kegiatan_id || '';
            document.getElementById('edit_tanggal_lahir').value = mainData.tanggal_lahir || '';
            // Sync flatpickr if available
            if (document.getElementById('edit_tanggal_lahir')._flatpickr) {
                document.getElementById('edit_tanggal_lahir')._flatpickr.setDate(mainData.tanggal_lahir || '');
            }
            document.getElementById('edit_jenis_kelamin').value = mainData.jenis_kelamin || '';
            document.getElementById('edit_asal_kota').value = mainData.asal_kota || '';
            document.getElementById('edit_nama_club').value = mainData.nama_club || '';
            document.getElementById('edit_nomor_hp').value = mainData.nomor_hp || '';
            document.getElementById('edit_sekolah').value = mainData.sekolah || '';
            document.getElementById('edit_kelas').value = mainData.kelas || '';

            loadCategories('edit');
            
            closeDetailModal();
            document.getElementById('editModal').classList.remove('hidden');
        }

        function closeEditModal() { document.getElementById('editModal').classList.add('hidden'); }

        function openMergeModal(group) {
            const list = document.getElementById('merge_items_list');
            list.innerHTML = '';
            document.getElementById('merge_search_input').value = '';
            document.getElementById('merge_search_results').innerHTML = '';
            
            // Initialize with current group data
            window.currentMergeGroup = JSON.parse(JSON.stringify(group)); // Deep copy
            
            // Render existing items
            window.currentMergeGroup.all_records.forEach((record, index) => {
                renderMergeItem(record, index === 0);
            });

            updateMergeSelection();
            document.getElementById('mergeModal').classList.remove('hidden');
        }

        function renderMergeItem(record, isChecked = false) {
            // Check if already exists to prevent duplicate rendering
            const list = document.getElementById('merge_items_list');
            if (list.querySelector(`input[value="${record.id}"]`)) return;

            const item = document.createElement('div');
            item.className = 'bg-white dark:bg-zinc-800 border border-slate-200 dark:border-zinc-700 rounded-xl p-4 cursor-pointer hover:border-indigo-500 dark:hover:border-indigo-400 transition-all relative group/item';
            
            item.onclick = (e) => {
                const radio = item.querySelector('input[type="radio"]');
                radio.checked = true;
                updateMergeSelection();
            };

            const statusClass = record.nama_kegiatan ? 'bg-indigo-100 dark:bg-indigo-900/30 text-indigo-700 dark:text-indigo-400' : 'bg-slate-100 dark:bg-zinc-800 text-slate-500';

            item.innerHTML = `
                <div class="flex items-start gap-3">
                    <div class="mt-1">
                        <input type="radio" name="master_id" value="${record.id}" ${isChecked ? 'checked' : ''} 
                               class="w-4 h-4 text-indigo-600 focus:ring-indigo-500 border-slate-300 dark:border-zinc-600 dark:bg-zinc-800"
                               onclick="event.stopPropagation(); updateMergeSelection();">
                    </div>
                    <div class="flex-1">
                        <div class="flex items-center justify-between mb-1">
                            <div>
                                <span class="text-sm font-bold text-slate-900 dark:text-white">ID: ${record.id}</span>
                                <span class="ml-2 text-xs text-slate-500 dark:text-zinc-500 font-normal">(${record.nama_peserta})</span>
                            </div>
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider ${statusClass}">
                                ${record.nama_kegiatan || 'Tanpa Kegiatan'}
                            </span>
                        </div>
                        <div class="grid grid-cols-2 gap-y-1 text-xs text-slate-500 dark:text-zinc-400">
                            <div><i class="fas fa-calendar-alt w-4"></i> ${record.tanggal_lahir || '-'}</div>
                            <div><i class="fas fa-user-tag w-4"></i> ${record.category_name || '-'}</div>
                            <div class="col-span-2"><i class="fas fa-university w-4"></i> ${record.nama_club || '-'}</div>
                        </div>
                    </div>
                    ${!window.currentMergeGroup.ids.includes(record.id) ? `
                        <button type="button" onclick="event.stopPropagation(); removeMergeItem(this, ${record.id})" 
                                class="opacity-0 group-hover/item:opacity-100 p-1 text-red-500 hover:text-red-700 transition-opacity">
                            <i class="fas fa-times"></i>
                        </button>
                    ` : ''}
                </div>
            `;
            list.appendChild(item);
        }

        function removeMergeItem(btn, id) {
            btn.closest('.group\\/item').remove();
            // Remove from currentMergeGroup.ids if explicitly added (logic can be refined if needed)
            updateMergeSelection();
        }

        function updateMergeSelection() {
            const items = document.querySelectorAll('#merge_items_list > div');
            items.forEach(item => {
                const radio = item.querySelector('input[type="radio"]');
                if (radio && radio.checked) {
                    item.classList.add('ring-2', 'ring-indigo-500', 'border-indigo-500');
                    item.classList.remove('border-slate-200', 'dark:border-zinc-700');
                } else {
                    item.classList.remove('ring-2', 'ring-indigo-500', 'border-indigo-500');
                    item.classList.add('border-slate-200', 'dark:border-zinc-700');
                }
            });
        }

        function closeMergeModal() {
            document.getElementById('mergeModal').classList.add('hidden');
        }

        // Debounce function
        function debounce(func, wait) {
            let timeout;
            return function(...args) {
                clearTimeout(timeout);
                timeout = setTimeout(() => func.apply(this, args), wait);
            };
        }

        function searchMergeParticipants() {
            const query = document.getElementById('merge_search_input').value;
            const resultsContainer = document.getElementById('merge_search_results');
            
            if (query.length < 2) {
                resultsContainer.innerHTML = '';
                return;
            }

            resultsContainer.innerHTML = '<div class="text-center py-2 text-sm text-slate-500"><i class="fas fa-spinner fa-spin mr-2"></i>Mencari...</div>';

            fetch(`?action=search_peserta&q=${encodeURIComponent(query)}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(response => response.json())
            .then(data => {
                resultsContainer.innerHTML = '';
                if (data.length === 0) {
                    resultsContainer.innerHTML = '<div class="text-center py-2 text-sm text-slate-500">Tidak ada hasil ditemukan.</div>';
                    return;
                }

                data.forEach(item => {
                    // Don't show if already in the list
                    if (document.querySelector(`#merge_items_list input[value="${item.id}"]`)) return;

                    const el = document.createElement('div');
                    el.className = 'flex items-center justify-between p-2 hover:bg-slate-100 dark:hover:bg-zinc-800 rounded-lg cursor-pointer transition-colors';
                    el.onclick = () => addKeyToMerge(item);
                    el.innerHTML = `
                        <div class="flex-1">
                            <div class="text-sm font-medium text-slate-900 dark:text-white">${item.nama_peserta}</div>
                            <div class="text-xs text-slate-500 dark:text-zinc-400">
                                ${item.nama_club || '-'} • ${item.category_name || '-'}
                            </div>
                        </div>
                        <button type="button" class="p-1.5 text-indigo-600 dark:text-indigo-400 bg-indigo-50 dark:bg-indigo-900/30 rounded-lg">
                            <i class="fas fa-plus text-xs"></i>
                        </button>
                    `;
                    resultsContainer.appendChild(el);
                });
            })
            .catch(error => {
                console.error('Error:', error);
                resultsContainer.innerHTML = '<div class="text-center py-2 text-sm text-red-500">Terjadi kesalahan.</div>';
            });
        }

        function addKeyToMerge(item) {
            renderMergeItem(item, false);
            // Optionally add to window.currentMergeGroup.ids if needed for validation logic
            // But validation now scrapes DOM so strictly not required to sync existing ID array
            document.getElementById('merge_search_results').innerHTML = ''; // Clear results
            document.getElementById('merge_search_input').value = '';
        }

        function validateMerge() {
            const masterRadio = document.querySelector('input[name="master_id"]:checked');
            if (!masterRadio) {
                alert('Pilih satu data sebagai data utama.');
                return false;
            }

            const masterId = parseInt(masterRadio.value);
            
            // Gather all IDs from the list
            const allInputs = document.querySelectorAll('#merge_items_list input[name="master_id"]');
            const duplicateIds = [];
            
            allInputs.forEach(input => {
                const id = parseInt(input.value);
                if (id !== masterId) {
                    duplicateIds.push(id);
                }
            });

            if (duplicateIds.length === 0) {
                alert('Tidak ada duplikat yang bisa digabungkan.');
                return false;
            }

            const form = document.querySelector('#mergeModal form');
            form.querySelectorAll('input[name="duplicate_ids[]"]').forEach(el => el.remove());

            duplicateIds.forEach(id => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'duplicate_ids[]';
                input.value = id;
                form.appendChild(input);
            });

            return confirm('Yakin ingin menggabungkan data ini? Tindakan ini tidak dapat dibatalkan.');
        }

        function confirmDelete(id, name) {
            showConfirmModal(
                'Hapus Peserta',
                `Apakah Anda yakin ingin menghapus peserta <strong class="text-red-600 dark:text-red-400">${name}</strong>?<br><br><span class="text-sm text-slate-500 dark:text-zinc-400"><i class="fas fa-exclamation-triangle mr-1"></i> Data yang dihapus tidak dapat dikembalikan!</span>`,
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
    </script>
    <?= getUiScripts() ?>
</body>
</html>
<?php skip_post: ?>
