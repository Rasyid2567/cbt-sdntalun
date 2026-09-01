<?php
/**
 * Modul Detail & Pemeriksaan Lembar Jawaban Siswa (Guru & Operator)
 * Menampilkan rincian butir soal, pilihan jawaban siswa vs kunci jawaban, skor, dan status pengerjaan.
 */

require_once __DIR__ . '/../middleware/auth.php';

$currentUser = auth_check(['guru', 'operator']);
$db = get_db();

$idUjianSiswa = (int)($_GET['id_ujian_siswa'] ?? 0);
$backSesiId   = (int)($_GET['id_sesi'] ?? 0);

if ($idUjianSiswa <= 0) {
    flash_set('danger', 'Parameter data ujian siswa tidak valid.');
    redirect(base_url('guru/rekap_nilai.php' . ($backSesiId > 0 ? '?id_sesi=' . $backSesiId : '')));
}

// 1. Ambil Data Ujian Siswa, Identitas Siswa, dan Sesi Ujian
$stmtUjian = $db->prepare("
    SELECT us.*, 
           u.id_user as id_siswa, u.nis, u.username, u.nama_lengkap as nama_siswa,
           s.id_sesi, s.nama_ujian, s.judul_soal, s.id_guru, s.durasi_menit, s.acak_soal, s.acak_opsi, s.status as status_sesi,
           m.id_mapel, m.nama_mapel, m.kode_mapel,
           k.id_kelas, k.nama_kelas,
           g.nama_lengkap as nama_guru
    FROM ujian_siswa us
    JOIN users u ON us.id_siswa = u.id_user
    JOIN sesi_ujian s ON us.id_sesi = s.id_sesi
    JOIN mapel m ON s.id_mapel = m.id_mapel
    JOIN kelas k ON s.id_kelas = k.id_kelas
    LEFT JOIN users g ON s.id_guru = g.id_user
    WHERE us.id_ujian_siswa = :us
");
$stmtUjian->execute([':us' => $idUjianSiswa]);
$detailUjian = $stmtUjian->fetch();

if (!$detailUjian) {
    flash_set('danger', 'Data ujian siswa tidak ditemukan.');
    redirect(base_url('guru/rekap_nilai.php' . ($backSesiId > 0 ? '?id_sesi=' . $backSesiId : '')));
}

// Validasi Hak Akses Guru (hanya boleh melihat sesi miliknya sendiri, kecuali role operator)
if ($currentUser['role'] === 'guru' && (int)$detailUjian['id_guru'] !== (int)$currentUser['id_user']) {
    flash_set('danger', 'Anda tidak memiliki hak akses untuk memeriksa ujian sesi ini.');
    redirect(base_url('guru/rekap_nilai.php'));
}

// 2. Ambil Urutan Soal Siswa
$urutanIds = json_decode($detailUjian['urutan_soal'], true);
if (empty($urutanIds) || !is_array($urutanIds)) {
    // Fallback ambil soal berdasarkan mapel & judul
    if (!empty($detailUjian['judul_soal'])) {
        $stmtFallback = $db->prepare("SELECT id_soal FROM bank_soal WHERE id_mapel = :m AND judul_soal = :j ORDER BY id_soal ASC");
        $stmtFallback->execute([':m' => $detailUjian['id_mapel'], ':j' => $detailUjian['judul_soal']]);
    } else {
        $stmtFallback = $db->prepare("SELECT id_soal FROM bank_soal WHERE id_mapel = :m ORDER BY id_soal ASC");
        $stmtFallback->execute([':m' => $detailUjian['id_mapel']]);
    }
    $urutanIds = $stmtFallback->fetchAll(PDO::FETCH_COLUMN);
}

// 3. Ambil Butir Soal dan Jawaban Siswa
$soalList = [];
$statBenar = 0;
$statSalah = 0;
$statKosong = 0;
$statEssai = 0;
$statRagu = 0;

if (!empty($urutanIds)) {
    $placeholders = implode(',', array_fill(0, count($urutanIds), '?'));
    
    $stmtSoal = $db->prepare("
        SELECT b.id_soal, b.jenis_soal, b.pertanyaan, b.gambar, 
               b.opsi_a, b.opsi_b, b.opsi_c, b.opsi_d, b.opsi_e, b.kunci_jawaban,
               j.jawaban_terpilih,
               COALESCE(j.status_ragu, false) as status_ragu,
               j.updated_at as waktu_jawab
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
        
        $jenisSoal       = $item['jenis_soal'] ?? 'pilihan_ganda';
        $jawabanSiswa    = strtoupper(trim($item['jawaban_terpilih'] ?? ''));
        $kunciJawaban    = strtoupper(trim($item['kunci_jawaban'] ?? ''));
        $isRagu          = (bool)$item['status_ragu'];
        
        $isCorrect = false;
        $isAnswered = ($jawabanSiswa !== '');

        if ($isRagu) {
            $statRagu++;
        }

        if ($jenisSoal === 'essai') {
            $statEssai++;
            $statusItem = 'essai';
        } else {
            // Pilihan Ganda
            if (!$isAnswered) {
                $statKosong++;
                $statusItem = 'kosong';
            } else {
                // Periksa kunci
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

        // Susun Opsi A-E
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
            'status_ragu'      => $isRagu,
            'status_item'      => $statusItem,
            'is_correct'       => $isCorrect,
            'waktu_jawab'      => $item['waktu_jawab']
        ];
    }
}

$totalPG = count($soalList) - $statEssai;
$calculatedNilai = ($totalPG > 0) ? round(($statBenar / $totalPG) * 100, 2) : 0.00;

// Durasi pengerjaan aktual
$durasiPakaiStr = '-';
if (!empty($detailUjian['waktu_mulai']) && !empty($detailUjian['waktu_selesai'])) {
    $tMulai = strtotime($detailUjian['waktu_mulai']);
    $tSelesai = strtotime($detailUjian['waktu_selesai']);
    $diffSec = max(0, $tSelesai - $tMulai);
    $menitPakai = floor($diffSec / 60);
    $detikPakai = $diffSec % 60;
    $durasiPakaiStr = "{$menitPakai} Menit {$detikPakai} Detik";
}

$flash = flash_get();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Detail Lembar Jawaban: <?= sanitize($detailUjian['nama_siswa']) ?> - CBT</title>
    <link rel="stylesheet" href="<?= base_url('assets/css/cbt-style.css') ?>">
    <style>
        /* Custom Styles for Answer Sheet Inspection */
        .detail-sheet-header {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.25rem;
            background: #ffffff;
            border-radius: var(--radius-md);
            padding: 1.25rem;
            border: 1px solid var(--gray-200);
            box-shadow: var(--shadow-sm);
        }
        @media (max-width: 768px) {
            .detail-sheet-header {
                grid-template-columns: 1fr;
            }
        }
        .stat-badge-card {
            background: #f8fafc;
            border: 1px solid var(--gray-200);
            border-radius: 8px;
            padding: 0.85rem 1rem;
            text-align: center;
        }
        .stat-badge-card .num {
            font-size: 1.45rem;
            font-weight: 800;
            line-height: 1.2;
        }
        .stat-badge-card .lbl {
            font-size: 0.76rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--gray-500);
            margin-top: 0.25rem;
        }
        .grid-inspect-container {
            display: flex;
            flex-wrap: wrap;
            gap: 0.45rem;
        }
        .grid-inspect-btn {
            width: 36px;
            height: 36px;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.85rem;
            font-weight: 700;
            text-decoration: none;
            border: 1px solid transparent;
            transition: all 0.15s ease;
            position: relative;
        }
        .grid-inspect-btn.btn-benar {
            background: #dcfce7;
            color: #166534;
            border-color: #86efac;
        }
        .grid-inspect-btn.btn-salah {
            background: #fee2e2;
            color: #991b1b;
            border-color: #fca5a5;
        }
        .grid-inspect-btn.btn-kosong {
            background: #f1f5f9;
            color: #64748b;
            border-color: #cbd5e1;
        }
        .grid-inspect-btn.btn-essai {
            background: #ede9fe;
            color: #6d28d9;
            border-color: #c4b5fd;
        }
        .grid-inspect-btn .ragu-dot {
            position: absolute;
            top: -3px;
            right: -3px;
            width: 9px;
            height: 9px;
            background: #f59e0b;
            border: 1.5px solid #ffffff;
            border-radius: 50%;
        }
        .soal-inspect-card {
            background: #ffffff;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-md);
            padding: 1.25rem 1.4rem;
            margin-bottom: 1.25rem;
            box-shadow: var(--shadow-sm);
        }
        .opsi-inspect-item {
            border: 1.5px solid var(--gray-200);
            border-radius: 8px;
            padding: 0.75rem 1rem;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            background: #ffffff;
            font-size: 0.92rem;
            transition: all 0.15s ease;
        }
        .opsi-inspect-item.chosen-correct {
            background: #f0fdf4;
            border-color: #22c55e;
            color: #15803d;
        }
        .opsi-inspect-item.chosen-wrong {
            background: #fef2f2;
            border-color: #ef4444;
            color: #b91c1c;
        }
        .opsi-inspect-item.correct-key-only {
            background: #f0fdf4;
            border: 1.5px dashed #16a34a;
            color: #15803d;
        }
        .opsi-inspect-code {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: #f1f5f9;
            color: var(--gray-700);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.85rem;
            flex-shrink: 0;
        }
        .opsi-inspect-item.chosen-correct .opsi-inspect-code {
            background: #22c55e;
            color: #ffffff;
        }
        .opsi-inspect-item.chosen-wrong .opsi-inspect-code {
            background: #ef4444;
            color: #ffffff;
        }
        .opsi-inspect-item.correct-key-only .opsi-inspect-code {
            background: #16a34a;
            color: #ffffff;
        }
        @media print {
            .no-print, .cbt-navbar, .card-header-actions, .grid-inspect-box {
                display: none !important;
            }
            body {
                background: #ffffff !important;
                font-size: 13px !important;
            }
            .container {
                max-width: 100% !important;
                padding: 0 !important;
                margin: 0 !important;
            }
            .card, .detail-sheet-header, .soal-inspect-card {
                box-shadow: none !important;
                border: 1px solid #cbd5e1 !important;
                page-break-inside: avoid;
            }
        }
    </style>
</head>
<body>

<header class="cbt-navbar no-print">
    <div class="cbt-navbar-header">
        <a href="<?= base_url('guru/dashboard.php') ?>" class="cbt-navbar-brand">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg>
            <span>CBT <?= strtoupper($currentUser['role']) ?></span>
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
            <?php if ($currentUser['role'] === 'guru'): ?>
                <li><a href="<?= base_url('guru/dashboard.php') ?>">Dashboard</a></li>
                <li><a href="<?= base_url('guru/bank_soal.php') ?>">Bank Soal</a></li>
                <li><a href="<?= base_url('guru/sesi_ujian.php') ?>">Sesi Ujian & Token</a></li>
                <li><a href="<?= base_url('guru/rekap_nilai.php') ?>" class="active">Rekap Nilai</a></li>
            <?php else: ?>
                <li><a href="<?= base_url('operator/dashboard.php') ?>">Dashboard</a></li>
                <li><a href="<?= base_url('operator/siswa_crud.php') ?>">Data Siswa</a></li>
                <li><a href="<?= base_url('operator/guru_crud.php') ?>">Data Guru</a></li>
            <?php endif; ?>
            <li><a href="<?= base_url('logout.php') ?>" class="btn-danger">Keluar</a></li>
        </ul>
    </nav>
</header>

<main class="container">
    <?php if ($flash): ?>
        <div class="alert alert-<?= sanitize($flash['type']) ?> no-print">
            <?= sanitize($flash['message']) ?>
        </div>
    <?php endif; ?>

    <!-- Navigation & Action Bar -->
    <div class="card-header no-print mb-3">
        <div style="display: flex; align-items: center; gap: 0.75rem;">
            <a href="<?= base_url('guru/rekap_nilai.php?id_sesi=' . (int)$detailUjian['id_sesi']) ?>" class="btn btn-outline btn-sm">
                ◄ Kembali ke Rekap Nilai
            </a>
            <span class="text-muted text-sm">/ Detail Lembar Jawaban Siswa</span>
        </div>
        <div class="card-header-actions">
            <button type="button" class="btn btn-primary" onclick="window.print()">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" style="vertical-align: middle; margin-right: 4px;"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
                Cetak Lembar Jawaban
            </button>
        </div>
    </div>

    <!-- Identitas Siswa & Informasi Sesi Ujian -->
    <div class="detail-sheet-header mb-4">
        <div>
            <div style="font-size: 0.78rem; text-transform: uppercase; font-weight: 700; color: var(--gray-500); margin-bottom: 0.25rem;">IDENTITAS PESERTA UJIAN</div>
            <h2 style="font-size: 1.35rem; font-weight: 800; color: var(--gray-900); margin: 0 0 0.4rem 0;">
                <?= sanitize($detailUjian['nama_siswa']) ?>
            </h2>
            <div style="display: grid; grid-template-columns: auto 1fr; gap: 0.25rem 0.75rem; font-size: 0.88rem; color: var(--gray-700);">
                <strong>NIS:</strong>
                <div><span class="badge" style="background:#e0f2fe; color:#0369a1; font-family:monospace;"><?= sanitize($detailUjian['nis'] ?? '-') ?></span> (Username: <?= sanitize($detailUjian['username']) ?>)</div>
                <strong>Kelas:</strong>
                <div><?= sanitize($detailUjian['nama_kelas']) ?></div>
                <strong>Status Ujian:</strong>
                <div>
                    <?php if ($detailUjian['status'] === 'selesai'): ?>
                        <span class="badge badge-online">SELESAI DIKUMPULKAN</span>
                    <?php elseif ($detailUjian['status'] === 'sedang'): ?>
                        <span class="badge badge-aktif">SEDANG MENGERJAKAN</span>
                    <?php else: ?>
                        <span class="badge badge-offline">BELUM MENGERJAKAN</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div style="border-left: 1px solid var(--gray-200); padding-left: 1.25rem;">
            <div style="font-size: 0.78rem; text-transform: uppercase; font-weight: 700; color: var(--gray-500); margin-bottom: 0.25rem;">INFORMASI SESI & WAKTU</div>
            <h3 style="font-size: 1.15rem; font-weight: 700; color: var(--primary); margin: 0 0 0.4rem 0;">
                <?= sanitize($detailUjian['nama_ujian']) ?>
            </h3>
            <div style="display: grid; grid-template-columns: auto 1fr; gap: 0.25rem 0.75rem; font-size: 0.88rem; color: var(--gray-700);">
                <strong>Mata Pelajaran:</strong>
                <div><?= sanitize($detailUjian['nama_mapel']) ?> <?= $detailUjian['kode_mapel'] ? '('.sanitize($detailUjian['kode_mapel']).')' : '' ?></div>
                <strong>Waktu Mulai:</strong>
                <div><?= $detailUjian['waktu_mulai'] ? date('d M Y, H:i:s', strtotime($detailUjian['waktu_mulai'])) . ' WIB' : '-' ?></div>
                <strong>Waktu Selesai:</strong>
                <div><?= $detailUjian['waktu_selesai'] ? date('d M Y, H:i:s', strtotime($detailUjian['waktu_selesai'])) . ' WIB' : '-' ?></div>
                <strong>Durasi Terpakai:</strong>
                <div><strong><?= $durasiPakaiStr ?></strong> (Alokasi: <?= (int)$detailUjian['durasi_menit'] ?> Menit)</div>
            </div>
        </div>
    </div>

    <!-- Ringkasan Statistik Skor & Jawaban -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 0.75rem; margin-bottom: 1.5rem;">
        <!-- Nilai Akhir -->
        <div class="stat-badge-card" style="background: #f0fdf4; border-color: #bbf7d0;">
            <div class="num" style="color: <?= ($detailUjian['nilai_akhir'] >= 75) ? '#15803d' : '#b91c1c' ?>;">
                <?= number_format((float)$detailUjian['nilai_akhir'], 2) ?>
            </div>
            <div class="lbl" style="color: #166534;">Nilai Akhir (0-100)</div>
        </div>

        <!-- Total Butir Soal -->
        <div class="stat-badge-card">
            <div class="num" style="color: var(--gray-900);"><?= count($soalList) ?></div>
            <div class="lbl">Total Butir Soal</div>
        </div>

        <!-- Jawaban Benar -->
        <div class="stat-badge-card" style="background: #f0fdf4; border-color: #86efac;">
            <div class="num" style="color: #16a34a;"><?= $statBenar ?></div>
            <div class="lbl" style="color: #15803d;">Benar (PG)</div>
        </div>

        <!-- Jawaban Salah -->
        <div class="stat-badge-card" style="background: #fef2f2; border-color: #fca5a5;">
            <div class="num" style="color: #dc2626;"><?= $statSalah ?></div>
            <div class="lbl" style="color: #991b1b;">Salah (PG)</div>
        </div>

        <!-- Tidak Dijawab -->
        <div class="stat-badge-card">
            <div class="num" style="color: var(--gray-500);"><?= $statKosong ?></div>
            <div class="lbl">Kosong / Belum</div>
        </div>

        <!-- Soal Essai -->
        <?php if ($statEssai > 0): ?>
            <div class="stat-badge-card" style="background: #faf5ff; border-color: #d8b4fe;">
                <div class="num" style="color: #7e22ce;"><?= $statEssai ?></div>
                <div class="lbl" style="color: #6b21a8;">Essai di Kertas</div>
            </div>
        <?php endif; ?>

        <!-- Ragu-ragu -->
        <?php if ($statRagu > 0): ?>
            <div class="stat-badge-card" style="background: #fffbeb; border-color: #fde68a;">
                <div class="num" style="color: #d97706;"><?= $statRagu ?></div>
                <div class="lbl" style="color: #b45309;">Ditandai Ragu</div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Quick Navigation Grid -->
    <div class="card grid-inspect-box mb-4 no-print" style="padding: 1rem 1.25rem;">
        <div class="flex-between mb-2" style="flex-wrap: wrap; gap: 0.5rem;">
            <span class="font-bold text-sm" style="color: var(--gray-800);">Peta Lembar Jawaban Butir Soal:</span>
            <div class="flex gap-3 text-xs" style="align-items: center; flex-wrap: wrap;">
                <span class="flex gap-1" style="align-items: center;"><span style="width:12px;height:12px;background:#dcfce7;border:1px solid #86efac;border-radius:3px;"></span> Benar</span>
                <span class="flex gap-1" style="align-items: center;"><span style="width:12px;height:12px;background:#fee2e2;border:1px solid #fca5a5;border-radius:3px;"></span> Salah</span>
                <span class="flex gap-1" style="align-items: center;"><span style="width:12px;height:12px;background:#f1f5f9;border:1px solid #cbd5e1;border-radius:3px;"></span> Kosong</span>
                <?php if ($statEssai > 0): ?>
                    <span class="flex gap-1" style="align-items: center;"><span style="width:12px;height:12px;background:#ede9fe;border:1px solid #c4b5fd;border-radius:3px;"></span> Essai</span>
                <?php endif; ?>
                <?php if ($statRagu > 0): ?>
                    <span class="flex gap-1" style="align-items: center;"><span style="width:8px;height:8px;background:#f59e0b;border-radius:50%;"></span> Titik Kuning: Ragu</span>
                <?php endif; ?>
            </div>
        </div>

        <div class="grid-inspect-container">
            <?php foreach ($soalList as $s): ?>
                <?php
                    $btnClass = 'btn-kosong';
                    if ($s['status_item'] === 'benar') $btnClass = 'btn-benar';
                    elseif ($s['status_item'] === 'salah') $btnClass = 'btn-salah';
                    elseif ($s['status_item'] === 'essai') $btnClass = 'btn-essai';
                ?>
                <a href="#soal-item-<?= $s['nomor'] ?>" class="grid-inspect-btn <?= $btnClass ?>" title="Soal No. <?= $s['nomor'] ?>: <?= strtoupper($s['status_item']) ?>">
                    <?= $s['nomor'] ?>
                    <?php if ($s['status_ragu']): ?>
                        <span class="ragu-dot" title="Ditandai Ragu-ragu"></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Rincian Lembar Soal & Jawaban Siswa -->
    <div>
        <h2 style="font-size: 1.15rem; font-weight: 800; color: var(--gray-900); margin-bottom: 1rem;">
            Rincian Jawaban Siswa per Butir Pertanyaan
        </h2>

        <?php if (empty($soalList)): ?>
            <div class="card text-center" style="padding: 3rem 0;">
                <p class="text-muted">Tidak ada data butir soal yang dapat ditampilkan.</p>
            </div>
        <?php else: ?>
            <?php foreach ($soalList as $s): ?>
                <div id="soal-item-<?= $s['nomor'] ?>" class="soal-inspect-card">
                    <!-- Top Bar Nomor & Status Koreksi -->
                    <div class="flex-between mb-3" style="border-bottom: 1px solid var(--gray-200); padding-bottom: 0.65rem; flex-wrap: wrap; gap: 0.5rem;">
                        <div class="flex gap-2" style="align-items: center;">
                            <span class="badge" style="background: var(--primary); color: #ffffff; font-size: 0.85rem; padding: 0.35rem 0.65rem; font-weight: 700;">
                                SOAL NO. <?= $s['nomor'] ?>
                            </span>

                            <?php if ($s['jenis_soal'] === 'essai'): ?>
                                <span class="badge" style="background: #ede9fe; color: #6d28d9; font-weight: 700;">SOAL ESSAI / URAIAN</span>
                            <?php else: ?>
                                <span class="badge" style="background: #f1f5f9; color: #475569; font-weight: 600;">PILIHAN GANDA</span>
                            <?php endif; ?>

                            <?php if ($s['status_ragu']): ?>
                                <span class="badge" style="background: #fef3c7; color: #b45309; font-weight: 700;">⚠️ RAGU-RAGU</span>
                            <?php endif; ?>
                        </div>

                        <div>
                            <?php if ($s['jenis_soal'] === 'essai'): ?>
                                <span class="badge" style="background: #ede9fe; color: #6d28d9; font-weight: 700; padding: 0.35rem 0.65rem;">
                                    📝 Dinilai Manual di Kertas
                                </span>
                            <?php elseif ($s['status_item'] === 'benar'): ?>
                                <span class="badge" style="background: #dcfce7; color: #166534; font-weight: 800; padding: 0.35rem 0.65rem; font-size: 0.85rem;">
                                    ✓ JAWABAN BENAR (+1)
                                </span>
                            <?php elseif ($s['status_item'] === 'salah'): ?>
                                <span class="badge" style="background: #fee2e2; color: #991b1b; font-weight: 800; padding: 0.35rem 0.65rem; font-size: 0.85rem;">
                                    ✕ JAWABAN SALAH (0)
                                </span>
                            <?php else: ?>
                                <span class="badge" style="background: #f1f5f9; color: #64748b; font-weight: 700; padding: 0.35rem 0.65rem;">
                                    – TIDAK DIJAWAB (0)
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Pertanyaan -->
                    <div style="font-size: 1rem; color: var(--gray-900); line-height: 1.6; margin-bottom: 1rem;">
                        <?= nl2br(sanitize($s['pertanyaan'])) ?>
                    </div>

                    <!-- Gambar Soal (jika ada) -->
                    <?php if (!empty($s['gambar'])): ?>
                        <div style="margin-bottom: 1.25rem;">
                            <img src="<?= sanitize($s['gambar']) ?>" alt="Gambar Soal <?= $s['nomor'] ?>" style="max-width: 100%; max-height: 320px; border-radius: 6px; border: 1px solid var(--gray-200); object-fit: contain;">
                        </div>
                    <?php endif; ?>

                    <!-- Opsi Jawaban Pilihan Ganda -->
                    <?php if ($s['jenis_soal'] !== 'essai'): ?>
                        <div style="margin-bottom: 0.75rem;">
                            <?php foreach ($s['opsi'] as $opt): ?>
                                <?php
                                    if (empty($opt['text']) && $opt['text'] !== '0') continue;

                                    $isStudentChoice = ($s['jawaban_terpilih'] === $opt['code']);
                                    $isKey           = ($s['kunci_jawaban'] === $opt['code']);

                                    $optClass = '';
                                    $badgeLabel = '';

                                    if ($isStudentChoice && $isKey) {
                                        $optClass = 'chosen-correct';
                                        $badgeLabel = '<span class="badge" style="background:#22c55e; color:#ffffff; font-weight:700; font-size:0.75rem;">✓ Jawaban Siswa & Kunci Benar</span>';
                                    } elseif ($isStudentChoice && !$isKey) {
                                        $optClass = 'chosen-wrong';
                                        $badgeLabel = '<span class="badge" style="background:#ef4444; color:#ffffff; font-weight:700; font-size:0.75rem;">✕ Jawaban Siswa (Salah)</span>';
                                    } elseif (!$isStudentChoice && $isKey) {
                                        $optClass = 'correct-key-only';
                                        $badgeLabel = '<span class="badge" style="background:#16a34a; color:#ffffff; font-weight:700; font-size:0.75rem;">★ Kunci Jawaban Benar</span>';
                                    }
                                ?>
                                <div class="opsi-inspect-item <?= $optClass ?>">
                                    <div class="opsi-inspect-code"><?= $opt['code'] ?></div>
                                    <div style="flex: 1; line-height: 1.45;">
                                        <?= sanitize($opt['text']) ?>
                                    </div>
                                    <?php if ($badgeLabel): ?>
                                        <div style="margin-left: auto; flex-shrink: 0;">
                                            <?= $badgeLabel ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Ringkasan Singkat Bawah Soal -->
                        <div style="background: #f8fafc; border-radius: 6px; padding: 0.6rem 0.85rem; font-size: 0.84rem; display: flex; gap: 1.5rem; flex-wrap: wrap; color: var(--gray-700); border: 1px solid var(--gray-200);">
                            <div>
                                Jawaban Siswa: 
                                <?php if ($s['jawaban_terpilih']): ?>
                                    <strong style="font-size: 0.95rem; color: <?= $s['is_correct'] ? '#16a34a' : '#dc2626' ?>;">
                                        Opsi <?= $s['jawaban_terpilih'] ?>
                                    </strong>
                                <?php else: ?>
                                    <span class="text-muted font-bold">(Kosong / Tidak Dijawab)</span>
                                <?php endif; ?>
                            </div>
                            <div>
                                Kunci Jawaban Resmi: 
                                <strong style="font-size: 0.95rem; color: #16a34a;">
                                    Opsi <?= $s['kunci_jawaban'] ?: '-' ?>
                                </strong>
                            </div>
                            <?php if (!empty($s['waktu_jawab'])): ?>
                                <div class="text-muted" style="margin-left: auto; font-size: 0.78rem;">
                                    Tersimpan: <?= date('d/m/y H:i:s', strtotime($s['waktu_jawab'])) ?>
                                </div>
                            <?php endif; ?>
                        </div>

                    <?php else: ?>
                        <!-- Bagian Khusus Soal Essai -->
                        <div style="background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 8px; padding: 1rem 1.25rem;">
                            <div style="font-size: 0.88rem; font-weight: 700; color: #475569; margin-bottom: 0.35rem;">
                                📋 Pedoman / Kunci Jawaban Essai:
                            </div>
                            <div style="font-size: 0.92rem; color: var(--gray-900); background: #ffffff; padding: 0.75rem 1rem; border-radius: 6px; border: 1px solid var(--gray-200);">
                                <?= !empty($s['kunci_jawaban']) ? nl2br(sanitize($s['kunci_jawaban'])) : '<em class="text-muted">Tidak ada catatan pedoman kunci jawaban dari guru.</em>' ?>
                            </div>
                            <p class="text-xs text-muted" style="margin: 0.5rem 0 0 0;">
                                Siswa mengerjakan butir soal ini langsung pada lembar kertas ujian. Nilai uraian dapat direkapitulasi secara manual oleh Guru.
                            </p>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Tombol Navigasi Bawah -->
    <div class="flex-between mt-4 mb-4 no-print">
        <a href="<?= base_url('guru/rekap_nilai.php?id_sesi=' . (int)$detailUjian['id_sesi']) ?>" class="btn btn-outline">
            ◄ Kembali ke Rekap Nilai
        </a>
        <button type="button" class="btn btn-primary" onclick="window.scrollTo({top: 0, behavior: 'smooth'})">
            ▲ Kembali ke Atas
        </button>
    </div>
</main>

<script src="<?= base_url('assets/js/app.js') ?>"></script>
</body>
</html>
