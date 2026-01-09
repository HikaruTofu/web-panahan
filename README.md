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

Jika melakukan update dari versi lama, jalankan perintah ini di database server untuk mendukung role baru:

```sql
ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'operator', 'viewer', 'petugas') DEFAULT 'operator';
```

## 👤 Login Default

*Silakan cek database/setup awal untuk detail kredensial admin.*

---
Made with ❤️ for Indonesian Archery Community 🇮🇩
