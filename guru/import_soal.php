<?php
/**
 * Modul Import Butir Soal Massal via CSV (Mendukung Pilihan Ganda & Essai)
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
    fputcsv($output, ['jenis_soal', 'pertanyaan', 'opsi_a', 'opsi_b', 'opsi_c', 'opsi_d', 'opsi_e', 'kunci_jawaban']);
    
    // Contoh 1: Pilihan Ganda (Bisa ditulis 'pg' atau 'pilihan_ganda')
    fputcsv($output, [
        'pg',
        'Ibu kota negara Indonesia yang baru sesuai undang-undang adalah...',
        'Jakarta',
        'Nusantara',
        'Bandung',
        'Surabaya',
        'Medan',
        'B'
    ]);
    
    // Contoh 2: Pilihan Ganda
    fputcsv($output, [
        'pg',
        'Manakah di bawah ini yang merupakan sistem operasi open source?',
        'Windows 11',
        'macOS Sequoia',
        'Linux Ubuntu',
        'iOS 17',
        '',
        'C'
    ]);

    // Contoh 3: Soal Essai / Uraian (Opsi A-E dikosongkan)
    fputcsv($output, [
        'essai',
        'Sebutkan 3 contoh sumber energi terbarukan dan jelaskan manfaatnya bagi lingkungan!',
        '',
        '',
        '',
        '',
        '',
        'Energi surya (matahari), energi angin, dan energi air. Manfaatnya ramah lingkungan dan tidak menghasilkan polusi udara.'
    ]);

    // Contoh 4: Soal Essai / Uraian
    fputcsv($output, [
        'essai',
        'Jelaskan perbedaan antara kalimat utama dan ide pokok dalam sebuah paragraf!',
        '',
        '',
        '',
        '',
        '',
        'Kalimat utama adalah kalimat yang memuat ide pokok, sedangkan ide pokok adalah inti atau gagasan utama paragraf.'
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
    $rawContent = file_get_contents($tmpPath);
    if ($rawContent === false || trim($rawContent) === '') {
        flash_set('danger', 'Berkas CSV kosong atau tidak dapat dibaca.');
        redirect(base_url('guru/import_soal.php'));
    }

    // Bersihkan UTF-8 BOM jika ada
    if (str_starts_with($rawContent, "\xEF\xBB\xBF")) {
        $rawContent = substr($rawContent, 3);
    }

    // Deteksi pemisah terbaik (koma, titik koma, atau tab) dari baris-baris data
    $lines = explode("\n", $rawContent);
    $checkLines = [];
    foreach ($lines as $l) {
        $trimmed = trim($l);
        if ($trimmed === '' || str_starts_with(strtolower($trimmed), 'sep=')) continue;
        $checkLines[] = $trimmed;
        if (count($checkLines) >= 5) break;
    }
    $sampleText = implode("\n", $checkLines);

    $commaCount = substr_count($sampleText, ',');
    $semiCount  = substr_count($sampleText, ';');
    $tabCount   = substr_count($sampleText, "\t");

    $delimiter = ',';
    if ($semiCount > $commaCount && $semiCount > $tabCount) {
        $delimiter = ';';
    } elseif ($tabCount > $commaCount && $tabCount > $semiCount) {
        $delimiter = "\t";
    }

    $handle = fopen($tmpPath, 'r');
    if ($handle === false) {
        flash_set('danger', 'Gagal membuka berkas CSV.');
        redirect(base_url('guru/import_soal.php'));
    }

    $importedPG    = 0;
    $importedEssai = 0;
    $skipped       = 0;
    $headerMap     = null;

    $stmtIns = $db->prepare("
        INSERT INTO bank_soal (id_guru, id_mapel, judul_soal, jenis_soal, pertanyaan, opsi_a, opsi_b, opsi_c, opsi_d, opsi_e, kunci_jawaban)
        VALUES (:g, :m, :j, :jenis, :p, :oa, :ob, :oc, :od, :oe, :k)
    ");

    while (($row = fgetcsv($handle, 8192, $delimiter)) !== false) {
        if (empty($row)) continue;

        // Bersihkan UTF-8 BOM dan spasi pada kolom pertama
        if (isset($row[0])) {
            $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', trim($row[0]));
        }

        // Abaikan baris kosong atau baris penunjuk sep=...
        if (empty($row[0]) && count(array_filter($row)) === 0) continue;
        if (str_starts_with(strtolower($row[0]), 'sep=')) continue;

        if ($headerMap === null) {
            $headerMap = [
                'jenis'      => null,
                'pertanyaan' => null,
                'opsi_a'     => null,
                'opsi_b'     => null,
                'opsi_c'     => null,
                'opsi_d'     => null,
                'opsi_e'     => null,
                'kunci'      => null
            ];

            foreach ($row as $colIdx => $colName) {
                $clean = strtolower(trim(preg_replace('/[^a-zA-Z0-9]/', '', $colName)));
                if (in_array($clean, ['jenissoal', 'jenis', 'type'], true)) {
                    $headerMap['jenis'] = $colIdx;
                } elseif (in_array($clean, ['pertanyaan', 'soal', 'soalpertanyaan', 'question'], true)) {
                    $headerMap['pertanyaan'] = $colIdx;
                } elseif (in_array($clean, ['opsia', 'a', 'pilihan1', 'pilihana'], true)) {
                    $headerMap['opsi_a'] = $colIdx;
                } elseif (in_array($clean, ['opsib', 'b', 'pilihan2', 'pilihanb'], true)) {
                    $headerMap['opsi_b'] = $colIdx;
                } elseif (in_array($clean, ['opsic', 'c', 'pilihan3', 'pilihanc'], true)) {
                    $headerMap['opsi_c'] = $colIdx;
                } elseif (in_array($clean, ['opsid', 'd', 'pilihan4', 'pilihand'], true)) {
                    $headerMap['opsi_d'] = $colIdx;
                } elseif (in_array($clean, ['opsie', 'e', 'pilihan5', 'pilihane'], true)) {
                    $headerMap['opsi_e'] = $colIdx;
                } elseif (in_array($clean, ['kuncijawaban', 'kunci', 'jawaban', 'answer', 'key'], true)) {
                    $headerMap['kunci'] = $colIdx;
                }
            }

            // Fallback posisi kolom jika nama header tidak terdeteksi
            if ($headerMap['pertanyaan'] === null) {
                $firstCell = strtolower(trim($row[0] ?? ''));
                if ($firstCell === 'no' || is_numeric($firstCell)) {
                    $headerMap['jenis']      = 1;
                    $headerMap['pertanyaan'] = 2;
                    $headerMap['opsi_a']     = 3;
                    $headerMap['opsi_b']     = 4;
                    $headerMap['opsi_c']     = 5;
                    $headerMap['opsi_d']     = 6;
                    $headerMap['opsi_e']     = 7;
                    $headerMap['kunci']      = 8;
                } elseif (in_array($firstCell, ['jenis_soal', 'jenis', 'pg', 'pilihan_ganda', 'essai'])) {
                    $headerMap['jenis']      = 0;
                    $headerMap['pertanyaan'] = 1;
                    $headerMap['opsi_a']     = 2;
                    $headerMap['opsi_b']     = 3;
                    $headerMap['opsi_c']     = 4;
                    $headerMap['opsi_d']     = 5;
                    $headerMap['opsi_e']     = 6;
                    $headerMap['kunci']      = 7;
                } else {
                    $headerMap['jenis']      = null;
                    $headerMap['pertanyaan'] = 0;
                    $headerMap['opsi_a']     = 1;
                    $headerMap['opsi_b']     = 2;
                    $headerMap['opsi_c']     = 3;
                    $headerMap['opsi_d']     = 4;
                    $headerMap['opsi_e']     = 5;
                    $headerMap['kunci']      = 6;
                }
            }
            continue; // Header selesai diproses
        }

        $pertanyaan = trim($row[$headerMap['pertanyaan']] ?? '');
        if ($pertanyaan === '') {
            $skipped++;
            continue;
        }

        $rawJenis = $headerMap['jenis'] !== null ? strtolower(trim($row[$headerMap['jenis']] ?? '')) : '';
        $oa       = $headerMap['opsi_a'] !== null ? trim($row[$headerMap['opsi_a']] ?? '') : '';
        $ob       = $headerMap['opsi_b'] !== null ? trim($row[$headerMap['opsi_b']] ?? '') : '';
        $oc       = $headerMap['opsi_c'] !== null ? trim($row[$headerMap['opsi_c']] ?? '') : '';
        $od       = $headerMap['opsi_d'] !== null ? trim($row[$headerMap['opsi_d']] ?? '') : '';
        $oe       = $headerMap['opsi_e'] !== null ? trim($row[$headerMap['opsi_e']] ?? '') : '';
        $kunci    = $headerMap['kunci'] !== null ? trim($row[$headerMap['kunci']] ?? '') : '';

        if ($rawJenis === '') {
            $rawJenis = ($oa === '' && $ob === '') ? 'essai' : 'pg';
        }

        $isEssai = in_array($rawJenis, ['essai', 'uraian', 'essay', 'esay', 'u'], true);

        if ($isEssai) {
            // Soal Essai / Uraian
            $jenisDb = 'essai';
            $oaDb = null;
            $obDb = null;
            $ocDb = null;
            $odDb = null;
            $oeDb = null;
            // Fallback kunci jika kolom bergeser
            if ($kunci === '' && isset($row[6]) && trim($row[6]) !== '' && $oa === '' && $ob === '') {
                $kunci = trim($row[6]);
            }
            $kunciDb = ($kunci !== '') ? $kunci : null;
            $importedEssai++;
        } else {
            // Soal Pilihan Ganda
            $jenisDb = 'pilihan_ganda';
            $kunciUpper = strtoupper($kunci);
            if ($oa === '' || $ob === '' || $oc === '' || $od === '' || !in_array($kunciUpper, ['A', 'B', 'C', 'D', 'E'], true)) {
                $skipped++;
                continue;
            }
            $oaDb = $oa;
            $obDb = $ob;
            $ocDb = $oc;
            $odDb = $od;
            $oeDb = ($oe !== '' ? $oe : null);
            $kunciDb = $kunciUpper;
            $importedPG++;
        }

        try {
            $stmtIns->execute([
                ':g'     => $idGuru,
                ':m'     => $idMapel,
                ':j'     => $judulSoal,
                ':jenis' => $jenisDb,
                ':p'     => $pertanyaan,
                ':oa'    => $oaDb,
                ':ob'    => $obDb,
                ':oc'    => $ocDb,
                ':od'    => $odDb,
                ':oe'    => $oeDb,
                ':k'     => $kunciDb
            ]);
        } catch (Exception $e) {
            if ($isEssai) $importedEssai--; else $importedPG--;
            $skipped++;
        }
    }

    fclose($handle);

    $totalImported = $importedPG + $importedEssai;
    if ($totalImported > 0) {
        flash_set('success', "Berhasil mengimpor {$totalImported} butir soal ({$importedPG} PG, {$importedEssai} Essai) ke paket '{$judulSoal}'." . ($skipped > 0 ? " (Dilewati: {$skipped})" : ''));
    } else {
        flash_set('warning', "Tidak ada soal yang berhasil diimpor. Pastikan file CSV memiliki kolom dan isi yang sesuai template.");
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
    <title>Import Soal - CBT Guru</title>
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

<main class="container" style="max-width: 620px; margin-top: 1.5rem; margin-bottom: 2rem;">
    <?php if ($flash): ?>
        <div class="alert alert-<?= sanitize($flash['type']) ?>" style="margin-bottom: 1rem;">
            <?= sanitize($flash['message']) ?>
        </div>
    <?php endif; ?>

    <div class="card" style="padding: 1.5rem; border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.04); background: #fff;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem; padding-bottom: 0.75rem; border-bottom: 1px solid #f1f5f9;">
            <div>
                <h1 style="font-size: 1.15rem; font-weight: 700; color: #0f172a; margin: 0;">Import Soal CSV</h1>
                <p style="font-size: 0.8rem; color: #64748b; margin: 0.15rem 0 0;">Upload file CSV untuk menambah butir soal secara massal.</p>
            </div>
            <a href="<?= base_url('guru/bank_soal.php') ?>" class="btn btn-sm btn-outline" style="font-size: 0.8rem;">Kembali</a>
        </div>

        <form action="<?= base_url('guru/import_soal.php') ?>" method="POST" enctype="multipart/form-data">
            <?= csrf_field() ?>

            <!-- 1. Mapel -->
            <div class="form-group" style="margin-bottom: 1rem;">
                <label for="id_mapel" style="font-size: 0.85rem; font-weight: 600; color: #334155; margin-bottom: 0.35rem; display: block;">Mata Pelajaran <span class="text-danger">*</span></label>
                <select name="id_mapel" id="id_mapel" class="form-control" required style="width: 100%; padding: 0.5rem 0.75rem; font-size: 0.875rem; border: 1px solid #cbd5e1; border-radius: 6px;">
                    <option value="">Pilih Mata Pelajaran</option>
                    <?php foreach ($mapelList as $m): ?>
                        <option value="<?= $m['id_mapel'] ?>" <?= (($_GET['id_mapel'] ?? '') == $m['id_mapel']) ? 'selected' : '' ?>><?= sanitize($m['nama_mapel']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- 2. Judul Paket Soal -->
            <div class="form-group" style="margin-bottom: 1rem;">
                <label for="judul_soal" style="font-size: 0.85rem; font-weight: 600; color: #334155; margin-bottom: 0.35rem; display: block;">Judul / Paket Soal <span class="text-danger">*</span></label>
                <input type="text" name="judul_soal" id="judul_soal" class="form-control" list="list_judul" placeholder="Contoh: Penilaian Harian 1" value="<?= sanitize($_GET['judul_soal'] ?? '') ?>" required style="width: 100%; padding: 0.5rem 0.75rem; font-size: 0.875rem; border: 1px solid #cbd5e1; border-radius: 6px;">
                <datalist id="list_judul">
                    <?php foreach ($existingJudul as $ej): ?>
                        <option value="<?= sanitize($ej) ?>"></option>
                    <?php endforeach; ?>
                    <option value="Penilaian Harian 1"></option>
                    <option value="Penilaian Tengah Semester"></option>
                    <option value="Penilaian Akhir Semester"></option>
                    <option value="Asesmen Nasional"></option>
                </datalist>
            </div>

            <!-- 3. Berkas CSV -->
            <div class="form-group" style="margin-bottom: 1.25rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.35rem;">
                    <label for="csv_file" style="font-size: 0.85rem; font-weight: 600; color: #334155; margin: 0;">Berkas CSV <span class="text-danger">*</span></label>
                    <a href="<?= base_url('guru/import_soal.php?action=download_template') ?>" style="font-size: 0.75rem; color: #2563eb; text-decoration: none; font-weight: 500;">Unduh Template CSV</a>
                </div>
                <input type="file" name="csv_file" id="csv_file" class="form-control" accept=".csv" required style="width: 100%; padding: 0.45rem 0.75rem; font-size: 0.85rem; border: 1px solid #cbd5e1; border-radius: 6px;">
                <span style="font-size: 0.75rem; color: #94a3b8; display: block; margin-top: 0.25rem;">Kolom CSV: <code>jenis_soal, pertanyaan, opsi_a, opsi_b, opsi_c, opsi_d, opsi_e, kunci_jawaban</code></span>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 0.5rem; margin-top: 1.25rem; padding-top: 1rem; border-top: 1px solid #f1f5f9;">
                <a href="<?= base_url('guru/bank_soal.php') ?>" class="btn btn-outline" style="font-size: 0.85rem; padding: 0.5rem 1rem;">Batal</a>
                <button type="submit" class="btn btn-primary" style="font-size: 0.85rem; padding: 0.5rem 1.25rem; font-weight: 600;">Import Soal</button>
            </div>
        </form>
    </div>
</main>

<script src="<?= base_url('assets/js/app.js') ?>"></script>
</body>
</html>
