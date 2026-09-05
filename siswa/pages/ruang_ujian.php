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
    SELECT b.id_soal, b.jenis_soal, b.pertanyaan, b.gambar, b.opsi_a, b.opsi_b, b.opsi_c, b.opsi_d, b.opsi_e, b.kunci_jawaban,
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

    // Deteksi jika soal merupakan Pilihan Ganda Kompleks (kunci jawaban > 1 opsi atau jenis_soal)
    $isEssai    = ($item['jenis_soal'] ?? 'pilihan_ganda') === 'essai';
    $kunciArr   = array_filter(array_map('trim', explode(',', $item['kunci_jawaban'] ?? '')));
    $isKompleks = !$isEssai && ((count($kunciArr) > 1) || (($item['jenis_soal'] ?? '') === 'pilihan_ganda_kompleks'));

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
        'is_kompleks'      => (bool)$isKompleks,
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
<header class="cbt-exam-header app-safe-top">
    <div class="cbt-header-info">
        <div class="cbt-exam-title-row" style="display: flex; align-items: center; gap: 0.6rem; flex-wrap: wrap;">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" style="color: #60a5fa; flex-shrink: 0;"><path d="M22 10v6M2 10l10-5 10 5-10 5z"></path><path d="M6 12v5c3 3 9 3 12 0v-5"></path></svg>
            <span class="cbt-exam-title" style="color: #ffffff !important; font-size: 1.15rem; font-weight: 800; letter-spacing: 0.3px;"><?= sanitize($ujianSiswa['nama_ujian']) ?></span>
            <span class="badge" style="background: rgba(59, 130, 246, 0.25); color: #93c5fd; border: 1px solid rgba(147, 197, 253, 0.35); font-weight: 700; font-size: 0.75rem; padding: 0.2rem 0.55rem; border-radius: 999px;"><?= sanitize($ujianSiswa['nama_mapel']) ?></span>
        </div>
        <div class="cbt-student-info" style="display: inline-flex; align-items: center; gap: 0.5rem; color: #94a3b8; font-size: 0.8rem; margin-top: 0.35rem; background: rgba(15, 23, 42, 0.65); border: 1px solid #334155; padding: 0.25rem 0.65rem; border-radius: 6px; width: fit-content;">
            <strong style="color: #f1f5f9; font-weight: 700;"><?= sanitize($currentUser['nama_lengkap']) ?></strong>
            <span style="color: #475569;">|</span>
            <span>NIS: <code style="color: #93c5fd; background: rgba(30, 41, 59, 0.8); border: 1px solid rgba(148, 163, 184, 0.2); padding: 1px 6px; border-radius: 4px; font-family: monospace; font-size: 0.85rem; font-weight: 700;"><?= sanitize($currentUser['nis'] ?? '-') ?></code></span>
        </div>
    </div>
    <div class="flex gap-2 cbt-header-actions" style="align-items: center;">
        <div class="timer-container" style="background: #090d1a; border: 1px solid #334155; border-radius: 8px; padding: 0.4rem 0.85rem; display: flex; align-items: center; gap: 0.5rem;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#38bdf8" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
            <span id="timer-display" class="timer-display" style="font-family: monospace; font-size: 1.25rem; font-weight: 800; color: #38bdf8; letter-spacing: 1px;">00:00:00</span>
        </div>
        <a href="<?= base_url('siswa?page=konfirmasi') ?>" class="btn-back-square" title="Kembali ke Halaman Utama" onclick="return confirm('Kembali ke halaman utama? (Jawaban Anda yang sudah dipilih telah tersimpan dan ujian dapat dilanjutkan kembali selama waktu masih ada)');">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
        </a>
    </div>
</header>

<!-- Main Exam Layout -->
<main class="cbt-exam-layout">
    <!-- Kolom Kiri: Box Soal (1 Soal per Layar) -->
    <div class="soal-box">
        <!-- Top Bar Soal -->
        <div class="soal-top-bar">
            <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
                <div id="soal-nomor-badge" class="soal-nomor-badge">
                    SOAL NO. 1 DARI <?= count($soalClean) ?>
                </div>
                <span id="soal-tipe-badge" class="badge" style="display: none; font-size: 0.75rem; padding: 0.25rem 0.65rem; border-radius: 9999px; font-weight: 700;"></span>
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
                <button type="button" id="btn-close-grid" class="btn btn-sm btn-outline webview-grid-close" aria-label="Tutup">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                </button>
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
            if (window.innerWidth <= 768) {
                gridSidebar.scrollIntoView({ behavior: 'smooth', block: 'start' });
            } else {
                gridSidebar.style.transition = 'box-shadow 0.3s ease';
                gridSidebar.style.boxShadow = '0 0 0 3px rgba(37, 99, 235, 0.5)';
                setTimeout(() => { gridSidebar.style.boxShadow = ''; }, 900);
            }
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
