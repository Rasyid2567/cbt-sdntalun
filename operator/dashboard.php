<?php
/**
 * CBT SDN Talun 01 - Front Controller Modul Operator
 * Menangani navigasi seluruh modul operator melalui parameter ?page=...
 */

require_once __DIR__ . '/../middleware/auth.php';

// Pastikan pengguna terautentikasi sebagai Operator
$currentUser = auth_check(['operator']);
$db = get_db();

// Whitelist router untuk mencegah Local File Inclusion (LFI)
$routes = [
    'home'        => __DIR__ . '/pages/home.php',
    'dashboard'   => __DIR__ . '/pages/home.php',
    'siswa_crud'  => __DIR__ . '/pages/siswa_crud.php',
    'guru_crud'   => __DIR__ . '/pages/guru_crud.php',
    'reset_login' => __DIR__ . '/pages/reset_login.php',
];

$pageKey = $_GET['page'] ?? 'home';

if (!isset($routes[$pageKey])) {
    flash_set('warning', 'Halaman yang diminta tidak ditemukan.');
    redirect(base_url('operator?page=home'));
}

// Muat controller / view untuk halaman yang diminta
require $routes[$pageKey];
