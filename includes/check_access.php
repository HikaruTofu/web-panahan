<?php
// check_access.php
// File ini digunakan untuk mengecek akses user berdasarkan role

// Tidak perlu session_start() karena sudah ada di panggil.php

// Fungsi untuk cek apakah user sudah login
function isLoggedIn() {
    return isset($_SESSION['login']) && $_SESSION['login'] === true;
}

// Fungsi untuk cek apakah user adalah admin
function isAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

// Fungsi untuk cek apakah user adalah operator
function isOperator() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'operator';
}

// Fungsi untuk cek apakah user adalah petugas
function isPetugas() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'petugas';
}

// Fungsi untuk cek apakah user adalah viewer (read-only/guest access)
function isViewer() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'viewer';
}

// Fungsi untuk cek apakah user bisa melakukan aksi (bukan viewer)
function canPerformActions() {
    // Viewer tidak bisa melakukan input/edit/delete
    return isset($_SESSION['role']) && $_SESSION['role'] !== 'viewer';
}

// Fungsi untuk cek apakah user bisa input score (admin, operator, petugas)
function canInputScore() {
    $allowedRoles = ['admin', 'operator', 'petugas'];
    return isset($_SESSION['role']) && in_array($_SESSION['role'], $allowedRoles);
}

    function requireLogin() {
        if (!isLoggedIn()) {
            session_write_close();
            header('Location: ../index.php');
            exit;
        }
    }

// Fungsi untuk redirect jika bukan admin
function requireAdmin() {
    requireLogin(); // Pastikan sudah login dulu
    
    if (!isAdmin()) {
        // Redirect ke halaman yang diizinkan untuk non-admin
        session_write_close();
        header('Location: kegiatan.view.php');
        exit;
    }
}

// Fungsi untuk cek akses halaman
function checkPageAccess($currentPage) {
    requireLogin(); // Pastikan user sudah login

    // Jika admin, bisa akses semua halaman
    if (isAdmin()) {
        return true;
    }

    // Halaman yang bisa diakses viewer (read-only)
    $viewerAllowed = [
        'kegiatan.view.php',
        'statistik.php',
        'detail.php',
        'logout.php'
    ];

    // Daftar halaman yang bisa diakses semua user (kecuali viewer yang lebih terbatas)
    $allowedForAll = [
        'kegiatan.view.php',
        'peserta.view.php',
        'statistik.php',
        'detail.php',
        'logout.php',
        'profile.php' // jika ada halaman profile
    ];

    // Jika viewer, hanya bisa akses halaman tertentu
    if (isViewer()) {
        if (!in_array($currentPage, $viewerAllowed)) {
            session_write_close();
            header('Location: kegiatan.view.php');
            exit;
        }
        return true;
    }

    // Jika bukan admin dan bukan viewer, cek apakah halaman diizinkan
    if (!in_array($currentPage, $allowedForAll)) {
        session_write_close();
        header('Location: kegiatan.view.php');
        exit;
    }

    return true;
}
