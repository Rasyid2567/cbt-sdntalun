<?php
/**
 * Modul Manajemen Data Guru, Mata Pelajaran & Kelas
 */

require_once __DIR__ . '/../middleware/auth.php';

$currentUser = auth_check(['operator']);
$db = get_db();

// Tangani Operasi Form POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash_set('danger', 'Validasi token keamanan gagal.');
        redirect(base_url('operator/guru_crud.php'));
    }

    $action = $_POST['action'] ?? '';

    // 1. GURU: TAMBAH
    if ($action === 'tambah_guru') {
        $username = trim($_POST['username'] ?? '');
        $nama     = trim($_POST['nama_lengkap'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $id_kelas = !empty($_POST['id_kelas']) ? (int)$_POST['id_kelas'] : null;

        if ($username === '' || $nama === '' || $password === '') {
            flash_set('danger', 'Semua kolom guru wajib diisi.');
        } else {
            $cek = $db->prepare("SELECT id_user FROM users WHERE username = :u");
            $cek->execute([':u' => $username]);
            if ($cek->fetch()) {
                flash_set('danger', 'Username sudah terdaftar.');
            } else {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $ins = $db->prepare("INSERT INTO users (username, password, nama_lengkap, role, id_kelas, status_login) VALUES (:u, :p, :n, 'guru', :k, 'offline')");
                $ins->execute([
                    ':u' => $username,
                    ':p' => $hash,
                    ':n' => $nama,
                    ':k' => $id_kelas
                ]);
                flash_set('success', 'Guru berhasil ditambahkan.');
            }
        }
        redirect(base_url('operator/guru_crud.php?tab=guru'));
    }

    // GURU: EDIT
    if ($action === 'edit_guru') {
        $id_user  = (int)($_POST['id_user'] ?? 0);
        $username = trim($_POST['username'] ?? '');
        $nama     = trim($_POST['nama_lengkap'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $id_kelas = !empty($_POST['id_kelas']) ? (int)$_POST['id_kelas'] : null;

        if ($id_user <= 0 || $username === '' || $nama === '') {
            flash_set('danger', 'Data guru tidak valid.');
        } else {
            $cek = $db->prepare("SELECT id_user FROM users WHERE username = :u AND id_user != :id");
            $cek->execute([':u' => $username, ':id' => $id_user]);
            if ($cek->fetch()) {
                flash_set('danger', 'Username sudah digunakan akun lain.');
            } else {
                if ($password !== '') {
                    $hash = password_hash($password, PASSWORD_BCRYPT);
                    $upd = $db->prepare("UPDATE users SET username = :u, password = :p, nama_lengkap = :n, id_kelas = :k WHERE id_user = :id AND role = 'guru'");
                    $upd->execute([':u' => $username, ':p' => $hash, ':n' => $nama, ':k' => $id_kelas, ':id' => $id_user]);
                } else {
                    $upd = $db->prepare("UPDATE users SET username = :u, nama_lengkap = :n, id_kelas = :k WHERE id_user = :id AND role = 'guru'");
                    $upd->execute([':u' => $username, ':n' => $nama, ':k' => $id_kelas, ':id' => $id_user]);
                }
                flash_set('success', 'Data guru berhasil diperbarui.');
            }
        }
        redirect(base_url('operator/guru_crud.php?tab=guru'));
    }

    // GURU: HAPUS
    if ($action === 'hapus_guru') {
        $id = (int)($_POST['id_user'] ?? 0);
        if ($id > 0) {
            $del = $db->prepare("DELETE FROM users WHERE id_user = :id AND role = 'guru'");
            $del->execute([':id' => $id]);
            flash_set('danger', 'Data guru berhasil dihapus.');
        }
        redirect(base_url('operator/guru_crud.php?tab=guru'));
    }

    // 2. MAPEL: TAMBAH
    if ($action === 'tambah_mapel') {
        $nama_mapel = trim($_POST['nama_mapel'] ?? '');
        $kode_mapel = trim($_POST['kode_mapel'] ?? '');

        if ($nama_mapel === '' || $kode_mapel === '') {
            flash_set('danger', 'Nama dan kode mata pelajaran wajib diisi.');
        } else {
            $cek = $db->prepare("SELECT id_mapel FROM mapel WHERE kode_mapel = :k");
            $cek->execute([':k' => $kode_mapel]);
            if ($cek->fetch()) {
                flash_set('danger', "Kode mapel '{$kode_mapel}' sudah ada.");
            } else {
                $ins = $db->prepare("INSERT INTO mapel (nama_mapel, kode_mapel) VALUES (:n, :k)");
                $ins->execute([':n' => $nama_mapel, ':k' => $kode_mapel]);
                flash_set('success', 'Mata pelajaran berhasil ditambahkan.');
            }
        }
        redirect(base_url('operator/guru_crud.php?tab=mapel'));
    }

    // MAPEL: HAPUS
    if ($action === 'hapus_mapel') {
        $id = (int)($_POST['id_mapel'] ?? 0);
        if ($id > 0) {
            $del = $db->prepare("DELETE FROM mapel WHERE id_mapel = :id");
            $del->execute([':id' => $id]);
            flash_set('danger', 'Mata pelajaran berhasil dihapus.');
        }
        redirect(base_url('operator/guru_crud.php?tab=mapel'));
    }

    // 3. KELAS: TAMBAH
    if ($action === 'tambah_kelas') {
        $nama_kelas = trim($_POST['nama_kelas'] ?? '');
        if ($nama_kelas === '') {
            flash_set('danger', 'Nama kelas wajib diisi.');
        } else {
            $ins = $db->prepare("INSERT INTO kelas (nama_kelas) VALUES (:k)");
            $ins->execute([':k' => $nama_kelas]);
            flash_set('success', 'Kelas berhasil ditambahkan.');
        }
        redirect(base_url('operator/guru_crud.php?tab=kelas'));
    }

    // KELAS: HAPUS
    if ($action === 'hapus_kelas') {
        $id = (int)($_POST['id_kelas'] ?? 0);
        if ($id > 0) {
            $del = $db->prepare("DELETE FROM kelas WHERE id_kelas = :id");
            $del->execute([':id' => $id]);
            flash_set('danger', 'Kelas berhasil dihapus.');
        }
        redirect(base_url('operator/guru_crud.php?tab=kelas'));
    }
}

// Ambil Data
$activeTab  = $_GET['tab'] ?? 'guru';
$guruList   = $db->query("
    SELECT u.id_user, u.username, u.nama_lengkap, u.status_login, u.id_kelas, k.nama_kelas 
    FROM users u 
    LEFT JOIN kelas k ON u.id_kelas = k.id_kelas 
    WHERE u.role = 'guru' 
    ORDER BY k.nama_kelas NULLS LAST, u.nama_lengkap ASC
")->fetchAll();
$mapelList  = $db->query("SELECT id_mapel, nama_mapel, kode_mapel FROM mapel ORDER BY nama_mapel ASC")->fetchAll();
$kelasList  = $db->query("SELECT id_kelas, nama_kelas FROM kelas ORDER BY nama_kelas ASC")->fetchAll();

$flash = flash_get();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Guru, Mapel & Kelas - CBT Operator</title>
    <link rel="icon" type="image/svg+xml" href="<?= base_url('assets/img/favicon.svg') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/cbt-style.css') ?>">
</head>
<body>

<header class="cbt-navbar">
    <div class="cbt-navbar-header">
        <a href="<?= base_url('operator/dashboard.php') ?>" class="cbt-navbar-brand">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect><line x1="8" y1="21" x2="16" y2="21"></line><line x1="12" y1="17" x2="12" y2="21"></line></svg>
            <span>CBT OPERATOR</span>
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
            <li><a href="<?= base_url('operator/dashboard.php') ?>">Dashboard</a></li>
            <li><a href="<?= base_url('operator/siswa_crud.php') ?>">Data Siswa</a></li>
            <li><a href="<?= base_url('operator/guru_crud.php') ?>" class="active">Guru & Mapel</a></li>
            <li><a href="<?= base_url('operator/reset_login.php') ?>">Monitoring & Reset</a></li>
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
            <h1 class="card-title">Master Data Guru, Mapel & Kelas</h1>
        </div>
        <div class="card-header-actions">
            <a href="?tab=guru" class="btn <?= ($activeTab === 'guru') ? 'btn-primary' : 'btn-outline' ?>">Data Guru</a>
            <a href="?tab=mapel" class="btn <?= ($activeTab === 'mapel') ? 'btn-primary' : 'btn-outline' ?>">Mata Pelajaran</a>
            <a href="?tab=kelas" class="btn <?= ($activeTab === 'kelas') ? 'btn-primary' : 'btn-outline' ?>">Daftar Kelas</a>
        </div>
    </div>

    <!-- TAB 1: GURU -->
    <?php if ($activeTab === 'guru'): ?>
        <div class="card">
            <div class="flex-between mb-3" style="flex-wrap: wrap; gap: 0.5rem;">
                <h2 class="card-title">Daftar Guru Penguji</h2>
                <button type="button" class="btn btn-primary btn-sm" onclick="openModal('modal-tambah-guru')">+ Tambah Guru</button>
            </div>
            <div class="table-responsive table-mobile-cards">
                <table class="table">
                    <thead>
                        <tr>
                            <th style="width: 50px;">No</th>
                            <th>Username</th>
                            <th>Nama Lengkap Guru</th>
                            <th>Guru Kelas / Tingkat</th>
                            <th>Status Login</th>
                            <th style="width: 160px; text-align: center;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($guruList)): ?>
                            <tr><td colspan="6" class="text-center text-muted" style="padding: 2rem;">Belum ada data guru.</td></tr>
                        <?php else: ?>
                            <?php foreach ($guruList as $idx => $g): ?>
                                <tr>
                                    <td data-label="No"><?= $idx + 1 ?></td>
                                    <td data-label="Username"><strong><?= sanitize($g['username']) ?></strong></td>
                                    <td data-label="Nama Lengkap"><?= sanitize($g['nama_lengkap']) ?></td>
                                    <td data-label="Tingkat Kelas">
                                        <?php if (!empty($g['nama_kelas'])): ?>
                                            <span class="badge badge-aktif">Guru <?= sanitize($g['nama_kelas']) ?></span>
                                        <?php else: ?>
                                            <span class="badge badge-offline">Guru Mapel Umum</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Status Login"><span class="badge badge-<?= ($g['status_login'] === 'online') ? 'online' : 'offline' ?>"><?= strtoupper($g['status_login']) ?></span></td>
                                    <td data-label="Aksi">
                                        <div class="flex gap-2">
                                            <button type="button" class="btn btn-sm btn-outline" onclick='openEditGuruModal(<?= json_encode($g) ?>)'>Edit</button>
                                            <form action="<?= base_url('operator/guru_crud.php') ?>" method="POST" data-confirm="Hapus akun guru <?= sanitize($g['nama_lengkap']) ?> beserta seluruh soal & sesinya?" data-confirm-title="Hapus Akun Guru" data-confirm-type="danger" data-confirm-btn="Ya, Hapus">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="hapus_guru">
                                                <input type="hidden" name="id_user" value="<?= $g['id_user'] ?>">
                                                <button type="submit" class="btn btn-sm btn-danger">Hapus</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <!-- TAB 2: MAPEL -->
    <?php if ($activeTab === 'mapel'): ?>
        <div class="card">
            <div class="flex-between mb-3">
                <h2 class="card-title">Mata Pelajaran</h2>
                <button type="button" class="btn btn-primary btn-sm" onclick="openModal('modal-tambah-mapel')">+ Tambah Mapel</button>
            </div>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th style="width: 50px;">No</th>
                            <th>Kode Mapel</th>
                            <th>Nama Mata Pelajaran</th>
                            <th style="width: 120px; text-align: center;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($mapelList)): ?>
                            <tr><td colspan="4" class="text-center text-muted" style="padding: 2rem;">Belum ada mata pelajaran.</td></tr>
                        <?php else: ?>
                            <?php foreach ($mapelList as $idx => $m): ?>
                                <tr>
                                    <td><?= $idx + 1 ?></td>
                                    <td><span class="badge badge-role"><?= sanitize($m['kode_mapel']) ?></span></td>
                                    <td><strong><?= sanitize($m['nama_mapel']) ?></strong></td>
                                    <td style="text-align: center;">
                                        <form action="<?= base_url('operator/guru_crud.php') ?>" method="POST" style="display:inline;" data-confirm="Hapus mata pelajaran <?= sanitize($m['nama_mapel']) ?>?" data-confirm-title="Hapus Mata Pelajaran" data-confirm-type="danger" data-confirm-btn="Ya, Hapus">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="hapus_mapel">
                                            <input type="hidden" name="id_mapel" value="<?= $m['id_mapel'] ?>">
                                            <button type="submit" class="btn btn-sm btn-danger">Hapus</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <!-- TAB 3: KELAS -->
    <?php if ($activeTab === 'kelas'): ?>
        <div class="card">
            <div class="flex-between mb-3">
                <h2 class="card-title">Daftar Kelas / Rombel</h2>
                <button type="button" class="btn btn-primary btn-sm" onclick="openModal('modal-tambah-kelas')">+ Tambah Kelas</button>
            </div>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th style="width: 50px;">No</th>
                            <th>Nama Kelas / Rombel</th>
                            <th style="width: 120px; text-align: center;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($kelasList)): ?>
                            <tr><td colspan="3" class="text-center text-muted" style="padding: 2rem;">Belum ada kelas.</td></tr>
                        <?php else: ?>
                            <?php foreach ($kelasList as $idx => $k): ?>
                                <tr>
                                    <td><?= $idx + 1 ?></td>
                                    <td><strong><?= sanitize($k['nama_kelas']) ?></strong></td>
                                    <td style="text-align: center;">
                                        <form action="<?= base_url('operator/guru_crud.php') ?>" method="POST" style="display:inline;" data-confirm="Hapus kelas <?= sanitize($k['nama_kelas']) ?>? Siswa di kelas ini akan menjadi tanpa kelas." data-confirm-title="Hapus Kelas" data-confirm-type="danger" data-confirm-btn="Ya, Hapus">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="hapus_kelas">
                                            <input type="hidden" name="id_kelas" value="<?= $k['id_kelas'] ?>">
                                            <button type="submit" class="btn btn-sm btn-danger">Hapus</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</main>

<!-- Modal Tambah Guru -->
<div id="modal-tambah-guru" class="modal-overlay">
    <div class="modal-box">
        <h2 class="card-title mb-3">Tambah Guru Penguji / Guru Kelas</h2>
        <form action="<?= base_url('operator/guru_crud.php') ?>" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="tambah_guru">
            <div class="form-group">
                <label>Username Guru</label>
                <input type="text" name="username" class="form-control" required placeholder="Contoh: guru_kelas1">
            </div>
            <div class="form-group">
                <label>Nama Lengkap (beserta Gelar)</label>
                <input type="text" name="nama_lengkap" class="form-control" required placeholder="Contoh: Siti Nurhaliza, S.Pd.SD">
            </div>
            <div class="form-group">
                <label>Kata Sandi</label>
                <input type="password" name="password" class="form-control" required placeholder="Kata sandi akun guru...">
            </div>
            <div class="form-group">
                <label>Penugasan Kelas (Guru Kelas / Wali Kelas)</label>
                <select name="id_kelas" class="form-control">
                    <option value="">Guru Mata Pelajaran Umum (Semua Kelas)</option>
                    <?php foreach ($kelasList as $k): ?>
                        <option value="<?= $k['id_kelas'] ?>">Guru <?= sanitize($k['nama_kelas']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex gap-2 mt-4" style="justify-content: flex-end;">
                <button type="button" class="btn btn-outline" onclick="closeModal('modal-tambah-guru')">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Guru</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Edit Guru -->
<div id="modal-edit-guru" class="modal-overlay">
    <div class="modal-box">
        <h2 class="card-title mb-3">Edit Data Guru / Penugasan Kelas</h2>
        <form action="<?= base_url('operator/guru_crud.php') ?>" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit_guru">
            <input type="hidden" id="edit-guru-id" name="id_user" value="">

            <div class="form-group">
                <label>Username Guru</label>
                <input type="text" id="edit-guru-username" name="username" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Nama Lengkap</label>
                <input type="text" id="edit-guru-nama" name="nama_lengkap" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Ganti Kata Sandi (Kosongkan jika tidak diubah)</label>
                <input type="password" name="password" class="form-control" placeholder="Kata sandi baru...">
            </div>
            <div class="form-group">
                <label>Penugasan Kelas (Guru Kelas / Wali Kelas)</label>
                <select id="edit-guru-kelas" name="id_kelas" class="form-control">
                    <option value="">Guru Mata Pelajaran Umum (Semua Kelas)</option>
                    <?php foreach ($kelasList as $k): ?>
                        <option value="<?= $k['id_kelas'] ?>">Guru <?= sanitize($k['nama_kelas']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex gap-2 mt-4" style="justify-content: flex-end;">
                <button type="button" class="btn btn-outline" onclick="closeModal('modal-edit-guru')">Batal</button>
                <button type="submit" class="btn btn-primary">Perbarui Data Guru</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Tambah Mapel -->
<div id="modal-tambah-mapel" class="modal-overlay">
    <div class="modal-box">
        <h2 class="card-title mb-3">Tambah Mata Pelajaran</h2>
        <form action="<?= base_url('operator/guru_crud.php') ?>" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="tambah_mapel">
            <div class="form-group">
                <label>Kode Mapel</label>
                <input type="text" name="kode_mapel" class="form-control" required placeholder="Contoh: IPAS-SD">
            </div>
            <div class="form-group">
                <label>Nama Mata Pelajaran</label>
                <input type="text" name="nama_mapel" class="form-control" required placeholder="Contoh: Ilmu Pengetahuan Alam dan Sosial">
            </div>
            <div class="flex gap-2 mt-4" style="justify-content: flex-end;">
                <button type="button" class="btn btn-outline" onclick="closeModal('modal-tambah-mapel')">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Mapel</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Tambah Kelas -->
<div id="modal-tambah-kelas" class="modal-overlay">
    <div class="modal-box">
        <h2 class="card-title mb-3">Tambah Kelas Baru</h2>
        <form action="<?= base_url('operator/guru_crud.php') ?>" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="tambah_kelas">
            <div class="form-group">
                <label>Nama Kelas</label>
                <input type="text" name="nama_kelas" class="form-control" required placeholder="Contoh: Kelas 6">
            </div>
            <div class="flex gap-2 mt-4" style="justify-content: flex-end;">
                <button type="button" class="btn btn-outline" onclick="closeModal('modal-tambah-kelas')">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Kelas</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditGuruModal(data) {
    document.getElementById('edit-guru-id').value = data.id_user;
    document.getElementById('edit-guru-username').value = data.username;
    document.getElementById('edit-guru-nama').value = data.nama_lengkap;
    document.getElementById('edit-guru-kelas').value = data.id_kelas || '';
    openModal('modal-edit-guru');
}
</script>
<script src="<?= base_url('assets/js/app.js') ?>"></script>
</body>
</html>
