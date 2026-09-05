<?php
/**
 * Page: Interface Utama Ruang Ujian CBT (UNBK Clean Layout)
 * 1 Soal per Layar, Navigasi Grid, Timer Sinkron, AJAX Auto-Save
 */

require_once __DIR__ . '/../../middleware/auth.php';

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
    redirect(base_url('siswa?page=konfirmasi'));
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
    redirect(base_url('siswa?page=konfirmasi'));
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
        'pertanyaan'       => $item['pertanyaan'],
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
    <title><?= sanitize($ujianSiswa['nama_ujian']) ?> - CBT Ruang Ujian</title>
    <link rel="icon" type="image/svg+xml" href="<?= base_url('assets/img/favicon.svg') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/cbt-style.css') ?>">
</head>
<body class="cbt-fullscreen app-webview-body">

<!-- Header Ujian (Fixed Top) -->
<header class="cbt-exam-header">
    <div class="header-left">
        <div class="cbt-logo-badge">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 10v6M2 10l10-5 10 5-10 5z"></path><path d="M6 12v5c3 3 9 3 12 0v-5"></path></svg>
            <span class="exam-title-text"><?= sanitize($ujianSiswa['nama_ujian']) ?></span>
        </div>
        <span class="exam-mapel-text">(<?= sanitize($ujianSiswa['nama_mapel']) ?>)</span>
    </div>

    <div class="header-right">
        <!-- Floating Global Real-time Countdown Timer -->
        <div class="cbt-timer" id="timer-container" title="Sisa waktu ujian Anda">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
            <span id="timer-display">--:--:--</span>
        </div>

        <!-- Tombol Toggle Grid Nomor (Mobile/Drawer) -->
        <button type="button" id="btn-toggle-grid" class="btn-toggle-grid" aria-label="Buka Daftar Nomor">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
            <span>Daftar Soal</span>
        </button>
    </div>
</header>

<main class="cbt-exam-wrapper">
    <!-- Area Lembar Soal (Kiri) -->
    <section class="cbt-question-area" id="cbt-question-area">
        <!-- Top Toolbar Soal: Nomor & Ukuran Font -->
        <div class="question-header">
            <div class="question-number">
                <span>Soal No.</span>
                <strong id="current-question-num">1</strong>
                <span id="label-jenis-soal" style="font-size: 0.8rem; margin-left: 0.5rem; font-weight: 700;"></span>
            </div>
            
            <div class="font-size-adjuster" title="Ubah Ukuran Tulisan">
                <span class="text-xs text-muted">Font:</span>
                <button type="button" class="btn-font-size" data-size="small">A-</button>
                <button type="button" class="btn-font-size active" data-size="normal">A</button>
                <button type="button" class="btn-font-size" data-size="large">A+</button>
            </div>
        </div>

        <!-- Kontainer Butir Pertanyaan -->
        <div class="question-content font-normal" id="question-text-box">
            <div id="soal-image-container" class="question-image mb-3" style="display: none;">
                <img id="soal-image" src="" alt="Gambar Soal" style="max-height: 250px; border-radius: var(--radius-sm); border: 1px solid var(--gray-300);">
            </div>
            <div id="soal-text" class="question-text">
                Memuat butir pertanyaan...
            </div>
        </div>

        <!-- Kontainer Pilihan Jawaban (Radio Kustom) -->
        <div class="question-options" id="options-container">
            <!-- Diisi secara dinamis oleh JavaScript -->
        </div>

        <!-- Bottom Navigation Controls -->
        <footer class="question-nav-footer">
            <button type="button" id="btn-prev" class="btn btn-outline btn-nav">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"></polyline></svg>
                <span>Sebelumnya</span>
            </button>

            <!-- Checkbox Ragu-ragu -->
            <label class="checkbox-ragu" id="label-ragu" title="Tandai jika masih ragu dengan jawaban ini">
                <input type="checkbox" id="check-ragu">
                <span class="ragu-indicator"></span>
                <span>Ragu-ragu</span>
            </label>

            <button type="button" id="btn-next" class="btn btn-primary btn-nav">
                <span>Berikutnya</span>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"></polyline></svg>
            </button>

            <button type="button" id="btn-finish" class="btn btn-danger btn-nav" style="display: none;">
                <span>Selesai Ujian</span>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
            </button>
        </footer>
    </section>

    <!-- Drawer Navigasi Nomor Soal (Kanan) -->
    <aside class="cbt-grid-sidebar" id="cbt-grid-sidebar">
        <div class="sidebar-header">
            <h2 class="sidebar-title">Daftar Nomor Soal</h2>
            <button type="button" id="btn-close-grid" class="btn-close-sidebar" aria-label="Tutup Menu">&times;</button>
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
                <span>Soal Essai Belum Diisi (Ungu)</span>
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
<script src="<?= base_url('assets/js/server-alert.js') ?>"></script>

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
