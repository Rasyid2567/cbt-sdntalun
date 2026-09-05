<?php
/**
 * Shared Header & Navbar Layout Guru
 *
 * @var array       $currentUser
 * @var string      $page
 * @var string|null $pageTitle
 * @var array|null  $flash
 */

$activePage = $page ?? 'home';
$title = !empty($pageTitle) ? $pageTitle . ' - CBT Guru' : 'Dashboard Guru - CBT System';
$flash = $flash ?? flash_get();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title><?= sanitize($title) ?></title>
    <link rel="icon" type="image/svg+xml" href="<?= base_url('assets/img/favicon.svg') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/cbt-style.css') ?>">
    <?= $extraCss ?? '' ?>
</head>
<body>

<header class="cbt-navbar no-print">
    <div class="cbt-navbar-header">
        <a href="<?= base_url('guru') ?>" class="cbt-navbar-brand">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M22 10v6M2 10l10-5 10 5-10 5z"></path>
                <path d="M6 12v5c3 3 9 3 12 0v-5"></path>
            </svg>
            <span>CBT <?= strtoupper(htmlspecialchars($currentUser['role'] ?? 'guru')) ?></span>
        </a>
        <button type="button" class="cbt-menu-toggle" aria-label="Toggle Menu" onclick="toggleNavMenu(event)">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <line x1="4" y1="6" x2="20" y2="6"></line>
                <line x1="4" y1="12" x2="20" y2="12"></line>
                <line x1="4" y1="18" x2="20" y2="18"></line>
            </svg>
        </button>
    </div>
    <nav class="cbt-nav" id="cbt-nav-menu">
        <ul class="cbt-nav-links">
            <?php if (($currentUser['role'] ?? 'guru') === 'guru'): ?>
                <li><a href="<?= base_url('guru') ?>" class="<?= in_array($activePage, ['home', 'dashboard'], true) ? 'active' : '' ?>">Dashboard</a></li>
                <li><a href="<?= base_url('guru?page=bank_soal') ?>" class="<?= in_array($activePage, ['bank_soal', 'tambah_soal', 'import_soal'], true) ? 'active' : '' ?>">Bank Soal</a></li>
                <li><a href="<?= base_url('guru?page=sesi_ujian') ?>" class="<?= $activePage === 'sesi_ujian' ? 'active' : '' ?>">Sesi Ujian & Token</a></li>
                <li><a href="<?= base_url('guru?page=rekap_nilai') ?>" class="<?= in_array($activePage, ['rekap_nilai', 'detail_jawaban'], true) ? 'active' : '' ?>">Rekap Nilai</a></li>
            <?php else: ?>
                <li><a href="<?= base_url('operator') ?>">Dashboard</a></li>
                <li><a href="<?= base_url('operator?page=siswa_crud') ?>">Siswa</a></li>
                <li><a href="<?= base_url('operator?page=guru_crud') ?>">Guru</a></li>
            <?php endif; ?>
            <li><a href="<?= base_url('logout.php') ?>" class="btn-danger">Keluar</a></li>
        </ul>
    </nav>
</header>
