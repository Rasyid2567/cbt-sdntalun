<?php
/**
 * Modul Import Butir Soal Massal via CSV
 */

require_once __DIR__ . '/../middleware/auth.php';

$currentUser = auth_check(['guru']);
$db = get_db();
$idGuru = $currentUser['id_user'];

// Tangani Pengunduhan Template CSV
if (isset($_GET['action']) && $_GET['action'] === 'download_template') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=template_import_soal_cbt.csv');
    $output = fopen('php://output', 'w');
    // UTF-8 BOM untuk kompatibilitas Microsoft Excel
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    // Beritahu Excel untuk memecah kolom dengan koma secara otomatis
    fwrite($output, "sep=,\n");
    fputcsv($output, ['pertanyaan', 'opsi_a', 'opsi_b', 'opsi_c', 'opsi_d', 'opsi_e', 'kunci_jawaban']);
    fputcsv($output, [
        'Ibu kota negara Indonesia yang baru sesuai undang-undang adalah...',
        'Jakarta',
        'Nusantara',
        'Bandung',
        'Surabaya',
        'Medan',
        'B'
    ]);
    fputcsv($output, [
        'Manakah di bawah ini yang merupakan sistem operasi open source?',
        'Windows 11',
        'macOS Sequoia',
        'Linux Ubuntu',
        'iOS 17',
        'ChromeOS Pro',
        'C'
    ]);
    fclose($output);
    exit;
}

// Tangani Proses Upload dan Parse CSV
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash_set('danger', 'Validasi token keamanan CSRF gagal.');
        redirect(base_url('guru/import_soal.php'));
    }

    $idMapel   = (int)($_POST['id_mapel'] ?? 0);
    $judulSoal = trim($_POST['judul_soal'] ?? '');
    if ($judulSoal === '') {
        $judulSoal = 'Asesmen Nasional';
    }
    if ($idMapel <= 0) {
        flash_set('danger', 'Silakan pilih Mata Pelajaran tujuan terlebih dahulu.');
        redirect(base_url('guru/import_soal.php'));
    }

    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        flash_set('danger', 'Gagal mengunggah file CSV. Pastikan berkas terpilih.');
        redirect(base_url('guru/import_soal.php'));
    }

    $tmpPath = $_FILES['csv_file']['tmp_name'];
    $handle  = fopen($tmpPath, 'r');
    if ($handle === false) {
        flash_set('danger', 'Gagal membuka berkas CSV yang diunggah.');
        redirect(base_url('guru/import_soal.php'));
    }

    $imported = 0;
    $skipped  = 0;
    $rowIndex = 0;

    $stmtIns = $db->prepare("
        INSERT INTO bank_soal (id_guru, id_mapel, judul_soal, pertanyaan, opsi_a, opsi_b, opsi_c, opsi_d, opsi_e, kunci_jawaban)
        VALUES (:g, :m, :j, :p, :oa, :ob, :oc, :od, :oe, :k)
    ");

    // Deteksi otomatis pemisah kolom (koma atau titik koma)
    $sampleLine = fgets($handle);
    $delimiter = ',';
    if ($sampleLine !== false) {
        $semiCount = substr_count($sampleLine, ';');
        $commaCount = substr_count($sampleLine, ',');
        if ($semiCount > $commaCount) {
            $delimiter = ';';
        }
    }
    rewind($handle);

    while (($row = fgetcsv($handle, 4096, $delimiter)) !== false) {
        // Abaikan baris kosong atau baris penunjuk sep=...
        if (empty($row) || (isset($row[0]) && str_starts_with(trim($row[0]), 'sep='))) {
            continue;
        }

        $rowIndex++;
        if ($rowIndex === 1) continue; // Lewati header kolom

        $pertanyaan = trim($row[0] ?? '');
        $oa         = trim($row[1] ?? '');
        $ob         = trim($row[2] ?? '');
        $oc         = trim($row[3] ?? '');
        $od         = trim($row[4] ?? '');
        $oe         = trim($row[5] ?? '');
        $kunci      = strtoupper(trim($row[6] ?? ''));

        // Validasi kelengkapan minimal (pertanyaan, opsi A-D, kunci A-E)
        if ($pertanyaan === '' || $oa === '' || $ob === '' || $oc === '' || $od === '' || !in_array($kunci, ['A', 'B', 'C', 'D', 'E'], true)) {
            $skipped++;
            continue;
        }

        try {
            $stmtIns->execute([
                ':g'  => $idGuru,
                ':m'  => $idMapel,
                ':j'  => $judulSoal,
                ':p'  => $pertanyaan,
                ':oa' => $oa,
                ':ob' => $ob,
                ':oc' => $oc,
                ':od' => $od,
                ':oe' => ($oe !== '' ? $oe : null),
                ':k'  => $kunci
            ]);
            $imported++;
        } catch (Exception $e) {
            $skipped++;
        }
    }

    fclose($handle);

    if ($imported > 0) {
        flash_set('success', "Berhasil mengimpor {$imported} butir soal ke paket '{$judulSoal}'. (Dilewati: {$skipped})");
    } else {
        flash_set('warning', "Tidak ada soal yang berhasil diimpor. Pastikan format CSV sesuai template.");
    }
    redirect(base_url('guru/bank_soal.php?id_mapel=' . $idMapel));
}

// Data Mapel & Judul yang pernah ada
$mapelList = $db->query("SELECT id_mapel, nama_mapel FROM mapel ORDER BY nama_mapel ASC")->fetchAll();
$stmtJudul = $db->prepare("SELECT DISTINCT judul_soal FROM bank_soal WHERE id_guru = :g ORDER BY judul_soal ASC");
$stmtJudul->execute([':g' => $idGuru]);
$existingJudul = $stmtJudul->fetchAll(PDO::FETCH_COLUMN);
$flash = flash_get();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import Soal Massal - CBT Guru</title>
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

<main class="container" style="max-width: 800px;">
    <?php if ($flash): ?>
        <div class="alert alert-<?= sanitize($flash['type']) ?>">
            <?= sanitize($flash['message']) ?>
        </div>
    <?php endif; ?>

    <div class="card-header">
        <div>
            <h1 class="card-title">Import Soal Massal via CSV</h1>
            <p class="text-sm text-muted">Unggah kumpulan butir soal pilihan ganda sekaligus menggunakan berkas template CSV.</p>
        </div>
        <div class="flex gap-2">
            <a href="<?= base_url('guru/import_soal.php?action=download_template') ?>" class="btn btn-secondary">Unduh Template CSV</a>
            <a href="<?= base_url('guru/bank_soal.php') ?>" class="btn btn-outline">Kembali</a>
        </div>
    </div>

    <div class="card">
        <form action="<?= base_url('guru/import_soal.php') ?>" method="POST" enctype="multipart/form-data">
            <?= csrf_field() ?>

            <!-- 1. Mapel -->
            <div class="form-group">
                <label for="id_mapel">Mata Pelajaran (Mapel) <span class="text-danger">*</span></label>
                <select name="id_mapel" id="id_mapel" class="form-control" required>
                    <option value="">-- Pilih Mata Pelajaran --</option>
                    <?php foreach ($mapelList as $m): ?>
                        <option value="<?= $m['id_mapel'] ?>" <?= (($_GET['id_mapel'] ?? '') == $m['id_mapel']) ? 'selected' : '' ?>><?= sanitize($m['nama_mapel']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- 2. Soal (Judul / Nama Ujian) -->
            <div class="form-group mt-3">
                <label for="judul_soal">Soal (Judul / Nama Ujian) <span class="text-danger">*</span></label>
                <input type="text" name="judul_soal" id="judul_soal" class="form-control" list="list_judul" placeholder="Contoh: Asesmen Nasional, Penilaian Harian Bab 1, Ujian Akhir..." value="<?= sanitize($_GET['judul_soal'] ?? '') ?>" required>
                <datalist id="list_judul">
                    <?php foreach ($existingJudul as $ej): ?>
                        <option value="<?= sanitize($ej) ?>"></option>
                    <?php endforeach; ?>
                    <option value="Asesmen Nasional"></option>
                    <option value="Penilaian Harian 1"></option>
                    <option value="Penilaian Tengah Semester"></option>
                    <option value="Penilaian Akhir Semester"></option>
                </datalist>
                <p class="text-xs text-muted mt-1">Nama kelompok soal (misal: <em>Asesmen Nasional</em>). Seluruh pertanyaan dalam CSV ini akan dimasukkan ke paket ini.</p>
            </div>

            <!-- 3. Berkas CSV (Pertanyaan) -->

            <div class="form-group mt-3">
                <label for="csv_file">Berkas CSV Soal <span class="text-danger">*</span></label>
                <input type="file" name="csv_file" id="csv_file" class="form-control" accept=".csv" required>
                <p class="text-xs text-muted mt-1">Pastikan susunan kolom: <em>pertanyaan, opsi_a, opsi_b, opsi_c, opsi_d, opsi_e, kunci_jawaban</em>.</p>
            </div>

            <div class="flex gap-2 mt-4" style="justify-content: flex-end;">
                <a href="<?= base_url('guru/bank_soal.php') ?>" class="btn btn-outline">Batal</a>
                <button type="submit" class="btn btn-primary btn-lg">Mulai Import Soal</button>
            </div>
        </form>
    </div>
</main>

<script src="<?= base_url('assets/js/app.js') ?>"></script>
</body>
</html>
