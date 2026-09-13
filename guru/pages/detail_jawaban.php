<?php
/**
 * Page: Detail Jawaban Siswa
 * Menampilkan hasil pengerjaan siswa dan koreksi 7 bentuk butir soal CBT SDN Talun:
 * PG-1, PGK-L1, PGK-BS-1, PGK-BS-L1, MJDK, IJS, dan Uraian.
 */

require_once __DIR__ . '/../../middleware/auth.php';
require_once __DIR__ . '/../../config/scoring.php';

$currentUser = auth_check(['guru', 'operator']);
$db = get_db();

$idUjianSiswa = (int)($_GET['id_ujian_siswa'] ?? $_POST['id_ujian_siswa'] ?? 0);
$backSesiId   = (int)($_GET['id_sesi'] ?? $_POST['id_sesi'] ?? 0);

if ($idUjianSiswa <= 0) {
    flash_set('danger', 'Data ujian tidak valid.');
    redirect(base_url('guru?page=rekap_nilai' . ($backSesiId > 0 ? '&id_sesi=' . $backSesiId : '')));
}

// 1. Ambil Data Ujian Siswa
try {
    $stmtUjian = $db->prepare("
        SELECT us.*, 
               u.id_user as id_siswa, u.nis, u.username, u.nama_lengkap as nama_siswa,
               s.id_sesi, s.nama_ujian, s.id_paket, s.id_guru, s.durasi_menit,
               s.status as status_sesi, s.created_at as created_at_sesi,
               p.nama_paket,
               m.id_mapel, m.nama_mapel, m.kode_mapel,
               k.id_kelas, k.nama_kelas,
               g.nama_lengkap as nama_guru, g.nip as nip_guru
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
} catch (Throwable $e) {
    $stmtUjian = $db->prepare("
        SELECT us.*, 
               u.id_user as id_siswa, u.nis, u.username, u.nama_lengkap as nama_siswa,
               s.id_sesi, s.nama_ujian, s.id_paket, s.id_guru, s.durasi_menit,
               s.status as status_sesi, s.created_at as created_at_sesi,
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
    if ($detailUjian) {
        $detailUjian['nip_guru'] = null;
    }
}

if (!$detailUjian) {
    flash_set('danger', 'Data ujian siswa tidak ditemukan.');
    redirect(base_url('guru?page=rekap_nilai' . ($backSesiId > 0 ? '&id_sesi=' . $backSesiId : '')));
}

if ($currentUser['role'] === 'guru' && (int)$detailUjian['id_guru'] !== (int)$currentUser['id_user']) {
    flash_set('danger', 'Anda tidak memiliki akses ke data ini.');
    redirect(base_url('guru?page=rekap_nilai'));
}

// 2. Ambil Urutan Butir Soal Ujian
$urutanIds = json_decode($detailUjian['urutan_soal'] ?? '[]', true);
if (!empty($detailUjian['id_paket'])) {
    $stmtAllPaket = $db->prepare("SELECT id_soal FROM bank_soal WHERE id_paket = :p ORDER BY id_soal ASC");
    $stmtAllPaket->execute([':p' => $detailUjian['id_paket']]);
    $paketSoalIds = $stmtAllPaket->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($paketSoalIds)) {
        if (empty($urutanIds) || !is_array($urutanIds)) {
            $urutanIds = $paketSoalIds;
        } else {
            foreach ($paketSoalIds as $psid) {
                if (!in_array($psid, $urutanIds)) {
                    $urutanIds[] = $psid;
                }
            }
        }
    }
} elseif (empty($urutanIds) || !is_array($urutanIds)) {
    $stmtFallback = $db->prepare("SELECT id_soal FROM bank_soal WHERE id_paket IN (SELECT id_paket FROM paket_soal WHERE id_mapel = :m) ORDER BY id_soal ASC");
    $stmtFallback->execute([':m' => $detailUjian['id_mapel']]);
    $urutanIds = $stmtFallback->fetchAll(PDO::FETCH_COLUMN);
}

// Catatan: Saat guru membuka lembar detail jawaban siswa yang sedang aktif ujian ('sedang'),
// status siswa TETAP dibiarkan 'sedang' agar siswa dapat terus mengerjakan ujian secara normal.

// 3. Simpan Nilai Guru (Koreksi Uraian / Penyesuaian Nilai) atau Finalisasi Manual
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (($_POST['action'] ?? '') === 'simpan_nilai_essai') {
        if (!verify_csrf()) {
            flash_set('danger', 'Validasi keamanan CSRF gagal.');
            redirect(base_url('guru?page=detail_jawaban&id_ujian_siswa=' . $idUjianSiswa . ($backSesiId > 0 ? '&id_sesi=' . $backSesiId : '')));
        }

        $inputNilai = $_POST['nilai_soal'] ?? [];
        $rekap = cbt_hitung_rekap_ujian($idUjianSiswa, $db, $inputNilai);

        flash_set('success', 'Nilai ujian siswa berhasil disimpan dan dikalkulasi ulang sesuai rubrik resmi.');
        redirect(base_url('guru?page=detail_jawaban&id_ujian_siswa=' . $idUjianSiswa . ($backSesiId > 0 ? '&id_sesi=' . $backSesiId : '')));
    } elseif (($_POST['action'] ?? '') === 'selesaikan_ujian_siswa') {
        if (!verify_csrf()) {
            flash_set('danger', 'Validasi keamanan CSRF gagal.');
            redirect(base_url('guru?page=detail_jawaban&id_ujian_siswa=' . $idUjianSiswa . ($backSesiId > 0 ? '&id_sesi=' . $backSesiId : '')));
        }

        $stmtLastAct = $db->prepare("
            SELECT MAX(updated_at) FROM jawaban_siswa 
            WHERE id_ujian_siswa = :us AND jawaban_terpilih IS NOT NULL AND jawaban_terpilih != ''
        ");
        $stmtLastAct->execute([':us' => $idUjianSiswa]);
        $lastAct = $stmtLastAct->fetchColumn();

        $waktuSelesaiFixed = $lastAct ?: (
            !empty($detailUjian['waktu_mulai'])
                ? date('Y-m-d H:i:s', min(time(), strtotime($detailUjian['waktu_mulai']) + ((int)$detailUjian['durasi_menit'] * 60)))
                : date('Y-m-d H:i:s')
        );

        $updClose = $db->prepare("
            UPDATE ujian_siswa 
            SET status = 'selesai',
                waktu_selesai = :ws,
                sisa_detik = 0
            WHERE id_ujian_siswa = :us
        ");
        $updClose->execute([':ws' => $waktuSelesaiFixed, ':us' => $idUjianSiswa]);

        // Hitung ulang rekap nilai
        cbt_hitung_rekap_ujian($idUjianSiswa, $db);

        flash_set('success', 'Ujian siswa berhasil diselesaikan secara resmi oleh guru.');
        redirect(base_url('guru?page=detail_jawaban&id_ujian_siswa=' . $idUjianSiswa . ($backSesiId > 0 ? '&id_sesi=' . $backSesiId : '')));
    }
}

// 4. Ambil dan Evaluasi Butir Soal Menggunakan Scoring Engine Resmi
$soalList = [];
$statBenar = 0;
$statSebagian = 0;
$statSalah = 0;
$statKosong = 0;
$statUraian = 0;
$totalSkorDiperoleh = 0.00;
$totalSkorMaksimal  = 0.00;

if (!empty($urutanIds)) {
    $placeholders = implode(',', array_fill(0, count($urutanIds), '?'));
    
    $stmtSoal = $db->prepare("
        SELECT b.id_soal, b.jenis_soal, b.pertanyaan, b.gambar, 
               b.opsi_a, b.opsi_b, b.opsi_c, b.opsi_d, b.opsi_e, b.kunci_jawaban,
               b.bobot_soal, b.konten_soal,
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
        
        $eval = cbt_evaluasi_soal($item, $item['jawaban_terpilih'], $item['nilai_soal']);

        $totalSkorDiperoleh += $eval['skor'];
        $totalSkorMaksimal  += $eval['bobot_max'];

        if ($eval['jenis'] === 'uraian') {
            $statUraian++;
        }

        if ($eval['is_empty']) {
            $statKosong++;
        } elseif ($eval['is_correct']) {
            $statBenar++;
        } elseif ($eval['is_partial']) {
            $statSebagian++;
        } else {
            $statSalah++;
        }

        $opsiList = [
            ['code' => 'A', 'text' => $item['opsi_a']],
            ['code' => 'B', 'text' => $item['opsi_b']],
            ['code' => 'C', 'text' => $item['opsi_c']],
        ];
        if (!empty($item['opsi_d'])) {
            $opsiList[] = ['code' => 'D', 'text' => $item['opsi_d']];
        }
        if (!empty($item['opsi_e'])) {
            $opsiList[] = ['code' => 'E', 'text' => $item['opsi_e']];
        }

        $kontenSoal = null;
        if (!empty($item['konten_soal'])) {
            $kontenSoal = is_array($item['konten_soal']) ? $item['konten_soal'] : json_decode((string)$item['konten_soal'], true);
        }

        $soalList[] = [
            'nomor'            => $index + 1,
            'id_soal'          => (int)$item['id_soal'],
            'jenis_soal'       => $eval['jenis'],
            'short_label'      => $eval['short_label'],
            'nama_jenis'       => $eval['nama_jenis'],
            'bobot_max'        => $eval['bobot_max'],
            'skor'             => $eval['skor'],
            'pertanyaan'       => $item['pertanyaan'],
            'gambar'           => !empty($item['gambar']) ? base_url(ltrim($item['gambar'], '/')) : null,
            'opsi'             => $opsiList,
            'konten_soal'      => $kontenSoal,
            'kunci_jawaban'    => $item['kunci_jawaban'],
            'jawaban_terpilih' => $item['jawaban_terpilih'],
            'nilai_soal'       => $item['nilai_soal'],
            'eval'             => $eval,
            'status_label'     => $eval['status_label'],
            'is_correct'       => $eval['is_correct'],
            'is_partial'       => $eval['is_partial'],
            'keterangan'       => $eval['keterangan']
        ];
    }
}

$calculatedNilaiAkhir = ($totalSkorMaksimal > 0) ? round(($totalSkorDiperoleh / $totalSkorMaksimal) * 100, 2) : 0.00;

// Sinkronisasi otomatis ke database ujian_siswa jika ada perbedaan
if (abs((float)($detailUjian['nilai_akhir'] ?? -1) - $calculatedNilaiAkhir) > 0.01 || (int)($detailUjian['jumlah_benar'] ?? -1) !== $statBenar) {
    $stmtSync = $db->prepare("
        UPDATE ujian_siswa 
        SET jumlah_benar = :benar,
            total_skor = :tot_skor,
            skor_maksimal = :tot_max,
            nilai_akhir = :nak,
            nilai_pg = :tot_skor
        WHERE id_ujian_siswa = :us
    ");
    $stmtSync->execute([
        ':benar'    => $statBenar,
        ':tot_skor' => round($totalSkorDiperoleh, 2),
        ':tot_max'  => round($totalSkorMaksimal, 2),
        ':nak'      => $calculatedNilaiAkhir,
        ':us'       => $idUjianSiswa
    ]);
    $detailUjian['nilai_akhir']   = $calculatedNilaiAkhir;
    $detailUjian['jumlah_benar']  = $statBenar;
    $detailUjian['total_skor']    = round($totalSkorDiperoleh, 2);
    $detailUjian['skor_maksimal'] = round($totalSkorMaksimal, 2);
}

// Durasi Pengerjaan
$durasiKerjaMenit    = '-';
$durasiKerjaText     = '-';
$waktuMulaiFormatted = !empty($detailUjian['waktu_mulai']) ? date('H:i', strtotime($detailUjian['waktu_mulai'])) : null;
$waktuSelesaiFormatted = !empty($detailUjian['waktu_selesai']) ? date('H:i', strtotime($detailUjian['waktu_selesai'])) : null;

if (!empty($detailUjian['waktu_mulai'])) {
    $startSec = strtotime($detailUjian['waktu_mulai']);

    if (!empty($detailUjian['waktu_selesai'])) {
        $endSec   = strtotime($detailUjian['waktu_selesai']);
        $diffSec  = max(0, $endSec - $startSec);
        $menit    = floor($diffSec / 60);
        $detik    = $diffSec % 60;
        $durasiKerjaText  = "{$menit} Menit {$detik} Detik ({$waktuMulaiFormatted} - {$waktuSelesaiFormatted} WIB)";
        $durasiKerjaMenit = "<strong>{$menit} Menit {$detik} Detik</strong> <span style=\"font-size:0.85rem; color:var(--gray-600);\">({$waktuMulaiFormatted} - {$waktuSelesaiFormatted} WIB)</span>";
    } elseif ($detailUjian['status'] === 'sedang') {
        // Cek aktivitas jawaban terakhir siswa
        $stmtLastAct = $db->prepare("
            SELECT MAX(updated_at) FROM jawaban_siswa 
            WHERE id_ujian_siswa = :us AND jawaban_terpilih IS NOT NULL AND jawaban_terpilih != ''
        ");
        $stmtLastAct->execute([':us' => $idUjianSiswa]);
        $lastAct = $stmtLastAct->fetchColumn();

        if ($lastAct) {
            $actSec   = strtotime($lastAct);
            $diffSec  = max(0, $actSec - $startSec);
            $menit    = floor($diffSec / 60);
            $detik    = $diffSec % 60;
            $jamTerakhir = date('H:i', $actSec);
            $durasiKerjaText  = "{$menit} Menit {$detik} Detik (Sedang Berjalan - Mulai {$waktuMulaiFormatted} WIB, Terakhir {$jamTerakhir} WIB)";
            $durasiKerjaMenit = "<strong>{$menit} Menit {$detik} Detik</strong> <span class=\"badge\" style=\"background:#fef3c7; color:#92400e; font-size:0.75rem; vertical-align:middle; margin-left:4px;\">SEDANG BERJALAN</span> <span style=\"font-size:0.85rem; color:var(--gray-600);\">(Mulai {$waktuMulaiFormatted} WIB, Terakhir {$jamTerakhir} WIB)</span>";
        } else {
            $diffSec  = max(0, time() - $startSec);
            $menit    = floor($diffSec / 60);
            $detik    = $diffSec % 60;
            $durasiKerjaText  = "{$menit} Menit {$detik} Detik (Sedang Berjalan - Mulai {$waktuMulaiFormatted} WIB)";
            $durasiKerjaMenit = "<strong>{$menit} Menit {$detik} Detik</strong> <span class=\"badge\" style=\"background:#fef3c7; color:#92400e; font-size:0.75rem; vertical-align:middle; margin-left:4px;\">SEDANG BERJALAN</span> <span style=\"font-size:0.85rem; color:var(--gray-600);\">(Mulai {$waktuMulaiFormatted} WIB)</span>";
        }
    }
}

// =============================================================================
// 5. TANGANI EKSPOR LEMBAR HASIL & JAWABAN SISWA (PDF / PRINT)
// =============================================================================
if (isset($_GET['action']) && in_array($_GET['action'], ['export_pdf', 'export_doc', 'export_dokumen', 'export_jawaban'], true)) {
    if (ob_get_level() > 0) {
        ob_end_clean();
    }

    $rawNamaSiswa = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)$detailUjian['nama_siswa']);
    $rawNis       = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)($detailUjian['nis'] ?: $detailUjian['username']));
    $rawUjian     = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)$detailUjian['nama_ujian']);
    $filenameBase = "Lembar_Jawaban_{$rawNis}_{$rawNamaSiswa}_{$rawUjian}";

    header('Content-Type: text/html; charset=utf-8');
    ?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($filenameBase, ENT_QUOTES, 'UTF-8') ?></title>
<link rel="icon" type="image/svg+xml" href="<?= base_url('assets/img/favicon.svg') ?>">
<style>
  @page {
    size: A4 portrait;
    margin: 12mm 15mm 12mm 15mm;
  }
  * {
    box-sizing: border-box;
  }
  body {
    background-color: #f1f5f9;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    font-size: 10pt;
    line-height: 1.4;
    color: #0f172a;
    margin: 0;
    padding: 0;
  }
  .no-print-bar {
    position: sticky;
    top: 0;
    z-index: 1000;
    background: #1e293b;
    color: #f8fafc;
    padding: 0.75rem 1.5rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    box-shadow: 0 4px 10px rgba(0,0,0,0.15);
    font-family: sans-serif;
  }
  .no-print-bar .title-group {
    display: flex;
    align-items: center;
    gap: 0.75rem;
  }
  .no-print-bar .title-group span {
    font-weight: 700;
    font-size: 0.95rem;
  }
  .no-print-bar .badge-user {
    background: #334155;
    color: #93c5fd;
    font-size: 0.8rem;
    font-weight: 600;
    padding: 0.25rem 0.6rem;
    border-radius: 4px;
  }
  .no-print-bar .btn-group {
    display: flex;
    align-items: center;
    gap: 0.5rem;
  }
  .btn-print-action {
    background: #2563eb;
    color: #ffffff;
    border: none;
    padding: 0.45rem 1rem;
    border-radius: 6px;
    font-size: 0.85rem;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    transition: background 0.15s;
    box-shadow: 0 1px 2px rgba(0,0,0,0.2);
  }
  .btn-print-action:hover {
    background: #1d4ed8;
  }
  .btn-download-action {
    background: #059669;
    color: #ffffff;
    border: none;
    padding: 0.45rem 0.9rem;
    border-radius: 6px;
    font-size: 0.85rem;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    transition: background 0.15s;
    box-shadow: 0 1px 2px rgba(0,0,0,0.2);
  }
  .btn-download-action:hover {
    background: #047857;
  }
  .btn-close-action {
    background: #475569;
    color: #ffffff;
    border: none;
    padding: 0.45rem 0.8rem;
    border-radius: 6px;
    font-size: 0.85rem;
    cursor: pointer;
    transition: background 0.15s;
  }
  .btn-close-action:hover {
    background: #334155;
  }
  .print-hint {
    font-size: 0.75rem;
    color: #94a3b8;
    margin-right: 0.5rem;
  }
  .paper-page {
    background: #ffffff;
    width: 210mm;
    min-height: 297mm;
    margin: 20px auto 40px auto;
    padding: 15mm 18mm;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
    box-sizing: border-box;
    border-radius: 4px;
  }
  table {
    border-collapse: collapse;
    width: 100%;
  }
  .tbl-info {
    margin-bottom: 14px;
    font-size: 9.5pt;
  }
  .tbl-info td {
    padding: 3px 4px;
    vertical-align: top;
  }
  .tbl-score {
    margin-bottom: 16px;
    font-size: 9.5pt;
    border: 1px solid #334155;
  }
  .tbl-score th {
    background-color: #f1f5f9;
    border: 1px solid #334155;
    padding: 6px 8px;
    text-align: center;
    font-weight: 700;
  }
  .tbl-score td {
    border: 1px solid #334155;
    padding: 6px 8px;
    text-align: center;
    font-weight: 700;
    font-size: 11pt;
  }
  .tbl-soal {
    border: 1px solid #334155;
    font-size: 9.5pt;
    margin-top: 10px;
  }
  .tbl-soal th {
    background-color: #e2e8f0;
    border: 1px solid #334155;
    padding: 6px 6px;
    text-align: center;
    font-weight: 700;
  }
  .tbl-soal td {
    border: 1px solid #334155;
    padding: 6px 6px;
    vertical-align: top;
  }
  .essay-ans {
    font-size: 9pt;
    color: #0f172a;
    white-space: pre-wrap;
    background: #f8fafc;
    padding: 4px 6px;
    border-left: 3px solid #64748b;
  }
  .signature-box {
    margin-top: 25px;
    font-size: 10pt;
    border: none;
    page-break-inside: avoid;
    break-inside: avoid;
  }
  .signature-box td {
    border: none;
    padding: 4px;
  }

  @media print {
    .no-print, .no-print-bar {
      display: none !important;
    }
    body {
      background: #ffffff !important;
      padding: 0 !important;
      margin: 0 !important;
    }
    .paper-page {
      width: 100% !important;
      min-height: auto !important;
      margin: 0 !important;
      padding: 0 !important;
      box-shadow: none !important;
      border: none !important;
    }
    tr {
      page-break-inside: avoid;
      break-inside: avoid;
    }
    .tbl-soal tr {
      page-break-inside: avoid;
      break-inside: avoid;
    }
    .signature-box {
      page-break-inside: avoid;
      break-inside: avoid;
    }
  }
</style>
</head>
<body>

<div class="no-print-bar no-print">
  <div class="title-group">
    <span>📄 Lembar Hasil &amp; Jawaban Siswa</span>
    <span class="badge-user"><?= htmlspecialchars($detailUjian['nama_siswa'], ENT_QUOTES, 'UTF-8') ?></span>
  </div>
  <div class="btn-group">
    <span class="print-hint">Pilih "Save as PDF" pada jendela cetak</span>
    <button type="button" class="btn-print-action" onclick="window.print()">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
      <span>Cetak / Simpan PDF</span>
    </button>
    <button type="button" class="btn-download-action" id="btn-download-pdf" onclick="downloadPdfDirectly()">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
      <span>Unduh File .PDF</span>
    </button>
    <button type="button" class="btn-close-action" onclick="window.close()">Tutup</button>
  </div>
</div>

<div class="paper-page" id="printable-area">
  <!-- Kop Resmi Sekolah -->
  <div style="display: flex; align-items: center; justify-content: center; gap: 16px; margin-bottom: 10px;">
    <img src="<?= base_url('assets/img/sdntalun.png') ?>" alt="Logo SDN 1 Talun" style="width: 62px; height: 62px; object-fit: contain;">
    <div style="text-align: center; flex: 1;">
      <div style="font-size: 10.5pt; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #1e293b; line-height: 1.2;">PEMERINTAH KABUPATEN PONOROGO</div>
      <div style="font-size: 10.5pt; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #1e293b; line-height: 1.2;">DINAS PENDIDIKAN</div>
      <div style="font-size: 14pt; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; color: #0f172a; margin: 2px 0; line-height: 1.2;">SD NEGERI 1 TALUN</div>
      <div style="font-size: 8.5pt; color: #475569; line-height: 1.2;">Jalan Sukowati No. 23 Desa Talun, Kecamatan Ngebel, Kabupaten Ponorogo, Jawa Timur 63493</div>
    </div>
    <div style="width: 62px;"></div>
  </div>
  <div style="border-bottom: 2px solid #0f172a; border-top: 1px solid #0f172a; height: 2px; margin-bottom: 14px;"></div>
  <div style="text-align: center; font-size: 12pt; font-weight: 800; text-decoration: underline; letter-spacing: 0.5px; text-transform: uppercase; color: #0f172a; margin-bottom: 14px;">LEMBAR HASIL &amp; JAWABAN SISWA (CBT)</div>

  <table class="tbl-info">
    <tr>
      <td style="width: 16%; font-weight: bold;">Nama Siswa</td>
      <td style="width: 2%;">:</td>
      <td style="width: 32%; font-weight: bold;"><?= htmlspecialchars($detailUjian['nama_siswa'], ENT_QUOTES, 'UTF-8') ?></td>
      <td style="width: 16%; font-weight: bold;">Nama Ujian</td>
      <td style="width: 2%;">:</td>
      <td style="width: 32%; font-weight: bold;"><?= htmlspecialchars($detailUjian['nama_ujian'], ENT_QUOTES, 'UTF-8') ?></td>
    </tr>
    <tr>
      <td style="font-weight: bold;">NIS / Akun</td>
      <td>:</td>
      <td><?= htmlspecialchars((string)($detailUjian['nis'] ?: $detailUjian['username']), ENT_QUOTES, 'UTF-8') ?></td>
      <td style="font-weight: bold;">Mata Pelajaran</td>
      <td>:</td>
      <td><?= htmlspecialchars($detailUjian['nama_mapel'], ENT_QUOTES, 'UTF-8') ?></td>
    </tr>
    <tr>
      <td style="font-weight: bold;">Kelas</td>
      <td>:</td>
      <td><?= htmlspecialchars($detailUjian['nama_kelas'], ENT_QUOTES, 'UTF-8') ?></td>
      <td style="font-weight: bold;">Guru Penguji</td>
      <td>:</td>
      <td><?= htmlspecialchars((string)($detailUjian['nama_guru'] ?: '-'), ENT_QUOTES, 'UTF-8') ?><?= !empty($detailUjian['nip_guru']) ? ' <span style="font-size: 8.5pt; color: #475569;">(NIP. ' . htmlspecialchars($detailUjian['nip_guru'], ENT_QUOTES, 'UTF-8') . ')</span>' : '' ?></td>
    </tr>
    <tr>
      <td style="font-weight: bold;">Waktu Pengerjaan</td>
      <td>:</td>
      <td><?= htmlspecialchars($durasiKerjaText, ENT_QUOTES, 'UTF-8') ?></td>
      <td style="font-weight: bold;">Status Ujian</td>
      <td>:</td>
      <td><?= strtoupper($detailUjian['status']) ?></td>
    </tr>
  </table>

  <!-- Ringkasan Nilai Resmi -->
  <table class="tbl-score">
    <tr>
      <th>Total Skor Diperoleh</th>
      <th>Skor Maksimal</th>
      <th style="background-color: #dbeafe; color: #1e40af;">Nilai Akhir (Skala 100)</th>
      <th>Benar / Sebagian</th>
      <th>Salah / Kosong</th>
    </tr>
    <tr>
      <td style="color: #0284c7;"><?= number_format($totalSkorDiperoleh, 2) ?></td>
      <td><?= number_format($totalSkorMaksimal, 2) ?></td>
      <td style="background-color: #eff6ff; color: #1e3a8a; font-size: 14pt;"><?= number_format($calculatedNilaiAkhir, 2) ?></td>
      <td style="color: #166534; font-size: 11pt;"><?= $statBenar ?> / <?= $statSebagian ?></td>
      <td style="color: #dc2626; font-size: 11pt;"><?= $statSalah ?> / <?= $statKosong ?></td>
    </tr>
  </table>

  <!-- Tabel Rincian Butir Soal -->
  <table class="tbl-soal">
    <thead>
      <tr>
        <th style="width: 5%;">No</th>
        <th style="width: 14%;">Bentuk Soal</th>
        <th style="width: 41%;">Pertanyaan</th>
        <th style="width: 25%;">Jawaban Siswa &amp; Kunci</th>
        <th style="width: 15%;">Skor &amp; Status</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($soalList as $s): ?>
        <tr>
          <td style="text-align: center; font-weight: bold;"><?= $s['nomor'] ?></td>
          <td style="text-align: center;">
            <strong><?= htmlspecialchars($s['short_label'], ENT_QUOTES, 'UTF-8') ?></strong>
            <div style="font-size: 8pt; color: #555;">(Max: <?= $s['bobot_max'] ?>)</div>
          </td>
          <td>
            <div style="font-weight: 500; margin-bottom: 4px;">
              <?= nl2br(htmlspecialchars(trim(strip_tags($s['pertanyaan'])), ENT_QUOTES, 'UTF-8')) ?>
            </div>
          </td>
          <td>
            <div style="margin-bottom: 3px;">
              <span style="font-size: 8.5pt; color: #64748b; font-weight: bold;">Siswa:</span>
              <div class="essay-ans"><?= nl2br(htmlspecialchars((string)$s['jawaban_terpilih'], ENT_QUOTES, 'UTF-8')) ?: '<span style="color:#94a3b8;font-style:italic;">(Kosong)</span>' ?></div>
            </div>
            <div>
              <span style="font-size: 8.5pt; color: #64748b; font-weight: bold;">Kunci:</span>
              <div style="font-size: 9pt; color: #166534; font-weight: bold;"><?= htmlspecialchars((string)$s['kunci_jawaban'], ENT_QUOTES, 'UTF-8') ?: '-' ?></div>
            </div>
          </td>
          <td style="text-align: center;">
            <div style="font-size: 11pt; font-weight: bold; color: <?= $s['is_correct'] ? '#166534' : ($s['is_partial'] ? '#ca8a04' : '#dc2626') ?>;">
              <?= number_format($s['skor'], 2) ?> / <?= $s['bobot_max'] ?>
            </div>
            <div style="font-size: 8pt; font-weight: bold; color: #555;">
              <?= htmlspecialchars($s['status_label'], ENT_QUOTES, 'UTF-8') ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <!-- Tanda Tangan Pengesahan -->
  <table class="signature-box" style="width: 100%; border: none; margin-top: 25px; font-size: 10pt; page-break-inside: avoid;">
    <tr>
      <td style="width: 50%; text-align: center; border: none; vertical-align: top;">
        Mengetahui,<br>
        Kepala SD Negeri 1 Talun<br><br><br><br><br>
        <strong><u>...................................................</u></strong><br>
        <span style="font-size: 9pt; color: #475569;">NIP. ...........................................</span>
      </td>
      <td style="width: 50%; text-align: center; border: none; vertical-align: top;">
        Talun, <?= date('d/m/Y') ?><br>
        Guru Penguji / Pengampu<br><br><br><br><br>
        <strong><u><?= htmlspecialchars((string)($detailUjian['nama_guru'] ?: '...................................................'), ENT_QUOTES, 'UTF-8') ?></u></strong><br>
        <span style="font-size: 9pt; color: #475569;">NIP. <?= htmlspecialchars((string)($detailUjian['nip_guru'] ?: '...........................................'), ENT_QUOTES, 'UTF-8') ?></span>
      </td>
    </tr>
  </table>

</div>

<script src="<?= base_url('assets/js/html2pdf.bundle.min.js') ?>"></script>
<script>
function downloadPdfDirectly() {
    const btn = document.getElementById('btn-download-pdf');
    if (typeof html2pdf === 'undefined') {
        window.print();
        return;
    }
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span>⏳ Memproses PDF...</span>';
    }
    const element = document.getElementById('printable-area');
    const opt = {
        margin:       [10, 12, 10, 12],
        filename:     '<?= $filenameBase ?>.pdf',
        image:        { type: 'jpeg', quality: 0.98 },
        html2canvas:  { scale: 2, useCORS: true, logging: false },
        jsPDF:        { unit: 'mm', format: 'a4', orientation: 'portrait' },
        pagebreak:    { mode: ['avoid-all', 'css', 'legacy'] }
    };
    html2pdf().set(opt).from(element).save().then(function() {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg><span>Unduh File .PDF</span>';
        }
    }).catch(function(err) {
        console.error(err);
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<span>Unduh File .PDF</span>';
        }
        window.print();
    });
}

window.addEventListener('load', function() {
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('download') === '1') {
        setTimeout(downloadPdfDirectly, 300);
    }
});
</script>
</body>
</html>
    <?php
    exit;
}

// =============================================================================
// 6. TANGANI EKSPOR CSV
// =============================================================================
if (isset($_GET['action']) && $_GET['action'] === 'export_csv') {
    if (ob_get_level() > 0) {
        ob_end_clean();
    }

    $rawNamaSiswa = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)$detailUjian['nama_siswa']);
    $rawNis       = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)($detailUjian['nis'] ?: $detailUjian['username']));
    $filename     = "koreksi_{$rawNis}_{$rawNamaSiswa}.csv";

    header('Content-Description: File Transfer');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Expires: 0');
    header('Pragma: public');

    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    fwrite($out, "sep=,
");

    fputcsv($out, ['nomor', 'bentuk_soal', 'pertanyaan', 'kunci_jawaban', 'jawaban_siswa', 'skor_diperoleh', 'skor_maksimal', 'status']);

    foreach ($soalList as $s) {
        fputcsv($out, [
            $s['nomor'],
            $s['short_label'],
            trim(strip_tags((string)$s['pertanyaan'])),
            (string)$s['kunci_jawaban'],
            (string)$s['jawaban_terpilih'],
            $s['skor'],
            $s['bobot_max'],
            $s['status_label']
        ]);
    }

    fclose($out);
    exit;
}

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
        gap: 0.4rem;
        margin-top: 0.5rem;
    }
    .opt-item {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.45rem 0.75rem;
        border: 1px solid #e2e8f0;
        border-radius: 4px;
        font-size: 0.9rem;
        background: #fff;
    }
    .opt-item.is-correct-choice {
        background: #dcfce7;
        border-color: #86efac;
        color: #166534;
        font-weight: 600;
    }
    .opt-item.is-wrong-choice {
        background: #fee2e2;
        border-color: #fca5a5;
        color: #991b1b;
        font-weight: 600;
    }
    .opt-item.is-key-target {
        background: #f0fdf4;
        border-color: #bbf7d0;
        color: #15803d;
        font-style: italic;
    }
    .badge-status {
        padding: 0.25rem 0.6rem;
        border-radius: 9999px;
        font-size: 0.75rem;
        font-weight: 700;
        text-transform: uppercase;
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
    }
    .badge-status.benar {
        background: #dcfce7;
        color: #166534;
    }
    .badge-status.sebagian {
        background: #fef9c3;
        color: #854d0e;
    }
    .badge-status.salah {
        background: #fee2e2;
        color: #991b1b;
    }
    .badge-status.kosong {
        background: #f1f5f9;
        color: #475569;
    }
</style>
';

include __DIR__ . '/../layouts/header.php';
?>

<main class="container">
    <div class="page-header">
        <div>
            <h1 class="card-title">Koreksi &amp; Lembar Jawaban Siswa</h1>
            <p style="color: var(--gray-500); font-size: 0.85rem; margin-top: 0.25rem;">
                Evaluasi jawaban siswa berdasarkan 7 bentuk soal &amp; rubrik resmi SDN Talun.
            </p>
        </div>
        <div style="display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;">
            <a href="<?= base_url('guru?page=detail_jawaban&action=export_pdf&id_ujian_siswa=' . $idUjianSiswa) ?>" target="_blank" class="btn btn-primary btn-sm" style="display: inline-flex; align-items: center; gap: 0.35rem;" title="Ekspor Lembar Jawaban Siswa (PDF)">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>
                <span>Ekspor PDF</span>
            </a>
            <a href="<?= base_url('guru?page=detail_jawaban&action=export_csv&id_ujian_siswa=' . $idUjianSiswa) ?>" class="btn btn-outline btn-sm" style="display: inline-flex; align-items: center; gap: 0.35rem;" title="Ekspor CSV">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                <span>CSV</span>
            </a>
            <?php if ($detailUjian['status'] === 'sedang'): ?>
                <form action="<?= base_url('guru?page=detail_jawaban') ?>" method="POST" style="display: inline;" onsubmit="return confirm('Apakah Anda yakin ingin menyelesaikan ujian siswa ini sekarang?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="selesaikan_ujian_siswa">
                    <input type="hidden" name="id_ujian_siswa" value="<?= $idUjianSiswa ?>">
                    <input type="hidden" name="id_sesi" value="<?= (int)$detailUjian['id_sesi'] ?>">
                    <button type="submit" class="btn btn-warning btn-sm font-bold" style="display: inline-flex; align-items: center; gap: 0.35rem;">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
                        <span>Tandai Selesai</span>
                    </button>
                </form>
            <?php endif; ?>
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
            <div class="info-row">
                <span class="info-label">Status Ujian</span>
                <span class="info-val">
                    <?php if ($detailUjian['status'] === 'selesai'): ?>
                        <span class="badge badge-online">SELESAI</span>
                    <?php else: ?>
                        <span class="badge badge-aktif">SEDANG MENGERJAKAN</span>
                    <?php endif; ?>
                </span>
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
                <span class="info-label">Waktu Pengerjaan</span>
                <span class="info-val"><?= $durasiKerjaMenit ?></span>
            </div>
        </div>
    </div>

    <!-- Ringkasan Nilai Akurat -->
    <div class="scores-panel">
        <div class="score-card" style="border-left: 4px solid #2563eb;">
            <div class="title">Nilai Akhir (Skala 100)</div>
            <div class="number" style="color: #1d4ed8; font-size: 1.6rem;"><?= number_format((float)$detailUjian['nilai_akhir'], 2) ?></div>
        </div>
        <div class="score-card">
            <div class="title">Akumulasi Skor Butir</div>
            <div class="number" style="color: #0284c7;"><?= number_format($totalSkorDiperoleh, 2) ?> <span style="font-size:0.9rem; color:#64748b;">/ <?= number_format($totalSkorMaksimal, 2) ?></span></div>
        </div>
        <div class="score-card">
            <div class="title">Benar Sempurna</div>
            <div class="number" style="color: #16a34a;"><?= $statBenar ?></div>
        </div>
        <div class="score-card">
            <div class="title">Sebagian Benar</div>
            <div class="number" style="color: #ca8a04;"><?= $statSebagian ?></div>
        </div>
        <div class="score-card">
            <div class="title">Salah / Kosong</div>
            <div class="number" style="color: #dc2626;"><?= $statSalah + $statKosong ?></div>
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
                        <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
                            <strong>Soal No. <?= $s['nomor'] ?></strong>
                            <span class="badge" style="background:#e0f2fe; color:#0369a1; font-weight:700; font-size:0.75rem; padding:0.2rem 0.55rem; border-radius:999px;">
                                <?= sanitize($s['short_label']) ?>: <?= sanitize($s['nama_jenis']) ?>
                            </span>
                            <span style="font-size:0.8rem; color:#64748b; font-weight:600;">
                                (Bobot: <?= $s['bobot_max'] ?> Poin)
                            </span>
                        </div>
                        <div>
                            <?php if ($s['jenis_soal'] === 'uraian'): ?>
                                <?php if ($s['nilai_soal'] !== null): ?>
                                    <span class="badge-status benar">Skor: <?= number_format((float)$s['nilai_soal'], 2) ?> / <?= $s['bobot_max'] ?></span>
                                <?php elseif ($s['jawaban_terpilih'] === ''): ?>
                                    <span class="badge-status kosong">Kosong (0)</span>
                                <?php else: ?>
                                    <span class="badge-status sebagian" style="background:#fef3c7; color:#92400e;">Menunggu Koreksi</span>
                                <?php endif; ?>
                            <?php elseif ($s['is_correct']): ?>
                                <span class="badge-status benar">Benar (<?= number_format($s['skor'], 2) ?> / <?= $s['bobot_max'] ?>)</span>
                            <?php elseif ($s['is_partial']): ?>
                                <span class="badge-status sebagian">Sebagian Benar (<?= number_format($s['skor'], 2) ?> / <?= $s['bobot_max'] ?>)</span>
                            <?php elseif (empty($s['jawaban_terpilih'])): ?>
                                <span class="badge-status kosong">Kosong (0 / <?= $s['bobot_max'] ?>)</span>
                            <?php else: ?>
                                <span class="badge-status salah">Salah (0 / <?= $s['bobot_max'] ?>)</span>
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

                    <!-- TAMPILAN JAWABAN SESUAI BENTUK SOAL -->

                    <!-- 1. URAIAN / ESSAI -->
                    <?php if ($s['jenis_soal'] === 'uraian'): ?>
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

                            <div style="background:#ede9fe;border:1px solid #ddd6fe;border-radius:6px;padding:0.75rem 1rem;">
                                <div style="font-size:0.85rem;font-weight:700;color:#5b21b6;margin-bottom:0.5rem;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:0.4rem;">
                                    <span>Penilaian Nilai Guru (Skala 0 s/d <?= $s['bobot_max'] ?>):</span>
                                </div>
                                <div style="display:flex;gap:0.75rem;align-items:center;flex-wrap:wrap;">
                                    <input type="number" step="0.1" min="0" max="<?= $s['bobot_max'] ?>" name="nilai_soal[<?= $s['id_soal'] ?>]" value="<?= $s['nilai_soal'] !== null ? $s['nilai_soal'] : '' ?>" class="form-control" style="width: 110px; font-size:1.1rem; font-weight:800;" placeholder="0 - <?= $s['bobot_max'] ?>">
                                    <span style="font-weight:700; color:#5b21b6;">/ <?= $s['bobot_max'] ?> Poin</span>
                                    <div style="display:flex; gap:0.35rem;">
                                        <button type="button" class="btn btn-sm btn-outline" onclick="this.parentElement.previousElementSibling.previousElementSibling.value = 0;">0</button>
                                        <button type="button" class="btn btn-sm btn-outline" onclick="this.parentElement.previousElementSibling.previousElementSibling.value = <?= round($s['bobot_max'] * 0.5, 1) ?>;">50%</button>
                                        <button type="button" class="btn btn-sm btn-outline" onclick="this.parentElement.previousElementSibling.previousElementSibling.value = <?= $s['bobot_max'] ?>;">Penuh (<?= $s['bobot_max'] ?>)</button>
                                    </div>
                                </div>
                            </div>
                        </div>

                    <!-- 2. ISIAN / JAWABAN SINGKAT (IJS) -->
                    <?php elseif ($s['jenis_soal'] === 'ijs'): ?>
                        <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:0.75rem 1rem; margin-top:0.5rem;">
                            <div style="display:flex; justify-content:space-between; margin-bottom:0.4rem; font-size:0.85rem;">
                                <span><strong>Jawaban Siswa:</strong> <span style="font-size:1rem; font-weight:700; color:<?= $s['is_correct'] ? '#166534' : '#991b1b' ?>;"><?= sanitize($s['jawaban_terpilih']) ?: '<em style="color:#94a3b8;">(Kosong)</em>' ?></span></span>
                                <span><strong>Kunci Jawaban:</strong> <code style="background:#e0f2fe; color:#0369a1; padding:0.2rem 0.5rem; border-radius:4px; font-weight:700;"><?= sanitize($s['kunci_jawaban']) ?></code></span>
                            </div>
                            <div style="font-size:0.82rem; color:#64748b;">
                                <?= sanitize($s['keterangan']) ?>
                            </div>
                        </div>

                    <!-- 3. BENAR / SALAH 1 PERNYATAAN (PGK-BS-1) -->
                    <?php elseif ($s['jenis_soal'] === 'pgk_bs_1'): ?>
                        <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:0.75rem 1rem; margin-top:0.5rem;">
                            <div style="display:flex; justify-content:space-between; margin-bottom:0.4rem; font-size:0.85rem;">
                                <span><strong>Pilihan Siswa:</strong> <strong style="color:<?= $s['is_correct'] ? '#166534' : '#991b1b' ?>;"><?= sanitize($s['jawaban_terpilih']) ?: '(Kosong)' ?></strong></span>
                                <span><strong>Kunci Benar:</strong> <strong style="color:#166534;"><?= sanitize($s['kunci_jawaban']) ?></strong></span>
                            </div>
                            <div style="font-size:0.82rem; color:#64748b;">
                                <?= sanitize($s['keterangan']) ?>
                            </div>
                        </div>

                    <!-- 4. BENAR / SALAH > 1 PERNYATAAN (PGK-BS-L1) -->
                    <?php elseif ($s['jenis_soal'] === 'pgk_bs_l1'): ?>
                        <div style="margin-top:0.5rem;">
                            <table class="cbt-table-bs">
                                <thead>
                                    <tr>
                                        <th style="width: 40px; text-align:center;">No</th>
                                        <th>Pernyataan</th>
                                        <th style="width: 130px; text-align:center;">Jawaban Siswa</th>
                                        <th style="width: 110px; text-align:center;">Kunci Benar</th>
                                        <th style="width: 90px; text-align:center;">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($s['eval']['detail']['rows'])): ?>
                                        <?php foreach ($s['eval']['detail']['rows'] as $r): ?>
                                            <tr>
                                                <td style="text-align:center; font-weight:700; color:#64748b;"><?= $r['index'] ?></td>
                                                <td><?= sanitize($r['pernyataan']) ?></td>
                                                <td style="text-align:center; font-weight:700; color:<?= $r['is_correct'] ? '#166534' : '#991b1b' ?>;">
                                                    <?= $r['siswa'] ? ($r['siswa'] === 'B' ? 'BENAR' : 'SALAH') : '<em style="color:#94a3b8;">(Kosong)</em>' ?>
                                                </td>
                                                <td style="text-align:center; font-weight:700; color:#166534;">
                                                    <?= $r['kunci'] === 'B' ? 'BENAR' : 'SALAH' ?>
                                                </td>
                                                <td style="text-align:center;">
                                                    <?= $r['is_correct'] ? '<span class="badge-status benar">✔ Tepat</span>' : '<span class="badge-status salah">✖ Salah</span>' ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                            <div style="font-size:0.82rem; color:#64748b; margin-top:0.4rem;">
                                <?= sanitize($s['keterangan']) ?>
                            </div>
                        </div>

                    <!-- 5. MENJODOHKAN (MJDK) -->
                    <?php elseif ($s['jenis_soal'] === 'mjdk'): ?>
                        <div style="margin-top:0.5rem;">
                            <table class="cbt-table-mjdk">
                                <thead>
                                    <tr>
                                        <th style="width: 40px; text-align:center;">No</th>
                                        <th style="width: 45%;">Pokok Soal / Premis</th>
                                        <th style="width: 25%;">Pasangan Siswa</th>
                                        <th style="width: 20%;">Kunci Tepat</th>
                                        <th style="width: 10%; text-align:center;">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($s['eval']['detail']['rows'])): ?>
                                        <?php foreach ($s['eval']['detail']['rows'] as $r): ?>
                                            <tr>
                                                <td style="text-align:center; font-weight:700; color:#64748b;"><?= $r['index'] ?></td>
                                                <td style="font-weight:600;"><?= sanitize($r['premis']) ?></td>
                                                <td style="font-weight:700; color:<?= $r['is_correct'] ? '#166534' : '#991b1b' ?>;">
                                                    <?= sanitize($r['siswa']) ?: '<em style="color:#94a3b8;">(Kosong)</em>' ?>
                                                </td>
                                                <td style="font-weight:700; color:#166534;"><?= sanitize($r['kunci']) ?></td>
                                                <td style="text-align:center;">
                                                    <?= $r['is_correct'] ? '<span class="badge-status benar">✔ Tepat</span>' : '<span class="badge-status salah">✖ Salah</span>' ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                            <div style="font-size:0.82rem; color:#64748b; margin-top:0.4rem;">
                                <?= sanitize($s['keterangan']) ?>
                            </div>
                        </div>

                    <!-- 6. PILIHAN GANDA (PG-1) ATAU KOMPLEKS (PGK-L1) -->
                    <?php else: ?>
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
                                        $tag = '<span style="margin-left:auto;font-size:0.75rem;">[Jawaban Siswa &amp; Kunci Benar]</span>';
                                    } elseif ($isChoice && !$isKey) {
                                        $cls = 'is-wrong-choice';
                                        $tag = '<span style="margin-left:auto;font-size:0.75rem;">[Jawaban Siswa - Salah]</span>';
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
                        <?php if (!empty($s['keterangan'])): ?>
                            <div style="font-size:0.82rem; color:#64748b; margin-top:0.5rem;">
                                ℹ <?= sanitize($s['keterangan']) ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>

                </div>
            <?php endforeach; ?>

            <div style="text-align: right; margin-top: 1.5rem; margin-bottom: 2rem;">
                <button type="submit" class="btn btn-primary" style="padding: 0.65rem 2rem; font-size: 1rem; font-weight: 700;">
                    Simpan Seluruh Nilai Ujian
                </button>
            </div>
        <?php endif; ?>
    </form>
</main>

<?php include __DIR__ . '/../layouts/footer.php'; ?>
