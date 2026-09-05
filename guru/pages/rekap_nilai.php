<?php
/**
 * Modul Rekapitulasi Nilai Ujian Siswa (Guru Penguji)
 * Mendukung Ekspor CSV dan Tampilan Cetak (Printable View)
 */

require_once __DIR__ . '/../../middleware/auth.php';

$currentUser = auth_check(['guru', 'operator']);
$db = get_db();
$idGuru = $currentUser['id_user'];
$page = 'rekap_nilai';
$pageTitle = 'Rekapitulasi Nilai Ujian';

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
$totalSoalUjian = 0;

if ($selectedSesiId > 0) {
    // Detail Sesi
    $sqlDet = "
        SELECT s.*, m.nama_mapel, k.nama_kelas, k.id_kelas, p.nama_paket
        FROM sesi_ujian s
        LEFT JOIN paket_soal p ON s.id_paket = p.id_paket
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
        if (!empty($sesiDetail['id_paket'])) {
            $stmtStat = $db->prepare("
                SELECT COUNT(*) as total_soal,
                       COUNT(CASE WHEN jenis_soal != 'essai' OR jenis_soal IS NULL THEN 1 END) as total_pg,
                       COUNT(CASE WHEN jenis_soal = 'essai' THEN 1 END) as total_essai
                FROM bank_soal 
                WHERE id_paket = :p
            ");
            $stmtStat->execute([':p' => $sesiDetail['id_paket']]);
        } else {
            $stmtStat = $db->prepare("
                SELECT COUNT(*) as total_soal,
                       COUNT(CASE WHEN jenis_soal != 'essai' OR jenis_soal IS NULL THEN 1 END) as total_pg,
                       COUNT(CASE WHEN jenis_soal = 'essai' THEN 1 END) as total_essai
                FROM bank_soal 
                WHERE id_paket IN (SELECT id_paket FROM paket_soal WHERE id_mapel = :m)
            ");
            $stmtStat->execute([':m' => $sesiDetail['id_mapel']]);
        }
        $statRow = $stmtStat->fetch();

        $totalSoalUjian = (int)($statRow['total_soal'] ?? 0);
        $totalPG        = (int)($statRow['total_pg'] ?? 0);
        $totalEssai     = (int)($statRow['total_essai'] ?? 0);

        // Ambil Siswa yang terdaftar di kelas ini dan status pengerjaannya
        $stmtRekap = $db->prepare("
            SELECT u.id_user, u.nis, u.username, u.nama_lengkap, k.nama_kelas,
                   us.id_ujian_siswa, us.waktu_mulai, us.waktu_selesai, us.status as status_ujian,
                   COALESCE(us.jumlah_benar, 0) as jumlah_benar,
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
    }
}

// Tangani Export CSV
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
    fputcsv($output, ['No', 'NIS', 'Username', 'Nama Lengkap Siswa', 'Kelas', 'Waktu Mulai', 'Waktu Selesai', 'Status Ujian', 'Jumlah Benar (PG)', 'Total Soal PG', 'Nilai PG', 'Total Soal Essai', 'Nilai Essai', 'Nilai Akhir']);

    foreach ($rekapList as $idx => $r) {
        fputcsv($output, [
            $idx + 1,
            $r['nis'] ?? '-',
            $r['username'],
            $r['nama_lengkap'],
            $r['nama_kelas'],
            $r['waktu_mulai'] ?? '-',
            $r['waktu_selesai'] ?? '-',
            strtoupper($r['status_ujian'] ?? 'BELUM'),
            $r['jumlah_benar'],
            $totalPG,
            $r['nilai_pg'],
            $totalEssai,
            $r['nilai_essai'] !== null ? $r['nilai_essai'] : '-',
            $r['nilai_akhir']
        ]);
    }

    fclose($output);
    exit;
}

$flash = flash_get();

include __DIR__ . '/../layouts/header.php';
?>

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
                <button type="button" class="btn btn-primary" onclick="window.print()">Cetak Laporan</button>
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
            <div style="border-bottom: 2px solid var(--gray-800); padding-bottom: 0.75rem; margin-bottom: 1rem;">
                <h2 style="font-size: 1.25rem; font-weight: 800; color: var(--gray-900);"><?= sanitize($sesiDetail['nama_ujian']) ?></h2>
                <div class="flex gap-4 mt-1 text-sm text-muted" style="flex-wrap: wrap;">
                    <div><strong>Mata Pelajaran:</strong> <?= sanitize($sesiDetail['nama_mapel']) ?></div>
                    <div><strong>Kelas:</strong> <?= sanitize($sesiDetail['nama_kelas']) ?></div>
                    <div><strong>Durasi:</strong> <?= $sesiDetail['durasi_menit'] ?> Menit</div>
                    <div><strong>Total Soal:</strong> <?= $totalSoalUjian ?> Butir (<?= $totalPG ?> PG<?= $totalEssai > 0 ? ', ' . $totalEssai . ' Essai' : '' ?>)</div>
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
                            <th style="text-align: center; width: 85px;">Nilai PG</th>
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
                                            <div class="text-xs text-muted"><?= date('d/m/Y', strtotime($r['waktu_mulai'])) ?></div>
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
                                            <a href="<?= base_url('guru?page=detail_jawaban&id_ujian_siswa=' . (int)$r['id_ujian_siswa'] . '&id_sesi=' . (int)$selectedSesiId) ?>" class="btn btn-sm btn-primary" style="padding: 0.3rem 0.65rem; font-size: 0.78rem; display: inline-flex; align-items: center; gap: 0.35rem; white-space: nowrap;">
                                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                                                <span>Detail & Nilai</span>
                                            </a>
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
                    <strong>Keterangan:</strong> Terdapat <?= $totalEssai ?> butir soal uraian/essai pada paket ini. Klik tombol <strong>Detail & Nilai</strong> untuk memeriksa lembar jawaban dan menginputkan nilai essai siswa.
                </div>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="card text-center" style="padding: 3rem 0;">
            <p class="text-muted">Tidak ada sesi ujian yang dipilih atau belum dibuat.</p>
        </div>
    <?php endif; ?>
</main>

<?php
include __DIR__ . '/../layouts/footer.php';
?>
