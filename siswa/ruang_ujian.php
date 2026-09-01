<?php
/**
 * Interface Utama Ruang Ujian CBT (UNBK Clean Layout)
 * 1 Soal per Layar, Navigasi Grid, Timer Sinkron, AJAX Auto-Save
 */

require_once __DIR__ . '/../middleware/auth.php';

$currentUser = auth_check(['siswa']);
$db = get_db();
$idSiswa = $currentUser['id_user'];

// 1. Ambil Data Ujian Siswa yang Sedang Berjalan (Waktu Sesi Global)
$stmtUs = $db->prepare("
    SELECT us.*, s.nama_ujian, s.durasi_menit, s.acak_opsi, m.nama_mapel,
           GREATEST(0, FLOOR(EXTRACT(EPOCH FROM (s.created_at + (s.durasi_menit * INTERVAL '1 minute') - CURRENT_TIMESTAMP))))::int as sisa_detik_real
    FROM ujian_siswa us
    JOIN sesi_ujian s ON us.id_sesi = s.id_sesi
    JOIN mapel m ON s.id_mapel = m.id_mapel
    WHERE us.id_siswa = :s AND us.status = 'sedang'
    LIMIT 1
");
$stmtUs->execute([':s' => $idSiswa]);
$ujianSiswa = $stmtUs->fetch();

if (!$ujianSiswa) {
    flash_set('info', 'Tidak ada sesi ujian yang sedang aktif.');
    redirect(base_url('siswa/konfirmasi.php'));
}

$idUjianSiswa = $ujianSiswa['id_ujian_siswa'];

// Waktu server nyata yang terus berjalan
$sisaDetikReal = (int)$ujianSiswa['sisa_detik_real'];
$ujianSiswa['sisa_detik'] = $sisaDetikReal;

// Pastikan waktu_mulai terisi dan sisa_detik terupdate di server
$updSec = $db->prepare("
    UPDATE ujian_siswa 
    SET sisa_detik = :s,
        waktu_mulai = COALESCE(waktu_mulai, CURRENT_TIMESTAMP) 
    WHERE id_ujian_siswa = :id
");
$updSec->execute([':s' => $sisaDetikReal, ':id' => $idUjianSiswa]);

// Jika sisa detik sudah habis berdasarkan waktu server, arahkan ke finalisasi
if ($sisaDetikReal <= 0) {
    redirect(base_url('siswa/proses_selesai.php?id=' . $idUjianSiswa));
}

// 2. Ambil Urutan Soal dari JSONB
$urutanIds = json_decode($ujianSiswa['urutan_soal'], true);
if (empty($urutanIds)) {
    flash_set('danger', 'Urutan butir soal kosong.');
    redirect(base_url('siswa/konfirmasi.php'));
}

// 3. Ambil Butir Soal dan Jawaban Siswa
// Bentuk string placeholder untuk IN query
$placeholders = implode(',', array_fill(0, count($urutanIds), '?'));

$stmtSoal = $db->prepare("
    SELECT b.id_soal, b.jenis_soal, b.pertanyaan, b.gambar, b.opsi_a, b.opsi_b, b.opsi_c, b.opsi_d, b.opsi_e,
           COALESCE(j.jawaban_terpilih, '') as jawaban_terpilih,
           COALESCE(j.status_ragu, false) as status_ragu
    FROM bank_soal b
    LEFT JOIN jawaban_siswa j ON (j.id_soal = b.id_soal AND j.id_ujian_siswa = ?)
    WHERE b.id_soal IN ($placeholders)
");

$queryParams = array_merge([$idUjianSiswa], $urutanIds);
$stmtSoal->execute($queryParams);
$rawSoal = $stmtSoal->fetchAll();

// Mapping array berdasarkan id_soal
$soalMap = [];
foreach ($rawSoal as $s) {
    $soalMap[$s['id_soal']] = $s;
}

// Susun data soal sesuai urutan yang tersimpan di urutan_soal
$soalClean = [];
foreach ($urutanIds as $index => $sid) {
    if (!isset($soalMap[$sid])) continue;
    $item = $soalMap[$sid];

    // Persiapkan Opsi jika pilihan ganda
    $opsiArray = [
        ['code' => 'A', 'text' => $item['opsi_a']],
        ['code' => 'B', 'text' => $item['opsi_b']],
        ['code' => 'C', 'text' => $item['opsi_c']],
        ['code' => 'D', 'text' => $item['opsi_d']],
    ];
    if (!empty($item['opsi_e'])) {
        $opsiArray[] = ['code' => 'E', 'text' => $item['opsi_e']];
    }

    $soalClean[] = [
        'index'            => $index,
        'id_soal'          => (int)$item['id_soal'],
        'jenis_soal'       => $item['jenis_soal'] ?? 'pilihan_ganda',
        'pertanyaan'       => nl2br(sanitize($item['pertanyaan'])),
        'gambar'           => !empty($item['gambar']) ? base_url(ltrim($item['gambar'], '/')) : null,
        'opsi'             => $opsiArray,
        'jawaban_terpilih' => $item['jawaban_terpilih'],
        'status_ragu'      => (bool)$item['status_ragu']
    ];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title><?= sanitize($ujianSiswa['nama_ujian']) ?> - CBT Mobile WebView</title>
    <link rel="icon" type="image/svg+xml" href="<?= base_url('assets/img/favicon.svg') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/cbt-style.css') ?>">
</head>
<body class="cbt-webview-exam-body">

<!-- CBT Header (WebView App-Bar) -->
<header class="cbt-exam-header app-safe-top">
    <div class="cbt-header-info">
        <div class="cbt-exam-title"><?= sanitize($ujianSiswa['nama_ujian']) ?></div>
        <div class="text-xs text-muted cbt-student-info">
            <strong><?= sanitize($currentUser['nama_lengkap']) ?></strong> | NIS: <code><?= sanitize($currentUser['nis'] ?? '-') ?></code> (<?= sanitize($ujianSiswa['nama_mapel']) ?>)
        </div>
    </div>
    <div class="flex gap-2 cbt-header-actions" style="align-items: center;">
        <span id="status-sync" class="sync-badge saved">Tersinkron</span>
        <div class="timer-container">
            <span class="timer-label">WAKTU:</span>
            <span id="timer-display" class="timer-display">00:00:00</span>
        </div>
        <button type="button" id="btn-toggle-grid" class="btn btn-sm btn-outline webview-grid-toggle" title="Daftar Soal">
            📋 Grid Soal
        </button>
    </div>
</header>

<!-- Main Exam Layout -->
<main class="cbt-exam-layout">
    <!-- Kolom Kiri: Box Soal (1 Soal per Layar) -->
    <div class="soal-box">
        <!-- Top Bar Soal -->
        <div class="soal-top-bar">
            <div id="soal-nomor-badge" class="soal-nomor-badge">
                SOAL NO. 1 DARI <?= count($soalClean) ?>
            </div>
            <div>
                <button type="button" id="btn-finish-modal" class="btn btn-sm btn-danger font-bold" style="padding: 0.35rem 0.75rem; font-size: 0.8rem;">
                    SELESAIKAN UJIAN
                </button>
            </div>
        </div>

        <!-- Isi Pertanyaan & Opsi -->
        <div class="soal-content">
            <div id="soal-pertanyaan" class="mb-3"></div>

            <div id="soal-image-container" style="display: none;">
                <img id="soal-img-tag" src="" alt="Gambar Soal" class="soal-image">
            </div>

            <!-- Opsi Pilihan Ganda -->
            <div id="opsi-list-container" class="opsi-list"></div>
        </div>

        <!-- Navigasi Bawah (Touch Friendly) -->
        <div class="soal-nav-bar app-safe-bottom">
            <button type="button" id="btn-prev" class="btn btn-outline touch-btn">
                ◄ SEBELUMNYA
            </button>

            <div>
                <label class="ragu-label-touch">
                    <input type="checkbox" id="chk-ragu" style="transform: scale(1.3); accent-color: #f59e0b; cursor: pointer;">
                    <span>RAGU - RAGU</span>
                </label>
            </div>

            <button type="button" id="btn-next" class="btn btn-primary touch-btn">
                SELANJUTNYA ►
            </button>
        </div>
    </div>

    <!-- Kolom Kanan / Mobile Drawer: Grid Nomor Soal -->
    <aside id="cbt-grid-sidebar" class="grid-box">
        <div class="grid-header">
            <span>DAFTAR NOMOR SOAL</span>
            <div class="flex gap-2" style="align-items: center;">
                <span class="text-xs text-muted"><?= count($soalClean) ?> Butir</span>
                <button type="button" id="btn-close-grid" class="btn btn-sm btn-outline webview-grid-close">✕</button>
            </div>
        </div>

        <div id="soal-grid-container" class="soal-grid"></div>

        <!-- Legend / Petunjuk Warna -->
        <div class="grid-legend">
            <div class="legend-item">
                <span class="legend-color unanswered"></span>
                <span>Belum Dijawab (Abu-abu)</span>
            </div>
            <div class="legend-item">
                <span class="legend-color answered"></span>
                <span>Sudah Dijawab (Hijau)</span>
            </div>
            <div class="legend-item">
                <span class="legend-color doubt"></span>
                <span>Ragu-ragu (Kuning)</span>
            </div>
            <div class="legend-item">
                <span class="legend-color essai"></span>
                <span>Soal Essai di Kertas (Ungu)</span>
            </div>
        </div>
    </aside>
</main>

<!-- Modal Konfirmasi Selesai Ujian -->
<div id="modal-selesai" class="modal-overlay">
    <div class="modal-box">
        <h2 class="card-title text-center mb-2">Konfirmasi Pengumpulan Ujian</h2>
        <p class="text-sm text-muted text-center">Pastikan seluruh lembar jawaban telah Anda periksa sebelum mengakhiri sesi tes ini.</p>

        <div id="modal-ringkasan-soal"></div>

        <form id="form-selesai-ujian" action="<?= base_url('siswa/proses_selesai.php') ?>" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="id_ujian_siswa" value="<?= $idUjianSiswa ?>">

            <div class="flex gap-2 mt-4" style="justify-content: flex-end;">
                <button type="button" id="btn-batal-modal" class="btn btn-outline">Kembali Mengerjakan</button>
                <button type="submit" id="btn-submit-akhir" class="btn btn-danger font-bold">SELESAIKAN UJIAN</button>
            </div>
        </form>
    </div>
</div>

<!-- Load Standalone Modules -->
<script src="<?= base_url('assets/js/timer.js') ?>"></script>
<script src="<?= base_url('assets/js/ujian.js') ?>"></script>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const sisaDetikServer = <?= (int)$ujianSiswa['sisa_detik'] ?>;
    const idUjianSiswa    = <?= (int)$idUjianSiswa ?>;
    const csrfToken       = '<?= csrf_token() ?>';
    const soalDataJson    = <?= json_encode($soalClean, JSON_UNESCAPED_UNICODE) ?>;

    // 1. Inisialisasi Timer Sinkron
    const timer = new CBTTimer({
        initialSeconds: sisaDetikServer,
        displayElementId: 'timer-display',
        onTick: (secondsLeft) => {
            // Bisa digunakan untuk logging periodik jika diperlukan
        },
        onTimeUp: () => {
            alert('Waktu ujian telah habis! Sistem akan mengumpulkan lembar jawaban Anda secara otomatis.');
            document.getElementById('form-selesai-ujian').submit();
        }
    });

    timer.start();

    // 2. Inisialisasi CBT Exam Manager
    const examManager = new CBTExamManager({
        idUjianSiswa: idUjianSiswa,
        csrfToken: csrfToken,
        saveUrl: '<?= base_url('siswa/ajax_save.php') ?>',
        raguUrl: '<?= base_url('siswa/ajax_ragu.php') ?>',
        finishUrl: '<?= base_url('siswa/proses_selesai.php') ?>',
        soalData: soalDataJson,
        timerInstance: timer
    });

    // 3. Mobile WebView Grid Drawer Logic
    const btnToggleGrid = document.getElementById('btn-toggle-grid');
    const btnCloseGrid  = document.getElementById('btn-close-grid');
    const gridSidebar   = document.getElementById('cbt-grid-sidebar');

    if (btnToggleGrid && gridSidebar) {
        btnToggleGrid.addEventListener('click', () => {
            gridSidebar.classList.toggle('drawer-open');
        });
    }
    if (btnCloseGrid && gridSidebar) {
        btnCloseGrid.addEventListener('click', () => {
            gridSidebar.classList.remove('drawer-open');
        });
    }
    // Otomatis scroll ke atas saat nomor soal diklik di mobile
    gridSidebar.addEventListener('click', (e) => {
        if (e.target.classList.contains('soal-num-btn')) {
            if (window.innerWidth <= 768) {
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
        }
    });
});
</script>

</body>
</html>
