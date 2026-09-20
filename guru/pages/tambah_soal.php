<?php
/**
 * Page: Tambah & Edit Paket Soal (Dukungan Penuh 7 Bentuk Soal CBT SDN Talun)
 * 1. PG-1      : Pilihan Ganda 1 Jawaban Benar (Bobot: 2.00)
 * 2. PGK-L1   : Pilihan Ganda Kompleks > 1 Jawaban Benar (Bobot: 3.00)
 * 3. PGK-BS-1  : Benar - Salah 1 Pernyataan (Bobot: 1.00)
 * 4. PGK-BS-L1 : Benar - Salah > 1 Pernyataan (Bobot: 6.00)
 * 5. MJDK      : Menjodohkan (Bobot: 6.00)
 * 6. IJS       : Isian / Jawaban Singkat (Bobot: 5.00)
 * 7. URAIAN    : Uraian / Esai (Bobot: 7.00)
 */

require_once __DIR__ . '/../../middleware/auth.php';
require_once __DIR__ . '/../../config/scoring.php';

$currentUser = auth_check(['guru', 'operator']);
$db = get_db();

$idGuru = $currentUser['id_user'];
$isGuru = ($currentUser['role'] === 'guru');

// Tangani parameter inisialisasi
$initPaketId  = !empty($_GET['id_paket']) ? (int)$_GET['id_paket'] : 0;
$initMapel    = !empty($_GET['id_mapel']) ? (int)$_GET['id_mapel'] : 0;
$initJudul    = trim($_GET['judul_soal'] ?? '');
$editSingleId = !empty($_GET['edit']) ? (int)$_GET['edit'] : 0;

// Jika datang dari parameter edit butir soal tunggal
if ($editSingleId > 0 && $initPaketId <= 0) {
    $sqlSingle = "
        SELECT b.id_soal, b.id_paket, p.id_mapel, p.nama_paket 
        FROM bank_soal b
        JOIN paket_soal p ON b.id_paket = p.id_paket
        WHERE b.id_soal = :id
    ";
    $pSingle = [':id' => $editSingleId];
    if ($isGuru) {
        $sqlSingle .= " AND p.id_guru = :g";
        $pSingle[':g'] = $idGuru;
    }
    $stmtSingle = $db->prepare($sqlSingle);
    $stmtSingle->execute($pSingle);
    $singleData = $stmtSingle->fetch();
    if ($singleData) {
        $initPaketId = (int)$singleData['id_paket'];
        $initMapel   = (int)$singleData['id_mapel'];
        $initJudul   = $singleData['nama_paket'];
    }
}

// Jika id_paket ditentukan, ambil detail paket
if ($initPaketId > 0) {
    $sqlP = "SELECT * FROM paket_soal WHERE id_paket = :id";
    $pP = [':id' => $initPaketId];
    if ($isGuru) {
        $sqlP .= " AND id_guru = :g";
        $pP[':g'] = $idGuru;
    }
    $stmtP = $db->prepare($sqlP);
    $stmtP->execute($pP);
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
        $judulSoal = 'Asesmen SDN Talun';
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
            $sqlUpdPaket = "UPDATE paket_soal SET id_mapel = :m, nama_paket = :j WHERE id_paket = :p";
            $pUpdPaket   = [':m' => $idMapel, ':j' => $judulSoal, ':p' => $idPaket];
            if ($isGuru) {
                $sqlUpdPaket .= " AND id_guru = :g";
                $pUpdPaket[':g'] = $idGuru;
            }
            $stmtUpdPaket = $db->prepare($sqlUpdPaket);
            $stmtUpdPaket->execute($pUpdPaket);
        } else {
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

        // Kumpulkan ID butir soal yang masih dipertahankan
        $submittedIds = [];
        foreach ($soalItems as $item) {
            $sId = (int)($item['id_soal'] ?? 0);
            if ($sId > 0) {
                $submittedIds[] = $sId;
            }
        }

        // Hapus butir soal lama yang dibuang
        $stmtOld = $db->prepare("SELECT id_soal, gambar FROM bank_soal WHERE id_paket = :p");
        $stmtOld->execute([':p' => $idPaket]);
        $oldRows = $stmtOld->fetchAll();

        foreach ($oldRows as $oldR) {
            if (!in_array((int)$oldR['id_soal'], $submittedIds, true)) {
                if (!empty($oldR['gambar']) && file_exists(__DIR__ . '/../../' . ltrim($oldR['gambar'], '/'))) {
                    @unlink(__DIR__ . '/../../' . ltrim($oldR['gambar'], '/'));
                }
                $delStmt = $db->prepare("DELETE FROM bank_soal WHERE id_soal = :id AND id_paket = :p");
                $delStmt->execute([':id' => $oldR['id_soal'], ':p' => $idPaket]);
            }
        }

        // Statement INSERT & UPDATE
        $stmtInsert = $db->prepare("
            INSERT INTO bank_soal (id_paket, jenis_soal, pertanyaan, gambar, opsi_a, opsi_b, opsi_c, opsi_d, opsi_e, kunci_jawaban, bobot_soal, konten_soal)
            VALUES (:p, :jenis, :pert, :gbr, :oa, :ob, :oc, :od, :oe, :k, :bobot, :konten)
        ");

        $stmtUpdate = $db->prepare("
            UPDATE bank_soal 
            SET jenis_soal = :jenis, pertanyaan = :pert, gambar = :gbr, 
                opsi_a = :oa, opsi_b = :ob, opsi_c = :oc, opsi_d = :od, opsi_e = :oe, kunci_jawaban = :k,
                bobot_soal = :bobot, konten_soal = :konten
            WHERE id_soal = :id AND id_paket = :p
        ");

        $savedCount = 0;

        foreach ($soalItems as $idx => $item) {
            $itemSoalId = (int)($item['id_soal'] ?? 0);
            $rawJenis   = trim($item['jenis_soal'] ?? 'pg_1');
            $pertanyaan = trim($item['pertanyaan'] ?? '');

            if ($pertanyaan === '') {
                continue;
            }

            // Bobot Soal
            $bobotVal = (isset($item['bobot_soal']) && is_numeric($item['bobot_soal']) && (float)$item['bobot_soal'] > 0)
                ? (float)$item['bobot_soal']
                : cbt_get_default_bobot($rawJenis);

            // Kelola file gambar
            $gambarPath = !empty($item['existing_gambar']) ? $item['existing_gambar'] : null;
            if (!empty($item['hapus_gambar']) && $gambarPath) {
                if (file_exists(__DIR__ . '/../../' . ltrim($gambarPath, '/'))) {
                    @unlink(__DIR__ . '/../../' . ltrim($gambarPath, '/'));
                }
                $gambarPath = null;
            }

            $baseRootDir = dirname(__DIR__, 2);
            $uploadDir   = $baseRootDir . '/assets/uploads/';
            if (!is_dir($uploadDir)) {
                @mkdir($uploadDir, 0777, true);
                @chmod($uploadDir, 0777);
            }

            // 1. Cek gambar Canvas Base64
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
            // 2. Upload file multipart standar
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
                            if ($gambarPath && file_exists($baseRootDir . '/' . ltrim($gambarPath, '/'))) {
                                @unlink($baseRootDir . '/' . ltrim($gambarPath, '/'));
                            }
                            $gambarPath = 'assets/uploads/' . $newFileName;
                        }
                    }
                }
            }

            // Normalisasi parameter spesifik tiap bentuk soal
            $opsiA = null; $opsiB = null; $opsiC = null; $opsiD = null; $opsiE = null;
            $kunci = null;
            $kontenJson = null;

            // 1. URAIAN / ESSAI
            if ($rawJenis === 'uraian' || $rawJenis === 'essai') {
                $rawJenis = 'uraian';
                $kunci    = trim($item['kunci_jawaban'] ?? '');
            }
            // 2. ISIAN SINGKAT (IJS)
            elseif ($rawJenis === 'ijs') {
                $kunci = trim($item['kunci_jawaban'] ?? $item['kunci_ijs'] ?? '');
            }
            // 3. BENAR / SALAH 1 PERNYATAAN (PGK-BS-1)
            elseif ($rawJenis === 'pgk_bs_1') {
                $kunci = strtoupper(trim($item['kunci_bs_1'] ?? $item['kunci_jawaban'] ?? 'B'));
                if ($kunci !== 'S') $kunci = 'B';
            }
            // 4. BENAR / SALAH > 1 PERNYATAAN (PGK-BS-L1)
            elseif ($rawJenis === 'pgk_bs_l1') {
                $pTexts = $item['bs_l1_pernyataan'] ?? [];
                $pKunci = $item['bs_l1_kunci'] ?? [];
                $pRows  = [];
                $kArr   = [];

                foreach ($pTexts as $pi => $pt) {
                    $pt = trim($pt);
                    if ($pt === '') continue;
                    $kVal = strtoupper($pKunci[$pi] ?? 'B');
                    if ($kVal !== 'S') $kVal = 'B';
                    $pRows[] = [
                        'id'         => (string)$pi,
                        'pernyataan' => $pt,
                        'kunci'      => $kVal
                    ];
                    $kArr[] = $kVal;
                }

                if (empty($pRows)) {
                    // Default fallback jika belum diisi
                    $pRows[] = ['id' => '0', 'pernyataan' => 'Pernyataan 1', 'kunci' => 'B'];
                    $kArr[] = 'B';
                }

                $kontenJson = json_encode(['pernyataan' => $pRows]);
                $kunci      = implode(',', $kArr);
            }
            // 5. MENJODOHKAN (MJDK)
            elseif ($rawJenis === 'mjdk') {
                $premisTexts  = $item['mjdk_premis'] ?? [];
                $pilihanTexts = $item['mjdk_pilihan'] ?? [];
                $kunciPairs   = $item['mjdk_kunci'] ?? [];

                $premisList = [];
                foreach ($premisTexts as $pi => $pt) {
                    $pt = trim($pt);
                    if ($pt === '') continue;
                    $premisList[] = ['id' => 'p' . ($pi + 1), 'teks' => $pt];
                }

                $pilihanList = [];
                foreach ($pilihanTexts as $ji => $jt) {
                    $jt = trim($jt);
                    if ($jt === '') continue;
                    $pilihanList[] = ['id' => 'j' . ($ji + 1), 'teks' => $jt];
                }

                $validKunci = [];
                if (is_array($kunciPairs)) {
                    foreach ($kunciPairs as $pk => $jk) {
                        if ($pk !== '' && $jk !== '') {
                            $validKunci[$pk] = $jk;
                        }
                    }
                }

                // Default pairing jika belum ada kunci eksplisit (input baris demi baris):
                // Setiap premis p1 dipasangkan dengan pilihan j1, p2 dengan j2, dst.
                if (empty($validKunci)) {
                    foreach ($premisList as $pIdx => $pItem) {
                        if (isset($pilihanList[$pIdx])) {
                            $validKunci[$pItem['id']] = $pilihanList[$pIdx]['id'];
                        }
                    }
                }

                $kontenJson = json_encode([
                    'premis'  => $premisList,
                    'pilihan' => $pilihanList,
                    'kunci'   => $validKunci
                ]);
                $kunci = json_encode($validKunci);
            }
            // 6. PILIHAN GANDA (PG-1 & PGK-L1)
            else {
                $opsiA    = trim($item['opsi_a'] ?? '');
                $opsiB    = trim($item['opsi_b'] ?? '');
                $opsiC    = trim($item['opsi_c'] ?? '');
                $opsiD    = trim($item['opsi_d'] ?? '');
                $opsiE    = trim($item['opsi_e'] ?? '');
                $kunciArr = $item['kunci'] ?? [];
                $kunci    = is_array($kunciArr) ? strtoupper(implode(',', array_filter($kunciArr))) : strtoupper(trim($kunciArr));

                if ($rawJenis === 'pgk_l1' || count(array_filter(explode(',', $kunci))) > 1) {
                    $rawJenis = 'pgk_l1';
                } else {
                    $rawJenis = 'pg_1';
                }
            }

            $params = [
                ':p'      => $idPaket,
                ':jenis'  => $rawJenis,
                ':pert'   => $pertanyaan,
                ':gbr'    => $gambarPath,
                ':oa'     => $opsiA,
                ':ob'     => $opsiB,
                ':oc'     => $opsiC,
                ':od'     => $opsiD,
                ':oe'     => $opsiE !== '' ? $opsiE : null,
                ':k'      => $kunci !== '' ? $kunci : null,
                ':bobot'  => $bobotVal,
                ':konten' => $kontenJson
            ];

            if ($itemSoalId > 0) {
                $params[':id'] = $itemSoalId;
                $stmtUpdate->execute($params);
            } else {
                $stmtInsert->execute($params);
            }
            $savedCount++;
        }

        $db->commit();

        if ($savedCount > 0) {
            flash_set('success', "Paket soal '{$judulSoal}' berhasil disimpan ({$savedCount} butir pertanyaan).");
        } else {
            flash_set('warning', 'Tidak ada butir soal yang disimpan.');
        }
    } catch (Exception $e) {
        $db->rollBack();
        flash_set('danger', 'Gagal menyimpan paket soal: ' . $e->getMessage());
    }

    redirect(base_url('guru?page=bank_soal' . ($idMapel ? '&id_mapel=' . $idMapel : '')));
}

// Ambil Daftar Mapel
$stmtMapel = $db->query("SELECT * FROM mapel ORDER BY COALESCE(urutan, 0) ASC, nama_mapel ASC");
$mapelList = $stmtMapel->fetchAll();

// Ambil Daftar Judul untuk auto-suggest
$stmtJudul = $db->prepare("SELECT DISTINCT nama_paket FROM paket_soal WHERE id_guru = :g ORDER BY nama_paket ASC");
$stmtJudul->execute([':g' => $idGuru]);
$existingJudul = $stmtJudul->fetchAll(PDO::FETCH_COLUMN);

// Susun list pertanyaan yang akan dirender
$cardsToRender = !empty($existingQuestions) ? $existingQuestions : [
    [
        'id_soal'       => 0,
        'jenis_soal'    => 'pg_1',
        'pertanyaan'    => '',
        'gambar'        => null,
        'opsi_a'        => '',
        'opsi_b'        => '',
        'opsi_c'        => '',
        'opsi_d'        => '',
        'opsi_e'        => '',
        'kunci_jawaban' => '',
        'bobot_soal'    => 2.00,
        'konten_soal'   => null
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
                Mendukung 7 bentuk soal: PG-1, PGK-L1, PGK-BS-1, PGK-BS-L1, Menjodohkan, Isian Singkat, dan Uraian.
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

    <form action="<?= base_url('guru?page=tambah_soal' . ($initPaketId ? '&id_paket=' . $initPaketId : '')) ?>" method="POST" enctype="multipart/form-data" id="form-paket-soal">
        <?= csrf_field() ?>
        <input type="hidden" name="id_paket" value="<?= $initPaketId ?>">

        <!-- HEADER INFORMASI PAKET SOAL -->
        <div class="card mb-4" style="background: var(--white); border-left: 4px solid var(--primary);">
            <h2 style="font-size: 1.1rem; font-weight: 700; margin-bottom: 1rem; color: var(--gray-900);">Informasi Paket Soal</h2>
            <div class="form-group mb-3">
                <label for="id_mapel">Mata Pelajaran <span class="text-danger">*</span></label>
                <select name="id_mapel" id="id_mapel" class="form-control" required>
                    <option value="">-- Pilih Mata Pelajaran --</option>
                    <?php foreach ($mapelList as $m): ?>
                        <option value="<?= $m['id_mapel'] ?>" <?= ($initMapel === (int)$m['id_mapel']) ? 'selected' : '' ?>>
                            <?= sanitize($m['nama_mapel']) ?> (<?= sanitize($m['kode_mapel']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group mb-2">
                <label for="judul_soal">Nama / Judul Paket Soal <span class="text-danger">*</span></label>
                <input type="text" name="judul_soal" id="judul_soal" class="form-control" list="list_judul" value="<?= sanitize($initJudul) ?>" placeholder="Contoh: Asesmen Sumatif Akhir Semester" required autocomplete="off">
                <datalist id="list_judul">
                    <?php foreach ($existingJudul as $ej): ?>
                        <option value="<?= sanitize($ej) ?>"></option>
                    <?php endforeach; ?>
                    <option value="Asesmen Harian"></option>
                    <option value="Penilaian Tengah Semester"></option>
                    <option value="Penilaian Akhir Semester"></option>
                </datalist>
            </div>
        </div>

        <!-- CONTAINER DAFTAR BUTIR PERTANYAAN -->
        <div id="container-pertanyaan">
            <?php foreach ($cardsToRender as $idx => $q): ?>
                <?php 
                $qJenis = cbt_normalize_jenis_soal($q['jenis_soal'] ?? 'pg_1', $q['kunci_jawaban'] ?? null);
                $qMeta  = cbt_get_soal_meta($qJenis, $q['kunci_jawaban'] ?? null);
                $qBobot = (isset($q['bobot_soal']) && $q['bobot_soal'] !== null && is_numeric($q['bobot_soal']))
                    ? (float)$q['bobot_soal']
                    : (float)$qMeta['default_bobot'];
                $qId    = (int)($q['id_soal'] ?? 0);
                $kunciSelected = explode(',', $q['kunci_jawaban'] ?? '');
                $kontenDecoded = !empty($q['konten_soal']) ? (is_array($q['konten_soal']) ? $q['konten_soal'] : json_decode((string)$q['konten_soal'], true)) : null;
                ?>
                <div class="card pertanyaan-card mb-4" data-type="<?= $qJenis ?>" data-index="<?= $idx ?>">
                    <input type="hidden" name="soal[<?= $idx ?>][id_soal]" value="<?= $qId ?>" class="field-id-soal">
                    
                    <div class="flex-between mb-3 pb-2" style="border-bottom: 1px solid var(--gray-200); flex-wrap: wrap; gap: 0.5rem;">
                        <div class="flex gap-2" style="align-items: center; flex-wrap: wrap;">
                            <h3 class="font-bold" style="font-size: 1.25rem; color: var(--primary); margin: 0; min-width: 28px;">
                                <span class="nomor-pertanyaan"><?= $idx + 1 ?></span>.
                            </h3>
                            
                            <!-- Dropdown Bentuk Soal -->
                            <select name="soal[<?= $idx ?>][jenis_soal]" class="form-control field-jenis-soal" style="width: auto; font-weight: 700; font-size: 0.85rem;" onchange="gantiJenisSoal(this)">
                                <option value="pg_1" <?= ($qJenis === 'pg_1') ? 'selected' : '' ?>>PG-1: Pilihan Ganda (1 Jawaban Benar)</option>
                                <option value="pgk_l1" <?= ($qJenis === 'pgk_l1') ? 'selected' : '' ?>>PGK-L1: Pilihan Ganda Kompleks (> 1 Jawaban)</option>
                                <option value="pgk_bs_1" <?= ($qJenis === 'pgk_bs_1') ? 'selected' : '' ?>>PGK-BS-1: Benar / Salah (1 Pernyataan)</option>
                                <option value="pgk_bs_l1" <?= ($qJenis === 'pgk_bs_l1') ? 'selected' : '' ?>>PGK-BS-L1: Benar / Salah (> 1 Pernyataan)</option>
                                <option value="mjdk" <?= ($qJenis === 'mjdk') ? 'selected' : '' ?>>MJDK: Menjodohkan</option>
                                <option value="ijs" <?= ($qJenis === 'ijs') ? 'selected' : '' ?>>IJS: Isian / Jawaban Singkat</option>
                                <option value="uraian" <?= ($qJenis === 'uraian') ? 'selected' : '' ?>>Uraian / Esai</option>
                            </select>

                            <!-- Input Skor / Bobot Butir Soal -->
                            <div style="display: flex; align-items: center; gap: 0.35rem; font-size: 0.85rem; font-weight: 600; color: #475569;">
                                <span>Bobot Skor:</span>
                                <input type="number" step="0.5" min="0.5" max="50" name="soal[<?= $idx ?>][bobot_soal]" value="<?= $qBobot ?>" class="form-control field-bobot-soal" style="width: 75px; text-align: center; font-weight: 700;">
                            </div>
                        </div>

                        <button type="button" class="btn btn-sm btn-outline text-danger btn-hapus-pertanyaan" onclick="hapusPertanyaan(this)" style="<?= count($cardsToRender) > 1 ? '' : 'display: none;' ?>">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                            <span>Hapus</span>
                        </button>
                    </div>

                    <!-- Teks Pertanyaan -->
                    <div class="form-group">
                        <label>Teks Pertanyaan / Instruksi Soal <span class="text-danger">*</span></label>
                        <textarea name="soal[<?= $idx ?>][pertanyaan]" class="form-control field-pertanyaan" rows="3" required placeholder="Tuliskan butir soal pertanyaan di sini..."><?= sanitize($q['pertanyaan'] ?? '') ?></textarea>
                    </div>

                    <!-- Lampiran Gambar -->
                    <div class="form-group mt-2">
                        <label style="font-size:0.85rem; font-weight:600; color:#475569;">Lampiran Gambar (Opsional)</label>
                        <?php if (!empty($q['gambar'])): ?>
                            <div class="mb-2 flex gap-3 existing-img-box" style="align-items: center; background: #f8fafc; padding: 0.5rem 0.75rem; border: 1px solid var(--gray-200); border-radius: var(--radius-sm); width: fit-content;">
                                <img src="<?= base_url(sanitize($q['gambar'])) ?>" alt="Gambar Soal" style="max-height: 70px; border-radius: 4px; border: 1px solid var(--gray-300);">
                                <div style="display: flex; flex-direction: column; gap: 0.25rem;">
                                    <span style="font-size: 0.75rem; font-weight: 700; color: #15803d;">Foto Tersimpan</span>
                                    <label style="display: inline-flex; align-items: center; gap: 0.35rem; font-size: 0.8rem; color: var(--danger); cursor: pointer;">
                                        <input type="checkbox" name="soal[<?= $idx ?>][hapus_gambar]" value="1" onchange="this.closest('.existing-img-box').style.opacity = this.checked ? '0.4' : '1';">
                                        <span>Hapus Gambar</span>
                                    </label>
                                </div>
                                <input type="hidden" name="soal[<?= $idx ?>][existing_gambar]" value="<?= sanitize($q['gambar']) ?>">
                            </div>
                        <?php endif; ?>
                        <input type="hidden" name="soal[<?= $idx ?>][gambar_base64]" class="field-gambar-base64" value="">
                        <input type="file" name="gambar_<?= $idx ?>" class="form-control field-file-gambar" accept="image/*" onchange="previewDanKompresGambar(this)">
                    </div>

                    <div class="area-jawaban-spesifik">
                        <!-- A. PILIHAN GANDA (PG-1 / PGK-L1) -->
                        <div class="section-pg" style="<?= in_array($qJenis, ['pg_1', 'pgk_l1'], true) ? '' : 'display: none;' ?>">
                            <hr style="border: 0; border-top: 1px solid var(--gray-200); margin: 1rem 0;">
                            <div style="font-size:0.85rem; font-weight:700; color:#1e293b; margin-bottom:0.6rem;">
                                Opsi Pilihan Jawaban &amp; Checklist Kunci Benar:
                            </div>
                            <div style="display: flex; flex-direction: column; gap: 0.55rem;">
                                <?php foreach (['a' => 'A', 'b' => 'B', 'c' => 'C', 'd' => 'D', 'e' => 'E'] as $key => $label): ?>
                                    <div style="display: flex; align-items: center; gap: 0.65rem;">
                                        <label style="display: flex; align-items: center; gap: 0.35rem; min-width: 65px; margin: 0; font-weight: 700; cursor: pointer;">
                                            <input type="checkbox" name="soal[<?= $idx ?>][kunci][]" value="<?= $label ?>" class="chk-kunci" <?= in_array($label, $kunciSelected, true) ? 'checked' : '' ?>>
                                            <span><?= $label ?>.</span>
                                        </label>
                                        <input type="text" name="soal[<?= $idx ?>][opsi_<?= $key ?>]" class="form-control field-opsi" value="<?= sanitize($q['opsi_' . $key] ?? '') ?>" placeholder="Teks Pilihan <?= $label ?> <?= ($key === 'e') ? '(Opsional SD)' : '' ?>">
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- B. BENAR / SALAH 1 PERNYATAAN (PGK-BS-1) -->
                        <div class="section-bs-1" style="<?= ($qJenis === 'pgk_bs_1') ? '' : 'display: none;' ?>">
                            <hr style="border: 0; border-top: 1px solid var(--gray-200); margin: 1rem 0;">
                            <label style="font-weight: 700; color: #92400e; font-size: 0.9rem;">Kunci Jawaban Pernyataan Ini:</label>
                            <div style="display: flex; gap: 1.5rem; margin-top: 0.4rem;">
                                <label style="display: flex; align-items: center; gap: 0.4rem; cursor: pointer; font-weight: 700; color: #166534;">
                                    <input type="radio" name="soal[<?= $idx ?>][kunci_bs_1]" value="B" <?= (strtoupper($q['kunci_jawaban'] ?? '') !== 'S') ? 'checked' : '' ?>>
                                    <span>BENAR (B)</span>
                                </label>
                                <label style="display: flex; align-items: center; gap: 0.4rem; cursor: pointer; font-weight: 700; color: #dc2626;">
                                    <input type="radio" name="soal[<?= $idx ?>][kunci_bs_1]" value="S" <?= (strtoupper($q['kunci_jawaban'] ?? '') === 'S') ? 'checked' : '' ?>>
                                    <span>SALAH (S)</span>
                                </label>
                            </div>
                        </div>

                        <!-- C. BENAR / SALAH > 1 PERNYATAAN (PGK-BS-L1) -->
                        <div class="section-bs-l1" style="<?= ($qJenis === 'pgk_bs_l1') ? '' : 'display: none;' ?>">
                            <hr style="border: 0; border-top: 1px solid var(--gray-200); margin: 1rem 0;">
                            <label style="font-weight: 700; color: #92400e; font-size: 0.9rem;">Daftar Sub-Pernyataan &amp; Kunci (3 s/d 6 baris):</label>
                            <div class="container-bs-rows" style="display: flex; flex-direction: column; gap: 0.5rem; margin-top: 0.4rem;">
                                <?php 
                                $existingRows = $kontenDecoded['pernyataan'] ?? [
                                    ['pernyataan' => '', 'kunci' => 'B'],
                                    ['pernyataan' => '', 'kunci' => 'S'],
                                    ['pernyataan' => '', 'kunci' => 'B']
                                ];
                                ?>
                                <?php foreach ($existingRows as $rIdx => $row): ?>
                                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                                        <span style="font-weight: 700; min-width: 25px; color:#64748b;"><?= $rIdx + 1 ?>.</span>
                                        <input type="text" name="soal[<?= $idx ?>][bs_l1_pernyataan][]" class="form-control" value="<?= sanitize($row['pernyataan'] ?? '') ?>" placeholder="Tuliskan pernyataan...">
                                        <select name="soal[<?= $idx ?>][bs_l1_kunci][]" class="form-control" style="width: 120px; font-weight: 700;">
                                            <option value="B" <?= (($row['kunci'] ?? 'B') === 'B') ? 'selected' : '' ?>>BENAR</option>
                                            <option value="S" <?= (($row['kunci'] ?? 'B') === 'S') ? 'selected' : '' ?>>SALAH</option>
                                        </select>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- D. MENJODOHKAN (MJDK) -->
                        <div class="section-mjdk" style="<?= ($qJenis === 'mjdk') ? '' : 'display: none;' ?>">
                            <hr style="border: 0; border-top: 1px solid var(--gray-200); margin: 1rem 0;">
                            <label style="font-weight: 700; color: #166534; font-size: 0.9rem;">Pokok Soal &amp; Pasangan Jawaban:</label>
                            <div class="container-mjdk-rows" style="display: flex; flex-direction: column; gap: 0.5rem; margin-top: 0.4rem;">
                                <?php 
                                $premisArr = $kontenDecoded['premis'] ?? [['teks'=>''], ['teks'=>'']];
                                $pilihanArr = $kontenDecoded['pilihan'] ?? [['teks'=>''], ['teks'=>'']];
                                ?>
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                                    <div>
                                        <div style="font-size:0.8rem; font-weight:700; color:#475569; margin-bottom:0.3rem;">Lajur Pokok Soal (Kiri):</div>
                                        <?php for ($p=0; $p<3; $p++): ?>
                                            <input type="text" name="soal[<?= $idx ?>][mjdk_premis][]" class="form-control mb-1" value="<?= sanitize($premisArr[$p]['teks'] ?? '') ?>" placeholder="Pokok Soal <?= $p+1 ?>">
                                        <?php endfor; ?>
                                    </div>
                                    <div>
                                        <div style="font-size:0.8rem; font-weight:700; color:#475569; margin-bottom:0.3rem;">Lajur Jawaban (Kanan):</div>
                                        <?php for ($j=0; $j<3; $j++): ?>
                                            <input type="text" name="soal[<?= $idx ?>][mjdk_pilihan][]" class="form-control mb-1" value="<?= sanitize($pilihanArr[$j]['teks'] ?? '') ?>" placeholder="Pilihan <?= $j+1 ?>">
                                        <?php endfor; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- E. ISIAN SINGKAT (IJS) -->
                        <div class="section-ijs" style="<?= ($qJenis === 'ijs') ? '' : 'display: none;' ?>">
                            <hr style="border: 0; border-top: 1px solid var(--gray-200); margin: 1rem 0;">
                            <label style="font-weight: 700; color: #c2410c; font-size: 0.9rem;">Kunci Jawaban Singkat (Gunakan tanda | jika ada alternatif):</label>
                            <input type="text" name="soal[<?= $idx ?>][kunci_ijs]" class="form-control mt-1" value="<?= sanitize($q['kunci_jawaban'] ?? '') ?>" placeholder="Contoh: Soekarno | Ir. Soekarno">
                            <small style="color: var(--gray-500); font-size: 0.8rem;">Kunci acuan untuk penilaian otomatis oleh sistem CBT. Gunakan tanda | untuk variasi/alternatif jawaban benar.</small>
                        </div>

                        <!-- F. URAIAN / ESSAI -->
                        <div class="section-uraian" style="<?= ($qJenis === 'uraian') ? '' : 'display: none;' ?>">
                            <hr style="border: 0; border-top: 1px solid var(--gray-200); margin: 1rem 0;">
                            <label style="font-weight: 700; color: #6d28d9; font-size: 0.9rem;">Pedoman Penilaian / Rubrik Jawaban Essai (Opsional):</label>
                            <textarea name="soal[<?= $idx ?>][kunci_jawaban]" class="form-control mt-1" rows="2" placeholder="Catatan rubrik kunci jawaban untuk panduan penilaian guru..."><?= sanitize($q['kunci_jawaban'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- TOMBOL TAMBAH BUTIR SOAL -->
        <div class="flex gap-2 mb-4" style="flex-wrap: wrap;">
            <button type="button" class="btn btn-outline" onclick="tambahPertanyaan('pg_1')" style="font-weight: 700;">
                + PG Biasa (PG-1)
            </button>
            <button type="button" class="btn btn-outline" onclick="tambahPertanyaan('pgk_l1')" style="font-weight: 700;">
                + PG Kompleks (PGK-L1)
            </button>
            <button type="button" class="btn btn-outline" onclick="tambahPertanyaan('pgk_bs_1')" style="font-weight: 700;">
                + Benar/Salah (1 Pernyataan)
            </button>
            <button type="button" class="btn btn-outline" onclick="tambahPertanyaan('pgk_bs_l1')" style="font-weight: 700;">
                + Benar/Salah (> 1 Pernyataan)
            </button>
            <button type="button" class="btn btn-outline" onclick="tambahPertanyaan('mjdk')" style="font-weight: 700;">
                + Menjodohkan
            </button>
            <button type="button" class="btn btn-outline" onclick="tambahPertanyaan('ijs')" style="font-weight: 700;">
                + Isian Singkat
            </button>
            <button type="button" class="btn btn-outline" onclick="tambahPertanyaan('uraian')" style="font-weight: 700;">
                + Uraian / Esai
            </button>
        </div>

        <div style="text-align: right; margin-bottom: 3rem;">
            <button type="submit" class="btn btn-primary" style="padding: 0.75rem 2.5rem; font-size: 1.05rem; font-weight: 800;">
                Simpan Seluruh Paket Soal
            </button>
        </div>
    </form>
</main>

<script>
const defaultBobotMap = {
    'pg_1': 2.0,
    'pgk_l1': 2.0,
    'pgk_bs_1': 1.0,
    'pgk_bs_l1': 6.0,
    'mjdk': 6.0,
    'ijs': 5.0,
    'uraian': 7.0
};

// Sinkronkan visibility dan status disabled input agar tidak melebihi limit multipart body parts PHP
function syncCardSectionInputs(card) {
    if (!card) return;
    const jenis = card.getAttribute('data-type') || 'pg_1';
    const isPg = (jenis === 'pg_1' || jenis === 'pgk_l1');
    const isBs1 = (jenis === 'pgk_bs_1');
    const isBsL1 = (jenis === 'pgk_bs_l1');
    const isMjdk = (jenis === 'mjdk');
    const isIjs = (jenis === 'ijs');
    const isUraian = (jenis === 'uraian' || jenis === 'essai');

    function toggleSection(selector, show) {
        const sec = card.querySelector(selector);
        if (!sec) return;
        sec.style.display = show ? 'block' : 'none';
        sec.querySelectorAll('input, select, textarea').forEach(inp => {
            inp.disabled = !show;
        });
    }

    toggleSection('.section-pg', isPg);
    toggleSection('.section-bs-1', isBs1);
    toggleSection('.section-bs-l1', isBsL1);
    toggleSection('.section-mjdk', isMjdk);
    toggleSection('.section-ijs', isIjs);
    toggleSection('.section-uraian', isUraian);
}

function gantiJenisSoal(selectEl) {
    const card = selectEl.closest('.pertanyaan-card');
    if (!card) return;
    const jenis = selectEl.value;
    card.setAttribute('data-type', jenis);

    // Auto set default bobot jika ada
    const bobotInput = card.querySelector('.field-bobot-soal');
    if (bobotInput && defaultBobotMap[jenis] !== undefined) {
        bobotInput.value = defaultBobotMap[jenis];
    }

    syncCardSectionInputs(card);
}

function hapusPertanyaan(btn) {
    const card = btn.closest('.pertanyaan-card');
    const container = document.getElementById('container-pertanyaan');
    if (container.querySelectorAll('.pertanyaan-card').length <= 1) {
        alert('Minimal harus ada 1 butir pertanyaan.');
        return;
    }
    if (confirm('Hapus butir pertanyaan ini?')) {
        card.remove();
        refreshNomorSoal();
    }
}

function refreshNomorSoal() {
    const cards = document.querySelectorAll('.pertanyaan-card');
    cards.forEach((c, i) => {
        c.setAttribute('data-index', i);
        const noEl = c.querySelector('.nomor-pertanyaan');
        if (noEl) noEl.textContent = i + 1;
        
        c.querySelectorAll('[name^="soal["]').forEach(inp => {
            inp.name = inp.name.replace(/soal\[\d+\]/, `soal[${i}]`);
        });

        const fileInp = c.querySelector('.field-file-gambar');
        if (fileInp) {
            fileInp.name = `gambar_${i}`;
        }
    });

    const delBtns = document.querySelectorAll('.btn-hapus-pertanyaan');
    delBtns.forEach(btn => {
        btn.style.display = (cards.length > 1) ? '' : 'none';
    });
}

function tambahPertanyaan(jenis) {
    const container = document.getElementById('container-pertanyaan');
    const newIndex = container.querySelectorAll('.pertanyaan-card').length;
    const newNum = newIndex + 1;
    const defaultBobot = defaultBobotMap[jenis] || 2.0;

    const div = document.createElement('div');
    div.className = 'card pertanyaan-card mb-4';
    div.setAttribute('data-type', jenis);
    div.setAttribute('data-index', newIndex);

    div.innerHTML = `
        <input type="hidden" name="soal[${newIndex}][id_soal]" value="0" class="field-id-soal">
        <div class="flex-between mb-3 pb-2" style="border-bottom: 1px solid var(--gray-200); flex-wrap: wrap; gap: 0.5rem;">
            <div class="flex gap-2" style="align-items: center; flex-wrap: wrap;">
                <h3 class="font-bold" style="font-size: 1.25rem; color: var(--primary); margin: 0; min-width: 28px;">
                    <span class="nomor-pertanyaan">${newNum}</span>.
                </h3>
                <select name="soal[${newIndex}][jenis_soal]" class="form-control field-jenis-soal" style="width: auto; font-weight: 700; font-size: 0.85rem;" onchange="gantiJenisSoal(this)">
                    <option value="pg_1" ${jenis === 'pg_1' ? 'selected' : ''}>PG-1: Pilihan Ganda (1 Jawaban Benar)</option>
                    <option value="pgk_l1" ${jenis === 'pgk_l1' ? 'selected' : ''}>PGK-L1: Pilihan Ganda Kompleks (> 1 Jawaban)</option>
                    <option value="pgk_bs_1" ${jenis === 'pgk_bs_1' ? 'selected' : ''}>PGK-BS-1: Benar / Salah (1 Pernyataan)</option>
                    <option value="pgk_bs_l1" ${jenis === 'pgk_bs_l1' ? 'selected' : ''}>PGK-BS-L1: Benar / Salah (> 1 Pernyataan)</option>
                    <option value="mjdk" ${jenis === 'mjdk' ? 'selected' : ''}>MJDK: Menjodohkan</option>
                    <option value="ijs" ${jenis === 'ijs' ? 'selected' : ''}>IJS: Isian / Jawaban Singkat</option>
                    <option value="uraian" ${jenis === 'uraian' ? 'selected' : ''}>Uraian / Esai</option>
                </select>
                <div style="display: flex; align-items: center; gap: 0.35rem; font-size: 0.85rem; font-weight: 600; color: #475569;">
                    <span>Bobot Skor:</span>
                    <input type="number" step="0.5" min="0.5" max="50" name="soal[${newIndex}][bobot_soal]" value="${defaultBobot}" class="form-control field-bobot-soal" style="width: 75px; text-align: center; font-weight: 700;">
                </div>
            </div>
            <button type="button" class="btn btn-sm btn-outline text-danger btn-hapus-pertanyaan" onclick="hapusPertanyaan(this)">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                <span>Hapus</span>
            </button>
        </div>

        <div class="form-group">
            <label>Teks Pertanyaan / Instruksi Soal <span class="text-danger">*</span></label>
            <textarea name="soal[${newIndex}][pertanyaan]" class="form-control field-pertanyaan" rows="3" required placeholder="Tuliskan butir soal pertanyaan di sini..."></textarea>
        </div>

        <div class="form-group mt-2">
            <label style="font-size:0.85rem; font-weight:600; color:#475569;">Lampiran Gambar (Opsional)</label>
            <input type="hidden" name="soal[${newIndex}][gambar_base64]" class="field-gambar-base64" value="">
            <input type="file" name="gambar_${newIndex}" class="form-control field-file-gambar" accept="image/*" onchange="previewDanKompresGambar(this)">
        </div>

        <div class="area-jawaban-spesifik">
            <div class="section-pg" style="${(jenis === 'pg_1' || jenis === 'pgk_l1') ? '' : 'display: none;'}">
                <hr style="border: 0; border-top: 1px solid var(--gray-200); margin: 1rem 0;">
                <div style="font-size:0.85rem; font-weight:700; color:#1e293b; margin-bottom:0.6rem;">
                    Opsi Pilihan Jawaban &amp; Checklist Kunci Benar:
                </div>
                <div style="display: flex; flex-direction: column; gap: 0.55rem;">
                    <div style="display: flex; align-items: center; gap: 0.65rem;">
                        <label style="display: flex; align-items: center; gap: 0.35rem; min-width: 65px; margin: 0; font-weight: 700; cursor: pointer;">
                            <input type="checkbox" name="soal[${newIndex}][kunci][]" value="A" class="chk-kunci" checked>
                            <span>A.</span>
                        </label>
                        <input type="text" name="soal[${newIndex}][opsi_a]" class="form-control field-opsi" placeholder="Teks Pilihan A">
                    </div>
                    <div style="display: flex; align-items: center; gap: 0.65rem;">
                        <label style="display: flex; align-items: center; gap: 0.35rem; min-width: 65px; margin: 0; font-weight: 700; cursor: pointer;">
                            <input type="checkbox" name="soal[${newIndex}][kunci][]" value="B" class="chk-kunci">
                            <span>B.</span>
                        </label>
                        <input type="text" name="soal[${newIndex}][opsi_b]" class="form-control field-opsi" placeholder="Teks Pilihan B">
                    </div>
                    <div style="display: flex; align-items: center; gap: 0.65rem;">
                        <label style="display: flex; align-items: center; gap: 0.35rem; min-width: 65px; margin: 0; font-weight: 700; cursor: pointer;">
                            <input type="checkbox" name="soal[${newIndex}][kunci][]" value="C" class="chk-kunci">
                            <span>C.</span>
                        </label>
                        <input type="text" name="soal[${newIndex}][opsi_c]" class="form-control field-opsi" placeholder="Teks Pilihan C">
                    </div>
                    <div style="display: flex; align-items: center; gap: 0.65rem;">
                        <label style="display: flex; align-items: center; gap: 0.35rem; min-width: 65px; margin: 0; font-weight: 700; cursor: pointer;">
                            <input type="checkbox" name="soal[${newIndex}][kunci][]" value="D" class="chk-kunci">
                            <span>D.</span>
                        </label>
                        <input type="text" name="soal[${newIndex}][opsi_d]" class="form-control field-opsi" placeholder="Teks Pilihan D">
                    </div>
                </div>
            </div>

            <div class="section-bs-1" style="${jenis === 'pgk_bs_1' ? '' : 'display: none;'}">
                <hr style="border: 0; border-top: 1px solid var(--gray-200); margin: 1rem 0;">
                <label style="font-weight: 700; color: #92400e; font-size: 0.9rem;">Kunci Jawaban Pernyataan Ini:</label>
                <div style="display: flex; gap: 1.5rem; margin-top: 0.4rem;">
                    <label style="display: flex; align-items: center; gap: 0.4rem; cursor: pointer; font-weight: 700; color: #166534;">
                        <input type="radio" name="soal[${newIndex}][kunci_bs_1]" value="B" checked>
                        <span>BENAR (B)</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 0.4rem; cursor: pointer; font-weight: 700; color: #dc2626;">
                        <input type="radio" name="soal[${newIndex}][kunci_bs_1]" value="S">
                        <span>SALAH (S)</span>
                    </label>
                </div>
            </div>

            <div class="section-bs-l1" style="${jenis === 'pgk_bs_l1' ? '' : 'display: none;'}">
                <hr style="border: 0; border-top: 1px solid var(--gray-200); margin: 1rem 0;">
                <label style="font-weight: 700; color: #92400e; font-size: 0.9rem;">Daftar Sub-Pernyataan &amp; Kunci:</label>
                <div class="container-bs-rows" style="display: flex; flex-direction: column; gap: 0.5rem; margin-top: 0.4rem;">
                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                        <span style="font-weight: 700; min-width: 25px; color:#64748b;">1.</span>
                        <input type="text" name="soal[${newIndex}][bs_l1_pernyataan][]" class="form-control" placeholder="Pernyataan 1">
                        <select name="soal[${newIndex}][bs_l1_kunci][]" class="form-control" style="width: 120px; font-weight: 700;">
                            <option value="B">BENAR</option>
                            <option value="S">SALAH</option>
                        </select>
                    </div>
                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                        <span style="font-weight: 700; min-width: 25px; color:#64748b;">2.</span>
                        <input type="text" name="soal[${newIndex}][bs_l1_pernyataan][]" class="form-control" placeholder="Pernyataan 2">
                        <select name="soal[${newIndex}][bs_l1_kunci][]" class="form-control" style="width: 120px; font-weight: 700;">
                            <option value="B">BENAR</option>
                            <option value="S" selected>SALAH</option>
                        </select>
                    </div>
                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                        <span style="font-weight: 700; min-width: 25px; color:#64748b;">3.</span>
                        <input type="text" name="soal[${newIndex}][bs_l1_pernyataan][]" class="form-control" placeholder="Pernyataan 3">
                        <select name="soal[${newIndex}][bs_l1_kunci][]" class="form-control" style="width: 120px; font-weight: 700;">
                            <option value="B" selected>BENAR</option>
                            <option value="S">SALAH</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="section-mjdk" style="${jenis === 'mjdk' ? '' : 'display: none;'}">
                <hr style="border: 0; border-top: 1px solid var(--gray-200); margin: 1rem 0;">
                <label style="font-weight: 700; color: #166534; font-size: 0.9rem;">Pokok Soal &amp; Pasangan Jawaban:</label>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-top: 0.4rem;">
                    <div>
                        <div style="font-size:0.8rem; font-weight:700; color:#475569; margin-bottom:0.3rem;">Lajur Pokok Soal (Kiri):</div>
                        <input type="text" name="soal[${newIndex}][mjdk_premis][]" class="form-control mb-1" placeholder="Pokok Soal 1">
                        <input type="text" name="soal[${newIndex}][mjdk_premis][]" class="form-control mb-1" placeholder="Pokok Soal 2">
                        <input type="text" name="soal[${newIndex}][mjdk_premis][]" class="form-control mb-1" placeholder="Pokok Soal 3">
                    </div>
                    <div>
                        <div style="font-size:0.8rem; font-weight:700; color:#475569; margin-bottom:0.3rem;">Lajur Jawaban (Kanan):</div>
                        <input type="text" name="soal[${newIndex}][mjdk_pilihan][]" class="form-control mb-1" placeholder="Pilihan 1">
                        <input type="text" name="soal[${newIndex}][mjdk_pilihan][]" class="form-control mb-1" placeholder="Pilihan 2">
                        <input type="text" name="soal[${newIndex}][mjdk_pilihan][]" class="form-control mb-1" placeholder="Pilihan 3">
                    </div>
                </div>
            </div>

            <div class="section-ijs" style="${jenis === 'ijs' ? '' : 'display: none;'}">
                <hr style="border: 0; border-top: 1px solid var(--gray-200); margin: 1rem 0;">
                <label style="font-weight: 700; color: #c2410c; font-size: 0.9rem;">Kunci Jawaban Singkat (Penilaian Otomatis):</label>
                <input type="text" name="soal[${newIndex}][kunci_ijs]" class="form-control mt-1" placeholder="Contoh: Soekarno | Ir. Soekarno">
                <small style="color: var(--gray-500); font-size: 0.8rem;">Kunci acuan untuk penilaian otomatis oleh sistem CBT. Gunakan tanda | untuk variasi/alternatif jawaban benar.</small>
            </div>

            <div class="section-uraian" style="${jenis === 'uraian' ? '' : 'display: none;'}">
                <hr style="border: 0; border-top: 1px solid var(--gray-200); margin: 1rem 0;">
                <label style="font-weight: 700; color: #6d28d9; font-size: 0.9rem;">Pedoman Penilaian / Rubrik Jawaban:</label>
                <textarea name="soal[${newIndex}][kunci_jawaban]" class="form-control mt-1" rows="2" placeholder="Catatan rubrik kunci jawaban..."></textarea>
            </div>
        </div>
    `;

    container.appendChild(div);
    syncCardSectionInputs(div);
    refreshNomorSoal();
    div.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function previewDanKompresGambar(input) {
    if (!input.files || !input.files[0]) return;
    const file = input.files[0];
    const reader = new FileReader();

    reader.onload = function(e) {
        const img = new Image();
        img.onload = function() {
            const canvas = document.createElement('canvas');
            let width = img.width;
            let height = img.height;
            const maxDimension = 1200;

            if (width > height && width > maxDimension) {
                height = Math.round((height * maxDimension) / width);
                width = maxDimension;
            } else if (height > maxDimension) {
                width = Math.round((width * maxDimension) / height);
                height = maxDimension;
            }

            canvas.width = width;
            canvas.height = height;
            const ctx = canvas.getContext('2d');
            ctx.drawImage(img, 0, 0, width, height);

            const compressedBase64 = canvas.toDataURL('image/jpeg', 0.82);
            const card = input.closest('.pertanyaan-card');
            const hiddenBase64 = card.querySelector('.field-gambar-base64');
            if (hiddenBase64) hiddenBase64.value = compressedBase64;
        };
        img.src = e.target.result;
    };
    reader.readAsDataURL(file);
}

// Inisialisasi awal saat dokumen siap
document.addEventListener('DOMContentLoaded', function() {
    // Sinkronkan input semua butir pertanyaan yang sudah ada
    document.querySelectorAll('.pertanyaan-card').forEach(card => {
        syncCardSectionInputs(card);
    });

    const form = document.getElementById('form-paket-soal');
    if (form) {
        form.addEventListener('submit', function(e) {
            if (!form.checkValidity()) {
                return;
            }
            document.querySelectorAll('.pertanyaan-card').forEach(card => {
                syncCardSectionInputs(card);
                // Matikan input file yang kosong agar browser tidak mengirim part MIME kosong
                const fileInp = card.querySelector('.field-file-gambar');
                if (fileInp && (!fileInp.files || fileInp.files.length === 0)) {
                    fileInp.disabled = true;
                }
            });
        });
    }
});
</script>

<?php include __DIR__ . '/../layouts/footer.php'; ?>
