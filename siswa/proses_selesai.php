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
    SELECT us.*, s.id_mapel, s.id_paket, s.nama_ujian 
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
    // Fallback ambil seluruh soal di paket/mapel ini jika urutan_soal kosong
    if (!empty($ujian['id_paket'])) {
        $stmtFallback = $db->prepare("SELECT id_soal FROM bank_soal WHERE id_paket = :p");
        $stmtFallback->execute([':p' => $ujian['id_paket']]);
    } else {
        $stmtFallback = $db->prepare("SELECT id_soal FROM bank_soal WHERE id_paket IN (SELECT id_paket FROM paket_soal WHERE id_mapel = :m)");
        $stmtFallback->execute([':m' => $ujian['id_mapel']]);
    }
    $urutanIds = $stmtFallback->fetchAll(PDO::FETCH_COLUMN);
}

$totalSoal   = count($urutanIds);
$jumlahBenar = 0;

if ($totalSoal > 0) {
    $placeholders = implode(',', array_fill(0, count($urutanIds), '?'));
    
    // Ambil kunci jawaban bank soal
    $stmtKunci = $db->prepare("SELECT id_soal, jenis_soal, kunci_jawaban FROM bank_soal WHERE id_soal IN ($placeholders)");
    $stmtKunci->execute($urutanIds);
    $soalRows = $stmtKunci->fetchAll();

    $kunciMap = [];
    $jenisMap = [];
    foreach ($soalRows as $sr) {
        $kunciMap[$sr['id_soal']] = $sr['kunci_jawaban'];
        $jenisMap[$sr['id_soal']] = $sr['jenis_soal'] ?? 'pilihan_ganda';
    }

    // Ambil jawaban terpilih siswa
    $stmtJwb = $db->prepare("SELECT id_soal, jawaban_terpilih FROM jawaban_siswa WHERE id_ujian_siswa = ?");
    $stmtJwb->execute([$idUjianSiswa]);
    $jwbMap = $stmtJwb->fetchAll(PDO::FETCH_KEY_PAIR);

    $totalPG = 0;

    // Hitung jumlah jawaban benar untuk pilihan ganda
    foreach ($urutanIds as $sid) {
        $jenis = $jenisMap[$sid] ?? 'pilihan_ganda';

        if ($jenis === 'pilihan_ganda') {
            $totalPG++;
            $kunciStr = strtoupper(trim($kunciMap[$sid] ?? ''));
            $jwbStr   = strtoupper(trim($jwbMap[$sid] ?? ''));

            if ($kunciStr !== '' && $jwbStr !== '') {
                $kunciArr = array_filter(array_map('trim', explode(',', $kunciStr)));
                $jwbArr   = array_filter(array_map('trim', explode(',', $jwbStr)));
                sort($kunciArr);
                sort($jwbArr);
                if ($kunciArr === $jwbArr || in_array($jwbStr, $kunciArr, true)) {
                    $jumlahBenar++;
                }
            }
        }
    }
}

// 3. Hitung Nilai Akhir Otomatis dari Pilihan Ganda (Skala 0 - 100)
// Soal Uraian / Essai diisi di halaman ujian CBT dan dinilai secara manual oleh Guru di menu Rekap Nilai
$nilaiPG = ($totalPG > 0) ? round(($jumlahBenar / $totalPG) * 100, 2) : 0.00;
$nilaiAkhir = $nilaiPG;

// 4. Update Log Ujian Siswa Menjadi 'selesai'
$stmtUpdate = $db->prepare("
    UPDATE ujian_siswa 
    SET waktu_selesai = CURRENT_TIMESTAMP,
        sisa_detik = 0,
        status = 'selesai',
        jumlah_benar = :benar,
        nilai_pg = :nilai_pg,
        nilai_akhir = :nilai
    WHERE id_ujian_siswa = :us
");
$stmtUpdate->execute([
    ':benar'    => $jumlahBenar,
    ':nilai_pg' => $nilaiPG,
    ':nilai'    => $nilaiAkhir,
    ':us'       => $idUjianSiswa
]);

flash_set('success', 'Ujian Anda telah berhasil dikumpulkan dan diproses oleh sistem.');
redirect(base_url('siswa/hasil.php?id_ujian_siswa=' . $idUjianSiswa));
