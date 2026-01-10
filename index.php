<?php
include 'config/panggil.php';
include 'includes/theme.php';
// Cek jika sudah login
if (isset($_SESSION['login']) && $_SESSION['login'] === true) {
    // Redirect berdasarkan role
    $role = strtolower($_SESSION['role'] ?? '');
    session_write_close();
    if ($role === 'admin') {
        header('Location: views/dashboard.php');
    } else {
        header('Location: views/kegiatan.view.php');
    }
    exit;
}

$error_message = '';

if (isset($_POST['submit'])) {
    verify_csrf();
    
    if (!checkRateLimit('login', 5, 300)) {
        $error_message = 'Terlalu banyak percobaan login. Silakan coba lagi dalam 5 menit.';
        security_log("Brute-force attempt detected for user: " . ($_POST['name'] ?? 'unknown'), 'WARNING');
    } else {
        $_POST = cleanInput($_POST);
        $name = $_POST['name'] ?? '';
        $password = $_POST['password'] ?? '';

        if (empty($name) || empty($password)) {
            $error_message = 'Harap isi nama dan password';
        } else {
        $sql = "SELECT * FROM users WHERE name = ?";
        $stmt = $conn->prepare($sql);

        if ($stmt) {
            $stmt->bind_param("s", $name);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();

            if ($user && password_verify($password, $user['password'])) {
                // Set session dengan benar
                $_SESSION['login'] = true;
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['name'];
                $_SESSION['role'] = strtolower($user['role'] ?? 'user');

                // PENTING: Regenerate session ID untuk keamanan
                // session_regenerate_id(true); 

                // Redirect berdasarkan role

                // Redirect berdasarkan role
                $role = strtolower($_SESSION['role'] ?? 'user');
                session_write_close();
                if ($role === 'admin') {
                    header('Location: views/dashboard.php');
                } else {
                    header('Location: views/kegiatan.view.php');
                }
                exit;
            } else {
                $error_message = 'Login gagal! Username atau password salah.';
            }
            $stmt->close();
        }
    }
}
}
?>
<!DOCTYPE html>
<html lang="id" class="h-full">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Sistem Panahan</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script><?= getThemeTailwindConfig() ?></script>
    <script><?= getThemeInitScript() ?></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <style>
        .input-icon-left {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            pointer-events: none;
            transition: color 0.2s ease;
        }
        .input-wrapper:focus-within .input-icon-left {
            color: #16a34a;
        }
        .btn-loading {
            pointer-events: none;
            opacity: 0.7;
        }
        .btn-loading .btn-text {
            visibility: hidden;
        }
        .btn-loading .btn-spinner {
            display: flex;
        }
        .btn-spinner {
            display: none;
            position: absolute;
            inset: 0;
            align-items: center;
            justify-content: center;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        .animate-spin {
            animation: spin 1s linear infinite;
        }
    </style>
</head>

<body class="min-h-screen bg-slate-50 dark:bg-zinc-950 flex items-center justify-center p-4 transition-colors">
    <!-- Theme Toggle Button (Top Right) -->
    <div class="fixed top-4 right-4">
        <?= getThemeToggleButton('bg-white dark:bg-zinc-800 border border-slate-200 dark:border-zinc-700 shadow-sm') ?>
    </div>

    <!-- Main Card -->
    <div class="w-full max-w-md bg-white dark:bg-zinc-900 rounded-xl border border-slate-200 dark:border-zinc-800 shadow-sm overflow-hidden">
        <!-- Header Section -->
        <div class="px-8 pt-10 pb-8 text-center">
            <!-- Logo Icon -->
            <div class="w-14 h-14 bg-archery-100 dark:bg-archery-900/30 rounded-xl flex items-center justify-center mx-auto mb-6">
                <i class="fas fa-bullseye text-archery-600 dark:text-archery-400 text-xl"></i>
            </div>

            <!-- Title -->
            <h1 class="text-2xl font-bold text-slate-900 dark:text-white mb-2">Masuk ke Sistem Panahan</h1>
            <p class="text-slate-500 dark:text-zinc-400 text-sm">Silakan masukkan kredensial Anda untuk melanjutkan.</p>
        </div>

        <!-- Form Section -->
        <div class="px-8 pb-10">
            <!-- Error Alert -->
            <?php if (!empty($error_message)): ?>
                <div class="mb-6 p-4 bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 rounded-lg flex items-start gap-3">
                    <i class="fas fa-circle-exclamation text-red-500 dark:text-red-400 mt-0.5"></i>
                    <p class="text-sm text-red-700 dark:text-red-300"><?php echo htmlspecialchars($error_message); ?></p>
                </div>
            <?php endif; ?>

            <!-- Success Alert (Logout) -->
            <?php if (isset($_GET['message']) && $_GET['message'] == 'logout_success'): ?>
                <div class="mb-6 p-4 bg-emerald-50 dark:bg-emerald-900/30 border border-emerald-200 dark:border-emerald-800 rounded-lg flex items-start gap-3">
                    <i class="fas fa-circle-check text-emerald-600 dark:text-emerald-400 mt-0.5"></i>
                    <p class="text-sm text-emerald-700 dark:text-emerald-300">Logout berhasil! Silakan login kembali.</p>
                </div>
            <?php endif; ?>

            <form method="POST" id="loginForm" class="space-y-5">
                <?php csrf_field(); ?>
                <!-- Username Field -->
                <div>
                    <label for="name" class="block text-sm font-medium text-slate-700 dark:text-zinc-300 mb-2">Nama Pengguna</label>
                    <div class="input-wrapper relative">
                        <i class="fas fa-user input-icon-left text-slate-400 dark:text-zinc-500"></i>
                        <input
                            type="text"
                            id="name"
                            name="name"
                            class="w-full pl-11 pr-4 py-3 bg-slate-50 dark:bg-zinc-800 border border-slate-200 dark:border-zinc-700 rounded-lg text-slate-900 dark:text-white placeholder-slate-400 dark:placeholder-zinc-500 focus:outline-none focus:ring-2 focus:ring-archery-500 focus:border-transparent focus:bg-white dark:focus:bg-zinc-700 transition-all duration-200"
                            placeholder="Masukkan nama Anda"
                            value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''; ?>"
                            required
                        >
                    </div>
                </div>

                <!-- Password Field -->
                <div>
                    <label for="password" class="block text-sm font-medium text-slate-700 dark:text-zinc-300 mb-2">Password</label>
                    <div class="input-wrapper relative">
                        <i class="fas fa-lock input-icon-left text-slate-400 dark:text-zinc-500"></i>
                        <input
                            type="password"
                            id="password"
                            name="password"
                            class="w-full pl-11 pr-12 py-3 bg-slate-50 dark:bg-zinc-800 border border-slate-200 dark:border-zinc-700 rounded-lg text-slate-900 dark:text-white placeholder-slate-400 dark:placeholder-zinc-500 focus:outline-none focus:ring-2 focus:ring-archery-500 focus:border-transparent focus:bg-white dark:focus:bg-zinc-700 transition-all duration-200"
                            placeholder="Masukkan password Anda"
                            required
                        >
                        <button
                            type="button"
                            id="togglePassword"
                            class="absolute right-3 top-1/2 -translate-y-1/2 p-1 text-slate-400 dark:text-zinc-500 hover:text-archery-600 dark:hover:text-archery-400 transition-colors"
                        >
                            <i class="fas fa-eye" id="eyeIcon"></i>
                        </button>
                    </div>
                </div>

                <!-- Forgot Password Link -->
                <div class="text-right">
                    <a href="#" class="text-sm text-slate-400 dark:text-zinc-500 hover:text-archery-600 dark:hover:text-archery-400 transition-colors">
                        Lupa Password?
                    </a>
                </div>

                <!-- Submit Button -->
                <button
                    type="submit"
                    name="submit"
                    id="loginBtn"
                    class="relative w-full py-3 bg-archery-600 hover:bg-archery-700 text-white font-medium rounded-lg shadow-sm hover:shadow transition-all duration-200"
                >
                    <span class="btn-text">Masuk</span>
                    <div class="btn-spinner">
                        <svg class="w-5 h-5 animate-spin text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                    </div>
                </button>
            </form>
        </div>
    </div>

    <script>
        // Password toggle functionality
        const togglePassword = document.getElementById('togglePassword');
        const passwordField = document.getElementById('password');
        const eyeIcon = document.getElementById('eyeIcon');

        togglePassword.addEventListener('click', function() {
            const type = passwordField.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordField.setAttribute('type', type);
            eyeIcon.classList.toggle('fa-eye');
            eyeIcon.classList.toggle('fa-eye-slash');
        });

        // Auto focus on first input
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('name').focus();
        });

        // Enter key navigation
        document.getElementById('name').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                document.getElementById('password').focus();
            }
        });

        // Form submit with loading state
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const btn = document.getElementById('loginBtn');
            const nameValue = document.getElementById('name').value.trim();
            const passwordValue = document.getElementById('password').value.trim();

            if (nameValue && passwordValue) {
                btn.classList.add('btn-loading');
            }
        });

        // Theme Toggle
        <?= getThemeToggleScript() ?>
    </script>
</body>

</html>
