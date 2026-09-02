<?php
/**
 * Guru / Penguji Dashboard
 */

require_once __DIR__ . '/../middleware/auth.php';

$currentUser = auth_check(['guru']);
$db = get_db();

// Hitung Statistik Guru Ini
$idGuru = $currentUser['id_user'];

// Tangani POST Token Refresh / Custom Edit dari Dashboard
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash_set('danger', 'Validasi token keamanan gagal.');
        redirect(base_url('guru/dashboard.php'));
    }
    $action = $_POST['action'] ?? '';
    
    if ($action === 'refresh_token') {
        $idSesi = (int)($_POST['id_sesi'] ?? 0);
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $newToken = '';
        for ($i = 0; $i < 6; $i++) $newToken .= $chars[random_int(0, strlen($chars) - 1)];
        $upd = $db->prepare("UPDATE sesi_ujian SET token_ujian = :t WHERE id_sesi = :id AND id_guru = :g");
        $upd->execute([':t' => $newToken, ':id' => $idSesi, ':g' => $idGuru]);
        flash_set('info', "Token ujian berhasil diperbarui menjadi: {$newToken}");
        redirect(base_url('guru/dashboard.php'));
    }

    if ($action === 'edit_token') {
        $idSesi = (int)($_POST['id_sesi'] ?? 0);
        $customToken = strtoupper(trim($_POST['token_ujian'] ?? ''));
        $token = preg_replace('/[^A-Z0-9]/', '', $customToken);
        if ($token === '') {
            $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
            for ($i = 0; $i < 6; $i++) $token .= $chars[random_int(0, strlen($chars) - 1)];
        }

        if (strlen($token) >= 3 && strlen($token) <= 15) {
            $upd = $db->prepare("UPDATE sesi_ujian SET token_ujian = :t WHERE id_sesi = :id AND id_guru = :g");
            $upd->execute([':t' => $token, ':id' => $idSesi, ':g' => $idGuru]);
            flash_set('info', "Token ujian berhasil diubah menjadi: {$token}");
        } else {
            flash_set('danger', "Token harus berupa 3-15 karakter huruf/angka.");
        }
        redirect(base_url('guru/dashboard.php'));
    }
}

$stmtSoal = $db->prepare("SELECT COUNT(*) FROM paket_soal WHERE id_guru = :g");
$stmtSoal->execute([':g' => $idGuru]);
$totalPaket = (int)$stmtSoal->fetchColumn();

$stmtSesi = $db->prepare("SELECT COUNT(*) FROM sesi_ujian WHERE id_guru = :g AND status = 'aktif'");
$stmtSesi->execute([':g' => $idGuru]);
$sesiAktif = $stmtSesi->fetchColumn();

$stmtPeserta = $db->prepare("
    SELECT COUNT(us.id_ujian_siswa) 
    FROM ujian_siswa us
    JOIN sesi_ujian su ON us.id_sesi = su.id_sesi
    WHERE su.id_guru = :g AND us.status = 'selesai'
");
$stmtPeserta->execute([':g' => $idGuru]);
$totalSelesai = $stmtPeserta->fetchColumn();

// Ambil Daftar Sesi Ujian Terbaru Milik Guru (beserta sisa waktu sesi global live)
$stmtSesiList = $db->prepare("
    SELECT su.*, m.nama_mapel, k.nama_kelas, p.nama_paket,
           COUNT(us.id_ujian_siswa) as total_peserta,
           COUNT(CASE WHEN us.status = 'sedang' THEN 1 END) as peserta_sedang,
           COUNT(CASE WHEN us.status = 'selesai' THEN 1 END) as peserta_selesai,
           GREATEST(0, FLOOR(EXTRACT(EPOCH FROM (su.created_at + (su.durasi_menit * INTERVAL '1 minute') - CURRENT_TIMESTAMP))))::int as sisa_detik_sesi
    FROM sesi_ujian su
    LEFT JOIN paket_soal p ON su.id_paket = p.id_paket
    JOIN mapel m ON su.id_mapel = m.id_mapel
    JOIN kelas k ON su.id_kelas = k.id_kelas
    LEFT JOIN ujian_siswa us ON su.id_sesi = us.id_sesi
    WHERE su.id_guru = :g
    GROUP BY su.id_sesi, m.nama_mapel, k.nama_kelas, p.nama_paket
    ORDER BY su.created_at DESC
    LIMIT 5
");
$stmtSesiList->execute([':g' => $idGuru]);
$recentSessions = $stmtSesiList->fetchAll();

$flash = flash_get();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Dashboard Guru - CBT System</title>
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
            <li><a href="<?= base_url('guru/dashboard.php') ?>" class="active">Dashboard</a></li>
            <li><a href="<?= base_url('guru/bank_soal.php') ?>">Bank Soal</a></li>
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
            <h1 class="card-title" style="display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap;">
                <span>Selamat Datang, <?= sanitize($currentUser['nama_lengkap']) ?></span>
                <span style="font-size: 0.8rem; background: #e2e8f0; padding: 0.25rem 0.6rem; border-radius: 4px; font-weight: 700; color: #1e293b; display: inline-flex; align-items: center; gap: 0.35rem;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                    <span>Waktu Server:</span>
                    <span id="server_clock" style="font-family: monospace;"><?= date('H:i:s') ?></span> WIB
                </span>
            </h1>
        </div>
        <div class="card-header-actions">
            <a href="<?= base_url('guru/sesi_ujian.php') ?>" class="btn btn-primary">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                <span>Rilis Sesi Ujian</span>
            </a>
            <a href="<?= base_url('guru/bank_soal.php') ?>" class="btn btn-outline">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                <span>Bank Soal</span>
            </a>
        </div>
    </div>

    <!-- Quick Stats -->
    <div class="stats-grid stats-grid-3">
        <div class="stat-card">
            <div class="stat-icon" style="background:#1e40af;">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>
            </div>
            <div>
                <div class="stat-val"><?= $totalPaket ?></div>
                <div class="stat-label">Paket Soal Anda</div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon" style="background:#059669;">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
            </div>
            <div>
                <div class="stat-val"><?= $sesiAktif ?></div>
                <div class="stat-label">Sesi Ujian Aktif</div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon" style="background:#4f46e5;">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
            </div>
            <div>
                <div class="stat-val"><?= $totalSelesai ?></div>
                <div class="stat-label">Ujian Selesai Dinilai</div>
            </div>
        </div>
    </div>

    <!-- Sesi Ujian Terbaru -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Sesi Ujian Anda</h2>
            <a href="<?= base_url('guru/sesi_ujian.php') ?>" class="btn btn-sm btn-outline">Lihat Semua Sesi</a>
        </div>

        <?php if (empty($recentSessions)): ?>
            <p class="text-center text-muted" style="padding: 2rem;">Belum ada sesi ujian yang dibuat. Silakan klik tombol "Rilis Sesi Ujian" untuk memulai.</p>
        <?php else: ?>
            <div class="table-responsive table-mobile-cards">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Nama Ujian</th>
                            <th>Mata Pelajaran</th>
                            <th>Kelas</th>
                            <th>Token</th>
                            <th>Sisa Waktu</th>
                            <th>Status</th>
                            <th>Peserta</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentSessions as $s): ?>
                            <tr>
                                <td data-label="Nama Ujian"><strong><?= sanitize($s['nama_paket'] ?: $s['nama_ujian']) ?></strong></td>
                                <td data-label="Mapel"><?= sanitize($s['nama_mapel']) ?></td>
                                <td data-label="Kelas"><?= sanitize($s['nama_kelas']) ?></td>
                                <td data-label="Token" style="text-align: center;">
                                    <div style="display: inline-flex; align-items: center; gap: 0.35rem; background: #e0e7ff; padding: 0.35rem 0.65rem; border-radius: 6px;">
                                        <span style="font-family: monospace; font-size: 1.15rem; font-weight: 800; color: #1e40af; letter-spacing: 1.5px;">
                                            <?= sanitize($s['token_ujian']) ?>
                                        </span>
                                        <!-- Tombol Edit Token Custom -->
                                        <button type="button" class="btn btn-sm btn-outline" title="Ubah Token / Custom" style="padding: 0.15rem 0.45rem; font-size: 0.78rem;" onclick="openModalEditToken(<?= $s['id_sesi'] ?>, '<?= sanitize($s['token_ujian']) ?>', '<?= sanitize(addslashes($s['nama_paket'] ?: $s['nama_ujian'])) ?>')">
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                        </button>
                                        <!-- Tombol Acak Ulang Cepat -->
                                        <form action="<?= base_url('guru/dashboard.php') ?>" method="POST" style="display:inline;" data-confirm="Generate ulang token ujian ini secara acak?" data-confirm-title="Perbarui Token Ujian" data-confirm-type="warning" data-confirm-btn="Generate Acak">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="refresh_token">
                                            <input type="hidden" name="id_sesi" value="<?= $s['id_sesi'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline" title="Generate Acak Baru" style="padding: 0.15rem 0.45rem; font-size: 0.78rem;">↻</button>
                                        </form>
                                    </div>
                                </td>
                                <td data-label="Sisa Waktu">
                                    <?php if ($s['status'] === 'aktif'): ?>
                                        <?php if ($s['sisa_detik_sesi'] > 0): ?>
                                            <span class="badge" style="background: #eff6ff; color: #1d4ed8; font-family: monospace; font-size: 0.92rem; font-weight: 700; padding: 0.3rem 0.6rem; border: 1px solid #bfdbfe; letter-spacing: 0.5px; display: inline-flex; align-items: center; gap: 0.35rem;">
                                                <span class="countdown-timer" data-seconds="<?= (int)$s['sisa_detik_sesi'] ?>">--:--:--</span>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge badge-offline">Waktu Habis</span>
                                        <?php endif; ?>
                                    <?php elseif ($s['status'] === 'selesai'): ?>
                                        <span class="badge badge-selesai">Selesai</span>
                                    <?php else: ?>
                                        <span class="text-sm text-muted font-bold"><?= $s['durasi_menit'] ?> Menit (Jeda)</span>
                                    <?php endif; ?>
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
                                <td data-label="Peserta"><?= $s['total_peserta'] ?> Siswa</td>
                                <td data-label="Aksi">
                                    <a href="<?= base_url('guru/rekap_nilai.php?id_sesi=' . $s['id_sesi']) ?>" class="btn btn-sm btn-outline" style="width: 100%; text-align: center;">Lihat Nilai</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</main>

<!-- Modal Ubah / Custom Token -->
<div id="modal-edit-token" class="modal-overlay">
    <div class="modal-box" style="max-width: 440px;">
        <h2 class="card-title mb-2">Ubah Token Ujian</h2>
        <p class="text-sm text-muted mb-3" id="edit_token_subtitle">Ubah token untuk sesi ujian</p>

        <form action="<?= base_url('guru/dashboard.php') ?>" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit_token">
            <input type="hidden" name="id_sesi" id="edit_token_id_sesi" value="">

            <div class="form-group">
                <label for="edit_input_token">Token Ujian Baru <span class="text-danger">*</span></label>
                <div style="display: flex; gap: 0.5rem;">
                    <input type="text" name="token_ujian" id="edit_input_token" class="form-control" placeholder="Contoh: PAS2024..." maxlength="15" required style="text-transform: uppercase; font-family: monospace; font-size: 1.1rem; font-weight: 700; letter-spacing: 1.5px;">
                    <button type="button" class="btn btn-outline" onclick="generateTokenInput('edit_input_token')" title="Buat Token Acak Otomatis" style="white-space: nowrap;">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 3 21 3 21 8"></polyline><line x1="4" y1="20" x2="21" y2="3"></line><polyline points="21 16 21 21 16 21"></polyline><line x1="15" y1="15" x2="21" y2="21"></line><line x1="4" y1="4" x2="9" y2="9"></line></svg>
                        <span>Acak</span>
                    </button>
                </div>
                <small class="text-muted" style="display: block; margin-top: 0.35rem;">Bisa berupa huruf & angka bebas (3-15 karakter).</small>
            </div>

            <div class="flex gap-2 mt-4" style="justify-content: flex-end;">
                <button type="button" class="btn btn-outline" onclick="closeModal('modal-edit-token')">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Token</button>
            </div>
        </form>
    </div>
</div>

<script src="<?= base_url('assets/js/app.js') ?>"></script>
<script>
function generateTokenInput(targetId) {
    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    let token = '';
    for (let i = 0; i < 6; i++) {
        token += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    const input = document.getElementById(targetId);
    if (input) {
        input.value = token;
        input.focus();
    }
}

function openModalEditToken(idSesi, currentToken, namaUjian) {
    document.getElementById('edit_token_id_sesi').value = idSesi;
    document.getElementById('edit_input_token').value = currentToken;
    document.getElementById('edit_token_subtitle').textContent = 'Sesi: ' + namaUjian;
    openModal('modal-edit-token');
    setTimeout(() => {
        const inp = document.getElementById('edit_input_token');
        if (inp) inp.select();
    }, 150);
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

    const clockEl = document.getElementById('server_clock');
    if (clockEl) {
        const now = new Date();
        const pad = (n) => String(n).padStart(2, '0');
        clockEl.textContent = `${pad(now.getHours())}:${pad(now.getMinutes())}:${pad(now.getSeconds())}`;
    }
}
setInterval(updateDashboardCountdowns, 1000);
updateDashboardCountdowns();
</script>
</body>
</html>
