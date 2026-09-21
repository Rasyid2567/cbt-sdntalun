<?php
/**
 * Modul Manajemen Sesi Ujian & Distribusi Token CBT Guru
 */

require_once __DIR__ . '/../../middleware/auth.php';

$currentUser = auth_check(['guru']);
$db = get_db();
$idGuru = $currentUser['id_user'];
$page = 'sesi_ujian';
$pageTitle = 'Sesi Ujian & Token';

// Helper Generate Token Random 5 Karakter Huruf Kapital
if (!function_exists('generate_token_cbt')) {
    function generate_token_cbt(): string {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $res = '';
        for ($i = 0; $i < 5; $i++) {
            $res .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $res;
    }
}

// Tangani Form POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash_set('danger', 'Validasi token keamanan (CSRF) gagal.');
        redirect(base_url('guru?page=sesi_ujian'));
    }

    $action = $_POST['action'] ?? '';

    // 1. BUAT SESI BARU
    if ($action === 'tambah_sesi') {
        $idPaket     = (int)($_POST['id_paket'] ?? 0);
        $idMapel     = (int)($_POST['id_mapel'] ?? 0);
        $idKelas     = !empty($_POST['id_kelas']) ? (int)$_POST['id_kelas'] : (!empty($currentUser['id_kelas']) ? (int)$currentUser['id_kelas'] : 0);
        $durasiMenit = (int)($_POST['durasi_menit'] ?? 60);

        if ($idKelas <= 0) {
            flash_set('danger', 'Silakan pilih kelas peserta ujian terlebih dahulu.');
            redirect(base_url('guru?page=sesi_ujian'));
        }

        // Validasi akses mapel
        if (!empty($currentUser['id_mapel']) && (int)$currentUser['id_mapel'] !== $idMapel) {
            flash_set('danger', 'Anda hanya dapat merilis sesi ujian untuk mata pelajaran yang Anda ampu.');
            redirect(base_url('guru?page=sesi_ujian'));
        }
        if (empty($currentUser['id_mapel'])) {
            $cekMapelLain = $db->prepare("SELECT id_user FROM users WHERE role = 'guru' AND id_mapel = :m AND status_akun = 'aktif' AND id_user != :g LIMIT 1");
            $cekMapelLain->execute([':m' => $idMapel, ':g' => $idGuru]);
            if ($cekMapelLain->fetch()) {
                flash_set('danger', 'Mata pelajaran ini dikelola khusus oleh Guru Mata Pelajaran.');
                redirect(base_url('guru?page=sesi_ujian'));
            }
        }
        $acakSoal    = !empty($_POST['acak_soal']) ? 'true' : 'false';
        $acakOpsi    = 'false';
        
        $customToken = strtoupper(trim($_POST['token_ujian'] ?? ''));
        $token       = preg_replace('/[^A-Z0-9]/', '', $customToken);
        if (strlen($token) < 3 || strlen($token) > 15) {
            $token = generate_token_cbt();
        }

        $namaUjian = '';
        if ($idPaket > 0) {
            $stmtP = $db->prepare("SELECT * FROM paket_soal WHERE id_paket = :id AND id_guru = :g");
            $stmtP->execute([':id' => $idPaket, ':g' => $idGuru]);
            $paket = $stmtP->fetch();
            if ($paket) {
                $idMapel = (int)$paket['id_mapel'];
                $namaUjian = $paket['nama_paket'];
            }
        }

        if ($idKelas <= 0) {
            $idKelas = 6;
        }

        if ($idPaket <= 0 || $idMapel <= 0 || $durasiMenit <= 0 || $namaUjian === '') {
            flash_set('danger', 'Silakan pilih paket soal yang ingin diujikan.');
        } else {
            $stmtIns = $db->prepare("
                INSERT INTO sesi_ujian (id_guru, id_mapel, id_kelas, id_paket, nama_ujian, token_ujian, durasi_menit, acak_soal, acak_opsi, status)
                VALUES (:g, :m, :k, :p, :n, :t, :d, :as::boolean, :ao::boolean, 'aktif')
            ");
            $stmtIns->execute([
                ':g'  => $idGuru,
                ':m'  => $idMapel,
                ':k'  => $idKelas,
                ':p'  => $idPaket,
                ':n'  => $namaUjian,
                ':t'  => $token,
                ':d'  => $durasiMenit,
                ':as' => $acakSoal,
                ':ao' => $acakOpsi
            ]);
            flash_set('success', "Sesi ujian '{$namaUjian}' berhasil dibuat dan aktif dengan Token: {$token}");
        }
        redirect(base_url('guru?page=sesi_ujian'));
    }

    // 2. REFRESH / GENERATE ULANG TOKEN
    if ($action === 'refresh_token') {
        $idSesi = (int)($_POST['id_sesi'] ?? 0);
        $newToken = generate_token_cbt();

        $upd = $db->prepare("UPDATE sesi_ujian SET token_ujian = :t WHERE id_sesi = :id AND id_guru = :g");
        $upd->execute([':t' => $newToken, ':id' => $idSesi, ':g' => $idGuru]);
        flash_set('info', "Token ujian berhasil diperbarui menjadi: {$newToken}");
        redirect(base_url('guru?page=sesi_ujian'));
    }

    // 2B. EDIT TOKEN SECARA CUSTOM
    if ($action === 'edit_token') {
        $idSesi = (int)($_POST['id_sesi'] ?? 0);
        $customToken = strtoupper(trim($_POST['token_ujian'] ?? ''));
        $token = preg_replace('/[^A-Z0-9]/', '', $customToken);
        if ($token === '') {
            $token = generate_token_cbt();
        }

        if (strlen($token) >= 3 && strlen($token) <= 15) {
            $upd = $db->prepare("UPDATE sesi_ujian SET token_ujian = :t WHERE id_sesi = :id AND id_guru = :g");
            $upd->execute([':t' => $token, ':id' => $idSesi, ':g' => $idGuru]);
            flash_set('info', "Token ujian berhasil diubah menjadi: {$token}");
        } else {
            flash_set('danger', "Token harus berupa 3-15 karakter huruf/angka.");
        }
        redirect(base_url('guru?page=sesi_ujian'));
    }

    // 2C. TAMBAH WAKTU SESI UJIAN (CUSTOM)
    if ($action === 'tambah_waktu') {
        $idSesi      = (int)($_POST['id_sesi'] ?? 0);
        $tambahMenit = (int)($_POST['tambah_menit'] ?? 0);

        $res = tambah_durasi_sesi($idSesi, $tambahMenit, $idGuru);
        if ($res['success']) {
            flash_set('success', "Waktu ujian untuk sesi '" . sanitize($res['nama_ujian']) . "' berhasil ditambah sebanyak {$res['tambah_menit']} menit. Durasi total kini: {$res['durasi_baru']} menit.");
        } else {
            flash_set('danger', $res['message']);
        }
        redirect(base_url('guru?page=sesi_ujian'));
    }

    // 3. UBAH STATUS SESI
    if ($action === 'update_status') {
        $idSesi    = (int)($_POST['id_sesi'] ?? 0);
        $newStatus = $_POST['status'] ?? '';

        if (in_array($newStatus, ['aktif', 'nonaktif', 'selesai'], true)) {
            if ($newStatus === 'aktif') {
                $upd = $db->prepare("UPDATE sesi_ujian SET status = 'aktif', created_at = CURRENT_TIMESTAMP WHERE id_sesi = :id AND id_guru = :g");
                $upd->execute([':id' => $idSesi, ':g' => $idGuru]);
            } else {
                $upd = $db->prepare("UPDATE sesi_ujian SET status = :s::session_status WHERE id_sesi = :id AND id_guru = :g");
                $upd->execute([':s' => $newStatus, ':id' => $idSesi, ':g' => $idGuru]);

                // Otomatis tandai ujian siswa yang masih 'sedang' menjadi 'selesai' agar tidak menggantung
                $updStudents = $db->prepare("
                    UPDATE ujian_siswa 
                    SET status = 'selesai', 
                        waktu_selesai = COALESCE(waktu_selesai, CURRENT_TIMESTAMP) 
                    WHERE id_sesi = :id AND status = 'sedang'
                ");
                $updStudents->execute([':id' => $idSesi]);
            }
            flash_set('success', "Status sesi ujian berhasil diubah menjadi: {$newStatus}");
        }
        redirect(base_url('guru?page=sesi_ujian'));
    }

    // 4. HAPUS SESI
    if ($action === 'hapus_sesi') {
        $idSesi = (int)($_POST['id_sesi'] ?? 0);
        $del = $db->prepare("DELETE FROM sesi_ujian WHERE id_sesi = :id AND id_guru = :g");
        $del->execute([':id' => $idSesi, ':g' => $idGuru]);
        flash_set('danger', 'Sesi ujian berhasil dihapus.');
        redirect(base_url('guru?page=sesi_ujian'));
    }
}

// Data Kelas Guru & Seluruh Kelas
$idKelasGuru = !empty($currentUser['id_kelas']) ? (int)$currentUser['id_kelas'] : 0;
$namaKelasGuru = '';
if ($idKelasGuru > 0) {
    $stmtKelas = $db->prepare("SELECT nama_kelas FROM kelas WHERE id_kelas = :k");
    $stmtKelas->execute([':k' => $idKelasGuru]);
    $namaKelasGuru = $stmtKelas->fetchColumn() ?: '';
}
$kelasList = $db->query("SELECT id_kelas, nama_kelas FROM kelas ORDER BY nama_kelas ASC")->fetchAll();

// Ambil Paket Soal Guru
$stmtPaket = $db->prepare("
    SELECT p.id_paket, p.nama_paket, p.id_mapel, m.nama_mapel, m.kode_mapel,
           COUNT(b.id_soal) AS total_soal
    FROM paket_soal p
    JOIN mapel m ON p.id_mapel = m.id_mapel
    LEFT JOIN bank_soal b ON p.id_paket = b.id_paket
    WHERE p.id_guru = :g
    GROUP BY p.id_paket, p.nama_paket, p.id_mapel, m.nama_mapel, m.kode_mapel
    ORDER BY p.created_at DESC
");
$stmtPaket->execute([':g' => $idGuru]);
$paketList = $stmtPaket->fetchAll();

// Ambil Daftar Mapel untuk Pilihan di Modal Tambah Sesi
if (!empty($currentUser['id_mapel'])) {
    // Guru Mapel: hanya mapel yang diampunya
    $stmtMapelList = $db->prepare("SELECT id_mapel, nama_mapel, kode_mapel FROM mapel WHERE id_mapel = :m ORDER BY COALESCE(urutan, 0) ASC, nama_mapel ASC");
    $stmtMapelList->execute([':m' => (int)$currentUser['id_mapel']]);
} else {
    // Guru Kelas: sembunyikan mapel yang sedang memiliki Guru Mapel aktif
    $stmtMapelList = $db->prepare("
        SELECT m.id_mapel, m.nama_mapel, m.kode_mapel 
        FROM mapel m 
        WHERE m.id_mapel NOT IN (
            SELECT id_mapel FROM users 
            WHERE role = 'guru' AND id_mapel IS NOT NULL AND status_akun = 'aktif' AND id_user != :g
        )
        ORDER BY COALESCE(m.urutan, 0) ASC, m.nama_mapel ASC
    ");
    $stmtMapelList->execute([':g' => $idGuru]);
}
$mapelList = $stmtMapelList->fetchAll();

$paketCountPerMapel = [];
foreach ($paketList as $p) {
    $mid = (int)$p['id_mapel'];
    $paketCountPerMapel[$mid] = ($paketCountPerMapel[$mid] ?? 0) + 1;
}

// Ambil Seluruh Sesi Ujian Guru
$stmtSesi = $db->prepare("
    SELECT su.*, m.nama_mapel, k.nama_kelas, p.nama_paket,
           (SELECT COUNT(*) FROM bank_soal WHERE id_paket = su.id_paket) as total_soal,
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
");
$stmtSesi->execute([':g' => $idGuru]);
$sesiList = $stmtSesi->fetchAll();

$flash = flash_get();

include __DIR__ . '/../layouts/header.php';
?>

<main class="container">
    <div class="card-header mb-4">
        <div>
            <h1 class="card-title">Sesi Ujian & Token</h1>
            <p class="text-sm text-muted">Kelola sesi ujian aktif, rilis token ujian, dan pantau status peserta.</p>
        </div>
        <div class="card-header-actions">
            <button class="btn btn-primary" onclick="openModalTambahSesi()">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                <span>Buat Sesi Ujian</span>
            </button>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?= sanitize($flash['type']) ?>">
            <?= sanitize($flash['message']) ?>
        </div>
    <?php endif; ?>

    <!-- DAFTAR SESI UJIAN -->
    <div class="card">
        <?php if (empty($sesiList)): ?>
            <p class="text-center text-muted" style="padding: 2.5rem 0;">Belum ada sesi ujian yang dibuat. Klik tombol "+ Buat Sesi Ujian" untuk memulai.</p>
        <?php else: ?>
            <div class="table-responsive table-mobile-cards">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Mapel & Kelas</th>
                            <th>Nama Ujian</th>
                            <th style="text-align: center;">Token Ujian</th>
                            <th style="white-space: nowrap;">Sisa Waktu</th>
                            <th>Acak</th>
                            <th>Status</th>
                            <th>Peserta</th>
                            <th style="text-align: center; white-space: nowrap;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sesiList as $s): ?>
                            <tr>
                                <td data-label="Mapel & Kelas">
                                    <strong><?= sanitize($s['nama_mapel']) ?></strong>
                                    <div class="text-xs text-muted">Kelas: <?= sanitize($s['nama_kelas']) ?></div>
                                </td>
                                <td data-label="Nama Ujian">
                                    <div><?= sanitize($s['nama_paket'] ?: $s['nama_ujian']) ?></div>
                                    <?php if (!empty($s['total_soal'])): ?>
                                        <div class="text-xs text-muted"><?= (int)$s['total_soal'] ?> Butir Soal</div>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Token" style="text-align: center;">
                                    <div style="display: inline-flex; align-items: center; gap: 0.35rem; background: #e0e7ff; padding: 0.35rem 0.65rem; border-radius: 6px;">
                                        <span style="font-family: monospace; font-size: 1.15rem; font-weight: 800; color: #1e40af; letter-spacing: 1.5px;">
                                            <?= sanitize($s['token_ujian']) ?>
                                        </span>
                                        <button type="button" class="btn btn-sm btn-outline" title="Ubah Token / Custom" style="padding: 0.15rem 0.45rem; font-size: 0.78rem;" onclick="openModalEditToken(<?= $s['id_sesi'] ?>, '<?= sanitize($s['token_ujian']) ?>', '<?= sanitize(addslashes($s['nama_paket'] ?: $s['nama_ujian'])) ?>')">
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                        </button>
                                        <form action="<?= base_url('guru?page=sesi_ujian') ?>" method="POST" style="display:inline;" data-confirm="Generate ulang token ujian ini secara acak?" data-confirm-title="Perbarui Token Ujian" data-confirm-type="warning" data-confirm-btn="Generate Acak">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="refresh_token">
                                            <input type="hidden" name="id_sesi" value="<?= $s['id_sesi'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline" title="Generate Acak Baru" style="padding: 0.15rem 0.45rem; font-size: 0.78rem;">↻</button>
                                        </form>
                                    </div>
                                </td>
                                <td data-label="Sisa Waktu" style="white-space: nowrap;">
                                    <?php if ($s['status'] === 'aktif'): ?>
                                        <div style="display: inline-flex; align-items: center; gap: 0.35rem; flex-wrap: nowrap; white-space: nowrap;">
                                            <?php if ($s['sisa_detik_sesi'] > 0): ?>
                                                <span class="badge" style="background: #eff6ff; color: #1d4ed8; font-family: monospace; font-size: 0.92rem; font-weight: 700; padding: 0.3rem 0.6rem; border: 1px solid #bfdbfe; letter-spacing: 0.5px; display: inline-flex; align-items: center; gap: 0.35rem;">
                                                    <span class="countdown-timer" data-seconds="<?= (int)$s['sisa_detik_sesi'] ?>">--:--:--</span>
                                                </span>
                                            <?php else: ?>
                                                <span class="badge badge-offline">Waktu Habis</span>
                                            <?php endif; ?>
                                            <button type="button" class="btn btn-sm btn-warning" style="padding: 0.22rem 0.45rem; font-size: 0.8rem; font-weight: 800; display: inline-flex; align-items: center; gap: 0.2rem; white-space: nowrap; border-radius: 6px; line-height: 1;" title="Tambah Waktu Sesi Ini" onclick="openModalTambahWaktu(<?= $s['id_sesi'] ?>, '<?= sanitize(addslashes($s['nama_paket'] ?: $s['nama_ujian'])) ?>', <?= (int)$s['durasi_menit'] ?>, <?= (int)$s['sisa_detik_sesi'] ?>)">
                                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                                                <span style="font-size: 0.92rem; font-weight: 800; line-height: 1;">+</span>
                                            </button>
                                        </div>
                                    <?php elseif ($s['status'] === 'selesai'): ?>
                                        <span class="badge badge-selesai">Selesai</span>
                                    <?php else: ?>
                                        <span class="text-sm text-muted font-bold"><?= $s['durasi_menit'] ?> Menit (Jeda)</span>
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
                                    <a href="<?= base_url('guru?page=rekap_nilai&id_sesi=' . $s['id_sesi']) ?>" class="btn btn-sm btn-outline">
                                        <?= $s['total_peserta'] ?> Siswa
                                    </a>
                                </td>
                                <td data-label="Aksi" style="text-align: center; white-space: nowrap;">
                                    <div class="flex" style="display: inline-flex; align-items: center; justify-content: center; gap: 0.35rem; flex-wrap: nowrap;">
                                        <form action="<?= base_url('guru?page=sesi_ujian') ?>" method="POST" style="display: inline-block; margin: 0;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="update_status">
                                            <input type="hidden" name="id_sesi" value="<?= $s['id_sesi'] ?>">
                                            <?php if ($s['status'] === 'aktif'): ?>
                                                <input type="hidden" name="status" value="nonaktif">
                                                <button type="submit" class="btn btn-sm btn-secondary" style="white-space: nowrap; padding: 0.32rem 0.65rem;">Nonaktifkan</button>
                                            <?php else: ?>
                                                <input type="hidden" name="status" value="aktif">
                                                <button type="submit" class="btn btn-sm btn-success" style="white-space: nowrap; padding: 0.32rem 0.65rem;">Aktifkan</button>
                                            <?php endif; ?>
                                        </form>

                                        <form action="<?= base_url('guru?page=sesi_ujian') ?>" method="POST" style="display: inline-block; margin: 0;" data-confirm="Hapus sesi ujian ini beserta seluruh riwayat pengerjaan siswa?" data-confirm-title="Hapus Sesi Ujian" data-confirm-type="danger" data-confirm-btn="Ya, Hapus Sesi">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="hapus_sesi">
                                            <input type="hidden" name="id_sesi" value="<?= $s['id_sesi'] ?>">
                                            <button type="submit" class="btn btn-sm btn-danger" style="white-space: nowrap; padding: 0.32rem 0.65rem;">Hapus</button>
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

<!-- Modal Tambah Sesi Ujian -->
<div id="modal-tambah-sesi" class="modal-overlay">
    <div class="modal-box" style="max-width: 520px;">
        <h2 class="card-title mb-3">Rilis Sesi Ujian Baru</h2>

        <form action="<?= base_url('guru?page=sesi_ujian') ?>" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="tambah_sesi">
            <input type="hidden" name="id_mapel" id="hidden_id_mapel" value="">
            <input type="hidden" name="id_paket" id="hidden_id_paket" value="">

            <div class="form-group mb-3">
                <label for="select_kelas_ujian" style="font-weight: 700;">Pilih Kelas Peserta Ujian <span class="text-danger">*</span></label>
                <select id="select_kelas_ujian" name="id_kelas" class="form-control" required onchange="onPilihKelas(this)">
                    <?php if (!empty($currentUser['id_kelas'])): ?>
                        <option value="<?= (int)$currentUser['id_kelas'] ?>" selected><?= sanitize($namaKelasGuru) ?> (Kelas Anda)</option>
                        <optgroup label="Pilih Kelas Lain">
                            <?php foreach ($kelasList as $k): ?>
                                <?php if ((int)$k['id_kelas'] !== (int)$currentUser['id_kelas']): ?>
                                    <option value="<?= $k['id_kelas'] ?>"><?= sanitize($k['nama_kelas']) ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php else: ?>
                        <option value="">-- Pilih Kelas Peserta Ujian --</option>
                        <?php foreach ($kelasList as $k): ?>
                            <option value="<?= $k['id_kelas'] ?>"><?= sanitize($k['nama_kelas']) ?></option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
                <small class="text-muted text-xs">Pilih kelas siswa yang dapat mengakses dan mengerjakan sesi ujian ini.</small>
            </div>

            <div class="form-group mb-3">
                <label for="select_mapel_ujian" style="font-weight: 700;">Pilih Mata Pelajaran <span class="text-danger">*</span></label>
                <select id="select_mapel_ujian" class="form-control" required onchange="onPilihMapel(this)">
                    <option value="">-- Pilih Mata Pelajaran Terlebih Dahulu --</option>
                    <?php foreach ($mapelList as $m): ?>
                        <?php 
                        $mid = (int)$m['id_mapel'];
                        $pCount = $paketCountPerMapel[$mid] ?? 0;
                        $mapelLabel = sanitize($m['nama_mapel']);
                        if (!empty($m['kode_mapel']) && !str_contains($m['nama_mapel'], $m['kode_mapel'])) {
                            $mapelLabel .= ' (' . sanitize($m['kode_mapel']) . ')';
                        }
                        ?>
                        <option value="<?= $mid ?>">
                            <?= $mapelLabel ?><?= $pCount > 0 ? " — {$pCount} Paket Soal" : ' — (0 Paket)' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group mb-3">
                <label for="select_paket_soal" style="font-weight: 700;">Pilih Paket Soal <span class="text-danger">*</span></label>
                <select id="select_paket_soal" class="form-control" required onchange="onPilihPaket(this)" disabled>
                    <option value="">-- Pilih Mata Pelajaran Terlebih Dahulu --</option>
                </select>
                <div id="alert_paket_kosong" class="alert alert-warning mt-2" style="display: none; font-size: 0.85rem; padding: 0.65rem 0.9rem;">
                    Belum ada paket soal untuk mata pelajaran ini di Bank Soal. <a href="<?= base_url('guru?page=tambah_soal') ?>" style="font-weight: 700; text-decoration: underline;">Klik untuk membuat paket soal</a>.
                </div>
            </div>

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
                        <strong id="preview_kelas" style="color: #059669; font-size: 0.95rem;"><?= sanitize($namaKelasGuru ?: 'Pilih Kelas') ?></strong>
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label for="durasi_menit">Durasi Pengerjaan (Menit) <span class="text-danger">*</span></label>
                <input type="number" name="durasi_menit" id="durasi_menit" class="form-control" min="5" max="300" value="60" required>
            </div>

            <div class="form-group">
                <label for="input_token_tambah">Token Ujian <small class="text-muted">(Kosongkan jika ingin token acak otomatis)</small></label>
                <div style="display: flex; gap: 0.5rem;">
                    <input type="text" name="token_ujian" id="input_token_tambah" class="form-control" placeholder="Contoh: KLS6A" maxlength="15" style="text-transform: uppercase; font-family: monospace; font-weight: 700; letter-spacing: 1px;">
                    <button type="button" class="btn btn-outline" onclick="generateRandomTokenTambah()" title="Generate Token Acak">
                        Acak
                    </button>
                </div>
            </div>

            <div class="form-group" style="margin-top: 1rem;">
                <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                    <input type="checkbox" name="acak_soal" value="1" checked style="width: 18px; height: 18px;">
                    <span>Acak Urutan Butir Soal untuk Tiap Siswa</span>
                </label>
            </div>

            <div class="flex gap-2" style="justify-content: flex-end; margin-top: 1.5rem;">
                <button type="button" class="btn btn-outline" onclick="closeModal('modal-tambah-sesi')">Batal</button>
                <button type="submit" class="btn btn-primary" <?= empty($paketList) ? 'disabled' : '' ?>>Rilis & Aktifkan Sesi</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Edit Token Custom -->
<div id="modal-edit-token" class="modal-overlay">
    <div class="modal-box" style="max-width: 440px;">
        <h2 class="card-title mb-1">Ubah Token Ujian</h2>
        <p class="text-xs text-muted mb-3" id="edit_token_subtitle">Sesuaikan kode token ujian untuk peserta</p>

        <form action="<?= base_url('guru?page=sesi_ujian') ?>" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit_token">
            <input type="hidden" name="id_sesi" id="edit_token_id_sesi" value="">

            <div class="form-group">
                <label for="edit_input_token">Kode Token Baru (3 - 15 Karakter): <span class="text-danger">*</span></label>
                <div style="display: flex; gap: 0.5rem; margin-top: 0.35rem;">
                    <input type="text" name="token_ujian" id="edit_input_token" class="form-control" placeholder="Contoh: PASMTK" maxlength="15" required style="text-transform: uppercase; font-family: monospace; font-size: 1.15rem; font-weight: 800; letter-spacing: 1.5px;">
                    <button type="button" class="btn btn-outline" onclick="generateRandomTokenEdit()" title="Generate Acak">
                        Acak
                    </button>
                </div>
                <small class="text-muted" style="display: block; margin-top: 0.35rem;">Bisa berupa kata mudah (contoh: <code>IPA6A</code>, <code>PAS01</code>) atau kombinasi angka & huruf.</small>
            </div>

            <div class="flex gap-2" style="justify-content: flex-end; margin-top: 1.25rem;">
                <button type="button" class="btn btn-outline" onclick="closeModal('modal-edit-token')">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Token</button>
            </div>
        </form>
    </div>
</div>

<script>
function generateRandomToken(len = 5) {
    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    let res = '';
    for (let i = 0; i < len; i++) {
        res += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    return res;
}

function generateRandomTokenTambah() {
    const input = document.getElementById('input_token_tambah');
    if (input) {
        input.value = generateRandomToken(5);
        input.focus();
    }
}

function generateRandomTokenEdit() {
    const input = document.getElementById('edit_input_token');
    if (input) {
        input.value = generateRandomToken(5);
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

const daftarPaketGuru = <?= json_encode($paketList, JSON_UNESCAPED_UNICODE) ?>;

const guruDefaultMapel = <?= json_encode((int)($currentUser['id_mapel'] ?? 0)) ?>;

function openModalTambahSesi() {
    const selMapel = document.getElementById('select_mapel_ujian');
    if (selMapel) {
        if (guruDefaultMapel > 0) {
            selMapel.value = String(guruDefaultMapel);
        } else {
            selMapel.value = '';
        }
        if (typeof window.refreshCustomSelect === 'function') {
            window.refreshCustomSelect(selMapel);
        }
        onPilihMapel(selMapel);
    }
    const selKelas = document.getElementById('select_kelas_ujian');
    if (selKelas) {
        onPilihKelas(selKelas);
    }
    openModal('modal-tambah-sesi');
}

function onPilihKelas(sel) {
    const prevKelas = document.getElementById('preview_kelas');
    if (prevKelas && sel && sel.selectedIndex >= 0) {
        const text = sel.options[sel.selectedIndex]?.text || '';
        prevKelas.textContent = text.replace(' (Kelas Anda)', '').trim() || '-';
    }
}

function onPilihMapel(sel) {
    const idMapel = parseInt(sel.value, 10) || 0;
    const selectPaket = document.getElementById('select_paket_soal');
    const alertKosong = document.getElementById('alert_paket_kosong');
    const infoBox = document.getElementById('info_otomatis');
    const hidMapel = document.getElementById('hidden_id_mapel');
    const hidPaket = document.getElementById('hidden_id_paket');

    if (selectPaket) selectPaket.innerHTML = '';
    if (infoBox) infoBox.style.display = 'none';
    if (hidMapel) hidMapel.value = idMapel > 0 ? idMapel : '';
    if (hidPaket) hidPaket.value = '';

    if (idMapel <= 0) {
        if (selectPaket) {
            selectPaket.disabled = true;
            selectPaket.innerHTML = '<option value="">-- Pilih Mata Pelajaran Terlebih Dahulu --</option>';
        }
        if (alertKosong) alertKosong.style.display = 'none';
        if (typeof window.refreshCustomSelect === 'function') {
            window.refreshCustomSelect(selectPaket);
        }
        return;
    }

    const filtered = daftarPaketGuru.filter(p => parseInt(p.id_mapel, 10) === idMapel);

    if (filtered.length === 0) {
        if (selectPaket) {
            selectPaket.disabled = true;
            selectPaket.innerHTML = '<option value="">(Tidak ada paket soal untuk mapel ini)</option>';
        }
        if (alertKosong) alertKosong.style.display = 'block';
    } else {
        if (selectPaket) {
            selectPaket.disabled = false;
            let optHtml = '<option value="">-- Pilih Paket Soal yang Diujikan --</option>';
            filtered.forEach(p => {
                const payload = JSON.stringify({
                    id_paket: parseInt(p.id_paket, 10),
                    judul: p.nama_paket,
                    id_mapel: parseInt(p.id_mapel, 10),
                    nama_mapel: p.nama_mapel,
                    kode_mapel: p.kode_mapel || '',
                    total_soal: parseInt(p.total_soal, 10) || 0
                });
                const safePayload = payload.replace(/"/g, '&quot;');
                optHtml += `<option value="${safePayload}">${p.nama_paket} (${p.total_soal || 0} Soal)</option>`;
            });
            selectPaket.innerHTML = optHtml;
        }
        if (alertKosong) alertKosong.style.display = 'none';
    }

    if (typeof window.refreshCustomSelect === 'function') {
        window.refreshCustomSelect(selectPaket);
    }
}

function onPilihPaket(sel) {
    const infoBox = document.getElementById('info_otomatis');
    const hidMapel = document.getElementById('hidden_id_mapel');
    const hidPaket = document.getElementById('hidden_id_paket');
    const prevJudul = document.getElementById('preview_judul');
    const prevMapel = document.getElementById('preview_mapel');

    if (!sel.value) {
        infoBox.style.display = 'none';
        hidMapel.value = '';
        hidPaket.value = '';
        return;
    }

    try {
        const data = JSON.parse(sel.value);
        hidMapel.value = data.id_mapel;
        hidPaket.value = data.id_paket;
        prevJudul.textContent = data.judul;
        let mapelName = data.nama_mapel;
        if (data.kode_mapel && !mapelName.includes('(' + data.kode_mapel + ')')) {
            mapelName += ' (' + data.kode_mapel + ')';
        }
        prevMapel.textContent = mapelName;
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

<?php
$modalFormAction = base_url('guru?page=sesi_ujian');
include __DIR__ . '/../layouts/modal_tambah_waktu.php';
include __DIR__ . '/../layouts/footer.php';
?>
