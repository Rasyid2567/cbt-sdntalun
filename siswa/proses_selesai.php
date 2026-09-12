<?php
/**
 * Modul Finalisasi & Penilaian Otomatis Ujian Siswa
 * Mengakhiri sesi pengerjaan, mengevaluasi seluruh butir soal dengan Scoring Engine resmi SDN Talun,
 * menyimpan nilai per butir soal, dan menghitung total akumulasi skor serta nilai akhir skala 100.
 */

require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../config/scoring.php';

$currentUser = auth_check(['siswa']);
$db = get_db();
$idSiswa = $currentUser['id_user'];

$idUjianSiswa = (int)($_POST['id_ujian_siswa'] ?? $_GET['id'] ?? 0);

if ($idUjianSiswa <= 0) {
    // Ambil sesi pengerjaan yang sedang berjalan, prioritaskan sesi aktif terbaru
    $stmtCari = $db->prepare("
        SELECT us.id_ujian_siswa 
        FROM ujian_siswa us
        JOIN sesi_ujian s ON us.id_sesi = s.id_sesi
        WHERE us.id_siswa = :s AND us.status = 'sedang'
        ORDER BY CASE WHEN s.status = 'aktif' THEN 1 ELSE 2 END, us.id_ujian_siswa DESC 
        LIMIT 1
    ");
    $stmtCari->execute([':s' => $idSiswa]);
    $idUjianSiswa = (int)$stmtCari->fetchColumn();
}

if ($idUjianSiswa <= 0) {
    flash_set('danger', 'Sesi ujian tidak valid.');
    redirect(base_url('siswa?page=konfirmasi'));
}

// 1. Ambil Data Ujian Siswa
$stmtUs = $db->prepare("
    SELECT us.*, s.id_mapel, s.id_paket, s.nama_ujian 
    FROM ujian_siswa us
    JOIN sesi_ujian s ON us.id_sesi = s.id_sesi
    WHERE us.id_ujian_siswa = :us AND us.id_siswa = :siswa
");
$stmtUs->execute([':us' => $idUjianSiswa, ':siswa' => $idSiswa]);
$ujian = $stmtUs->fetch();

if (!$ujian) {
    flash_set('danger', 'Data ujian siswa tidak ditemukan.');
    redirect(base_url('siswa?page=konfirmasi'));
}

// Jika sudah berstatus selesai sebelumnya, langsung arahkan ke halaman hasil
if ($ujian['status'] === 'selesai') {
    redirect(base_url('siswa?page=hasil&id_ujian_siswa=' . $idUjianSiswa));
}

// 2. Evaluasi Jawaban Terhadap Kunci Bank Soal Menggunakan Scoring Engine Resmi
$urutanIds = json_decode($ujian['urutan_soal'], true) ?: [];

if (!empty($ujian['id_paket'])) {
    $stmtAllPaket = $db->prepare("SELECT id_soal FROM bank_soal WHERE id_paket = :p ORDER BY id_soal ASC");
    $stmtAllPaket->execute([':p' => $ujian['id_paket']]);
    $paketSoalIds = $stmtAllPaket->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($paketSoalIds)) {
        if (empty($urutanIds)) {
            $urutanIds = $paketSoalIds;
        } else {
            foreach ($paketSoalIds as $psid) {
                if (!in_array($psid, $urutanIds)) {
                    $urutanIds[] = $psid;
                }
            }
        }
    }
} elseif (empty($urutanIds)) {
    $stmtFallback = $db->prepare("SELECT id_soal FROM bank_soal WHERE id_paket IN (SELECT id_paket FROM paket_soal WHERE id_mapel = :m) ORDER BY id_soal ASC");
    $stmtFallback->execute([':m' => $ujian['id_mapel']]);
    $urutanIds = $stmtFallback->fetchAll(PDO::FETCH_COLUMN);
}

// Pastikan urutan_soal tersimpan sinkron di database jika ada penyesuaian
$db->prepare("UPDATE ujian_siswa SET urutan_soal = :urutan WHERE id_ujian_siswa = :us")
   ->execute([':urutan' => json_encode($urutanIds), ':us' => $idUjianSiswa]);

// Jalankan kalkulator penilaian resmi SDN Talun
$rekap = cbt_hitung_rekap_ujian($idUjianSiswa, $db);

// 3. Finalisasi Status Ujian Siswa Menjadi 'selesai'
$stmtUpdate = $db->prepare("
    UPDATE ujian_siswa 
    SET waktu_selesai = CURRENT_TIMESTAMP,
        sisa_detik    = 0,
        status        = 'selesai'
    WHERE id_ujian_siswa = :us
");
$stmtUpdate->execute([':us' => $idUjianSiswa]);

flash_set('success', 'Ujian Anda telah berhasil dikumpulkan dan diproses oleh sistem.');
redirect(base_url('siswa?page=hasil&id_ujian_siswa=' . $idUjianSiswa));
