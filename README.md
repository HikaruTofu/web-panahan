# 🏹 Web Panahan

Sistem manajemen turnamen panahan modern untuk pendaftaran, scoring, dan analitik.

## 🚀 Quick Start (Docker)

1. **Clone & Enter**
   ```bash
   git clone https://github.com/HikaruTofu/web-panahan.git
   cd web-panahan
   ```

2. **Jalankan**
   ```bash
   docker-compose up -d
   ```
   Akses: [http://localhost:8080](http://localhost:8080)

## 🔐 Security Status

Sistem telah di-harden sesuai standar **OWASP Top 10:2025**:
- **Rate Limiting**: Proteksi brute-force & abuse di semua API & form.
- **Input Sanitization**: Global cleaning untuk mencegah XSS & SQLi.
- **Access Control**: Role-based access (Admin/User) yang ketat.
- **CSRF Protection**: Token validasi pada semua state-changing actions.

## 🛠️ Tech Stack

- **Backend**: PHP 8.x + MySQL 8.0
- **Frontend**: Tailwind CSS + Chart.js
- **DevOps**: Docker & Docker Compose

## 📋 SQL Migration (PENTING)

Jika melakukan update dari versi lama ke versi terbaru, Anda **WAJIB** menjalankan perintah SQL di bawah ini agar sistem mengenali role baru (`petugas`).

### Cara Update via phpMyAdmin:
1. Masuk ke **phpMyAdmin** server Anda.
2. Pilih database yang digunakan (`panahan_turnament_new`).
3. Klik tab **"SQL"** di bagian atas.
4. Copy-paste perintah di bawah ini ke dalam kotak teks:
   ```sql
   ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'operator', 'viewer', 'petugas') DEFAULT 'operator';
   ```
5. Klik tombol **"Go"** atau **"Kirim"** di pojok kanan bawah.
6. **Selesai!** Sekarang Anda bisa mengubah role user menjadi "petugas" di menu Manajemen User.

## 👤 Login Default

*Silakan cek database/setup awal untuk detail kredensial admin.*

---
Made with ❤️ for Indonesian Archery Community 🇮🇩
