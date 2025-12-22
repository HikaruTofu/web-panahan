<?php
// Include konfigurasi database
include "../config/panggil.php";

if($_SESSION['role']  != 'admin') {
    header('Location: ../views/kegiatan.view.php');
    exit;
}

if(isset($_GET['id'])) {
    $users_show = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM users WHERE id=".$_GET['id']));
}
// Proses form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $role = $_POST['role'];
    $status = $_POST['status'];
    
    // Validasi basic
    if (!empty($name) && !empty($email) && !empty($role) && !empty($status)) {
        try {
            // Hash password untuk keamanan
            
            // Cek apakah email sudah ada
            $check_sql = "SELECT id, email FROM users WHERE email = ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param("s", $email);
            $check_stmt->execute();
            $result = $check_stmt->get_result();

            $permission_update = false;
            if ($row = $result->fetch_assoc()) {
                if($row['email'] == $users_show['email']) {
                    // echo "<div class='alert alert-danger'><i class='fas fa-exclamation-circle'></i> Email masih sama!</div>";
                    $permission_update = true;
                } else {
                    if ($result->num_rows > 0) {
                        echo "<div class='alert alert-danger'><i class='fas fa-exclamation-circle'></i> Email sudah terdaftar!</div>";
                        $permission_update = false;
                    } 
                }
            } else {
                $permission_update = true;
            }
            
            if ($permission_update == true) {
                if($password != null) {
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    $sql = "UPDATE users SET name=?, email=?, role=?, status=?, updated_at=NOW() WHERE id=".$_GET['id'];
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("ssss", $name, $email, $role, $status); 
                } else {
                    $sql = "UPDATE users SET name=?, email=?, role=?, password=?, status=?, updated_at=NOW() WHERE id=".$_GET['id'];
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("sssss", $name, $email, $role, $hashed_password, $status);  
                }
                
                if ($stmt->execute()) {
                    echo "<div class='alert alert-success'><i class='fas fa-check-circle'></i> User berhasil diubah!</div>";
                } else {
                    echo "<div class='alert alert-danger'><i class='fas fa-exclamation-circle'></i> Error: " . $conn->error . "</div>";
                }
                $stmt->close();  
            } 
            $check_stmt->close();
            
        } catch (Exception $e) {
            echo "<div class='alert alert-danger'><i class='fas fa-exclamation-circle'></i> Error: " . $e->getMessage() . "</div>";
        }
    } else {
        echo "<div class='alert alert-warning'><i class='fas fa-exclamation-triangle'></i> Semua field harus diisi!</div>";
    }
}


?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit User</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .card {
            border: none;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            backdrop-filter: blur(10px);
            background: rgba(255,255,255,0.95);
        }
        
        .card-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 20px 20px 0 0 !important;
            padding: 2rem;
        }
        
        .form-control, .form-select {
            border-radius: 12px;
            border: 2px solid #e9ecef;
            padding: 12px 16px;
            transition: all 0.3s ease;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
            transform: translateY(-2px);
        }
        
        .btn {
            border-radius: 12px;
            padding: 12px 24px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.4);
        }
        
        .btn-secondary {
            background: #6c757d;
            border: none;
        }
        
        .btn-secondary:hover {
            transform: translateY(-2px);
            background: #5a6268;
        }
        
        .btn-back {
            background: rgba(255,255,255,0.2);
            color: white;
            border: 2px solid rgba(255,255,255,0.3);
            backdrop-filter: blur(10px);
        }
        
        .btn-back:hover {
            background: rgba(255,255,255,0.3);
            color: white;
            border-color: rgba(255,255,255,0.5);
            transform: translateY(-2px);
        }
        
        .alert {
            border-radius: 12px;
            border: none;
            padding: 16px 20px;
            margin-bottom: 2rem;
        }
        
        .alert-success {
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
            color: #155724;
        }
        
        .alert-danger {
            background: linear-gradient(135deg, #f8d7da 0%, #f1aeb5 100%);
            color: #721c24;
        }
        
        .alert-warning {
            background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
            color: #856404;
        }
        
        .form-label {
            font-weight: 600;
            color: #495057;
            margin-bottom: 8px;
        }
        
        .input-group-text {
            background: #f8f9fa;
            border: 2px solid #e9ecef;
            border-radius: 12px 0 0 12px;
        }
        
        .form-control.with-icon {
            border-radius: 0 12px 12px 0;
        }
        
        .info-card {
            background: rgba(255,255,255,0.1);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: 15px;
            padding: 1.5rem;
            color: white;
            margin-top: 2rem;
        }
        
        .animate-float {
            animation: float 6s ease-in-out infinite;
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
        }
    </style>
</head>
<body>
    <div class="container py-5">
        <!-- Header dengan tombol back -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h1 class="text-white fw-bold mb-2">
                            <i class="fas fa-user-plus me-3"></i>Edit User
                        </h1>
                        <p class="text-white opacity-75">Ubah form dibawah untuk merubah data user ke sistem</p>
                    </div>
                    <a href="users.php" class="btn btn-back animate-float">
                        <i class="fas fa-arrow-left me-2"></i>Kembali ke halaman Users
                    </a>
                </div>
            </div>
        </div>

        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header text-white text-center">
                        <h3 class="mb-0">
                            <i class="fas fa-user-circle me-2"></i>Informasi User
                        </h3>
                    </div>
                    
                    <div class="card-body p-5">
                        <form method="POST">
                            <div class="row mb-4">
                                <div class="col-md-6">
                                    <label for="name" class="form-label">
                                        <i class="fas fa-user text-primary me-2"></i>Nama Lengkap <span class="text-danger">*</span>
                                    </label>
                                    <input type="text" class="form-control" value="<?= $users_show['name'] ?>" id="name" name="name" 
                                           placeholder="Masukkan nama lengkap" required>
                                </div>
                                
                                <div class="col-md-6">
                                    <label for="email" class="form-label">
                                        <i class="fas fa-envelope text-primary me-2"></i>Email Address <span class="text-danger">*</span>
                                    </label>
                                    <input type="email" class="form-control" id="email" value="<?= $users_show['email'] ?>" name="email" 
                                           placeholder="contoh@email.com" required>
                                </div>
                            </div>

                            <div class="mb-4">
                                <label for="password" class="form-label">
                                    <i class="fas fa-lock text-primary me-2"></i>Password
                                </label>
                                <div class="input-group">
                                    <input type="password" class="form-control with-icon" id="password" name="password" 
                                           placeholder="Minimal 6 karakter">
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePassword()">
                                        <i class="fas fa-eye" id="eyeIcon"></i>
                                    </button>
                                </div>
                                <small class="text-muted">Password harus minimal 6 karakter</small>
                            </div>

                            <div class="row mb-4">
                                <div class="col-md-6">
                                    <label for="role" class="form-label">
                                        <i class="fas fa-user-tag text-primary me-2"></i>Role User <span class="text-danger">*</span>
                                    </label>
                                    <select class="form-select" id="role" name="role" required>
                                        <option value="">Pilih Role</option>
                                        <option value="admin" <?= $users_show['role'] == 'admin' ? 'selected' : '' ?>>👑 Admin</option>
                                        <option value="operator" <?= $users_show['role'] == 'operator' ? 'selected' : '' ?>>⚙️ Operator</option>
                                        <option value="viewer" <?= $users_show['role'] == 'viewer' ? 'selected' : '' ?>>👀 Viewer</option>
                                    </select>
                                </div>
                                
                                <div class="col-md-6">
                                    <label for="status" class="form-label">
                                        <i class="fas fa-toggle-on text-primary me-2"></i>Status <span class="text-danger">*</span>
                                    </label>
                                    <select class="form-select" id="status" name="status" required>
                                        <option value="">Pilih Status</option>
                                        <option value="active" <?= $users_show['status'] == 'active' ? 'selected' : '' ?>>✅ Active</option>
                                        <option value="inactive" <?= $users_show['role'] == 'inactive' ? 'selected' : '' ?>>❌ Inactive</option>
                                    </select>
                                </div>
                            </div>

                            <hr class="my-4">

                            <div class="d-flex gap-3 justify-content-end">
                                <button type="reset" class="btn btn-secondary">
                                    <i class="fas fa-undo me-2"></i>Reset Form
                                </button>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save me-2"></i>Simpan User
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Info Card -->
                <div class="info-card">
                    <h5><i class="fas fa-info-circle me-2"></i>Informasi Penting</h5>
                    <ul class="mb-0 mt-3">
                        <li>Password akan otomatis di-enkripsi untuk keamanan</li>
                        <li>Email harus unik dan belum terdaftar sebelumnya</li>
                        <li>Role menentukan hak akses user dalam sistem</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function togglePassword() {
            const passwordField = document.getElementById('password');
            const eyeIcon = document.getElementById('eyeIcon');
            
            if (passwordField.type === 'password') {
                passwordField.type = 'text';
                eyeIcon.classList.remove('fa-eye');
                eyeIcon.classList.add('fa-eye-slash');
            } else {
                passwordField.type = 'password';
                eyeIcon.classList.remove('fa-eye-slash');
                eyeIcon.classList.add('fa-eye');
            }
        }

        // Add floating animation to form elements
        document.addEventListener('DOMContentLoaded', function() {
            const formControls = document.querySelectorAll('.form-control, .form-select');
            formControls.forEach((element, index) => {
                element.style.animationDelay = (index * 0.1) + 's';
                element.addEventListener('focus', function() {
                    this.style.transform = 'translateY(-2px)';
                });
                element.addEventListener('blur', function() {
                    this.style.transform = 'translateY(0px)';
                });
            });
        });
    </script>
</body>
</html>