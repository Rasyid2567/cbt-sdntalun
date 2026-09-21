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
               u.no_hp as no_hp_siswa, u.orang_tua, u.no_hp_ortu,
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
               u.no_hp as no_hp_siswa, u.orang_tua, u.no_hp_ortu,
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

if ($currentUser['role'] === 'guru') {
    $isOwner = ((int)$detailUjian['id_guru'] === (int)$currentUser['id_user']);
    $isWaliKelas = (!empty($currentUser['id_kelas']) && (int)$detailUjian['id_kelas'] === (int)$currentUser['id_kelas']);
    if (!$isOwner && !$isWaliKelas) {
        flash_set('danger', 'Anda tidak memiliki akses ke data ini.');
        redirect(base_url('guru?page=rekap_nilai'));
    }
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
$durasiLaporan       = '-';
$waktuMulaiFormatted = !empty($detailUjian['waktu_mulai']) ? date('H:i', strtotime($detailUjian['waktu_mulai'])) : null;
$waktuSelesaiFormatted = !empty($detailUjian['waktu_selesai']) ? date('H:i', strtotime($detailUjian['waktu_selesai'])) : null;
$alokasiMenit = (int)($detailUjian['durasi_menit'] ?? 0);

if (!empty($detailUjian['waktu_mulai'])) {
    $startSec = strtotime($detailUjian['waktu_mulai']);

    if (!empty($detailUjian['waktu_selesai'])) {
        $endSec   = strtotime($detailUjian['waktu_selesai']);
        $diffSec  = max(0, $endSec - $startSec);
        $menit    = (int)floor($diffSec / 60);
        $durasiLaporan    = $alokasiMenit > 0 ? "{$menit} Menit ({$alokasiMenit} Menit)" : "{$menit} Menit";
        $durasiKerjaText  = $durasiLaporan;
        $durasiKerjaMenit = "<strong>{$menit} Menit</strong>" . ($alokasiMenit > 0 ? " <span style=\"color:var(--gray-600); font-weight:normal;\">({$alokasiMenit} Menit)</span>" : "");
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
            $menit    = (int)floor($diffSec / 60);
            $durasiLaporan    = $alokasiMenit > 0 ? "{$menit} Menit ({$alokasiMenit} Menit)" : "{$menit} Menit";
            $durasiKerjaText  = $durasiLaporan . " (Sedang Berjalan)";
            $durasiKerjaMenit = "<strong>{$menit} Menit</strong>" . ($alokasiMenit > 0 ? " <span style=\"color:var(--gray-600); font-weight:normal;\">({$alokasiMenit} Menit)</span>" : "") . " <span class=\"badge\" style=\"background:#fef3c7; color:#92400e; font-size:0.75rem; vertical-align:middle; margin-left:4px;\">SEDANG BERJALAN</span>";
        } else {
            $diffSec  = max(0, time() - $startSec);
            $menit    = (int)floor($diffSec / 60);
            $durasiLaporan    = $alokasiMenit > 0 ? "{$menit} Menit ({$alokasiMenit} Menit)" : "{$menit} Menit";
            $durasiKerjaText  = $durasiLaporan . " (Sedang Berjalan)";
            $durasiKerjaMenit = "<strong>{$menit} Menit</strong>" . ($alokasiMenit > 0 ? " <span style=\"color:var(--gray-600); font-weight:normal;\">({$alokasiMenit} Menit)</span>" : "") . " <span class=\"badge\" style=\"background:#fef3c7; color:#92400e; font-size:0.75rem; vertical-align:middle; margin-left:4px;\">SEDANG BERJALAN</span>";
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
    $totalSkor  = (float)($detailUjian["total_skor"] ?? $totalSkorDiperoleh);
    $totalMax   = (float)($detailUjian["skor_maksimal"] ?? $totalSkorMaksimal);
    $nilaiAkhir = (float)($detailUjian["nilai_akhir"] ?? $calculatedNilaiAkhir);

    $totalSoal = count($soalList);


    header('Content-Type: text/html; charset=utf-8');
    ?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($filenameBase, ENT_QUOTES, "UTF-8") ?></title>
<link rel="icon" type="image/png" href="<?= base_url("assets/img/sdntalun.png") ?>">
<style>
  @page {
    size: A4 portrait;
    margin: 12mm 15mm 12mm 15mm;
  }
  * {
    box-sizing: border-box;
  }
  body {
    background-color: #383d41; /* Chrome PDF Reader Canvas */
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    font-size: 10pt;
    line-height: 1.4;
    color: #0f172a;
    margin: 0;
    padding: 0;
    overflow-x: auto;
  }

  /* Browser PDF Viewer Style Top Bar */
  .no-print-bar {
    position: sticky;
    top: 0;
    z-index: 1000;
    background: #202124; /* Google Chrome PDF viewer toolbar */
    color: #f1f5f9;
    padding: 0.45rem 1rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    box-shadow: 0 2px 8px rgba(0,0,0,0.35);
    font-family: sans-serif;
    gap: 0.5rem;
  }

  .bar-left {
    display: flex;
    align-items: center;
    gap: 0.65rem;
    min-width: 0;
  }

  .btn-close-action {
    background: rgba(255,255,255,0.12);
    color: #f1f5f9;
    border: 1px solid rgba(255,255,255,0.18);
    padding: 0.35rem 0.65rem;
    border-radius: 6px;
    font-size: 0.78rem;
    font-weight: 600;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    transition: background 0.15s;
    white-space: nowrap;
  }
  .btn-close-action:hover {
    background: rgba(255,255,255,0.22);
  }

  .doc-info {
    display: flex;
    flex-direction: column;
    min-width: 0;
  }
  .doc-title {
    font-size: 0.85rem;
    font-weight: 700;
    white-space: nowrap;
    color: #ffffff;
  }
  .doc-student {
    font-size: 0.74rem;
    color: #94a3b8;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 220px;
  }

  .bar-center {
    display: flex;
    align-items: center;
    justify-content: center;
  }

  .zoom-controls {
    display: inline-flex;
    align-items: center;
    background: rgba(0,0,0,0.35);
    border: 1px solid rgba(255,255,255,0.14);
    border-radius: 6px;
    padding: 2px 4px;
    gap: 3px;
  }

  .btn-zoom {
    background: transparent;
    color: #cbd5e1;
    border: none;
    width: 24px;
    height: 24px;
    border-radius: 4px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: background 0.15s;
    padding: 0;
  }
  .btn-zoom:hover {
    background: rgba(255,255,255,0.18);
    color: #fff;
  }

  .zoom-label {
    font-size: 0.75rem;
    font-weight: 600;
    color: #e2e8f0;
    min-width: 36px;
    text-align: center;
    user-select: none;
  }

  .btn-zoom-preset {
    background: transparent;
    color: #cbd5e1;
    border: none;
    padding: 0.12rem 0.4rem;
    font-size: 0.72rem;
    font-weight: 600;
    border-radius: 4px;
    cursor: pointer;
    transition: all 0.15s;
  }
  .btn-zoom-preset:hover,
  .btn-zoom-preset.active {
    background: rgba(255,255,255,0.22);
    color: #ffffff;
  }

  .bar-right {
    display: flex;
    align-items: center;
    gap: 0.4rem;
  }

  .btn-print-action {
    background: #2563eb;
    color: #ffffff;
    border: none;
    padding: 0.35rem 0.75rem;
    border-radius: 6px;
    font-size: 0.78rem;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    transition: background 0.15s;
    box-shadow: 0 1px 3px rgba(0,0,0,0.2);
    white-space: nowrap;
  }
  .btn-print-action:hover {
    background: #1d4ed8;
  }



  /* Viewport Kanvas Dokumen */
  .preview-container {
    width: 100%;
    min-height: calc(100vh - 50px);
    background-color: #383d41;
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 24px 12px 60px 12px;
    box-sizing: border-box;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    gap: 26px;
  }

  /* Lembaran Kertas A4 Fisik */
  .paper-page {
    background: #ffffff !important;
    color: #0f172a;
    width: 210mm;
    min-height: 297mm;
    height: auto;
    margin: 0 auto;
    padding: 12mm 15mm;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.45), 0 0 0 1px rgba(0, 0, 0, 0.15);
    box-sizing: border-box;
    border-radius: 2px;
    position: relative;
  }

  .rendering-pdf {
    background-color: #ffffff !important;
    padding: 0 !important;
    gap: 0 !important;
  }
  .rendering-pdf .paper-page {
    box-shadow: none !important;
    border-radius: 0 !important;
    margin: 0 !important;
  }

  table {
    border-collapse: collapse;
    width: 100%;
    background-color: #ffffff;
  }
  .tbl-info {
    margin-bottom: 12px;
    font-size: 9.5pt;
    background-color: #ffffff;
  }
  .tbl-info td {
    padding: 3px 4px;
    vertical-align: top;
    background-color: #ffffff;
  }
  .tbl-score {
    margin-bottom: 14px;
    font-size: 9pt;
    border: 1px solid #334155;
    background-color: #ffffff;
  }
  .tbl-score th {
    background-color: #f1f5f9;
    border: 1px solid #334155;
    padding: 5px 6px;
    text-align: center;
    font-weight: 700;
  }
  .tbl-score td {
    border: 1px solid #334155;
    padding: 5px 6px;
    text-align: center;
    font-weight: 700;
    font-size: 10.5pt;
    background-color: #ffffff;
  }
  .tbl-soal {
    border: 1px solid #334155;
    font-size: 9pt;
    margin-top: 6px;
    background-color: #ffffff;
    border-collapse: collapse;
  }
  .tbl-soal thead {
    display: table-header-group;
  }
  .tbl-soal th {
    background-color: #e2e8f0;
    border: 1px solid #334155;
    padding: 5px 6px;
    text-align: center;
    font-weight: 700;
  }
  .tbl-soal td {
    border: 1px solid #334155;
    padding: 5px 6px;
    vertical-align: top;
    background-color: #ffffff;
  }
  .essay-ans {
    font-size: 8.5pt;
    color: #0f172a;
    white-space: pre-wrap;
    background: #f8fafc;
    padding: 4px 6px;
    border-left: 3px solid #64748b;
  }
  .signature-box {
    margin-top: 25px;
    font-size: 9.5pt;
    border: none;
    background-color: #ffffff;
    page-break-inside: avoid;
    break-inside: avoid;
  }
  .signature-box td {
    border: none;
    padding: 4px;
    background-color: #ffffff;
  }

  /* Penyesuaian Tampilan Layar HP / Mobile */
  @media screen and (max-width: 768px) {
    .no-print-bar {
      padding: 0.4rem 0.5rem;
      flex-wrap: wrap;
    }
    .bar-left {
      order: 1;
      flex: 1 1 auto;
      gap: 0.4rem;
    }
    .doc-student {
      max-width: 120px;
    }
    .bar-right {
      order: 2;
      flex: 0 0 auto;
      gap: 0.3rem;
    }
    .btn-print-action {
      padding: 0.32rem 0.55rem;
      font-size: 0.74rem;
    }
    .btn-close-action span {
      display: none;
    }
    .btn-close-action {
      padding: 0.32rem 0.45rem;
    }
    .bar-center {
      order: 3;
      width: 100%;
      justify-content: center;
      margin-top: 2px;
      padding-top: 3px;
      border-top: 1px solid rgba(255,255,255,0.08);
    }
    .preview-container {
      padding: 12px 8px 40px 8px;
    }
    .paper-page {
      width: 100% !important;
      min-width: 0 !important;
      max-width: 100% !important;
      min-height: auto !important;
      padding: 14px 10px 14px 10px !important;
      box-shadow: 0 4px 16px rgba(0, 0, 0, 0.35), 0 0 0 1px rgba(0, 0, 0, 0.12) !important;
      border-radius: 4px !important;
    }
    .tbl-info {
      font-size: 8.5pt;
    }
    .tbl-info td {
      padding: 2px 2px;
    }
    .tbl-score {
      font-size: 8pt;
    }
    .tbl-score th, .tbl-score td {
      padding: 4px 2px;
    }
    .tbl-score td {
      font-size: 10pt;
    }
    .tbl-soal {
      font-size: 8.5pt;
    }
    .tbl-soal th, .tbl-soal td {
      padding: 4px 4px;
    }
  }

  /* Cetak Printer Otentik A4 (Auto Page Breaks Antar Baris) */
  @media print {
    .no-print, .no-print-bar {
      display: none !important;
    }
    body {
      background: #ffffff !important;
      padding: 0 !important;
      margin: 0 !important;
    }
    .preview-container {
      padding: 0 !important;
      margin: 0 !important;
      background: #ffffff !important;
      display: block !important;
      overflow: visible !important;
      min-height: auto !important;
    }
    .paper-page {
      width: 100% !important;
      min-width: 100% !important;
      max-width: 100% !important;
      min-height: auto !important;
      height: auto !important;
      margin: 0 !important;
      padding: 0 !important;
      box-shadow: none !important;
      border: none !important;
      zoom: 1.0 !important;
      transform: none !important;
    }
    .tbl-soal thead {
      display: table-header-group !important;
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

<!-- Browser-style Toolbar -->
<div class="no-print-bar no-print">
  <div class="bar-left">
    <button type="button" class="btn-close-action" onclick="window.close()" title="Tutup">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
      <span>Tutup</span>
    </button>
    <div class="doc-info">
      <span class="doc-title">Lembar Hasil Ujian (<?= $totalSoal ?> Soal)</span>
      <span class="doc-student"><?= htmlspecialchars($detailUjian["nama_siswa"], ENT_QUOTES, "UTF-8") ?></span>
    </div>
  </div>

  <div class="bar-center">
    <div class="zoom-controls">
      <button type="button" class="btn-zoom" onclick="changeZoom(-0.1)" title="Perkecil">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="5" y1="12" x2="19" y2="12"></line></svg>
      </button>
      <span id="zoom-label" class="zoom-label">100%</span>
      <button type="button" class="btn-zoom" onclick="changeZoom(0.1)" title="Perbesar">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
      </button>
      <button type="button" class="btn-zoom-preset" id="btn-zoom-fit" onclick="setZoomPreset('fit')">Fit</button>
      <button type="button" class="btn-zoom-preset active" id="btn-zoom-100" onclick="setZoomPreset(1.0)">100%</button>
    </div>
  </div>

  <div class="bar-right">
    <button type="button" class="btn-print-action" onclick="window.print()" title="Cetak Dokumen atau Simpan sebagai PDF">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
      <span>Cetak / Simpan PDF</span>
    </button>
  </div>
</div>

<!-- Viewport Dokumen (Menampilkan Kertas A4 Otentik Menyambung Mulus) -->
<!-- Viewport Dokumen (Menampilkan Lembaran Kertas A4 Fisik Terpisah) -->
<div class="preview-container" id="printable-area">
  <div class="paper-page" id="paper-document">
    <!-- Kop Resmi Sekolah -->
    <div style="display: flex; align-items: center; justify-content: center; gap: 16px; margin-bottom: 8px;">
      <img src="<?= base_url("assets/img/sdntalun.png") ?>" alt="Logo SDN 1 Talun" style="width: 58px; height: 58px; object-fit: contain;">
      <div style="text-align: center; flex: 1;">
        <div style="font-size: 10pt; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #1e293b; line-height: 1.2;">PEMERINTAH KABUPATEN PONOROGO</div>
        <div style="font-size: 10pt; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #1e293b; line-height: 1.2;">DINAS PENDIDIKAN</div>
        <div style="font-size: 13.5pt; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; color: #0f172a; margin: 1px 0; line-height: 1.2;">SD NEGERI 1 TALUN</div>
        <div style="font-size: 8pt; color: #475569; line-height: 1.2;">Jalan Sukowati No. 23 Desa Talun, Kecamatan Ngebel, Kabupaten Ponorogo, Jawa Timur 63493</div>
      </div>
      <div style="width: 58px;"></div>
    </div>
    <div style="border-bottom: 2px solid #0f172a; border-top: 1px solid #0f172a; height: 2px; margin-bottom: 10px;"></div>
    <div style="text-align: center; font-size: 11pt; font-weight: 800; text-decoration: underline; letter-spacing: 0.5px; text-transform: uppercase; color: #0f172a; margin-bottom: 10px;">LEMBAR HASIL &amp; JAWABAN SISWA (CBT)</div>

    <!-- Tabel Data Siswa -->
    <table class="tbl-info">
      <tr>
        <td style="width: 16%; font-weight: bold;">Nama Siswa</td>
        <td style="width: 2%;">:</td>
        <td style="width: 32%; font-weight: bold;"><?= htmlspecialchars($detailUjian["nama_siswa"], ENT_QUOTES, "UTF-8") ?></td>
        <td style="width: 16%; font-weight: bold;">Mata Pelajaran</td>
        <td style="width: 2%;">:</td>
        <td style="width: 32%; font-weight: bold;"><?= htmlspecialchars((string)($detailUjian["nama_mapel"] ?: "-"), ENT_QUOTES, "UTF-8") ?></td>
      </tr>
      <tr>
        <td style="font-weight: bold;">NIS / Akun</td>
        <td>:</td>
        <td><?= htmlspecialchars((string)($detailUjian["nis"] ?: $detailUjian["username"]), ENT_QUOTES, "UTF-8") ?></td>
        <td style="font-weight: bold;">Nama Ujian</td>
        <td>:</td>
        <td><?= htmlspecialchars($detailUjian["nama_ujian"], ENT_QUOTES, "UTF-8") ?></td>
      </tr>
      <tr>
        <td style="font-weight: bold;">Kelas</td>
        <td>:</td>
        <td><?= htmlspecialchars((string)($detailUjian["nama_kelas"] ?: "-"), ENT_QUOTES, "UTF-8") ?></td>
        <td style="font-weight: bold;">Tanggal Ujian</td>
        <td>:</td>
        <td><?= !empty($detailUjian["waktu_mulai"]) ? date("d/m/Y H:i", strtotime($detailUjian["waktu_mulai"])) : "-" ?></td>
      </tr>
      <tr>
        <td style="font-weight: bold;">No. HP</td>
        <td>:</td>
        <td><?= htmlspecialchars((string)($detailUjian["no_hp_siswa"] ?? "-") ?: "-", ENT_QUOTES, "UTF-8") ?></td>
        <td style="font-weight: bold;">Durasi</td>
        <td>:</td>
        <td><?= htmlspecialchars($durasiLaporan, ENT_QUOTES, "UTF-8") ?></td>
      </tr>
      <tr>
        <td style="font-weight: bold;">Orang Tua</td>
        <td>:</td>
        <td><?= htmlspecialchars((string)($detailUjian["orang_tua"] ?: "-"), ENT_QUOTES, "UTF-8") ?></td>
        <td style="font-weight: bold;">No. HP Ortu</td>
        <td>:</td>
        <td><?= htmlspecialchars((string)($detailUjian["no_hp_ortu"] ?? "-") ?: "-", ENT_QUOTES, "UTF-8") ?></td>
      </tr>
    </table>

    <!-- Ringkasan Nilai & Statistik -->
    <table class="tbl-score">
      <thead>
        <tr>
          <th style="width: 20%;">Total Skor</th>
          <th style="width: 20%;">Nilai Akhir</th>
          <th style="width: 20%;">Benar</th>
          <th style="width: 20%;">Salah</th>
          <th style="width: 20%;">Kosong</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td><strong><?= number_format($totalSkor, 2) ?></strong> / <?= $totalMax ?></td>
          <td style="font-size: 14pt; font-weight: 800; color: #1e3a8a;">
            <?= number_format($nilaiAkhir, 2) ?>
          </td>
          <td style="color: #166534; font-weight: bold;"><?= $statBenar ?></td>
          <td style="color: #dc2626; font-weight: bold;"><?= $statSalah ?></td>
          <td style="color: #d97706; font-weight: bold;"><?= $statKosong ?></td>
        </tr>
      </tbody>
    </table>

    <!-- Tabel Rincian Butir Soal -->
    <table class="tbl-soal">
      <thead>
        <tr>
          <th style="width: 5%;">No</th>
          <th style="width: 14%;">Bentuk Soal</th>
          <th>Pertanyaan</th>
          <th style="width: 25%;">Jawaban Siswa &amp; Kunci</th>
          <th style="width: 14%;">Skor &amp; Status</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($soalList as $s): ?>
          <tr>
            <td style="text-align: center; font-weight: bold;"><?= $s["nomor"] ?></td>
            <td style="text-align: center;">
              <strong><?= htmlspecialchars($s["short_label"], ENT_QUOTES, "UTF-8") ?></strong>
              <div style="font-size: 8pt; color: #555;">(Max: <?= $s["bobot_max"] ?>)</div>
            </td>
            <td>
              <div style="font-weight: 500; margin-bottom: 4px;">
                <?= nl2br(htmlspecialchars(trim(strip_tags($s["pertanyaan"])), ENT_QUOTES, "UTF-8")) ?>
              </div>
            </td>
            <td>
              <div style="margin-bottom: 3px;">
                <span style="font-size: 8.5pt; color: #64748b; font-weight: bold;">Siswa:</span>
                <?php if ($s["jenis_soal"] === "mjdk" && !empty($s["eval"]["detail"]["rows"])): ?>
                  <div class="essay-ans" style="font-size: 8.5pt;">
                    <?php foreach ($s["eval"]["detail"]["rows"] as $r): ?>
                      <div>• <?= sanitize($r["premis"]) ?> ➔ <strong style="color:<?= $r["is_correct"] ? "#166534" : "#dc2626" ?>;"><?= sanitize($r["siswa"]) ?: "(Kosong)" ?></strong></div>
                    <?php endforeach; ?>
                  </div>
                <?php else: ?>
                  <span style="font-weight: bold; color: <?= $s["skor"] > 0 ? "#166534" : "#dc2626" ?>;">
                    <?= htmlspecialchars((string)($s["jawaban_terpilih"] ?: "(Kosong)"), ENT_QUOTES, "UTF-8") ?>
                  </span>
                <?php endif; ?>
              </div>
              <div>
                <span style="font-size: 8.5pt; color: #64748b; font-weight: bold;">Kunci:</span>
                <?php if ($s["jenis_soal"] === "mjdk" && !empty($s["eval"]["detail"]["rows"])): ?>
                  <div class="essay-ans" style="font-size: 8pt; color: #166534;">
                    <?php foreach ($s["eval"]["detail"]["rows"] as $r): ?>
                      <div>• <?= sanitize($r["premis"]) ?> ➔ <?= sanitize($r["kunci"]) ?></div>
                    <?php endforeach; ?>
                  </div>
                <?php else: ?>
                  <span style="font-weight: bold; color: #166534;">
                    <?= htmlspecialchars((string)$s["kunci_jawaban"], ENT_QUOTES, "UTF-8") ?>
                  </span>
                <?php endif; ?>
              </div>
            </td>
            <td style="text-align: center;">
              <div style="font-size: 11pt; font-weight: 800; color: <?= $s["skor"] > 0 ? "#166534" : "#dc2626" ?>;">
                <?= number_format($s["skor"], 2) ?> / <?= $s["bobot_max"] ?>
              </div>
              <div style="font-size: 8pt; font-weight: bold; color: #555;">
                <?= htmlspecialchars($s["status_label"], ENT_QUOTES, "UTF-8") ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <!-- Tanda Tangan Pengesahan (Kepala Sekolah, Orang Tua / Wali, Guru Pengampu) -->
    <table class="signature-box" style="width: 100%; border: none; margin-top: 25px; font-size: 9.5pt; page-break-inside: avoid;">
      <tr>
        <td style="width: 33%; text-align: center; border: none; vertical-align: top;">
          Mengetahui,<br>
          Kepala <?= defined("SEKOLAH_NAMA") ? sanitize(SEKOLAH_NAMA) : "SD Negeri 1 Talun" ?><br><br><br><br><br>
          <strong><u><?= defined("KEPALA_SEKOLAH_NAMA") ? sanitize(KEPALA_SEKOLAH_NAMA) : "MASHURI, S.Pd." ?></u></strong><br>
          <span style="font-size: 8.5pt; color: #475569;">NIP. <?= defined("KEPALA_SEKOLAH_NIP") ? sanitize(KEPALA_SEKOLAH_NIP) : "198511052022211001" ?></span>
        </td>
        <td style="width: 34%; text-align: center; border: none; vertical-align: top;">
          Mengetahui,<br>
          Orang Tua / Wali<br><br><br><br><br>
          <strong><u><?= !empty($detailUjian["orang_tua"]) ? "( " . sanitize($detailUjian["orang_tua"]) . " )" : "( .................................................. )" ?></u></strong><br>
          <span style="font-size: 8.5pt; color: #475569;">&nbsp;</span>
        </td>
        <td style="width: 33%; text-align: center; border: none; vertical-align: top;">
          Talun, <?= date("d/m/Y") ?><br>
          Guru Penguji / Pengampu<br><br><br><br><br>
          <strong><u><?= htmlspecialchars((string)($detailUjian["nama_guru"] ?: "..................................................."), ENT_QUOTES, "UTF-8") ?></u></strong><br>
          <span style="font-size: 8.5pt; color: #475569;">NIP. <?= htmlspecialchars((string)($detailUjian["nip_guru"] ?: "..........................................."), ENT_QUOTES, "UTF-8") ?></span>
        </td>
      </tr>
    </table>
  </div>
</div>


<script>
let currentZoom = 1.0;

function applyZoom(z) {
    const container = document.getElementById("printable-area");
    const label = document.getElementById("zoom-label");
    const btn100 = document.getElementById("btn-zoom-100");
    const fitBtn = document.getElementById("btn-zoom-fit");
    if (!container) return;

    currentZoom = Math.max(0.4, Math.min(2.0, z));

    // Penskalaan halaman menggunakan CSS zoom browser native
    if ("zoom" in container.style) {
        container.style.zoom = currentZoom;
        container.style.transform = "none";
    } else {
        container.style.transform = `scale(${currentZoom})`;
        container.style.transformOrigin = "top center";
    }

    if (label) {
        label.textContent = `${Math.round(currentZoom * 100)}%`;
    }
    if (btn100) {
        btn100.classList.toggle("active", Math.abs(currentZoom - 1.0) < 0.05);
    }
    if (fitBtn) {
        fitBtn.classList.toggle("active", Math.abs(currentZoom - 1.0) >= 0.05);
    }
}

function changeZoom(delta) {
    applyZoom(currentZoom + delta);
}

function setZoomPreset(mode) {
    const container = document.getElementById("printable-area");
    if (mode === "fit") {
        if (window.innerWidth <= 768) {
            applyZoom(1.0);
        } else if (container && container.clientWidth < 840) {
            const fitRatio = Math.max(0.4, (container.clientWidth - 32) / 794);
            applyZoom(fitRatio);
        } else {
            applyZoom(1.0);
        }
    } else {
        applyZoom(1.0);
    }
}

window.addEventListener("DOMContentLoaded", () => {
    applyZoom(1.0);
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
            <?php if (!empty($detailUjian['no_hp_siswa'])): ?>
            <div class="info-row">
                <span class="info-label">No. HP Siswa</span>
                <span class="info-val"><?= sanitize($detailUjian['no_hp_siswa']) ?></span>
            </div>
            <?php endif; ?>
            <?php if (!empty($detailUjian['orang_tua'])): ?>
            <div class="info-row">
                <span class="info-label">Orang Tua / Wali</span>
                <span class="info-val"><?= sanitize($detailUjian['orang_tua']) ?><?= !empty($detailUjian['no_hp_ortu']) ? ' (' . sanitize($detailUjian['no_hp_ortu']) . ')' : '' ?></span>
            </div>
            <?php endif; ?>
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
                <span class="info-label">Mata Pelajaran</span>
                <span class="info-val"><?= sanitize($detailUjian['nama_mapel']) ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Ujian</span>
                <span class="info-val"><?= sanitize($detailUjian['nama_ujian']) ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Durasi</span>
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
                                <span class="badge-status salah"><?= ($s['skor'] < 0) ? 'Salah (Minus)' : 'Salah' ?> (<?= number_format((float)$s['skor'], 2) ?> / <?= $s['bobot_max'] ?>)</span>
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

                    <!-- 2. ISIAN / JAWABAN SINGKAT (IJS - PENILAIAN OTOMATIS) -->
                    <?php elseif ($s['jenis_soal'] === 'ijs'): ?>
                        <div class="essay-box" style="border-left: 4px solid <?= $s['is_correct'] ? '#16a34a' : ($s['jawaban_terpilih'] === '' ? '#94a3b8' : '#dc2626') ?>;">
                            <div style="margin-bottom: 0.85rem;">
                                <div style="font-size:0.82rem;color:#475569;font-weight:700;margin-bottom:0.35rem;display:flex;align-items:center;gap:0.4rem;">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                    <span>Jawaban Siswa:</span>
                                </div>
                                <div style="background:#fff;border:1.5px solid <?= $s['is_correct'] ? '#86efac' : ($s['jawaban_terpilih'] === '' ? '#cbd5e1' : '#fca5a5') ?>;border-radius:6px;padding:0.75rem 1rem;font-size:1rem;font-weight:700;color:#1e293b;line-height:1.6;white-space:pre-wrap;word-break:break-word;min-height:48px;">
                                    <?php if (!empty($s['jawaban_terpilih'])): ?>
                                        <?= sanitize($s['jawaban_terpilih']) ?>
                                    <?php else: ?>
                                        <span style="color:#94a3b8;font-style:italic;font-weight:normal;">(Siswa tidak mengisi jawaban)</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php if (!empty($s['kunci_jawaban'])): ?>
                                <div style="margin-bottom: 0.85rem;">
                                    <div style="font-size:0.8rem;color:#64748b;font-weight:600;margin-bottom:0.25rem;">Kunci Jawaban Acuan Sistem:</div>
                                    <div style="background:#f1f5f9;border:1px dashed #cbd5e1;border-radius:6px;padding:0.6rem 0.85rem;font-size:0.88rem;color:#334155;line-height:1.5;">
                                        <code style="font-size: 0.95rem;"><?= sanitize($s['kunci_jawaban']) ?></code>
                                        <small style="color:#64748b; margin-left:0.5rem;">(Cocok otomatis secara case-insensitive; dipisah tanda | jika ada variasi)</small>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:0.75rem 1rem;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:0.75rem;">
                                <div style="font-size:0.85rem;color:#334155;">
                                    <strong>Evaluasi Otomatis:</strong>
                                    <?php if ($s['jawaban_terpilih'] === ''): ?>
                                        <span class="badge-status kosong" style="margin-left: 0.35rem;">Kosong (0)</span>
                                    <?php elseif ($s['is_correct']): ?>
                                        <span class="badge-status benar" style="margin-left: 0.35rem;">Tepat Sesuai Kunci (Skor: <?= $s['skor'] ?> / <?= $s['bobot_max'] ?>)</span>
                                    <?php else: ?>
                                        <span class="badge-status salah" style="margin-left: 0.35rem;">Tidak Cocok (Skor: 0 / <?= $s['bobot_max'] ?>)</span>
                                    <?php endif; ?>
                                </div>

                                <div style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap;">
                                    <span style="font-size:0.8rem;color:#64748b;">Penyesuaian Nilai (Opsional):</span>
                                    <input type="number" step="0.1" min="0" max="<?= $s['bobot_max'] ?>" name="nilai_soal[<?= $s['id_soal'] ?>]" value="<?= $s['nilai_soal'] !== null ? $s['nilai_soal'] : '' ?>" class="form-control" style="width: 85px; font-size:0.95rem; font-weight:700;" placeholder="<?= $s['skor'] ?>">
                                    <span style="font-size:0.85rem;font-weight:600;color:#64748b;">/ <?= $s['bobot_max'] ?></span>
                                </div>
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
