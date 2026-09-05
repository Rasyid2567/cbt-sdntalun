<?php
/**
 * Modul Bank Soal Guru (Daftar & Kelola Paket Soal Terkelompok)
 */

require_once __DIR__ . '/../../middleware/auth.php';

$currentUser = auth_check(['guru']);
$db = get_db();
$idGuru = $currentUser['id_user'];
$page = 'bank_soal';
$pageTitle = 'Bank Soal Ujian';

// Tangani Export CSV per Paket
if (isset($_GET['action']) && $_GET['action'] === 'export_csv') {
    $idPaket = (int)($_GET['id_paket'] ?? 0);
    $expMapel = (int)($_GET['id_mapel'] ?? 0);
    $expJudul = trim($_GET['judul_soal'] ?? '');

    $paket = null;
    if ($idPaket > 0) {
        $stmtP = $db->prepare("
            SELECT p.*, m.nama_mapel 
            FROM paket_soal p
            JOIN mapel m ON p.id_mapel = m.id_mapel
            WHERE p.id_paket = :p AND p.id_guru = :g
        ");
        $stmtP->execute([':p' => $idPaket, ':g' => $idGuru]);
        $paket = $stmtP->fetch();
    } elseif ($expMapel > 0 && $expJudul !== '') {
        $stmtP = $db->prepare("
            SELECT p.*, m.nama_mapel 
            FROM paket_soal p
            JOIN mapel m ON p.id_mapel = m.id_mapel
            WHERE p.id_mapel = :m AND p.nama_paket = :j AND p.id_guru = :g
        ");
        $stmtP->execute([':m' => $expMapel, ':j' => $expJudul, ':g' => $idGuru]);
        $paket = $stmtP->fetch();
    }

    if (!$paket) {
        flash_set('danger', 'Paket soal tidak ditemukan.');
        redirect(base_url('guru/dashboard.php?page=bank_soal'));
    }

    $stmtExp = $db->prepare("
        SELECT * FROM bank_soal 
        WHERE id_paket = :p
        ORDER BY id_soal ASC
    ");
    $stmtExp->execute([':p' => $paket['id_paket']]);
    $rows = $stmtExp->fetchAll();

    $filename = 'paket_soal_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $paket['nama_paket']) . '.csv';
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

// Tangani Form POST (Hapus Soal Tunggal, Rename Paket, Hapus Paket)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash_set('danger', 'Validasi token keamanan gagal.');
        redirect(base_url('guru/dashboard.php?page=bank_soal'));
    }

    $action = $_POST['action'] ?? '';

    // Hapus Butir Soal Tunggal
    if ($action === 'hapus') {
        $idSoal = (int)($_POST['id_soal'] ?? 0);
        if ($idSoal > 0) {
            // Ambil info gambar untuk dihapus dari server
            $stmtImg = $db->prepare("
                SELECT b.gambar 
                FROM bank_soal b
                JOIN paket_soal p ON b.id_paket = p.id_paket
                WHERE b.id_soal = :id AND p.id_guru = :g
            ");
            $stmtImg->execute([':id' => $idSoal, ':g' => $idGuru]);
            $soalImg = $stmtImg->fetchColumn();

            if ($soalImg && file_exists(__DIR__ . '/../../' . ltrim($soalImg, '/'))) {
                unlink(__DIR__ . '/../../' . ltrim($soalImg, '/'));
            }

            $del = $db->prepare("
                DELETE FROM bank_soal 
                WHERE id_soal = :id AND id_paket IN (SELECT id_paket FROM paket_soal WHERE id_guru = :g)
            ");
            $del->execute([':id' => $idSoal, ':g' => $idGuru]);
            flash_set('danger', 'Butir soal berhasil dihapus.');
        }
        redirect(base_url('guru/dashboard.php?page=bank_soal' . (!empty($_POST['redirect_mapel']) ? '&id_mapel=' . (int)$_POST['redirect_mapel'] : '')));
    }

    // Rename Nama Paket
    if ($action === 'rename_paket') {
        $idPaket  = (int)($_POST['id_paket'] ?? 0);
        $newJudul = trim($_POST['new_judul'] ?? '');

        if ($idPaket > 0 && $newJudul !== '') {
            $upd = $db->prepare("
                UPDATE paket_soal 
                SET nama_paket = :new 
                WHERE id_paket = :id AND id_guru = :g
            ");
            $upd->execute([':new' => $newJudul, ':id' => $idPaket, ':g' => $idGuru]);
            flash_set('success', "Nama paket soal berhasil diubah menjadi '{$newJudul}'.");
        }
        redirect(base_url('guru/dashboard.php?page=bank_soal' . (!empty($_POST['id_mapel']) ? '&id_mapel=' . (int)$_POST['id_mapel'] : '')));
    }

    // Hapus Seluruh Paket Soal (Cascade Butir Soal & Gambar)
    if ($action === 'hapus_paket') {
        $idPaket = (int)($_POST['id_paket'] ?? 0);
        $idMapel = (int)($_POST['id_mapel'] ?? 0);

        if ($idPaket > 0) {
            $stmtP = $db->prepare("SELECT nama_paket, id_mapel FROM paket_soal WHERE id_paket = :id AND id_guru = :g");
            $stmtP->execute([':id' => $idPaket, ':g' => $idGuru]);
            $pInfo = $stmtP->fetch();

            if ($pInfo) {
                $idMapel = (int)$pInfo['id_mapel'];
                $namaPaket = $pInfo['nama_paket'];

                // Hapus berkas gambar butir soal
                $stmtImgs = $db->prepare("SELECT gambar FROM bank_soal WHERE id_paket = :p AND gambar IS NOT NULL");
                $stmtImgs->execute([':p' => $idPaket]);
                $imgs = $stmtImgs->fetchAll(PDO::FETCH_COLUMN);
                foreach ($imgs as $img) {
                    if ($img && file_exists(__DIR__ . '/../../' . ltrim($img, '/'))) {
                        @unlink(__DIR__ . '/../../' . ltrim($img, '/'));
                    }
                }

                $del = $db->prepare("DELETE FROM paket_soal WHERE id_paket = :id AND id_guru = :g");
                $del->execute([':id' => $idPaket, ':g' => $idGuru]);
                flash_set('danger', "Seluruh butir soal dalam paket '{$namaPaket}' berhasil dihapus.");
            }
        }
        redirect(base_url('guru/dashboard.php?page=bank_soal' . ($idMapel > 0 ? '&id_mapel=' . $idMapel : '')));
    }
}

// Helper format nama mapel
if (!function_exists('format_mapel_name')) {
    function format_mapel_name($nama, $kode) {
        if (empty($kode)) return $nama;
        if (stripos($nama, "({$kode})") !== false || strcasecmp($nama, $kode) === 0) {
            return $nama;
        }
        return $nama . " ({$kode})";
    }
}

// Filter Mapel & Pencarian
$filterMapel = !empty($_GET['id_mapel']) ? (int)$_GET['id_mapel'] : null;
$search      = trim($_GET['search'] ?? '');

// Ambil Statistik Paket per Mapel untuk Guru ini
$stmtMapel = $db->prepare("
    SELECT m.id_mapel, m.nama_mapel, m.kode_mapel,
           COUNT(b.id_soal) AS total_soal,
           COUNT(DISTINCT p.id_paket) AS total_paket
    FROM mapel m
    LEFT JOIN paket_soal p ON m.id_mapel = p.id_mapel AND p.id_guru = :g
    LEFT JOIN bank_soal b ON p.id_paket = b.id_paket
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
    SELECT p.id_paket, p.nama_paket, p.id_mapel, p.created_at,
           m.nama_mapel, m.kode_mapel,
           COUNT(b.id_soal) AS total_butir,
           COUNT(CASE WHEN b.jenis_soal = 'essai' THEN 1 END) AS total_essai,
           COUNT(CASE WHEN b.jenis_soal != 'essai' OR b.jenis_soal IS NULL THEN 1 END) AS total_pg
    FROM paket_soal p
    JOIN mapel m ON p.id_mapel = m.id_mapel
    LEFT JOIN bank_soal b ON p.id_paket = b.id_paket
    WHERE p.id_guru = :g
";
$params = [':g' => $idGuru];

if ($filterMapel) {
    $sql .= " AND p.id_mapel = :m";
    $params[':m'] = $filterMapel;
}

if ($search !== '') {
    $sql .= " AND (p.nama_paket ILIKE :s OR b.pertanyaan ILIKE :s)";
    $params[':s'] = "%{$search}%";
}

$sql .= " GROUP BY p.id_paket, p.nama_paket, p.id_mapel, p.created_at, m.nama_mapel, m.kode_mapel ORDER BY m.nama_mapel ASC, p.nama_paket ASC";
$stmtPaket = $db->prepare($sql);
$stmtPaket->execute($params);
$paketList = $stmtPaket->fetchAll();

$flash = flash_get();

include __DIR__ . '/../layouts/header.php';
?>

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
            <a href="<?= base_url('guru/dashboard.php?page=tambah_soal' . ($filterMapel ? '&id_mapel=' . $filterMapel : '')) ?>" class="btn btn-primary">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                <span>Buat Paket Soal</span>
            </a>
            <a href="<?= base_url('guru/dashboard.php?page=import_soal' . ($filterMapel ? '&id_mapel=' . $filterMapel : '')) ?>" class="btn btn-secondary">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
                <span>Import CSV</span>
            </a>
        </div>
    </div>

    <!-- Filter & Pencarian Cepat (Single Row) -->
    <div class="card mb-4" style="padding: 1rem 1.25rem;">
        <form method="GET" action="<?= base_url('guru/dashboard.php') ?>" style="display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap;">
            <input type="hidden" name="page" value="bank_soal">
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
                <button type="submit" class="btn btn-primary">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                    <span>Cari</span>
                </button>
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
                <a href="<?= base_url('guru/dashboard.php?page=tambah_soal' . ($filterMapel ? '&id_mapel=' . $filterMapel : '')) ?>" class="btn btn-primary">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                    <span>Buat Paket Soal</span>
                </a>
                <a href="<?= base_url('guru/dashboard.php?page=import_soal' . ($filterMapel ? '&id_mapel=' . $filterMapel : '')) ?>" class="btn btn-secondary">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
                    <span>Import CSV</span>
                </a>
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
                                        <?= sanitize($p['nama_paket']) ?>
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
                            <a href="<?= base_url('guru/dashboard.php?page=tambah_soal&id_paket=' . $p['id_paket']) ?>" class="btn btn-primary btn-sm" title="Edit Paket Soal">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                <span>Edit</span>
                            </a>
                            <a href="<?= base_url('guru/dashboard.php?page=bank_soal&action=export_csv&id_paket=' . $p['id_paket']) ?>" class="btn btn-outline btn-sm" title="Export CSV">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                                <span>Export</span>
                            </a>
                            <form action="<?= base_url('guru/dashboard.php?page=bank_soal') ?>" method="POST" style="display:inline;" data-confirm="Apakah Anda yakin ingin menghapus SELURUH butir pertanyaan dalam paket '<?= sanitize($p['nama_paket']) ?>'?" data-confirm-title="Hapus Paket Soal" data-confirm-type="danger" data-confirm-btn="Ya, Hapus Paket">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="hapus_paket">
                                <input type="hidden" name="id_paket" value="<?= $p['id_paket'] ?>">
                                <input type="hidden" name="id_mapel" value="<?= $p['id_mapel'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm" title="Hapus Paket Soal">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                                    <span>Hapus</span>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>

<?php
include __DIR__ . '/../layouts/footer.php';
?>
