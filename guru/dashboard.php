<?php
/**
 * Guru / Penguji Dashboard
 */

require_once __DIR__ . '/../middleware/auth.php';

$currentUser = auth_check(['guru']);
$db = get_db();

// Hitung Statistik Guru Ini
$idGuru = $currentUser['id_user'];

$stmtSoal = $db->prepare("SELECT COUNT(*) FROM bank_soal WHERE id_guru = :g");
$stmtSoal->execute([':g' => $idGuru]);
$totalSoal = $stmtSoal->fetchColumn();

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
    SELECT su.*, m.nama_mapel, k.nama_kelas,
           COUNT(us.id_ujian_siswa) as total_peserta,
           COUNT(CASE WHEN us.status = 'sedang' THEN 1 END) as peserta_sedang,
           COUNT(CASE WHEN us.status = 'selesai' THEN 1 END) as peserta_selesai,
           GREATEST(0, FLOOR(EXTRACT(EPOCH FROM (su.created_at + (su.durasi_menit * INTERVAL '1 minute') - CURRENT_TIMESTAMP))))::int as sisa_detik_sesi
    FROM sesi_ujian su
    JOIN mapel m ON su.id_mapel = m.id_mapel
    JOIN kelas k ON su.id_kelas = k.id_kelas
    LEFT JOIN ujian_siswa us ON su.id_sesi = us.id_sesi
    WHERE su.id_guru = :g
    GROUP BY su.id_sesi, m.nama_mapel, k.nama_kelas
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
            <h1 class="card-title">Selamat Datang, <?= sanitize($currentUser['nama_lengkap']) ?></h1>
            <p class="text-sm text-muted">
                Kelola butir soal dan terbitkan token ujian secara mandiri untuk siswa.
                <span style="display: inline-block; margin-left: 0.5rem; background: #e2e8f0; padding: 0.15rem 0.5rem; border-radius: 4px; font-weight: 700; color: #1e293b;">
                    🕒 Jam Server: <span id="server_clock" style="font-family: monospace;"><?= date('H:i:s') ?></span> WIB
                </span>
            </p>
        </div>
        <div class="card-header-actions">
            <a href="<?= base_url('guru/sesi_ujian.php') ?>" class="btn btn-primary">+ Rilis Sesi Ujian</a>
            <a href="<?= base_url('guru/bank_soal.php') ?>" class="btn btn-outline">Buat / Edit Soal</a>
        </div>
    </div>

    <!-- Quick Stats -->
    <div class="stats-grid stats-grid-3">
        <div class="stat-card">
            <div class="stat-icon" style="background:#2563eb;">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
            </div>
            <div>
                <div class="stat-val"><?= $totalSoal ?></div>
                <div class="stat-label">Soal Milik Anda</div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon" style="background:#059669;">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
            </div>
            <div>
                <div class="stat-val"><?= $sesiAktif ?></div>
                <div class="stat-label">Sesi Ujian Aktif</div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon" style="background:#7c3aed;">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
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
                                <td data-label="Nama Ujian"><strong><?= sanitize($s['nama_ujian']) ?></strong></td>
                                <td data-label="Mapel"><?= sanitize($s['nama_mapel']) ?></td>
                                <td data-label="Kelas"><?= sanitize($s['nama_kelas']) ?></td>
                                <td data-label="Token"><span style="font-family:monospace; font-size:1.1rem; font-weight:800; color:#1e40af; letter-spacing:1px;"><?= sanitize($s['token_ujian']) ?></span></td>
                                <td data-label="Sisa Waktu">
                                    <?php if ($s['status'] === 'aktif'): ?>
                                        <?php if ($s['sisa_detik_sesi'] > 0): ?>
                                            <span class="badge" style="background: #eff6ff; color: #1d4ed8; font-family: monospace; font-size: 0.95rem; font-weight: 800; padding: 0.35rem 0.65rem; border: 1px solid #bfdbfe; letter-spacing: 0.5px; display: inline-flex; align-items: center; gap: 0.35rem;">
                                                ⏱️ <span class="countdown-timer" data-seconds="<?= (int)$s['sisa_detik_sesi'] ?>">--:--:--</span>
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

<script src="<?= base_url('assets/js/app.js') ?>"></script>
<script>
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
