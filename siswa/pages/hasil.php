<?php
/**
 * Page: Halaman Rekap & Bukti Selesai Ujian (Siswa Peserta)
 */

require_once __DIR__ . '/../../middleware/auth.php';

$currentUser = auth_check(['siswa']);
$db = get_db();
$idSiswa = $currentUser['id_user'];

$idUjianSiswa = (int)($_GET['id_ujian_siswa'] ?? 0);

if ($idUjianSiswa <= 0) {
    // Ambil ujian terakhir yang diselesaikan
    $stmtCari = $db->prepare("
        SELECT id_ujian_siswa FROM ujian_siswa 
        WHERE id_siswa = :s AND status = 'selesai' 
        ORDER BY waktu_selesai DESC LIMIT 1
    ");
    $stmtCari->execute([':s' => $idSiswa]);
    $idUjianSiswa = (int)$stmtCari->fetchColumn();
}

if ($idUjianSiswa <= 0) {
    flash_set('info', 'Belum ada rekaman ujian yang selesai.');
    redirect(base_url('siswa?page=konfirmasi'));
}

// Ambil Detail Hasil
$stmtHasil = $db->prepare("
    SELECT us.*, s.nama_ujian, m.nama_mapel, k.nama_kelas, p.nama_paket,
           (SELECT COUNT(*) FROM bank_soal WHERE id_paket = s.id_paket) as total_soal
    FROM ujian_siswa us
    JOIN sesi_ujian s ON us.id_sesi = s.id_sesi
    LEFT JOIN paket_soal p ON s.id_paket = p.id_paket
    JOIN mapel m ON s.id_mapel = m.id_mapel
    JOIN kelas k ON s.id_kelas = k.id_kelas
    WHERE us.id_ujian_siswa = :us AND us.id_siswa = :s
");
$stmtHasil->execute([':us' => $idUjianSiswa, ':s' => $idSiswa]);
$hasil = $stmtHasil->fetch();

if (!$hasil) {
    flash_set('danger', 'Data bukti ujian tidak ditemukan.');
    redirect(base_url('siswa?page=konfirmasi'));
}

$flash = flash_get();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Bukti Penyelesaian Ujian - CBT System</title>
    <link rel="icon" type="image/svg+xml" href="<?= base_url('assets/img/favicon.svg') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/cbt-style.css') ?>">
</head>
<body class="bg-gray-100 flex-center app-webview-body" style="height: 100vh; height: 100dvh; overflow: hidden; display: flex; align-items: center; justify-content: center; margin: 0; padding: 0.75rem; box-sizing: border-box;">

<div style="max-width: 460px; width: 100%; margin: auto;">
    <?php if ($flash): ?>
        <div class="alert alert-<?= sanitize($flash['type']) ?>" style="margin-bottom: 0.75rem; padding: 0.5rem 0.75rem; font-size: 0.82rem;">
            <?= sanitize($flash['message']) ?>
        </div>
    <?php endif; ?>

    <div class="card" style="padding: 1.25rem 1.35rem; border: 1px solid var(--gray-200); border-radius: var(--radius-md); margin: 0; background: var(--white); box-shadow: var(--shadow-sm);">
        <div class="text-center mb-3">
            <div style="width: 46px; height: 46px; background: #dcfce7; color: #166534; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 0.4rem;">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <polyline points="20 6 9 17 4 12"></polyline>
                </svg>
            </div>
            <h1 style="font-size: 1.2rem; font-weight: 800; color: var(--gray-900); margin: 0 0 0.15rem 0;">TES TELAH DISELESAIKAN</h1>
            <p class="text-xs text-muted" style="margin: 0;">Lembar jawaban Anda telah diterima dan tersimpan aman.</p>
        </div>

        <div style="background: #f8fafc; border: 1px solid var(--gray-200); border-radius: 6px; padding: 0.6rem 0.85rem; margin-bottom: 0.85rem;">
            <table style="width: 100%; border-collapse: collapse; font-size: 0.82rem;">
                <tbody>
                    <tr>
                        <td style="padding: 0.2rem 0; font-weight: 600; color: #475569; width: 45%;">NIS</td>
                        <td style="padding: 0.2rem 0;">: <strong style="font-family: monospace; color: #1e40af;"><?= sanitize($currentUser['nis'] ?? '-') ?></strong></td>
                    </tr>
                    <tr>
                        <td style="padding: 0.2rem 0; font-weight: 600; color: #475569;">Nama Peserta</td>
                        <td style="padding: 0.2rem 0;">: <strong><?= sanitize($currentUser['nama_lengkap']) ?></strong></td>
                    </tr>
                    <tr>
                        <td style="padding: 0.2rem 0; font-weight: 600; color: #475569;">Kelas</td>
                        <td style="padding: 0.2rem 0;">: <?= sanitize($hasil['nama_kelas']) ?></td>
                    </tr>
                    <tr>
                        <td style="padding: 0.2rem 0; font-weight: 600; color: #475569;">Nama Ujian</td>
                        <td style="padding: 0.2rem 0;">: <strong><?= sanitize($hasil['nama_ujian']) ?></strong></td>
                    </tr>
                    <tr>
                        <td style="padding: 0.2rem 0; font-weight: 600; color: #475569;">Mata Pelajaran</td>
                        <td style="padding: 0.2rem 0;">: <?= sanitize($hasil['nama_mapel']) ?></td>
                    </tr>
                    <tr>
                        <td style="padding: 0.2rem 0; font-weight: 600; color: #475569;">Waktu Selesai</td>
                        <td style="padding: 0.2rem 0;">: <?= date('d M Y, H:i', strtotime($hasil['waktu_selesai'])) ?> WIB</td>
                    </tr>
                    <tr>
                        <td style="padding: 0.2rem 0; font-weight: 600; color: #475569;">Status</td>
                        <td style="padding: 0.2rem 0;">: <span class="badge badge-online" style="font-size: 0.7rem; padding: 0.15rem 0.45rem;">TERVERIFIKASI</span></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Konfirmasi Penyerahan Ujian (Nilai hanya dapat dilihat Guru) -->
        <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 0.75rem 1rem; text-align: center; margin-bottom: 0.85rem;">
            <div style="font-size: 0.88rem; font-weight: 700; color: #166534; display: flex; align-items: center; justify-content: center; gap: 0.4rem;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                <span>Jawaban Berhasil Diserahkan</span>
            </div>
            <p style="font-size: 0.76rem; color: #15803d; margin: 0.35rem 0 0 0; line-height: 1.4;">
                Seluruh jawaban telah tersimpan. Nilai dan hasil evaluasi ujian akan diperiksa dan direkapitulasi langsung oleh Bapak/Ibu Guru.
            </p>
        </div>

        <div class="flex gap-2" style="justify-content: center;">
            <a href="<?= base_url('siswa?page=konfirmasi') ?>" class="btn btn-outline" style="padding: 0.45rem 1.5rem; font-size: 0.85rem; font-weight: 600;">Kembali</a>
        </div>
    </div>
</div>

<script src="<?= base_url('assets/js/app.js') ?>"></script>
<script src="<?= base_url('assets/js/server-alert.js') ?>"></script>
</body>
</html>
