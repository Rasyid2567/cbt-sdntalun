<?php
/**
 * Modul Rekapitulasi Nilai Ujian Siswa (Guru Penguji)
 * Mendukung Ekspor CSV dengan Rincian Skor Tiap Soal
 */

require_once __DIR__ . '/../../middleware/auth.php';
require_once __DIR__ . '/../../config/scoring.php';

$currentUser = auth_check(['guru', 'operator']);
$db = get_db();
$idGuru = $currentUser['id_user'];
$page = 'rekap_nilai';
$pageTitle = 'Rekapitulasi Nilai Ujian';

// Tangani POST Tambah Waktu Sesi Ujian
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash_set('danger', 'Validasi token keamanan (CSRF) gagal.');
        redirect(base_url('guru?page=rekap_nilai' . (!empty($_POST['id_sesi']) ? '&id_sesi=' . (int)$_POST['id_sesi'] : '')));
    }
    $action = $_POST['action'] ?? '';
    if ($action === 'tambah_waktu') {
        $idSesi      = (int)($_POST['id_sesi'] ?? 0);
        $tambahMenit = (int)($_POST['tambah_menit'] ?? 0);

        $res = tambah_durasi_sesi($idSesi, $tambahMenit, ($currentUser['role'] === 'guru' ? $idGuru : null));
        if ($res['success']) {
            flash_set('success', "Waktu ujian untuk sesi '" . sanitize($res['nama_ujian']) . "' berhasil ditambah sebanyak {$res['tambah_menit']} menit. Durasi total kini: {$res['durasi_baru']} menit.");
        } else {
            flash_set('danger', $res['message']);
        }
        redirect(base_url('guru?page=rekap_nilai&id_sesi=' . $idSesi));
    }
}

// Ambil Daftar Seluruh Sesi Ujian (Guru terfilter, Operator melihat semua)
$sqlSesiAll = "
    SELECT s.id_sesi, s.nama_ujian, s.token_ujian, s.status, s.id_mapel, m.nama_mapel, k.nama_kelas, p.nama_paket
    FROM sesi_ujian s
    LEFT JOIN paket_soal p ON s.id_paket = p.id_paket
    JOIN mapel m ON s.id_mapel = m.id_mapel
    JOIN kelas k ON s.id_kelas = k.id_kelas
";
$paramsSesi = [];
if ($currentUser['role'] === 'guru') {
    $sqlSesiAll .= " WHERE s.id_guru = :g";
    $paramsSesi[':g'] = $idGuru;
}
$sqlSesiAll .= " ORDER BY s.created_at DESC";
$stmtSesiAll = $db->prepare($sqlSesiAll);
$stmtSesiAll->execute($paramsSesi);
$allSessions = $stmtSesiAll->fetchAll();

// Tentukan Sesi Terpilih
$selectedSesiId = !empty($_GET['id_sesi']) ? (int)$_GET['id_sesi'] : ($allSessions[0]['id_sesi'] ?? 0);

$sesiDetail = null;
$rekapList = [];
$daftarSoal = [];
$soalMeta = [];
$jawabanMap = [];
$totalSoalUjian = 0;
$totalPG = 0;
$totalEssai = 0;
$totalBobotMaksimal = 0.00;

if ($selectedSesiId > 0) {
    // Detail Sesi
    $sqlDet = "
        SELECT s.*, m.nama_mapel, k.nama_kelas, k.id_kelas, p.nama_paket,
               u.nama_lengkap as nama_guru, u.nip as nip_guru,
               GREATEST(0, FLOOR(EXTRACT(EPOCH FROM (s.created_at + (s.durasi_menit * INTERVAL '1 minute') - CURRENT_TIMESTAMP))))::int as sisa_detik_sesi
        FROM sesi_ujian s
        LEFT JOIN paket_soal p ON s.id_paket = p.id_paket
        LEFT JOIN users u ON s.id_guru = u.id_user
        JOIN mapel m ON s.id_mapel = m.id_mapel
        JOIN kelas k ON s.id_kelas = k.id_kelas
        WHERE s.id_sesi = :id
    ";
    $paramsDet = [':id' => $selectedSesiId];
    if ($currentUser['role'] === 'guru') {
        $sqlDet .= " AND s.id_guru = :g";
        $paramsDet[':g'] = $idGuru;
    }
    $stmtDet = $db->prepare($sqlDet);
    $stmtDet->execute($paramsDet);
    $sesiDetail = $stmtDet->fetch();

    if ($sesiDetail) {
        // Ambil Daftar Butir Soal Sesi Ujian (Master Urutan Soal 1..N)
        if (!empty($sesiDetail['id_paket'])) {
            $stmtSoalList = $db->prepare("
                SELECT id_soal, jenis_soal, bobot_soal, kunci_jawaban, pertanyaan
                FROM bank_soal 
                WHERE id_paket = :p
                ORDER BY id_soal ASC
            ");
            $stmtSoalList->execute([':p' => $sesiDetail['id_paket']]);
        } else {
            $stmtSoalList = $db->prepare("
                SELECT id_soal, jenis_soal, bobot_soal, kunci_jawaban, pertanyaan
                FROM bank_soal 
                WHERE id_paket IN (SELECT id_paket FROM paket_soal WHERE id_mapel = :m)
                ORDER BY id_soal ASC
            ");
            $stmtSoalList->execute([':m' => $sesiDetail['id_mapel']]);
        }
        $daftarSoal = $stmtSoalList->fetchAll();

        foreach ($daftarSoal as $idx => $s) {
            $normJenis = cbt_normalize_jenis_soal($s['jenis_soal'] ?? 'pg_1', $s['kunci_jawaban'] ?? null);
            $meta = cbt_get_soal_meta($normJenis, $s['kunci_jawaban'] ?? null);
            $bobot = ($s['bobot_soal'] !== null && $s['bobot_soal'] !== '' && is_numeric($s['bobot_soal']) && (float)$s['bobot_soal'] > 0)
                ? (float)$s['bobot_soal']
                : cbt_get_default_bobot($normJenis, $s['kunci_jawaban'] ?? null);
            $totalBobotMaksimal += $bobot;

            if ($normJenis === 'uraian') {
                $totalEssai++;
            } else {
                $totalPG++;
            }

            $soalMeta[$s['id_soal']] = [
                'index'      => $idx + 1,
                'id_soal'    => $s['id_soal'],
                'norm_jenis' => $normJenis,
                'meta'       => $meta,
                'bobot'      => $bobot,
                'pertanyaan' => $s['pertanyaan'],
            ];
        }
        $totalSoalUjian = count($daftarSoal);

        // Auto-finalize ujian siswa jika sesi ujian SUDAH ditutup/nonaktif oleh guru
        $stmtAutoClose = $db->prepare("
            UPDATE ujian_siswa us
            SET status = 'selesai',
                waktu_selesai = COALESCE(
                    us.waktu_selesai,
                    (SELECT MAX(js.updated_at) FROM jawaban_siswa js WHERE js.id_ujian_siswa = us.id_ujian_siswa AND js.jawaban_terpilih IS NOT NULL AND js.jawaban_terpilih != ''),
                    us.waktu_mulai + (s.durasi_menit * INTERVAL '1 minute'),
                    CURRENT_TIMESTAMP
                ),
                sisa_detik = 0
            FROM sesi_ujian s
            WHERE us.id_sesi = s.id_sesi
              AND us.id_sesi = :sesi
              AND us.status = 'sedang'
              AND s.status != 'aktif'
        ");
        $stmtAutoClose->execute([':sesi' => $selectedSesiId]);

        // Auto-healing waktu_selesai bagi yang statusnya sudah selesai tapi waktu_selesai null
        $stmtFixWaktu = $db->prepare("
            UPDATE ujian_siswa us
            SET waktu_selesai = COALESCE(
                (SELECT MAX(js.updated_at) FROM jawaban_siswa js WHERE js.id_ujian_siswa = us.id_ujian_siswa AND js.jawaban_terpilih IS NOT NULL AND js.jawaban_terpilih != ''),
                us.waktu_mulai + (s.durasi_menit * INTERVAL '1 minute'),
                CURRENT_TIMESTAMP
            )
            FROM sesi_ujian s
            WHERE us.id_sesi = s.id_sesi
              AND us.id_sesi = :sesi
              AND us.status = 'selesai'
              AND us.waktu_selesai IS NULL
        ");
        $stmtFixWaktu->execute([':sesi' => $selectedSesiId]);

        // Ambil Siswa yang terdaftar di kelas ini dan status pengerjaannya
        $stmtRekap = $db->prepare("
            SELECT u.id_user, u.nis, u.username, u.nama_lengkap, k.nama_kelas,
                   us.id_ujian_siswa, us.waktu_mulai, us.waktu_selesai, us.status as status_ujian,
                   COALESCE(us.jumlah_benar, 0) as jumlah_benar,
                   COALESCE(us.total_skor, us.nilai_pg, 0.00) as total_skor,
                   us.skor_maksimal,
                   COALESCE(us.nilai_pg, us.nilai_akhir, 0.00) as nilai_pg,
                   us.nilai_essai,
                   COALESCE(us.nilai_akhir, 0.00) as nilai_akhir
            FROM users u
            JOIN kelas k ON u.id_kelas = k.id_kelas
            LEFT JOIN ujian_siswa us ON (us.id_siswa = u.id_user AND us.id_sesi = :sesi)
            WHERE u.role = 'siswa' AND u.id_kelas = :kelas
            ORDER BY u.nama_lengkap ASC
        ");
        $stmtRekap->execute([':sesi' => $selectedSesiId, ':kelas' => $sesiDetail['id_kelas']]);
        $rekapList = $stmtRekap->fetchAll();

        // Ambil data jawaban dan skor butir per siswa
        $ujianSiswaIds = array_filter(array_column($rekapList, 'id_ujian_siswa'));
        if (!empty($ujianSiswaIds)) {
            $placeholders = implode(',', array_fill(0, count($ujianSiswaIds), '?'));
            $stmtJwb = $db->prepare("
                SELECT id_ujian_siswa, id_soal, jawaban_terpilih, nilai_soal
                FROM jawaban_siswa
                WHERE id_ujian_siswa IN ($placeholders)
            ");
            $stmtJwb->execute(array_values($ujianSiswaIds));
            while ($jRow = $stmtJwb->fetch()) {
                $jawabanMap[$jRow['id_ujian_siswa']][$jRow['id_soal']] = $jRow;
            }
        }
    }
}

// Tangani Export CSV (Lengkap dengan Skor Tiap Butir Soal)
if (isset($_GET['action']) && $_GET['action'] === 'export_csv') {
    if (!$sesiDetail) {
        flash_set('danger', 'Sesi ujian tidak ditemukan untuk diekspor.');
        redirect(base_url('guru?page=rekap_nilai'));
    }

    if (ob_get_level() > 0) {
        ob_end_clean();
    }

    $filename = 'rekap_nilai_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $sesiDetail['nama_ujian']) . '.csv';
    header('Content-Description: File Transfer');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Expires: 0');
    header('Pragma: public');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM
    fwrite($output, "sep=,\n");

    // Header Kolom CSV (Termasuk Kolom Skor Tiap Soal)
    $csvHeader = ['No', 'NIS', 'Username', 'Nama Lengkap Siswa', 'Kelas', 'Waktu Pengerjaan', 'Status Ujian'];
    foreach ($daftarSoal as $idx => $s) {
        $sm = $soalMeta[$s['id_soal']];
        $bobotDisplay = (float)$sm['bobot'] == (int)$sm['bobot'] ? (int)$sm['bobot'] : number_format($sm['bobot'], 1);
        $csvHeader[] = 'Soal ' . ($idx + 1) . ' (' . $sm['meta']['short_label'] . ' - Max ' . $bobotDisplay . ')';
    }
    $csvHeader[] = 'Jumlah Benar';
    $csvHeader[] = 'Total Butir Soal';
    $csvHeader[] = 'Skor Diperoleh';
    $csvHeader[] = 'Skor Maksimal';
    $csvHeader[] = 'Nilai Akhir';

    fputcsv($output, $csvHeader);

    foreach ($rekapList as $idx => $r) {
        $waktuPengerjaan = '-';
        if (!empty($r['waktu_mulai'])) {
            if (!empty($r['waktu_selesai'])) {
                $diffSec = max(0, strtotime($r['waktu_selesai']) - strtotime($r['waktu_mulai']));
                $menitKerja = floor($diffSec / 60);
                $detikKerja = $diffSec % 60;
                $waktuPengerjaan = "{$menitKerja}m {$detikKerja}s";
            } elseif (($r['status_ujian'] ?? '') === 'sedang') {
                $diffSec = max(0, time() - strtotime($r['waktu_mulai']));
                $menitKerja = floor($diffSec / 60);
                $detikKerja = $diffSec % 60;
                $waktuPengerjaan = "{$menitKerja}m {$detikKerja}s (Aktif)";
            }
        }

        $hasStarted = (!empty($r['id_ujian_siswa']) && ($r['status_ujian'] ?? '') !== 'belum');

        $rowItem = [
            $idx + 1,
            $r['nis'] ?? '-',
            $r['username'],
            $r['nama_lengkap'],
            $r['nama_kelas'],
            $waktuPengerjaan,
            strtoupper($r['status_ujian'] ?? 'BELUM')
        ];

        // Masukkan Skor Tiap Butir Soal Siswa
        foreach ($daftarSoal as $s) {
            $sId = $s['id_soal'];
            $isUraian = ($soalMeta[$sId]['norm_jenis'] === 'uraian');

            if (!$hasStarted) {
                $rowItem[] = '-';
            } else {
                $jwb = $jawabanMap[$r['id_ujian_siswa']][$sId] ?? null;
                if ($jwb === null && ($r['status_ujian'] ?? '') === 'sedang') {
                    $rowItem[] = '-';
                } else {
                    $skorVal = $jwb['nilai_soal'] ?? null;
                    if ($skorVal === null && $isUraian && !empty($jwb['jawaban_terpilih'])) {
                        $rowItem[] = 'Belum Dinilai';
                    } else {
                        if ($skorVal === null && $jwb !== null && !$isUraian) {
                            $eval = cbt_evaluasi_soal($s, $jwb['jawaban_terpilih'] ?? null, null);
                            $numSkor = (float)$eval['skor'];
                        } else {
                            $numSkor = (float)($skorVal ?? 0.0);
                        }
                        $rowItem[] = ($numSkor == (int)$numSkor) ? (int)$numSkor : number_format($numSkor, 1);
                    }
                }
            }
        }

        $rowItem[] = $hasStarted ? $r['jumlah_benar'] : '-';
        $rowItem[] = count($daftarSoal);
        $rowItem[] = $hasStarted ? (float)$r['total_skor'] : '-';
        $rowItem[] = (float)$totalBobotMaksimal;
        $rowItem[] = $hasStarted ? (float)$r['nilai_akhir'] : '-';

        fputcsv($output, $rowItem);
    }

    fclose($output);
    exit;
}

$flash = flash_get();

include __DIR__ . '/../layouts/header.php';
?>

<style>
.print-only { display: none !important; }
@media print {
    .print-only { display: block !important; }
    .no-print { display: none !important; }
    body { background: #fff !important; color: #000 !important; font-size: 9.5pt; }
    .card { border: none !important; box-shadow: none !important; padding: 0 !important; margin: 0 !important; }
    .table { width: 100% !important; border-collapse: collapse !important; }
    .table th, .table td { border: 1px solid #333 !important; padding: 5px 8px !important; color: #000 !important; }
    .badge { border: none !important; padding: 0 !important; background: transparent !important; color: #000 !important; font-weight: bold; }
    @page { margin: 15mm 12mm; }
}
</style>

<main class="container" style="max-width: 1380px;">
    <?php if ($flash): ?>
        <div class="alert alert-<?= sanitize($flash['type']) ?> no-print">
            <?= sanitize($flash['message']) ?>
        </div>
    <?php endif; ?>

    <div class="card-header no-print">
        <div>
            <h1 class="card-title">Laporan & Rekapitulasi Nilai Ujian</h1>
        </div>
        <?php if ($sesiDetail): ?>
            <div class="card-header-actions">
                <a href="<?= base_url('guru?page=rekap_nilai&action=export_csv&id_sesi=' . $sesiDetail['id_sesi']) ?>" class="btn btn-secondary">Ekspor CSV</a>
            </div>
        <?php endif; ?>
    </div>

    <!-- Pilih Sesi Ujian -->
    <div class="card no-print" style="padding: 1rem 1.25rem;">
        <form method="GET" action="<?= base_url('guru') ?>" class="filter-form-responsive">
            <input type="hidden" name="page" value="rekap_nilai">
            <label for="select_sesi" class="font-bold">Pilih Sesi Ujian:</label>
            <div class="filter-row">
                <select name="id_sesi" id="select_sesi" class="form-control" onchange="this.form.submit()">
                    <?php if (empty($allSessions)): ?>
                        <option value="">Belum ada sesi ujian</option>
                    <?php else: ?>
                        <?php foreach ($allSessions as $as): ?>
                            <option value="<?= $as['id_sesi'] ?>" <?= ($selectedSesiId == $as['id_sesi']) ? 'selected' : '' ?>>
                                <?= sanitize($as['nama_ujian']) ?> (<?= sanitize($as['nama_mapel']) ?> - <?= sanitize($as['nama_kelas']) ?>)
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>
        </form>
    </div>

    <?php if ($sesiDetail): ?>
        <!-- Ringkasan Info Ujian -->
        <div class="card">
            <!-- Kop Resmi Sekolah (Hanya Tampil Saat Dicetak) -->
            <div class="print-only" style="display: none; margin-bottom: 14px;">
                <div style="display: flex; align-items: center; justify-content: center; gap: 16px; margin-bottom: 8px;">
                    <img src="<?= base_url('assets/img/sdntalun.png') ?>" alt="Logo SDN 1 Talun" style="width: 55px; height: 55px; object-fit: contain;">
                    <div style="text-align: center; flex: 1;">
                        <div style="font-size: 10pt; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #1e293b; line-height: 1.2;">PEMERINTAH KABUPATEN PONOROGO</div>
                        <div style="font-size: 10pt; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #1e293b; line-height: 1.2;">DINAS PENDIDIKAN</div>
                        <div style="font-size: 13pt; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; color: #0f172a; margin: 2px 0; line-height: 1.2;">SD NEGERI 1 TALUN</div>
                        <div style="font-size: 8pt; color: #475569; line-height: 1.2;">Jalan Sukowati No. 23 Desa Talun, Kecamatan Ngebel, Kabupaten Ponorogo, Jawa Timur 63493</div>
                    </div>
                    <div style="width: 55px;"></div>
                </div>
                <div style="border-bottom: 2px solid #0f172a; border-top: 1px solid #0f172a; height: 2px; margin-bottom: 12px;"></div>
                <div style="text-align: center; font-size: 11pt; font-weight: 800; text-decoration: underline; letter-spacing: 0.5px; text-transform: uppercase; color: #0f172a; margin-bottom: 12px;">REKAPITULASI HASIL PENILAIAN ASESMEN (CBT)</div>
            </div>

            <div style="border-bottom: 2px solid var(--gray-800); padding-bottom: 0.75rem; margin-bottom: 1rem;">
                <h2 style="font-size: 1.25rem; font-weight: 800; color: var(--gray-900);"><?= sanitize($sesiDetail['nama_ujian']) ?></h2>
                <div class="flex gap-4 mt-1 text-sm text-muted" style="flex-wrap: wrap;">
                    <div><strong>Mata Pelajaran:</strong> <?= sanitize($sesiDetail['nama_mapel']) ?></div>
                    <div><strong>Kelas:</strong> <?= sanitize($sesiDetail['nama_kelas']) ?></div>
                    <div><strong>Durasi:</strong> <?= $sesiDetail['durasi_menit'] ?> Menit</div>
                    <div><strong>Total Soal:</strong> <?= $totalSoalUjian ?> Butir (<?= $totalPG ?> PG<?= $totalEssai > 0 ? ', ' . $totalEssai . ' Essai' : '' ?>)</div>
                    <div style="display: inline-flex; align-items: center; gap: 0.35rem;">
                        <strong>Status:</strong>
                        <?php if ($sesiDetail['status'] === 'aktif'): ?>
                            <span class="badge badge-online">AKTIF</span>
                            <?php if (($sesiDetail['sisa_detik_sesi'] ?? 0) > 0): ?>
                                <span class="badge" style="background: #eff6ff; color: #1d4ed8; font-family: monospace; font-size: 0.85rem; font-weight: 700; padding: 0.2rem 0.5rem; border: 1px solid #bfdbfe;">
                                    Sisa: <span class="countdown-timer" data-seconds="<?= (int)$sesiDetail['sisa_detik_sesi'] ?>">--:--:--</span>
                                </span>
                            <?php else: ?>
                                <span class="badge badge-offline">Waktu Habis</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="badge badge-offline"><?= strtoupper($sesiDetail['status']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Tabel Nilai Siswa (Auto-Card on Mobile) -->
            <div class="table-responsive table-mobile-cards">
                <table class="table" style="table-layout: auto;">
                    <thead>
                        <tr>
                            <th style="width: 40px; text-align: center;">No</th>
                            <th style="width: 105px;">NIS / Akun</th>
                            <th style="min-width: 170px;">Nama Lengkap Siswa</th>
                            <th style="width: 120px;">Waktu</th>
                            <th style="text-align: center; width: 130px;">Status</th>
                            <th style="text-align: center; width: 85px;">Benar</th>
                            <th style="text-align: center; width: 95px;">Nilai PG</th>
                            <?php if ($totalEssai > 0): ?>
                                <th style="text-align: center; width: 95px;">Nilai Essai</th>
                            <?php endif; ?>
                            <th style="text-align: center; width: 95px;">Nilai Akhir</th>
                            <th style="text-align: center; width: 125px;" class="no-print">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rekapList)): ?>
                            <tr><td colspan="<?= $totalEssai > 0 ? 10 : 9 ?>" class="text-center text-muted" style="padding: 2rem;">Belum ada data siswa di kelas ini.</td></tr>
                        <?php else: ?>
                            <?php foreach ($rekapList as $idx => $r): ?>
                                <tr>
                                    <td data-label="No" style="text-align: center;"><?= $idx + 1 ?></td>
                                    <td data-label="NIS / Akun">
                                        <span class="badge" style="background:#e0f2fe; color:#0369a1; font-family:monospace; font-weight: 700;"><?= sanitize($r['nis'] ?: $r['username']) ?></span>
                                        <?php if ($r['nis'] && $r['nis'] !== $r['username']): ?>
                                            <div class="text-xs text-muted" style="font-family: monospace;"><?= sanitize($r['username']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Nama Siswa">
                                        <strong style="color: var(--gray-900);"><?= sanitize($r['nama_lengkap']) ?></strong>
                                    </td>
                                    <td data-label="Waktu">
                                        <?php if ($r['waktu_mulai']): ?>
                                            <div class="text-xs font-bold" style="color: var(--gray-800);"><?= date('H:i', strtotime($r['waktu_mulai'])) ?> - <?= $r['waktu_selesai'] ? date('H:i', strtotime($r['waktu_selesai'])) : '...' ?></div>
                                            <div class="text-xs text-muted">
                                                <?php
                                                if ($r['waktu_selesai']) {
                                                    $dSec = max(0, strtotime($r['waktu_selesai']) - strtotime($r['waktu_mulai']));
                                                    $m = floor($dSec / 60);
                                                    $s = $dSec % 60;
                                                    echo "{$m}m {$s}s";
                                                } elseif ($r['status_ujian'] === 'sedang') {
                                                    $dSec = max(0, time() - strtotime($r['waktu_mulai']));
                                                    $m = floor($dSec / 60);
                                                    $s = $dSec % 60;
                                                    echo "{$m}m {$s}s (aktif)";
                                                } else {
                                                    echo date('d/m/Y', strtotime($r['waktu_mulai']));
                                                }
                                                ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted text-xs font-bold">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Status" style="text-align: center;">
                                        <?php if ($r['status_ujian'] === 'selesai'): ?>
                                            <span class="badge badge-online">SELESAI</span>
                                        <?php elseif ($r['status_ujian'] === 'sedang'): ?>
                                            <span class="badge badge-aktif">SEDANG MENGERJAKAN</span>
                                        <?php else: ?>
                                            <span class="badge badge-offline">BELUM MENGERJAKAN</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Benar" style="text-align: center; font-weight: 600;">
                                        <?= $r['jumlah_benar'] ?> / <?= $totalPG ?>
                                    </td>
                                    <td data-label="Nilai PG" style="text-align: center; font-size: 0.95rem; font-weight: 700; color: #1e40af;">
                                        <?= number_format((float)$r['nilai_pg'], 2) ?>
                                    </td>
                                    <?php if ($totalEssai > 0): ?>
                                        <td data-label="Nilai Essai" style="text-align: center;">
                                            <?php if ($r['nilai_essai'] !== null): ?>
                                                <strong style="color: #7e22ce; font-size: 0.95rem;"><?= number_format((float)$r['nilai_essai'], 2) ?></strong>
                                            <?php elseif (!empty($r['id_ujian_siswa'])): ?>
                                                <span class="badge" style="background:#fef3c7; color:#b45309; font-size:0.75rem;">Belum Dinilai</span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                    <td data-label="Nilai Akhir" style="text-align: center; font-size: 1.1rem; font-weight: 800; color: <?= ($r['nilai_akhir'] >= 75) ? '#166534' : '#991b1b' ?>;">
                                        <?= number_format((float)$r['nilai_akhir'], 2) ?>
                                    </td>
                                    <td data-label="Aksi" class="no-print" style="text-align: center; white-space: nowrap;">
                                        <?php if (!empty($r['id_ujian_siswa'])): ?>
                                            <div class="flex" style="gap: 0.35rem; align-items: center; justify-content: center;">
                                                <a href="<?= base_url('guru?page=detail_jawaban&id_ujian_siswa=' . (int)$r['id_ujian_siswa'] . '&id_sesi=' . (int)$selectedSesiId) ?>" class="btn btn-sm btn-primary" style="padding: 0.3rem 0.65rem; font-size: 0.78rem; display: inline-flex; align-items: center; gap: 0.35rem; white-space: nowrap;" title="Lihat Lembar Jawaban & Penilaian">
                                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                                                    <span>Detail & Nilai</span>
                                                </a>
                                                <a href="<?= base_url('guru?page=detail_jawaban&action=export_pdf&id_ujian_siswa=' . (int)$r['id_ujian_siswa']) ?>" target="_blank" class="btn btn-sm btn-secondary" style="padding: 0.3rem 0.65rem; font-size: 0.78rem; display: inline-flex; align-items: center; gap: 0.35rem; white-space: nowrap;" title="Ekspor Lembar Jawaban Siswa (PDF)">
                                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>
                                                    <span>Ekspor PDF</span>
                                                </a>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted text-xs font-bold">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalEssai > 0): ?>
                <div class="alert alert-info mt-3 no-print" style="font-size: 0.85rem; padding: 0.65rem 0.95rem; margin-bottom: 0;">
                    <strong>Keterangan:</strong> Terdapat <?= $totalEssai ?> butir soal uraian pada paket ini. Klik tombol <strong>Detail & Nilai</strong> untuk memeriksa lembar jawaban dan menginputkan nilai uraian siswa.
                </div>
            <?php endif; ?>

            <!-- Tanda Tangan Pengesahan (Hanya Tampil Saat Dicetak) -->
            <div class="print-only" style="display: none; margin-top: 30px; page-break-inside: avoid;">
                <table style="width: 100%; border: none !important; font-size: 10pt;">
                    <tr style="border: none !important;">
                        <td style="width: 50%; text-align: center; border: none !important; vertical-align: top;">
                            Mengetahui,<br>
                            Kepala <?= defined('SEKOLAH_NAMA') ? sanitize(SEKOLAH_NAMA) : 'SD Negeri 1 Talun' ?><br><br><br><br><br>
                            <strong><u><?= defined('KEPALA_SEKOLAH_NAMA') ? sanitize(KEPALA_SEKOLAH_NAMA) : 'MASHURI, S.Pd.' ?></u></strong><br>
                            <span style="font-size: 9pt; color: #475569;">NIP. <?= defined('KEPALA_SEKOLAH_NIP') ? sanitize(KEPALA_SEKOLAH_NIP) : '198511052022211001' ?></span>
                        </td>
                        <td style="width: 50%; text-align: center; border: none !important; vertical-align: top;">
                            Talun, <?= date('d/m/Y') ?><br>
                            Guru Penguji / Pengampu<br><br><br><br><br>
                            <strong><u><?= htmlspecialchars((string)(!empty($sesiDetail['nama_guru']) ? $sesiDetail['nama_guru'] : ($currentUser['nama_lengkap'] ?: '...................................................')), ENT_QUOTES, 'UTF-8') ?></u></strong><br>
                            <span style="font-size: 9pt; color: #475569;">NIP. <?= htmlspecialchars((string)(!empty($sesiDetail['nip_guru']) ? $sesiDetail['nip_guru'] : ($currentUser['nip'] ?: '...........................................')), ENT_QUOTES, 'UTF-8') ?></span>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
    <?php else: ?>
        <div class="card text-center" style="padding: 3rem 0;">
            <p class="text-muted">Tidak ada sesi ujian yang dipilih atau belum dibuat.</p>
        </div>
    <?php endif; ?>
</main>

<?php
if ($sesiDetail) {
    $modalFormAction = base_url('guru?page=rekap_nilai&id_sesi=' . (int)$sesiDetail['id_sesi']);
    include __DIR__ . '/../layouts/modal_tambah_waktu.php';
}
?>
<script>
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
if (document.querySelector('.countdown-timer')) {
    setInterval(updateDashboardCountdowns, 1000);
    updateDashboardCountdowns();
}
</script>
<?php
include __DIR__ . '/../layouts/footer.php';
?>
