<?php
/**
 * CBT SDN Talun 01 - Front Controller Modul Guru
 * Menangani navigasi seluruh modul guru melalui parameter ?page=...
 */

require_once __DIR__ . '/../middleware/auth.php';

// Pastikan pengguna terautentikasi (Guru atau Operator)
$currentUser = auth_check(['guru', 'operator']);
$db = get_db();

// Whitelist router untuk mencegah Local File Inclusion (LFI)
$routes = [
    'home'           => __DIR__ . '/pages/home.php',
    'dashboard'      => __DIR__ . '/pages/home.php',
    'bank_soal'      => __DIR__ . '/pages/bank_soal.php',
    'sesi_ujian'     => __DIR__ . '/pages/sesi_ujian.php',
    'rekap_nilai'    => __DIR__ . '/pages/rekap_nilai.php',
    'detail_jawaban' => __DIR__ . '/pages/detail_jawaban.php',
    'tambah_soal'    => __DIR__ . '/pages/tambah_soal.php',
    'import_soal'    => __DIR__ . '/pages/import_soal.php',
];

$pageKey = $_GET['page'] ?? 'home';

if (!isset($routes[$pageKey])) {
    flash_set('warning', 'Halaman yang diminta tidak ditemukan.');
    redirect(base_url('guru?page=home'));
}

// Muat controller / view untuk halaman yang diminta
require $routes[$pageKey];
