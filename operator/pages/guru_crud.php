<?php
/**
 * Page: Manajemen Data Guru, Mata Pelajaran & Kelas
 */

require_once __DIR__ . '/../../middleware/auth.php';

$currentUser = auth_check(['operator']);
$db = get_db();

// Tangani Operasi Form POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash_set('danger', 'Validasi token keamanan gagal.');
        redirect(base_url('operator?page=guru_crud'));
    }

    $action = $_POST['action'] ?? '';

    // 1. GURU: TAMBAH
    if ($action === 'tambah_guru') {
        $username = trim($_POST['username'] ?? '');
        $nama     = trim($_POST['nama_lengkap'] ?? '');
        $nip      = trim($_POST['nip'] ?? '');
        $no_hp    = clean_phone($_POST['no_hp'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $id_kelas = !empty($_POST['id_kelas']) ? (int)$_POST['id_kelas'] : null;

        if ($username === '' || $nama === '' || $password === '') {
            flash_set('danger', 'Semua kolom guru wajib diisi.');
        } elseif ($no_hp !== '' && !is_valid_phone($no_hp)) {
            flash_set('danger', 'Nomor HP Guru tidak valid. Hanya boleh diisi angka yang sesuai (contoh: 081234567890).');
        } else {
            $cek = $db->prepare("SELECT id_user FROM users WHERE username = :u");
            $cek->execute([':u' => $username]);
            if ($cek->fetch()) {
                flash_set('danger', 'Username sudah terdaftar.');
            } else {
                $status_akun = in_array($_POST['status_akun'] ?? 'aktif', ['aktif', 'nonaktif'], true) ? $_POST['status_akun'] : 'aktif';
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $ins = $db->prepare("INSERT INTO users (username, password, nama_lengkap, nip, no_hp, role, id_kelas, status_login, status_akun) VALUES (:u, :p, :n, :nip, :hp, 'guru', :k, 'offline', :sa)");
                $ins->execute([
                    ':u'   => $username,
                    ':p'   => $hash,
                    ':n'   => $nama,
                    ':nip' => ($nip !== '' ? $nip : null),
                    ':hp'  => ($no_hp !== '' ? $no_hp : null),
                    ':k'   => $id_kelas,
                    ':sa'  => $status_akun
                ]);
                flash_set('success', 'Guru berhasil ditambahkan.');
            }
        }
        redirect(base_url('operator?page=guru_crud&tab=guru'));
    }

    // GURU: EDIT
    if ($action === 'edit_guru') {
        $id_user  = (int)($_POST['id_user'] ?? 0);
        $username = trim($_POST['username'] ?? '');
        $nama     = trim($_POST['nama_lengkap'] ?? '');
        $nip      = trim($_POST['nip'] ?? '');
        $no_hp    = clean_phone($_POST['no_hp'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $id_kelas = !empty($_POST['id_kelas']) ? (int)$_POST['id_kelas'] : null;

        if ($id_user <= 0 || $username === '' || $nama === '') {
            flash_set('danger', 'Data guru tidak valid.');
        } elseif ($no_hp !== '' && !is_valid_phone($no_hp)) {
            flash_set('danger', 'Nomor HP Guru tidak valid. Hanya boleh diisi angka yang sesuai (contoh: 081234567890).');
        } else {
            $cek = $db->prepare("SELECT id_user FROM users WHERE username = :u AND id_user != :id");
            $cek->execute([':u' => $username, ':id' => $id_user]);
            if ($cek->fetch()) {
                flash_set('danger', 'Username sudah digunakan akun lain.');
            } else {
                $status_akun = in_array($_POST['status_akun'] ?? 'aktif', ['aktif', 'nonaktif'], true) ? $_POST['status_akun'] : 'aktif';
                $extraSql = ($status_akun === 'nonaktif') ? ", status_login = 'offline'" : "";
                if ($password !== '') {
                    $hash = password_hash($password, PASSWORD_BCRYPT);
                    $upd = $db->prepare("UPDATE users SET username = :u, password = :p, nama_lengkap = :n, nip = :nip, no_hp = :hp, id_kelas = :k, status_akun = :sa{$extraSql} WHERE id_user = :id AND role = 'guru'");
                    $upd->execute([':u' => $username, ':p' => $hash, ':n' => $nama, ':nip' => ($nip !== '' ? $nip : null), ':hp' => ($no_hp !== '' ? $no_hp : null), ':k' => $id_kelas, ':sa' => $status_akun, ':id' => $id_user]);
                } else {
                    $upd = $db->prepare("UPDATE users SET username = :u, nama_lengkap = :n, nip = :nip, no_hp = :hp, id_kelas = :k, status_akun = :sa{$extraSql} WHERE id_user = :id AND role = 'guru'");
                    $upd->execute([':u' => $username, ':n' => $nama, ':nip' => ($nip !== '' ? $nip : null), ':hp' => ($no_hp !== '' ? $no_hp : null), ':k' => $id_kelas, ':sa' => $status_akun, ':id' => $id_user]);
                }
                flash_set('success', 'Data guru berhasil diperbarui.');
            }
        }
        redirect(base_url('operator?page=guru_crud&tab=guru'));
    }

    // GURU: HAPUS
    if ($action === 'hapus_guru') {
        $id = (int)($_POST['id_user'] ?? 0);
        if ($id > 0) {
            $del = $db->prepare("DELETE FROM users WHERE id_user = :id AND role = 'guru'");
            $del->execute([':id' => $id]);
            flash_set('danger', 'Data guru berhasil dihapus.');
        }
        redirect(base_url('operator?page=guru_crud&tab=guru'));
    }

    // GURU: TOGGLE STATUS AKUN
    if ($action === 'toggle_status_guru') {
        $id = (int)($_POST['id_user'] ?? 0);
        if ($id > 0) {
            $st = $db->prepare("SELECT status_akun, nama_lengkap FROM users WHERE id_user = :id AND role = 'guru'");
            $st->execute([':id' => $id]);
            $userTarget = $st->fetch();
            if ($userTarget) {
                $newStatus = (($userTarget['status_akun'] ?? 'aktif') === 'nonaktif') ? 'aktif' : 'nonaktif';
                if ($newStatus === 'nonaktif') {
                    $upd = $db->prepare("UPDATE users SET status_akun = 'nonaktif', status_login = 'offline' WHERE id_user = :id AND role = 'guru'");
                    $upd->execute([':id' => $id]);
                    flash_set('warning', 'Akun guru ' . sanitize($userTarget['nama_lengkap']) . ' berhasil dinonaktifkan.');
                } else {
                    $upd = $db->prepare("UPDATE users SET status_akun = 'aktif' WHERE id_user = :id AND role = 'guru'");
                    $upd->execute([':id' => $id]);
                    flash_set('success', 'Akun guru ' . sanitize($userTarget['nama_lengkap']) . ' berhasil diaktifkan kembali.');
                }
            }
        }
        redirect(base_url('operator?page=guru_crud&tab=guru'));
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
        redirect(base_url('operator?page=guru_crud&tab=mapel'));
    }

    // MAPEL: HAPUS
    if ($action === 'hapus_mapel') {
        $id = (int)($_POST['id_mapel'] ?? 0);
        if ($id > 0) {
            $del = $db->prepare("DELETE FROM mapel WHERE id_mapel = :id");
            $del->execute([':id' => $id]);
            flash_set('danger', 'Mata pelajaran berhasil dihapus.');
        }
        redirect(base_url('operator?page=guru_crud&tab=mapel'));
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
        redirect(base_url('operator?page=guru_crud&tab=kelas'));
    }

    // KELAS: HAPUS
    if ($action === 'hapus_kelas') {
        $id = (int)($_POST['id_kelas'] ?? 0);
        if ($id > 0) {
            $del = $db->prepare("DELETE FROM kelas WHERE id_kelas = :id");
            $del->execute([':id' => $id]);
            flash_set('danger', 'Kelas berhasil dihapus.');
        }
        redirect(base_url('operator?page=guru_crud&tab=kelas'));
    }
}

// Ambil Data
$activeTab  = $_GET['tab'] ?? 'guru';
try {
    $guruList = $db->query("
        SELECT u.id_user, u.username, u.nama_lengkap, u.nip, u.no_hp, u.status_login, COALESCE(u.status_akun, 'aktif') AS status_akun, u.id_kelas, k.nama_kelas 
        FROM users u 
        LEFT JOIN kelas k ON u.id_kelas = k.id_kelas 
        WHERE u.role = 'guru' 
        ORDER BY k.nama_kelas NULLS LAST, u.nama_lengkap ASC
    ")->fetchAll();
} catch (Throwable $e) {
    $guruList = $db->query("
        SELECT u.id_user, u.username, u.nama_lengkap, u.status_login, COALESCE(u.status_akun, 'aktif') AS status_akun, u.id_kelas, k.nama_kelas 
        FROM users u 
        LEFT JOIN kelas k ON u.id_kelas = k.id_kelas 
        WHERE u.role = 'guru' 
        ORDER BY k.nama_kelas NULLS LAST, u.nama_lengkap ASC
    ")->fetchAll();
}
$mapelList  = $db->query("SELECT id_mapel, nama_mapel, kode_mapel FROM mapel ORDER BY nama_mapel ASC")->fetchAll();
$kelasList  = $db->query("SELECT id_kelas, nama_kelas FROM kelas ORDER BY nama_kelas ASC")->fetchAll();

$page = 'guru_crud';
$pageTitle = 'Guru, Mapel & Kelas';
$flash = flash_get();

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
            <h1 class="card-title">Master Data Guru, Mapel & Kelas</h1>
        </div>
        <div class="card-header-actions">
            <a href="<?= base_url('operator?page=guru_crud&tab=guru') ?>" class="btn <?= ($activeTab === 'guru') ? 'btn-primary' : 'btn-outline' ?>">Data Guru</a>
            <a href="<?= base_url('operator?page=guru_crud&tab=mapel') ?>" class="btn <?= ($activeTab === 'mapel') ? 'btn-primary' : 'btn-outline' ?>">Mata Pelajaran</a>
            <a href="<?= base_url('operator?page=guru_crud&tab=kelas') ?>" class="btn <?= ($activeTab === 'kelas') ? 'btn-primary' : 'btn-outline' ?>">Daftar Kelas</a>
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
                            <th>Status Akun</th>
                            <th>Status Login</th>
                            <th style="width: 250px; text-align: center;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($guruList)): ?>
                            <tr><td colspan="7" class="text-center text-muted" style="padding: 2rem;">Belum ada data guru.</td></tr>
                        <?php else: ?>
                            <?php foreach ($guruList as $idx => $g): ?>
                                <tr>
                                    <td data-label="No"><?= $idx + 1 ?></td>
                                    <td data-label="Username"><strong><?= sanitize($g['username']) ?></strong></td>
                                    <td data-label="Nama Lengkap">
                                        <strong><?= sanitize($g['nama_lengkap']) ?></strong>
                                        <?php if (!empty($g['nip'])): ?>
                                            <div class="text-xs text-muted">NIP. <?= sanitize($g['nip']) ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($g['no_hp'])): ?>
                                            <div class="text-xs" style="margin-top: 2px;">
                                                <a href="tel:<?= sanitize($g['no_hp']) ?>" class="badge" style="background:#ecfdf5; color:#047857; font-family:monospace; text-decoration:none; padding: 1px 6px;">
                                                    📞 <?= sanitize($g['no_hp']) ?>
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Tingkat Kelas">
                                        <?php if (!empty($g['nama_kelas'])): ?>
                                            <span class="badge badge-aktif">Guru <?= sanitize($g['nama_kelas']) ?></span>
                                        <?php else: ?>
                                            <span class="badge badge-offline">Guru Mapel Umum</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Status Akun">
                                        <?php if (($g['status_akun'] ?? 'aktif') === 'nonaktif'): ?>
                                            <span class="badge" style="background:#dc2626; color:#fff;">Nonaktif</span>
                                        <?php else: ?>
                                            <span class="badge badge-aktif">Aktif</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Status Login"><span class="badge badge-<?= ($g['status_login'] === 'online') ? 'online' : 'offline' ?>"><?= strtoupper($g['status_login']) ?></span></td>
                                    <td data-label="Aksi">
                                        <div class="flex" style="gap: 0.5rem; justify-content: center; align-items: center; flex-wrap: nowrap;">
                                            <!-- Tombol Toggle Status Akun -->
                                            <form action="<?= base_url('operator?page=guru_crud') ?>" method="POST" style="display:inline-flex; margin:0;" data-confirm="<?= ($g['status_akun'] ?? 'aktif') === 'nonaktif' ? 'Aktifkan kembali akun guru ' . sanitize($g['nama_lengkap']) . '?' : 'Nonaktifkan akun guru ' . sanitize($g['nama_lengkap']) . '? Guru tidak akan bisa login sampai diaktifkan kembali.' ?>" data-confirm-title="<?= ($g['status_akun'] ?? 'aktif') === 'nonaktif' ? 'Aktifkan Akun Guru' : 'Nonaktifkan Akun Guru' ?>" data-confirm-type="<?= ($g['status_akun'] ?? 'aktif') === 'nonaktif' ? 'primary' : 'warning' ?>" data-confirm-btn="<?= ($g['status_akun'] ?? 'aktif') === 'nonaktif' ? 'Ya, Aktifkan' : 'Ya, Nonaktifkan' ?>">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="toggle_status_guru">
                                                <input type="hidden" name="id_user" value="<?= $g['id_user'] ?>">
                                                <?php if (($g['status_akun'] ?? 'aktif') === 'nonaktif'): ?>
                                                    <button type="submit" class="btn btn-sm" style="background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; padding: 0.25rem 0.6rem; font-size: 0.78rem; font-weight: 600;" title="Aktifkan Akun Guru">Aktifkan</button>
                                                <?php else: ?>
                                                    <button type="submit" class="btn btn-sm" style="background: #fff7ed; color: #c2410c; border: 1px solid #fed7aa; padding: 0.25rem 0.6rem; font-size: 0.78rem; font-weight: 600;" title="Nonaktifkan Akun Guru">Nonaktifkan</button>
                                                <?php endif; ?>
                                            </form>

                                            <button type="button" class="btn btn-sm btn-outline" style="padding: 0.25rem 0.6rem; font-size: 0.78rem;" onclick='openEditGuruModal(<?= json_encode($g) ?>)'>Edit</button>

                                            <form action="<?= base_url('operator?page=guru_crud') ?>" method="POST" style="display:inline-flex; margin:0;" data-confirm="Hapus akun guru <?= sanitize($g['nama_lengkap']) ?> beserta seluruh soal & sesinya?" data-confirm-title="Hapus Akun Guru" data-confirm-type="danger" data-confirm-btn="Ya, Hapus">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="hapus_guru">
                                                <input type="hidden" name="id_user" value="<?= $g['id_user'] ?>">
                                                <button type="submit" class="btn btn-sm btn-danger" style="padding: 0.25rem 0.6rem; font-size: 0.78rem;">Hapus</button>
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
                                        <form action="<?= base_url('operator?page=guru_crud') ?>" method="POST" style="display:inline;" data-confirm="Hapus mata pelajaran <?= sanitize($m['nama_mapel']) ?>?" data-confirm-title="Hapus Mata Pelajaran" data-confirm-type="danger" data-confirm-btn="Ya, Hapus">
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
                                        <form action="<?= base_url('operator?page=guru_crud') ?>" method="POST" style="display:inline;" data-confirm="Hapus kelas <?= sanitize($k['nama_kelas']) ?>? Siswa di kelas ini akan menjadi tanpa kelas." data-confirm-title="Hapus Kelas" data-confirm-type="danger" data-confirm-btn="Ya, Hapus">
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
        <form action="<?= base_url('operator?page=guru_crud') ?>" method="POST">
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
                <label>NIP Guru (Nomor Induk Pegawai - Opsional)</label>
                <input type="text" name="nip" class="form-control" placeholder="Contoh: 198501012010011005">
            </div>
            <div class="form-group">
                <label>No. HP / WhatsApp Guru (Opsional)</label>
                <input type="tel" inputmode="numeric" name="no_hp" class="form-control input-phone" maxlength="16" placeholder="Contoh: 081234567890 (Hanya Angka)">
                <small class="text-muted text-xs">Hanya angka (contoh: 081234567890)</small>
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
            <div class="form-group">
                <label>Status Akun</label>
                <select name="status_akun" class="form-control">
                    <option value="aktif" selected>Aktif (Dapat Login)</option>
                    <option value="nonaktif">Nonaktif (Diblokir dari Login)</option>
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
        <form action="<?= base_url('operator?page=guru_crud') ?>" method="POST">
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
                <label>NIP Guru (Nomor Induk Pegawai - Opsional)</label>
                <input type="text" id="edit-guru-nip" name="nip" class="form-control" placeholder="Contoh: 198501012010011005">
            </div>
            <div class="form-group">
                <label>No. HP / WhatsApp Guru (Opsional)</label>
                <input type="tel" inputmode="numeric" id="edit-guru-no_hp" name="no_hp" class="form-control input-phone" maxlength="16" placeholder="Contoh: 081234567890 (Hanya Angka)">
                <small class="text-muted text-xs">Hanya angka (contoh: 081234567890)</small>
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
            <div class="form-group">
                <label>Status Akun</label>
                <select id="edit-guru-status_akun" name="status_akun" class="form-control">
                    <option value="aktif">Aktif (Dapat Login)</option>
                    <option value="nonaktif">Nonaktif (Diblokir dari Login)</option>
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
        <form action="<?= base_url('operator?page=guru_crud') ?>" method="POST">
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
        <form action="<?= base_url('operator?page=guru_crud') ?>" method="POST">
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

<?php
$extraJs = '
<script>
function openEditGuruModal(data) {
    document.getElementById("edit-guru-id").value = data.id_user;
    document.getElementById("edit-guru-username").value = data.username;
    document.getElementById("edit-guru-nama").value = data.nama_lengkap;
    document.getElementById("edit-guru-nip").value = data.nip || "";
    document.getElementById("edit-guru-no_hp").value = data.no_hp || "";
    document.getElementById("edit-guru-kelas").value = data.id_kelas || "";
    document.getElementById("edit-guru-status_akun").value = data.status_akun || "aktif";
    openModal("modal-edit-guru");
}
</script>
';

include __DIR__ . '/../layouts/footer.php';
