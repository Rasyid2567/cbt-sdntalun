<?php
/**
 * Page: Operator Dashboard Home
 */

require_once __DIR__ . '/../../middleware/auth.php';

$currentUser = auth_check(['operator']);
$db = get_db();

// 1. Statistik Master Data
$countSiswa = $db->query("SELECT COUNT(*) FROM users WHERE role = 'siswa'")->fetchColumn();
$countGuru  = $db->query("SELECT COUNT(*) FROM users WHERE role = 'guru'")->fetchColumn();
$countOnline = $db->query("SELECT COUNT(*) FROM users WHERE status_login = 'online'")->fetchColumn();
$countSesiAktif = $db->query("SELECT COUNT(*) FROM sesi_ujian WHERE status = 'aktif'")->fetchColumn();

// 2. Daftar Pengguna yang Sedang Online
$stmtOnline = $db->query("
    SELECT u.id_user, u.nis, u.username, u.nama_lengkap, u.role, u.status_login, k.nama_kelas
    FROM users u
    LEFT JOIN kelas k ON u.id_kelas = k.id_kelas
    WHERE u.status_login = 'online'
    ORDER BY u.role, u.nama_lengkap ASC
");
$onlineUsers = $stmtOnline->fetchAll();

$page = 'home';
$pageTitle = 'Dashboard Operator';
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
            <h1 class="card-title">Panel Kontrol Administrator</h1>
        </div>
        <div>
            <span class="badge badge-online">Server: Terhubung</span>
        </div>
    </div>

    <!-- Metric Cards -->
    <div class="stats-grid stats-grid-4">
        <div class="stat-card">
            <div class="stat-icon" style="background:#2563eb;">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
            </div>
            <div>
                <div class="stat-val"><?= $countSiswa ?></div>
                <div class="stat-label">Total Siswa</div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon" style="background:#059669;">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
            </div>
            <div>
                <div class="stat-val"><?= $countGuru ?></div>
                <div class="stat-label">Total Guru</div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon" style="background:#dc2626;">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
            </div>
            <div>
                <div class="stat-val"><?= $countSesiAktif ?></div>
                <div class="stat-label">Sesi Ujian Aktif</div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon" style="background:#0284c7;">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18.36 6.64a9 9 0 1 1-12.73 0"></path><line x1="12" y1="2" x2="12" y2="12"></line></svg>
            </div>
            <div>
                <div class="stat-val"><?= $countOnline ?></div>
                <div class="stat-label">User Online</div>
            </div>
        </div>
    </div>

    <!-- Active User Sessions -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Pengguna Sedang Aktif (Real-time Online)</h2>
            <a href="<?= base_url('operator?page=reset_login') ?>" class="btn btn-sm btn-outline">Buka Reset Sesi</a>
        </div>

        <?php if (empty($onlineUsers)): ?>
            <p class="text-muted text-center" style="padding: 2rem 0;">Tidak ada pengguna yang sedang online saat ini.</p>
        <?php else: ?>
            <div class="table-responsive table-mobile-cards">
                <table class="table">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>NIS</th>
                            <th>Username</th>
                            <th>Nama Lengkap</th>
                            <th>Role</th>
                            <th>Kelas</th>
                            <th>Status</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($onlineUsers as $idx => $ou): ?>
                            <tr>
                                <td data-label="No"><?= $idx + 1 ?></td>
                                <td data-label="NIS"><span class="badge" style="background:#e0f2fe; color:#0369a1; font-family:monospace;"><?= sanitize($ou['nis'] ?? '-') ?></span></td>
                                <td data-label="Username"><strong><?= sanitize($ou['username']) ?></strong></td>
                                <td data-label="Nama Lengkap"><?= sanitize($ou['nama_lengkap']) ?></td>
                                <td data-label="Role"><span class="badge badge-role"><?= sanitize($ou['role']) ?></span></td>
                                <td data-label="Kelas"><?= sanitize($ou['nama_kelas'] ?? '-') ?></td>
                                <td data-label="Status"><span class="badge badge-online">ONLINE</span></td>
                                <td data-label="Aksi">
                                    <?php if ($ou['id_user'] != $currentUser['id_user']): ?>
                                        <form action="<?= base_url('operator?page=reset_login') ?>" method="POST" data-confirm="Lepaskan sesi login pengguna ini?" data-confirm-title="Reset Login Pengguna" data-confirm-type="warning" data-confirm-btn="Reset Login">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="reset_single">
                                            <input type="hidden" name="id_user" value="<?= $ou['id_user'] ?>">
                                            <button type="submit" class="btn btn-sm btn-warning">Reset Login</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="text-xs text-muted">Akun Anda</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</main>

<?php
include __DIR__ . '/../layouts/footer.php';
