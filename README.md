# 🏹 Web Panahan - Sistem Turnamen Panahan

Sistem manajemen turnamen panahan berbasis web yang digunakan untuk pendaftaran peserta, pencatatan skor, dan pelaporan hasil pertandingan.

## 📋 Fitur

### Manajemen Peserta
- Pendaftaran peserta dengan data lengkap (nama, tanggal lahir, gender, asal kota, klub, sekolah)
- Upload bukti pembayaran
- Pengelompokan berdasarkan kategori umur dan gender

### Kategori Pertandingan
- **Shortbow Pemula** (Putra/Putri) - Jarak 3m
- **Shortbow Pelajar SD** (Kelas 1-3: 5m, Kelas 4-6: 7m)
- **Shortbow Pelajar SMP** - Jarak 10m
- **Shortbow Pelajar SMA** - Jarak 15m
- **Shortbow Non Pelajar** (Putra/Putri) - Jarak 20m
- **Umum** - 30m, 40m, 50m, 70m

### Scoring & Statistik
- Pencatatan skor per ronde (6 anak panah per ronde)
- Export data ke Excel
- Statistik atlet berprestasi (Kategori A-E)
- Dashboard analitik real-time

### Sistem User & UI
- Login dengan role-based access (Admin/User)
- Dark Mode support
- Session management
- Password hashing
- **Security Middleware**: `includes/check_access.php` restricts access based on session roles.

## 🧠 Business Logic & Rules

### 1. Sistem Ranking & Kategori (Grade)
Ranking peserta dikonversi menjadi Grade (A-E) berdasarkan posisi dan persentase ranking mereka terhadap total peserta.
*Logic found in `actions/api/get_athlete_detail.php`*

| Kategori | Label | Kriteria |
|----------|-------|----------|
| **A** | Sangat Baik | Ranking 1-3 AND Top 30% |
| **B** | Baik | Ranking 4-10 AND Top 40% |
| **C** | Cukup | Top 60% |
| **D** | Perlu Latihan | Top 80% |
| **E** | Pemula | Bottom 20% or New |

### 2. Bantalan Assignment (Shuffling)
Sistem menggunakan algoritma pengacak (`betterShuffle` with `mt_srand`) untuk membagikan bantalan secara adil.
*Logic found in `views/pertandingan.view.php`*

- **Grouping**: 3 Peserta per bantalan (A, B, C).
- **Format**: Nomor Bantalan + Huruf (Contoh: 1A, 1B, 1C).
- **Filter**: Bisa difilter per Kegiatan & Kategori sebelum diacak.

### 3. Scoring System
*Logic found in `actions/score_akar.php` & `actions/api/get_athlete_detail.php`*
- **X** dihitung sebagai **10 poin** + hitung jumlah X.
- **M** (Miss) dihitung **0 poin**.
- Ranking diurutkan berdasarkan `Total Score (DESC)` kemudian `Total X (DESC)`.

## 🛠️ Tech Stack

| Layer | Technology |
|-------|------------|
| **Backend** | PHP 8.x |
| **Styling** | Tailwind CSS |
| **Database** | MySQL 8.0 |
| **Frontend** | HTML, CSS, JavaScript |
| **Charts** | Chart.js |
| **Icons** | Font Awesome 6.x |
| **Excel Export** | PhpSpreadsheet |
| **Container** | Docker & Docker Compose |

## 📁 Struktur Proyek

```
web-panahan/
├── index.php                 # Login page
├── docker-compose.yml        # Docker configuration
├── composer.json             # PHP dependencies
├── .gitignore
│
├── actions/                  # Backend actions
│   ├── api/                  # REST API endpoints
│   │   ├── get_athlete_detail.php
│   │   ├── get_athlete_stats.php
│   │   └── get_club_members.php
│   ├── login-sistem.php
│   ├── logout.php
│   ├── excel_score.php       # Excel export
│   ├── score_akar.php        # Score processing
│   ├── tamplate_excel_score.php
│   ├── cleanup_scores.php    # Utility: Cleanup scores
│   ├── debug_ranking.php     # Utility: Debug ranking
│
├── views/                    # Frontend views
│   ├── dashboard.php         # Admin dashboard
│   ├── peserta.view.php      # Manajemen peserta & pendaftaran
│   ├── peserta_lomba.php     # Peserta per lomba
│   ├── kegiatan.view.php     # Daftar kegiatan
│   ├── categori.view.php     # Kategori pertandingan
│   ├── pertandingan.view.php # Pertandingan
│   ├── detail.php            # Detail peserta & skor
│   ├── statistik.php         # Statistik
│   └── users.php             # Manajemen user
│
├── config/
│   └── panggil.php           # Database connection
│
├── includes/
│   └── check_access.php      # Access control
│   └── theme.php             # Theme configuration
│
├── assets/                   # Static assets
│
├── docker/
│   ├── php/
│   │   └── Dockerfile        # PHP container config
│   └── mysql/
│       └── init.db/
│           └── init.sql      # Database schema & seed
│
└── vendor/                   # Composer dependencies
```

## 🔌 API Documentation

Backend menyediakan endpoint JSON untuk data statistik dan detail atlet.

### `GET /actions/api/get_athlete_detail.php`
Mengambil statistik performa atlet, history ranking, dan kategori dominan.

**Parameters:**
- `nama` (string): Nama lengkap peserta.

**Response:**
```json
{
  "success": true,
  "athlete": {
    "nama": "John Doe",
    "kategori_dominan": {"kategori": "B", "label": "Baik"},
    "avg_ranking": 4.5,
    "stat_juara": {"1": 2, "2": 0, "3": 1},
    "rankings": [...]
  }
}
```

## 🔐 Security & Access Control

Akses dikontrol melalui `includes/check_access.php` dengan dua level proteksi:

1.  **Authentication (`requireLogin`)**:
    -   Memastikan `$_SESSION['login'] === true`.
    -   Redirect ke `index.php` jika belum login.

2.  **Authorization (`requireAdmin`)**:
    -   Memastikan `$_SESSION['role'] === 'admin'`.
    -   User biasa (`role: user`) hanya bisa mengakses halaman publik/kegiatan:
        -   `kegiatan.view.php`
        -   `logout.php`
        -   `profile.php`
    -   Admin memiliki akses penuh ke folder `views/` dan `actions/`.

## 🚀 Instalasi

### Menggunakan Docker (Recommended)

1. **Clone repository**
   ```bash
   git clone https://github.com/HikaruTofu/web-panahan.git
   cd web-panahan
   ```

2. **Jalankan container**
   ```bash
   docker-compose up -d
   ```

3. **Akses aplikasi**
   - Web: http://localhost:8080
   - MySQL: localhost:3306

### Konfigurasi Database

Database akan otomatis di-initialize dari `docker/mysql/init.db/init.sql` dengan:
- Database: `panahan_turnament_new`
- Username: `root`
- Password: `root`

### Konfigurasi Manual (Tanpa Docker)
Jika menjalankan manual (tanpa Docker), pastikan untuk menyesuaikan koneksi database di file `config/panggil.php`.

## 📊 Database Schema

### Tabel Utama

| Table | Deskripsi |
|-------|-----------|
| `users` | Data user & admin |
| `peserta` | Data peserta turnamen |
| `participants` | Master data partisipan |
| `categories` | Kategori pertandingan |
| `kegiatan` | Event/kegiatan turnamen |
| `kegiatan_kategori` | Relasi kegiatan-kategori |
| `score` | Skor per anak panah |
| `matches` | Data pertandingan |
| `match_results` | Hasil pertandingan |
| `bracket_matches` | Data bracket eliminasi |
| `bracket_champions` | Juara per bracket |
| `elimination_results` | Hasil eliminasi |

## 👤 Default Login

| Role | Username | Password |
|------|----------|----------|
| Admin | *(lihat database)* | *(hashed)* |

## 📝 License

This project is private and maintained by [HikaruTofu](https://github.com/HikaruTofu).

---

<p align="center">
  Made with ❤️ for Indonesian Archery Community 🇮🇩
</p>
