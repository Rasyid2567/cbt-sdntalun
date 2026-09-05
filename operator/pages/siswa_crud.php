<?php
/**
 * Page: Manajemen Data Siswa (CRUD & CSV Import)
 */

require_once __DIR__ . '/../../middleware/auth.php';

$currentUser = auth_check(['operator']);
$db = get_db();

// Tangani Export Template CSV
if (isset($_GET['action']) && $_GET['action'] === 'download_template') {
    if (ob_get_level() > 0) {
        ob_end_clean();
    }

    $filename = 'template_import_siswa.csv';
    header('Content-Description: File Transfer');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Expires: 0');
    header('Pragma: public');

    $output = fopen('php://output', 'w');
    // UTF-8 BOM untuk kompatibilitas Microsoft Excel
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    // Beritahu Excel untuk memecah kolom dengan koma secara otomatis
    fwrite($output, "sep=,\n");
    fputcsv($output, ['nis', 'username', 'nama_lengkap', 'password', 'nama_kelas']);
    fputcsv($output, ['2024001', 'siswa1', 'Budi Pratama', 'siswa123', 'Kelas 6']);
    fputcsv($output, ['2024002', 'siswa2', 'Dewi Lestari', 'siswa123', 'Kelas 6']);
    fputcsv($output, ['2024003', 'siswa3', 'Rian Hidayat', 'siswa123', 'Kelas 5']);
    fclose($output);
    exit;
}

// 1. Tangani Proses Form POST (Tambah, Edit, Hapus, Import CSV)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash_set('danger', 'Validasi token keamanan gagal.');
        redirect(base_url('operator?page=siswa_crud'));
    }

    $action = $_POST['action'] ?? '';

    // TAMBAH SISWA
    if ($action === 'tambah') {
        $nis      = trim($_POST['nis'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $nama     = trim($_POST['nama_lengkap'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $id_kelas = !empty($_POST['id_kelas']) ? (int)$_POST['id_kelas'] : null;

        if ($username === '' || $nama === '' || $password === '' || $nis === '') {
            flash_set('danger', 'Seluruh field wajib diisi (NIS, Username, Nama, Password).');
        } else {
            // Cek duplikasi username atau nis
            $cek = $db->prepare("SELECT id_user FROM users WHERE username = :u OR (nis = :nis AND nis IS NOT NULL)");
            $cek->execute([':u' => $username, ':nis' => $nis]);
            if ($cek->fetch()) {
                flash_set('danger', "NIS '{$nis}' atau Username '{$username}' sudah terdaftar pada akun lain.");
            } else {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $ins = $db->prepare("
                    INSERT INTO users (nis, username, password, nama_lengkap, role, id_kelas, status_login) 
                    VALUES (:nis, :u, :p, :n, 'siswa', :k, 'offline')
                ");
                $ins->execute([
                    ':nis' => $nis,
                    ':u'   => $username,
                    ':p'   => $hash,
                    ':n'   => $nama,
                    ':k'   => $id_kelas
                ]);
                flash_set('success', 'Data siswa berhasil ditambahkan.');
            }
        }
        redirect(base_url('operator?page=siswa_crud'));
    }

    // EDIT SISWA
    if ($action === 'edit') {
        $id_user  = (int)($_POST['id_user'] ?? 0);
        $nis      = trim($_POST['nis'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $nama     = trim($_POST['nama_lengkap'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $id_kelas = !empty($_POST['id_kelas']) ? (int)$_POST['id_kelas'] : null;

        if ($id_user <= 0 || $username === '' || $nama === '' || $nis === '') {
            flash_set('danger', 'Data tidak valid. NIS, Username, dan Nama wajib diisi.');
        } else {
            // Cek duplikasi username atau nis untuk user lain
            $cek = $db->prepare("SELECT id_user FROM users WHERE (username = :u OR (nis = :nis AND nis IS NOT NULL)) AND id_user != :id");
            $cek->execute([':u' => $username, ':nis' => $nis, ':id' => $id_user]);
            if ($cek->fetch()) {
                flash_set('danger', "NIS atau Username sudah digunakan oleh akun siswa lain.");
            } else {
                if ($password !== '') {
                    $hash = password_hash($password, PASSWORD_BCRYPT);
                    $upd = $db->prepare("
                        UPDATE users 
                        SET nis = :nis, username = :u, password = :p, nama_lengkap = :n, id_kelas = :k 
                        WHERE id_user = :id AND role = 'siswa'
                    ");
                    $upd->execute([':nis' => $nis, ':u' => $username, ':p' => $hash, ':n' => $nama, ':k' => $id_kelas, ':id' => $id_user]);
                } else {
                    $upd = $db->prepare("
                        UPDATE users 
                        SET nis = :nis, username = :u, nama_lengkap = :n, id_kelas = :k 
                        WHERE id_user = :id AND role = 'siswa'
                    ");
                    $upd->execute([':nis' => $nis, ':u' => $username, ':n' => $nama, ':k' => $id_kelas, ':id' => $id_user]);
                }
                flash_set('success', 'Data siswa berhasil diperbarui.');
            }
        }
        redirect(base_url('operator?page=siswa_crud'));
    }

    // HAPUS SISWA
    if ($action === 'hapus') {
        $id_user = (int)($_POST['id_user'] ?? 0);
        if ($id_user > 0) {
            $del = $db->prepare("DELETE FROM users WHERE id_user = :id AND role = 'siswa'");
            $del->execute([':id' => $id_user]);
            flash_set('danger', 'Data siswa berhasil dihapus.');
        }
        redirect(base_url('operator?page=siswa_crud'));
    }

    // IMPORT CSV
    if ($action === 'import_csv') {
        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            flash_set('danger', 'Gagal mengunggah file CSV.');
        } else {
            $tmpPath = $_FILES['csv_file']['tmp_name'];
            $handle  = fopen($tmpPath, 'r');
            if ($handle === false) {
                flash_set('danger', 'Tidak dapat membaca file CSV.');
            } else {
                $imported = 0;
                $skipped  = 0;
                $rowIndex = 0;

                // Cache nama kelas ke id_kelas
                $kelasMap = [];
                $kRows = $db->query("SELECT id_kelas, nama_kelas FROM kelas")->fetchAll();
                foreach ($kRows as $kr) {
                    $kelasMap[strtoupper(trim($kr['nama_kelas']))] = $kr['id_kelas'];
                }

                // Deteksi otomatis pemisah kolom (koma atau titik koma)
                $sampleLine = fgets($handle);
                $delimiter = ',';
                if ($sampleLine !== false) {
                    $semiCount = substr_count($sampleLine, ';');
                    $commaCount = substr_count($sampleLine, ',');
                    if ($semiCount > $commaCount) {
                        $delimiter = ';';
                    }
                }
                rewind($handle);

                while (($row = fgetcsv($handle, 2000, $delimiter)) !== false) {
                    // Abaikan baris kosong atau petunjuk sep=...
                    if (empty($row) || (isset($row[0]) && str_starts_with(trim($row[0]), 'sep='))) {
                        continue;
                    }

                    $rowIndex++;
                    if ($rowIndex === 1) continue; // Lewati header

                    $nis    = trim($row[0] ?? '');
                    $uUser  = trim($row[1] ?? '');
                    $nama   = trim($row[2] ?? '');
                    $pwd    = trim($row[3] ?? '');
                    $kNama  = trim($row[4] ?? '');

                    if ($nis === '' || $uUser === '' || $nama === '') {
                        $skipped++;
                        continue;
                    }

                    // Tentukan Kelas
                    $targetKelasId = null;
                    if ($kNama !== '') {
                        $kKey = strtoupper($kNama);
                        if (isset($kelasMap[$kKey])) {
                            $targetKelasId = $kelasMap[$kKey];
                        } else {
                            $kIns = $db->prepare("INSERT INTO kelas (nama_kelas) VALUES (:k) RETURNING id_kelas");
                            $kIns->execute([':k' => $kNama]);
                            $newKId = $kIns->fetchColumn();
                            $kelasMap[$kKey] = $newKId;
                            $targetKelasId = $newKId;
                        }
                    }

                    $pwdHash = password_hash($pwd !== '' ? $pwd : 'siswa123', PASSWORD_BCRYPT);

                    try {
                        $stmtIns = $db->prepare("
                            INSERT INTO users (nis, username, password, nama_lengkap, role, id_kelas, status_login)
                            VALUES (:nis, :u, :p, :n, 'siswa', :k, 'offline')
                            ON CONFLICT (username) DO NOTHING
                        ");
                        $stmtIns->execute([
                            ':nis' => $nis,
                            ':u'   => $uUser,
                            ':p'   => $pwdHash,
                            ':n'   => $nama,
                            ':k'   => $targetKelasId
                        ]);
                        if ($stmtIns->rowCount() > 0) {
                            $imported++;
                        } else {
                            $skipped++;
                        }
                    } catch (Exception $e) {
                        $skipped++;
                    }
                }
                fclose($handle);
                flash_set('success', "Proses import selesai. Berhasil diimpor: {$imported} siswa. Dilewati: {$skipped}.");
            }
        }
        redirect(base_url('operator?page=siswa_crud'));
    }
}

// 2. Query Data Kelas untuk Dropdown
$kelasList = $db->query("SELECT id_kelas, nama_kelas FROM kelas ORDER BY nama_kelas ASC")->fetchAll();

// 3. Filter & Query Daftar Siswa
$filterKelas = !empty($_GET['filter_kelas']) ? (int)$_GET['filter_kelas'] : null;
$search      = trim($_GET['search'] ?? '');

$sql = "
    SELECT u.id_user, u.nis, u.username, u.nama_lengkap, u.status_login, u.id_kelas, k.nama_kelas
    FROM users u
    LEFT JOIN kelas k ON u.id_kelas = k.id_kelas
    WHERE u.role = 'siswa'
";
$params = [];

if ($filterKelas) {
    $sql .= " AND u.id_kelas = :fk";
    $params[':fk'] = $filterKelas;
}

if ($search !== '') {
    $sql .= " AND (u.username ILIKE :q OR u.nis ILIKE :q OR u.nama_lengkap ILIKE :q)";
    $params[':q'] = "%{$search}%";
}

$sql .= " ORDER BY k.nama_kelas NULLS LAST, u.nama_lengkap ASC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$siswaList = $stmt->fetchAll();

$page = 'siswa_crud';
$pageTitle = 'Manajemen Siswa';
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
            <h1 class="card-title">Manajemen Data Siswa (Peserta CBT)</h1>
        </div>
        <div class="card-header-actions">
            <button type="button" class="btn btn-primary" onclick="openModal('modal-tambah')">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                <span>Tambah Siswa</span>
            </button>
            <button type="button" class="btn btn-secondary" onclick="openModal('modal-import')">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
                <span>Import CSV</span>
            </button>
            <a href="<?= base_url('operator?page=siswa_crud&action=download_template') ?>" class="btn btn-outline">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                <span>Unduh Template</span>
            </a>
        </div>
    </div>

    <!-- Filter & Pencarian -->
    <div class="card" style="padding: 1rem 1.25rem;">
        <form method="GET" action="<?= base_url('operator') ?>" class="filter-form-responsive">
            <input type="hidden" name="page" value="siswa_crud">
            <input type="text" name="search" class="form-control" placeholder="Cari NIS, Username, atau Nama Siswa..." value="<?= sanitize($search) ?>">
            <div class="filter-row">
                <select name="filter_kelas" class="form-control">
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

    <!-- Data Table Siswa (Auto-Card on Mobile) -->
    <div class="card">
        <div class="table-responsive table-mobile-cards">
            <table class="table">
                <thead>
                    <tr>
                        <th style="width: 50px;">No</th>
                        <th>NIS</th>
                        <th>Username</th>
                        <th>Nama Lengkap</th>
                        <th>Kelas</th>
                        <th>Status Sesi</th>
                        <th style="width: 180px; text-align: center;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($siswaList)): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted" style="padding: 2rem;">Tidak ada data siswa yang ditemukan.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($siswaList as $idx => $s): ?>
                            <tr>
                                <td data-label="No"><?= $idx + 1 ?></td>
                                <td data-label="NIS"><span class="badge" style="background:#e0f2fe; color:#0369a1; font-family:monospace; font-size:0.85rem; font-weight:700;"><?= sanitize($s['nis'] ?? '-') ?></span></td>
                                <td data-label="Username"><strong><?= sanitize($s['username']) ?></strong></td>
                                <td data-label="Nama Lengkap"><?= sanitize($s['nama_lengkap']) ?></td>
                                <td data-label="Kelas"><?= sanitize($s['nama_kelas'] ?? 'Belum ada') ?></td>
                                <td data-label="Status Sesi">
                                    <?php if ($s['status_login'] === 'online'): ?>
                                        <span class="badge badge-online">Online</span>
                                    <?php else: ?>
                                        <span class="badge badge-offline">Offline</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Aksi">
                                    <div class="flex gap-2">
                                        <button type="button" class="btn btn-sm btn-outline" 
                                            onclick='openEditModal(<?= json_encode($s) ?>)'>Edit</button>
                                        
                                        <form action="<?= base_url('operator?page=siswa_crud') ?>" method="POST" data-confirm="Yakin ingin menghapus data siswa <?= sanitize($s['nama_lengkap']) ?>?" data-confirm-title="Hapus Data Siswa" data-confirm-type="danger" data-confirm-btn="Ya, Hapus">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="hapus">
                                            <input type="hidden" name="id_user" value="<?= $s['id_user'] ?>">
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
</main>

<!-- Modal Tambah Siswa -->
<div id="modal-tambah" class="modal-overlay">
    <div class="modal-box">
        <h2 class="card-title mb-3">Tambah Siswa Baru</h2>
        <form action="<?= base_url('operator?page=siswa_crud') ?>" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="tambah">

            <div class="form-group">
                <label>Nomor Induk Siswa (NIS)</label>
                <input type="text" name="nis" class="form-control" required placeholder="Contoh: 2024001">
            </div>
            <div class="form-group">
                <label>Username Akun</label>
                <input type="text" name="username" class="form-control" required placeholder="Contoh: siswa_ahmad">
            </div>
            <div class="form-group">
                <label>Nama Lengkap</label>
                <input type="text" name="nama_lengkap" class="form-control" required placeholder="Nama lengkap siswa">
            </div>
            <div class="form-group">
                <label>Kata Sandi (Password)</label>
                <input type="password" name="password" class="form-control" required placeholder="Minimal 6 karakter">
            </div>
            <div class="form-group">
                <label>Kelas</label>
                <select name="id_kelas" class="form-control" required>
                    <option value="">Pilih Kelas</option>
                    <?php foreach ($kelasList as $k): ?>
                        <option value="<?= $k['id_kelas'] ?>"><?= sanitize($k['nama_kelas']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="flex gap-2 mt-4" style="justify-content: flex-end;">
                <button type="button" class="btn btn-outline" onclick="closeModal('modal-tambah')">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Data</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Edit Siswa -->
<div id="modal-edit" class="modal-overlay">
    <div class="modal-box">
        <h2 class="card-title mb-3">Edit Data Siswa</h2>
        <form action="<?= base_url('operator?page=siswa_crud') ?>" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit">
            <input type="hidden" id="edit-id-user" name="id_user" value="">

            <div class="form-group">
                <label>Nomor Induk Siswa (NIS)</label>
                <input type="text" id="edit-nis" name="nis" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Username</label>
                <input type="text" id="edit-username" name="username" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Nama Lengkap</label>
                <input type="text" id="edit-nama" name="nama_lengkap" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Ganti Kata Sandi (Kosongkan jika tidak diubah)</label>
                <input type="password" name="password" class="form-control" placeholder="Kata sandi baru...">
            </div>
            <div class="form-group">
                <label>Kelas</label>
                <select id="edit-kelas" name="id_kelas" class="form-control" required>
                    <option value="">Pilih Kelas</option>
                    <?php foreach ($kelasList as $k): ?>
                        <option value="<?= $k['id_kelas'] ?>"><?= sanitize($k['nama_kelas']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="flex gap-2 mt-4" style="justify-content: flex-end;">
                <button type="button" class="btn btn-outline" onclick="closeModal('modal-edit')">Batal</button>
                <button type="submit" class="btn btn-primary">Perbarui Siswa</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Import CSV -->
<div id="modal-import" class="modal-overlay">
    <div class="modal-box">
        <h2 class="card-title mb-2">Import Data Siswa via CSV</h2>
        <p class="text-sm text-muted mb-3">Format kolom CSV: <code>nis, username, nama_lengkap, password, nama_kelas</code></p>
        
        <form action="<?= base_url('operator?page=siswa_crud') ?>" method="POST" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="import_csv">

            <div class="form-group">
                <label>Pilih File CSV</label>
                <input type="file" name="csv_file" class="form-control" accept=".csv" required>
            </div>

            <div class="flex gap-2 mt-4" style="justify-content: flex-end;">
                <button type="button" class="btn btn-outline" onclick="closeModal('modal-import')">Batal</button>
                <button type="submit" class="btn btn-primary">Proses Import</button>
            </div>
        </form>
    </div>
</div>

<?php
$extraJs = '
<script>
function openEditModal(data) {
    document.getElementById("edit-id-user").value = data.id_user;
    document.getElementById("edit-nis").value = data.nis || "";
    document.getElementById("edit-username").value = data.username;
    document.getElementById("edit-nama").value = data.nama_lengkap;
    document.getElementById("edit-kelas").value = data.id_kelas || "";
    openModal("modal-edit");
}
</script>
';

include __DIR__ . '/../layouts/footer.php';
