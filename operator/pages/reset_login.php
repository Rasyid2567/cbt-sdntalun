<?php
/**
 * Page: Monitoring Sesi & Reset Login Siswa / Pengguna
 */

require_once __DIR__ . '/../../middleware/auth.php';

$currentUser = auth_check(['operator']);
$db = get_db();

// Tangani Aksi POST Reset Login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash_set('danger', 'Validasi token keamanan gagal.');
        redirect(base_url('operator?page=reset_login'));
    }

    $action = $_POST['action'] ?? '';

    // RESET SATU USER
    if ($action === 'reset_single') {
        $id_user = (int)($_POST['id_user'] ?? 0);
        if ($id_user > 0) {
            $upd = $db->prepare("UPDATE users SET status_login = 'offline' WHERE id_user = :id");
            $upd->execute([':id' => $id_user]);
            flash_set('success', 'Sesi pengguna berhasil di-reset menjadi offline.');
        }
        redirect(base_url('operator?page=reset_login'));
    }

    // RESET SEMUA SESI SISWA SEKALIGUS
    if ($action === 'reset_all_siswa') {
        $upd = $db->query("UPDATE users SET status_login = 'offline' WHERE role = 'siswa'");
        flash_set('success', 'Seluruh sesi akun siswa berhasil di-reset.');
        redirect(base_url('operator?page=reset_login'));
    }
}

// Filter dan Query Pengguna Online
$search      = trim($_GET['search'] ?? '');
$filterRole  = $_GET['role'] ?? 'all';
$filterKelas = !empty($_GET['id_kelas']) ? (int)$_GET['id_kelas'] : null;

$sql = "
    SELECT u.id_user, u.nis, u.username, u.nama_lengkap, u.role, u.status_login, k.nama_kelas
    FROM users u
    LEFT JOIN kelas k ON u.id_kelas = k.id_kelas
    WHERE 1=1
";
$params = [];

if ($search !== '') {
    $sql .= " AND (u.username ILIKE :s OR u.nis ILIKE :s OR u.nama_lengkap ILIKE :s)";
    $params[':s'] = "%{$search}%";
}

if ($filterRole !== 'all') {
    $sql .= " AND u.role = :r::user_role";
    $params[':r'] = $filterRole;
}

if ($filterKelas) {
    $sql .= " AND u.id_kelas = :k";
    $params[':k'] = $filterKelas;
}

$sql .= " ORDER BY (u.status_login = 'online') DESC, u.role, u.nama_lengkap ASC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

$kelasList = $db->query("SELECT id_kelas, nama_kelas FROM kelas ORDER BY nama_kelas ASC")->fetchAll();
$flash = flash_get();

$page = 'reset_login';
$pageTitle = 'Monitoring & Reset Sesi';

include __DIR__ . '/../layouts/header.php';
?>

<main class="container">
    <?php if ($flash): ?>
        <div class="alert alert-<?= sanitize($flash['type']) ?>">
            <?= sanitize($flash['message']) ?>
        </div>
    <?php endif; ?>

    <div class="card-header">
        <div>
            <h1 class="card-title">Monitoring & Reset Sesi Login</h1>
        </div>
        <div class="card-header-actions">
            <form action="<?= base_url('operator?page=reset_login') ?>" method="POST" data-confirm="Apakah Anda yakin ingin me-reset SEMUA sesi siswa yang sedang aktif menjadi offline?" data-confirm-title="Reset Semua Sesi Siswa" data-confirm-type="danger" data-confirm-btn="Ya, Reset Semua" style="width: 100%;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reset_all_siswa">
                <button type="submit" class="btn btn-danger" style="width: 100%;">Reset Semua Sesi Siswa</button>
            </form>
        </div>
    </div>

    <!-- Filter & Pencarian -->
    <div class="card" style="padding: 1rem 1.25rem;">
        <form method="GET" action="<?= base_url('operator') ?>" class="filter-form-responsive">
            <input type="hidden" name="page" value="reset_login">
            <input type="text" name="search" class="form-control" placeholder="Cari NIS, username, atau nama..." value="<?= sanitize($search) ?>">
            <div class="filter-row">
                <select name="role" class="form-control">
                    <option value="all" <?= ($filterRole === 'all') ? 'selected' : '' ?>>Semua Role</option>
                    <option value="siswa" <?= ($filterRole === 'siswa') ? 'selected' : '' ?>>Siswa</option>
                    <option value="guru" <?= ($filterRole === 'guru') ? 'selected' : '' ?>>Guru</option>
                </select>
                <select name="id_kelas" class="form-control">
                    <option value="">Semua Kelas</option>
                    <?php foreach ($kelasList as $k): ?>
                        <option value="<?= $k['id_kelas'] ?>" <?= ($filterKelas == $k['id_kelas']) ? 'selected' : '' ?>>
                            <?= sanitize($k['nama_kelas']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-primary">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                    <span>Cari</span>
                </button>
            </div>
        </form>
    </div>

    <!-- Tabel Monitoring Sesi (Auto-Card on Mobile) -->
    <div class="card">
        <div class="table-responsive table-mobile-cards">
            <table class="table">
                <thead>
                    <tr>
                        <th style="width: 50px;">No</th>
                        <th>NIS</th>
                        <th>Username</th>
                        <th>Nama Lengkap</th>
                        <th>Role</th>
                        <th>Kelas</th>
                        <th>Status Sesi</th>
                        <th style="width: 160px; text-align: center;">Tindakan Proktor</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)): ?>
                        <tr><td colspan="8" class="text-center text-muted" style="padding: 2rem;">Tidak ada data yang sesuai.</td></tr>
                    <?php else: ?>
                        <?php foreach ($users as $idx => $u): ?>
                            <tr>
                                <td data-label="No"><?= $idx + 1 ?></td>
                                <td data-label="NIS"><span class="badge" style="background:#e0f2fe; color:#0369a1; font-family:monospace;"><?= sanitize($u['nis'] ?? '-') ?></span></td>
                                <td data-label="Username"><strong><?= sanitize($u['username']) ?></strong></td>
                                <td data-label="Nama Lengkap"><?= sanitize($u['nama_lengkap']) ?></td>
                                <td data-label="Role"><span class="badge badge-role"><?= sanitize($u['role']) ?></span></td>
                                <td data-label="Kelas"><?= sanitize($u['nama_kelas'] ?? '-') ?></td>
                                <td data-label="Status Sesi">
                                    <?php if ($u['status_login'] === 'online'): ?>
                                        <span class="badge badge-online">TERKUNCI (ONLINE)</span>
                                    <?php else: ?>
                                        <span class="badge badge-offline">OFFLINE</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Aksi">
                                    <?php if ($u['id_user'] != $currentUser['id_user']): ?>
                                        <?php if ($u['status_login'] === 'online'): ?>
                                            <form action="<?= base_url('operator?page=reset_login') ?>" method="POST">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="reset_single">
                                                <input type="hidden" name="id_user" value="<?= $u['id_user'] ?>">
                                                <button type="submit" class="btn btn-sm btn-warning">Buka Kunci Sesi</button>
                                            </form>
                                        <?php else: ?>
                                            <span class="text-xs text-muted">Sesi Terbuka</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-xs text-muted">Sesi Anda</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<?php
include __DIR__ . '/../layouts/footer.php';
