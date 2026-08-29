<?php
/**
 * Modul Finalisasi & Penilaian Otomatis Ujian Siswa
 * Mengakhiri sesi pengerjaan, menghitung jumlah benar dan skor akhir secara akurat.
 */

require_once __DIR__ . '/../middleware/auth.php';

$currentUser = auth_check(['siswa']);
$db = get_db();
$idSiswa = $currentUser['id_user'];

$idUjianSiswa = (int)($_POST['id_ujian_siswa'] ?? $_GET['id'] ?? 0);

if ($idUjianSiswa <= 0) {
    // Ambil sesi pengerjaan yang sedang berjalan
    $stmtCari = $db->prepare("SELECT id_ujian_siswa FROM ujian_siswa WHERE id_siswa = :s AND status = 'sedang' LIMIT 1");
    $stmtCari->execute([':s' => $idSiswa]);
    $idUjianSiswa = (int)$stmtCari->fetchColumn();
}

if ($idUjianSiswa <= 0) {
    flash_set('danger', 'Sesi ujian tidak valid.');
    redirect(base_url('siswa/konfirmasi.php'));
}

// 1. Ambil Data Ujian Siswa
$stmtUs = $db->prepare("
    SELECT us.*, s.id_mapel, s.nama_ujian 
    FROM ujian_siswa us
    JOIN sesi_ujian s ON us.id_sesi = s.id_sesi
    WHERE us.id_ujian_siswa = :us AND us.id_siswa = :siswa
");
$stmtUs->execute([':us' => $idUjianSiswa, ':siswa' => $idSiswa]);
$ujian = $stmtUs->fetch();

if (!$ujian) {
    flash_set('danger', 'Data ujian siswa tidak ditemukan.');
    redirect(base_url('siswa/konfirmasi.php'));
}

// Jika sudah berstatus selesai sebelumnya, langsung arahkan ke halaman hasil
if ($ujian['status'] === 'selesai') {
    redirect(base_url('siswa/hasil.php?id_ujian_siswa=' . $idUjianSiswa));
}

// 2. Evaluasi Jawaban Terhadap Kunci Bank Soal
$urutanIds = json_decode($ujian['urutan_soal'], true) ?: [];

if (empty($urutanIds)) {
    // Fallback ambil seluruh soal di mapel ini jika urutan_soal kosong
    $stmtFallback = $db->prepare("SELECT id_soal FROM bank_soal WHERE id_mapel = :m");
    $stmtFallback->execute([':m' => $ujian['id_mapel']]);
    $urutanIds = $stmtFallback->fetchAll(PDO::FETCH_COLUMN);
}

$totalSoal   = count($urutanIds);
$jumlahBenar = 0;

if ($totalSoal > 0) {
    $placeholders = implode(',', array_fill(0, count($urutanIds), '?'));
    
    // Ambil kunci jawaban bank soal
    $stmtKunci = $db->prepare("SELECT id_soal, kunci_jawaban FROM bank_soal WHERE id_soal IN ($placeholders)");
    $stmtKunci->execute($urutanIds);
    $kunciMap = $stmtKunci->fetchAll(PDO::FETCH_KEY_PAIR);

    // Ambil jawaban terpilih siswa
    $stmtJwb = $db->prepare("SELECT id_soal, jawaban_terpilih FROM jawaban_siswa WHERE id_ujian_siswa = ?");
    $stmtJwb->execute([$idUjianSiswa]);
    $jwbMap = $stmtJwb->fetchAll(PDO::FETCH_KEY_PAIR);

    // Hitung jumlah jawaban benar
    foreach ($urutanIds as $sid) {
        $kunci = strtoupper(trim($kunciMap[$sid] ?? ''));
        $jwb   = strtoupper(trim($jwbMap[$sid] ?? ''));

        if ($kunci !== '' && $jwb !== '' && $kunci === $jwb) {
            $jumlahBenar++;
        }
    }
}

// 3. Hitung Nilai Akhir (Skala 0 - 100)
$nilaiAkhir = ($totalSoal > 0) ? round(($jumlahBenar / $totalSoal) * 100, 2) : 0.00;

// 4. Update Log Ujian Siswa Menjadi 'selesai'
$stmtUpdate = $db->prepare("
    UPDATE ujian_siswa 
    SET waktu_selesai = CURRENT_TIMESTAMP,
        sisa_detik = 0,
        status = 'selesai',
        jumlah_benar = :benar,
        nilai_akhir = :nilai
    WHERE id_ujian_siswa = :us
");
$stmtUpdate->execute([
    ':benar' => $jumlahBenar,
    ':nilai' => $nilaiAkhir,
    ':us'    => $idUjianSiswa
]);

flash_set('success', 'Ujian Anda telah berhasil dikumpulkan dan diproses oleh sistem.');
redirect(base_url('siswa/hasil.php?id_ujian_siswa=' . $idUjianSiswa));
