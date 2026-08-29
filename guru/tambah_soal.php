<?php
/**
 * Modul Tambah & Edit Butir Soal (Dukungan Multi-Pertanyaan Dinamis)
 */

require_once __DIR__ . '/../middleware/auth.php';

$currentUser = auth_check(['guru']);
$db = get_db();
$idGuru = $currentUser['id_user'];

$editId = !empty($_GET['edit']) ? (int)$_GET['edit'] : 0;
$soalData = null;

if ($editId > 0) {
    $stmt = $db->prepare("SELECT * FROM bank_soal WHERE id_soal = :id AND id_guru = :g");
    $stmt->execute([':id' => $editId, ':g' => $idGuru]);
    $soalData = $stmt->fetch();
    if (!$soalData) {
        flash_set('danger', 'Soal tidak ditemukan atau bukan milik Anda.');
        redirect(base_url('guru/bank_soal.php'));
    }
}

// Proses Form POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash_set('danger', 'Validasi CSRF Token gagal.');
        redirect(base_url('guru/tambah_soal.php' . ($editId ? '?edit=' . $editId : '')));
    }

    $idMapel   = (int)($_POST['id_mapel'] ?? 0);
    $judulSoal = trim($_POST['judul_soal'] ?? '');
    if ($judulSoal === '') {
        $judulSoal = 'Asesmen Harian';
    }

    if ($idMapel <= 0) {
        flash_set('danger', 'Silakan pilih Mata Pelajaran terlebih dahulu.');
        redirect(base_url('guru/tambah_soal.php' . ($editId ? '?edit=' . $editId : '')));
    }

    // MODE 1: EDIT BUTIR SOAL TUNGGAL
    if ($editId > 0) {
        $pertanyaan   = trim($_POST['pertanyaan'] ?? '');
        $opsiA        = trim($_POST['opsi_a'] ?? '');
        $opsiB        = trim($_POST['opsi_b'] ?? '');
        $opsiC        = trim($_POST['opsi_c'] ?? '');
        $opsiD        = trim($_POST['opsi_d'] ?? '');
        $opsiE        = trim($_POST['opsi_e'] ?? '');
        $kunci        = strtoupper(trim($_POST['kunci_jawaban'] ?? ''));

        if ($pertanyaan === '' || $opsiA === '' || $opsiB === '' || $opsiC === '' || $opsiD === '' || $kunci === '') {
            flash_set('danger', 'Mohon lengkapi teks pertanyaan, opsi A-D, dan kunci jawaban.');
            redirect(base_url('guru/tambah_soal.php?edit=' . $editId));
        }

        $gambarPath = $soalData['gambar'] ?? null;

        // Upload Gambar
        if (isset($_FILES['gambar']) && $_FILES['gambar']['error'] === UPLOAD_ERR_OK) {
            $fileTmp  = $_FILES['gambar']['tmp_name'];
            $fileName = $_FILES['gambar']['name'];
            $fileSize = $_FILES['gambar']['size'];
            $ext      = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

            $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            if (in_array($ext, $allowedExts, true) && $fileSize <= 2 * 1024 * 1024) {
                $uploadDir = __DIR__ . '/../assets/uploads/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                $newFileName = 'soal_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                if (move_uploaded_file($fileTmp, $uploadDir . $newFileName)) {
                    if (!empty($soalData['gambar']) && file_exists(__DIR__ . '/../' . ltrim($soalData['gambar'], '/'))) {
                        unlink(__DIR__ . '/../' . ltrim($soalData['gambar'], '/'));
                    }
                    $gambarPath = 'assets/uploads/' . $newFileName;
                }
            }
        }

        if (isset($_POST['hapus_gambar']) && $_POST['hapus_gambar'] == '1') {
            if (!empty($soalData['gambar']) && file_exists(__DIR__ . '/../' . ltrim($soalData['gambar'], '/'))) {
                unlink(__DIR__ . '/../' . ltrim($soalData['gambar'], '/'));
            }
            $gambarPath = null;
        }

        $upd = $db->prepare("
            UPDATE bank_soal 
            SET id_mapel = :m, judul_soal = :j, pertanyaan = :p, gambar = :g, 
                opsi_a = :oa, opsi_b = :ob, opsi_c = :oc, opsi_d = :od, opsi_e = :oe, 
                kunci_jawaban = :k
            WHERE id_soal = :id AND id_guru = :guru
        ");
        $upd->execute([
            ':m'    => $idMapel,
            ':j'    => $judulSoal,
            ':p'    => $pertanyaan,
            ':g'    => $gambarPath,
            ':oa'   => $opsiA,
            ':ob'   => $opsiB,
            ':oc'   => $opsiC,
            ':od'   => $opsiD,
            ':oe'   => $opsiE !== '' ? $opsiE : null,
            ':k'    => $kunci,
            ':id'   => $editId,
            ':guru' => $idGuru
        ]);
        flash_set('success', 'Butir soal berhasil diperbarui.');
        redirect(base_url('guru/bank_soal.php?id_mapel=' . $idMapel));
    }

    // MODE 2: TAMBAH SATU ATAU BANYAK PERTANYAAN (MULTI-QUESTION INPUT)
    $soalItems = $_POST['soal'] ?? [];
    if (empty($soalItems) || !is_array($soalItems)) {
        flash_set('danger', 'Tidak ada butir pertanyaan yang diinput.');
        redirect(base_url('guru/tambah_soal.php'));
    }

    $insertedCount = 0;
    $db->beginTransaction();

    try {
        $ins = $db->prepare("
            INSERT INTO bank_soal (id_guru, id_mapel, judul_soal, pertanyaan, gambar, opsi_a, opsi_b, opsi_c, opsi_d, opsi_e, kunci_jawaban)
            VALUES (:guru, :m, :j, :p, :g, :oa, :ob, :oc, :od, :oe, :k)
        ");

        foreach ($soalItems as $idx => $item) {
            $pertanyaan   = trim($item['pertanyaan'] ?? '');
            $opsiA        = trim($item['opsi_a'] ?? '');
            $opsiB        = trim($item['opsi_b'] ?? '');
            $opsiC        = trim($item['opsi_c'] ?? '');
            $opsiD        = trim($item['opsi_d'] ?? '');
            $opsiE        = trim($item['opsi_e'] ?? '');
            $kunci        = strtoupper(trim($item['kunci_jawaban'] ?? ''));

            // Lewati jika pertanyaan kosong
            if ($pertanyaan === '' || $opsiA === '' || $opsiB === '' || $opsiC === '' || $opsiD === '' || $kunci === '') {
                continue;
            }

            // Upload gambar jika diunggah untuk pertanyaan ini
            $gambarPath = null;
            $fileKey = 'gambar_' . $idx;
            if (isset($_FILES[$fileKey]) && $_FILES[$fileKey]['error'] === UPLOAD_ERR_OK) {
                $fileTmp  = $_FILES[$fileKey]['tmp_name'];
                $fileName = $_FILES[$fileKey]['name'];
                $fileSize = $_FILES[$fileKey]['size'];
                $ext      = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

                $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                if (in_array($ext, $allowedExts, true) && $fileSize <= 2 * 1024 * 1024) {
                    $uploadDir = __DIR__ . '/../assets/uploads/';
                    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                    $newFileName = 'soal_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    if (move_uploaded_file($fileTmp, $uploadDir . $newFileName)) {
                        $gambarPath = 'assets/uploads/' . $newFileName;
                    }
                }
            }

            $ins->execute([
                ':guru' => $idGuru,
                ':m'    => $idMapel,
                ':j'    => $judulSoal,
                ':p'    => $pertanyaan,
                ':g'    => $gambarPath,
                ':oa'   => $opsiA,
                ':ob'   => $opsiB,
                ':oc'   => $opsiC,
                ':od'   => $opsiD,
                ':oe'   => $opsiE !== '' ? $opsiE : null,
                ':k'    => $kunci
            ]);
            $insertedCount++;
        }

        $db->commit();

        if ($insertedCount > 0) {
            flash_set('success', "Berhasil menyimpan {$insertedCount} butir pertanyaan ke dalam paket '{$judulSoal}'.");
        } else {
            flash_set('warning', 'Tidak ada pertanyaan valid yang disimpan. Pastikan narasi dan opsi terisi lengkap.');
        }
    } catch (Exception $e) {
        $db->rollBack();
        flash_set('danger', 'Gagal menyimpan pertanyaan: ' . $e->getMessage());
    }

    redirect(base_url('guru/bank_soal.php?id_mapel=' . $idMapel));
}

// Data Mapel & Daftar Judul
$mapelList = $db->query("SELECT id_mapel, nama_mapel FROM mapel ORDER BY nama_mapel ASC")->fetchAll();
$stmtJudul = $db->prepare("SELECT DISTINCT judul_soal FROM bank_soal WHERE id_guru = :g ORDER BY judul_soal ASC");
$stmtJudul->execute([':g' => $idGuru]);
$existingJudul = $stmtJudul->fetchAll(PDO::FETCH_COLUMN);
$flash = flash_get();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title><?= $editId ? 'Edit Soal' : 'Tambah Pertanyaan' ?> - CBT Guru</title>
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
            <li><a href="<?= base_url('guru/bank_soal.php') ?>" class="active">Bank Soal</a></li>
            <li><a href="<?= base_url('guru/sesi_ujian.php') ?>">Sesi Ujian & Token</a></li>
            <li><a href="<?= base_url('guru/rekap_nilai.php') ?>">Rekap Nilai</a></li>
            <li><a href="<?= base_url('logout.php') ?>" class="btn-danger">Keluar</a></li>
        </ul>
    </nav>
</header>

<main class="container" style="max-width: 920px;">
    <?php if ($flash): ?>
        <div class="alert alert-<?= sanitize($flash['type']) ?>">
            <?= sanitize($flash['message']) ?>
        </div>
    <?php endif; ?>

    <div class="card-header">
        <div>
            <h1 class="card-title"><?= $editId ? 'Edit Butir Soal' : 'Form Input Butir Soal' ?></h1>
            <p class="text-sm text-muted">Urutan pengisian: Tentukan <strong>Mapel</strong>, beri nama kelompok <strong>Soal</strong>, lalu tuliskan butir <strong>Pertanyaan</strong>.</p>
        </div>
        <a href="<?= base_url('guru/bank_soal.php') ?>" class="btn btn-outline">Kembali</a>
    </div>

    <form action="" method="POST" enctype="multipart/form-data">
        <?= csrf_field() ?>

        <!-- BAGIAN 1: MAPEL & SOAL (JUDUL PAKET) -->
        <div class="card mb-4" style="border-top: 4px solid var(--primary); background: #ffffff;">
            <h3 class="card-title text-sm uppercase text-muted mb-3" style="color: var(--primary); font-weight: 800;">
                1. Informasi Paket Soal
            </h3>

            <!-- 1. Mapel -->
            <div class="form-group">
                <label for="id_mapel">Mata Pelajaran (Mapel) <span class="text-danger">*</span></label>
                <select name="id_mapel" id="id_mapel" class="form-control" required>
                    <option value="">-- Pilih Mata Pelajaran --</option>
                    <?php foreach ($mapelList as $m): ?>
                        <option value="<?= $m['id_mapel'] ?>" <?= (($soalData['id_mapel'] ?? ($_GET['id_mapel'] ?? '')) == $m['id_mapel']) ? 'selected' : '' ?>>
                            <?= sanitize($m['nama_mapel']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- 2. Soal (Judul / Nama Ujian) -->
            <div class="form-group mt-3">
                <label for="judul_soal">Soal (Judul / Nama Ujian) <span class="text-danger">*</span></label>
                <input type="text" name="judul_soal" id="judul_soal" class="form-control" list="list_judul" placeholder="Contoh: Asesmen Nasional, Penilaian Harian Bab 1, Ujian Akhir..." value="<?= sanitize($soalData['judul_soal'] ?? ($_GET['judul_soal'] ?? '')) ?>" required>
                <datalist id="list_judul">
                    <?php foreach ($existingJudul as $ej): ?>
                        <option value="<?= sanitize($ej) ?>"></option>
                    <?php endforeach; ?>
                    <option value="Asesmen Nasional"></option>
                    <option value="Penilaian Harian 1"></option>
                    <option value="Penilaian Tengah Semester"></option>
                    <option value="Penilaian Akhir Semester"></option>
                </datalist>
                <p class="text-xs text-muted mt-1">Nama kelompok soal (misal: <em>Asesmen Nasional</em>). Semua pertanyaan di bawah akan dihimpun ke dalam nama ini.</p>
            </div>
        </div>

        <?php if ($editId > 0): ?>
            <!-- FORM EDIT TUNGGAL -->
            <div class="card mb-4" style="border-left: 4px solid var(--primary);">
                <h3 class="font-bold mb-3" style="font-size: 1.1rem; color: var(--primary);">
                    3. Pertanyaan
                </h3>

                <div class="form-group">
                    <label for="pertanyaan">Teks Pertanyaan <span class="text-danger">*</span></label>
                    <textarea name="pertanyaan" id="pertanyaan" class="form-control" rows="5" required placeholder="Tuliskan butir soal pertanyaan di sini..."><?= sanitize($soalData['pertanyaan'] ?? '') ?></textarea>
                </div>

                <div class="form-group mt-3">
                    <label for="gambar">Lampiran Gambar (Opsional, Max 2MB: JPG, PNG, WebP)</label>
                    <input type="file" name="gambar" id="gambar" class="form-control" accept="image/*">
                    <?php if (!empty($soalData['gambar'])): ?>
                        <div class="mt-2 flex gap-3" style="align-items: center;">
                            <img src="<?= base_url(ltrim($soalData['gambar'], '/')) ?>" alt="Preview" style="max-height: 80px; border-radius: 4px; border: 1px solid #ccc;">
                            <label style="cursor: pointer; font-size: 0.85rem;" class="text-danger">
                                <input type="checkbox" name="hapus_gambar" value="1"> Hapus gambar lampiran ini
                            </label>
                        </div>
                    <?php endif; ?>
                </div>

                <hr style="border: 0; border-top: 1px solid var(--gray-200); margin: 1.5rem 0;">

                <h4 class="card-title text-sm uppercase text-muted mb-3">Pilihan Ganda & Kunci Jawaban</h4>

                <div class="form-group">
                    <label>Opsi A <span class="text-danger">*</span></label>
                    <input type="text" name="opsi_a" class="form-control" required value="<?= sanitize($soalData['opsi_a'] ?? '') ?>" placeholder="Teks opsi pilihan A">
                </div>

                <div class="form-group">
                    <label>Opsi B <span class="text-danger">*</span></label>
                    <input type="text" name="opsi_b" class="form-control" required value="<?= sanitize($soalData['opsi_b'] ?? '') ?>" placeholder="Teks opsi pilihan B">
                </div>

                <div class="form-group">
                    <label>Opsi C <span class="text-danger">*</span></label>
                    <input type="text" name="opsi_c" class="form-control" required value="<?= sanitize($soalData['opsi_c'] ?? '') ?>" placeholder="Teks opsi pilihan C">
                </div>

                <div class="form-group">
                    <label>Opsi D <span class="text-danger">*</span></label>
                    <input type="text" name="opsi_d" class="form-control" required value="<?= sanitize($soalData['opsi_d'] ?? '') ?>" placeholder="Teks opsi pilihan D">
                </div>

                <div class="form-group">
                    <label>Opsi E (Opsional)</label>
                    <input type="text" name="opsi_e" class="form-control" value="<?= sanitize($soalData['opsi_e'] ?? '') ?>" placeholder="Teks opsi pilihan E (kosongkan jika SD hanya 4 opsi)">
                </div>

                <div class="form-group mt-3" style="background: #f8fafc; padding: 1rem; border-radius: 6px; border: 1px solid var(--gray-300);">
                    <label for="kunci_jawaban" class="font-bold" style="color: #1e40af;">Kunci Jawaban Benar <span class="text-danger">*</span></label>
                    <select name="kunci_jawaban" id="kunci_jawaban" class="form-control" required style="max-width: 200px;">
                        <option value="">-- Pilih Kunci --</option>
                        <option value="A" <?= (($soalData['kunci_jawaban'] ?? '') === 'A') ? 'selected' : '' ?>>Opsi A</option>
                        <option value="B" <?= (($soalData['kunci_jawaban'] ?? '') === 'B') ? 'selected' : '' ?>>Opsi B</option>
                        <option value="C" <?= (($soalData['kunci_jawaban'] ?? '') === 'C') ? 'selected' : '' ?>>Opsi C</option>
                        <option value="D" <?= (($soalData['kunci_jawaban'] ?? '') === 'D') ? 'selected' : '' ?>>Opsi D</option>
                        <option value="E" <?= (($soalData['kunci_jawaban'] ?? '') === 'E') ? 'selected' : '' ?>>Opsi E</option>
                    </select>
                </div>
            </div>

        <?php else: ?>
            <!-- FORM MULTI-PERTANYAAN DINAMIS -->
            <div id="container-pertanyaan">
                <!-- KARTU PERTANYAAN #1 -->
                <div class="card pertanyaan-card mb-4" data-index="0" style="border-left: 4px solid var(--primary); background: #ffffff;">
                    <div class="flex-between mb-3 pb-2" style="border-bottom: 1px solid var(--gray-200);">
                        <h3 class="font-bold" style="font-size: 1.15rem; color: var(--primary); margin: 0;">
                            3. Pertanyaan #<span class="nomor-pertanyaan">1</span>
                        </h3>
                        <button type="button" class="btn btn-sm btn-outline text-danger btn-hapus-pertanyaan" onclick="hapusPertanyaan(this)" style="display: none;">
                            ✕ Hapus Pertanyaan
                        </button>
                    </div>

                    <!-- 3. Teks Pertanyaan -->
                    <div class="form-group">
                        <label>Teks Pertanyaan <span class="text-danger">*</span></label>
                        <textarea name="soal[0][pertanyaan]" class="form-control" rows="4" required placeholder="Tuliskan teks butir pertanyaan di sini..."></textarea>
                    </div>

                    <!-- Lampiran Gambar -->
                    <div class="form-group mt-2">
                        <label>Lampiran Gambar (Opsional, Max 2MB)</label>
                        <input type="file" name="gambar_0" class="form-control" accept="image/*">
                    </div>

                    <hr style="border: 0; border-top: 1px solid var(--gray-200); margin: 1.25rem 0;">

                    <!-- Opsi Pilihan Ganda -->
                    <h4 class="card-title text-sm uppercase text-muted mb-2" style="font-size: 0.8rem; font-weight: 700;">
                        Pilihan Ganda & Kunci Jawaban
                    </h4>

                    <div style="display: flex; flex-direction: column; gap: 0.65rem;">
                        <div class="form-group" style="margin: 0;">
                            <label style="font-size: 0.85rem; font-weight: 600;">Opsi A <span class="text-danger">*</span></label>
                            <input type="text" name="soal[0][opsi_a]" class="form-control" required placeholder="Teks pilihan opsi A">
                        </div>

                        <div class="form-group" style="margin: 0;">
                            <label style="font-size: 0.85rem; font-weight: 600;">Opsi B <span class="text-danger">*</span></label>
                            <input type="text" name="soal[0][opsi_b]" class="form-control" required placeholder="Teks pilihan opsi B">
                        </div>

                        <div class="form-group" style="margin: 0;">
                            <label style="font-size: 0.85rem; font-weight: 600;">Opsi C <span class="text-danger">*</span></label>
                            <input type="text" name="soal[0][opsi_c]" class="form-control" required placeholder="Teks pilihan opsi C">
                        </div>

                        <div class="form-group" style="margin: 0;">
                            <label style="font-size: 0.85rem; font-weight: 600;">Opsi D <span class="text-danger">*</span></label>
                            <input type="text" name="soal[0][opsi_d]" class="form-control" required placeholder="Teks pilihan opsi D">
                        </div>

                        <div class="form-group" style="margin: 0;">
                            <label style="font-size: 0.85rem; font-weight: 600;">Opsi E (Opsional untuk SD/SMP)</label>
                            <input type="text" name="soal[0][opsi_e]" class="form-control" placeholder="Teks pilihan opsi E (boleh kosong jika hanya 4 opsi)">
                        </div>
                    </div>

                    <!-- Kunci Jawaban -->
                    <div class="form-group mt-3" style="background: #f8fafc; padding: 0.85rem 1rem; border-radius: 6px; border: 1px solid var(--gray-300);">
                        <label class="font-bold" style="color: #1e40af; font-size: 0.9rem;">Kunci Jawaban Benar <span class="text-danger">*</span></label>
                        <select name="soal[0][kunci_jawaban]" class="form-control" required style="max-width: 180px; margin-top: 0.25rem;">
                            <option value="">-- Pilih Kunci --</option>
                            <option value="A">Opsi A</option>
                            <option value="B">Opsi B</option>
                            <option value="C">Opsi C</option>
                            <option value="D">Opsi D</option>
                            <option value="E">Opsi E</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- TOMBOL TAMBAH PERTANYAAN DI PALING BAWAH KOTAK INPUT -->
            <div class="mb-4">
                <button type="button" id="btn-tambah-pertanyaan" onclick="tambahPertanyaanBaru()" class="btn btn-outline" style="border: 2px dashed #2563eb; width: 100%; padding: 0.95rem; font-weight: 700; font-size: 1rem; color: #2563eb; background: #eff6ff; border-radius: var(--radius-md); cursor: pointer; transition: all 0.2s ease;">
                    ➕ Tambah Pertanyaan Berikutnya
                </button>
            </div>
        <?php endif; ?>

        <!-- TOMBOL SIMPAN -->
        <div class="flex gap-2" style="justify-content: flex-end; margin-bottom: 3rem;">
            <a href="<?= base_url('guru/bank_soal.php') ?>" class="btn btn-outline">Batal</a>
            <button type="submit" class="btn btn-primary btn-lg" style="min-width: 220px;">
                <?= $editId ? 'Simpan Perubahan' : 'Simpan Semua Pertanyaan' ?>
            </button>
        </div>
    </form>
</main>

<script src="<?= base_url('assets/js/app.js') ?>"></script>
<script>
function updateQuestionNumbers() {
    const cards = document.querySelectorAll('.pertanyaan-card');
    cards.forEach((card, i) => {
        const num = i + 1;
        card.setAttribute('data-index', i);
        
        // Update label nomor
        const numEl = card.querySelector('.nomor-pertanyaan');
        if (numEl) numEl.textContent = num;

        // Update attribute name form elements
        const ta = card.querySelector('textarea');
        if (ta) ta.name = `soal[${i}][pertanyaan]`;

        const fi = card.querySelector('input[type="file"]');
        if (fi) fi.name = `gambar_${i}`;

        const inputs = card.querySelectorAll('input[type="text"]');
        if (inputs[0]) inputs[0].name = `soal[${i}][opsi_a]`;
        if (inputs[1]) inputs[1].name = `soal[${i}][opsi_b]`;
        if (inputs[2]) inputs[2].name = `soal[${i}][opsi_c]`;
        if (inputs[3]) inputs[3].name = `soal[${i}][opsi_d]`;
        if (inputs[4]) inputs[4].name = `soal[${i}][opsi_e]`;

        const sel = card.querySelector('select');
        if (sel) sel.name = `soal[${i}][kunci_jawaban]`;

        // Tampilkan tombol hapus jika jumlah kartu > 1
        const btnHapus = card.querySelector('.btn-hapus-pertanyaan');
        if (btnHapus) {
            btnHapus.style.display = (cards.length > 1) ? 'inline-flex' : 'none';
        }
    });
}

function tambahPertanyaanBaru() {
    const container = document.getElementById('container-pertanyaan');
    const firstCard = container.querySelector('.pertanyaan-card');
    if (!firstCard) return;

    const clone = firstCard.cloneNode(true);

    // Kosongkan nilai input di kartu baru
    clone.querySelectorAll('textarea, input[type="text"]').forEach(el => el.value = '');
    clone.querySelectorAll('input[type="file"]').forEach(el => el.value = '');
    clone.querySelectorAll('select').forEach(el => el.selectedIndex = 0);

    container.appendChild(clone);
    updateQuestionNumbers();

    // Scroll otomatis ke kartu pertanyaan baru
    clone.scrollIntoView({ behavior: 'smooth', block: 'center' });
    
    // Auto focus ke textarea baru
    const newTextarea = clone.querySelector('textarea');
    if (newTextarea) {
        setTimeout(() => newTextarea.focus(), 300);
    }
}

function hapusPertanyaan(btn) {
    const card = btn.closest('.pertanyaan-card');
    const total = document.querySelectorAll('.pertanyaan-card').length;
    if (total > 1) {
        cbtConfirm({
            title: 'Hapus Pertanyaan',
            message: 'Apakah Anda yakin ingin menghapus blok pertanyaan ini?',
            type: 'danger',
            confirmText: 'Ya, Hapus'
        }).then(ok => {
            if (ok) {
                card.remove();
                updateQuestionNumbers();
            }
        });
    }
}
</script>
</body>
</html>
