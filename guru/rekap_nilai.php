<?php
/**
 * Modul Rekapitulasi Nilai Ujian Siswa (Guru Penguji)
 * Mendukung Ekspor CSV dan Tampilan Cetak (Printable View)
 */

require_once __DIR__ . '/../middleware/auth.php';

$currentUser = auth_check(['guru']);
$db = get_db();
$idGuru = $currentUser['id_user'];

// Ambil Daftar Seluruh Sesi Ujian Guru
$stmtSesiAll = $db->prepare("
    SELECT s.id_sesi, s.nama_ujian, m.nama_mapel, k.nama_kelas
    FROM sesi_ujian s
    JOIN mapel m ON s.id_mapel = m.id_mapel
    JOIN kelas k ON s.id_kelas = k.id_kelas
    WHERE s.id_guru = :g
    ORDER BY s.created_at DESC
");
$stmtSesiAll->execute([':g' => $idGuru]);
$allSessions = $stmtSesiAll->fetchAll();

// Tentukan Sesi Terpilih
$selectedSesiId = !empty($_GET['id_sesi']) ? (int)$_GET['id_sesi'] : ($allSessions[0]['id_sesi'] ?? 0);

$sesiDetail = null;
$rekapList = [];
$totalSoalUjian = 0;

if ($selectedSesiId > 0) {
    // Detail Sesi
    $stmtDet = $db->prepare("
        SELECT s.*, m.nama_mapel, k.nama_kelas, k.id_kelas
        FROM sesi_ujian s
        JOIN mapel m ON s.id_mapel = m.id_mapel
        JOIN kelas k ON s.id_kelas = k.id_kelas
        WHERE s.id_sesi = :id AND s.id_guru = :g
    ");
    $stmtDet->execute([':id' => $selectedSesiId, ':g' => $idGuru]);
    $sesiDetail = $stmtDet->fetch();

    if ($sesiDetail) {
        // Hitung total butir soal untuk mapel ini (dan judul_soal jika ada)
        if (!empty($sesiDetail['judul_soal'])) {
            $stmtTotalSoal = $db->prepare("SELECT COUNT(*) FROM bank_soal WHERE id_mapel = :m AND judul_soal = :j");
            $stmtTotalSoal->execute([':m' => $sesiDetail['id_mapel'], ':j' => $sesiDetail['judul_soal']]);
        } else {
            $stmtTotalSoal = $db->prepare("SELECT COUNT(*) FROM bank_soal WHERE id_mapel = :m");
            $stmtTotalSoal->execute([':m' => $sesiDetail['id_mapel']]);
        }
        $totalSoalUjian = (int)$stmtTotalSoal->fetchColumn();

        // Ambil Siswa yang terdaftar di kelas ini dan status pengerjaannya
        $stmtRekap = $db->prepare("
            SELECT u.id_user, u.nis, u.username, u.nama_lengkap, k.nama_kelas,
                   us.id_ujian_siswa, us.waktu_mulai, us.waktu_selesai, us.status as status_ujian,
                   COALESCE(us.jumlah_benar, 0) as jumlah_benar,
                   COALESCE(us.nilai_akhir, 0.00) as nilai_akhir
            FROM users u
            JOIN kelas k ON u.id_kelas = k.id_kelas
            LEFT JOIN ujian_siswa us ON (us.id_siswa = u.id_user AND us.id_sesi = :sesi)
            WHERE u.role = 'siswa' AND u.id_kelas = :kelas
            ORDER BY u.nama_lengkap ASC
        ");
        $stmtRekap->execute([':sesi' => $selectedSesiId, ':kelas' => $sesiDetail['id_kelas']]);
        $rekapList = $stmtRekap->fetchAll();
    }
}

// Tangani Export CSV
if (isset($_GET['action']) && $_GET['action'] === 'export_csv' && $sesiDetail) {
    $filename = 'rekap_nilai_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $sesiDetail['nama_ujian']) . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename);

    $output = fopen('php://output', 'w');
    // UTF-8 BOM untuk kompatibilitas Microsoft Excel
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    // Beritahu Excel untuk memecah kolom dengan koma secara otomatis
    fwrite($output, "sep=,\n");
    fputcsv($output, ['No', 'NIS', 'Username', 'Nama Lengkap Siswa', 'Kelas', 'Waktu Mulai', 'Waktu Selesai', 'Status Ujian', 'Jumlah Benar', 'Total Soal', 'Nilai Akhir']);

    foreach ($rekapList as $idx => $r) {
        fputcsv($output, [
            $idx + 1,
            $r['nis'] ?? '-',
            $r['username'],
            $r['nama_lengkap'],
            $r['nama_kelas'],
            $r['waktu_mulai'] ?? '-',
            $r['waktu_selesai'] ?? '-',
            strtoupper($r['status_ujian'] ?? 'BELUM'),
            $r['jumlah_benar'],
            $totalSoalUjian,
            $r['nilai_akhir']
        ]);
    }

    fclose($output);
    exit;
}

$flash = flash_get();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Rekapitulasi Nilai Ujian - CBT Guru</title>
    <link rel="stylesheet" href="<?= base_url('assets/css/cbt-style.css') ?>">
</head>
<body>

<header class="cbt-navbar no-print">
    <div class="cbt-navbar-header">
        <a href="<?= base_url('guru/dashboard.php') ?>" class="cbt-navbar-brand">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg>
            <span>CBT GURU</span>
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
            <li><a href="<?= base_url('guru/dashboard.php') ?>">Dashboard</a></li>
            <li><a href="<?= base_url('guru/bank_soal.php') ?>">Bank Soal</a></li>
            <li><a href="<?= base_url('guru/sesi_ujian.php') ?>">Sesi Ujian & Token</a></li>
            <li><a href="<?= base_url('guru/rekap_nilai.php') ?>" class="active">Rekap Nilai</a></li>
            <li><a href="<?= base_url('logout.php') ?>" class="btn-danger">Keluar</a></li>
        </ul>
    </nav>
</header>

<main class="container">
    <?php if ($flash): ?>
        <div class="alert alert-<?= sanitize($flash['type']) ?> no-print">
            <?= sanitize($flash['message']) ?>
        </div>
    <?php endif; ?>

    <div class="card-header no-print">
        <div>
            <h1 class="card-title">Laporan & Rekapitulasi Nilai Ujian</h1>
        </div>
        <?php if ($sesiDetail): ?>
            <div class="card-header-actions">
                <a href="<?= base_url('guru/rekap_nilai.php?action=export_csv&id_sesi=' . $sesiDetail['id_sesi']) ?>" class="btn btn-secondary">Ekspor CSV</a>
                <button type="button" class="btn btn-primary" onclick="window.print()">Cetak Laporan</button>
            </div>
        <?php endif; ?>
    </div>

    <!-- Pilih Sesi Ujian -->
    <div class="card no-print" style="padding: 1rem 1.25rem;">
        <form method="GET" action="<?= base_url('guru/rekap_nilai.php') ?>" class="filter-form-responsive">
            <label for="select_sesi" class="font-bold">Pilih Sesi Ujian:</label>
            <div class="filter-row">
                <select name="id_sesi" id="select_sesi" class="form-control" onchange="this.form.submit()">
                    <?php if (empty($allSessions)): ?>
                        <option value="">Belum ada sesi ujian</option>
                    <?php else: ?>
                        <?php foreach ($allSessions as $as): ?>
                            <option value="<?= $as['id_sesi'] ?>" <?= ($selectedSesiId == $as['id_sesi']) ? 'selected' : '' ?>>
                                <?= sanitize($as['nama_ujian']) ?> (<?= sanitize($as['nama_mapel']) ?> - <?= sanitize($as['nama_kelas']) ?>)
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>
        </form>
    </div>

    <?php if ($sesiDetail): ?>
        <!-- Ringkasan Info Ujian -->
        <div class="card">
            <div style="border-bottom: 2px solid var(--gray-800); padding-bottom: 0.75rem; margin-bottom: 1rem;">
                <h2 style="font-size: 1.25rem; font-weight: 800; color: var(--gray-900);"><?= sanitize($sesiDetail['nama_ujian']) ?></h2>
                <div class="flex gap-4 mt-1 text-sm text-muted" style="flex-wrap: wrap;">
                    <div><strong>Mata Pelajaran:</strong> <?= sanitize($sesiDetail['nama_mapel']) ?></div>
                    <div><strong>Kelas:</strong> <?= sanitize($sesiDetail['nama_kelas']) ?></div>
                    <div><strong>Durasi:</strong> <?= $sesiDetail['durasi_menit'] ?> Menit</div>
                    <div><strong>Total Soal:</strong> <?= $totalSoalUjian ?> Butir</div>
                </div>
            </div>

            <!-- Tabel Nilai Siswa (Auto-Card on Mobile) -->
            <div class="table-responsive table-mobile-cards">
                <table class="table">
                    <thead>
                        <tr>
                            <th style="width: 40px;">No</th>
                            <th>NIS</th>
                            <th>Username</th>
                            <th>Nama Siswa</th>
                            <th>Waktu Mulai</th>
                            <th>Waktu Selesai</th>
                            <th>Status</th>
                            <th style="text-align: center;">Benar</th>
                            <th style="text-align: center;">Nilai Akhir</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rekapList)): ?>
                            <tr><td colspan="9" class="text-center text-muted" style="padding: 2rem;">Belum ada data siswa di kelas ini.</td></tr>
                        <?php else: ?>
                            <?php foreach ($rekapList as $idx => $r): ?>
                                <tr>
                                    <td data-label="No"><?= $idx + 1 ?></td>
                                    <td data-label="NIS"><span class="badge" style="background:#e0f2fe; color:#0369a1; font-family:monospace;"><?= sanitize($r['nis'] ?? '-') ?></span></td>
                                    <td data-label="Username"><strong><?= sanitize($r['username']) ?></strong></td>
                                    <td data-label="Nama Siswa"><?= sanitize($r['nama_lengkap']) ?></td>
                                    <td data-label="Mulai" class="text-xs"><?= $r['waktu_mulai'] ? date('d/m/y H:i', strtotime($r['waktu_mulai'])) : '-' ?></td>
                                    <td data-label="Selesai" class="text-xs"><?= $r['waktu_selesai'] ? date('d/m/y H:i', strtotime($r['waktu_selesai'])) : '-' ?></td>
                                    <td data-label="Status">
                                        <?php if ($r['status_ujian'] === 'selesai'): ?>
                                            <span class="badge badge-online">SELESAI</span>
                                        <?php elseif ($r['status_ujian'] === 'sedang'): ?>
                                            <span class="badge badge-aktif">SEDANG MENGERJAKAN</span>
                                        <?php else: ?>
                                            <span class="badge badge-offline">BELUM MENGERJAKAN</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Benar" style="text-align: center; font-weight: 600;">
                                        <?= $r['jumlah_benar'] ?> / <?= $totalSoalUjian ?>
                                    </td>
                                    <td data-label="Nilai Akhir" style="text-align: center; font-size: 1.1rem; font-weight: 800; color: <?= ($r['nilai_akhir'] >= 75) ? '#166534' : '#991b1b' ?>;">
                                        <?= number_format((float)$r['nilai_akhir'], 2) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php else: ?>
        <div class="card text-center" style="padding: 3rem 0;">
            <p class="text-muted">Tidak ada sesi ujian yang dipilih atau belum dibuat.</p>
        </div>
    <?php endif; ?>
</main>

<script src="<?= base_url('assets/js/app.js') ?>"></script>
</body>
</html>
