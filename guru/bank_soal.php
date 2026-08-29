<?php
/**
 * Modul Bank Soal Guru (Daftar & Hapus Soal Terkelompok: Mapel -> Judul Soal)
 */

require_once __DIR__ . '/../middleware/auth.php';

$currentUser = auth_check(['guru']);
$db = get_db();
$idGuru = $currentUser['id_user'];

// Tangani Hapus Soal via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash_set('danger', 'Validasi token keamanan gagal.');
        redirect(base_url('guru/bank_soal.php'));
    }

    $action = $_POST['action'] ?? '';
    if ($action === 'hapus') {
        $idSoal = (int)($_POST['id_soal'] ?? 0);
        if ($idSoal > 0) {
            // Ambil info gambar untuk dihapus dari server
            $stmtImg = $db->prepare("SELECT gambar FROM bank_soal WHERE id_soal = :id AND id_guru = :g");
            $stmtImg->execute([':id' => $idSoal, ':g' => $idGuru]);
            $soalImg = $stmtImg->fetchColumn();

            if ($soalImg && file_exists(__DIR__ . '/../' . ltrim($soalImg, '/'))) {
                unlink(__DIR__ . '/../' . ltrim($soalImg, '/'));
            }

            $del = $db->prepare("DELETE FROM bank_soal WHERE id_soal = :id AND id_guru = :g");
            $del->execute([':id' => $idSoal, ':g' => $idGuru]);
            flash_set('success', 'Butir soal berhasil dihapus.');
        }
        redirect(base_url('guru/bank_soal.php' . (!empty($_POST['redirect_mapel']) ? '?id_mapel=' . (int)$_POST['redirect_mapel'] : '')));
    }
}

// Filter Mapel & Pencarian
$filterMapel = !empty($_GET['id_mapel']) ? (int)$_GET['id_mapel'] : null;
$search      = trim($_GET['search'] ?? '');

// Ambil Statistik Soal per Mapel untuk Guru ini
$stmtMapel = $db->prepare("
    SELECT m.id_mapel, m.nama_mapel, m.kode_mapel,
           COUNT(b.id_soal) AS total_soal,
           COUNT(DISTINCT b.judul_soal) AS total_paket
    FROM mapel m
    LEFT JOIN bank_soal b ON m.id_mapel = b.id_mapel AND b.id_guru = :g
    GROUP BY m.id_mapel, m.nama_mapel, m.kode_mapel
    ORDER BY m.id_mapel ASC
");
$stmtMapel->execute([':g' => $idGuru]);
$mapelList = $stmtMapel->fetchAll();

$totalSemuaSoal = 0;
foreach ($mapelList as $m) {
    $totalSemuaSoal += (int)$m['total_soal'];
}

// Query Ambil Data Soal
$sql = "
    SELECT b.*, m.nama_mapel, m.kode_mapel 
    FROM bank_soal b
    JOIN mapel m ON b.id_mapel = m.id_mapel
    WHERE b.id_guru = :g
";
$params = [':g' => $idGuru];

if ($filterMapel) {
    $sql .= " AND b.id_mapel = :m";
    $params[':m'] = $filterMapel;
}

if ($search !== '') {
    $sql .= " AND (b.pertanyaan ILIKE :s OR b.judul_soal ILIKE :s)";
    $params[':s'] = "%{$search}%";
}

$sql .= " ORDER BY m.id_mapel ASC, b.judul_soal ASC, b.id_soal ASC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$soalList = $stmt->fetchAll();

// KELOMPOKKAN HIERARKIS: Mapel -> Judul / Paket Soal -> Butir Soal
$grouped = [];
foreach ($soalList as $s) {
    $mId = $s['id_mapel'];
    $jdl = $s['judul_soal'] !== '' ? $s['judul_soal'] : 'Umum';
    if (!isset($grouped[$mId])) {
        $grouped[$mId] = [
            'id_mapel'   => $s['id_mapel'],
            'nama_mapel' => $s['nama_mapel'],
            'kode_mapel' => $s['kode_mapel'],
            'total_soal' => 0,
            'paket'      => []
        ];
    }
    if (!isset($grouped[$mId]['paket'][$jdl])) {
        $grouped[$mId]['paket'][$jdl] = [];
    }
    $grouped[$mId]['paket'][$jdl][] = $s;
    $grouped[$mId]['total_soal']++;
}

$flash = flash_get();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Bank Soal - CBT Guru</title>
    <link rel="stylesheet" href="<?= base_url('assets/css/cbt-style.css') ?>">
</head>
<body>

<header class="cbt-navbar">
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
            <li><a href="<?= base_url('guru/bank_soal.php') ?>" class="active">Bank Soal</a></li>
            <li><a href="<?= base_url('guru/sesi_ujian.php') ?>">Sesi Ujian & Token</a></li>
            <li><a href="<?= base_url('guru/rekap_nilai.php') ?>">Rekap Nilai</a></li>
            <li><a href="<?= base_url('logout.php') ?>" class="btn-danger">Keluar</a></li>
        </ul>
    </nav>
</header>

<main class="container">
    <?php if ($flash): ?>
        <div class="alert alert-<?= sanitize($flash['type']) ?>">
            <?= sanitize($flash['message']) ?>
        </div>
    <?php endif; ?>

    <div class="card-header">
        <div>
            <h1 class="card-title">Bank Soal Ujian</h1>
            <p class="text-sm text-muted">Koleksi butir soal pilihan ganda dikelompokkan berdasarkan Judul/Paket Soal di tiap Mata Pelajaran.</p>
        </div>
        <div class="card-header-actions">
            <a href="<?= base_url('guru/tambah_soal.php' . ($filterMapel ? '?id_mapel=' . $filterMapel : '')) ?>" class="btn btn-primary">+ Buat Judul / Soal Baru</a>
            <a href="<?= base_url('guru/import_soal.php' . ($filterMapel ? '?id_mapel=' . $filterMapel : '')) ?>" class="btn btn-secondary">Import Massal (CSV)</a>
        </div>
    </div>

    <!-- Tab Navigasi Kategori Mapel -->
    <div class="mapel-nav-pills">
        <a href="<?= base_url('guru/bank_soal.php' . ($search !== '' ? '?search=' . urlencode($search) : '')) ?>" class="mapel-pill <?= empty($filterMapel) ? 'active' : '' ?>">
            <span>Semua Mapel</span>
            <span class="pill-badge"><?= $totalSemuaSoal ?> Soal</span>
        </a>
        <?php foreach ($mapelList as $m): ?>
            <a href="<?= base_url('guru/bank_soal.php?id_mapel=' . $m['id_mapel'] . ($search !== '' ? '&search=' . urlencode($search) : '')) ?>" class="mapel-pill <?= ($filterMapel == $m['id_mapel']) ? 'active' : '' ?>">
                <span><?= sanitize($m['nama_mapel']) ?> (<?= sanitize($m['kode_mapel']) ?>)</span>
                <span class="pill-badge"><?= (int)$m['total_soal'] ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- Pencarian Cepat -->
    <div class="card" style="padding: 0.85rem 1.15rem; margin-bottom: 1.25rem;">
        <form method="GET" action="<?= base_url('guru/bank_soal.php') ?>" class="filter-form-responsive">
            <?php if ($filterMapel): ?>
                <input type="hidden" name="id_mapel" value="<?= $filterMapel ?>">
            <?php endif; ?>
            <input type="text" name="search" class="form-control" placeholder="Cari judul soal atau kata kunci pertanyaan..." value="<?= sanitize($search) ?>">
            <div class="filter-row">
                <button type="submit" class="btn btn-primary">Cari</button>
                <?php if ($search !== '' || $filterMapel): ?>
                    <a href="<?= base_url('guru/bank_soal.php') ?>" class="btn btn-outline">Reset Filter</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Tampilan Hierarki: Mapel -> Judul Soal -> Butir Soal -->
    <?php if (empty($grouped)): ?>
        <div class="card text-center" style="padding: 3.5rem 1.5rem;">
            <div style="font-size: 2.5rem; margin-bottom: 0.5rem;">📚</div>
            <h3 style="font-size: 1.2rem; font-weight: 700; color: var(--gray-800); margin-bottom: 0.5rem;">
                Belum Ada Butir Soal <?= $filterMapel ? 'pada Mapel Ini' : '' ?>
            </h3>
            <p class="text-sm text-muted mb-3" style="max-width: 480px; margin: 0 auto 1.5rem;">
                <?= $search !== '' ? 'Tidak ditemukan soal yang cocok dengan kata kunci pencarian.' : 'Silakan klik tombol di bawah untuk membuat judul paket soal dan butir pertanyaan baru.' ?>
            </p>
            <div class="flex gap-2" style="justify-content: center;">
                <a href="<?= base_url('guru/tambah_soal.php' . ($filterMapel ? '?id_mapel=' . $filterMapel : '')) ?>" class="btn btn-primary">+ Buat Judul / Soal Baru</a>
                <a href="<?= base_url('guru/import_soal.php' . ($filterMapel ? '?id_mapel=' . $filterMapel : '')) ?>" class="btn btn-secondary">Import CSV</a>
            </div>
        </div>
    <?php else: ?>
        <?php foreach ($grouped as $mId => $mapel): ?>
            <!-- SEKSI MATA PELAJARAN -->
            <div class="mb-5">
                <div class="flex-between mb-3" style="border-bottom: 2px solid var(--primary); padding-bottom: 0.6rem;">
                    <div style="display: flex; align-items: center; gap: 0.65rem;">
                        <div class="stat-icon" style="width: 36px; height: 36px; min-width: 36px; background: var(--primary);">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg>
                        </div>
                        <div>
                            <h2 style="font-size: 1.25rem; font-weight: 800; color: var(--gray-900); margin: 0;">
                                <?= sanitize($mapel['nama_mapel']) ?> <span class="text-muted" style="font-weight: 500; font-size: 0.95rem;">(<?= sanitize($mapel['kode_mapel']) ?>)</span>
                            </h2>
                            <span class="text-xs text-muted">Total Koleksi: <?= $mapel['total_soal'] ?> Butir Soal (<?= count($mapel['paket']) ?> Judul Paket)</span>
                        </div>
                    </div>
                    <div>
                        <a href="<?= base_url('guru/tambah_soal.php?id_mapel=' . $mapel['id_mapel']) ?>" class="btn btn-sm btn-primary">+ Buat Paket Baru</a>
                    </div>
                </div>

                <!-- DAFTAR JUDUL / PAKET SOAL DI BAWAH MAPEL INI -->
                <div style="display: flex; flex-direction: column; gap: 1.5rem;">
                    <?php foreach ($mapel['paket'] as $judul => $soals): ?>
                        <div class="card" style="padding: 0; overflow: hidden; border: 1px solid var(--gray-300); box-shadow: var(--shadow-sm);">
                            <!-- Header Judul / Paket Soal -->
                            <div class="card-header" style="background: #f1f5f9; padding: 0.85rem 1.25rem; border-bottom: 1px solid var(--gray-200); margin: 0;">
                                <div style="display: flex; align-items: center; gap: 0.6rem;">
                                    <span style="font-size: 1.25rem;">📁</span>
                                    <div>
                                        <div class="flex items-center gap-2">
                                            <h3 class="card-title" style="font-size: 1.1rem; margin: 0; color: var(--gray-900);">
                                                <?= sanitize($judul) ?>
                                            </h3>
                                            <span class="badge badge-online"><?= count($soals) ?> Soal</span>
                                        </div>
                                        <span class="text-xs text-muted">Paket Soal Mapel <?= sanitize($mapel['nama_mapel']) ?></span>
                                    </div>
                                </div>
                                <div class="flex gap-2">
                                    <a href="<?= base_url('guru/tambah_soal.php?id_mapel=' . $mapel['id_mapel'] . '&judul_soal=' . urlencode($judul)) ?>" class="btn btn-sm btn-primary">+ Tambah Butir Soal</a>
                                    <a href="<?= base_url('guru/import_soal.php?id_mapel=' . $mapel['id_mapel'] . '&judul_soal=' . urlencode($judul)) ?>" class="btn btn-sm btn-outline">Import CSV</a>
                                </div>
                            </div>

                            <!-- Butir-Butir Soal di Bawah Judul Ini -->
                            <div style="padding: 1.25rem; display: flex; flex-direction: column; gap: 1rem; background: var(--gray-50);">
                                <?php foreach ($soals as $subIdx => $s): ?>
                                    <div style="border: 1px solid var(--gray-200); border-radius: 8px; padding: 1.15rem; background: var(--white); box-shadow: 0 1px 2px rgba(0,0,0,0.03);">
                                        <div class="flex-between mb-2">
                                            <div>
                                                <span class="badge badge-role">Nomor <?= $subIdx + 1 ?></span>
                                                <span class="badge badge-aktif" style="margin-left: 0.4rem;"><?= sanitize($judul) ?></span>
                                            </div>
                                            <div class="flex gap-2">
                                                <a href="<?= base_url('guru/tambah_soal.php?edit=' . $s['id_soal']) ?>" class="btn btn-sm btn-outline">Edit</a>
                                                <form action="<?= base_url('guru/bank_soal.php') ?>" method="POST" style="display:inline;" data-confirm="Yakin ingin menghapus butir soal nomor <?= $subIdx + 1 ?> pada paket <?= sanitize($judul) ?> ini?" data-confirm-title="Hapus Butir Soal" data-confirm-type="danger" data-confirm-btn="Ya, Hapus">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="hapus">
                                                    <input type="hidden" name="id_soal" value="<?= $s['id_soal'] ?>">
                                                    <input type="hidden" name="redirect_mapel" value="<?= $filterMapel ?>">
                                                    <button type="submit" class="btn btn-sm btn-danger">Hapus</button>
                                                </form>
                                            </div>
                                        </div>

                                        <div class="mb-3" style="font-size: 1rem; line-height: 1.6; color: var(--gray-900);">
                                            <?= nl2br(sanitize($s['pertanyaan'])) ?>
                                        </div>

                                        <?php if (!empty($s['gambar'])): ?>
                                            <div class="mb-3">
                                                <img src="<?= base_url(ltrim($s['gambar'], '/')) ?>" alt="Lampiran Soal" style="max-height: 180px; border-radius: 6px; border: 1px solid var(--gray-300);">
                                            </div>
                                        <?php endif; ?>

                                        <!-- Preview Pilihan Opsi A, B, C, D, E -->
                                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 0.5rem; font-size: 0.88rem; background: var(--gray-50); padding: 0.75rem 1rem; border-radius: 6px; border: 1px solid var(--gray-200);">
                                            <div style="<?= ($s['kunci_jawaban'] === 'A') ? 'font-weight:bold; color:#166534;' : '' ?>">
                                                <strong>A.</strong> <?= sanitize($s['opsi_a']) ?> <?= ($s['kunci_jawaban'] === 'A') ? '✓ (Kunci)' : '' ?>
                                            </div>
                                            <div style="<?= ($s['kunci_jawaban'] === 'B') ? 'font-weight:bold; color:#166534;' : '' ?>">
                                                <strong>B.</strong> <?= sanitize($s['opsi_b']) ?> <?= ($s['kunci_jawaban'] === 'B') ? '✓ (Kunci)' : '' ?>
                                            </div>
                                            <div style="<?= ($s['kunci_jawaban'] === 'C') ? 'font-weight:bold; color:#166534;' : '' ?>">
                                                <strong>C.</strong> <?= sanitize($s['opsi_c']) ?> <?= ($s['kunci_jawaban'] === 'C') ? '✓ (Kunci)' : '' ?>
                                            </div>
                                            <div style="<?= ($s['kunci_jawaban'] === 'D') ? 'font-weight:bold; color:#166534;' : '' ?>">
                                                <strong>D.</strong> <?= sanitize($s['opsi_d']) ?> <?= ($s['kunci_jawaban'] === 'D') ? '✓ (Kunci)' : '' ?>
                                            </div>
                                            <?php if (!empty($s['opsi_e'])): ?>
                                                <div style="<?= ($s['kunci_jawaban'] === 'E') ? 'font-weight:bold; color:#166534;' : '' ?>">
                                                    <strong>E.</strong> <?= sanitize($s['opsi_e']) ?> <?= ($s['kunci_jawaban'] === 'E') ? '✓ (Kunci)' : '' ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</main>

<script src="<?= base_url('assets/js/app.js') ?>"></script>
</body>
</html>
