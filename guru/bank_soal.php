<?php
/**
 * Modul Bank Soal Guru (Daftar & Hapus Soal Terkelompok: Mapel -> Judul Soal)
 */

require_once __DIR__ . '/../middleware/auth.php';

$currentUser = auth_check(['guru']);
$db = get_db();
$idGuru = $currentUser['id_user'];

// Tangani Export CSV per Paket
if (isset($_GET['action']) && $_GET['action'] === 'export_csv') {
    $expMapel = (int)($_GET['id_mapel'] ?? 0);
    $expJudul = trim($_GET['judul_soal'] ?? '');

    $stmtExp = $db->prepare("
        SELECT b.*, m.nama_mapel 
        FROM bank_soal b
        JOIN mapel m ON b.id_mapel = m.id_mapel
        WHERE b.id_guru = :g AND b.id_mapel = :m AND b.judul_soal = :j
        ORDER BY b.id_soal ASC
    ");
    $stmtExp->execute([':g' => $idGuru, ':m' => $expMapel, ':j' => $expJudul]);
    $rows = $stmtExp->fetchAll();

    $filename = 'paket_soal_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $expJudul) . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename);

    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM
    fwrite($out, "sep=,\n");
    fputcsv($out, ['jenis_soal', 'pertanyaan', 'opsi_a', 'opsi_b', 'opsi_c', 'opsi_d', 'opsi_e', 'kunci_jawaban']);
    foreach ($rows as $r) {
        $jenisExport = ($r['jenis_soal'] === 'essai') ? 'essai' : 'pg';
        fputcsv($out, [
            $jenisExport,
            $r['pertanyaan'],
            $r['opsi_a'] ?? '',
            $r['opsi_b'] ?? '',
            $r['opsi_c'] ?? '',
            $r['opsi_d'] ?? '',
            $r['opsi_e'] ?? '',
            $r['kunci_jawaban'] ?? ''
        ]);
    }
    fclose($out);
    exit;
}

// Tangani Form POST (Hapus Soal, Rename Paket, Hapus Paket)
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
            flash_set('danger', 'Butir soal berhasil dihapus.');
        }
        redirect(base_url('guru/bank_soal.php' . (!empty($_POST['redirect_mapel']) ? '?id_mapel=' . (int)$_POST['redirect_mapel'] : '')));
    }

    if ($action === 'rename_paket') {
        $idMapel  = (int)($_POST['id_mapel'] ?? 0);
        $oldJudul = trim($_POST['old_judul'] ?? '');
        $newJudul = trim($_POST['new_judul'] ?? '');

        if ($idMapel > 0 && $oldJudul !== '' && $newJudul !== '') {
            $upd = $db->prepare("
                UPDATE bank_soal 
                SET judul_soal = :new 
                WHERE id_guru = :g AND id_mapel = :m AND judul_soal = :old
            ");
            $upd->execute([':new' => $newJudul, ':g' => $idGuru, ':m' => $idMapel, ':old' => $oldJudul]);
            flash_set('success', "Nama paket soal berhasil diubah menjadi '{$newJudul}'.");
        }
        redirect(base_url('guru/bank_soal.php?id_mapel=' . $idMapel));
    }

    if ($action === 'hapus_paket') {
        $idMapel = (int)($_POST['id_mapel'] ?? 0);
        $judul   = trim($_POST['judul_soal'] ?? '');

        if ($idMapel > 0 && $judul !== '') {
            $del = $db->prepare("DELETE FROM bank_soal WHERE id_guru = :g AND id_mapel = :m AND judul_soal = :j");
            $del->execute([':g' => $idGuru, ':m' => $idMapel, ':j' => $judul]);
            flash_set('danger', "Seluruh butir soal dalam paket '{$judul}' berhasil dihapus.");
        }
        redirect(base_url('guru/bank_soal.php?id_mapel=' . $idMapel));
    }
}

// Helper format nama mapel agar tidak double code seperti (IPAS) (IPAS)
function format_mapel_name($nama, $kode) {
    if (empty($kode)) return $nama;
    if (stripos($nama, "({$kode})") !== false || strcasecmp($nama, $kode) === 0) {
        return $nama;
    }
    return $nama . " ({$kode})";
}

// Filter Mapel & Pencarian
$filterMapel = !empty($_GET['id_mapel']) ? (int)$_GET['id_mapel'] : null;
$search      = trim($_GET['search'] ?? '');

// Ambil Statistik Paket per Mapel untuk Guru ini
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

$totalSemuaPaket = 0;
foreach ($mapelList as $m) {
    $totalSemuaPaket += (int)$m['total_paket'];
}

// Query Ambil Seluruh Paket Soal Guru Ini
$sql = "
    SELECT b.id_mapel, b.judul_soal, m.nama_mapel, m.kode_mapel,
           COUNT(b.id_soal) AS total_butir,
           COUNT(CASE WHEN b.jenis_soal = 'essai' THEN 1 END) AS total_essai,
           COUNT(CASE WHEN b.jenis_soal != 'essai' OR b.jenis_soal IS NULL THEN 1 END) AS total_pg
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

$sql .= " GROUP BY b.id_mapel, b.judul_soal, m.nama_mapel, m.kode_mapel ORDER BY m.nama_mapel ASC, b.judul_soal ASC";
$stmtPaket = $db->prepare($sql);
$stmtPaket->execute($params);
$paketList = $stmtPaket->fetchAll();

$flash = flash_get();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Bank Soal - CBT Guru</title>
    <link rel="icon" type="image/svg+xml" href="<?= base_url('assets/img/favicon.svg') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/cbt-style.css') ?>">
</head>
<body>

<header class="cbt-navbar">
    <div class="cbt-navbar-header">
        <a href="<?= base_url('guru/dashboard.php') ?>" class="cbt-navbar-brand">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M22 10v6M2 10l10-5 10 5-10 5z"></path>
                <path d="M6 12v5c3 3 9 3 12 0v-5"></path>
            </svg>
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

    <div class="card-header mb-4">
        <div>
            <h1 class="card-title">Bank Soal Ujian</h1>
        </div>
        <div class="card-header-actions">
            <a href="<?= base_url('guru/tambah_soal.php' . ($filterMapel ? '?id_mapel=' . $filterMapel : '')) ?>" class="btn btn-primary">+ Buat Paket Soal Baru</a>
            <a href="<?= base_url('guru/import_soal.php' . ($filterMapel ? '?id_mapel=' . $filterMapel : '')) ?>" class="btn btn-secondary">Import CSV</a>
        </div>
    </div>

    <!-- Filter & Pencarian Cepat (Single Row) -->
    <div class="card mb-4" style="padding: 1rem 1.25rem;">
        <form method="GET" action="<?= base_url('guru/bank_soal.php') ?>" style="display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap;">
            <div style="flex: 1; min-width: 220px;">
                <input type="text" name="search" class="form-control" placeholder="Cari nama paket soal..." value="<?= sanitize($search) ?>">
            </div>
            <div style="min-width: 220px;">
                <select name="id_mapel" class="form-control" onchange="this.form.submit()">
                    <option value="">Semua Mata Pelajaran</option>
                    <?php foreach ($mapelList as $m): ?>
                        <option value="<?= $m['id_mapel'] ?>" <?= ($filterMapel == $m['id_mapel']) ? 'selected' : '' ?>>
                            <?= sanitize(format_mapel_name($m['nama_mapel'], $m['kode_mapel'])) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <button type="submit" class="btn btn-primary">Cari</button>
            </div>
        </form>
    </div>

    <!-- DAFTAR PAKET SOAL -->
    <?php if (empty($paketList)): ?>
        <div class="card text-center" style="padding: 3.5rem 1.5rem;">
            <div style="width: 56px; height: 56px; margin: 0 auto 1rem; border-radius: 50%; background: #f1f5f9; color: var(--gray-500); display: flex; align-items: center; justify-content: center;">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg>
            </div>
            <h3 style="font-size: 1.15rem; font-weight: 700; color: var(--gray-800); margin-bottom: 0.5rem;">
                Belum Ada Paket Soal <?= $filterMapel ? 'pada Mapel Ini' : '' ?>
            </h3>
            <div class="flex gap-2 mt-3" style="justify-content: center;">
                <a href="<?= base_url('guru/tambah_soal.php' . ($filterMapel ? '?id_mapel=' . $filterMapel : '')) ?>" class="btn btn-primary">+ Buat Paket Soal Baru</a>
                <a href="<?= base_url('guru/import_soal.php' . ($filterMapel ? '?id_mapel=' . $filterMapel : '')) ?>" class="btn btn-secondary">Import CSV</a>
            </div>
        </div>
    <?php else: ?>
        <div style="display: flex; flex-direction: column; gap: 0.85rem;">
            <?php foreach ($paketList as $p): ?>
                <div class="card" style="margin-bottom: 0; padding: 1.15rem 1.35rem; border: 1px solid var(--gray-200); border-radius: var(--radius-md); box-shadow: var(--shadow-sm);">
                    <div class="flex-between" style="flex-wrap: wrap; gap: 1rem;">
                        <div style="display: flex; align-items: center; gap: 0.85rem;">
                            <div style="width: 40px; height: 40px; border-radius: 6px; background: #f1f5f9; color: var(--primary); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path></svg>
                            </div>
                            <div>
                                <div class="flex gap-2" style="align-items: center; flex-wrap: wrap;">
                                    <h3 style="font-size: 1.08rem; font-weight: 700; color: var(--gray-900); margin: 0;">
                                        <?= sanitize($p['judul_soal']) ?>
                                    </h3>
                                    <span class="badge badge-online" style="font-size: 0.75rem; font-weight: 700;">
                                        <?= (int)$p['total_butir'] ?> Butir Soal
                                    </span>
                                </div>
                                <div class="flex gap-2 mt-1" style="align-items: center; flex-wrap: wrap; font-size: 0.85rem;">
                                    <span class="badge" style="background: #e2e8f0; color: #334155; font-weight: 600;">
                                        <?= sanitize(format_mapel_name($p['nama_mapel'], $p['kode_mapel'])) ?>
                                    </span>
                                    <span style="color: var(--gray-500); font-size: 0.82rem;">
                                        (<?= (int)$p['total_pg'] ?> Pilihan Ganda<?= (int)$p['total_essai'] > 0 ? ', ' . (int)$p['total_essai'] . ' Essai' : '' ?>)
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- Tombol Aksi Paket -->
                        <div class="flex gap-2" style="align-items: center;">
                            <a href="<?= base_url('guru/tambah_soal.php?id_mapel=' . $p['id_mapel'] . '&judul_soal=' . urlencode($p['judul_soal'])) ?>" class="btn btn-primary btn-sm">
                                Edit Paket
                            </a>
                            <a href="<?= base_url('guru/bank_soal.php?action=export_csv&id_mapel=' . $p['id_mapel'] . '&judul_soal=' . urlencode($p['judul_soal'])) ?>" class="btn btn-outline btn-sm">
                                Export CSV
                            </a>
                            <form action="<?= base_url('guru/bank_soal.php') ?>" method="POST" style="display:inline;" data-confirm="Apakah Anda yakin ingin menghapus SELURUH butir pertanyaan dalam paket '<?= sanitize($p['judul_soal']) ?>'?" data-confirm-title="Hapus Paket Soal" data-confirm-type="danger" data-confirm-btn="Ya, Hapus Paket">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="hapus_paket">
                                <input type="hidden" name="id_mapel" value="<?= $p['id_mapel'] ?>">
                                <input type="hidden" name="judul_soal" value="<?= sanitize($p['judul_soal']) ?>">
                                <button type="submit" class="btn btn-danger btn-sm" title="Hapus Paket Soal">
                                    Hapus
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>

<script src="<?= base_url('assets/js/app.js') ?>"></script>
</body>
</html>
