<?php
/**
 * Page: Tambah & Edit Paket Soal (Dukungan Semua Butir Soal, Pilihan Ganda & Essai)
 */

require_once __DIR__ . '/../../middleware/auth.php';

$currentUser = auth_check(['guru']);
$db = get_db();

$idGuru = $currentUser['id_user'];

// Tangani parameter inisialisasi
$initPaketId  = !empty($_GET['id_paket']) ? (int)$_GET['id_paket'] : 0;
$initMapel    = !empty($_GET['id_mapel']) ? (int)$_GET['id_mapel'] : 0;
$initJudul    = trim($_GET['judul_soal'] ?? '');
$editSingleId = !empty($_GET['edit']) ? (int)$_GET['edit'] : 0;

// Jika datang dari parameter edit butir soal tunggal
if ($editSingleId > 0 && $initPaketId <= 0) {
    $stmtSingle = $db->prepare("
        SELECT b.id_soal, b.id_paket, p.id_mapel, p.nama_paket 
        FROM bank_soal b
        JOIN paket_soal p ON b.id_paket = p.id_paket
        WHERE b.id_soal = :id AND p.id_guru = :g
    ");
    $stmtSingle->execute([':id' => $editSingleId, ':g' => $idGuru]);
    $singleData = $stmtSingle->fetch();
    if ($singleData) {
        $initPaketId = (int)$singleData['id_paket'];
        $initMapel   = (int)$singleData['id_mapel'];
        $initJudul   = $singleData['nama_paket'];
    }
}

// Jika id_paket ditentukan, ambil detail paket
if ($initPaketId > 0) {
    $stmtP = $db->prepare("SELECT * FROM paket_soal WHERE id_paket = :id AND id_guru = :g");
    $stmtP->execute([':id' => $initPaketId, ':g' => $idGuru]);
    $paketRow = $stmtP->fetch();
    if ($paketRow) {
        $initMapel = (int)$paketRow['id_mapel'];
        $initJudul = $paketRow['nama_paket'];
    }
}

// Ambil seluruh butir pertanyaan dalam paket ini jika paket sudah ada
$existingQuestions = [];
if ($initPaketId > 0) {
    $stmtPaket = $db->prepare("
        SELECT * FROM bank_soal 
        WHERE id_paket = :p 
        ORDER BY id_soal ASC
    ");
    $stmtPaket->execute([':p' => $initPaketId]);
    $existingQuestions = $stmtPaket->fetchAll();
} elseif ($initMapel > 0 && $initJudul !== '') {
    // Fallback pencarian paket berdasarkan mapel dan nama jika id_paket belum di URL
    $stmtP = $db->prepare("SELECT * FROM paket_soal WHERE id_guru = :g AND id_mapel = :m AND nama_paket = :j");
    $stmtP->execute([':g' => $idGuru, ':m' => $initMapel, ':j' => $initJudul]);
    $paketRow = $stmtP->fetch();
    if ($paketRow) {
        $initPaketId = (int)$paketRow['id_paket'];
        $stmtPaket = $db->prepare("SELECT * FROM bank_soal WHERE id_paket = :p ORDER BY id_soal ASC");
        $stmtPaket->execute([':p' => $initPaketId]);
        $existingQuestions = $stmtPaket->fetchAll();
    }
}

$isEditMode = !empty($existingQuestions);

// PROSES FORM POST (SIMPAN / PERBARUI SELURUH PAKET SOAL)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash_set('danger', 'Validasi token keamanan (CSRF) gagal.');
        redirect(base_url('guru?page=tambah_soal' . ($initPaketId ? '&id_paket=' . $initPaketId : ($initMapel ? '&id_mapel=' . $initMapel : ''))));
    }

    $idPaket   = (int)($_POST['id_paket'] ?? 0);
    $idMapel   = (int)($_POST['id_mapel'] ?? 0);
    $judulSoal = trim($_POST['judul_soal'] ?? '');
    $soalItems = $_POST['soal'] ?? [];

    if ($judulSoal === '') {
        $judulSoal = 'Asesmen Harian';
    }

    if ($idMapel <= 0) {
        flash_set('danger', 'Silakan pilih Mata Pelajaran terlebih dahulu.');
        redirect(base_url('guru?page=tambah_soal' . ($initPaketId ? '&id_paket=' . $initPaketId : ($initMapel ? '&id_mapel=' . $initMapel : ''))));
    }

    if (empty($soalItems) || !is_array($soalItems)) {
        flash_set('danger', 'Minimal harus ada 1 butir pertanyaan.');
        redirect(base_url('guru?page=tambah_soal' . ($initPaketId ? '&id_paket=' . $initPaketId : ($initMapel ? '&id_mapel=' . $initMapel : ''))));
    }

    $db->beginTransaction();
    try {
        // 1. Simpan Header Paket Soal (INSERT atau UPDATE)
        if ($idPaket > 0) {
            $stmtUpdPaket = $db->prepare("
                UPDATE paket_soal 
                SET id_mapel = :m, nama_paket = :j 
                WHERE id_paket = :p AND id_guru = :g
            ");
            $stmtUpdPaket->execute([':m' => $idMapel, ':j' => $judulSoal, ':p' => $idPaket, ':g' => $idGuru]);
        } else {
            // Cek apakah sudah ada paket dengan nama & mapel yang sama
            $stmtCek = $db->prepare("SELECT id_paket FROM paket_soal WHERE id_guru = :g AND id_mapel = :m AND nama_paket = :j");
            $stmtCek->execute([':g' => $idGuru, ':m' => $idMapel, ':j' => $judulSoal]);
            $foundPaketId = $stmtCek->fetchColumn();

            if ($foundPaketId) {
                $idPaket = (int)$foundPaketId;
            } else {
                $stmtInsPaket = $db->prepare("
                    INSERT INTO paket_soal (id_guru, id_mapel, nama_paket)
                    VALUES (:g, :m, :j)
                ");
                $stmtInsPaket->execute([':g' => $idGuru, ':m' => $idMapel, ':j' => $judulSoal]);
                $idPaket = (int)$db->lastInsertId('paket_soal_id_paket_seq');
            }
        }

        // Kumpulkan ID butir soal yang masih dipertahankan pada form ini
        $submittedIds = [];
        foreach ($soalItems as $item) {
            $sId = (int)($item['id_soal'] ?? 0);
            if ($sId > 0) {
                $submittedIds[] = $sId;
            }
        }

        // Hapus butir soal lama yang dibuang oleh guru dari paket ini
        $stmtOld = $db->prepare("SELECT id_soal, gambar FROM bank_soal WHERE id_paket = :p");
        $stmtOld->execute([':p' => $idPaket]);
        $oldRows = $stmtOld->fetchAll();

        foreach ($oldRows as $oldR) {
            if (!in_array((int)$oldR['id_soal'], $submittedIds, true)) {
                // Hapus gambar jika ada
                if (!empty($oldR['gambar']) && file_exists(__DIR__ . '/../../' . ltrim($oldR['gambar'], '/'))) {
                    @unlink(__DIR__ . '/../../' . ltrim($oldR['gambar'], '/'));
                }
                $delStmt = $db->prepare("DELETE FROM bank_soal WHERE id_soal = :id AND id_paket = :p");
                $delStmt->execute([':id' => $oldR['id_soal'], ':p' => $idPaket]);
            }
        }

        // Siapkan statement INSERT & UPDATE
        $stmtInsert = $db->prepare("
            INSERT INTO bank_soal (id_paket, jenis_soal, pertanyaan, gambar, opsi_a, opsi_b, opsi_c, opsi_d, opsi_e, kunci_jawaban)
            VALUES (:p, :jenis, :pert, :gbr, :oa, :ob, :oc, :od, :oe, :k)
        ");

        $stmtUpdate = $db->prepare("
            UPDATE bank_soal 
            SET jenis_soal = :jenis, pertanyaan = :pert, gambar = :gbr, 
                opsi_a = :oa, opsi_b = :ob, opsi_c = :oc, opsi_d = :od, opsi_e = :oe, kunci_jawaban = :k
            WHERE id_soal = :id AND id_paket = :p
        ");

        $savedCount = 0;

        foreach ($soalItems as $idx => $item) {
            $itemSoalId = (int)($item['id_soal'] ?? 0);
            $jenis      = $item['jenis_soal'] ?? 'pilihan_ganda';
            $pertanyaan = trim($item['pertanyaan'] ?? '');

            if ($pertanyaan === '') {
                continue; // Lewati pertanyaan kosong
            }

            // Kelola file gambar
            $gambarPath = !empty($item['existing_gambar']) ? $item['existing_gambar'] : null;

            // Jika ada request hapus gambar lama
            if (!empty($item['hapus_gambar']) && $gambarPath) {
                if (file_exists(__DIR__ . '/../../' . ltrim($gambarPath, '/'))) {
                    @unlink(__DIR__ . '/../../' . ltrim($gambarPath, '/'));
                }
                $gambarPath = null;
            }

            // Upload gambar baru jika ada (dukung base64 canvas kompresi & multipart file)
            $baseRootDir = dirname(__DIR__, 2);
            $uploadDir   = $baseRootDir . '/assets/uploads/';
            if (!is_dir($uploadDir)) {
                @mkdir($uploadDir, 0777, true);
                @chmod($uploadDir, 0777);
            }

            // 1. Cek gambar dari Canvas Base64 (Kompresi browser client-side)
            $base64Gbr = trim($item['gambar_base64'] ?? '');
            if (!empty($base64Gbr) && preg_match('/^data:image\/(\w+);base64,/', $base64Gbr, $matches)) {
                $imgData = substr($base64Gbr, strpos($base64Gbr, ',') + 1);
                $decodedData = base64_decode($imgData);
                if ($decodedData !== false && strlen($decodedData) > 0) {
                    $ext = strtolower($matches[1]);
                    if ($ext === 'jpeg') $ext = 'jpg';
                    $newFileName = 'soal_' . time() . '_' . $idx . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
                    $destPath    = $uploadDir . $newFileName;
                    if (file_put_contents($destPath, $decodedData)) {
                        if ($gambarPath && file_exists($baseRootDir . '/' . ltrim($gambarPath, '/'))) {
                            @unlink($baseRootDir . '/' . ltrim($gambarPath, '/'));
                        }
                        $gambarPath = 'assets/uploads/' . $newFileName;
                    }
                }
            }
            // 2. Upload multipart file standar jika ada
            else {
                $fileKey = 'gambar_' . $idx;
                if (isset($_FILES[$fileKey]) && $_FILES[$fileKey]['error'] === UPLOAD_ERR_OK) {
                    $fileTmp  = $_FILES[$fileKey]['tmp_name'];
                    $fileName = $_FILES[$fileKey]['name'];
                    $fileSize = $_FILES[$fileKey]['size'];
                    $ext      = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

                    $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'jfif'];
                    if (in_array($ext, $allowedExts, true) && $fileSize <= 10 * 1024 * 1024) {
                        $newFileName = 'soal_' . time() . '_' . $idx . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
                        $destPath    = $uploadDir . $newFileName;
                        if (@move_uploaded_file($fileTmp, $destPath)) {
                            // Hapus file lama jika ditimpa
                            if ($gambarPath && file_exists($baseRootDir . '/' . ltrim($gambarPath, '/'))) {
                                @unlink($baseRootDir . '/' . ltrim($gambarPath, '/'));
                            }
                            $gambarPath = 'assets/uploads/' . $newFileName;
                        }
                    }
                }
            }

            if ($jenis === 'essai') {
                $kunci = trim($item['kunci_jawaban'] ?? '');
                $params = [
                    ':p'     => $idPaket,
                    ':jenis' => 'essai',
                    ':pert'  => $pertanyaan,
                    ':gbr'   => $gambarPath,
                    ':oa'    => null,
                    ':ob'    => null,
                    ':oc'    => null,
                    ':od'    => null,
                    ':oe'    => null,
                    ':k'     => $kunci !== '' ? $kunci : null
                ];

                if ($itemSoalId > 0) {
                    $params[':id'] = $itemSoalId;
                    $stmtUpdate->execute($params);
                } else {
                    $stmtInsert->execute($params);
                }
                $savedCount++;
            } else {
                // Pilihan Ganda
                $opsiA    = trim($item['opsi_a'] ?? '');
                $opsiB    = trim($item['opsi_b'] ?? '');
                $opsiC    = trim($item['opsi_c'] ?? '');
                $opsiD    = trim($item['opsi_d'] ?? '');
                $opsiE    = trim($item['opsi_e'] ?? '');
                $kunciArr = $item['kunci'] ?? [];
                $kunci    = is_array($kunciArr) ? strtoupper(implode(',', array_filter($kunciArr))) : strtoupper(trim($kunciArr));

                if ($opsiA === '' || $opsiB === '' || $opsiC === '' || $opsiD === '' || $kunci === '') {
                    continue; // Lewati jika opsi belum lengkap
                }

                $params = [
                    ':p'     => $idPaket,
                    ':jenis' => 'pilihan_ganda',
                    ':pert'  => $pertanyaan,
                    ':gbr'   => $gambarPath,
                    ':oa'    => $opsiA,
                    ':ob'    => $opsiB,
                    ':oc'    => $opsiC,
                    ':od'    => $opsiD,
                    ':oe'    => $opsiE !== '' ? $opsiE : null,
                    ':k'     => $kunci
                ];

                if ($itemSoalId > 0) {
                    $params[':id'] = $itemSoalId;
                    $stmtUpdate->execute($params);
                } else {
                    $stmtInsert->execute($params);
                }
                $savedCount++;
            }
        }

        $db->commit();

        if ($savedCount > 0) {
            flash_set('success', "Paket soal '{$judulSoal}' berhasil disimpan ({$savedCount} butir pertanyaan).");
        } else {
            flash_set('warning', 'Tidak ada butir soal yang disimpan. Mohon pastikan teks pertanyaan, opsi, dan checklist kunci terisi lengkap.');
        }
    } catch (Exception $e) {
        $db->rollBack();
        flash_set('danger', 'Gagal menyimpan paket soal: ' . $e->getMessage());
    }

    redirect(base_url('guru?page=bank_soal' . ($idMapel ? '&id_mapel=' . $idMapel : '')));
}

// Ambil Daftar Mapel
$stmtMapel = $db->query("SELECT * FROM mapel ORDER BY nama_mapel ASC");
$mapelList = $stmtMapel->fetchAll();

// Ambil Daftar Nama Paket Soal yang pernah dibuat untuk auto-suggest
$stmtJudul = $db->prepare("SELECT DISTINCT nama_paket FROM paket_soal WHERE id_guru = :g ORDER BY nama_paket ASC");
$stmtJudul->execute([':g' => $idGuru]);
$existingJudul = $stmtJudul->fetchAll(PDO::FETCH_COLUMN);

// Susun list pertanyaan yang akan dirender (jika kosong, buat 1 butir pilihan ganda kosong)
$cardsToRender = !empty($existingQuestions) ? $existingQuestions : [
    [
        'id_soal' => 0,
        'jenis_soal' => 'pilihan_ganda',
        'pertanyaan' => '',
        'gambar' => null,
        'opsi_a' => '',
        'opsi_b' => '',
        'opsi_c' => '',
        'opsi_d' => '',
        'opsi_e' => '',
        'kunci_jawaban' => ''
    ]
];

$page = 'tambah_soal';
$pageTitle = $isEditMode ? 'Edit Paket Soal' : 'Buat Paket Soal Baru';

include __DIR__ . '/../layouts/header.php';
?>

<main class="container">
    <div class="card-header mb-4">
        <div>
            <h1 class="card-title"><?= $isEditMode ? 'Edit Paket Soal' : 'Buat Paket Soal Baru' ?></h1>
            <p style="color: var(--gray-500); font-size: 0.85rem; margin-top: 0.25rem;">
                Kelola nama paket dan butir pertanyaan sekaligus (Pilihan Ganda & Essai).
            </p>
        </div>
        <div class="card-header-actions">
            <a href="<?= base_url('guru?page=bank_soal' . ($initMapel ? '&id_mapel=' . $initMapel : '')) ?>" class="btn btn-outline">
                Kembali
            </a>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?= sanitize($flash['type']) ?>">
            <?= sanitize($flash['message']) ?>
        </div>
    <?php endif; ?>

    <form action="<?= base_url('guru?page=tambah_soal') ?>" method="POST" enctype="multipart/form-data" id="form-paket-soal">
        <?= csrf_field() ?>
        <input type="hidden" name="id_paket" value="<?= $initPaketId ?>">

        <!-- KARTU INFORMASI UTAMA PAKET -->
        <div class="card mb-4" style="border-left: 4px solid var(--primary);">
            <h3 class="font-bold mb-3" style="font-size: 1.1rem; color: var(--gray-800);">Informasi Paket Soal</h3>
            
            <!-- 1. Pilihan Mata Pelajaran -->
            <div class="form-group">
                <label for="id_mapel">Mata Pelajaran <span class="text-danger">*</span></label>
                <select name="id_mapel" id="id_mapel" class="form-control" required>
                    <option value="">-- Pilih Mata Pelajaran --</option>
                    <?php foreach ($mapelList as $m): ?>
                        <option value="<?= $m['id_mapel'] ?>" <?= ($initMapel == $m['id_mapel']) ? 'selected' : '' ?>>
                            <?= sanitize($m['nama_mapel']) ?> (<?= sanitize($m['kode_mapel']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- 2. Soal (Judul / Nama Ujian) -->
            <div class="form-group mt-3">
                <label for="judul_soal">Nama / Judul Paket Soal <span class="text-danger">*</span></label>
                <input type="text" name="judul_soal" id="judul_soal" class="form-control" list="list_judul" placeholder="Contoh: Penilaian Harian 1, PTS Matematika..." value="<?= sanitize($initJudul) ?>" required>
                <datalist id="list_judul">
                    <?php foreach ($existingJudul as $ej): ?>
                        <option value="<?= sanitize($ej) ?>"></option>
                    <?php endforeach; ?>
                    <option value="Asesmen Nasional"></option>
                    <option value="Penilaian Harian 1"></option>
                    <option value="Penilaian Tengah Semester"></option>
                    <option value="Penilaian Akhir Semester"></option>
                </datalist>
            </div>
        </div>

        <!-- CONTAINER DAFTAR BUTIR PERTANYAAN -->
        <div id="container-pertanyaan">
            <?php foreach ($cardsToRender as $idx => $q): ?>
                <?php 
                $isEssai = (($q['jenis_soal'] ?? 'pilihan_ganda') === 'essai');
                $kunciSelected = explode(',', $q['kunci_jawaban'] ?? '');
                $qId = (int)($q['id_soal'] ?? 0);
                ?>
                <div class="card pertanyaan-card mb-4" data-type="<?= $isEssai ? 'essai' : 'pilihan_ganda' ?>" data-index="<?= $idx ?>">
                    <input type="hidden" name="soal[<?= $idx ?>][id_soal]" value="<?= $qId ?>" class="field-id-soal">
                    <input type="hidden" name="soal[<?= $idx ?>][jenis_soal]" value="<?= $isEssai ? 'essai' : 'pilihan_ganda' ?>" class="field-jenis-soal">
                    
                    <div class="flex-between mb-3 pb-2" style="border-bottom: 1px solid var(--gray-200);">
                        <div class="flex gap-2" style="align-items: center;">
                            <h3 class="font-bold" style="font-size: 1.25rem; color: <?= $isEssai ? '#7c3aed' : 'var(--primary)' ?>; margin: 0; min-width: 28px;">
                                <span class="nomor-pertanyaan"><?= $idx + 1 ?></span>.
                            </h3>
                            <span class="badge badge-jenis" style="<?= $isEssai ? 'background: #ede9fe; color: #6d28d9;' : 'background: #e0f2fe; color: #0369a1;' ?> font-weight: 700;">
                                <?= $isEssai ? 'Soal Essai' : 'Pilihan Ganda' ?>
                            </span>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline text-danger btn-hapus-pertanyaan" onclick="hapusPertanyaan(this)" style="<?= count($cardsToRender) > 1 ? '' : 'display: none;' ?>">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                            <span>Hapus</span>
                        </button>
                    </div>

                    <!-- Teks Pertanyaan -->
                    <div class="form-group">
                        <label>Teks Pertanyaan <?= $isEssai ? 'Essai / Uraian' : '' ?> <span class="text-danger">*</span></label>
                        <textarea name="soal[<?= $idx ?>][pertanyaan]" class="form-control field-pertanyaan" rows="4" required placeholder="Tuliskan butir soal pertanyaan di sini..."><?= sanitize($q['pertanyaan'] ?? '') ?></textarea>
                    </div>

                    <!-- Lampiran Gambar -->
                    <div class="form-group mt-3">
                        <label>Lampiran Gambar (Opsional)</label>
                        <?php if (!empty($q['gambar'])): ?>
                            <div class="mb-2 flex gap-3 existing-img-box" style="align-items: center;">
                                <img src="<?= base_url(sanitize($q['gambar'])) ?>" alt="Gambar Soal" style="max-height: 90px; border-radius: 4px; border: 1px solid var(--gray-300);">
                                <label style="display: flex; align-items: center; gap: 0.35rem; font-size: 0.85rem; color: var(--danger); cursor: pointer;">
                                    <input type="checkbox" name="soal[<?= $idx ?>][hapus_gambar]" value="1">
                                    <span>Hapus Gambar Ini</span>
                                </label>
                                <input type="hidden" name="soal[<?= $idx ?>][existing_gambar]" value="<?= sanitize($q['gambar']) ?>">
                            </div>
                        <?php endif; ?>
                        <div class="preview-gambar-container mb-2" style="display: none; align-items: center; gap: 0.75rem;">
                            <img class="img-preview" src="" alt="Preview" style="max-height: 100px; border-radius: 6px; border: 1px solid var(--gray-300); box-shadow: var(--shadow-sm);">
                            <button type="button" class="btn btn-sm btn-outline text-danger" onclick="hapusPreviewGambar(this)">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                                <span>Batal Gambar</span>
                            </button>
                        </div>
                        <input type="hidden" name="soal[<?= $idx ?>][gambar_base64]" class="field-gambar-base64" value="">
                        <input type="file" name="gambar_<?= $idx ?>" class="form-control field-file-gambar" accept="image/*" onchange="previewDanKompresGambar(this)">
                        <small style="color: var(--gray-500); font-size: 0.8rem; display: block; margin-top: 0.25rem;">
                            Format didukung: JPG, PNG, GIF, WebP. Gambar otomatis dikompresi agar cepat dimuat dan tidak gagal upload.
                        </small>
                    </div>

                    <!-- Input Jawaban Berdasarkan Tipe -->
                    <?php if ($isEssai): ?>
                        <div class="area-essai">
                            <hr style="border: 0; border-top: 1px solid var(--gray-200); margin: 1.25rem 0;">
                            <div class="form-group">
                                <label style="color: #6d28d9; font-weight: 600;">Pedoman Jawaban / Kata Kunci Essai (Opsional)</label>
                                <textarea name="soal[<?= $idx ?>][kunci_jawaban]" class="form-control field-kunci-essai" rows="2" placeholder="Catatan kunci penilaian untuk guru saat memeriksa..."><?= sanitize($q['kunci_jawaban'] ?? '') ?></textarea>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="area-pilihan-ganda">
                            <hr style="border: 0; border-top: 1px solid var(--gray-200); margin: 1.25rem 0;">
                            <label style="font-weight: 700; margin-bottom: 0.65rem; display: block; color: var(--gray-800);">
                                Pilihan Jawaban & Kunci Benar: <span class="text-danger">*</span>
                                <small style="font-weight: normal; color: var(--gray-500); display: block;">Centang kotak pada opsi yang merupakan jawaban benar (bisa lebih dari 1).</small>
                            </label>

                            <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                                <!-- A -->
                                <div style="display: flex; align-items: center; gap: 0.65rem;">
                                    <label style="display: inline-flex; align-items: center; gap: 0.45rem; cursor: pointer; font-weight: 700; font-size: 0.95rem; min-width: 58px; justify-content: center; user-select: none; background: #f8fafc; padding: 0.5rem 0.65rem; border-radius: 6px; border: 1px solid var(--gray-300);" title="Centang jika pilihan A adalah kunci jawaban benar">
                                        <input type="checkbox" name="soal[<?= $idx ?>][kunci][]" value="A" <?= in_array('A', $kunciSelected, true) ? 'checked' : '' ?> class="field-kunci-cb" style="width: 18px; height: 18px; cursor: pointer; accent-color: #16a34a;">
                                        <span>A</span>
                                    </label>
                                    <input type="text" name="soal[<?= $idx ?>][opsi_a]" class="form-control field-opsi" required value="<?= sanitize($q['opsi_a'] ?? '') ?>" placeholder="Teks pilihan A..." style="flex: 1;">
                                </div>

                                <!-- B -->
                                <div style="display: flex; align-items: center; gap: 0.65rem;">
                                    <label style="display: inline-flex; align-items: center; gap: 0.45rem; cursor: pointer; font-weight: 700; font-size: 0.95rem; min-width: 58px; justify-content: center; user-select: none; background: #f8fafc; padding: 0.5rem 0.65rem; border-radius: 6px; border: 1px solid var(--gray-300);" title="Centang jika pilihan B adalah kunci jawaban benar">
                                        <input type="checkbox" name="soal[<?= $idx ?>][kunci][]" value="B" <?= in_array('B', $kunciSelected, true) ? 'checked' : '' ?> class="field-kunci-cb" style="width: 18px; height: 18px; cursor: pointer; accent-color: #16a34a;">
                                        <span>B</span>
                                    </label>
                                    <input type="text" name="soal[<?= $idx ?>][opsi_b]" class="form-control field-opsi" required value="<?= sanitize($q['opsi_b'] ?? '') ?>" placeholder="Teks pilihan B..." style="flex: 1;">
                                </div>

                                <!-- C -->
                                <div style="display: flex; align-items: center; gap: 0.65rem;">
                                    <label style="display: inline-flex; align-items: center; gap: 0.45rem; cursor: pointer; font-weight: 700; font-size: 0.95rem; min-width: 58px; justify-content: center; user-select: none; background: #f8fafc; padding: 0.5rem 0.65rem; border-radius: 6px; border: 1px solid var(--gray-300);" title="Centang jika pilihan C adalah kunci jawaban benar">
                                        <input type="checkbox" name="soal[<?= $idx ?>][kunci][]" value="C" <?= in_array('C', $kunciSelected, true) ? 'checked' : '' ?> class="field-kunci-cb" style="width: 18px; height: 18px; cursor: pointer; accent-color: #16a34a;">
                                        <span>C</span>
                                    </label>
                                    <input type="text" name="soal[<?= $idx ?>][opsi_c]" class="form-control field-opsi" required value="<?= sanitize($q['opsi_c'] ?? '') ?>" placeholder="Teks pilihan C..." style="flex: 1;">
                                </div>

                                <!-- D -->
                                <div style="display: flex; align-items: center; gap: 0.65rem;">
                                    <label style="display: inline-flex; align-items: center; gap: 0.45rem; cursor: pointer; font-weight: 700; font-size: 0.95rem; min-width: 58px; justify-content: center; user-select: none; background: #f8fafc; padding: 0.5rem 0.65rem; border-radius: 6px; border: 1px solid var(--gray-300);" title="Centang jika pilihan D adalah kunci jawaban benar">
                                        <input type="checkbox" name="soal[<?= $idx ?>][kunci][]" value="D" <?= in_array('D', $kunciSelected, true) ? 'checked' : '' ?> class="field-kunci-cb" style="width: 18px; height: 18px; cursor: pointer; accent-color: #16a34a;">
                                        <span>D</span>
                                    </label>
                                    <input type="text" name="soal[<?= $idx ?>][opsi_d]" class="form-control field-opsi" required value="<?= sanitize($q['opsi_d'] ?? '') ?>" placeholder="Teks pilihan D..." style="flex: 1;">
                                </div>

                                <!-- E -->
                                <div style="display: flex; align-items: center; gap: 0.65rem;">
                                    <label style="display: inline-flex; align-items: center; gap: 0.45rem; cursor: pointer; font-weight: 700; font-size: 0.95rem; min-width: 58px; justify-content: center; user-select: none; background: #f8fafc; padding: 0.5rem 0.65rem; border-radius: 6px; border: 1px solid var(--gray-300);" title="Centang jika pilihan E adalah kunci jawaban benar">
                                        <input type="checkbox" name="soal[<?= $idx ?>][kunci][]" value="E" <?= in_array('E', $kunciSelected, true) ? 'checked' : '' ?> class="field-kunci-cb" style="width: 18px; height: 18px; cursor: pointer; accent-color: #16a34a;">
                                        <span>E</span>
                                    </label>
                                    <input type="text" name="soal[<?= $idx ?>][opsi_e]" class="form-control field-opsi" value="<?= sanitize($q['opsi_e'] ?? '') ?>" placeholder="Teks pilihan E (opsional)..." style="flex: 1;">
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- TOMBOL TAMBAH PERTANYAAN (DIBAGI 2: KIRI PILIHAN GANDA, KANAN ESSAI) -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.75rem;">
            <button type="button" id="btn-tambah-pg" onclick="tambahPertanyaan('pilihan_ganda')" class="btn btn-outline" style="border: 2px dashed #2563eb; width: 100%; padding: 0.85rem; font-weight: 600; font-size: 0.9rem; color: #2563eb; background: #eff6ff; border-radius: var(--radius-sm); cursor: pointer; justify-content: center; gap: 0.5rem;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                <span>Tambah Pilihan Ganda</span>
            </button>
            <button type="button" id="btn-tambah-essai" onclick="tambahPertanyaan('essai')" class="btn btn-outline" style="border: 2px dashed #7c3aed; width: 100%; padding: 0.85rem; font-weight: 600; font-size: 0.9rem; color: #7c3aed; background: #faf5ff; border-radius: var(--radius-sm); cursor: pointer; justify-content: center; gap: 0.5rem;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path></svg>
                <span>Tambah Soal Essai</span>
            </button>
        </div>

        <!-- TOMBOL SIMPAN -->
        <div class="flex gap-2" style="justify-content: flex-end; margin-bottom: 3rem;">
            <a href="<?= base_url('guru?page=bank_soal' . ($initMapel ? '&id_mapel=' . $initMapel : '')) ?>" class="btn btn-outline">Batal</a>
            <button type="submit" class="btn btn-primary btn-lg" style="min-width: 240px; font-weight: 800;">
                <?= $isEditMode ? 'Simpan Perubahan Paket Soal' : 'Simpan Semua Pertanyaan' ?>
            </button>
        </div>
    </form>
</main>

<?php
$extraJs = <<<'JS'
<script>
function updateQuestionNumbers() {
    const cards = document.querySelectorAll(".pertanyaan-card");
    cards.forEach((card, i) => {
        const num = i + 1;
        card.setAttribute("data-index", i);
        
        // Update label nomor
        const numEl = card.querySelector(".nomor-pertanyaan");
        if (numEl) numEl.textContent = num;

        // Update id_soal input
        const idInput = card.querySelector(".field-id-soal");
        if (idInput) idInput.name = `soal[${i}][id_soal]`;

        // Update jenis soal input
        const jenisInput = card.querySelector(".field-jenis-soal");
        if (jenisInput) jenisInput.name = `soal[${i}][jenis_soal]`;

        // Update existing gambar input jika ada
        const existImg = card.querySelector('input[name*="[existing_gambar]"]');
        if (existImg) existImg.name = `soal[${i}][existing_gambar]`;

        const hapusImg = card.querySelector('input[name*="[hapus_gambar]"]');
        if (hapusImg) hapusImg.name = `soal[${i}][hapus_gambar]`;

        // Update input hidden base64
        const bg64 = card.querySelector(".field-gambar-base64");
        if (bg64) bg64.name = `soal[${i}][gambar_base64]`;

        // Update attribute name form elements
        const ta = card.querySelector(".field-pertanyaan, textarea");
        if (ta) ta.name = `soal[${i}][pertanyaan]`;

        const fi = card.querySelector('.field-file-gambar, input[type="file"]');
        if (fi && (!fi.files || fi.files.length === 0)) {
            fi.name = `gambar_${i}`;
        }

        const isEssai = card.getAttribute("data-type") === "essai";
        if (isEssai) {
            const kunciEssai = card.querySelector(".field-kunci-essai");
            if (kunciEssai) kunciEssai.name = `soal[${i}][kunci_jawaban]`;
        } else {
            const inputs = card.querySelectorAll(".field-opsi");
            if (inputs[0]) inputs[0].name = `soal[${i}][opsi_a]`;
            if (inputs[1]) inputs[1].name = `soal[${i}][opsi_b]`;
            if (inputs[2]) inputs[2].name = `soal[${i}][opsi_c]`;
            if (inputs[3]) inputs[3].name = `soal[${i}][opsi_d]`;
            if (inputs[4]) inputs[4].name = `soal[${i}][opsi_e]`;

            const checkboxes = card.querySelectorAll('.field-kunci-cb, input[type="checkbox"][value]');
            checkboxes.forEach(cb => {
                cb.name = `soal[${i}][kunci][]`;
            });
        }

        // Tampilkan tombol hapus jika jumlah kartu > 1
        const btnHapus = card.querySelector(".btn-hapus-pertanyaan");
        if (btnHapus) {
            btnHapus.style.display = (cards.length > 1) ? "inline-flex" : "none";
        }
    });
}

function previewDanKompresGambar(input) {
    const card = input.closest(".pertanyaan-card");
    if (!card) return;
    const previewContainer = card.querySelector(".preview-gambar-container");
    const previewImg = card.querySelector(".img-preview");
    const base64Input = card.querySelector(".field-gambar-base64");
    
    if (!input.files || !input.files[0]) {
        return;
    }
    
    const file = input.files[0];
    if (!file.type.match("image.*")) {
        alert("Pilih file gambar yang valid (JPG, PNG, GIF, WebP).");
        input.value = "";
        return;
    }

    const reader = new FileReader();
    reader.onload = function(e) {
        const img = new Image();
        img.onload = function() {
            // Resize / kompres via canvas (max 1600px width/height)
            const maxDim = 1600;
            let width = img.width;
            let height = img.height;

            if (width > maxDim || height > maxDim) {
                if (width > height) {
                    height = Math.round((height * maxDim) / width);
                    width = maxDim;
                } else {
                    width = Math.round((width * maxDim) / height);
                    height = maxDim;
                }
            }

            const canvas = document.createElement("canvas");
            canvas.width = width;
            canvas.height = height;
            const ctx = canvas.getContext("2d");
            ctx.drawImage(img, 0, 0, width, height);

            // Export ke JPEG quality 0.85
            const dataUrl = canvas.toDataURL("image/jpeg", 0.85);
            if (base64Input) base64Input.value = dataUrl;
            if (previewImg) previewImg.src = dataUrl;
            if (previewContainer) previewContainer.style.display = "flex";
        };
        img.src = e.target.result;
    };
    reader.readAsDataURL(file);
}

function hapusPreviewGambar(btn) {
    const card = btn.closest(".pertanyaan-card");
    if (!card) return;
    const previewContainer = card.querySelector(".preview-gambar-container");
    const previewImg = card.querySelector(".img-preview");
    const base64Input = card.querySelector(".field-gambar-base64");
    const fileInput = card.querySelector(".field-file-gambar, input[type='file']");

    if (previewContainer) previewContainer.style.display = "none";
    if (previewImg) previewImg.src = "";
    if (base64Input) base64Input.value = "";
    if (fileInput) fileInput.value = "";
}

function tambahPertanyaan(tipe) {
    const container = document.getElementById("container-pertanyaan");
    const newIndex = container.querySelectorAll(".pertanyaan-card").length;
    const newNum = newIndex + 1;

    const newCard = document.createElement("div");
    newCard.className = "card pertanyaan-card mb-4";
    newCard.setAttribute("data-type", tipe);
    newCard.setAttribute("data-index", newIndex);

    if (tipe === "essai") {
        newCard.innerHTML = `
            <input type="hidden" name="soal[${newIndex}][id_soal]" value="0" class="field-id-soal">
            <input type="hidden" name="soal[${newIndex}][jenis_soal]" value="essai" class="field-jenis-soal">
            
            <div class="flex-between mb-3 pb-2" style="border-bottom: 1px solid var(--gray-200);">
                <div class="flex gap-2" style="align-items: center;">
                    <h3 class="font-bold" style="font-size: 1.25rem; color: #7c3aed; margin: 0; min-width: 28px;">
                        <span class="nomor-pertanyaan">${newNum}</span>.
                    </h3>
                    <span class="badge" style="background: #ede9fe; color: #6d28d9; font-weight: 700;">Soal Essai</span>
                </div>
                <button type="button" class="btn btn-sm btn-outline text-danger btn-hapus-pertanyaan" onclick="hapusPertanyaan(this)">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                    <span>Hapus</span>
                </button>
            </div>

            <div class="form-group">
                <label>Teks Pertanyaan Essai <span class="text-danger">*</span></label>
                <textarea name="soal[${newIndex}][pertanyaan]" class="form-control field-pertanyaan" rows="4" required placeholder="Tuliskan butir soal pertanyaan uraian / essai di sini..."></textarea>
            </div>

            <div class="form-group mt-3">
                <label>Lampiran Gambar (Opsional)</label>
                <div class="preview-gambar-container mb-2" style="display: none; align-items: center; gap: 0.75rem;">
                    <img class="img-preview" src="" alt="Preview" style="max-height: 100px; border-radius: 6px; border: 1px solid var(--gray-300); box-shadow: var(--shadow-sm);">
                    <button type="button" class="btn btn-sm btn-outline text-danger" onclick="hapusPreviewGambar(this)">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                        <span>Batal Gambar</span>
                    </button>
                </div>
                <input type="hidden" name="soal[${newIndex}][gambar_base64]" class="field-gambar-base64" value="">
                <input type="file" name="gambar_${newIndex}" class="form-control field-file-gambar" accept="image/*" onchange="previewDanKompresGambar(this)">
                <small style="color: var(--gray-500); font-size: 0.8rem; display: block; margin-top: 0.25rem;">
                    Format didukung: JPG, PNG, GIF, WebP. Gambar otomatis dikompresi agar cepat dimuat dan tidak gagal upload.
                </small>
            </div>

            <div class="area-essai">
                <hr style="border: 0; border-top: 1px solid var(--gray-200); margin: 1.25rem 0;">
                <div class="form-group">
                    <label style="color: #6d28d9; font-weight: 600;">Pedoman Jawaban / Kata Kunci Essai (Opsional)</label>
                    <textarea name="soal[${newIndex}][kunci_jawaban]" class="form-control field-kunci-essai" rows="2" placeholder="Catatan kunci penilaian untuk guru saat memeriksa..."></textarea>
                </div>
            </div>
        `;
    } else {
        newCard.innerHTML = `
            <input type="hidden" name="soal[${newIndex}][id_soal]" value="0" class="field-id-soal">
            <input type="hidden" name="soal[${newIndex}][jenis_soal]" value="pilihan_ganda" class="field-jenis-soal">
            
            <div class="flex-between mb-3 pb-2" style="border-bottom: 1px solid var(--gray-200);">
                <div class="flex gap-2" style="align-items: center;">
                    <h3 class="font-bold" style="font-size: 1.25rem; color: var(--primary); margin: 0; min-width: 28px;">
                        <span class="nomor-pertanyaan">${newNum}</span>.
                    </h3>
                    <span class="badge badge-jenis" style="background: #e0f2fe; color: #0369a1; font-weight: 700;">Pilihan Ganda</span>
                </div>
                <button type="button" class="btn btn-sm btn-outline text-danger btn-hapus-pertanyaan" onclick="hapusPertanyaan(this)">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                    <span>Hapus</span>
                </button>
            </div>

            <div class="form-group">
                <label>Teks Pertanyaan <span class="text-danger">*</span></label>
                <textarea name="soal[${newIndex}][pertanyaan]" class="form-control field-pertanyaan" rows="4" required placeholder="Tuliskan teks butir pertanyaan di sini..."></textarea>
            </div>

            <div class="form-group mt-3">
                <label>Lampiran Gambar (Opsional)</label>
                <div class="preview-gambar-container mb-2" style="display: none; align-items: center; gap: 0.75rem;">
                    <img class="img-preview" src="" alt="Preview" style="max-height: 100px; border-radius: 6px; border: 1px solid var(--gray-300); box-shadow: var(--shadow-sm);">
                    <button type="button" class="btn btn-sm btn-outline text-danger" onclick="hapusPreviewGambar(this)">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                        <span>Batal Gambar</span>
                    </button>
                </div>
                <input type="hidden" name="soal[${newIndex}][gambar_base64]" class="field-gambar-base64" value="">
                <input type="file" name="gambar_${newIndex}" class="form-control field-file-gambar" accept="image/*" onchange="previewDanKompresGambar(this)">
                <small style="color: var(--gray-500); font-size: 0.8rem; display: block; margin-top: 0.25rem;">
                    Format didukung: JPG, PNG, GIF, WebP. Gambar otomatis dikompresi agar cepat dimuat dan tidak gagal upload.
                </small>
            </div>

            <div class="area-pilihan-ganda">
                <hr style="border: 0; border-top: 1px solid var(--gray-200); margin: 1.25rem 0;">
                <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                    <!-- A -->
                    <div style="display: flex; align-items: center; gap: 0.65rem;">
                        <label style="display: inline-flex; align-items: center; gap: 0.45rem; cursor: pointer; font-weight: 700; font-size: 0.95rem; min-width: 58px; justify-content: center; user-select: none; background: #f8fafc; padding: 0.5rem 0.65rem; border-radius: 6px; border: 1px solid var(--gray-300);" title="Centang jika pilihan A adalah kunci jawaban benar">
                            <input type="checkbox" name="soal[${newIndex}][kunci][]" value="A" class="field-kunci-cb" style="width: 18px; height: 18px; cursor: pointer; accent-color: #16a34a;">
                            <span>A</span>
                        </label>
                        <input type="text" name="soal[${newIndex}][opsi_a]" class="form-control field-opsi" required placeholder="Teks pilihan A..." style="flex: 1;">
                    </div>

                    <!-- B -->
                    <div style="display: flex; align-items: center; gap: 0.65rem;">
                        <label style="display: inline-flex; align-items: center; gap: 0.45rem; cursor: pointer; font-weight: 700; font-size: 0.95rem; min-width: 58px; justify-content: center; user-select: none; background: #f8fafc; padding: 0.5rem 0.65rem; border-radius: 6px; border: 1px solid var(--gray-300);" title="Centang jika pilihan B adalah kunci jawaban benar">
                            <input type="checkbox" name="soal[${newIndex}][kunci][]" value="B" class="field-kunci-cb" style="width: 18px; height: 18px; cursor: pointer; accent-color: #16a34a;">
                            <span>B</span>
                        </label>
                        <input type="text" name="soal[${newIndex}][opsi_b]" class="form-control field-opsi" required placeholder="Teks pilihan B..." style="flex: 1;">
                    </div>

                    <!-- C -->
                    <div style="display: flex; align-items: center; gap: 0.65rem;">
                        <label style="display: inline-flex; align-items: center; gap: 0.45rem; cursor: pointer; font-weight: 700; font-size: 0.95rem; min-width: 58px; justify-content: center; user-select: none; background: #f8fafc; padding: 0.5rem 0.65rem; border-radius: 6px; border: 1px solid var(--gray-300);" title="Centang jika pilihan C adalah kunci jawaban benar">
                            <input type="checkbox" name="soal[${newIndex}][kunci][]" value="C" class="field-kunci-cb" style="width: 18px; height: 18px; cursor: pointer; accent-color: #16a34a;">
                            <span>C</span>
                        </label>
                        <input type="text" name="soal[${newIndex}][opsi_c]" class="form-control field-opsi" required placeholder="Teks pilihan C..." style="flex: 1;">
                    </div>

                    <!-- D -->
                    <div style="display: flex; align-items: center; gap: 0.65rem;">
                        <label style="display: inline-flex; align-items: center; gap: 0.45rem; cursor: pointer; font-weight: 700; font-size: 0.95rem; min-width: 58px; justify-content: center; user-select: none; background: #f8fafc; padding: 0.5rem 0.65rem; border-radius: 6px; border: 1px solid var(--gray-300);" title="Centang jika pilihan D adalah kunci jawaban benar">
                            <input type="checkbox" name="soal[${newIndex}][kunci][]" value="D" class="field-kunci-cb" style="width: 18px; height: 18px; cursor: pointer; accent-color: #16a34a;">
                            <span>D</span>
                        </label>
                        <input type="text" name="soal[${newIndex}][opsi_d]" class="form-control field-opsi" required placeholder="Teks pilihan D..." style="flex: 1;">
                    </div>

                    <!-- E -->
                    <div style="display: flex; align-items: center; gap: 0.65rem;">
                        <label style="display: inline-flex; align-items: center; gap: 0.45rem; cursor: pointer; font-weight: 700; font-size: 0.95rem; min-width: 58px; justify-content: center; user-select: none; background: #f8fafc; padding: 0.5rem 0.65rem; border-radius: 6px; border: 1px solid var(--gray-300);" title="Centang jika pilihan E adalah kunci jawaban benar">
                            <input type="checkbox" name="soal[${newIndex}][kunci][]" value="E" class="field-kunci-cb" style="width: 18px; height: 18px; cursor: pointer; accent-color: #16a34a;">
                            <span>E</span>
                        </label>
                        <input type="text" name="soal[${newIndex}][opsi_e]" class="form-control field-opsi" placeholder="Teks pilihan E (opsional)..." style="flex: 1;">
                    </div>
                </div>
            </div>
        `;
    }

    container.appendChild(newCard);
    updateQuestionNumbers();

    // Scroll otomatis ke kartu pertanyaan baru
    newCard.scrollIntoView({ behavior: "smooth", block: "center" });
    
    // Auto focus ke textarea baru
    const newTextarea = newCard.querySelector("textarea");
    if (newTextarea) {
        setTimeout(() => newTextarea.focus(), 250);
    }
}

function hapusPertanyaan(btn) {
    const card = btn.closest(".pertanyaan-card");
    const total = document.querySelectorAll(".pertanyaan-card").length;
    if (total > 1) {
        cbtConfirm({
            title: "Hapus Pertanyaan",
            message: "Apakah Anda yakin ingin menghapus butir pertanyaan ini dari paket?",
            type: "danger",
            confirmText: "Ya, Hapus"
        }).then(ok => {
            if (ok) {
                card.remove();
                updateQuestionNumbers();
            }
        });
    }
}
</script>
JS;

include __DIR__ . '/../layouts/footer.php';
