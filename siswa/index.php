<?php
/**
 * CBT SDN Talun 01 - Front Controller Modul Siswa
 * Menangani alur ujian siswa berbasis parameter ?page=...
 */

require_once __DIR__ . '/../middleware/auth.php';

// Fallback routing untuk request file script langsung / clean URL (mendukung Apache & dev server php -S)
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$fileBase = basename($requestPath);
if ($fileBase !== 'index' && $fileBase !== 'siswa' && file_exists(__DIR__ . '/' . $fileBase . '.php')) {
    require __DIR__ . '/' . $fileBase . '.php';
    exit;
}

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
