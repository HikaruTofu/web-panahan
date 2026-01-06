<?php
// Aktifkan error reporting untuk debuggin
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
include '../includes/check_access.php';
requireLogin();

// Mulai session jika belum
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// ============================================
// HANDLER UNTUK BRACKET TOURNAMENT (ADUAN)
// ============================================
if (isset($_GET['aduan']) && $_GET['aduan'] == 'true') {
    try {
        include '../config/panggil.php';
    } catch (Exception $e) {
        die("Error koneksi database: " . $e->getMessage());
    }

    $kegiatan_id = isset($_GET['kegiatan_id']) ? intval($_GET['kegiatan_id']) : null;
    $category_id = isset($_GET['category_id']) ? intval($_GET['category_id']) : null;
    $scoreboard_id = isset($_GET['scoreboard']) ? intval($_GET['scoreboard']) : null;

    if (!$kegiatan_id || !$category_id || !$scoreboard_id) {
        die("Parameter tidak lengkap.");
    }

    // Handler untuk menyimpan hasil match
    if (isset($_POST['save_match_result'])) {
        header('Content-Type: application/json');

        $match_id = $_POST['match_id'] ?? '';
        $winner_id = intval($_POST['winner_id'] ?? 0);
        $loser_id = intval($_POST['loser_id'] ?? 0);
        $bracket_size = intval($_POST['bracket_size'] ?? 0);

        try {
            // Check if match result already exists
            $checkQuery = "SELECT id FROM bracket_matches WHERE kegiatan_id = ? AND category_id = ? AND scoreboard_id = ? AND match_id = ?";
            $checkStmt = $conn->prepare($checkQuery);
            $checkStmt->bind_param("iiis", $kegiatan_id, $category_id, $scoreboard_id, $match_id);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();

            if ($checkResult->num_rows > 0) {
                // Update existing record
                $updateQuery = "UPDATE bracket_matches SET winner_id = ?, loser_id = ?, updated_at = NOW() WHERE kegiatan_id = ? AND category_id = ? AND scoreboard_id = ? AND match_id = ?";
                $updateStmt = $conn->prepare($updateQuery);
                $updateStmt->bind_param("iiiiss", $winner_id, $loser_id, $kegiatan_id, $category_id, $scoreboard_id, $match_id);
                $updateStmt->execute();
                $updateStmt->close();
            } else {
                // Insert new record
                $insertQuery = "INSERT INTO bracket_matches (kegiatan_id, category_id, scoreboard_id, match_id, winner_id, loser_id, bracket_size, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
                $insertStmt = $conn->prepare($insertQuery);
                $insertStmt->bind_param("iiisiii", $kegiatan_id, $category_id, $scoreboard_id, $match_id, $winner_id, $loser_id, $bracket_size);
                $insertStmt->execute();
                $insertStmt->close();
            }

            $checkStmt->close();

            echo json_encode(['status' => 'success', 'message' => 'Match result saved']);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }

        $conn->close();
        exit;
    }

    // Handler untuk menyimpan champion
    if (isset($_POST['save_champion'])) {
        header('Content-Type: application/json');

        $champion_id = intval($_POST['champion_id'] ?? 0);
        $runner_up_id = intval($_POST['runner_up_id'] ?? 0);
        $third_place_id = !empty($_POST['third_place_id']) ? intval($_POST['third_place_id']) : null;
        $bracket_size = intval($_POST['bracket_size'] ?? 0);

        try {
            // Check if champion record already exists
            $checkQuery = "SELECT id FROM bracket_champions WHERE kegiatan_id = ? AND category_id = ? AND scoreboard_id = ?";
            $checkStmt = $conn->prepare($checkQuery);
            $checkStmt->bind_param("iii", $kegiatan_id, $category_id, $scoreboard_id);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();

            if ($checkResult->num_rows > 0) {
                // Update existing record
                if ($third_place_id !== null) {
                    $updateQuery = "UPDATE bracket_champions SET champion_id = ?, runner_up_id = ?, third_place_id = ?, bracket_size = ?, updated_at = NOW() WHERE kegiatan_id = ? AND category_id = ? AND scoreboard_id = ?";
                    $updateStmt = $conn->prepare($updateQuery);
                    $updateStmt->bind_param("iiiiii", $champion_id, $runner_up_id, $third_place_id, $bracket_size, $kegiatan_id, $category_id, $scoreboard_id);
                } else {
                    $updateQuery = "UPDATE bracket_champions SET champion_id = ?, runner_up_id = ?, bracket_size = ?, updated_at = NOW() WHERE kegiatan_id = ? AND category_id = ? AND scoreboard_id = ?";
                    $updateStmt = $conn->prepare($updateQuery);
                    $updateStmt->bind_param("iiiiii", $champion_id, $runner_up_id, $bracket_size, $kegiatan_id, $category_id, $scoreboard_id);
                }
                $updateStmt->execute();
                $updateStmt->close();
            } else {
                // Insert new record
                if ($third_place_id !== null) {
                    $insertQuery = "INSERT INTO bracket_champions (kegiatan_id, category_id, scoreboard_id, champion_id, runner_up_id, third_place_id, bracket_size, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
                    $insertStmt = $conn->prepare($insertQuery);
                    $insertStmt->bind_param("iiiiiii", $kegiatan_id, $category_id, $scoreboard_id, $champion_id, $runner_up_id, $third_place_id, $bracket_size);
                } else {
                    $insertQuery = "INSERT INTO bracket_champions (kegiatan_id, category_id, scoreboard_id, champion_id, runner_up_id, bracket_size, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())";
                    $insertStmt = $conn->prepare($insertQuery);
                    $insertStmt->bind_param("iiiiii", $kegiatan_id, $category_id, $scoreboard_id, $champion_id, $runner_up_id, $bracket_size);
                }
                $insertStmt->execute();
                $insertStmt->close();
            }

            $checkStmt->close();

            echo json_encode(['status' => 'success', 'message' => 'Champion saved']);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }

        $conn->close();
        exit;
    }

    // Ambil data kegiatan
    $kegiatanData = [];
    try {
        $queryKegiatan = "SELECT id, nama_kegiatan FROM kegiatan WHERE id = ?";
        $stmtKegiatan = $conn->prepare($queryKegiatan);
        $stmtKegiatan->bind_param("i", $kegiatan_id);
        $stmtKegiatan->execute();
        $resultKegiatan = $stmtKegiatan->get_result();

        if ($resultKegiatan->num_rows > 0) {
            $kegiatanData = $resultKegiatan->fetch_assoc();
        }
        $stmtKegiatan->close();
    } catch (Exception $e) {
        die("Error mengambil data kegiatan: " . $e->getMessage());
    }

    // Ambil data kategori
    $kategoriData = [];
    try {
        $queryKategori = "SELECT id, name FROM categories WHERE id = ?";
        $stmtKategori = $conn->prepare($queryKategori);
        $stmtKategori->bind_param("i", $category_id);
        $stmtKategori->execute();
        $resultKategori = $stmtKategori->get_result();

        if ($resultKategori->num_rows > 0) {
            $kategoriData = $resultKategori->fetch_assoc();
        }
        $stmtKategori->close();
    } catch (Exception $e) {
        die("Error mengambil data kategori: " . $e->getMessage());
    }

    // Ambil data peserta berdasarkan ranking
    $pesertaList = [];
    try {
        // Ambil data peserta berdasarkan ranking (OPTIMIZED)
        $pesertaList = [];
        try {
            $queryPeserta = "
            SELECT 
                p.id,
                p.nama_peserta,
                p.jenis_kelamin,
                COALESCE(SUM(
                    CASE 
                        WHEN LOWER(s.score) = 'x' THEN 10 
                        WHEN LOWER(s.score) = 'm' THEN 0 
                        ELSE CAST(s.score AS UNSIGNED) 
                    END
                ), 0) as total_score,
                COUNT(CASE WHEN LOWER(s.score) = 'x' THEN 1 END) as total_x
            FROM peserta p
            LEFT JOIN score s ON p.id = s.peserta_id 
                AND s.kegiatan_id = ? 
                AND s.category_id = ? 
                AND s.score_board_id = ?
            WHERE p.kegiatan_id = ? AND p.category_id = ?
            GROUP BY p.id, p.nama_peserta, p.jenis_kelamin
            ORDER BY total_score DESC, total_x DESC, p.nama_peserta ASC
        ";
            $stmtPeserta = $conn->prepare($queryPeserta);
            // Bind params: kegiatan_id, category_id, scoreboard_id (for JOIN), then kegiatan_id, category_id (for WHERE)
            $stmtPeserta->bind_param("iiiii", $kegiatan_id, $category_id, $scoreboard_id, $kegiatan_id, $category_id);
            $stmtPeserta->execute();
            $resultPeserta = $stmtPeserta->get_result();

            while ($row = $resultPeserta->fetch_assoc()) {
                $pesertaList[] = $row;
            }

            $stmtPeserta->close();
        } catch (Exception $e) {
            die("Error mengambil data peserta: " . $e->getMessage());
        }
    } catch (Exception $e) {
        die("Error mengambil data peserta: " . $e->getMessage());
    }

    $conn->close();
    ?>
    <!DOCTYPE html>
    <html lang="id" class="h-full">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Tournament Eliminasi / Aduan <?= htmlspecialchars($kategoriData['name']) ?></title>
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
            .custom-scrollbar::-webkit-scrollbar-track { background: rgba(255,255,255,0.05); border-radius: 3px; }
            .custom-scrollbar::-webkit-scrollbar-thumb { background: rgba(253,203,110,0.5); border-radius: 3px; }
            .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: rgba(253,203,110,0.7); }

            .player-card { transition: all 0.2s ease; }
            .player-card:hover:not(.empty) { transform: translateX(4px); }
            .player-card.winner { border-color: #16a34a !important; box-shadow: 0 0 20px rgba(22,163,74,0.4); }
            .player-card.empty { background: rgba(255,255,255,0.1) !important; cursor: default; }

            .size-btn.active { background: linear-gradient(135deg, #16a34a 0%, #22c55e 100%) !important; }

            @media print { .no-print { display: none !important; } }
        </style>
    </head>

    <body class="min-h-screen bg-gradient-to-br from-zinc-800 to-zinc-950 text-white p-4 md:p-6">
        <div class="max-w-7xl mx-auto">
            <!-- Back Button -->
            <a href="detail.php?id=<?= $kegiatan_id ?>"
               class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-white/10 text-white text-sm font-medium hover:bg-white/20 transition-colors mb-6 no-print">
                <i class="fas fa-arrow-left"></i> Kembali
            </a>

            <!-- Header -->
            <div class="bg-white/5 rounded-2xl p-6 mb-6 text-center border border-white/10">
                <div class="w-14 h-14 rounded-xl bg-amber-500/20 flex items-center justify-center mx-auto mb-3">
                    <i class="fas fa-sitemap text-amber-400 text-2xl"></i>
                </div>
                <h1 class="text-2xl font-bold text-white mb-1">Tournament Eliminasi / Aduan</h1>
                <h3 class="text-lg font-semibold text-amber-400"><?= htmlspecialchars($kategoriData['name']) ?></h3>
                <p class="text-sm text-slate-400 mt-1"><?= htmlspecialchars($kegiatanData['nama_kegiatan']) ?></p>
                <p class="text-sm text-cyan-400 mt-3">
                    <i class="fas fa-users mr-1"></i> Total Peserta: <?= count($pesertaList) ?> orang
                </p>
            </div>

            <!-- Setup Container -->
            <div class="bg-white/5 rounded-2xl p-8 text-center max-w-xl mx-auto border border-white/10" id="setupContainer">
                <h2 class="text-xl md:text-2xl font-bold text-white mb-6">Pilih Jumlah Peserta Eliminasi / Aduan</h2>

                <div class="flex flex-col sm:flex-row gap-4 justify-center mb-6">
                    <button class="size-btn px-10 py-5 rounded-xl text-2xl font-bold text-white cursor-pointer hover:-translate-y-1 hover:shadow-lg transition-all"
                            style="background: linear-gradient(135deg, #f59e0b 0%, #ea580c 100%);"
                            onclick="selectBracketSize(16)" id="size16">16</button>
                    <button class="size-btn px-10 py-5 rounded-xl text-2xl font-bold text-white cursor-pointer hover:-translate-y-1 hover:shadow-lg transition-all"
                            style="background: linear-gradient(135deg, #f59e0b 0%, #ea580c 100%);"
                            onclick="selectBracketSize(32)" id="size32">32</button>
                </div>

                <p class="text-sm text-slate-400 mb-6">
                    Pilih jumlah peserta untuk Eliminasi / Aduan
                </p>

                <button class="w-full sm:w-auto px-12 py-4 rounded-xl text-lg font-semibold text-white cursor-pointer hover:-translate-y-1 hover:shadow-lg transition-all disabled:opacity-50 disabled:cursor-not-allowed disabled:transform-none"
                        style="background: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%);"
                        id="startBracketBtn" onclick="startBracket()" disabled>
                    <i class="fas fa-trophy mr-2"></i> Masuk ke Eliminasi / Aduan
                </button>
            </div>

            <!-- Bracket Container -->
            <div class="hidden mt-8 overflow-x-auto custom-scrollbar p-4" id="bracketContainer">
                <div class="text-center mb-8 no-print">
                    <div class="flex flex-col sm:flex-row gap-3 justify-center">
                        <button class="px-8 py-3 rounded-xl text-base font-semibold text-white cursor-pointer hover:-translate-y-1 hover:shadow-lg transition-all"
                                style="background: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%);"
                                id="generateBtn" onclick="generateBracket()">
                            <i class="fas fa-random mr-2"></i> Generate & Acak Eliminasi / Aduan
                        </button>
                        <button class="px-6 py-3 rounded-xl text-base font-semibold text-white cursor-pointer hover:-translate-y-1 hover:shadow-lg transition-all"
                                style="background: linear-gradient(135deg, #52525b 0%, #27272a 100%);"
                                onclick="backToSetup()">
                            <i class="fas fa-arrow-left mr-2"></i> Kembali
                        </button>
                    </div>
                    <p class="text-sm text-slate-400 mt-4">
                        Klik tombol "Generate & Acak Eliminasi / Aduan" untuk mengacak posisi peserta secara random
                    </p>
                </div>

                <div id="bracketContent">
                    <!-- Bracket akan di-generate di sini -->
                </div>

                <!-- Third Place Section -->
                <div class="hidden bg-amber-900/20 border-2 border-amber-600/30 rounded-2xl p-6 mt-10 text-center max-w-lg mx-auto" id="thirdPlaceSection">
                    <div class="text-xl font-bold text-amber-500 mb-6 flex items-center justify-center gap-3">
                        <span>🥉</span>
                        <span>PEREBUTAN JUARA 3</span>
                        <span>🥉</span>
                    </div>
                    <div class="bg-black/20 rounded-xl p-5 inline-block min-w-[300px] md:min-w-[400px]">
                        <div id="thirdPlaceMatch">
                            <div class="match flex flex-col gap-2">
                                <div class="player-card empty px-4 py-3 rounded-lg text-sm font-semibold text-center text-slate-500">Menunggu Semifinal</div>
                                <div class="player-card empty px-4 py-3 rounded-lg text-sm font-semibold text-center text-slate-500">Menunggu Semifinal</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <script>
            const pesertaData = <?= json_encode($pesertaList) ?>;
            let selectedSize = 0;
            let shuffledPeserta = [];
            let bracketData = {};
            let semifinalLosers = [];

            function selectBracketSize(size) {
                selectedSize = size;

                document.querySelectorAll('.size-btn').forEach(btn => {
                    btn.classList.remove('active');
                });
                document.getElementById('size' + size).classList.add('active');

                document.getElementById('startBracketBtn').disabled = false;
            }

            function startBracket() {
                if (selectedSize === 0) {
                    alert('Pilih jumlah peserta terlebih dahulu!');
                    return;
                }

                if (pesertaData.length < 2) {
                    alert('Minimal 2 peserta diperlukan untuk membuat bracket!');
                    return;
                }

                document.getElementById('setupContainer').style.display = 'none';
                document.getElementById('bracketContainer').classList.remove('hidden');

                showPlaceholderBracket();
            }

            function backToSetup() {
                if (confirm('Kembali ke setup akan mereset semua data bracket. Lanjutkan?')) {
                    document.getElementById('setupContainer').style.display = 'block';
                    document.getElementById('bracketContainer').classList.add('hidden');

                    document.getElementById('bracketContent').innerHTML = '';
                    document.getElementById('thirdPlaceMatch').innerHTML = '';
                    document.getElementById('thirdPlaceSection').classList.add('hidden');
                    bracketData = {};
                    shuffledPeserta = [];
                    semifinalLosers = [];
                }
            }

            function showPlaceholderBracket() {
                if (selectedSize === 16) {
                    showPlaceholder16Bracket();
                } else {
                    showPlaceholder32Bracket();
                }
            }

            function showPlaceholder16Bracket() {
                const bracketHTML = `
                    <div class="flex justify-around gap-8 min-w-fit py-4">
                        <div class="flex flex-col justify-around min-h-[500px] flex-1">
                            <div class="text-center text-lg font-bold mb-4 text-amber-400">Round of 16</div>
                            ${generatePlaceholderMatches(8)}
                        </div>
                        <div class="flex flex-col justify-around min-h-[500px] flex-1">
                            <div class="text-center text-lg font-bold mb-4 text-amber-400">Quarter Finals</div>
                            ${generatePlaceholderMatches(4)}
                        </div>
                        <div class="flex flex-col justify-around min-h-[500px] flex-1">
                            <div class="text-center text-lg font-bold mb-4 text-amber-400">Semi Finals</div>
                            ${generatePlaceholderMatches(2)}
                        </div>
                        <div class="flex flex-col items-center justify-center min-h-[500px] flex-1">
                            <div class="text-center text-lg font-bold mb-4 text-amber-400">Finals</div>
                            <div class="text-[80px] my-5">🏆</div>
                            <div class="flex flex-col gap-1.5 my-2.5">
                                <div class="player-card empty px-4 py-3 rounded-lg min-w-[150px] max-w-[200px] font-semibold text-sm text-center text-slate-500">Finalist 1</div>
                                <div class="player-card empty px-4 py-3 rounded-lg min-w-[150px] max-w-[200px] font-semibold text-sm text-center text-slate-500">Finalist 2</div>
                            </div>
                            <div class="hidden mt-8 px-10 py-5 rounded-xl text-2xl font-bold text-slate-900 shadow-lg" style="background: linear-gradient(135deg, #ffd700 0%, #fde68a 100%);" id="champion">Champion</div>
                        </div>
                    </div>
                `;
                document.getElementById('bracketContent').innerHTML = bracketHTML;
            }

            function showPlaceholder32Bracket() {
                const bracketHTML = `
                    <div class="flex justify-around gap-8 min-w-fit py-4">
                        <div class="flex flex-col justify-around min-h-[500px] flex-1">
                            <div class="text-center text-lg font-bold mb-4 text-amber-400">Round of 32</div>
                            ${generatePlaceholderMatches(16)}
                        </div>
                        <div class="flex flex-col justify-around min-h-[500px] flex-1">
                            <div class="text-center text-lg font-bold mb-4 text-amber-400">Round of 16</div>
                            ${generatePlaceholderMatches(8)}
                        </div>
                        <div class="flex flex-col justify-around min-h-[500px] flex-1">
                            <div class="text-center text-lg font-bold mb-4 text-amber-400">Quarter Finals</div>
                            ${generatePlaceholderMatches(4)}
                        </div>
                        <div class="flex flex-col justify-around min-h-[500px] flex-1">
                            <div class="text-center text-lg font-bold mb-4 text-amber-400">Semi Finals</div>
                            ${generatePlaceholderMatches(2)}
                        </div>
                        <div class="flex flex-col items-center justify-center min-h-[500px] flex-1">
                            <div class="text-center text-lg font-bold mb-4 text-amber-400">Finals</div>
                            <div class="text-[80px] my-5">🏆</div>
                            <div class="flex flex-col gap-1.5 my-2.5">
                                <div class="player-card empty px-4 py-3 rounded-lg min-w-[150px] max-w-[200px] font-semibold text-sm text-center text-slate-500">Finalist 1</div>
                                <div class="player-card empty px-4 py-3 rounded-lg min-w-[150px] max-w-[200px] font-semibold text-sm text-center text-slate-500">Finalist 2</div>
                            </div>
                            <div class="hidden mt-8 px-10 py-5 rounded-xl text-2xl font-bold text-slate-900 shadow-lg" style="background: linear-gradient(135deg, #ffd700 0%, #fde68a 100%);" id="champion">Champion</div>
                        </div>
                    </div>
                `;
                document.getElementById('bracketContent').innerHTML = bracketHTML;
            }

            function generatePlaceholderMatches(numMatches) {
                let html = '';
                for (let i = 0; i < numMatches; i++) {
                    html += `
                        <div class="flex flex-col gap-1.5 my-2.5">
                            <div class="player-card empty px-4 py-3 rounded-lg min-w-[150px] max-w-[200px] font-semibold text-sm text-center text-slate-500">TBD</div>
                            <div class="player-card empty px-4 py-3 rounded-lg min-w-[150px] max-w-[200px] font-semibold text-sm text-center text-slate-500">TBD</div>
                        </div>
                    `;
                }
                return html;
            }

            function shuffleArray(array) {
                const newArray = [...array];
                for (let i = newArray.length - 1; i > 0; i--) {
                    const j = Math.floor(Math.random() * (i + 1));
                    [newArray[i], newArray[j]] = [newArray[j], newArray[i]];
                }
                return newArray;
            }

            function generateBracket() {
                if (selectedSize === 0) {
                    alert('Pilih jumlah peserta terlebih dahulu!');
                    return;
                }

                if (pesertaData.length < 2) {
                    alert('Minimal 2 peserta diperlukan untuk membuat bracket!');
                    return;
                }

                shuffledPeserta = shuffleArray(pesertaData).slice(0, selectedSize);

                while (shuffledPeserta.length < selectedSize) {
                    shuffledPeserta.push({ id: null, nama_peserta: 'BYE', empty: true });
                }

                bracketData = {};
                semifinalLosers = [];
                shuffledPeserta.forEach((player, index) => {
                    bracketData[index] = {
                        player: player,
                        round: 1,
                        position: index
                    };
                });

                if (selectedSize === 16) {
                    generate16Bracket();
                } else {
                    generate32Bracket();
                }

                document.getElementById('thirdPlaceSection').classList.remove('hidden');
            }

            function generate16Bracket() {
                const bracketHTML = `
                    <div class="flex justify-around gap-8 min-w-fit py-4">
                        <div class="flex flex-col justify-around min-h-[500px] flex-1">
                            <div class="text-center text-lg font-bold mb-4 text-amber-400">Round of 16</div>
                            ${generateMatches(0, 16, 1, 'r16')}
                        </div>
                        <div class="flex flex-col justify-around min-h-[500px] flex-1">
                            <div class="text-center text-lg font-bold mb-4 text-amber-400">Quarter Finals</div>
                            ${generateEmptyMatches(4, 2, 'qf')}
                        </div>
                        <div class="flex flex-col justify-around min-h-[500px] flex-1">
                            <div class="text-center text-lg font-bold mb-4 text-amber-400">Semi Finals</div>
                            ${generateEmptyMatches(2, 3, 'sf')}
                        </div>
                        <div class="flex flex-col items-center justify-center min-h-[500px] flex-1">
                            <div class="text-center text-lg font-bold mb-4 text-amber-400">Finals</div>
                            <div class="text-[80px] my-5">🏆</div>
                            <div class="flex flex-col gap-1.5 my-2.5" data-match="final">
                                <div class="player-card empty px-4 py-3 rounded-lg min-w-[150px] max-w-[200px] font-semibold text-sm text-center text-slate-500 border-2 border-transparent" data-slot="final-1">Finalist 1</div>
                                <div class="player-card empty px-4 py-3 rounded-lg min-w-[150px] max-w-[200px] font-semibold text-sm text-center text-slate-500 border-2 border-transparent" data-slot="final-2">Finalist 2</div>
                            </div>
                            <div class="hidden mt-8 px-10 py-5 rounded-xl text-2xl font-bold text-slate-900 shadow-lg" style="background: linear-gradient(135deg, #ffd700 0%, #fde68a 100%);" id="champion">Champion</div>
                        </div>
                    </div>
                `;
                document.getElementById('bracketContent').innerHTML = bracketHTML;
            }

            function generate32Bracket() {
                const bracketHTML = `
                    <div class="flex justify-around gap-8 min-w-fit py-4">
                        <div class="flex flex-col justify-around min-h-[500px] flex-1">
                            <div class="text-center text-lg font-bold mb-4 text-amber-400">Round of 32</div>
                            ${generateMatches(0, 32, 1, 'r32')}
                        </div>
                        <div class="flex flex-col justify-around min-h-[500px] flex-1">
                            <div class="text-center text-lg font-bold mb-4 text-amber-400">Round of 16</div>
                            ${generateEmptyMatches(8, 2, 'r16')}
                        </div>
                        <div class="flex flex-col justify-around min-h-[500px] flex-1">
                            <div class="text-center text-lg font-bold mb-4 text-amber-400">Quarter Finals</div>
                            ${generateEmptyMatches(4, 3, 'qf')}
                        </div>
                        <div class="flex flex-col justify-around min-h-[500px] flex-1">
                            <div class="text-center text-lg font-bold mb-4 text-amber-400">Semi Finals</div>
                            ${generateEmptyMatches(2, 4, 'sf')}
                        </div>
                        <div class="flex flex-col items-center justify-center min-h-[500px] flex-1">
                            <div class="text-center text-lg font-bold mb-4 text-amber-400">Finals</div>
                            <div class="text-[80px] my-5">🏆</div>
                            <div class="flex flex-col gap-1.5 my-2.5" data-match="final">
                                <div class="player-card empty px-4 py-3 rounded-lg min-w-[150px] max-w-[200px] font-semibold text-sm text-center text-slate-500 border-2 border-transparent" data-slot="final-1">Finalist 1</div>
                                <div class="player-card empty px-4 py-3 rounded-lg min-w-[150px] max-w-[200px] font-semibold text-sm text-center text-slate-500 border-2 border-transparent" data-slot="final-2">Finalist 2</div>
                            </div>
                            <div class="hidden mt-8 px-10 py-5 rounded-xl text-2xl font-bold text-slate-900 shadow-lg" style="background: linear-gradient(135deg, #ffd700 0%, #fde68a 100%);" id="champion">Champion</div>
                        </div>
                    </div>
                `;
                document.getElementById('bracketContent').innerHTML = bracketHTML;
            }

            function generateMatches(start, end, round, prefix) {
                let html = '';
                let matchIndex = 0;

                for (let i = start; i < end; i += 2) {
                    const player1 = shuffledPeserta[i];
                    const player2 = shuffledPeserta[i + 1];
                    const matchId = `${prefix}-m${matchIndex}`;

                    html += `
                        <div class="flex flex-col gap-1.5 my-2.5" data-match="${matchId}">
                            <div class="player-card ${player1.empty ? 'empty' : ''} px-4 py-3 rounded-lg min-w-[150px] max-w-[200px] font-semibold text-sm text-center text-white border-2 border-transparent break-words cursor-pointer"
                                 style="${player1.empty ? '' : 'background: linear-gradient(135deg, #f59e0b 0%, #ef4444 100%);'}"
                                 data-slot="${matchId}-1"
                                 data-player-index="${i}"
                                 data-player-id="${player1.id || ''}"
                                 onclick="${player1.empty ? '' : `selectWinner('${matchId}', 1, ${i})`}">
                                ${player1.nama_peserta}
                            </div>
                            <div class="player-card ${player2.empty ? 'empty' : ''} px-4 py-3 rounded-lg min-w-[150px] max-w-[200px] font-semibold text-sm text-center text-white border-2 border-transparent break-words cursor-pointer"
                                 style="${player2.empty ? '' : 'background: linear-gradient(135deg, #f59e0b 0%, #ef4444 100%);'}"
                                 data-slot="${matchId}-2"
                                 data-player-index="${i + 1}"
                                 data-player-id="${player2.id || ''}"
                                 onclick="${player2.empty ? '' : `selectWinner('${matchId}', 2, ${i + 1})`}">
                                ${player2.nama_peserta}
                            </div>
                        </div>
                    `;
                    matchIndex++;
                }
                return html;
            }

            function generateEmptyMatches(count, round, prefix) {
                let html = '';
                for (let i = 0; i < count; i++) {
                    const matchId = `${prefix}-m${i}`;
                    html += `
                        <div class="flex flex-col gap-1.5 my-2.5" data-match="${matchId}">
                            <div class="player-card empty px-4 py-3 rounded-lg min-w-[150px] max-w-[200px] font-semibold text-sm text-center text-slate-500 border-2 border-transparent" data-slot="${matchId}-1">TBD</div>
                            <div class="player-card empty px-4 py-3 rounded-lg min-w-[150px] max-w-[200px] font-semibold text-sm text-center text-slate-500 border-2 border-transparent" data-slot="${matchId}-2">TBD</div>
                        </div>
                    `;
                }
                return html;
            }

            function selectWinner(matchId, slot, playerIndex) {
                const player = shuffledPeserta[playerIndex];

                if (player.empty) return;

                const matchElement = document.querySelector(`[data-match="${matchId}"]`);
                const player1Element = matchElement.querySelector(`[data-slot="${matchId}-1"]`);
                const player2Element = matchElement.querySelector(`[data-slot="${matchId}-2"]`);

                player1Element.classList.remove('winner');
                player2Element.classList.remove('winner');

                const winnerElement = slot === 1 ? player1Element : player2Element;
                winnerElement.classList.add('winner');

                advanceWinner(matchId, player, playerIndex);
            }

            function selectWinnerNext(matchId, slot) {
                const matchElement = document.querySelector(`[data-match="${matchId}"]`);
                const slotElement = matchElement.querySelector(`[data-slot="${matchId}-${slot}"]`);

                if (slotElement.classList.contains('empty')) {
                    alert('Pemain belum ditentukan untuk slot ini!');
                    return;
                }

                const player1Element = matchElement.querySelector(`[data-slot="${matchId}-1"]`);
                const player2Element = matchElement.querySelector(`[data-slot="${matchId}-2"]`);
                player1Element.classList.remove('winner');
                player2Element.classList.remove('winner');

                slotElement.classList.add('winner');

                const playerName = slotElement.textContent.trim();
                const playerIndex = slotElement.getAttribute('data-player-index');
                const playerId = slotElement.getAttribute('data-player-id');

                if (playerIndex) {
                    const player = shuffledPeserta[parseInt(playerIndex)];
                    advanceWinner(matchId, player, parseInt(playerIndex));
                } else {
                    const player = {
                        id: playerId,
                        nama_peserta: playerName
                    };
                    advanceWinner(matchId, player, null);
                }
            }

            function advanceWinner(matchId, player, playerIndex) {
                let nextMatchId, nextSlot;

                if (matchId.startsWith('r16-m')) {
                    const matchNum = parseInt(matchId.split('m')[1]);
                    nextMatchId = `qf-m${Math.floor(matchNum / 2)}`;
                    nextSlot = (matchNum % 2) + 1;
                } else if (matchId.startsWith('qf-m')) {
                    const matchNum = parseInt(matchId.split('m')[1]);
                    nextMatchId = `sf-m${Math.floor(matchNum / 2)}`;
                    nextSlot = (matchNum % 2) + 1;
                } else if (matchId.startsWith('sf-m')) {
                    const matchNum = parseInt(matchId.split('m')[1]);

                    const matchElement = document.querySelector(`[data-match="${matchId}"]`);
                    const player1Element = matchElement.querySelector(`[data-slot="${matchId}-1"]`);
                    const player2Element = matchElement.querySelector(`[data-slot="${matchId}-2"]`);

                    const loserElement = player1Element.classList.contains('winner') ? player2Element : player1Element;
                    const loserName = loserElement.textContent.trim();
                    const loserId = loserElement.getAttribute('data-player-id');

                    if (loserName !== 'TBD' && !semifinalLosers.some(l => l.id === loserId)) {
                        semifinalLosers.push({
                            id: loserId,
                            nama_peserta: loserName,
                            index: loserElement.getAttribute('data-player-index')
                        });

                        updateThirdPlaceMatch();
                    }

                    nextMatchId = 'final';
                    nextSlot = matchNum + 1;
                } else if (matchId.startsWith('r32-m')) {
                    const matchNum = parseInt(matchId.split('m')[1]);
                    nextMatchId = `r16-m${Math.floor(matchNum / 2)}`;
                    nextSlot = (matchNum % 2) + 1;
                }

                if (nextMatchId) {
                    const nextSlotElement = document.querySelector(`[data-slot="${nextMatchId}-${nextSlot}"]`);
                    if (nextSlotElement) {
                        nextSlotElement.textContent = player.nama_peserta;
                        nextSlotElement.classList.remove('empty');
                        nextSlotElement.setAttribute('data-player-index', playerIndex !== null ? playerIndex : '');
                        nextSlotElement.setAttribute('data-player-id', player.id || '');

                        nextSlotElement.onclick = function () {
                            selectWinnerNext(nextMatchId, nextSlot);
                        };

                        if (nextMatchId === 'final') {
                            const finalMatch = document.querySelector(`[data-match="final"]`);
                            const finalist1 = finalMatch.querySelector(`[data-slot="final-1"]`);
                            const finalist2 = finalMatch.querySelector(`[data-slot="final-2"]`);

                            if (!finalist1.classList.contains('empty') && !finalist2.classList.contains('empty')) {
                                finalist1.onclick = function () {
                                    selectFinalWinner(1);
                                };
                                finalist2.onclick = function () {
                                    selectFinalWinner(2);
                                };
                            }
                        }
                    }
                }
            }

            function selectFinalWinner(slot) {
                const finalMatch = document.querySelector(`[data-match="final"]`);
                const finalist1 = finalMatch.querySelector(`[data-slot="final-1"]`);
                const finalist2 = finalMatch.querySelector(`[data-slot="final-2"]`);

                if (finalist1.classList.contains('empty') || finalist2.classList.contains('empty')) {
                    alert('Kedua finalist harus sudah ditentukan!');
                    return;
                }

                finalist1.classList.remove('winner');
                finalist2.classList.remove('winner');

                const winnerElement = slot === 1 ? finalist1 : finalist2;
                winnerElement.classList.add('winner');

                const championName = winnerElement.textContent.trim();
                declareChampion(championName);
            }

            function updateThirdPlaceMatch() {
                if (semifinalLosers.length === 2) {
                    const thirdPlaceMatch = document.getElementById('thirdPlaceMatch');
                    thirdPlaceMatch.innerHTML = `
                        <div class="flex flex-col gap-2" data-match="third-place">
                            <div class="player-card px-4 py-3 rounded-lg min-w-[150px] max-w-[200px] font-semibold text-sm text-center text-white border-2 border-transparent break-words cursor-pointer mx-auto"
                                 style="background: linear-gradient(135deg, #cd7f32 0%, #b87333 100%);"
                                 data-slot="third-1"
                                 data-player-id="${semifinalLosers[0].id}"
                                 data-player-index="${semifinalLosers[0].index}"
                                 onclick="selectThirdPlace(0)">
                                ${semifinalLosers[0].nama_peserta}
                            </div>
                            <div class="player-card px-4 py-3 rounded-lg min-w-[150px] max-w-[200px] font-semibold text-sm text-center text-white border-2 border-transparent break-words cursor-pointer mx-auto"
                                 style="background: linear-gradient(135deg, #cd7f32 0%, #b87333 100%);"
                                 data-slot="third-2"
                                 data-player-id="${semifinalLosers[1].id}"
                                 data-player-index="${semifinalLosers[1].index}"
                                 onclick="selectThirdPlace(1)">
                                ${semifinalLosers[1].nama_peserta}
                            </div>
                        </div>
                    `;

                    console.log('Third place match updated:', semifinalLosers);
                }
            }

            function selectThirdPlace(index) {
                const matchElement = document.querySelector(`[data-match="third-place"]`);
                if (!matchElement) {
                    alert('Match element tidak ditemukan!');
                    return;
                }

                const player1Element = matchElement.querySelector(`[data-slot="third-1"]`);
                const player2Element = matchElement.querySelector(`[data-slot="third-2"]`);

                if (!player1Element || !player2Element) {
                    alert('Player elements tidak ditemukan!');
                    return;
                }

                player1Element.classList.remove('winner');
                player2Element.classList.remove('winner');

                const winnerElement = index === 0 ? player1Element : player2Element;
                const loserElement = index === 0 ? player2Element : player1Element;
                winnerElement.classList.add('winner');

                const thirdPlaceWinner = semifinalLosers[index];
                const thirdPlaceLoser = semifinalLosers[index === 0 ? 1 : 0];

                // Save to database
                saveMatchResult('third-place', thirdPlaceWinner.id, thirdPlaceLoser.id);

                setTimeout(() => {
                    alert('🥉 Juara 3: ' + thirdPlaceWinner.nama_peserta + '\n\nSelamat atas pencapaian luar biasa!');
                }, 300);
            }

            function saveMatchResult(matchId, winnerId, loserId) {
                if (!winnerId || !loserId) {
                    console.log('Skipping save - missing IDs:', { matchId, winnerId, loserId });
                    return;
                }

                const formData = new FormData();
                formData.append('save_match_result', '1');
                formData.append('match_id', matchId);
                formData.append('winner_id', winnerId);
                formData.append('loser_id', loserId);
                formData.append('kegiatan_id', <?= $kegiatan_id ?>);
                formData.append('category_id', <?= $category_id ?>);
                formData.append('scoreboard_id', <?= $scoreboard_id ?>);
                formData.append('bracket_size', selectedSize);

                fetch('', {
                    method: 'POST',
                    body: formData
                })
                    .then(response => response.json())
                    .then(data => {
                        console.log('Match result saved:', data);
                    })
                    .catch(error => {
                        console.error('Error saving match result:', error);
                    });
            }

            function saveChampion(championId, runnerUpId, thirdPlaceId) {
                const formData = new FormData();
                formData.append('save_champion', '1');
                formData.append('champion_id', championId);
                formData.append('runner_up_id', runnerUpId);
                if (thirdPlaceId) {
                    formData.append('third_place_id', thirdPlaceId);
                }
                formData.append('kegiatan_id', <?= $kegiatan_id ?>);
                formData.append('category_id', <?= $category_id ?>);
                formData.append('scoreboard_id', <?= $scoreboard_id ?>);
                formData.append('bracket_size', selectedSize);

                fetch('', {
                    method: 'POST',
                    body: formData
                })
                    .then(response => response.json())
                    .then(data => {
                        console.log('Champion saved:', data);
                    })
                    .catch(error => {
                        console.error('Error saving champion:', error);
                    });
            }

            function declareChampion(championName) {
                const championElement = document.getElementById('champion');
                championElement.textContent = '🏆 ' + championName + ' 🏆';
                championElement.classList.remove('hidden');

                // Get champion and runner-up IDs
                const finalMatch = document.querySelector(`[data-match="final"]`);
                const finalist1 = finalMatch.querySelector(`[data-slot="final-1"]`);
                const finalist2 = finalMatch.querySelector(`[data-slot="final-2"]`);

                const championId = finalist1.classList.contains('winner') ?
                    finalist1.getAttribute('data-player-id') :
                    finalist2.getAttribute('data-player-id');

                const runnerUpId = finalist1.classList.contains('winner') ?
                    finalist2.getAttribute('data-player-id') :
                    finalist1.getAttribute('data-player-id');

                // Get third place if exists
                let thirdPlaceId = null;
                const thirdPlaceMatch = document.querySelector(`[data-match="third-place"]`);
                if (thirdPlaceMatch) {
                    const thirdWinner = thirdPlaceMatch.querySelector('.player-card.winner');
                    if (thirdWinner) {
                        thirdPlaceId = thirdWinner.getAttribute('data-player-id');
                    }
                }

                // Save to database
                saveMatchResult('final', championId, runnerUpId);
                saveChampion(championId, runnerUpId, thirdPlaceId);

                setTimeout(() => {
                    let message = '🎉 Selamat kepada juara: ' + championName + '! 🎉';
                    if (thirdPlaceId) {
                        const thirdPlaceName = semifinalLosers.find(p => p.id == thirdPlaceId)?.nama_peserta;
                        if (thirdPlaceName) {
                            const runnerUpName = finalist1.classList.contains('winner') ?
                                finalist2.textContent.trim() :
                                finalist1.textContent.trim();
                            message += '\n\n🥈 Juara 2: ' + runnerUpName;
                            message += '\n🥉 Juara 3: ' + thirdPlaceName;
                        }
                    }
                    alert(message);
                }, 500);
            }
        </script>
    </body>

    </html>
    <?php
    exit;
}


// ============================================
// HANDLER UNTUK SCORECARD SETUP
// ============================================
if (isset($_GET['action']) && $_GET['action'] == 'scorecard') {
    try {
        include '../config/panggil.php';
    } catch (Exception $e) {
        die("Error koneksi database: " . $e->getMessage());
    }

    $kegiatan_id = isset($_GET['kegiatan_id']) ? intval($_GET['kegiatan_id']) : null;
    $category_id = isset($_GET['category_id']) ? intval($_GET['category_id']) : null;

    if (!$kegiatan_id || !$category_id) {
        die("Parameter kegiatan_id dan category_id harus diisi.");
    }

    // Handler untuk get scores via AJAX
    if (isset($_GET['action']) && $_GET['action'] == 'get_scores') {
        header('Content-Type: application/json');

        $peserta_id = isset($_GET['peserta_id']) ? intval($_GET['peserta_id']) : 0;
        $kegiatan_id = isset($_GET['kegiatan_id']) ? intval($_GET['kegiatan_id']) : 0;
        $category_id = isset($_GET['category_id']) ? intval($_GET['category_id']) : 0;
        $scoreboard_id = isset($_GET['scoreboard']) ? intval($_GET['scoreboard']) : 0;

        $scores = [];

        try {
            $queryScores = "SELECT peserta_id, arrow, session, score 
                        FROM score 
                        WHERE kegiatan_id = ? 
                        AND category_id = ? 
                        AND score_board_id = ? 
                        AND peserta_id = ?
                        ORDER BY session ASC, arrow ASC";

            $stmtScores = $conn->prepare($queryScores);
            $stmtScores->bind_param("iiii", $kegiatan_id, $category_id, $scoreboard_id, $peserta_id);
            $stmtScores->execute();
            $resultScores = $stmtScores->get_result();

            while ($row = $resultScores->fetch_assoc()) {
                $scores[] = $row;
            }

            $stmtScores->close();

            echo json_encode($scores);
        } catch (Exception $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }

        $conn->close();
        exit;
    }

    $mysql_table_score_board = mysqli_query($conn, "SELECT * FROM score_boards WHERE kegiatan_id=" . $kegiatan_id . " AND category_id=" . $category_id . " ORDER BY created DESC");
    if (isset($_GET['scoreboard'])) {
        $mysql_data_score = mysqli_query($conn, "SELECT * FROM score WHERE kegiatan_id=" . $kegiatan_id . " AND category_id=" . $category_id . " AND score_board_id=" . $_GET['scoreboard'] . " ");
    }

    // Ambil data kegiatan
    $kegiatanData = [];
    try {
        $queryKegiatan = "SELECT id, nama_kegiatan FROM kegiatan WHERE id = ?";
        $stmtKegiatan = $conn->prepare($queryKegiatan);
        $stmtKegiatan->bind_param("i", $kegiatan_id);
        $stmtKegiatan->execute();
        $resultKegiatan = $stmtKegiatan->get_result();

        if ($resultKegiatan->num_rows > 0) {
            $kegiatanData = $resultKegiatan->fetch_assoc();
        } else {
            die("Kegiatan tidak ditemukan.");
        }
        $stmtKegiatan->close();
    } catch (Exception $e) {
        die("Error mengambil data kegiatan: " . $e->getMessage());
    }

    // Ambil data kategori
    $kategoriData = [];
    try {
        $queryKategori = "SELECT id, name FROM categories WHERE id = ?";
        $stmtKategori = $conn->prepare($queryKategori);
        $stmtKategori->bind_param("i", $category_id);
        $stmtKategori->execute();
        $resultKategori = $stmtKategori->get_result();

        if ($resultKategori->num_rows > 0) {
            $kategoriData = $resultKategori->fetch_assoc();
        } else {
            die("Kategori tidak ditemukan.");
        }
        $stmtKategori->close();
    } catch (Exception $e) {
        die("Error mengambil data kategori: " . $e->getMessage());
    }

    // Ambil data peserta berdasarkan kegiatan dan kategori
    $pesertaList = [];
    $peserta_score = [];
    try {
        $queryPeserta = "
            SELECT 
                p.id,
                p.nama_peserta,
                p.jenis_kelamin,
                c.name as category_name
            FROM peserta p
            LEFT JOIN categories c ON p.category_id = c.id
            WHERE p.kegiatan_id = ? AND p.category_id = ?
            ORDER BY p.nama_peserta ASC
        ";
        $stmtPeserta = $conn->prepare($queryPeserta);
        $stmtPeserta->bind_param("ii", $kegiatan_id, $category_id);
        $stmtPeserta->execute();
        $resultPeserta = $stmtPeserta->get_result();

        while ($row = $resultPeserta->fetch_assoc()) {
            $pesertaList[] = $row;
        }

        if (isset($_GET['scoreboard'])) {
            foreach ($pesertaList as $a) {
                $mysql_score_total = mysqli_query($conn, "SELECT * FROM score WHERE kegiatan_id=" . $kegiatan_id . " AND category_id=" . $category_id . " AND score_board_id =" . $_GET['scoreboard'] . " AND peserta_id=" . $a['id']);
                $score = 0;
                $x_score = 0;
                while ($b = mysqli_fetch_array($mysql_score_total)) {
                    if ($b['score'] == 'm') {
                        $score = $score + 0;
                    } else if ($b['score'] == 'x') {
                        $score = $score + 10;
                        $x_score = $x_score + 1;
                    } else {
                        $score = $score + (int) $b['score'];
                    }
                }
                $peserta_score[] = ['id' => $a['id'], 'total_score' => $score, 'total_x' => $x_score];
            }
        }

        $stmtPeserta->close();
    } catch (Exception $e) {
        die("Error mengambil data peserta: " . $e->getMessage());
    }

    if (isset($_POST['create'])) {
        $create_score_board = mysqli_query($conn, "INSERT INTO `score_boards` 
                                                    (`kegiatan_id`, `category_id`, `jumlah_sesi`, `jumlah_anak_panah`, `created`) 
                                                    VALUES 
                                                    ('" . $kegiatan_id . "', '" . $category_id . "', '" . $_POST['jumlahSesi'] . "', '" . $_POST['jumlahPanah'] . "', '" . $_POST['local_time'] . "');");
        header("Location: detail.php?action=scorecard&resource=index&kegiatan_id=" . $kegiatan_id . "&category_id=" . $category_id);
    }

    if (isset($_POST['save_score'])) {
        header("Content-Type: application/json; charset=UTF-8");

        $nama = !empty($_POST['nama']) ? $_POST['nama'] : "Anonim";
        $checkScore = mysqli_query($conn, "SELECT * FROM score WHERE kegiatan_id='" . $kegiatan_id . "' AND category_id='" . $category_id . "' AND score_board_id='" . $_GET['scoreboard'] . "' AND peserta_id='" . $_POST['peserta_id'] . "' AND arrow='" . $_POST['arrow'] . "' AND session='" . $_POST['session'] . "'");
        if (!$checkScore) {
            echo json_encode([
                "status" => "error",
                "message" => "Query Error: " . mysqli_error($conn)
            ]);
            exit;
        }
        $fetch_checkScore = mysqli_fetch_assoc($checkScore);

        if ($fetch_checkScore) {
            $message = "Score updated";
            if (empty($_POST['score'])) {
                $score = mysqli_query($conn, "DELETE FROM score WHERE id='" . $fetch_checkScore['id'] . "'");
            } else {
                $score = mysqli_query($conn, "UPDATE score SET score='" . $_POST['score'] . "' WHERE id='" . $fetch_checkScore['id'] . "'");
            }
        } else {
            if (!empty($_POST['score'])) {
                $score = mysqli_query($conn, "INSERT INTO `score` 
                                                    (`kegiatan_id`, `category_id`, `score_board_id`, `peserta_id`, `arrow`, `session`, `score`) 
                                                    VALUES 
                                                    ('" . $kegiatan_id . "', '" . $category_id . "', '" . $_GET['scoreboard'] . "', '" . $_POST['peserta_id'] . "', '" . $_POST['arrow'] . "','" . $_POST['session'] . "','" . $_POST['score'] . "');");
                $message = "Score added";
            } else {
                $message = "Empty score - no action";
            }
        }

        echo json_encode([
            "status" => "success",
            "message" => $message
        ]);
        exit;
    }

    if (isset($_GET['delete_score_board'])) {
        $delete_score_board = mysqli_query($conn, 'DELETE FROM `score_boards` WHERE `score_boards`.`id` =' . $_GET['delete_score_board']);
        header("Location: detail.php?action=scorecard&resource=index&kegiatan_id=" . $kegiatan_id . "&category_id=" . $category_id);
    }

    if (isset($_GET['scoreboard'])) {
        $sql_show_score_board = mysqli_query($conn, 'SELECT * FROM `score_boards` WHERE `score_boards`.`id` =' . $_GET['scoreboard']);
        $show_score_board = mysqli_fetch_assoc($sql_show_score_board);
    }

    $conn->close();

    // BAGIAN SCORECARD SETUP
    ?>
    <!DOCTYPE html>
    <html lang="id" class="h-full">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Setup Scorecard Panahan - <?= htmlspecialchars($kategoriData['name']) ?></title>
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
        <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <style>
            .custom-scrollbar::-webkit-scrollbar { width: 6px; height: 6px; }
            .custom-scrollbar::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 3px; }
            .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
            .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
            .dropdown-menu { display: none; }
            .dropdown-menu.show { display: block; }
            .hidden { display: none !important; }

            /* Score input styling */
            .arrow-input { transition: all 0.2s ease; }
            .arrow-input:focus { outline: none; box-shadow: 0 0 0 2px rgba(22, 163, 74, 0.3); }
            .arrow-input.score-x { background: #dcfce7 !important; border-color: #16a34a !important; color: #15803d !important; }
            .arrow-input.score-m { background: #fee2e2 !important; border-color: #dc2626 !important; color: #dc2626 !important; }
            .arrow-input.score-10 { background: #dcfce7 !important; border-color: #16a34a !important; color: #15803d !important; }
            .arrow-input.score-high { background: #fef3c7 !important; border-color: #f59e0b !important; color: #92400e !important; }
            .arrow-input.saving { border-color: #f59e0b !important; opacity: 0.7; }
            .arrow-input.saved { border-color: #16a34a !important; }
            .arrow-input.error { border-color: #dc2626 !important; }

            @media print {
                .no-print { display: none !important; }
            }
        </style>
    </head>
    <body class="h-full bg-slate-50">
        <div class="max-w-6xl mx-auto px-4 py-6">
            <?php if (isset($_GET['resource'])) { ?>
                <?php if ($_GET['resource'] == 'form') { ?>
                    <!-- Scorecard Setup Form -->
                    <a href="detail.php?action=scorecard&resource=index&kegiatan_id=<?= $kegiatan_id ?>&category_id=<?= $category_id ?>"
                       class="inline-flex items-center gap-2 text-archery-600 hover:text-archery-700 font-medium text-sm mb-6 no-print">
                        <i class="fas fa-arrow-left"></i> Kembali
                    </a>

                    <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
                        <div class="bg-gradient-to-br from-archery-600 to-archery-800 px-6 py-5 text-white text-center">
                            <div class="w-14 h-14 rounded-xl bg-white/20 flex items-center justify-center mx-auto mb-3">
                                <i class="fas fa-bullseye text-2xl"></i>
                            </div>
                            <h2 class="text-xl font-bold">Setup Scorecard</h2>
                            <p class="text-white/80 text-sm">Atur jumlah sesi dan anak panah</p>
                        </div>

                        <form action="" method="post" class="p-6">
                            <input type="hidden" id="local_time" name="local_time">

                            <div class="bg-archery-50 border border-archery-200 rounded-xl p-4 mb-6 text-center">
                                <p class="font-semibold text-archery-700"><?= htmlspecialchars($kategoriData['name']) ?></p>
                                <p class="text-sm text-slate-600"><?= htmlspecialchars($kegiatanData['nama_kegiatan']) ?></p>
                                <p class="text-lg font-bold text-amber-600 mt-2"><?= count($pesertaList) ?> Peserta Terdaftar</p>
                            </div>

                            <?php if (count($pesertaList) == 0): ?>
                                <div class="bg-amber-50 border border-amber-200 text-amber-700 rounded-lg p-4 mb-6">
                                    <i class="fas fa-exclamation-triangle mr-2"></i>
                                    <strong>Peringatan:</strong> Tidak ada peserta yang terdaftar dalam kategori ini.
                                </div>
                            <?php endif; ?>

                            <div class="space-y-5">
                                <div>
                                    <label for="jumlahSesi" class="block text-sm font-medium text-slate-700 mb-2">Jumlah Sesi</label>
                                    <input type="number" id="jumlahSesi" name="jumlahSesi" min="1" value="9"
                                           class="w-full px-4 py-3 rounded-lg border border-slate-300 text-center text-lg font-semibold focus:ring-2 focus:ring-archery-500 focus:border-archery-500">
                                </div>

                                <div>
                                    <label for="jumlahPanah" class="block text-sm font-medium text-slate-700 mb-2">Jumlah Anak Panah per Sesi</label>
                                    <input type="number" id="jumlahPanah" name="jumlahPanah" min="1" value="3"
                                           class="w-full px-4 py-3 rounded-lg border border-slate-300 text-center text-lg font-semibold focus:ring-2 focus:ring-archery-500 focus:border-archery-500">
                                </div>
                            </div>

                            <button type="submit" name="create"
                                    class="w-full mt-6 px-6 py-3 rounded-xl bg-archery-600 text-white font-semibold hover:bg-archery-700 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                                    <?= count($pesertaList) == 0 ? 'disabled' : '' ?>>
                                <i class="fas fa-plus mr-2"></i> Buat Scorecard
                            </button>
                        </form>
                    </div>
                <?php } ?>

                <?php if ($_GET['resource'] == 'index') { ?>
                    <?php if (!isset($_GET['scoreboard'])) { ?>
                        <!-- Scorecard List -->
                        <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
                            <div class="px-6 py-4 border-b border-slate-200 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                                <div class="flex items-center gap-3">
                                    <button onclick="goBack()" class="p-2 rounded-lg text-slate-500 hover:bg-slate-100 transition-colors no-print">
                                        <i class="fas fa-arrow-left"></i>
                                    </button>
                                    <div>
                                        <h2 class="font-semibold text-slate-900">Daftar Scorecard</h2>
                                        <p class="text-sm text-slate-500"><?= htmlspecialchars($kategoriData['name']) ?> - <?= htmlspecialchars($kegiatanData['nama_kegiatan']) ?></p>
                                    </div>
                                </div>
                                <div class="flex gap-2 no-print">
                                    <a href="detail.php?action=scorecard&resource=form&kegiatan_id=<?= $kegiatan_id ?>&category_id=<?= $category_id ?>"
                                       class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-archery-600 text-white text-sm font-medium hover:bg-archery-700 transition-colors">
                                        <i class="fas fa-plus"></i> Tambah
                                    </a>
                                    <button onclick="exportTableToExcel()" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-emerald-600 text-white text-sm font-medium hover:bg-emerald-700 transition-colors">
                                        <i class="fas fa-file-excel"></i> Export
                                    </button>
                                </div>
                            </div>

                            <div class="overflow-x-auto custom-scrollbar">
                                <table id="scorecardTable" class="w-full">
                                    <thead class="bg-zinc-800 text-white">
                                        <tr>
                                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider w-16">No</th>
                                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider">Tanggal</th>
                                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider">Jumlah Sesi</th>
                                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider">Jumlah Anak Panah</th>
                                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider w-48 no-print">Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100">
                                        <?php
                                        $loopNumber = 1;
                                        $hasData = false;
                                        while ($a = mysqli_fetch_array($mysql_table_score_board)) {
                                            $hasData = true;
                                        ?>
                                            <tr class="hover:bg-slate-50 transition-colors">
                                                <td class="px-4 py-3">
                                                    <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-archery-100 text-archery-700 text-sm font-semibold">
                                                        <?= $loopNumber++ ?>
                                                    </span>
                                                </td>
                                                <td class="px-4 py-3 text-sm text-slate-600"><?= $a['created'] ?></td>
                                                <td class="px-4 py-3 text-center">
                                                    <span class="px-2.5 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-700"><?= $a['jumlah_sesi'] ?> Sesi</span>
                                                </td>
                                                <td class="px-4 py-3 text-center">
                                                    <span class="px-2.5 py-1 rounded-full text-xs font-medium bg-amber-100 text-amber-700"><?= $a['jumlah_anak_panah'] ?> Panah</span>
                                                </td>
                                                <td class="px-4 py-3 no-print">
                                                    <div class="flex items-center justify-center gap-1 flex-wrap">
                                                        <a href="detail.php?action=scorecard&resource=index&kegiatan_id=<?= $kegiatan_id ?>&category_id=<?= $category_id ?>&scoreboard=<?= $a['id'] ?>&rangking=true"
                                                           class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-amber-100 text-amber-700 text-xs font-medium hover:bg-amber-200 transition-colors">
                                                            <i class="fas fa-trophy text-xs"></i> Ranking
                                                        </a>
                                                        <a href="detail.php?action=scorecard&resource=index&kegiatan_id=<?= $kegiatan_id ?>&category_id=<?= $category_id ?>&scoreboard=<?= $a['id'] ?>"
                                                           class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-blue-100 text-blue-700 text-xs font-medium hover:bg-blue-200 transition-colors">
                                                            <i class="fas fa-edit text-xs"></i> Detail
                                                        </a>
                                                        <a href="detail.php?aduan=true&kegiatan_id=<?= $kegiatan_id ?>&category_id=<?= $category_id ?>&scoreboard=<?= $a['id'] ?>"
                                                           class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-purple-100 text-purple-700 text-xs font-medium hover:bg-purple-200 transition-colors">
                                                            <i class="fas fa-sitemap text-xs"></i> Aduan
                                                        </a>
                                                        <button onclick="delete_score_board('<?= $kegiatan_id ?>', '<?= $category_id ?>', '<?= $a['id'] ?>')"
                                                                class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-red-100 text-red-700 text-xs font-medium hover:bg-red-200 transition-colors">
                                                            <i class="fas fa-trash text-xs"></i>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php } ?>
                                        <?php if (!$hasData): ?>
                                            <tr>
                                                <td colspan="5" class="px-4 py-12">
                                                    <div class="flex flex-col items-center text-center">
                                                        <div class="w-16 h-16 rounded-full bg-slate-100 flex items-center justify-center mb-3">
                                                            <i class="fas fa-clipboard-list text-slate-400 text-2xl"></i>
                                                        </div>
                                                        <p class="text-slate-500 font-medium">Belum ada scorecard</p>
                                                        <p class="text-slate-400 text-sm mb-4">Klik tombol "Tambah" untuk membuat scorecard baru</p>
                                                        <a href="detail.php?action=scorecard&resource=form&kegiatan_id=<?= $kegiatan_id ?>&category_id=<?= $category_id ?>"
                                                           class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-archery-600 text-white text-sm font-medium hover:bg-archery-700 transition-colors">
                                                            <i class="fas fa-plus"></i> Buat Scorecard
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php } ?>
                <?php } ?>
            <?php } ?>

            <!-- Scorecard Detail / Input Mode -->
            <?php if (isset($_GET['scoreboard']) && !isset($_GET['rangking'])) { ?>
                <div id="scorecardContainer" class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
                    <div class="px-6 py-4 border-b border-slate-200 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                        <div class="flex items-center gap-3">
                            <a href="detail.php?action=scorecard&resource=index&kegiatan_id=<?= $kegiatan_id ?>&category_id=<?= $category_id ?>"
                               class="p-2 rounded-lg text-slate-500 hover:bg-slate-100 transition-colors no-print">
                                <i class="fas fa-arrow-left"></i>
                            </a>
                            <div>
                                <h2 class="font-semibold text-slate-900">Score Board - Input Skor</h2>
                                <p class="text-sm text-slate-500"><?= htmlspecialchars($kategoriData['name']) ?></p>
                            </div>
                        </div>
                        <button onclick="exportScorecardToExcel()" id="exportBtn" class="hidden inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-emerald-600 text-white text-sm font-medium hover:bg-emerald-700 transition-colors no-print">
                            <i class="fas fa-file-excel"></i> Export
                        </button>
                    </div>

                    <!-- Stats Header -->
                    <div class="px-6 py-4 bg-slate-50 border-b border-slate-200">
                        <div class="grid grid-cols-3 gap-4 text-center">
                            <div>
                                <div class="w-10 h-10 rounded-lg bg-archery-100 flex items-center justify-center mx-auto mb-1">
                                    <i class="fas fa-bullseye text-archery-600"></i>
                                </div>
                                <p class="text-sm font-medium text-slate-900"><?= htmlspecialchars($kategoriData['name']) ?></p>
                                <p class="text-xs text-slate-500"><?= htmlspecialchars($kegiatanData['nama_kegiatan']) ?></p>
                            </div>
                            <div>
                                <div class="w-10 h-10 rounded-lg bg-blue-100 flex items-center justify-center mx-auto mb-1">
                                    <i class="fas fa-users text-blue-600"></i>
                                </div>
                                <p class="text-sm font-medium text-slate-900" id="pesertaCount"><?= count($pesertaList) ?></p>
                                <p class="text-xs text-slate-500">Peserta</p>
                            </div>
                            <div>
                                <div class="w-10 h-10 rounded-lg bg-amber-100 flex items-center justify-center mx-auto mb-1">
                                    <i class="fas fa-crosshairs text-amber-600"></i>
                                </div>
                                <p class="text-sm font-medium text-slate-900" id="panahCount">-</p>
                                <p class="text-xs text-slate-500">Anak Panah</p>
                            </div>
                        </div>
                    </div>

                    <!-- Peserta Selector Dropdown -->
                    <div id="pesertaSelectorInline" class="p-6">
                        <div class="bg-archery-50 border-2 border-dashed border-archery-300 rounded-xl p-6 text-center">
                            <div class="w-14 h-14 rounded-full bg-archery-100 flex items-center justify-center mx-auto mb-4">
                                <i class="fas fa-bullseye text-archery-600 text-xl"></i>
                            </div>
                            <h3 class="font-semibold text-slate-900 mb-1">Pilih Peserta untuk Input Score</h3>
                            <p class="text-sm text-slate-500 mb-4">Pilih peserta dari dropdown untuk mulai mengisi score</p>

                            <div class="relative max-w-sm mx-auto">
                                <button id="dropdownBtn" onclick="toggleDropdown()"
                                        class="w-full px-4 py-3 rounded-lg bg-archery-600 text-white font-medium flex items-center justify-between hover:bg-archery-700 transition-colors">
                                    <span id="dropdownText">Pilih Peserta</span>
                                    <i class="fas fa-chevron-down dropdown-arrow transition-transform"></i>
                                </button>
                                <div id="dropdownMenu" class="absolute top-full left-0 right-0 mt-2 bg-white border border-slate-200 rounded-lg shadow-lg max-h-64 overflow-y-auto z-50">
                                    <!-- Populated by JavaScript -->
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Selected Peserta Info -->
                    <div id="selectedPesertaInfo" class="hidden px-6 pb-4">
                        <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 text-center">
                            <p class="text-sm text-slate-600 mb-1">Sedang mengisi score untuk:</p>
                            <p class="font-bold text-amber-700 text-lg" id="selectedPesertaName"></p>
                            <button onclick="changePeserta()" class="mt-3 px-4 py-2 rounded-lg border border-slate-300 text-slate-700 text-sm font-medium hover:bg-slate-50 transition-colors no-print">
                                <i class="fas fa-sync-alt mr-1"></i> Ganti Peserta
                            </button>
                        </div>
                    </div>

                    <div id="scorecardTitle" class="hidden px-6 pb-2">
                        <h3 class="font-semibold text-slate-700 text-center">Informasi Skor</h3>
                    </div>

                    <div id="playersContainer" class="px-6 pb-6"></div>
                </div>
            <?php } ?>

            <!-- Ranking Mode -->
            <?php if (isset($_GET['scoreboard']) && isset($_GET['rangking'])) { ?>
                <div id="scorecardContainer" class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
                    <div class="px-6 py-4 border-b border-slate-200 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                        <div class="flex items-center gap-3">
                            <a href="detail.php?action=scorecard&resource=index&kegiatan_id=<?= $kegiatan_id ?>&category_id=<?= $category_id ?>"
                               class="p-2 rounded-lg text-slate-500 hover:bg-slate-100 transition-colors no-print">
                                <i class="fas fa-arrow-left"></i>
                            </a>
                            <div>
                                <h2 class="font-semibold text-slate-900">Score Board - Ranking</h2>
                                <p class="text-sm text-slate-500"><?= htmlspecialchars($kategoriData['name']) ?></p>
                            </div>
                        </div>
                        <button onclick="exportScorecardToExcel()" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-emerald-600 text-white text-sm font-medium hover:bg-emerald-700 transition-colors no-print">
                            <i class="fas fa-file-excel"></i> Export
                        </button>
                    </div>

                    <!-- Stats Header -->
                    <div class="px-6 py-4 bg-slate-50 border-b border-slate-200">
                        <div class="grid grid-cols-3 gap-4 text-center">
                            <div>
                                <div class="w-10 h-10 rounded-lg bg-archery-100 flex items-center justify-center mx-auto mb-1">
                                    <i class="fas fa-bullseye text-archery-600"></i>
                                </div>
                                <p class="text-sm font-medium text-slate-900"><?= htmlspecialchars($kategoriData['name']) ?></p>
                                <p class="text-xs text-slate-500"><?= htmlspecialchars($kegiatanData['nama_kegiatan']) ?></p>
                            </div>
                            <div>
                                <div class="w-10 h-10 rounded-lg bg-blue-100 flex items-center justify-center mx-auto mb-1">
                                    <i class="fas fa-users text-blue-600"></i>
                                </div>
                                <p class="text-sm font-medium text-slate-900" id="pesertaCount"><?= count($pesertaList) ?></p>
                                <p class="text-xs text-slate-500">Peserta</p>
                            </div>
                            <div>
                                <div class="w-10 h-10 rounded-lg bg-amber-100 flex items-center justify-center mx-auto mb-1">
                                    <i class="fas fa-crosshairs text-amber-600"></i>
                                </div>
                                <p class="text-sm font-medium text-slate-900" id="panahCount">-</p>
                                <p class="text-xs text-slate-500">Anak Panah</p>
                            </div>
                        </div>
                    </div>

                    <div class="px-6 py-4">
                        <h3 class="font-semibold text-slate-700 text-center mb-4">Informasi Skor</h3>
                        <div id="playersContainer"></div>
                    </div>

                    <div class="px-6 pb-6 no-print">
                        <button onclick="editScorecard()" class="w-full px-4 py-3 rounded-lg border border-slate-300 text-slate-700 font-medium hover:bg-slate-50 transition-colors">
                            <i class="fas fa-cog mr-2"></i> Edit Setup
                        </button>
                    </div>
                </div>
            <?php } ?>
        </div>

        <script>
            <?php if ($_GET['resource'] == 'form') { ?>
                let now = new Date();
                let formatted = now.getFullYear() + "-"
                    + String(now.getMonth() + 1).padStart(2, '0') + "-"
                    + String(now.getDate()).padStart(2, '0') + " "
                    + String(now.getHours()).padStart(2, '0') + ":"
                    + String(now.getMinutes()).padStart(2, '0') + ":"
                    + String(now.getSeconds()).padStart(2, '0');

                document.getElementById("local_time").value = formatted;
            <?php } ?>

            const pesertaData = <?= json_encode($pesertaList) ?>;
            let selectedPesertaId = null;
            let saveTimeout = null;
            let inputTimeout = null;
            const SAVE_DELAY = 500;
            const INPUT_DELAY = 500;

            <?php if (isset($_GET['rangking'])) { ?>
                const peserta_score = <?= json_encode($peserta_score) ?>;
                function tambahAtributById(id, key, value) {
                    const peserta = pesertaData.find(p => p.id === id);
                    if (peserta) {
                        peserta[key] = value;
                    }
                }

                for (let i = 0; i < peserta_score.length; i++) {
                    tambahAtributById(peserta_score[i]['id'], "total_score", peserta_score[i]['total_score']);
                    tambahAtributById(peserta_score[i]['id'], "x_score", peserta_score[i]['total_x']);
                }

                pesertaData.sort((a, b) => {
                    if (b.total_score !== a.total_score) {
                        return b.total_score - a.total_score;
                    }
                    return b.x_score - a.x_score;
                });
            <?php } ?>

            <?php if (isset($_GET['scoreboard'])) { ?>
                <?php if (isset($_GET['rangking'])) { ?>
                    openScoreBoard("<?= $show_score_board['jumlah_sesi'] ?>", "<?= $show_score_board['jumlah_anak_panah'] ?>");
                <?php } else { ?>
                    document.addEventListener('DOMContentLoaded', function () {
                        init();
                    });
                <?php } ?>
            <?php } ?>

            function delete_score_board(kegiatan_id, category_id, id) {
                if (confirm("Apakah anda yakin akan menghapus data ini?")) {
                    window.location.href = `detail.php?action=scorecard&resource=index&kegiatan_id=${kegiatan_id}&category_id=${category_id}&delete_score_board=${id}`;
                }
            }

            <?php
            if (isset($mysql_data_score)) {
                while ($jatuh = mysqli_fetch_array($mysql_data_score)) { ?>
                    if (document.getElementById("peserta_<?= $jatuh['peserta_id'] ?>_a<?= $jatuh['arrow'] ?>_s<?= $jatuh['session'] ?>")) {
                        document.getElementById("peserta_<?= $jatuh['peserta_id'] ?>_a<?= $jatuh['arrow'] ?>_s<?= $jatuh['session'] ?>").value = "<?= $jatuh['score'] ?>";
                        hitungPerArrow('peserta_<?= $jatuh['peserta_id'] ?>', '<?= $jatuh['arrow'] ?>', '<?= $jatuh['session'] ?>', '<?= $show_score_board['jumlah_anak_panah'] ?>');
                    }
                <?php } ?>
            <?php }
            ?>

            function init() {
                renderDropdownMenu();

                document.addEventListener('click', function (event) {
                    const dropdown = document.getElementById('dropdownMenu');
                    const dropdownBtn = document.getElementById('dropdownBtn');

                    if (dropdown && dropdownBtn && !dropdownBtn.contains(event.target) && !dropdown.contains(event.target)) {
                        dropdown.classList.remove('show');
                        dropdownBtn.querySelector('.dropdown-arrow').style.transform = 'rotate(0deg)';
                    }
                });
            }

            function renderDropdownMenu() {
                const menu = document.getElementById('dropdownMenu');
                if (!menu) return;

                menu.innerHTML = '';

                pesertaData.forEach(peserta => {
                    const item = document.createElement('div');
                    item.className = 'px-4 py-3 hover:bg-slate-50 cursor-pointer flex items-center gap-3 border-b border-slate-100 last:border-0';
                    item.onclick = () => selectPeserta(peserta.id);

                    item.innerHTML = `
                        <div class="w-8 h-8 rounded-full ${peserta.jenis_kelamin === 'P' ? 'bg-pink-100 text-pink-600' : 'bg-blue-100 text-blue-600'} flex items-center justify-center">
                            <i class="fas ${peserta.jenis_kelamin === 'P' ? 'fa-venus' : 'fa-mars'} text-sm"></i>
                        </div>
                        <div>
                            <p class="font-medium text-slate-900 text-sm">${peserta.nama_peserta}</p>
                            <p class="text-xs text-slate-500">${peserta.jenis_kelamin === 'P' ? 'Putri' : 'Putra'}</p>
                        </div>
                    `;

                    menu.appendChild(item);
                });
            }

            function toggleDropdown() {
                const dropdown = document.getElementById('dropdownMenu');
                const dropdownBtn = document.getElementById('dropdownBtn');

                if (dropdown && dropdownBtn) {
                    dropdown.classList.toggle('show');
                    const arrow = dropdownBtn.querySelector('.dropdown-arrow');
                    arrow.style.transform = dropdown.classList.contains('show') ? 'rotate(180deg)' : 'rotate(0deg)';
                }
            }

            function selectPeserta(pesertaId) {
                selectedPesertaId = pesertaId;
                const peserta = pesertaData.find(p => p.id === pesertaId);

                if (peserta) {
                    const dropdown = document.getElementById('dropdownMenu');
                    const dropdownBtn = document.getElementById('dropdownBtn');
                    if (dropdown) dropdown.classList.remove('show');
                    if (dropdownBtn) dropdownBtn.querySelector('.dropdown-arrow').style.transform = 'rotate(0deg)';

                    const selectedName = document.getElementById('selectedPesertaName');
                    const selectedInfo = document.getElementById('selectedPesertaInfo');
                    if (selectedName) selectedName.textContent = peserta.nama_peserta;
                    if (selectedInfo) selectedInfo.classList.remove('hidden');

                    const selectorInline = document.getElementById('pesertaSelectorInline');
                    const scorecardTitle = document.getElementById('scorecardTitle');
                    const exportBtn = document.getElementById('exportBtn');

                    if (selectorInline) selectorInline.style.display = 'none';
                    if (scorecardTitle) scorecardTitle.classList.remove('hidden');
                    if (exportBtn) exportBtn.classList.remove('hidden');

                    const jumlahSesi = parseInt("<?= $show_score_board['jumlah_sesi'] ?? 9 ?>");
                    const jumlahPanah = parseInt("<?= $show_score_board['jumlah_anak_panah'] ?? 3 ?>");
                    document.getElementById('panahCount').textContent = jumlahSesi * jumlahPanah;
                    generatePlayerSection(peserta, jumlahSesi, jumlahPanah);

                    setTimeout(() => {
                        loadExistingScores(pesertaId, jumlahPanah);
                    }, 100);
                }
            }

            function loadExistingScores(pesertaId, jumlahPanah) {
                const playerId = `peserta_${pesertaId}`;

                <?php
                if (isset($mysql_data_score)) {
                    mysqli_data_seek($mysql_data_score, 0);

                    while ($jatuh = mysqli_fetch_array($mysql_data_score)) { ?>
                        if (<?= $jatuh['peserta_id'] ?> == pesertaId) {
                            const inputElement = document.getElementById("peserta_<?= $jatuh['peserta_id'] ?>_a<?= $jatuh['arrow'] ?>_s<?= $jatuh['session'] ?>");
                            if (inputElement) {
                                inputElement.value = "<?= $jatuh['score'] ?>";
                                validateArrowInput(inputElement);
                                hitungPerArrow('peserta_<?= $jatuh['peserta_id'] ?>', '<?= $jatuh['arrow'] ?>', '<?= $jatuh['session'] ?>', jumlahPanah, null);
                            }
                        }
                    <?php } ?>
                <?php }
                ?>
                console.log("Data loaded for peserta:", pesertaId);
            }

            function changePeserta() {
                if (confirm('Yakin ingin ganti peserta? Data yang telah diinput sudah tersimpan.')) {
                    location.reload();
                }
            }

            function goBack() {
                window.location.href = 'detail.php?id=<?= $kegiatan_id ?>';
            }

            function openScoreBoard(jumlahSesi_data, jumlahPanah_data) {
                const jumlahSesi = parseInt(jumlahSesi_data);
                const jumlahPanah = parseInt(jumlahPanah_data);
                document.getElementById('panahCount').textContent = jumlahSesi * jumlahPanah;
                generatePlayerSections(jumlahSesi, jumlahPanah);
            }

            function generatePlayerSections(jumlahSesi, jumlahPanah) {
                const playersContainer = document.getElementById('playersContainer');
                if (!playersContainer) return;
                playersContainer.innerHTML = '';

                pesertaData.forEach((peserta, index) => {
                    const playerId = `peserta_${peserta.id}`;
                    const playerName = peserta.nama_peserta;
                    const rankBadge = index < 3 ? ['bg-amber-500', 'bg-slate-400', 'bg-amber-700'][index] : 'bg-slate-200';
                    const rankText = index < 3 ? 'text-white' : 'text-slate-600';

                    const playerSection = document.createElement('div');
                    playerSection.className = 'bg-slate-50 rounded-xl border border-slate-200 p-4 mb-4';
                    playerSection.innerHTML = `
                        <div class="flex items-center gap-3 mb-4">
                            <div class="w-10 h-10 rounded-full ${rankBadge} ${rankText} flex items-center justify-center font-bold">
                                ${index + 1}
                            </div>
                            <div>
                                <p class="font-semibold text-slate-900">${playerName}</p>
                                <p class="text-sm text-slate-500">${peserta.jenis_kelamin === 'P' ? 'Putri' : 'Putra'}</p>
                            </div>
                            ${typeof peserta.total_score !== 'undefined' ? `<span class="ml-auto px-3 py-1 rounded-full bg-archery-100 text-archery-700 text-sm font-semibold">${peserta.total_score} poin</span>` : ''}
                        </div>
                        <div class="overflow-x-auto custom-scrollbar">
                            <table class="w-full min-w-[500px]">
                                <thead>
                                    <tr class="bg-zinc-800 text-white">
                                        <th rowspan="2" class="px-3 py-2 text-xs font-semibold text-center w-16">Sesi</th>
                                        <th colspan="${jumlahPanah}" class="px-3 py-2 text-xs font-semibold text-center">Anak Panah</th>
                                        <th rowspan="2" class="px-3 py-2 text-xs font-semibold text-center w-16">Total</th>
                                        <th rowspan="2" class="px-3 py-2 text-xs font-semibold text-center w-16">End</th>
                                    </tr>
                                    <tr class="bg-zinc-700 text-white">
                                        ${Array.from({ length: jumlahPanah }, (_, i) => `<th class="px-2 py-1 text-xs font-medium text-center w-12">${i + 1}</th>`).join('')}
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-slate-100">
                                    ${generateTableRows(playerId, jumlahSesi, jumlahPanah)}
                                </tbody>
                            </table>
                        </div>
                        <div class="mt-4 bg-gradient-to-r from-archery-500 to-archery-600 rounded-lg p-4 text-white text-center">
                            <p class="text-sm opacity-80">Total Keseluruhan</p>
                            <p class="text-2xl font-bold" id="${playerId}_grand_total">${typeof peserta.total_score !== 'undefined' ? peserta.total_score + ' poin' : '0 poin'}</p>
                            ${typeof peserta.x_score !== 'undefined' ? `<p class="text-sm opacity-80">X Score: ${peserta.x_score}</p>` : ''}
                        </div>
                    `;

                    playersContainer.appendChild(playerSection);
                });
            }

            function generatePlayerSection(peserta, jumlahSesi, jumlahPanah) {
                const playerId = `peserta_${peserta.id}`;
                const playerName = peserta.nama_peserta;

                const playersContainer = document.getElementById('playersContainer');
                if (!playersContainer) return;

                playersContainer.innerHTML = `
                    <div class="bg-slate-50 rounded-xl border border-slate-200 p-4">
                        <div class="flex items-center gap-3 mb-4">
                            <div class="w-10 h-10 rounded-full ${peserta.jenis_kelamin === 'P' ? 'bg-pink-100 text-pink-600' : 'bg-blue-100 text-blue-600'} flex items-center justify-center">
                                <i class="fas ${peserta.jenis_kelamin === 'P' ? 'fa-venus' : 'fa-mars'}"></i>
                            </div>
                            <div>
                                <p class="font-semibold text-slate-900">${playerName}</p>
                                <p class="text-sm text-slate-500">${peserta.jenis_kelamin === 'P' ? 'Putri' : 'Putra'}</p>
                            </div>
                        </div>
                        <div class="overflow-x-auto custom-scrollbar">
                            <table class="w-full min-w-[500px]">
                                <thead>
                                    <tr class="bg-zinc-800 text-white">
                                        <th rowspan="2" class="px-3 py-2 text-xs font-semibold text-center w-16">Sesi</th>
                                        <th colspan="${jumlahPanah}" class="px-3 py-2 text-xs font-semibold text-center">Anak Panah</th>
                                        <th rowspan="2" class="px-3 py-2 text-xs font-semibold text-center w-16">Total</th>
                                        <th rowspan="2" class="px-3 py-2 text-xs font-semibold text-center w-16">End</th>
                                    </tr>
                                    <tr class="bg-zinc-700 text-white">
                                        ${Array.from({ length: jumlahPanah }, (_, i) => `<th class="px-2 py-1 text-xs font-medium text-center w-12">${i + 1}</th>`).join('')}
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-slate-100">
                                    ${generateTableRows(playerId, jumlahSesi, jumlahPanah)}
                                </tbody>
                            </table>
                        </div>
                        <div class="mt-4 bg-gradient-to-r from-archery-500 to-archery-600 rounded-lg p-4 text-white text-center">
                            <p class="text-sm opacity-80">Total Keseluruhan</p>
                            <p class="text-2xl font-bold" id="${playerId}_grand_total">0 poin</p>
                        </div>
                    </div>
                `;
            }

            function generateTableRows(playerId, jumlahSesi, jumlahPanah) {
                let rowsHtml = '';

                for (let session = 1; session <= jumlahSesi; session++) {
                    const arrowInputs = Array.from({ length: jumlahPanah }, (_, arrow) => `
                        <td class="px-1 py-2 text-center">
                            <input type="text"
                                   class="arrow-input w-10 h-8 text-center text-sm font-semibold rounded border border-slate-300 focus:border-archery-500"
                                   <?= (isset($_GET['rangking'])) ? 'disabled' : '' ?>
                                   id="${playerId}_a${arrow + 1}_s${session}"
                                   placeholder=""
                                   data-player-id="${playerId}"
                                   data-arrow="${arrow + 1}"
                                   data-session="${session}"
                                   data-total-arrow="${jumlahPanah}"
                                   oninput="handleArrowInput(this)"
                                   onkeydown="handleArrowKeydown(event, this)">
                        </td>
                    `).join('');

                    rowsHtml += `
                        <tr class="hover:bg-slate-50">
                            <td class="px-3 py-2 text-center font-semibold text-slate-700 bg-slate-100">S${session}</td>
                            ${arrowInputs}
                            <td class="px-1 py-2 text-center">
                                <input type="text"
                                       class="w-10 h-8 text-center text-sm font-bold rounded bg-amber-50 border border-amber-300 text-amber-700"
                                       id="${playerId}_total_a${session}"
                                       readonly>
                            </td>
                            <td class="px-1 py-2 text-center">
                                <input type="text"
                                       class="w-10 h-8 text-center text-sm font-bold rounded bg-archery-50 border border-archery-300 text-archery-700"
                                       id="${playerId}_end_a${session}"
                                       readonly>
                            </td>
                        </tr>
                    `;
                }

                return rowsHtml;
            }

            function handleArrowInput(el) {
                const playerId = el.getAttribute('data-player-id');
                const arrow = el.getAttribute('data-arrow');
                const session = el.getAttribute('data-session');
                const totalArrow = parseInt(el.getAttribute('data-total-arrow'));

                validateArrowInput(el);
                hitungPerArrow(playerId, arrow, session, totalArrow, el);

                if (inputTimeout) {
                    clearTimeout(inputTimeout);
                }

                const val = el.value.trim().toLowerCase();
                if (val !== '') {
                    inputTimeout = setTimeout(() => {
                        moveToNextInput(el, playerId, arrow, session, totalArrow);
                    }, INPUT_DELAY);
                }
            }

            function hitungPerArrow(playerId, arrow, session, totalArrow, el) {
                let sessionTotal = 0;

                for (let a = 1; a <= totalArrow; a++) {
                    const input = document.getElementById(`${playerId}_a${a}_s${session}`);
                    if (input && input.value) {
                        let val = input.value.trim().toLowerCase();
                        let score = 0;
                        if (val === "x") {
                            score = 10;
                        } else if (val === "m") {
                            score = 0;
                        } else if (!isNaN(val) && val !== "") {
                            score = parseInt(val);
                        }
                        sessionTotal += score;
                    }
                }

                const totalInput = document.getElementById(`${playerId}_total_a${session}`);
                if (totalInput) {
                    totalInput.value = sessionTotal;
                }

                let maxSession = 20;
                let runningTotal = 0;

                for (let s = 1; s <= maxSession; s++) {
                    const sessionTotalInput = document.getElementById(`${playerId}_total_a${s}`);
                    const sessionEndInput = document.getElementById(`${playerId}_end_a${s}`);

                    if (sessionTotalInput && sessionEndInput) {
                        if (sessionTotalInput.value && sessionTotalInput.value !== '') {
                            runningTotal += parseInt(sessionTotalInput.value) || 0;
                        }
                        sessionEndInput.value = runningTotal;
                    } else {
                        break;
                    }
                }

                const grandTotalElement = document.getElementById(`${playerId}_grand_total`);
                if (grandTotalElement) {
                    grandTotalElement.innerText = runningTotal + " poin";
                }

                if (el != null) {
                    if (saveTimeout) {
                        clearTimeout(saveTimeout);
                    }

                    el.classList.add('saving');

                    saveTimeout = setTimeout(() => {
                        saveScoreToDatabase(playerId, arrow, session, el);
                    }, SAVE_DELAY);
                }

                return 0;
            }

            function saveScoreToDatabase(playerId, arrow, session, el) {
                let arr_playerID = playerId.split("_");
                let scoreValue = el.value.trim();

                fetch("", {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/x-www-form-urlencoded"
                    },
                    body: "save_score=1" +
                        "&peserta_id=" + encodeURIComponent(arr_playerID[1]) +
                        "&arrow=" + encodeURIComponent(arrow) +
                        "&session=" + encodeURIComponent(session) +
                        "&score=" + encodeURIComponent(scoreValue)
                })
                    .then(response => response.text())
                    .then(text => {
                        try {
                            return JSON.parse(text);
                        } catch (e) {
                            return { status: 'success', message: text };
                        }
                    })
                    .then(data => {
                        console.log("Score saved:", data);
                        el.classList.remove('saving');
                        el.classList.add('saved');

                        setTimeout(() => {
                            el.classList.remove('saved');
                            validateArrowInput(el);
                        }, 1000);
                    })
                    .catch(err => {
                        console.error("Save error:", err);
                        el.classList.remove('saving');
                        el.classList.add('error');

                        setTimeout(() => {
                            el.classList.remove('error');
                            validateArrowInput(el);
                        }, 2000);
                    });
            }

            function moveToNextInput(currentElement, playerId, currentArrow, currentSession, totalArrow) {
                let nextArrow = parseInt(currentArrow);
                let nextSession = parseInt(currentSession);

                if (nextArrow < totalArrow) {
                    nextArrow++;
                } else {
                    nextArrow = 1;
                    nextSession++;
                }

                const nextInput = document.getElementById(`${playerId}_a${nextArrow}_s${nextSession}`);

                if (nextInput && !nextInput.disabled) {
                    setTimeout(() => {
                        nextInput.focus();
                        nextInput.select();
                    }, 100);
                }
            }

            function validateArrowInput(el) {
                let val = el.value.trim().toLowerCase();

                if (!/^(10|[0-9]|x|m)?$/i.test(val)) {
                    el.value = "";
                    return;
                }

                el.classList.remove('score-x', 'score-m', 'score-10', 'score-high');

                if (val === 'x' || val === 'X') {
                    el.classList.add('score-x');
                } else if (val === 'm' || val === 'M') {
                    el.classList.add('score-m');
                } else if (val === '10') {
                    el.classList.add('score-10');
                } else if (val === '9' || val === '8') {
                    el.classList.add('score-high');
                }
            }

            function editScorecard() {
                window.location.href = 'detail.php?action=scorecard&resource=form&kegiatan_id=<?= $kegiatan_id ?>&category_id=<?= $category_id ?>';
            }

            function exportTableToExcel() {
                const table = document.getElementById('scorecardTable');
                if (!table) return;

                const tableClone = table.cloneNode(true);
                tableClone.querySelectorAll('.no-print').forEach(el => el.remove());
                tableClone.querySelectorAll('thead th:last-child').forEach(th => th.remove());
                tableClone.querySelectorAll('tbody td:last-child').forEach(td => td.remove());

                const htmlContent = `
                    <html xmlns:x="urn:schemas-microsoft-com:office:excel">
                    <head>
                        <meta charset="UTF-8">
                        <style>
                            table { border-collapse: collapse; width: 100%; }
                            th, td { border: 1px solid #000; padding: 8px; text-align: center; }
                            th { background-color: #000; color: white; font-weight: bold; }
                        </style>
                    </head>
                    <body>${tableClone.outerHTML}</body>
                    </html>
                `;

                const blob = new Blob([htmlContent], { type: 'application/vnd.ms-excel' });
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = `Scorecard_List_${new Date().toISOString().split('T')[0]}.xls`;
                a.click();
                window.URL.revokeObjectURL(url);
            }

            function exportScorecardToExcel() {
                const categoryName = '<?= htmlspecialchars($kategoriData['name']) ?>';
                const eventName = '<?= htmlspecialchars($kegiatanData['nama_kegiatan']) ?>';

                const firstPlayerSection = document.querySelector('.overflow-x-auto table');
                let jumlahSesi = 0;
                let jumlahPanah = 0;

                if (firstPlayerSection) {
                    const sessionRows = firstPlayerSection.querySelectorAll('tbody tr');
                    jumlahSesi = sessionRows.length;

                    const secondHeaderRow = firstPlayerSection.querySelectorAll('thead tr:nth-child(2) th');
                    jumlahPanah = secondHeaderRow.length;
                }

                if (jumlahPanah === 0 && pesertaData.length > 0) {
                    const firstPlayerId = `peserta_${pesertaData[0].id}`;
                    let arrowCount = 1;
                    while (document.getElementById(`${firstPlayerId}_a${arrowCount}_s1`)) {
                        arrowCount++;
                    }
                    jumlahPanah = arrowCount - 1;
                }

                if (jumlahPanah === 0 || pesertaData.length === 0) {
                    alert('Data tidak lengkap untuk export!');
                    return;
                }

                let sheet1HTML = '<table border="1" cellpadding="6" cellspacing="0">';
                sheet1HTML += '<tr><td colspan="20" style="font-size: 18px; font-weight: bold; border: 1px solid #000;">' + categoryName + '</td></tr>';
                sheet1HTML += '<tr><td colspan="20" style="font-size: 14px; border: 1px solid #000;">' + eventName + '</td></tr>';
                sheet1HTML += '<tr>';
                sheet1HTML += '<td style="background-color: #000; color: white; font-weight: bold; border: 1px solid #000;">No</td>';
                sheet1HTML += '<td style="background-color: #000; color: white; font-weight: bold; border: 1px solid #000;">Nama</td>';

                for (let i = 1; i <= jumlahSesi; i++) {
                    sheet1HTML += '<td style="background-color: #000; color: white; font-weight: bold; border: 1px solid #000;">Rambalan ' + i + '</td>';
                }
                sheet1HTML += '<td style="background-color: #000; color: white; font-weight: bold; border: 1px solid #000;">Total</td></tr>';

                for (let index = 0; index < pesertaData.length; index++) {
                    const peserta = pesertaData[index];
                    const playerId = 'peserta_' + peserta.id;
                    sheet1HTML += '<tr>';
                    sheet1HTML += '<td style="border: 1px solid #000;">' + (index + 1) + '</td>';
                    sheet1HTML += '<td style="text-align: left; border: 1px solid #000;">' + peserta.nama_peserta + '</td>';

                    for (let s = 1; s <= jumlahSesi; s++) {
                        const totalInput = document.getElementById(playerId + '_total_a' + s);
                        const value = totalInput ? (totalInput.value || '0') : '0';
                        sheet1HTML += '<td style="border: 1px solid #000;">' + value + '</td>';
                    }

                    const grandTotalEl = document.getElementById(playerId + '_grand_total');
                    const grandTotal = grandTotalEl ? grandTotalEl.textContent.replace(' poin', '') : '0';
                    sheet1HTML += '<td style="font-weight: bold; border: 1px solid #000;">' + grandTotal + '</td>';
                    sheet1HTML += '</tr>';
                }

                sheet1HTML += '</table>';

                let sheet2HTML = '<table border="1" cellpadding="6" cellspacing="0">';
                sheet2HTML += '<tr><td colspan="20" style="font-size: 18px; font-weight: bold; border: 1px solid #000;">' + categoryName + '</td></tr>';
                sheet2HTML += '<tr><td colspan="20" style="font-size: 14px; border: 1px solid #000;">' + eventName + ' - TRAINING</td></tr>';
                sheet2HTML += '</table>';

                for (let pesertaIndex = 0; pesertaIndex < pesertaData.length; pesertaIndex++) {
                    const peserta = pesertaData[pesertaIndex];
                    const playerId = 'peserta_' + peserta.id;

                    sheet2HTML += '<br/><table border="1" cellpadding="6" cellspacing="0">';
                    sheet2HTML += '<tr><td colspan="20" style="background-color: #ddd; font-weight: bold; padding: 8px; border: 1px solid #000;">Rank#' + (pesertaIndex + 1) + ' ' + peserta.nama_peserta + '</td></tr>';
                    sheet2HTML += '<tr>';
                    sheet2HTML += '<td style="background-color: #000; color: white; font-weight: bold; border: 1px solid #000;">Rambalan</td>';

                    for (let a = 1; a <= jumlahPanah; a++) {
                        sheet2HTML += '<td style="background-color: #000; color: white; font-weight: bold; border: 1px solid #000;">Shot ' + a + '</td>';
                    }
                    sheet2HTML += '<td style="background-color: #000; color: white; font-weight: bold; border: 1px solid #000;">Total</td>';
                    sheet2HTML += '<td style="background-color: #000; color: white; font-weight: bold; border: 1px solid #000;">End</td></tr>';

                    for (let s = 1; s <= jumlahSesi; s++) {
                        sheet2HTML += '<tr>';
                        sheet2HTML += '<td style="font-weight: bold; border: 1px solid #000;">' + s + '</td>';

                        for (let a = 1; a <= jumlahPanah; a++) {
                            const input = document.getElementById(playerId + '_a' + a + '_s' + s);
                            const value = input ? (input.value || '') : '';
                            sheet2HTML += '<td style="border: 1px solid #000;">' + value + '</td>';
                        }

                        const totalInput = document.getElementById(playerId + '_total_a' + s);
                        const totalValue = totalInput ? (totalInput.value || '0') : '0';
                        sheet2HTML += '<td style="font-weight: bold; border: 1px solid #000;">' + totalValue + '</td>';

                        const endInput = document.getElementById(playerId + '_end_a' + s);
                        const endValue = endInput ? (endInput.value || '0') : '0';
                        sheet2HTML += '<td style="font-weight: bold; border: 1px solid #000;">' + endValue + '</td>';

                        sheet2HTML += '</tr>';
                    }

                    sheet2HTML += '</table>';
                }

                const fullHTML = '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">' +
                    '<head><meta http-equiv="Content-Type" content="text/html; charset=utf-8">' +
                    '<!--[if gte mso 9]><xml>' +
                    '<x:ExcelWorkbook>' +
                    '<x:ExcelWorksheets>' +
                    '<x:ExcelWorksheet>' +
                    '<x:Name>Rekap Total</x:Name>' +
                    '<x:WorksheetOptions><x:Panes></x:Panes></x:WorksheetOptions>' +
                    '</x:ExcelWorksheet>' +
                    '<x:ExcelWorksheet>' +
                    '<x:Name>Training Detail</x:Name>' +
                    '<x:WorksheetOptions><x:Panes></x:Panes></x:WorksheetOptions>' +
                    '</x:ExcelWorksheet>' +
                    '</x:ExcelWorksheets>' +
                    '</x:ExcelWorkbook>' +
                    '</xml><![endif]-->' +
                    '</head><body>' +
                    '<div>' + sheet1HTML + '</div>' +
                    '<br clear=all style="mso-special-character:line-break;page-break-before:always">' +
                    '<div>' + sheet2HTML + '</div>' +
                    '</body></html>';

                const blob = new Blob([fullHTML], { type: 'application/vnd.ms-excel' });
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = 'Scorecard_' + categoryName + '_' + new Date().toISOString().split('T')[0] + '.xls';
                a.click();
                window.URL.revokeObjectURL(url);
            }
        </script>
    </body>
    </html>
    <?php
        exit;
}

// ============================================
// BAGIAN TAMPILAN NORMAL (DAFTAR PESERTA)
// ============================================

try {
    include '../config/panggil.php';
} catch (Exception $e) {
    die("Error koneksi database: " . $e->getMessage());
}

$kegiatan_id = isset($_GET['kegiatan_id']) ? intval($_GET['kegiatan_id']) : null;

if (!$kegiatan_id) {
    try {
        $queryFirstKegiatan = "SELECT id FROM kegiatan WHERE id = " . (isset($_GET['POST']) ? intval($_GET['POST']) : $_GET['id']);
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
    die("Tidak ada kegiatan yang tersedia.");
}

$kegiatanData = [];
try {
    $queryKegiatan = "SELECT id, nama_kegiatan FROM kegiatan WHERE id = ?";
    $stmtKegiatan = $conn->prepare($queryKegiatan);
    $stmtKegiatan->bind_param("i", $kegiatan_id);
    $stmtKegiatan->execute();
    $resultKegiatan = $stmtKegiatan->get_result();

    if ($resultKegiatan->num_rows > 0) {
        $kegiatanData = $resultKegiatan->fetch_assoc();
    } else {
        die("Kegiatan tidak ditemukan.");
    }
    $stmtKegiatan->close();
} catch (Exception $e) {
    die("Error mengambil data kegiatan: " . $e->getMessage());
}

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_kategori = isset($_GET['filter_kategori']) ? intval($_GET['filter_kategori']) : 0;
$filter_gender = isset($_GET['filter_gender']) ? $_GET['filter_gender'] : '';

$whereConditions = ["p.kegiatan_id = ?"];
$params = [$kegiatan_id];
$types = "i";

if (!empty($search)) {
    $whereConditions[] = "(p.nama_peserta LIKE ? OR p.asal_kota LIKE ? OR p.nama_club LIKE ? OR p.sekolah LIKE ?)";
    $searchParam = "%$search%";
    $params = array_merge($params, [$searchParam, $searchParam, $searchParam, $searchParam]);
    $types .= "ssss";
}

if ($filter_kategori > 0) {
    $whereConditions[] = "p.category_id = ?";
    $params[] = $filter_kategori;
    $types .= "i";
}

if (!empty($filter_gender)) {
    $whereConditions[] = "p.jenis_kelamin = ?";
    $params[] = $filter_gender;
    $types .= "s";
}

$whereClause = implode(" AND ", $whereConditions);

$queryPeserta = "
    SELECT 
        p.id,
        p.nama_peserta,
        p.tanggal_lahir,
        p.jenis_kelamin,
        p.asal_kota,
        p.nama_club,
        p.sekolah,
        p.kelas,
        p.nomor_hp,
        p.bukti_pembayaran,
        c.name as category_name,
        c.min_age,
        c.max_age,
        c.gender as category_gender,
        TIMESTAMPDIFF(YEAR, p.tanggal_lahir, CURDATE()) as umur
    FROM peserta p
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE $whereClause
    ORDER BY p.nama_peserta ASC
";

$pesertaList = [];
$totalPeserta = 0;

try {
    $stmtPeserta = $conn->prepare($queryPeserta);
    if (!empty($params)) {
        $stmtPeserta->bind_param($types, ...$params);
    }
    $stmtPeserta->execute();
    $resultPeserta = $stmtPeserta->get_result();

    while ($row = $resultPeserta->fetch_assoc()) {
        $pesertaList[] = $row;
    }
    $totalPeserta = count($pesertaList);
    $stmtPeserta->close();
} catch (Exception $e) {
    die("Error mengambil data peserta: " . $e->getMessage());
}

$kategoriesList = [];
try {
    $queryKategori = "
        SELECT DISTINCT c.id, c.name 
        FROM categories c 
        INNER JOIN kegiatan_kategori kk ON c.id = kk.category_id 
        WHERE kk.kegiatan_id = ? AND c.status = 'active'
        ORDER BY c.name ASC
    ";
    $stmtKategori = $conn->prepare($queryKategori);
    $stmtKategori->bind_param("i", $kegiatan_id);
    $stmtKategori->execute();
    $resultKategori = $stmtKategori->get_result();

    while ($row = $resultKategori->fetch_assoc()) {
        $kategoriesList[] = $row;
    }
    $stmtKategori->close();
} catch (Exception $e) {
    // Biarkan kosong jika error
}

$statistik = [
    'total' => $totalPeserta,
    'laki_laki' => 0,
    'perempuan' => 0,
    'kategori' => [],
    'sudah_bayar' => 0,
    'belum_bayar' => 0
];

foreach ($pesertaList as $peserta) {
    if ($peserta['jenis_kelamin'] == 'Laki-laki') {
        $statistik['laki_laki']++;
    } else {
        $statistik['perempuan']++;
    }

    if (!empty($peserta['bukti_pembayaran'])) {
        $statistik['sudah_bayar']++;
    } else {
        $statistik['belum_bayar']++;
    }

    $kategori = $peserta['category_name'];
    if (!isset($statistik['kategori'][$kategori])) {
        $statistik['kategori'][$kategori] = 0;
    }
    $statistik['kategori'][$kategori]++;
}
?>
<!DOCTYPE html>
<html lang="id" class="h-full">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daftar Peserta - <?= htmlspecialchars($kegiatanData['nama_kegiatan']) ?></title>
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
        .btn-input { display: none; }
        .btn-input.show { display: inline-block; }
        .payment-icon { cursor: pointer; transition: transform 0.2s; }
        .payment-icon:hover { transform: scale(1.2); }
        .payment-tooltip { position: relative; display: inline-block; }
        .payment-tooltip .tooltip-text {
            visibility: hidden; width: 140px; background: #333; color: #fff;
            text-align: center; border-radius: 6px; padding: 8px; position: absolute;
            z-index: 50; bottom: 125%; left: 50%; margin-left: -70px; opacity: 0;
            transition: opacity 0.3s; font-size: 12px;
        }
        .payment-tooltip:hover .tooltip-text { visibility: visible; opacity: 1; }
        /* Mobile card view */
        .mobile-card-view { display: none; }
        @media (max-width: 768px) {
            .table-container { display: none; }
            .mobile-card-view { display: block; }
        }

        /* Modal - minimal styles needed */
        .modal { display: none; position: fixed; z-index: 1000; inset: 0; background: rgba(0,0,0,0.8); }
        .modal-content { position: relative; margin: 5% auto; width: 90%; max-width: 700px; background: white; border-radius: 12px; overflow: hidden; }
        .modal-close { position: absolute; right: 1rem; top: 0.75rem; color: white; font-size: 1.5rem; cursor: pointer; z-index: 1001; }
        .modal-close:hover { opacity: 0.7; }
    </style>
</head>

<body class="min-h-screen bg-slate-50">
    <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
        <!-- Header Card -->
        <div class="bg-gradient-to-br from-archery-600 to-archery-800 rounded-t-2xl px-6 py-8 text-white">
            <h1 class="text-2xl sm:text-3xl font-bold mb-2">Daftar Peserta Terdaftar</h1>
            <p class="text-white/80 mb-4">Kelola dan pantau peserta yang telah mendaftar</p>
            <div class="bg-white/20 rounded-lg px-4 py-3 inline-block">
                <h3 class="font-semibold"><?= htmlspecialchars($kegiatanData['nama_kegiatan']) ?></h3>
                <p class="text-sm text-white/70">Total Peserta Terdaftar: <?= $totalPeserta ?> orang</p>
            </div>
        </div>

        <!-- Main Content -->
        <div class="bg-white rounded-b-2xl shadow-xl p-6 sm:p-8">
            <a href="kegiatan.view.php" class="inline-flex items-center gap-2 text-archery-600 hover:text-archery-700 font-medium text-sm mb-6">
                <i class="fas fa-arrow-left"></i> Kembali Ke Kegiatan
            </a>

            <!-- Statistics Cards -->
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4 mb-6">
                <div class="bg-white rounded-xl border-l-4 border-archery-500 p-4 shadow-sm">
                    <p class="text-2xl font-bold text-archery-600"><?= $statistik['total'] ?></p>
                    <p class="text-xs text-slate-500 mt-1">Total Peserta</p>
                </div>
                <div class="bg-white rounded-xl border-l-4 border-blue-500 p-4 shadow-sm">
                    <p class="text-2xl font-bold text-blue-600"><?= $statistik['laki_laki'] ?></p>
                    <p class="text-xs text-slate-500 mt-1">Laki-laki</p>
                </div>
                <div class="bg-white rounded-xl border-l-4 border-pink-500 p-4 shadow-sm">
                    <p class="text-2xl font-bold text-pink-600"><?= $statistik['perempuan'] ?></p>
                    <p class="text-xs text-slate-500 mt-1">Perempuan</p>
                </div>
                <div class="bg-white rounded-xl border-l-4 border-emerald-500 p-4 shadow-sm">
                    <p class="text-2xl font-bold text-emerald-600"><?= $statistik['sudah_bayar'] ?></p>
                    <p class="text-xs text-slate-500 mt-1">Sudah Bayar</p>
                </div>
                <div class="bg-white rounded-xl border-l-4 border-red-500 p-4 shadow-sm">
                    <p class="text-2xl font-bold text-red-600"><?= $statistik['belum_bayar'] ?></p>
                    <p class="text-xs text-slate-500 mt-1">Belum Bayar</p>
                </div>
                <div class="bg-white rounded-xl border-l-4 border-slate-400 p-4 shadow-sm">
                    <p class="text-2xl font-bold text-slate-600"><?= count($statistik['kategori']) ?></p>
                    <p class="text-xs text-slate-500 mt-1">Kategori</p>
                </div>
            </div>

            <!-- Filter Form - PRESERVED: form method, names, values -->
            <div class="bg-slate-50 rounded-xl border border-slate-200 p-5 mb-6">
                <form method="GET" action="">
                    <input type="hidden" name="kegiatan_id" value="<?= $kegiatan_id ?>">

                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                        <div>
                            <label for="search" class="block text-sm font-medium text-slate-700 mb-1">Cari Peserta</label>
                            <input type="text" id="search" name="search"
                                class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:ring-2 focus:ring-archery-500 focus:border-archery-500"
                                placeholder="Nama, kota, club, atau sekolah..."
                                value="<?= htmlspecialchars($search) ?>">
                        </div>

                        <div>
                            <label for="filter_kategori" class="block text-sm font-medium text-slate-700 mb-1">Kategori</label>
                            <select id="filter_kategori" name="filter_kategori" class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:ring-2 focus:ring-archery-500">
                                <option value="">Semua Kategori</option>
                                <?php foreach ($kategoriesList as $kategori): ?>
                                <option value="<?= $kategori['id'] ?>" <?= $filter_kategori == $kategori['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($kategori['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label for="filter_gender" class="block text-sm font-medium text-slate-700 mb-1">Jenis Kelamin</label>
                            <select id="filter_gender" name="filter_gender" class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:ring-2 focus:ring-archery-500">
                                <option value="">Semua</option>
                                <option value="Laki-laki" <?= $filter_gender == 'Laki-laki' ? 'selected' : '' ?>>Laki-laki</option>
                                <option value="Perempuan" <?= $filter_gender == 'Perempuan' ? 'selected' : '' ?>>Perempuan</option>
                            </select>
                        </div>

                        <div class="flex items-end gap-2">
                            <button type="submit" class="flex-1 px-4 py-2 rounded-lg bg-archery-600 text-white text-sm font-medium hover:bg-archery-700 transition-colors">
                                <i class="fas fa-search mr-1"></i> Filter
                            </button>
                            <a href="#" id="inputBtn" class="btn-input <?= $filter_kategori > 0 ? 'show' : '' ?> px-3 py-2 rounded-lg bg-amber-500 text-white text-sm font-medium hover:bg-amber-600 transition-colors"
                                onclick="goToInput(event)">
                                📝 Input
                            </a>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Action Bar - PRESERVED: export link format -->
            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-6">
                <div>
                    <?php if ($totalPeserta > 0): ?>
                    <a href="?export=excel&kegiatan_id=<?= $kegiatan_id ?>&search=<?= urlencode($search) ?>&filter_kategori=<?= $filter_kategori ?>&filter_gender=<?= urlencode($filter_gender) ?>"
                        class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-emerald-600 text-white text-sm font-medium hover:bg-emerald-700 transition-colors" target="_blank">
                        <i class="fas fa-file-excel"></i> Export ke Excel
                    </a>
                    <?php endif; ?>
                </div>
                <div>
                    <?php if ($totalPeserta > 0): ?>
                    <span class="text-sm text-slate-500">
                        Menampilkan <?= $totalPeserta ?> peserta
                        <?php if (!empty($search) || $filter_kategori > 0 || !empty($filter_gender)): ?>
                        dengan filter
                        <?php endif; ?>
                    </span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Desktop Table View -->
            <div class="hidden md:block bg-white rounded-xl border border-slate-200 overflow-hidden">
                <?php if ($totalPeserta > 0): ?>
                <div class="overflow-x-auto custom-scrollbar" style="max-height: 65vh;">
                    <table class="w-full">
                        <thead class="bg-zinc-800 text-white sticky top-0 z-10">
                            <tr>
                                <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider w-12">#</th>
                                <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider">Nama</th>
                                <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider w-20">Umur</th>
                                <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider w-24">Gender</th>
                                <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider">Kategori</th>
                                <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider">Kota</th>
                                <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider">Club</th>
                                <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider">Sekolah</th>
                                <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider w-16">Kelas</th>
                                <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider">No. HP</th>
                                <th class="px-3 py-3 text-center text-xs font-semibold uppercase tracking-wider w-20">Bayar</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach ($pesertaList as $index => $peserta): ?>
                            <tr class="hover:bg-slate-50 transition-colors">
                                <td class="px-3 py-3 text-sm text-slate-500"><?= $index + 1 ?></td>
                                <td class="px-3 py-3">
                                    <p class="font-medium text-slate-900"><?= htmlspecialchars($peserta['nama_peserta']) ?></p>
                                    <p class="text-xs text-slate-400">Lahir: <?= date('d/m/Y', strtotime($peserta['tanggal_lahir'])) ?></p>
                                </td>
                                <td class="px-3 py-3">
                                    <span class="font-semibold text-slate-700"><?= $peserta['umur'] ?> th</span>
                                </td>
                                <td class="px-3 py-3">
                                    <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium <?= $peserta['jenis_kelamin'] == 'Laki-laki' ? 'bg-blue-100 text-blue-700' : 'bg-pink-100 text-pink-700' ?>">
                                        <i class="fas <?= $peserta['jenis_kelamin'] == 'Laki-laki' ? 'fa-mars' : 'fa-venus' ?>"></i>
                                        <?= $peserta['jenis_kelamin'] == 'Laki-laki' ? 'L' : 'P' ?>
                                    </span>
                                </td>
                                <td class="px-3 py-3">
                                    <span class="px-2 py-1 rounded-full text-xs font-medium bg-archery-100 text-archery-700"><?= htmlspecialchars($peserta['category_name']) ?></span>
                                    <p class="text-xs text-slate-400 mt-0.5"><?= $peserta['min_age'] ?>-<?= $peserta['max_age'] ?> thn (<?= $peserta['category_gender'] == 'Campuran' ? 'All' : $peserta['category_gender'] ?>)</p>
                                </td>
                                <td class="px-3 py-3 text-sm text-slate-600"><?= htmlspecialchars($peserta['asal_kota'] ?: '-') ?></td>
                                <td class="px-3 py-3 text-sm text-slate-600 max-w-32 truncate"><?= htmlspecialchars($peserta['nama_club'] ?: '-') ?></td>
                                <td class="px-3 py-3 text-sm text-slate-600 max-w-32 truncate"><?= htmlspecialchars($peserta['sekolah'] ?: '-') ?></td>
                                <td class="px-3 py-3 text-sm text-slate-600"><?= htmlspecialchars($peserta['kelas'] ?: '-') ?></td>
                                <td class="px-3 py-3">
                                    <a href="tel:<?= htmlspecialchars($peserta['nomor_hp']) ?>" class="text-sm text-archery-600 hover:text-archery-700"><?= htmlspecialchars($peserta['nomor_hp']) ?></a>
                                </td>
                                <td class="px-3 py-3 text-center">
                                    <?php if (!empty($peserta['bukti_pembayaran'])): ?>
                                    <button class="payment-icon text-lg" onclick="showPaymentModal('<?= htmlspecialchars($peserta['nama_peserta']) ?>', '<?= $peserta['bukti_pembayaran'] ?>')" title="Lihat bukti">
                                        ✅
                                    </button>
                                    <?php else: ?>
                                    <span class="text-lg" title="Belum bayar">❌</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="px-4 py-3 bg-slate-50 border-t border-slate-200">
                    <p class="text-sm text-slate-500">Menampilkan <?= count($pesertaList) ?> peserta</p>
                </div>
                <?php else: ?>
                <div class="py-12 text-center">
                    <div class="w-16 h-16 rounded-full bg-slate-100 flex items-center justify-center mx-auto mb-3">
                        <i class="fas fa-inbox text-slate-400 text-2xl"></i>
                    </div>
                    <?php if (!empty($search) || $filter_kategori > 0 || !empty($filter_gender)): ?>
                    <p class="text-slate-500 font-medium">Tidak ada peserta yang sesuai filter</p>
                    <a href="?kegiatan_id=<?= $kegiatan_id ?>" class="inline-flex items-center gap-2 mt-3 px-4 py-2 rounded-lg bg-slate-200 text-slate-700 text-sm font-medium hover:bg-slate-300 transition-colors">
                        <i class="fas fa-redo"></i> Reset Filter
                    </a>
                    <?php else: ?>
                    <p class="text-slate-500 font-medium">Belum ada peserta terdaftar</p>
                    <a href="pendaftaran.php?kegiatan_id=<?= $kegiatan_id ?>" class="inline-flex items-center gap-2 mt-3 px-4 py-2 rounded-lg bg-archery-600 text-white text-sm font-medium hover:bg-archery-700 transition-colors">
                        <i class="fas fa-plus"></i> Daftarkan Peserta
                    </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Mobile Card View -->
            <div class="md:hidden space-y-4">
                <?php if ($totalPeserta > 0): ?>
                <?php foreach ($pesertaList as $index => $peserta): ?>
                <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-4 border-l-4 border-l-archery-500">
                    <div class="flex items-start gap-3 mb-3 pb-3 border-b border-slate-100">
                        <div class="w-8 h-8 rounded-full bg-archery-600 text-white flex items-center justify-center text-sm font-bold flex-shrink-0">
                            <?= $index + 1 ?>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="font-semibold text-slate-900 truncate"><?= htmlspecialchars($peserta['nama_peserta']) ?></p>
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium <?= $peserta['jenis_kelamin'] == 'Laki-laki' ? 'bg-blue-100 text-blue-700' : 'bg-pink-100 text-pink-700' ?>">
                                <i class="fas <?= $peserta['jenis_kelamin'] == 'Laki-laki' ? 'fa-mars' : 'fa-venus' ?>"></i>
                                <?= $peserta['jenis_kelamin'] ?>, <?= $peserta['umur'] ?> th
                            </span>
                        </div>
                        <div class="flex-shrink-0">
                            <?php if (!empty($peserta['bukti_pembayaran'])): ?>
                            <button class="text-xl" onclick="showPaymentModal('<?= htmlspecialchars($peserta['nama_peserta']) ?>', '<?= $peserta['bukti_pembayaran'] ?>')">✅</button>
                            <?php else: ?>
                            <span class="text-xl">❌</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3 text-sm mb-3">
                        <div>
                            <p class="text-xs text-slate-400 uppercase tracking-wide">Kategori</p>
                            <p class="font-medium text-slate-700"><?= htmlspecialchars($peserta['category_name']) ?></p>
                            <p class="text-xs text-slate-400"><?= $peserta['min_age'] ?>-<?= $peserta['max_age'] ?> thn</p>
                        </div>
                        <div>
                            <p class="text-xs text-slate-400 uppercase tracking-wide">Kota</p>
                            <p class="font-medium text-slate-700"><?= htmlspecialchars($peserta['asal_kota'] ?: '-') ?></p>
                        </div>
                        <div>
                            <p class="text-xs text-slate-400 uppercase tracking-wide">Club</p>
                            <p class="font-medium text-slate-700 truncate"><?= htmlspecialchars($peserta['nama_club'] ?: '-') ?></p>
                        </div>
                        <div>
                            <p class="text-xs text-slate-400 uppercase tracking-wide">Sekolah</p>
                            <p class="font-medium text-slate-700 truncate"><?= htmlspecialchars($peserta['sekolah'] ?: '-') ?></p>
                        </div>
                    </div>
                    <div class="flex items-center justify-between pt-3 border-t border-slate-100">
                        <a href="tel:<?= htmlspecialchars($peserta['nomor_hp']) ?>" class="inline-flex items-center gap-1 text-archery-600 text-sm font-medium">
                            <i class="fas fa-phone"></i> <?= htmlspecialchars($peserta['nomor_hp']) ?>
                        </a>
                        <span class="text-xs text-slate-400">Lahir: <?= date('d/m/Y', strtotime($peserta['tanggal_lahir'])) ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php else: ?>
                <div class="bg-white rounded-xl border border-slate-200 p-8 text-center">
                    <div class="w-16 h-16 rounded-full bg-slate-100 flex items-center justify-center mx-auto mb-3">
                        <i class="fas fa-inbox text-slate-400 text-2xl"></i>
                    </div>
                    <?php if (!empty($search) || $filter_kategori > 0 || !empty($filter_gender)): ?>
                    <p class="text-slate-500 font-medium mb-3">Tidak ada peserta yang sesuai filter</p>
                    <a href="?kegiatan_id=<?= $kegiatan_id ?>" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-slate-200 text-slate-700 text-sm font-medium">Reset Filter</a>
                    <?php else: ?>
                    <p class="text-slate-500 font-medium mb-3">Belum ada peserta terdaftar</p>
                    <a href="pendaftaran.php?kegiatan_id=<?= $kegiatan_id ?>" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-archery-600 text-white text-sm font-medium">Daftarkan Peserta</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Category Distribution -->
            <?php if (!empty($statistik['kategori'])): ?>
            <div class="mt-6 bg-slate-50 rounded-xl border border-slate-200 p-5">
                <h4 class="font-semibold text-slate-900 mb-4 flex items-center gap-2">
                    <i class="fas fa-chart-pie text-archery-600"></i> Distribusi per Kategori
                </h4>
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
                    <?php foreach ($statistik['kategori'] as $kategori => $jumlah): ?>
                    <div class="bg-white rounded-lg p-3 text-center shadow-sm">
                        <p class="font-medium text-slate-700 text-sm"><?= htmlspecialchars($kategori) ?></p>
                        <p class="text-lg font-bold text-archery-600"><?= $jumlah ?></p>
                        <p class="text-xs text-slate-400">orang</p>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Payment Modal - Tailwind styled -->
    <div id="paymentModal" class="modal">
        <div class="modal-content">
            <div class="bg-gradient-to-br from-archery-600 to-archery-800 text-white px-6 py-4 relative">
                <button class="modal-close" onclick="closePaymentModal()">&times;</button>
                <h3 id="modal-title" class="font-semibold text-lg pr-8">Bukti Pembayaran</h3>
            </div>
            <div class="p-6 text-center">
                <div id="modal-image-container"></div>
            </div>
        </div>
    </div>

    <script>
        document.getElementById('filter_kategori').addEventListener('change', function () {
            updateInputButton();
        });

        document.getElementById('filter_gender').addEventListener('change', function () {
            updateInputButton();
        });

        document.getElementById('search').addEventListener('keypress', function (e) {
            if (e.key === 'Enter') {
                this.form.submit();
            }
        });

        document.getElementById('search').addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                this.value = '';
                this.form.submit();
            }
        });

        function updateInputButton() {
            const kategoriSelect = document.getElementById('filter_kategori');
            const inputBtn = document.getElementById('inputBtn');

            if (kategoriSelect.value && kategoriSelect.value !== '') {
                inputBtn.classList.add('show');
                inputBtn.href = 'detail.php?action=scorecard&resource=index&kegiatan_id=<?= $kegiatan_id ?>&category_id=' + kategoriSelect.value;
            } else {
                inputBtn.classList.remove('show');
            }
        }

        function goToInput(e) {
            const kategoriSelect = document.getElementById('filter_kategori');

            if (!kategoriSelect.value || kategoriSelect.value === '') {
                e.preventDefault();
                alert('Silakan pilih kategori terlebih dahulu!');
                return false;
            }

            window.location.href = 'detail.php?action=scorecard&resource=index&kegiatan_id=<?= $kegiatan_id ?>&category_id=' + kategoriSelect.value;
        }

        document.addEventListener('DOMContentLoaded', function () {
            updateInputButton();
        });

        function showPaymentModal(namaPeserta, fileName) {
            const modal = document.getElementById('paymentModal');
            const modalTitle = document.getElementById('modal-title');
            const imageContainer = document.getElementById('modal-image-container');

            modalTitle.textContent = 'Bukti Pembayaran - ' + namaPeserta;

            const fileExtension = fileName.toLowerCase().split('.').pop();
            const imagePath = '../assets/uploads/pembayaran/' + fileName;

            if (['jpg', 'jpeg', 'png', 'gif'].includes(fileExtension)) {
                imageContainer.innerHTML = `
                    <img src="${imagePath}" alt="Bukti Pembayaran" style="max-width: 100%; max-height: 500px; border-radius: 8px;">
                    <div style="margin-top: 15px; padding: 10px; background: #f8f9fa; border-radius: 6px; font-size: 14px; color: #666;">
                        <strong>File:</strong> ${fileName}<br>
                        <strong>Peserta:</strong> ${namaPeserta}
                    </div>
                `;
            } else if (fileExtension === 'pdf') {
                imageContainer.innerHTML = `
                    <div style="text-align: center; padding: 40px;">
                        <div style="font-size: 48px; color: #dc3545; margin-bottom: 20px;">📄</div>
                        <h4>File PDF</h4>
                        <p style="margin: 15px 0; color: #666;">File bukti pembayaran dalam format PDF</p>
                        <a href="${imagePath}" target="_blank" class="btn btn-primary" style="margin: 10px;">Buka PDF</a>
                        <a href="${imagePath}" download="${fileName}" class="btn btn-success" style="margin: 10px;">Download</a>
                        <div style="margin-top: 20px; padding: 10px; background: #f8f9fa; border-radius: 6px; font-size: 14px; color: #666;">
                            <strong>File:</strong> ${fileName}<br>
                            <strong>Peserta:</strong> ${namaPeserta}
                        </div>
                    </div>
                `;
            } else {
                imageContainer.innerHTML = `
                    <div style="text-align: center; padding: 40px;">
                        <div style="font-size: 48px; color: #ffc107; margin-bottom: 20px;">⚠️</div>
                        <h4>File tidak dapat ditampilkan</h4>
                        <p style="margin: 15px 0; color: #666;">Format file tidak didukung untuk preview</p>
                        <a href="${imagePath}" target="_blank" class="btn btn-primary" style="margin: 10px;">Buka File</a>
                        <a href="${imagePath}" download="${fileName}" class="btn btn-success" style="margin: 10px;">Download</a>
                        <div style="margin-top: 20px; padding: 10px; background: #f8f9fa; border-radius: 6px; font-size: 14px; color: #666;">
                            <strong>File:</strong> ${fileName}<br>
                            <strong>Peserta:</strong> ${namaPeserta}
                        </div>
                    </div>
                `;
            }

            modal.style.display = 'block';
        }

        function closePaymentModal() {
            document.getElementById('paymentModal').style.display = 'none';
        }

        window.onclick = function (event) {
            const modal = document.getElementById('paymentModal');
            if (event.target == modal) {
                modal.style.display = 'none';
            }
        }

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closePaymentModal();
            }
        });
    </script>
</body>

</html>
<?php
$conn->close();
?>