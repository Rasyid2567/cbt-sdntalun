<?php
/**
 * Page: Halaman Konfirmasi Tes & Masukkan Token Ujian (Siswa Peserta)
 */

require_once __DIR__ . '/../../middleware/auth.php';

$currentUser = auth_check(['siswa']);
$db = get_db();
$idSiswa = $currentUser['id_user'];
$idKelas = $currentUser['id_kelas'];

// 1. Cek apakah ada ujian yang sedang berlangsung (status = 'sedang')
$stmtAktif = $db->prepare("
    SELECT us.*, s.nama_ujian, m.nama_mapel 
    FROM ujian_siswa us
    JOIN sesi_ujian s ON us.id_sesi = s.id_sesi
    JOIN mapel m ON s.id_mapel = m.id_mapel
    WHERE us.id_siswa = :s AND us.status = 'sedang'
    LIMIT 1
");
$stmtAktif->execute([':s' => $idSiswa]);
$ujianBerjalan = $stmtAktif->fetch();

// 2. Ambil Daftar Sesi Ujian yang Aktif untuk Kelas Siswa
$stmtSesiTersedia = $db->prepare("
    SELECT s.*, m.nama_mapel, k.nama_kelas, p.nama_paket,
           (SELECT COUNT(*) FROM bank_soal WHERE id_paket = s.id_paket) as total_soal,
           us.status as status_ujian_siswa, us.id_ujian_siswa,
           GREATEST(0, FLOOR(EXTRACT(EPOCH FROM (s.created_at + (s.durasi_menit * INTERVAL '1 minute') - CURRENT_TIMESTAMP))))::int as sisa_detik_sesi
    FROM sesi_ujian s
    LEFT JOIN paket_soal p ON s.id_paket = p.id_paket
    JOIN mapel m ON s.id_mapel = m.id_mapel
    JOIN kelas k ON s.id_kelas = k.id_kelas
    LEFT JOIN ujian_siswa us ON (s.id_sesi = us.id_sesi AND us.id_siswa = :siswa)
    WHERE s.id_kelas = :kelas AND s.status = 'aktif'
    ORDER BY s.created_at DESC
");
$stmtSesiTersedia->execute([':siswa' => $idSiswa, ':kelas' => $idKelas]);
$sesiTersedia = $stmtSesiTersedia->fetchAll();

// 3. Proses Input Token Ujian
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash_set('danger', 'Validasi token keamanan gagal.');
        redirect(base_url('siswa?page=konfirmasi'));
    }

    $idSesiInput = (int)($_POST['id_sesi'] ?? 0);
    $tokenInput  = strtoupper(trim($_POST['token_ujian'] ?? ''));

    if ($idSesiInput <= 0 || $tokenInput === '') {
        flash_set('danger', 'Silakan pilih sesi ujian dan masukkan token.');
        redirect(base_url('siswa?page=konfirmasi'));
    }

    // Validasi token dan sesi beserta sisa waktu global
    $stmtCek = $db->prepare("
        SELECT *, 
               GREATEST(0, FLOOR(EXTRACT(EPOCH FROM (created_at + (durasi_menit * INTERVAL '1 minute') - CURRENT_TIMESTAMP))))::int as sisa_detik_sesi
        FROM sesi_ujian 
        WHERE id_sesi = :id AND id_kelas = :k AND status = 'aktif'
    ");
    $stmtCek->execute([':id' => $idSesiInput, ':k' => $idKelas]);
    $sesi = $stmtCek->fetch();

    if (!$sesi) {
        flash_set('danger', 'Sesi ujian tidak valid atau sudah nonaktif.');
        redirect(base_url('siswa?page=konfirmasi'));
    }

    if ($sesi['token_ujian'] !== $tokenInput) {
        flash_set('danger', 'Token ujian yang Anda masukkan SALAH.');
        redirect(base_url('siswa?page=konfirmasi'));
    }

    if ((int)$sesi['sisa_detik_sesi'] <= 0) {
        flash_set('danger', 'Waktu pengerjaan sesi ujian ini telah berakhir.');
        redirect(base_url('siswa?page=konfirmasi'));
    }

    // Cek apakah sudah pernah menyelesaikan
    $stmtUs = $db->prepare("SELECT * FROM ujian_siswa WHERE id_sesi = :sesi AND id_siswa = :siswa");
    $stmtUs->execute([':sesi' => $idSesiInput, ':siswa' => $idSiswa]);
    $existingUs = $stmtUs->fetch();

    if ($existingUs) {
        if ($existingUs['status'] === 'selesai') {
            flash_set('warning', 'Anda telah menyelesaikan ujian ini.');
            redirect(base_url('siswa?page=hasil&id_ujian_siswa=' . $existingUs['id_ujian_siswa']));
        } elseif ($existingUs['status'] === 'sedang') {
            // Lanjutkan pengerjaan
            redirect(base_url('siswa?page=ruang_ujian'));
        }
    }

    // Ambil daftar butir soal untuk paket ini
    if (!empty($sesi['id_paket'])) {
        $stmtSoal = $db->prepare("SELECT id_soal FROM bank_soal WHERE id_paket = :p ORDER BY id_soal ASC");
        $stmtSoal->execute([':p' => $sesi['id_paket']]);
    } else {
        $stmtSoal = $db->prepare("SELECT id_soal FROM bank_soal WHERE id_paket IN (SELECT id_paket FROM paket_soal WHERE id_mapel = :m) ORDER BY id_soal ASC");
        $stmtSoal->execute([':m' => $sesi['id_mapel']]);
    }
    $soalRows = $stmtSoal->fetchAll(PDO::FETCH_COLUMN);

    if (empty($soalRows)) {
        flash_set('danger', 'Belum ada butir soal yang diinput pada ujian ini. Hubungi proktor.');
        redirect(base_url('siswa?page=konfirmasi'));
    }

    // Acak Urutan Soal jika opsi diaktifkan
    if ($sesi['acak_soal']) {
        shuffle($soalRows);
    }

    $urutanSoalJson = json_encode($soalRows);
    $durasiDetik    = (int)$sesi['sisa_detik_sesi'];

    // Buat Rekaman Log Pengerjaan Ujian Siswa
    $insUs = $db->prepare("
        INSERT INTO ujian_siswa (id_sesi, id_siswa, urutan_soal, waktu_mulai, sisa_detik, status)
        VALUES (:sesi, :siswa, :urutan, CURRENT_TIMESTAMP, :sisa, 'sedang')
        ON CONFLICT (id_sesi, id_siswa) DO UPDATE 
        SET status = 'sedang', waktu_mulai = COALESCE(ujian_siswa.waktu_mulai, CURRENT_TIMESTAMP)
        RETURNING id_ujian_siswa
    ");
    $insUs->execute([
        ':sesi'   => $idSesiInput,
        ':siswa'  => $idSiswa,
        ':urutan' => $urutanSoalJson,
        ':sisa'   => $durasiDetik
    ]);
    $newUjianSiswaId = $insUs->fetchColumn();

    // Inisialisasi baris jawaban siswa untuk setiap soal
    $stmtInsJwb = $db->prepare("
        INSERT INTO jawaban_siswa (id_ujian_siswa, id_soal, jawaban_terpilih, status_ragu)
        VALUES (:us, :soal, NULL, FALSE)
        ON CONFLICT (id_ujian_siswa, id_soal) DO NOTHING
    ");
    foreach ($soalRows as $sid) {
        $stmtInsJwb->execute([':us' => $newUjianSiswaId, ':soal' => $sid]);
    }

    redirect(base_url('siswa?page=ruang_ujian'));
}

$flash = flash_get();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Konfirmasi Tes - CBT Siswa</title>
    <link rel="icon" type="image/svg+xml" href="<?= base_url('assets/img/favicon.svg') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/cbt-style.css') ?>">
</head>
<body class="app-webview-body">

<header class="cbt-navbar cbt-navbar-student flex-between">
    <div class="cbt-navbar-brand">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 10v6M2 10l10-5 10 5-10 5z"></path><path d="M6 12v5c3 3 9 3 12 0v-5"></path></svg>
        <span>CBT PESERTA</span>
    </div>
    <div class="flex gap-2" style="align-items: center; flex-shrink: 0;">
        <span class="user-badge"><?= sanitize($currentUser['nama_lengkap']) ?></span>
        <a href="<?= base_url('logout.php') ?>" class="btn btn-sm btn-danger btn-logout" style="display: inline-flex; align-items: center; gap: 0.25rem;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
            <span>Keluar</span>
        </a>
    </div>
</header>

<main class="container" style="max-width: 960px;">
    <?php if ($flash): ?>
        <div class="alert alert-<?= sanitize($flash['type']) ?>">
            <?= sanitize($flash['message']) ?>
        </div>
    <?php endif; ?>

    <!-- Jika ada sesi yang sedang berjalan -->
    <?php if ($ujianBerjalan): ?>
        <div class="card" style="background: #f8fafc; border: 1px solid #bfdbfe;">
            <div class="flex-between" style="flex-wrap: wrap; gap: 1rem;">
                <div>
                    <h2 style="font-size: 1.25rem; font-weight: 800; color: var(--primary);">UJIAN ANDA SEDANG BERLANGSUNG</h2>
                    <p class="text-sm text-muted mt-1">
                        Ujian <strong><?= sanitize($ujianBerjalan['nama_ujian']) ?></strong> (<?= sanitize($ujianBerjalan['nama_mapel']) ?>) belum diselesaikan. Sisa waktu: <strong><?= ceil($ujianBerjalan['sisa_detik'] / 60) ?> Menit</strong>.
                    </p>
                </div>
                <div>
                    <a href="<?= base_url('siswa?page=ruang_ujian') ?>" class="btn btn-primary btn-lg" style="min-height: 48px;">Lanjutkan Ujian Sekarang</a>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Informasi Peserta -->
    <div class="card">
        <div class="card-header">
            <h1 class="card-title">Konfirmasi Data Peserta Ujian</h1>
        </div>
        <div class="table-responsive">
            <table class="table" style="margin-top: 0;">
                <tbody>
                    <tr>
                        <td style="width: 200px; font-weight: 600;">Nomor Induk Siswa (NIS)</td>
                        <td>: <strong style="font-family: monospace; font-size: 1.05rem; color: #1e40af;"><?= sanitize($currentUser['nis'] ?? '-') ?></strong></td>
                    </tr>
                    <tr>
                        <td style="font-weight: 600;">Username Akun</td>
                        <td>: <strong><?= sanitize($currentUser['username']) ?></strong></td>
                    </tr>
                    <tr>
                        <td style="font-weight: 600;">Nama Lengkap Peserta</td>
                        <td>: <?= sanitize($currentUser['nama_lengkap']) ?></td>
                    </tr>
                    <tr>
                        <td style="font-weight: 600;">Status Sesi Perangkat</td>
                        <td>: <span class="badge badge-online">TERVERIFIKASI ONLINE</span></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Sesi Ujian Tersedia -->
    <div class="card" style="padding: 1.25rem;">
        <div class="card-header" style="margin-bottom: 1rem;">
            <div>
                <h2 class="card-title">Daftar Sesi Ujian Aktif</h2>
            </div>
        </div>

        <?php if (empty($sesiTersedia)): ?>
            <p class="text-center text-muted" style="padding: 2.5rem 0;">Tidak ada jadwal sesi ujian yang sedang aktif untuk kelas Anda saat ini.</p>
        <?php else: ?>
            <div style="display: grid; grid-template-columns: 1fr; gap: 1rem;">
                <?php foreach ($sesiTersedia as $s): ?>
                    <div class="cbt-exam-card">
                        <div class="cbt-exam-card-header">
                            <div class="cbt-exam-mapel">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" color="var(--primary)"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg>
                                <span><?= sanitize($s['nama_mapel']) ?></span>
                            </div>
                            <span class="badge" style="background:#e0e7ff; color:#1e40af; font-weight:700; font-size:0.75rem;"><?= sanitize($s['nama_kelas']) ?></span>
                        </div>
                        <div class="cbt-exam-card-body">
                            <h3 class="cbt-exam-title"><?= sanitize($s['nama_ujian']) ?></h3>
                            <div class="cbt-exam-meta-pills">
                                <?php if ($s['sisa_detik_sesi'] > 0): ?>
                                    <span class="cbt-meta-pill" style="background: #eff6ff; color: #1d4ed8;">Sisa Waktu: <strong class="countdown-timer" data-seconds="<?= (int)$s['sisa_detik_sesi'] ?>">--:--:--</strong></span>
                                <?php else: ?>
                                    <span class="cbt-meta-pill" style="background: #fee2e2; color: #dc2626;"><strong>Waktu Sesi Berakhir</strong></span>
                                <?php endif; ?>
                                <span class="cbt-meta-pill">Jumlah: <strong><?= $s['total_soal'] ?> Soal</strong></span>
                            </div>
                        </div>
                        <div class="cbt-exam-card-footer">
                            <?php if ($s['status_ujian_siswa'] === 'selesai'): ?>
                                <span class="text-success" style="font-weight: 700; font-size: 0.88rem;">Telah Diselesaikan</span>
                                <a href="<?= base_url('siswa?page=hasil&id_ujian_siswa=' . $s['id_ujian_siswa']) ?>" class="btn btn-sm btn-outline">Bukti Selesai</a>
                            <?php elseif ($s['status_ujian_siswa'] === 'sedang'): ?>
                                <span class="text-primary" style="font-weight: 700; font-size: 0.88rem;">Sedang Dikerjakan</span>
                                <a href="<?= base_url('siswa?page=ruang_ujian') ?>" class="btn btn-sm btn-primary">Lanjutkan Ujian</a>
                            <?php elseif ($s['sisa_detik_sesi'] <= 0): ?>
                                <span class="text-danger" style="font-size: 0.85rem; font-weight: 700;">Waktu Sesi Berakhir</span>
                                <button type="button" class="btn btn-secondary btn-sm" disabled style="opacity: 0.6; cursor: not-allowed;">
                                    Sesi Selesai
                                </button>
                            <?php else: ?>
                                <span class="text-muted" style="font-size: 0.85rem; font-weight: 600;">Status: Siap Dikerjakan</span>
                                <button type="button" class="btn btn-primary" onclick="bukaKonfirmasi(<?= htmlspecialchars(json_encode([
                                    'id_sesi'      => (int)$s['id_sesi'],
                                    'nama_ujian'   => $s['nama_ujian'],
                                    'nama_mapel'   => $s['nama_mapel'],
                                    'nama_kelas'   => $s['nama_kelas'],
                                    'durasi_menit' => ceil($s['sisa_detik_sesi'] / 60),
                                    'total_soal'   => (int)$s['total_soal']
                                ])) ?>)" style="min-height: 40px; font-weight: 700; padding: 0.5rem 1.25rem;">
                                    Kerjakan Ujian
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</main>

<!-- Modal Pop-up Konfirmasi Tes & Token -->
<div class="modal-overlay" id="modal-konfirmasi-tes">
    <div class="modal-box" style="max-width: 500px; border-radius: 12px; padding: 1.35rem;">
        <div class="flex-between mb-3" style="border-bottom: 1px solid var(--gray-200); padding-bottom: 0.75rem;">
            <h2 style="font-size: 1.15rem; font-weight: 800; color: var(--gray-900); display: flex; align-items: center; gap: 0.5rem; margin: 0;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" color="var(--primary)"><path d="M22 10v6M2 10l10-5 10 5-10 5z"></path><path d="M6 12v5c3 3 9 3 12 0v-5"></path></svg>
                Konfirmasi Tes & Token
            </h2>
            <button type="button" class="btn btn-sm btn-outline" onclick="closeModal('modal-konfirmasi-tes')" style="padding: 0.2rem 0.5rem; font-size: 1.2rem; line-height: 1; border: none; cursor: pointer;">&times;</button>
        </div>

        <form action="<?= base_url('siswa?page=konfirmasi') ?>" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="id_sesi" id="modal_id_sesi" value="">

            <div style="background: #f8fafc; border: 1px solid var(--gray-200); border-radius: 8px; padding: 0.85rem 1rem; margin-bottom: 1.25rem; font-size: 0.85rem;">
                <div style="display: grid; grid-template-columns: 110px 1fr; gap: 0.4rem; align-items: center;">
                    <div style="font-weight: 600; color: var(--gray-600);">Nama Ujian</div>
                    <div>: <strong id="modal_nama_ujian" style="color: var(--gray-900);">-</strong></div>

                    <div style="font-weight: 600; color: var(--gray-600);">Mata Pelajaran</div>
                    <div>: <span id="modal_mapel" class="badge badge-aktif">-</span></div>

                    <div style="font-weight: 600; color: var(--gray-600);">Durasi & Soal</div>
                    <div>: <strong id="modal_durasi">-</strong> (<span id="modal_soal">-</span>)</div>

                    <div style="font-weight: 600; color: var(--gray-600);">Nama Siswa</div>
                    <div>: <strong><?= sanitize($currentUser['nama_lengkap']) ?></strong></div>

                    <div style="font-weight: 600; color: var(--gray-600);">NIS / Akun</div>
                    <div>: <span style="font-family: monospace; font-weight: 700; color: #1e40af;"><?= sanitize($currentUser['nis'] ?? '-') ?></span> (<?= sanitize($currentUser['username']) ?>)</div>
                </div>
            </div>

            <div class="form-group mb-4">
                <label for="modal_token" class="font-bold block mb-1" style="font-size: 0.9rem; color: var(--gray-900);">
                    Masukkan 6 Digit Token Ujian: <span class="text-danger">*</span>
                </label>
                <input type="text" name="token_ujian" id="modal_token" class="form-control" maxlength="10" autocomplete="off" style="text-transform: uppercase; font-family: monospace; font-size: 1.25rem; font-weight: 800; letter-spacing: 3px; text-align: center; height: 48px; border: 2px solid var(--primary-light); background: #f0f7ff;" required>
            </div>

            <div class="flex gap-2" style="justify-content: flex-end;">
                <button type="button" class="btn btn-outline" onclick="closeModal('modal-konfirmasi-tes')">Batal</button>
                <button type="submit" class="btn btn-primary btn-lg" style="font-weight: 800; min-height: 42px; min-width: 170px;">
                    MULAI TES SEKARANG
                </button>
            </div>
        </form>
    </div>
</div>

<script src="<?= base_url('assets/js/app.js') ?>"></script>
<script src="<?= base_url('assets/js/server-alert.js') ?>"></script>
<script>
function bukaKonfirmasi(data) {
    document.getElementById('modal_id_sesi').value = data.id_sesi;
    document.getElementById('modal_nama_ujian').textContent = data.nama_ujian;
    document.getElementById('modal_mapel').textContent = data.nama_mapel;
    document.getElementById('modal_durasi').textContent = data.durasi_menit + ' Menit';
    document.getElementById('modal_soal').textContent = data.total_soal + ' Butir Soal';
    
    const tokenInput = document.getElementById('modal_token');
    tokenInput.value = '';
    
    openModal('modal-konfirmasi-tes');
    setTimeout(() => tokenInput.focus(), 150);
}
</script>
</body>
</html>
