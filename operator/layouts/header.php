<?php
/**
 * Shared Header Layout Operator
 *
 * @var array       $currentUser
 * @var string      $page
 * @var string|null $pageTitle
 * @var array|null  $flash
 */

$activePage = $page ?? 'home';
$title = !empty($pageTitle) ? $pageTitle . ' - CBT Operator' : 'Dashboard Operator - CBT System';
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
        <a href="<?= base_url('operator') ?>" class="cbt-navbar-brand">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect>
                <line x1="8" y1="21" x2="16" y2="21"></line>
                <line x1="12" y1="17" x2="12" y2="21"></line>
            </svg>
            <span>CBT OPERATOR</span>
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
            <li><a href="<?= base_url('operator') ?>" class="<?= in_array($activePage, ['home', 'dashboard'], true) ? 'active' : '' ?>">Dashboard</a></li>
            <li><a href="<?= base_url('operator?page=siswa_crud') ?>" class="<?= $activePage === 'siswa_crud' ? 'active' : '' ?>">Data Siswa</a></li>
            <li><a href="<?= base_url('operator?page=guru_crud') ?>" class="<?= $activePage === 'guru_crud' ? 'active' : '' ?>">Guru & Mapel</a></li>
            <li><a href="<?= base_url('operator?page=reset_login') ?>" class="<?= $activePage === 'reset_login' ? 'active' : '' ?>">Monitoring & Reset</a></li>
            <li><a href="<?= base_url('logout.php') ?>" class="btn-danger">Keluar</a></li>
        </ul>
    </nav>
</header>
