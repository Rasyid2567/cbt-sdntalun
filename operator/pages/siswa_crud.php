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
    fputcsv($output, ['nis', 'username', 'nama_lengkap', 'password', 'nama_kelas', 'no_hp', 'orang_tua', 'no_hp_ortu']);
    fputcsv($output, ['2024001', 'siswa1', 'Budi Pratama', 'siswa123', 'Kelas 6', '081234567890', 'Ahmad Santoso', '081298765432']);
    fputcsv($output, ['2024002', 'siswa2', 'Dewi Lestari', 'siswa123', 'Kelas 6', '081234567891', 'Siti Rahayu', '081298765433']);
    fputcsv($output, ['2024003', 'siswa3', 'Rian Hidayat', 'siswa123', 'Kelas 5', '', 'Bambang Sudiro', '081298765434']);
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
        $nis        = trim($_POST['nis'] ?? '');
        $username   = trim($_POST['username'] ?? '');
        $nama       = trim($_POST['nama_lengkap'] ?? '');
        $password   = trim($_POST['password'] ?? '');
        $id_kelas   = !empty($_POST['id_kelas']) ? (int)$_POST['id_kelas'] : null;
        $no_hp      = trim($_POST['no_hp'] ?? '');
        $orang_tua  = trim($_POST['orang_tua'] ?? '');
        $no_hp_ortu = trim($_POST['no_hp_ortu'] ?? '');

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
                $status_akun = in_array($_POST['status_akun'] ?? 'aktif', ['aktif', 'nonaktif'], true) ? $_POST['status_akun'] : 'aktif';
                $ins = $db->prepare("
                    INSERT INTO users (nis, username, password, nama_lengkap, role, id_kelas, status_login, status_akun, no_hp, orang_tua, no_hp_ortu) 
                    VALUES (:nis, :u, :p, :n, 'siswa', :k, 'offline', :sa, :hp, :ot, :hpot)
                ");
                $ins->execute([
                    ':nis'  => $nis,
                    ':u'    => $username,
                    ':p'    => $hash,
                    ':n'    => $nama,
                    ':k'    => $id_kelas,
                    ':sa'   => $status_akun,
                    ':hp'   => ($no_hp !== '' ? $no_hp : null),
                    ':ot'   => ($orang_tua !== '' ? $orang_tua : null),
                    ':hpot' => ($no_hp_ortu !== '' ? $no_hp_ortu : null)
                ]);
                flash_set('success', 'Data siswa berhasil ditambahkan.');
            }
        }
        redirect(base_url('operator?page=siswa_crud'));
    }

    // EDIT SISWA
    if ($action === 'edit') {
        $id_user    = (int)($_POST['id_user'] ?? 0);
        $nis        = trim($_POST['nis'] ?? '');
        $username   = trim($_POST['username'] ?? '');
        $nama       = trim($_POST['nama_lengkap'] ?? '');
        $password   = trim($_POST['password'] ?? '');
        $id_kelas   = !empty($_POST['id_kelas']) ? (int)$_POST['id_kelas'] : null;
        $no_hp      = trim($_POST['no_hp'] ?? '');
        $orang_tua  = trim($_POST['orang_tua'] ?? '');
        $no_hp_ortu = trim($_POST['no_hp_ortu'] ?? '');

        if ($id_user <= 0 || $username === '' || $nama === '' || $nis === '') {
            flash_set('danger', 'Data tidak valid. NIS, Username, dan Nama wajib diisi.');
        } else {
            // Cek duplikasi username atau nis untuk user lain
            $cek = $db->prepare("SELECT id_user FROM users WHERE (username = :u OR (nis = :nis AND nis IS NOT NULL)) AND id_user != :id");
            $cek->execute([':u' => $username, ':nis' => $nis, ':id' => $id_user]);
            if ($cek->fetch()) {
                flash_set('danger', "NIS atau Username sudah digunakan oleh akun siswa lain.");
            } else {
                $status_akun = in_array($_POST['status_akun'] ?? 'aktif', ['aktif', 'nonaktif'], true) ? $_POST['status_akun'] : 'aktif';
                if ($password !== '') {
                    $hash = password_hash($password, PASSWORD_BCRYPT);
                    $upd = $db->prepare("
                        UPDATE users 
                        SET nis = :nis, username = :u, password = :p, nama_lengkap = :n, id_kelas = :k, status_akun = :sa,
                            no_hp = :hp, orang_tua = :ot, no_hp_ortu = :hpot
                        WHERE id_user = :id AND role = 'siswa'
                    ");
                    $upd->execute([
                        ':nis'  => $nis,
                        ':u'    => $username,
                        ':p'    => $hash,
                        ':n'    => $nama,
                        ':k'    => $id_kelas,
                        ':sa'   => $status_akun,
                        ':hp'   => ($no_hp !== '' ? $no_hp : null),
                        ':ot'   => ($orang_tua !== '' ? $orang_tua : null),
                        ':hpot' => ($no_hp_ortu !== '' ? $no_hp_ortu : null),
                        ':id'   => $id_user
                    ]);
                } else {
                    $upd = $db->prepare("
                        UPDATE users 
                        SET nis = :nis, username = :u, nama_lengkap = :n, id_kelas = :k, status_akun = :sa,
                            no_hp = :hp, orang_tua = :ot, no_hp_ortu = :hpot
                        WHERE id_user = :id AND role = 'siswa'
                    ");
                    $upd->execute([
                        ':nis'  => $nis,
                        ':u'    => $username,
                        ':n'    => $nama,
                        ':k'    => $id_kelas,
                        ':sa'   => $status_akun,
                        ':hp'   => ($no_hp !== '' ? $no_hp : null),
                        ':ot'   => ($orang_tua !== '' ? $orang_tua : null),
                        ':hpot' => ($no_hp_ortu !== '' ? $no_hp_ortu : null),
                        ':id'   => $id_user
                    ]);
                }
                if ($status_akun === 'nonaktif') {
                    $db->prepare("UPDATE users SET status_login = 'offline' WHERE id_user = :id")->execute([':id' => $id_user]);
                }
                flash_set('success', 'Data siswa berhasil diperbarui.');
            }
        }
        redirect(base_url('operator?page=siswa_crud'));
    }

    // TOGGLE STATUS AKUN SISWA (AKTIF / NONAKTIF)
    if ($action === 'toggle_status') {
        $id_user = (int)($_POST['id_user'] ?? 0);
        if ($id_user > 0) {
            $stmt = $db->prepare("SELECT id_user, nama_lengkap, status_akun FROM users WHERE id_user = :id AND role = 'siswa'");
            $stmt->execute([':id' => $id_user]);
            $target = $stmt->fetch();
            if ($target) {
                $cur = $target['status_akun'] ?? 'aktif';
                $newStatus = ($cur === 'nonaktif') ? 'aktif' : 'nonaktif';
                $upd = $db->prepare("UPDATE users SET status_akun = :s WHERE id_user = :id");
                $upd->execute([':s' => $newStatus, ':id' => $id_user]);

                if ($newStatus === 'nonaktif') {
                    $db->prepare("UPDATE users SET status_login = 'offline' WHERE id_user = :id")->execute([':id' => $id_user]);
                    flash_set('warning', "Akun siswa '{$target['nama_lengkap']}' berhasil dinonaktifkan.");
                } else {
                    flash_set('success', "Akun siswa '{$target['nama_lengkap']}' berhasil diaktifkan kembali.");
                }
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
                    $noHp   = trim($row[5] ?? '');
                    $ortu   = trim($row[6] ?? '');
                    $noHpO  = trim($row[7] ?? '');

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
                            INSERT INTO users (nis, username, password, nama_lengkap, role, id_kelas, status_login, no_hp, orang_tua, no_hp_ortu)
                            VALUES (:nis, :u, :p, :n, 'siswa', :k, 'offline', :hp, :ot, :hpot)
                            ON CONFLICT (username) DO UPDATE 
                            SET nis = EXCLUDED.nis,
                                nama_lengkap = EXCLUDED.nama_lengkap,
                                id_kelas = COALESCE(EXCLUDED.id_kelas, users.id_kelas),
                                no_hp = COALESCE(EXCLUDED.no_hp, users.no_hp),
                                orang_tua = COALESCE(EXCLUDED.orang_tua, users.orang_tua),
                                no_hp_ortu = COALESCE(EXCLUDED.no_hp_ortu, users.no_hp_ortu)
                        ");
                        $stmtIns->execute([
                            ':nis'  => $nis,
                            ':u'    => $uUser,
                            ':p'    => $pwdHash,
                            ':n'    => $nama,
                            ':k'    => $targetKelasId,
                            ':hp'   => ($noHp !== '' ? $noHp : null),
                            ':ot'   => ($ortu !== '' ? $ortu : null),
                            ':hpot' => ($noHpO !== '' ? $noHpO : null)
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
    SELECT u.id_user, u.nis, u.username, u.nama_lengkap, u.status_login, COALESCE(u.status_akun, 'aktif') AS status_akun, 
           u.id_kelas, k.nama_kelas, u.no_hp, u.orang_tua, u.no_hp_ortu
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
    $sql .= " AND (u.username ILIKE :q OR u.nis ILIKE :q OR u.nama_lengkap ILIKE :q OR u.orang_tua ILIKE :q OR u.no_hp ILIKE :q OR u.no_hp_ortu ILIKE :q)";
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
    <div class="card" style="padding: 1rem 1.25rem;">
        <div class="table-responsive table-mobile-cards">
            <table class="table" style="font-size: 0.88rem;">
                <thead>
                    <tr>
                        <th style="width: 40px; text-align: center; white-space: nowrap;">No</th>
                        <th style="white-space: nowrap;">NIS</th>
                        <th style="white-space: nowrap;">Username</th>
                        <th style="white-space: nowrap; min-width: 170px;">Nama Lengkap</th>
                        <th style="white-space: nowrap;">Kelas</th>
                        <th style="white-space: nowrap;">No. HP</th>
                        <th style="white-space: nowrap;">Orang Tua</th>
                        <th style="white-space: nowrap;">No. HP Ortu</th>
                        <th style="text-align: center; white-space: nowrap;">Status</th>
                        <th style="text-align: center; white-space: nowrap;">Sesi</th>
                        <th style="text-align: center; width: 185px; white-space: nowrap;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($siswaList)): ?>
                        <tr>
                            <td colspan="11" class="text-center text-muted" style="padding: 2rem;">Tidak ada data siswa yang ditemukan.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($siswaList as $idx => $s): ?>
                            <tr>
                                <td data-label="No" style="text-align: center;"><?= $idx + 1 ?></td>
                                <td data-label="NIS" style="white-space: nowrap;"><span class="badge" style="background:#e0f2fe; color:#0369a1; font-family:monospace; font-size:0.85rem; font-weight:700;"><?= sanitize($s['nis'] ?? '-') ?></span></td>
                                <td data-label="Username" style="white-space: nowrap;"><strong><?= sanitize($s['username']) ?></strong></td>
                                <td data-label="Nama Lengkap"><?= sanitize($s['nama_lengkap']) ?></td>
                                <td data-label="Kelas" style="white-space: nowrap;"><?= sanitize($s['nama_kelas'] ?? 'Belum ada') ?></td>
                                <td data-label="No. HP" style="white-space: nowrap;">
                                    <?php if (!empty($s['no_hp'])): ?>
                                        <a href="tel:<?= sanitize($s['no_hp']) ?>" class="badge" style="background:#ecfdf5; color:#047857; font-family:monospace; text-decoration:none;">
                                            <?= sanitize($s['no_hp']) ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted text-xs">-</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Orang Tua" style="white-space: nowrap;"><?= sanitize($s['orang_tua'] ?? '-') ?></td>
                                <td data-label="No. HP Ortu" style="white-space: nowrap;">
                                    <?php if (!empty($s['no_hp_ortu'])): ?>
                                        <a href="tel:<?= sanitize($s['no_hp_ortu']) ?>" class="badge" style="background:#eff6ff; color:#1d4ed8; font-family:monospace; text-decoration:none;">
                                            <?= sanitize($s['no_hp_ortu']) ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted text-xs">-</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Status Akun" style="text-align: center; white-space: nowrap;">
                                    <?php if (($s['status_akun'] ?? 'aktif') === 'aktif'): ?>
                                        <span class="badge" style="background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; font-weight: 700;">Aktif</span>
                                    <?php else: ?>
                                        <span class="badge" style="background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; font-weight: 700;">Nonaktif</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Status Sesi" style="text-align: center; white-space: nowrap;">
                                    <?php if ($s['status_login'] === 'online'): ?>
                                        <span class="badge badge-online">Online</span>
                                    <?php else: ?>
                                        <span class="badge badge-offline">Offline</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Aksi" style="text-align: center; white-space: nowrap;">
                                    <div class="flex" style="gap: 0.35rem; justify-content: center; align-items: center; flex-wrap: nowrap;">
                                        <!-- Tombol Toggle Status Akun -->
                                        <form action="<?= base_url('operator?page=siswa_crud') ?>" method="POST" style="display:inline-flex; margin:0;"
                                              data-confirm="<?= ($s['status_akun'] ?? 'aktif') === 'aktif' ? 'Nonaktifkan akun siswa ' . sanitize(addslashes($s['nama_lengkap'])) . '? Akun ini tidak akan dapat login ke CBT.' : 'Aktifkan kembali akun siswa ' . sanitize(addslashes($s['nama_lengkap'])) . '?' ?>"
                                              data-confirm-title="<?= ($s['status_akun'] ?? 'aktif') === 'aktif' ? 'Nonaktifkan Akun' : 'Aktifkan Akun' ?>"
                                              data-confirm-type="<?= ($s['status_akun'] ?? 'aktif') === 'aktif' ? 'warning' : 'info' ?>"
                                              data-confirm-btn="<?= ($s['status_akun'] ?? 'aktif') === 'aktif' ? 'Ya, Nonaktifkan' : 'Ya, Aktifkan' ?>">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="id_user" value="<?= $s['id_user'] ?>">
                                            <?php if (($s['status_akun'] ?? 'aktif') === 'aktif'): ?>
                                                <button type="submit" class="btn btn-sm" style="background: #fff7ed; color: #c2410c; border: 1px solid #fed7aa; padding: 0.25rem 0.5rem; font-size: 0.78rem; font-weight: 600; white-space: nowrap;" title="Nonaktifkan Akun Siswa">
                                                    Nonaktifkan
                                                </button>
                                            <?php else: ?>
                                                <button type="submit" class="btn btn-sm" style="background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; padding: 0.25rem 0.5rem; font-size: 0.78rem; font-weight: 600; white-space: nowrap;" title="Aktifkan Akun Siswa">
                                                    Aktifkan
                                                </button>
                                            <?php endif; ?>
                                        </form>

                                        <button type="button" class="btn btn-sm btn-outline" style="padding: 0.25rem 0.5rem; font-size: 0.78rem; font-weight: 600; white-space: nowrap;"
                                            onclick='openEditModal(<?= json_encode($s) ?>)'>Edit</button>
                                        
                                        <form action="<?= base_url('operator?page=siswa_crud') ?>" method="POST" style="display:inline-flex; margin:0;" data-confirm="Yakin ingin menghapus data siswa <?= sanitize($s['nama_lengkap']) ?>?" data-confirm-title="Hapus Data Siswa" data-confirm-type="danger" data-confirm-btn="Ya, Hapus">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="hapus">
                                            <input type="hidden" name="id_user" value="<?= $s['id_user'] ?>">
                                            <button type="submit" class="btn btn-sm btn-danger" style="padding: 0.25rem 0.5rem; font-size: 0.78rem; font-weight: 600; white-space: nowrap;">Hapus</button>
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
            <div class="form-group">
                <label>No. HP Siswa</label>
                <input type="text" name="no_hp" class="form-control" placeholder="Contoh: 081234567890 (Opsional)">
            </div>
            <div class="form-group">
                <label>Nama Orang Tua / Wali</label>
                <input type="text" name="orang_tua" class="form-control" placeholder="Nama ayah / ibu / wali murid (Opsional)">
            </div>
            <div class="form-group">
                <label>No. HP Orang Tua / Wali</label>
                <input type="text" name="no_hp_ortu" class="form-control" placeholder="Contoh: 081298765432 (Opsional)">
            </div>
            <div class="form-group">
                <label>Status Akun</label>
                <select name="status_akun" class="form-control" required>
                    <option value="aktif" selected>Aktif (Dapat Login)</option>
                    <option value="nonaktif">Nonaktif (Dilarang Login)</option>
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
            <div class="form-group">
                <label>No. HP Siswa</label>
                <input type="text" id="edit-no_hp" name="no_hp" class="form-control" placeholder="Nomor HP siswa (Opsional)">
            </div>
            <div class="form-group">
                <label>Nama Orang Tua / Wali</label>
                <input type="text" id="edit-orang_tua" name="orang_tua" class="form-control" placeholder="Nama orang tua/wali (Opsional)">
            </div>
            <div class="form-group">
                <label>No. HP Orang Tua / Wali</label>
                <input type="text" id="edit-no_hp_ortu" name="no_hp_ortu" class="form-control" placeholder="Nomor HP orang tua/wali (Opsional)">
            </div>
            <div class="form-group">
                <label>Status Akun</label>
                <select id="edit-status_akun" name="status_akun" class="form-control" required>
                    <option value="aktif">Aktif (Dapat Login)</option>
                    <option value="nonaktif">Nonaktif (Dilarang Login)</option>
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
        <p class="text-sm text-muted mb-3">Format kolom CSV: <code>nis, username, nama_lengkap, password, nama_kelas, no_hp, orang_tua, no_hp_ortu</code></p>
        
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
    document.getElementById("edit-no_hp").value = data.no_hp || "";
    document.getElementById("edit-orang_tua").value = data.orang_tua || "";
    document.getElementById("edit-no_hp_ortu").value = data.no_hp_ortu || "";
    document.getElementById("edit-status_akun").value = data.status_akun || "aktif";
    openModal("modal-edit");
}
</script>
';

include __DIR__ . '/../layouts/footer.php';
