<?php
/**
 * Modul Konfigurasi Sesi Ujian & Generate Token (Guru Penguji)
 */

require_once __DIR__ . '/../middleware/auth.php';

$currentUser = auth_check(['guru']);
$db = get_db();
$idGuru = $currentUser['id_user'];

/**
 * Fungsi pembantu generate token 6 karakter alfanumerik unik
 */
function generate_token_cbt(): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $token = '';
    $max = strlen($chars) - 1;
    for ($i = 0; $i < 6; $i++) {
        $token .= $chars[random_int(0, $max)];
    }
    return $token;
}

// Tangani Request POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash_set('danger', 'Validasi token keamanan gagal.');
        redirect(base_url('guru/sesi_ujian.php'));
    }

    $action = $_POST['action'] ?? '';

    // 1. BUAT SESI BARU
    if ($action === 'tambah_sesi') {
        $judulSoal   = trim($_POST['judul_soal'] ?? '');
        $idMapel     = (int)($_POST['id_mapel'] ?? 0);
        $namaUjian   = $judulSoal; // Nama sesi ujian selalu identik dengan judul soal
        $idKelas     = !empty($currentUser['id_kelas']) ? (int)$currentUser['id_kelas'] : (int)($_POST['id_kelas'] ?? 0);
        $durasiMenit = (int)($_POST['durasi_menit'] ?? 60);
        $acakSoal    = !empty($_POST['acak_soal']) ? 'true' : 'false';
        $acakOpsi    = 'false';
        $token       = generate_token_cbt();

        // Otomatis ambil id_mapel dari bank_soal jika belum ada
        if ($idMapel <= 0 && $judulSoal !== '') {
            $stmtM = $db->prepare("SELECT id_mapel FROM bank_soal WHERE id_guru = :g AND judul_soal = :j LIMIT 1");
            $stmtM->execute([':g' => $idGuru, ':j' => $judulSoal]);
            $idMapel = (int)$stmtM->fetchColumn();
        }

        // Fallback kelas jika akun guru belum diset kelasnya
        if ($idKelas <= 0) {
            $idKelas = 6;
        }

        if ($judulSoal === '' || $idMapel <= 0 || $durasiMenit <= 0) {
            flash_set('danger', 'Silakan pilih judul soal yang ingin diujikan.');
        } else {
            $stmtIns = $db->prepare("
                INSERT INTO sesi_ujian (id_guru, id_mapel, id_kelas, judul_soal, nama_ujian, token_ujian, durasi_menit, acak_soal, acak_opsi, status)
                VALUES (:g, :m, :k, :j, :n, :t, :d, :as::boolean, :ao::boolean, 'aktif')
            ");
            $stmtIns->execute([
                ':g'  => $idGuru,
                ':m'  => $idMapel,
                ':k'  => $idKelas,
                ':j'  => $judulSoal,
                ':n'  => $namaUjian,
                ':t'  => $token,
                ':d'  => $durasiMenit,
                ':as' => $acakSoal,
                ':ao' => $acakOpsi
            ]);
            flash_set('success', "Sesi ujian '{$namaUjian}' berhasil dibuat dan aktif dengan Token: {$token}");
        }
        redirect(base_url('guru/sesi_ujian.php'));
    }

    // 2. REFRESH / GENERATE ULANG TOKEN
    if ($action === 'refresh_token') {
        $idSesi = (int)($_POST['id_sesi'] ?? 0);
        $newToken = generate_token_cbt();

        $upd = $db->prepare("UPDATE sesi_ujian SET token_ujian = :t WHERE id_sesi = :id AND id_guru = :g");
        $upd->execute([':t' => $newToken, ':id' => $idSesi, ':g' => $idGuru]);
        flash_set('info', "Token ujian berhasil diperbarui menjadi: {$newToken}");
        redirect(base_url('guru/sesi_ujian.php'));
    }

    // 3. UBAH STATUS SESI (aktif, nonaktif, selesai)
    if ($action === 'update_status') {
        $idSesi    = (int)($_POST['id_sesi'] ?? 0);
        $newStatus = $_POST['status'] ?? '';

        if (in_array($newStatus, ['aktif', 'nonaktif', 'selesai'], true)) {
            $upd = $db->prepare("UPDATE sesi_ujian SET status = :s::session_status WHERE id_sesi = :id AND id_guru = :g");
            $upd->execute([':s' => $newStatus, ':id' => $idSesi, ':g' => $idGuru]);
            flash_set('success', "Status sesi ujian berhasil diubah menjadi: {$newStatus}");
        }
        redirect(base_url('guru/sesi_ujian.php'));
    }

    // 4. HAPUS SESI
    if ($action === 'hapus_sesi') {
        $idSesi = (int)($_POST['id_sesi'] ?? 0);
        $del = $db->prepare("DELETE FROM sesi_ujian WHERE id_sesi = :id AND id_guru = :g");
        $del->execute([':id' => $idSesi, ':g' => $idGuru]);
        flash_set('success', 'Sesi ujian berhasil dihapus.');
        redirect(base_url('guru/sesi_ujian.php'));
    }
}

// Ambil Data Sesi Ujian Guru (beserta sisa waktu live)
$stmt = $db->prepare("
    SELECT s.*, m.nama_mapel, k.nama_kelas,
           COUNT(us.id_ujian_siswa) as total_peserta,
           COUNT(CASE WHEN us.status = 'sedang' THEN 1 END) as peserta_sedang,
           COUNT(CASE WHEN us.status = 'selesai' THEN 1 END) as peserta_selesai,
           MIN(CASE WHEN us.status = 'sedang' THEN 
               GREATEST(0, FLOOR(EXTRACT(EPOCH FROM (COALESCE(us.waktu_mulai, CURRENT_TIMESTAMP) + (s.durasi_menit * INTERVAL '1 minute') - CURRENT_TIMESTAMP))))::int 
           END) as min_sisa_detik
    FROM sesi_ujian s
    JOIN mapel m ON s.id_mapel = m.id_mapel
    JOIN kelas k ON s.id_kelas = k.id_kelas
    LEFT JOIN ujian_siswa us ON s.id_sesi = us.id_sesi
    WHERE s.id_guru = :g
    GROUP BY s.id_sesi, m.nama_mapel, k.nama_kelas
    ORDER BY s.created_at DESC
");
$stmt->execute([':g' => $idGuru]);
$sesiList = $stmt->fetchAll();

// Ambil info kelas guru yang login
$idKelasGuru = !empty($currentUser['id_kelas']) ? (int)$currentUser['id_kelas'] : 6;
$namaKelasGuru = 'Kelas ' . $idKelasGuru;
$stmtGK = $db->prepare("SELECT nama_kelas FROM kelas WHERE id_kelas = :k");
$stmtGK->execute([':k' => $idKelasGuru]);
$namaKelasGuru = $stmtGK->fetchColumn() ?: $namaKelasGuru;

// Ambil paket-paket judul soal milik guru beserta mapelnya
$stmtPaket = $db->prepare("
    SELECT b.judul_soal, b.id_mapel, m.nama_mapel, m.kode_mapel, COUNT(b.id_soal) as total_soal
    FROM bank_soal b
    JOIN mapel m ON b.id_mapel = m.id_mapel
    WHERE b.id_guru = :g
    GROUP BY b.judul_soal, b.id_mapel, m.nama_mapel, m.kode_mapel
    ORDER BY b.judul_soal ASC
");
$stmtPaket->execute([':g' => $idGuru]);
$paketList = $stmtPaket->fetchAll();

$flash = flash_get();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sesi Ujian & Token - CBT Guru</title>
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
            <li><a href="<?= base_url('guru/bank_soal.php') ?>">Bank Soal</a></li>
            <li><a href="<?= base_url('guru/sesi_ujian.php') ?>" class="active">Sesi Ujian & Token</a></li>
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
            <h1 class="card-title">Konfigurasi Sesi Ujian & Token</h1>
            <p class="text-sm text-muted">Jadwalkan ujian, aktifkan acak soal/opsi, dan generate token ujian peserta.</p>
        </div>
        <div class="card-header-actions">
            <button type="button" class="btn btn-primary" onclick="openModal('modal-tambah-sesi')">+ Buat Sesi Ujian</button>
        </div>
    </div>

    <div class="card">
        <?php if (empty($sesiList)): ?>
            <p class="text-center text-muted" style="padding: 2.5rem 0;">Belum ada sesi ujian yang dibuat. Klik tombol "+ Buat Sesi Ujian" untuk memulai.</p>
        <?php else: ?>
            <div class="table-responsive table-mobile-cards">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Nama Ujian</th>
                            <th>Mapel & Kelas</th>
                            <th style="text-align: center;">Token Ujian</th>
                            <th>Sisa Waktu</th>
                            <th>Acak</th>
                            <th>Status</th>
                            <th>Peserta</th>
                            <th style="text-align: center;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sesiList as $s): ?>
                            <tr>
                                <td data-label="Nama Ujian"><strong><?= sanitize($s['nama_ujian']) ?></strong></td>
                                <td data-label="Mapel & Kelas">
                                    <div><?= sanitize($s['nama_mapel']) ?></div>
                                    <span class="text-xs text-muted">Kelas: <?= sanitize($s['nama_kelas']) ?></span>
                                </td>
                                <td data-label="Token" style="text-align: center;">
                                    <div style="display: inline-flex; align-items: center; gap: 0.4rem; background: #e0e7ff; padding: 0.35rem 0.75rem; border-radius: 6px;">
                                        <span style="font-family: monospace; font-size: 1.15rem; font-weight: 800; color: #1e40af; letter-spacing: 1.5px;">
                                            <?= sanitize($s['token_ujian']) ?>
                                        </span>
                                        <form action="<?= base_url('guru/sesi_ujian.php') ?>" method="POST" style="display:inline;" data-confirm="Generate ulang token ujian ini? Token lama tidak akan berlaku lagi." data-confirm-title="Perbarui Token Ujian" data-confirm-type="warning" data-confirm-btn="Generate Token">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="refresh_token">
                                            <input type="hidden" name="id_sesi" value="<?= $s['id_sesi'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline" title="Ganti Token Baru" style="padding: 0.15rem 0.4rem; font-size: 0.75rem;">↻</button>
                                        </form>
                                    </div>
                                </td>
                                <td data-label="Sisa Waktu">
                                    <?php if ($s['peserta_sedang'] > 0 && $s['min_sisa_detik'] !== null): ?>
                                        <span class="badge" style="background: #eff6ff; color: #1d4ed8; font-family: monospace; font-size: 0.95rem; font-weight: 800; padding: 0.35rem 0.65rem; border: 1px solid #bfdbfe; letter-spacing: 0.5px; display: inline-flex; align-items: center; gap: 0.35rem;">
                                            ⏱️ <span class="countdown-timer" data-seconds="<?= (int)$s['min_sisa_detik'] ?>">--:--:--</span>
                                        </span>
                                    <?php elseif ($s['peserta_selesai'] > 0): ?>
                                        <span class="badge badge-online">Selesai</span>
                                    <?php else: ?>
                                        <span class="text-sm text-muted font-bold"><?= $s['durasi_menit'] ?> Menit</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Acak">
                                    <div class="text-xs">
                                        <div>Soal: <?= $s['acak_soal'] ? '<span class="text-success">Ya</span>' : 'Tidak' ?></div>
                                    </div>
                                </td>
                                <td data-label="Status">
                                    <?php if ($s['status'] === 'aktif'): ?>
                                        <span class="badge badge-online">AKTIF</span>
                                    <?php elseif ($s['status'] === 'nonaktif'): ?>
                                        <span class="badge badge-offline">NONAKTIF</span>
                                    <?php else: ?>
                                        <span class="badge badge-selesai">SELESAI</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Peserta">
                                    <a href="<?= base_url('guru/rekap_nilai.php?id_sesi=' . $s['id_sesi']) ?>" class="btn btn-sm btn-outline">
                                        <?= $s['total_peserta'] ?> Siswa
                                    </a>
                                </td>
                                <td data-label="Aksi">
                                    <div class="flex gap-2" style="justify-content: center;">
                                        <!-- Form Ubah Status -->
                                        <form action="<?= base_url('guru/sesi_ujian.php') ?>" method="POST" style="display:inline;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="update_status">
                                            <input type="hidden" name="id_sesi" value="<?= $s['id_sesi'] ?>">
                                            <?php if ($s['status'] === 'aktif'): ?>
                                                <input type="hidden" name="status" value="nonaktif">
                                                <button type="submit" class="btn btn-sm btn-secondary">Nonaktifkan</button>
                                            <?php else: ?>
                                                <input type="hidden" name="status" value="aktif">
                                                <button type="submit" class="btn btn-sm btn-success">Aktifkan</button>
                                            <?php endif; ?>
                                        </form>

                                        <!-- Hapus Sesi -->
                                        <form action="<?= base_url('guru/sesi_ujian.php') ?>" method="POST" style="display:inline;" data-confirm="Hapus sesi ujian ini beserta seluruh riwayat pengerjaan siswa?" data-confirm-title="Hapus Sesi Ujian" data-confirm-type="danger" data-confirm-btn="Ya, Hapus Sesi">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="hapus_sesi">
                                            <input type="hidden" name="id_sesi" value="<?= $s['id_sesi'] ?>">
                                            <button type="submit" class="btn btn-sm btn-danger">Hapus</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</main>

<!-- Modal Tambah Sesi Ujian (Otomatis Mapel & Kelas dari Paket Soal) -->
<div id="modal-tambah-sesi" class="modal-overlay">
    <div class="modal-box" style="max-width: 520px;">
        <h2 class="card-title mb-1">Rilis Sesi Ujian Baru</h2>
        <p class="text-xs text-muted mb-3">Cukup pilih judul soal. Mata pelajaran dan kelas peserta otomatis terdeteksi.</p>

        <form action="<?= base_url('guru/sesi_ujian.php') ?>" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="tambah_sesi">
            <input type="hidden" name="id_kelas" value="<?= $idKelasGuru ?>">
            <input type="hidden" name="id_mapel" id="hidden_id_mapel" value="">
            <input type="hidden" name="judul_soal" id="hidden_judul_soal" value="">

            <!-- 1. PILIH PAKET / JUDUL SOAL -->
            <div class="form-group">
                <label for="select_paket_soal">Pilih Judul Soal <span class="text-danger">*</span></label>
                <?php if (empty($paketList)): ?>
                    <div class="alert alert-warning" style="font-size: 0.85rem; padding: 0.75rem 0.95rem; margin-top: 0.25rem;">
                        Belum ada soal di Bank Soal. <a href="<?= base_url('guru/tambah_soal.php') ?>"><strong>Klik untuk membuat soal dahulu</strong></a>.
                    </div>
                <?php else: ?>
                    <select id="select_paket_soal" class="form-control" required onchange="onPilihPaket(this)">
                        <option value="">-- Pilih Judul Soal yang Diujikan --</option>
                        <?php foreach ($paketList as $p): ?>
                            <option value="<?= htmlspecialchars(json_encode([
                                'judul'      => $p['judul_soal'],
                                'id_mapel'   => (int)$p['id_mapel'],
                                'nama_mapel' => $p['nama_mapel'],
                                'kode_mapel' => $p['kode_mapel'],
                                'total_soal' => (int)$p['total_soal']
                            ])) ?>">
                                <?= sanitize($p['judul_soal']) ?> — Mapel: <?= sanitize($p['nama_mapel']) ?> (<?= $p['total_soal'] ?> Soal)
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
            </div>

            <!-- PREVIEW OTOMATIS MAPEL & KELAS PESERTA -->
            <div id="info_otomatis" style="display: none; background: #f8fafc; border-radius: var(--radius-sm); padding: 0.85rem; margin-bottom: 1rem; border: 1px solid var(--gray-300);">
                <div style="margin-bottom: 0.6rem; padding-bottom: 0.5rem; border-bottom: 1px dashed var(--gray-200);">
                    <span class="text-muted" style="display:block; font-size: 0.72rem; text-transform: uppercase; font-weight: 700;">Nama / Judul Ujian:</span>
                    <strong id="preview_judul" style="color: var(--gray-900); font-size: 1rem;">-</strong>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; font-size: 0.85rem;">
                    <div>
                        <span class="text-muted" style="display:block; font-size: 0.72rem; text-transform: uppercase; font-weight: 700;">Mata Pelajaran:</span>
                        <strong id="preview_mapel" style="color: var(--primary); font-size: 0.95rem;">-</strong>
                    </div>
                    <div>
                        <span class="text-muted" style="display:block; font-size: 0.72rem; text-transform: uppercase; font-weight: 700;">Kelas Peserta:</span>
                        <strong style="color: #059669; font-size: 0.95rem;"><?= sanitize($namaKelasGuru) ?> (Kelas Anda)</strong>
                    </div>
                </div>
            </div>

            <!-- DURASI PENGERJAAN -->
            <div class="form-group">
                <label for="durasi_menit">Durasi Pengerjaan (Menit) <span class="text-danger">*</span></label>
                <input type="number" name="durasi_menit" id="durasi_menit" class="form-control" min="5" max="300" value="60" required>
            </div>

            <!-- FITUR PENGACAKAN -->
            <div class="form-group mt-3" style="background: var(--gray-50); padding: 0.75rem 0.9rem; border-radius: 6px; border: 1px solid var(--gray-200);">
                <label style="cursor: pointer; display: flex; align-items: center; gap: 0.5rem; font-size: 0.88rem; font-weight: 600;">
                    <input type="checkbox" name="acak_soal" value="1" checked> Acak Urutan Butir Soal Antar-Siswa
                </label>
            </div>

            <div class="flex gap-2 mt-4" style="justify-content: flex-end;">
                <button type="button" class="btn btn-outline" onclick="closeModal('modal-tambah-sesi')">Batal</button>
                <button type="submit" class="btn btn-primary" id="btn_submit_sesi" <?= empty($paketList) ? 'disabled' : '' ?>>
                    Rilis Sesi & Buat Token
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openModal(id) { document.getElementById(id).classList.add('active'); }
function closeModal(id) { document.getElementById(id).classList.remove('active'); }

function onPilihPaket(sel) {
    const infoBox = document.getElementById('info_otomatis');
    const hidMapel = document.getElementById('hidden_id_mapel');
    const hidJudul = document.getElementById('hidden_judul_soal');
    const prevJudul = document.getElementById('preview_judul');
    const prevMapel = document.getElementById('preview_mapel');

    if (!sel.value) {
        infoBox.style.display = 'none';
        hidMapel.value = '';
        hidJudul.value = '';
        return;
    }

    try {
        const data = JSON.parse(sel.value);
        hidMapel.value = data.id_mapel;
        hidJudul.value = data.judul;
        prevJudul.textContent = data.judul;
        prevMapel.textContent = data.nama_mapel + ' (' + data.kode_mapel + ')';
        infoBox.style.display = 'block';
    } catch (e) {
        console.error(e);
    }
}

function updateDashboardCountdowns() {
    const timers = document.querySelectorAll('.countdown-timer');
    timers.forEach(el => {
        let sec = parseInt(el.dataset.seconds, 10);
        if (isNaN(sec)) return;
        if (sec > 0) {
            sec--;
            el.dataset.seconds = sec;
        }
        const h = Math.floor(sec / 3600);
        const m = Math.floor((sec % 3600) / 60);
        const s = sec % 60;
        el.textContent = [
            String(h).padStart(2, '0'),
            String(m).padStart(2, '0'),
            String(s).padStart(2, '0')
        ].join(':');

        if (sec <= 300 && sec > 0) {
            const badge = el.closest('.badge');
            if (badge) {
                badge.style.backgroundColor = '#fee2e2';
                badge.style.color = '#b91c1c';
                badge.style.borderColor = '#fca5a5';
            }
        } else if (sec <= 0) {
            el.textContent = '00:00:00';
            const badge = el.closest('.badge');
            if (badge) {
                badge.style.backgroundColor = '#f1f5f9';
                badge.style.color = '#64748b';
                badge.style.borderColor = '#cbd5e1';
            }
        }
    });
}
setInterval(updateDashboardCountdowns, 1000);
updateDashboardCountdowns();
</script>
<script src="<?= base_url('assets/js/app.js') ?>"></script>
</body>
</html>
