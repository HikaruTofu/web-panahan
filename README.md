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

### Sistem User
- Login dengan role-based access (Admin/User)
- Session management
- Password hashing

## 🛠️ Tech Stack

| Layer | Technology |
|-------|------------|
| **Backend** | PHP 8.x |
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
│   ├── tambah-user.php
│   ├── edit-user.php
│   ├── hapus-user.php
│   ├── excel_score.php       # Excel export
│   ├── score_akar.php        # Score processing
│   └── tamplate_excel_score.php
│
├── views/                    # Frontend views
│   ├── dashboard.php         # Admin dashboard
│   ├── pendaftaran.php       # Registrasi peserta
│   ├── peserta.view.php      # Daftar peserta
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
