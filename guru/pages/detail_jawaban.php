<?php
/**
 * Page: Detail Jawaban Siswa
 * Menampilkan hasil pengerjaan siswa dan koreksi butir soal.
 */

require_once __DIR__ . '/../../middleware/auth.php';

$currentUser = auth_check(['guru', 'operator']);
$db = get_db();

$idUjianSiswa = (int)($_GET['id_ujian_siswa'] ?? $_POST['id_ujian_siswa'] ?? 0);
$backSesiId   = (int)($_GET['id_sesi'] ?? $_POST['id_sesi'] ?? 0);

if ($idUjianSiswa <= 0) {
    flash_set('danger', 'Data ujian tidak valid.');
    redirect(base_url('guru?page=rekap_nilai' . ($backSesiId > 0 ? '&id_sesi=' . $backSesiId : '')));
}

// 1. Ambil Data Ujian Siswa
$stmtUjian = $db->prepare("
    SELECT us.*, 
           u.id_user as id_siswa, u.nis, u.username, u.nama_lengkap as nama_siswa,
           s.id_sesi, s.nama_ujian, s.id_paket, s.id_guru, s.durasi_menit,
           p.nama_paket,
           m.id_mapel, m.nama_mapel, m.kode_mapel,
           k.id_kelas, k.nama_kelas,
           g.nama_lengkap as nama_guru
    FROM ujian_siswa us
    JOIN users u ON us.id_siswa = u.id_user
    JOIN sesi_ujian s ON us.id_sesi = s.id_sesi
    LEFT JOIN paket_soal p ON s.id_paket = p.id_paket
    JOIN mapel m ON s.id_mapel = m.id_mapel
    JOIN kelas k ON s.id_kelas = k.id_kelas
    LEFT JOIN users g ON s.id_guru = g.id_user
    WHERE us.id_ujian_siswa = :us
");
$stmtUjian->execute([':us' => $idUjianSiswa]);
$detailUjian = $stmtUjian->fetch();

if (!$detailUjian) {
    flash_set('danger', 'Data ujian siswa tidak ditemukan.');
    redirect(base_url('guru?page=rekap_nilai' . ($backSesiId > 0 ? '&id_sesi=' . $backSesiId : '')));
}

if ($currentUser['role'] === 'guru' && (int)$detailUjian['id_guru'] !== (int)$currentUser['id_user']) {
    flash_set('danger', 'Anda tidak memiliki akses ke data ini.');
    redirect(base_url('guru?page=rekap_nilai'));
}

// 2. Simpan Nilai Essai
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'simpan_nilai_essai') {
    if (!verify_csrf()) {
        flash_set('danger', 'Validasi keamanan gagal.');
        redirect(base_url('guru?page=detail_jawaban&id_ujian_siswa=' . $idUjianSiswa . ($backSesiId > 0 ? '&id_sesi=' . $backSesiId : '')));
    }

    $inputNilai = $_POST['nilai_soal'] ?? [];

    $stmtUpdSoal = $db->prepare("
        INSERT INTO jawaban_siswa (id_ujian_siswa, id_soal, nilai_soal, updated_at)
        VALUES (:us, :soal, :n, CURRENT_TIMESTAMP)
        ON CONFLICT (id_ujian_siswa, id_soal) 
        DO UPDATE SET 
            nilai_soal = EXCLUDED.nilai_soal,
            updated_at = CURRENT_TIMESTAMP
    ");

    foreach ($inputNilai as $sid => $scoreVal) {
        $sid = (int)$sid;
        $cleanScore = ($scoreVal !== '' && is_numeric($scoreVal)) ? max(0, min(100, (float)$scoreVal)) : null;
        $stmtUpdSoal->execute([
            ':n'    => $cleanScore,
            ':us'   => $idUjianSiswa,
            ':soal' => $sid
        ]);
    }

    $stmtAvgEssai = $db->prepare("
        SELECT js.nilai_soal 
        FROM jawaban_siswa js
        JOIN bank_soal bs ON js.id_soal = bs.id_soal
        WHERE js.id_ujian_siswa = :us AND bs.jenis_soal = 'essai'
    ");
    $stmtAvgEssai->execute([':us' => $idUjianSiswa]);
    $allEssaiScores = $stmtAvgEssai->fetchAll(PDO::FETCH_COLUMN);

    $totalEssaiItems = count($allEssaiScores);
    $filledEssaiScores = array_filter($allEssaiScores, function($v) { return $v !== null && $v !== ''; });

    $avgEssai = null;
    if (!empty($filledEssaiScores)) {
        $avgEssai = round(array_sum($filledEssaiScores) / count($filledEssaiScores), 2);
    }

    $stmtHitungPG = $db->prepare("
        SELECT b.id_soal, b.kunci_jawaban, js.jawaban_terpilih
        FROM bank_soal b
        JOIN jawaban_siswa js ON js.id_soal = b.id_soal
        WHERE js.id_ujian_siswa = :us AND (b.jenis_soal != 'essai' OR b.jenis_soal IS NULL)
    ");
    $stmtHitungPG->execute([':us' => $idUjianSiswa]);
    $pgRows = $stmtHitungPG->fetchAll();

    $totalPGCount = count($pgRows);
    $pgBenarCount = 0;
    foreach ($pgRows as $pgr) {
        $kunciStr = strtoupper(trim($pgr['kunci_jawaban'] ?? ''));
        $jwbStr   = strtoupper(trim($pgr['jawaban_terpilih'] ?? ''));
        if ($kunciStr !== '' && $jwbStr !== '') {
            $kunciArr = array_filter(array_map('trim', explode(',', $kunciStr)));
            $jwbArr   = array_filter(array_map('trim', explode(',', $jwbStr)));
            sort($kunciArr);
            sort($jwbArr);
            if ($kunciArr === $jwbArr || in_array($jwbStr, $kunciArr, true)) {
                $pgBenarCount++;
            }
        }
    }
    $nilaiPG = ($totalPGCount > 0) ? round(($pgBenarCount / $totalPGCount) * 100, 2) : 0.00;

    if ($totalPGCount > 0 && $totalEssaiItems > 0) {
        $nilaiAkhirBaru = ($avgEssai !== null) ? round(($nilaiPG + $avgEssai) / 2, 2) : $nilaiPG;
    } elseif ($totalEssaiItems > 0) {
        $nilaiAkhirBaru = ($avgEssai !== null) ? $avgEssai : 0.00;
    } else {
        $nilaiAkhirBaru = $nilaiPG;
    }

    $stmtUpdUs = $db->prepare("
        UPDATE ujian_siswa 
        SET jumlah_benar = :benar,
            nilai_pg = :npg,
            nilai_essai = :nessai,
            nilai_akhir = :nakhir
        WHERE id_ujian_siswa = :us
    ");
    $stmtUpdUs->execute([
        ':benar'  => $pgBenarCount,
        ':npg'    => $nilaiPG,
        ':nessai' => $avgEssai,
        ':nakhir' => $nilaiAkhirBaru,
        ':us'     => $idUjianSiswa
    ]);

    flash_set('success', "Nilai essai berhasil disimpan. Nilai Akhir: {$nilaiAkhirBaru}");
    redirect(base_url('guru?page=detail_jawaban&id_ujian_siswa=' . $idUjianSiswa . ($backSesiId > 0 ? '&id_sesi=' . $backSesiId : '')));
}

// 3. Urutan Soal
$urutanIds = json_decode($detailUjian['urutan_soal'] ?? '[]', true);
if (empty($urutanIds) || !is_array($urutanIds)) {
    if (!empty($detailUjian['id_paket'])) {
        $stmtFallback = $db->prepare("SELECT id_soal FROM bank_soal WHERE id_paket = :p ORDER BY id_soal ASC");
        $stmtFallback->execute([':p' => $detailUjian['id_paket']]);
    } else {
        $stmtFallback = $db->prepare("SELECT id_soal FROM bank_soal WHERE id_paket IN (SELECT id_paket FROM paket_soal WHERE id_mapel = :m) ORDER BY id_soal ASC");
        $stmtFallback->execute([':m' => $detailUjian['id_mapel']]);
    }
    $urutanIds = $stmtFallback->fetchAll(PDO::FETCH_COLUMN);
}

// 4. Data Butir Soal & Jawaban
$soalList = [];
$statBenar = 0;
$statSalah = 0;
$statKosong = 0;
$statEssai = 0;

if (!empty($urutanIds)) {
    $placeholders = implode(',', array_fill(0, count($urutanIds), '?'));
    
    $stmtSoal = $db->prepare("
        SELECT b.id_soal, b.jenis_soal, b.pertanyaan, b.gambar, 
               b.opsi_a, b.opsi_b, b.opsi_c, b.opsi_d, b.opsi_e, b.kunci_jawaban,
               j.jawaban_terpilih, j.nilai_soal, j.status_ragu
        FROM bank_soal b
        LEFT JOIN jawaban_siswa j ON (j.id_soal = b.id_soal AND j.id_ujian_siswa = ?)
        WHERE b.id_soal IN ($placeholders)
    ");
    
    $queryParams = array_merge([$idUjianSiswa], $urutanIds);
    $stmtSoal->execute($queryParams);
    $rawSoal = $stmtSoal->fetchAll();

    $soalMap = [];
    foreach ($rawSoal as $row) {
        $soalMap[$row['id_soal']] = $row;
    }

    foreach ($urutanIds as $index => $sid) {
        if (!isset($soalMap[$sid])) continue;
        $item = $soalMap[$sid];
        
        $jenisSoal    = $item['jenis_soal'] ?? 'pilihan_ganda';
        $rawJawaban   = trim($item['jawaban_terpilih'] ?? '');
        $rawKunci     = trim($item['kunci_jawaban'] ?? '');
        $jawabanSiswa = ($jenisSoal === 'essai') ? $rawJawaban : strtoupper($rawJawaban);
        $kunciJawaban = ($jenisSoal === 'essai') ? $rawKunci : strtoupper($rawKunci);
        $nilaiSoal    = $item['nilai_soal'] !== null ? (float)$item['nilai_soal'] : null;
        
        $isCorrect = false;
        $statusItem = 'kosong';

        if ($jenisSoal === 'essai') {
            $statEssai++;
            $statusItem = ($nilaiSoal !== null) ? 'dinilai' : 'essai';
        } else {
            if ($jawabanSiswa === '') {
                $statKosong++;
                $statusItem = 'kosong';
            } else {
                $kunciArr = array_filter(array_map('trim', explode(',', $kunciJawaban)));
                $jwbArr   = array_filter(array_map('trim', explode(',', $jawabanSiswa)));
                sort($kunciArr);
                sort($jwbArr);

                if (!empty($kunciArr) && ($kunciArr === $jwbArr || in_array($jawabanSiswa, $kunciArr, true))) {
                    $isCorrect = true;
                    $statBenar++;
                    $statusItem = 'benar';
                } else {
                    $statSalah++;
                    $statusItem = 'salah';
                }
            }
        }

        $opsiList = [
            ['code' => 'A', 'text' => $item['opsi_a']],
            ['code' => 'B', 'text' => $item['opsi_b']],
            ['code' => 'C', 'text' => $item['opsi_c']],
            ['code' => 'D', 'text' => $item['opsi_d']],
        ];
        if (!empty($item['opsi_e'])) {
            $opsiList[] = ['code' => 'E', 'text' => $item['opsi_e']];
        }

        $soalList[] = [
            'nomor'            => $index + 1,
            'id_soal'          => (int)$item['id_soal'],
            'jenis_soal'       => $jenisSoal,
            'pertanyaan'       => $item['pertanyaan'],
            'gambar'           => !empty($item['gambar']) ? base_url(ltrim($item['gambar'], '/')) : null,
            'opsi'             => $opsiList,
            'kunci_jawaban'    => $kunciJawaban,
            'jawaban_terpilih' => $jawabanSiswa,
            'nilai_soal'       => $nilaiSoal,
            'status_item'      => $statusItem,
            'is_correct'       => $isCorrect
        ];
    }
}

$totalPG = count($soalList) - $statEssai;
$calculatedNilaiPG = ($totalPG > 0) ? round(($statBenar / $totalPG) * 100, 2) : 0.00;
$nilaiPGDisplay    = isset($detailUjian['nilai_pg']) && $detailUjian['nilai_pg'] !== null ? (float)$detailUjian['nilai_pg'] : $calculatedNilaiPG;
$nilaiEssaiDisplay = $detailUjian['nilai_essai'] !== null ? (float)$detailUjian['nilai_essai'] : null;

$page = 'detail_jawaban';
$pageTitle = 'Detail Jawaban: ' . $detailUjian['nama_siswa'];

$extraCss = '
<style>
    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1rem;
    }
    .info-panel {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        padding: 1rem 1.25rem;
        margin-bottom: 1.25rem;
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1.5rem;
    }
    @media (max-width: 768px) {
        .info-panel { grid-template-columns: 1fr; gap: 0.75rem; }
    }
    .info-row {
        display: flex;
        margin-bottom: 0.35rem;
        font-size: 0.9rem;
    }
    .info-label {
        width: 130px;
        color: #64748b;
        font-weight: 500;
    }
    .info-val {
        color: #0f172a;
        font-weight: 600;
    }
    .scores-panel {
        display: flex;
        gap: 1rem;
        margin-bottom: 1.5rem;
        flex-wrap: wrap;
    }
    .score-card {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        padding: 0.75rem 1.25rem;
        min-width: 140px;
        flex: 1;
    }
    .score-card .title {
        font-size: 0.75rem;
        color: #64748b;
        text-transform: uppercase;
        font-weight: 600;
    }
    .score-card .number {
        font-size: 1.4rem;
        font-weight: 700;
        color: #0f172a;
        margin-top: 0.15rem;
    }
    .item-card {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        padding: 1.25rem;
        margin-bottom: 1rem;
    }
    .item-head {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding-bottom: 0.5rem;
        margin-bottom: 0.75rem;
        border-bottom: 1px solid #f1f5f9;
        font-size: 0.9rem;
    }
    .item-question {
        font-size: 0.95rem;
        line-height: 1.55;
        color: #1e293b;
        margin-bottom: 0.85rem;
    }
    .opt-list {
        display: flex;
        flex-direction: column;
        gap: 0.35rem;
        margin-bottom: 0.75rem;
    }
    .opt-item {
        display: flex;
        align-items: flex-start;
        gap: 0.5rem;
        padding: 0.5rem 0.75rem;
        border: 1px solid #e2e8f0;
        border-radius: 4px;
        font-size: 0.9rem;
        background: #fff;
    }
    .opt-item.is-correct-choice {
        background: #f0fdf4;
        border-color: #86efac;
        color: #166534;
        font-weight: 600;
    }
    .opt-item.is-wrong-choice {
        background: #fef2f2;
        border-color: #fca5a5;
        color: #991b1b;
    }
    .opt-item.is-key-target {
        background: #f0fdf4;
        border: 1px dashed #22c55e;
        color: #166534;
    }
    .badge-status {
        display: inline-block;
        padding: 0.2rem 0.5rem;
        border-radius: 4px;
        font-size: 0.75rem;
        font-weight: 600;
    }
    .badge-status.benar { background: #dcfce7; color: #166534; }
    .badge-status.salah { background: #fee2e2; color: #991b1b; }
    .badge-status.kosong { background: #f1f5f9; color: #64748b; }
    .badge-status.essai { background: #f3e8ff; color: #6b21a8; }
    .essay-box {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 4px;
        padding: 0.75rem 1rem;
        margin-top: 0.5rem;
    }
</style>
';

include __DIR__ . '/../layouts/header.php';
?>

<main class="container" style="max-width: 1100px;">
    <?php if ($flash): ?>
        <div class="alert alert-<?= sanitize($flash['type']) ?>">
            <?= sanitize($flash['message']) ?>
        </div>
    <?php endif; ?>

    <div class="page-header">
        <div>
            <h1 style="font-size: 1.25rem; font-weight: 700; margin: 0 0 0.25rem 0; color: #0f172a;">
                Lembar Jawaban Siswa
            </h1>
            <span style="font-size: 0.85rem; color: #64748b;">
                Pemeriksaan detail jawaban dan penilaian ujian
            </span>
        </div>
        <div>
            <a href="<?= base_url('guru?page=rekap_nilai&id_sesi=' . (int)$detailUjian['id_sesi']) ?>" class="btn btn-outline btn-sm">
                Kembali
            </a>
        </div>
    </div>

    <!-- Informasi Ujian & Siswa -->
    <div class="info-panel">
        <div>
            <div class="info-row">
                <span class="info-label">Nama Siswa</span>
                <span class="info-val"><?= sanitize($detailUjian['nama_siswa']) ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">NIS</span>
                <span class="info-val"><?= sanitize($detailUjian['nis'] ?: $detailUjian['username']) ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Kelas</span>
                <span class="info-val"><?= sanitize($detailUjian['nama_kelas']) ?></span>
            </div>
        </div>
        <div>
            <div class="info-row">
                <span class="info-label">Ujian</span>
                <span class="info-val"><?= sanitize($detailUjian['nama_ujian']) ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Mata Pelajaran</span>
                <span class="info-val"><?= sanitize($detailUjian['nama_mapel']) ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Waktu</span>
                <span class="info-val">
                    <?= $detailUjian['waktu_mulai'] ? date('d/m/Y H:i', strtotime($detailUjian['waktu_mulai'])) : '-' ?> 
                    <?= $detailUjian['waktu_selesai'] ? 's/d ' . date('H:i', strtotime($detailUjian['waktu_selesai'])) : '' ?>
                </span>
            </div>
        </div>
    </div>

    <!-- Ringkasan Nilai -->
    <div class="scores-panel">
        <div class="score-card">
            <div class="title">Nilai Akhir</div>
            <div class="number" style="color: #0f172a;"><?= number_format((float)$detailUjian['nilai_akhir'], 2) ?></div>
        </div>
        <div class="score-card">
            <div class="title">Nilai PG (<?= $statBenar ?>/<?= $totalPG ?>)</div>
            <div class="number" style="color: #0284c7;"><?= number_format($nilaiPGDisplay, 2) ?></div>
        </div>
        <?php if ($statEssai > 0): ?>
            <div class="score-card">
                <div class="title">Rata-rata Essai</div>
                <div class="number" style="color: #7c3aed;">
                    <?= $nilaiEssaiDisplay !== null ? number_format($nilaiEssaiDisplay, 2) : '<span style="font-size:0.9rem;color:#b45309;">Belum Diisi</span>' ?>
                </div>
            </div>
        <?php endif; ?>
        <div class="score-card">
            <div class="title">Salah PG</div>
            <div class="number" style="color: #dc2626;"><?= $statSalah ?></div>
        </div>
        <div class="score-card">
            <div class="title">Kosong</div>
            <div class="number" style="color: #64748b;"><?= $statKosong ?></div>
        </div>
    </div>

    <!-- Form Daftar Soal -->
    <form action="<?= base_url('guru?page=detail_jawaban') ?>" method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="simpan_nilai_essai">
        <input type="hidden" name="id_ujian_siswa" value="<?= $idUjianSiswa ?>">
        <input type="hidden" name="id_sesi" value="<?= (int)$detailUjian['id_sesi'] ?>">

        <?php if (empty($soalList)): ?>
            <div class="item-card" style="text-align: center; color: #64748b; padding: 2rem;">
                Tidak ada data butir soal.
            </div>
        <?php else: ?>
            <?php foreach ($soalList as $s): ?>
                <div class="item-card">
                    <div class="item-head">
                        <div>
                            <strong>Soal No. <?= $s['nomor'] ?></strong>
                            <?php 
                            $isKompleks = ($s['jenis_soal'] !== 'essai') && (count(array_filter(explode(',', $s['kunci_jawaban'] ?? ''))) > 1);
                            $labelTipe  = ($s['jenis_soal'] === 'essai') ? 'Essai / Uraian' : ($isKompleks ? 'Pilihan Ganda Kompleks' : 'Pilihan Ganda');
                            ?>
                            <span style="color: <?= $isKompleks ? '#4338ca' : '#64748b' ?>; font-size: 0.8rem; margin-left: 0.35rem; font-weight: <?= $isKompleks ? '700' : 'normal' ?>;">
                                (<?= $labelTipe ?>)
                            </span>
                        </div>
                        <div>
                            <?php if ($s['jenis_soal'] === 'essai'): ?>
                                <?php if ($s['nilai_soal'] !== null): ?>
                                    <span class="badge-status benar">Nilai: <?= (float)$s['nilai_soal'] ?></span>
                                <?php else: ?>
                                    <span class="badge-status" style="background:#fef3c7;color:#92400e;">Belum Dinilai</span>
                                <?php endif; ?>
                                <?php if (empty($s['jawaban_terpilih'])): ?>
                                    <span class="badge-status kosong" style="margin-left:0.25rem;">Tidak Diisi</span>
                                <?php endif; ?>
                            <?php elseif ($s['status_item'] === 'benar'): ?>
                                <span class="badge-status benar">Benar (+1)</span>
                            <?php elseif ($s['status_item'] === 'salah'): ?>
                                <span class="badge-status salah">Salah (0)</span>
                            <?php else: ?>
                                <span class="badge-status kosong">Kosong (0)</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="item-question">
                        <?= nl2br(sanitize($s['pertanyaan'])) ?>
                    </div>

                    <?php if (!empty($s['gambar'])): ?>
                        <div style="margin-bottom: 0.85rem;">
                            <img src="<?= sanitize($s['gambar']) ?>" alt="Soal <?= $s['nomor'] ?>" style="max-width: 100%; max-height: 250px; border: 1px solid #e2e8f0; border-radius: 4px;">
                        </div>
                    <?php endif; ?>

                    <?php if ($s['jenis_soal'] !== 'essai'): ?>
                        <div class="opt-list">
                            <?php 
                            $jwbArr   = array_filter(array_map('trim', explode(',', $s['jawaban_terpilih'] ?? '')));
                            $kunciArr = array_filter(array_map('trim', explode(',', $s['kunci_jawaban'] ?? '')));
                            ?>
                            <?php foreach ($s['opsi'] as $opt): ?>
                                <?php
                                    if (empty($opt['text']) && $opt['text'] !== '0') continue;

                                    $isChoice = in_array($opt['code'], $jwbArr, true);
                                    $isKey    = in_array($opt['code'], $kunciArr, true);

                                    $cls = '';
                                    $tag = '';

                                    if ($isChoice && $isKey) {
                                        $cls = 'is-correct-choice';
                                        $tag = '<span style="margin-left:auto;font-size:0.75rem;">[Jawaban Siswa & Kunci Benar]</span>';
                                    } elseif ($isChoice && !$isKey) {
                                        $cls = 'is-wrong-choice';
                                        $tag = '<span style="margin-left:auto;font-size:0.75rem;">[Jawaban Siswa]</span>';
                                    } elseif (!$isChoice && $isKey) {
                                        $cls = 'is-key-target';
                                        $tag = '<span style="margin-left:auto;font-size:0.75rem;">[Kunci Benar]</span>';
                                    }
                                ?>
                                <div class="opt-item <?= $cls ?>">
                                    <span style="font-weight:700;"><?= $opt['code'] ?>.</span>
                                    <span><?= sanitize($opt['text']) ?></span>
                                    <?= $tag ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="essay-box">
                            <div style="margin-bottom: 0.85rem;">
                                <div style="font-size:0.82rem;color:#475569;font-weight:700;margin-bottom:0.35rem;display:flex;align-items:center;gap:0.4rem;">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"></path><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path></svg>
                                    <span>Jawaban Siswa (Diisi Online):</span>
                                </div>
                                <div style="background:#fff;border:1.5px solid #cbd5e1;border-radius:6px;padding:0.75rem 1rem;font-size:0.95rem;color:#1e293b;line-height:1.6;white-space:pre-wrap;word-break:break-word;min-height:54px;">
                                    <?php if (!empty($s['jawaban_terpilih'])): ?>
                                        <?= nl2br(sanitize($s['jawaban_terpilih'])) ?>
                                    <?php else: ?>
                                        <span style="color:#94a3b8;font-style:italic;">(Siswa tidak mengisi jawaban untuk butir soal ini)</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php if (!empty($s['kunci_jawaban'])): ?>
                                <div style="margin-bottom: 0.85rem;">
                                    <div style="font-size:0.8rem;color:#64748b;font-weight:600;margin-bottom:0.25rem;">Pedoman Penilaian / Kunci Jawaban:</div>
                                    <div style="background:#f1f5f9;border:1px dashed #cbd5e1;border-radius:6px;padding:0.6rem 0.85rem;font-size:0.88rem;color:#334155;line-height:1.5;">
                                        <?= nl2br(sanitize($s['kunci_jawaban'])) ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div style="display:flex;align-items:center;gap:0.6rem;background:#ede9fe;border:1px solid #ddd6fe;border-radius:6px;padding:0.6rem 0.85rem;flex-wrap:wrap;">
                                <label for="nilai_<?= $s['id_soal'] ?>" style="font-size:0.85rem;font-weight:700;color:#5b21b6;margin:0;">
                                    Beri Nilai Manual:
                                </label>
                                <input type="number" name="nilai_soal[<?= $s['id_soal'] ?>]" id="nilai_<?= $s['id_soal'] ?>" class="form-control" min="0" max="100" step="0.5" placeholder="0-100" value="<?= $s['nilai_soal'] !== null ? (float)$s['nilai_soal'] : '' ?>" style="width:90px;text-align:center;padding:0.35rem 0.5rem;font-weight:700;background:#fff;">
                                <span style="font-size:0.85rem;color:#6d28d9;font-weight:600;">/ 100</span>
                                <span style="font-size:0.75rem;color:#7c3aed;margin-left:auto;">(Skala 0 - 100)</span>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if ($statEssai > 0): ?>
            <div style="background:#fff;border:1px solid #e2e8f0;border-radius:6px;padding:1rem;display:flex;justify-content:space-between;align-items:center;margin-top:1rem;">
                <span style="font-size:0.85rem;color:#64748b;">Klik simpan setelah mengisi nilai seluruh butir soal essai.</span>
                <button type="submit" class="btn btn-primary" style="font-weight:600;">
                    Simpan Nilai Essai
                </button>
            </div>
        <?php endif; ?>
    </form>

    <div style="margin: 1.5rem 0 3rem 0;">
        <a href="<?= base_url('guru?page=rekap_nilai&id_sesi=' . (int)$detailUjian['id_sesi']) ?>" class="btn btn-outline btn-sm">
            Kembali ke Rekap Nilai
        </a>
    </div>
</main>

<?php
include __DIR__ . '/../layouts/footer.php';
