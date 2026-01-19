# Product Specification Document
## Web Panahan - Archery Tournament Management System

**Version:** 1.0
**Last Updated:** January 2026
**Target Market:** Indonesian Archery Community

---

## 1. Executive Summary

**Web Panahan** is a comprehensive, modern archery tournament management system designed specifically for the Indonesian archery community. The system provides end-to-end tournament organization capabilities including participant registration, scoring, real-time rankings, performance analytics, and elimination bracket management.

### Key Value Propositions
- **Automated Tournament Operations** - Replaces manual scoring and ranking processes
- **Data-Driven Athlete Development** - Performance grading and tracking over time
- **Multi-Category Support** - Handles age groups, distances, and bow types simultaneously
- **OWASP-Compliant Security** - Enterprise-grade protection for participant data

---

## 2. System Overview

### 2.1 Technology Stack
| Layer | Technology |
|-------|------------|
| Backend | PHP 8.x |
| Database | MySQL 8.0 |
| Frontend | Tailwind CSS v4.0 |
| Charts | Chart.js |
| Interactivity | Alpine.js / HTMX |
| Deployment | Docker + Docker Compose |

### 2.2 Deployment Architecture
```
┌─────────────────────────────────────────────────────────────┐
│                    Docker Compose                            │
├───────────────┬───────────────────┬─────────────────────────┤
│  Web Service  │  MySQL 8.0        │  phpMyAdmin             │
│  PHP 8.x      │  Port: 3306       │  Port: 8082             │
│  Port: 8080   │                   │                         │
└───────────────┴───────────────────┴─────────────────────────┘
```

---

## 3. User Roles & Access Control

### 3.1 Role Hierarchy

| Role | Access Level | Primary Functions |
|------|--------------|-------------------|
| **Admin** | Full System | Tournament creation, user management, all CRUD operations, analytics dashboard |
| **Operator** | Operational | Score entry, participant registration, data input |
| **Petugas** (Staff) | Limited Operational | Score input, participant lookup |
| **Viewer** | Read-Only | View public rankings and results |

### 3.2 Authentication Features
- Secure password hashing (bcrypt via `password_verify`)
- Session-based authentication with custom session path
- Role-based route guards on all protected views
- Rate limiting (5 login attempts per 5 minutes)
- CSRF token validation on all forms

---

## 4. Core Features

### 4.1 Tournament Management (Kegiatan)

**Purpose:** Create and manage archery tournaments/training sessions.

**Capabilities:**
- Create new tournaments with name, date, and description
- Assign multiple competition categories to each tournament
- Track participant counts per tournament
- Search and filter tournaments
- Cascade deletion (removes all related participants, scores, brackets)

**Data Model:**
```
kegiatan
├── id (Primary Key)
├── nama_kegiatan (Tournament Name)
├── tanggal (Date)
├── deskripsi (Description)
└── created_at
```

### 4.2 Category System

**Purpose:** Define competition divisions based on age, gender, and distance.

**Supported Parameters:**
- **Age Ranges:** Minimum and maximum age requirements
- **Gender:** Putra (Male), Putri (Female), Mixed
- **Distances:** 3m, 5m, 7m, 10m, 15m, 20m, 30m, 40m, 50m, 70m
- **Bow Types:** Shortbow, Longbow, Recurve, Compound (implied)
- **Quota:** Maximum participants per category
- **Status:** Active/Inactive toggle

**Example Categories from Sample Data:**
| Category | Age Group | Distance |
|----------|-----------|----------|
| Shortbow Pemula Putra 2018 | Born 2018 | 3m |
| Shortbow Pelajar SD Kelas 1-2-3 | Elementary Grades 1-3 | 5m |
| Shortbow Pelajar SD Kelas 4-5-6 | Elementary Grades 4-6 | 7m |
| Shortbow Pelajar SMP | Junior High School | 10m |
| Shortbow Pelajar SMA | Senior High School | 15m |
| Shortbow NON Pelajar (Umum) | Adult/General | 20m |

### 4.3 Participant Registration (Peserta)

**Purpose:** Register and manage athlete profiles.

**Collected Information:**
- **Personal Data:** Full name, date of birth, gender, phone number, city
- **Affiliation:** Club name, school name, grade level
- **Payment:** Proof of payment upload (JPG/PNG/PDF, max 2MB)
- **Categories:** Multi-category registration support

**Features:**
- Auto-populate participant data from existing club members
- Age validation against category requirements
- Duplicate participant detection and merge capability
- Search by name, club, or city
- Export participant lists to Excel

### 4.4 Scoring System

**Purpose:** Record and calculate competition scores.

#### 4.4.1 Score Entry Workflow
1. Admin assigns participants to **Bantalan** (target positions)
2. Operators input scores per session/arrow
3. System calculates totals automatically
4. Rankings update in real-time

#### 4.4.2 Scoring Format
| Parameter | Description |
|-----------|-------------|
| Sessions | Multiple rounds (e.g., 6 sessions) |
| Arrows per Session | Configurable (e.g., 5 arrows) |
| Score Values | 0-10 numeric |
| Special Values | **X** = 10 points + perfect center bonus, **M** = Miss (0 points) |

#### 4.4.3 Ranking Calculation
Rankings are determined by:
1. **Total Score** - Sum of all arrow scores
2. **X Count** - Number of perfect center hits (tiebreaker)
3. **Category Rank** - Position within competition category

### 4.5 Bantalan Assignment (Target Positions)

**Purpose:** Organize athletes on shooting line targets.

**Features:**
- Assign participants to numbered target positions
- Random shuffle option for fair distribution
- Filter by tournament and category
- Export assignments to Excel for printing

### 4.6 Bracket Tournament System

**Purpose:** Manage elimination rounds for top qualifiers.

**Capabilities:**
- Single/double elimination formats
- Configurable bracket sizes (8, 16, 32 participants)
- Match-by-match result tracking
- Third-place playoff support
- Champion, runner-up, and third-place recording

**Data Flow:**
```
Qualification Round → Top 8/16/32 → Bracket Matches → Finals → Champions
```

### 4.7 Analytics Dashboard

**Purpose:** Provide performance insights for athletes and clubs.

#### 4.7.1 Performance Grading System

| Grade | Label (Indonesian) | Label (English) | Criteria |
|-------|-------------------|-----------------|----------|
| **A** | Sangat Baik | Excellent | Champions or weighted score ≥80 |
| **B** | Baik | Good | Weighted score ≥50 |
| **C** | Cukup | Adequate | Weighted score ≥30 |
| **D** | Perlu Latihan | Needs Training | Weighted score <30 |
| **E** | Pemula | Beginner | No tournament history |

#### 4.7.2 Weighted Scoring Formula
```
Performance Score = (100 / Rank) + (10 × log₂(Total)) - Rank
```

#### 4.7.3 Experience Floor System
Athletes with significant tournament history receive grade floors:
- **10+ tournaments:** Minimum Grade C (promotes D/E to C)
- **5-9 tournaments:** Minimum Grade D (promotes E to D)

#### 4.7.4 Dashboard Widgets
- **Top Performers:** Sorted by performance points
- **Underperformers:** Athletes needing improvement focus
- **Club Statistics:** Athlete count and performance by club
- **Tournament Filter:** View stats for all or specific tournaments

### 4.8 Athlete Statistics (Statistik)

**Purpose:** Individual athlete profile and performance tracking.

**Displayed Information:**
- Personal profile details
- Tournament participation history
- Win/loss record (championships, runner-ups, third places)
- Performance grade trends over time
- Bracket match statistics
- Personalized improvement recommendations

### 4.9 Reporting & Export

**Export Formats:**
- **Excel (.xlsx):** Participant lists, score sheets, rankings, bantalan assignments

**Export Options:**
- Filter by tournament, category, gender
- Professional formatting with headers, footers, styling
- Pre-configured templates for printing

---

## 5. Security Implementation

### 5.1 OWASP Top 10:2025 Compliance

| Vulnerability | Mitigation |
|--------------|------------|
| Injection (SQL/XSS) | Prepared statements, input sanitization, output escaping |
| Broken Authentication | bcrypt hashing, session regeneration, rate limiting |
| Sensitive Data Exposure | HTTPS enforcement (production), secure session handling |
| Broken Access Control | Role-based guards on all views and APIs |
| Security Misconfiguration | Docker isolation, minimal permissions |
| Cross-Site Request Forgery | CSRF token validation on all POST requests |

### 5.2 Security Functions
Located in `includes/security.php`:

```php
// Key security functions
generateCsrfToken()      // Create CSRF tokens
verifyCsrfToken($token)  // Validate form submissions
checkRateLimit($action)  // Prevent brute force attacks
cleanInput($data)        // Sanitize user input
logSecurityEvent($event) // Audit trail
```

### 5.3 File Upload Validation
- MIME type verification using `finfo`
- File size limit: 2MB
- Whitelist: JPG, PNG, PDF only
- Secure storage in `assets/uploads/pembayaran/`

---

## 6. Data Recovery System

**Purpose:** Protect against accidental data loss.

**Features:**
- Automatic JSON backup before record deletion
- 90-day backup retention
- Admin-accessible recovery interface
- Restore individual records on demand
- 10% random cleanup on admin page loads (storage management)

---

## 7. User Interface

### 7.1 Design Philosophy
**"Intentional Minimalism"** - Clean, efficient interfaces optimized for data-heavy operations.

### 7.2 Visual Style
- **Color Palette:**
  - Base: Slate/Zinc neutrals
  - Accent: Archery Green (`#16a34a`)
- **Typography:** System fonts, monospace for data
- **Icons:** Font Awesome library
- **Layout:** High-density data grids for administrative efficiency

### 7.3 Dark Mode Support
- Toggle between light/dark themes
- Cookie-based persistence
- Tailwind `dark:` class strategy
- Full component coverage

### 7.4 Responsive Design
- Mobile-friendly layouts
- Tablet-optimized data tables
- Desktop-first for administrative functions

---

## 8. Experimental Features

### 8.1 OCR Score Entry (Prototype)

**Location:** `ocr_testing/`

**Purpose:** Scan handwritten score sheets using AI.

**Technology:**
- Google Gemini AI (client-side)
- Image upload and processing
- Automated score extraction
- Manual verification step

**Status:** Prototype - Not production-ready

---

## 9. API Endpoints

### 9.1 Available APIs

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `actions/api/get_athlete_stats.php` | GET | Comprehensive athlete statistics |
| `actions/api/get_athlete_detail.php` | GET | Individual athlete profile |
| `actions/api/get_club_members.php` | GET | List members of a club |
| `actions/get_bracket_stats.php` | GET | Bracket tournament statistics |

### 9.2 Response Format
All APIs return JSON with consistent structure:
```json
{
  "success": true|false,
  "data": {...},
  "error": "Error message if applicable"
}
```

---

## 10. Workflow Examples

### 10.1 Complete Tournament Workflow

```
1. CREATE TOURNAMENT
   Admin → Kegiatan → Create new activity
   ↓
2. CONFIGURE CATEGORIES
   Admin → Select categories → Assign to tournament
   ↓
3. OPEN REGISTRATION
   Participants → Peserta → Register with payment proof
   ↓
4. ASSIGN BANTALAN
   Admin → Pertandingan → Assign target positions
   ↓
5. CONDUCT SCORING
   Operators → Peserta Lomba → Input scores per session
   ↓
6. VIEW RANKINGS
   System → Auto-calculate → Display rankings
   ↓
7. RUN BRACKETS (Optional)
   Admin → Detail → Create elimination brackets
   ↓
8. DECLARE CHAMPIONS
   System → Record winners → Update statistics
   ↓
9. EXPORT REPORTS
   Admin → Excel exports → Print certificates
```

### 10.2 Score Entry Workflow

```
┌──────────────┐    ┌──────────────┐    ┌──────────────┐
│  Session 1   │ →  │  Session 2   │ →  │  Session N   │
│  5 arrows    │    │  5 arrows    │    │  5 arrows    │
│  X,10,9,8,7  │    │  10,10,8,9,9 │    │  9,9,9,10,X  │
└──────────────┘    └──────────────┘    └──────────────┘
        ↓                   ↓                   ↓
        └───────────────────┴───────────────────┘
                            ↓
                 ┌────────────────────┐
                 │  TOTAL CALCULATION │
                 │  Score: 578        │
                 │  X Count: 2        │
                 │  Rank: 3           │
                 └────────────────────┘
```

---

## 11. Database Schema Overview

### 11.1 Core Tables (21 Total)

| Table | Purpose |
|-------|---------|
| `users` | System users with roles |
| `peserta` | Athletes/Participants |
| `kegiatan` | Tournaments/Activities |
| `categories` | Competition categories |
| `kegiatan_kategori` | Tournament-category mapping |
| `score` | Individual arrow scores |
| `score_boards` | Score sheet configurations |
| `qualification_scores` | Qualification results |
| `rankings_source` | Imported official rankings |
| `bracket_matches` | Elimination match results |
| `bracket_champions` | Final tournament standings |
| `tournament_participants` | Registration tracking |
| `peserta_rounds` | Round-based tracking |
| `tournament_settings` | Tournament configuration |
| `elimination_results` | Detailed elimination scores |
| `matches` | Match scheduling |
| `match_results` | Match outcomes |
| `tournaments` | Tournament master data |
| `tournament_categories` | Tournament-specific categories |
| `participants` | Alternative participant table |
| `settings` | System settings |

### 11.2 Key Relationships

```
kegiatan (Tournament)
    │
    ├──▶ kegiatan_kategori ◀── categories
    │
    ├──▶ peserta (Participants)
    │        │
    │        └──▶ score (Scores)
    │                 │
    │                 └──▶ score_boards
    │
    └──▶ bracket_matches
             │
             └──▶ bracket_champions
```

---

## 12. File Structure

```
/web-panahan/
├── index.php                       # Login page
├── config/
│   └── panggil.php                # Database connection
├── includes/
│   ├── security.php               # Security helpers
│   ├── check_access.php           # Access control
│   ├── theme.php                  # Dark mode
│   └── recovery.php               # Data backup/restore
├── views/                         # Frontend pages
│   ├── dashboard.php              # Analytics dashboard
│   ├── kegiatan.view.php          # Tournament management
│   ├── categori.view.php          # Category management
│   ├── peserta.view.php           # Participant registration
│   ├── detail.php                 # Details + brackets
│   ├── pertandingan.view.php      # Bantalan assignment
│   ├── peserta_lomba.php          # Score entry
│   ├── statistik.php              # Athlete statistics
│   ├── users.php                  # User management
│   └── recovery.php               # Recovery interface
├── actions/                       # Backend logic
│   ├── logout.php
│   ├── score_akar.php             # Score processing
│   ├── excel_score.php            # Excel exports
│   └── api/                       # API endpoints
├── assets/
│   ├── css/
│   ├── img/
│   └── uploads/pembayaran/        # Payment proofs
├── ocr_testing/                   # OCR prototype
├── docker-compose.yml             # Docker setup
├── Dockerfile                     # PHP container
└── latest_database_backup.sql     # Database backup
```

---

## 13. Localization

### 13.1 Language
- **Primary:** Bahasa Indonesia
- **UI Labels:** All Indonesian
- **Error Messages:** Indonesian
- **Documentation:** Bilingual (Indonesian/English)

### 13.2 Regional Considerations
- Date format: Indonesian standard (DD-MM-YYYY)
- Currency: Indonesian Rupiah (for payment tracking)
- Target clubs: East Kalimantan region (Samarinda, Balikpapan, Bontang, etc.)

---

## 14. Deployment Guide

### 14.1 Quick Start (Docker)
```bash
# Clone repository
git clone https://github.com/HikaruTofu/web-panahan.git
cd web-panahan

# Start services
docker-compose up -d

# Access application
# Web: http://localhost:8080
# phpMyAdmin: http://localhost:8082
```

### 14.2 Production Deployment
1. Import `latest_database_backup.sql` to MySQL 8.0+ server
2. Configure `config/panggil.php` with production credentials
3. Set up HTTPS/SSL certificates
4. Configure backup automation
5. Set proper file permissions for uploads directory

---

## 15. Future Roadmap (Suggested)

### Phase 1: Stability
- [ ] Production OCR integration
- [ ] Automated backup scheduling
- [ ] Email notifications for registration

### Phase 2: Enhancement
- [ ] Multi-language support (English)
- [ ] Mobile app companion
- [ ] Live scoreboard displays
- [ ] QR code check-in

### Phase 3: Scale
- [ ] Multi-tenant support
- [ ] Federation integration
- [ ] Advanced analytics/ML predictions
- [ ] Video replay integration

---

## 16. Support & Maintenance

**Repository:** https://github.com/HikaruTofu/web-panahan

**Database Backup:** `latest_database_backup.sql` (included in repo)

**Default Credentials:** Check database setup or contact administrator

---

*Made with care for the Indonesian Archery Community*
