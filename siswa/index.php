<?php
/**
 * CBT SDN Talun 01 - Front Controller Modul Siswa
 * Menangani alur ujian siswa berbasis parameter ?page=...
 */

require_once __DIR__ . '/../middleware/auth.php';

// Pastikan pengguna terautentikasi sebagai Siswa
$currentUser = auth_check(['siswa']);
$db = get_db();

// Whitelist router untuk modul Siswa
$routes = [
    'konfirmasi'  => __DIR__ . '/pages/konfirmasi.php',
    'ruang_ujian' => __DIR__ . '/pages/ruang_ujian.php',
    'hasil'       => __DIR__ . '/pages/hasil.php',
];

$pageKey = $_GET['page'] ?? 'konfirmasi';

if (!isset($routes[$pageKey])) {
    redirect(base_url('siswa?page=konfirmasi'));
}

// Muat controller / view untuk halaman yang diminta
require $routes[$pageKey];
