<?php
/**
 * Endpoint Background Heartbeat / Timer Sync CBT Siswa
 * Digunakan untuk sinkronisasi waktu pengerjaan secara berkala (real-time)
 * dengan waktu server ketika guru melakukan penambahan waktu sesi ujian.
 */

require_once __DIR__ . '/../middleware/auth.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('Content-Type: application/json; charset=utf-8');

$currentUser = get_auth_user();
if (!$currentUser || ($currentUser['role'] ?? '') !== 'siswa') {
    json_response(['success' => false, 'message' => 'Akses ditolak.'], 403);
}

$idUjianSiswa = (int)($_GET['id_ujian_siswa'] ?? $_POST['id_ujian_siswa'] ?? 0);
if ($idUjianSiswa <= 0) {
    json_response(['success' => false, 'message' => 'Parameter tidak valid.'], 400);
}

try {
    $db = get_db();

    $stmt = $db->prepare("
        SELECT us.id_ujian_siswa, us.status as status_ujian, us.sisa_detik,
               s.id_sesi, s.status as status_sesi, s.durasi_menit,
               GREATEST(0, FLOOR(EXTRACT(EPOCH FROM (s.created_at + (s.durasi_menit * INTERVAL '1 minute') - CURRENT_TIMESTAMP))))::int as sisa_detik_real
        FROM ujian_siswa us
        JOIN sesi_ujian s ON us.id_sesi = s.id_sesi
        WHERE us.id_ujian_siswa = :id AND us.id_siswa = :siswa
        LIMIT 1
    ");
    $stmt->execute([
        ':id'    => $idUjianSiswa,
        ':siswa' => $currentUser['id_user']
    ]);
    $ujian = $stmt->fetch();

    if (!$ujian) {
        json_response(['success' => false, 'message' => 'Data ujian siswa tidak ditemukan.'], 404);
    }

    $sisaReal = (int)$ujian['sisa_detik_real'];

    // Update sisa_detik di DB server jika status masih sedang
    if ($ujian['status_ujian'] === 'sedang' && $ujian['status_sesi'] === 'aktif') {
        $upd = $db->prepare("UPDATE ujian_siswa SET sisa_detik = :s WHERE id_ujian_siswa = :id");
        $upd->execute([':s' => $sisaReal, ':id' => $idUjianSiswa]);
    }

    json_response([
        'success'      => true,
        'sisa_detik'   => $sisaReal,
        'status_sesi'  => $ujian['status_sesi'],
        'status_ujian' => $ujian['status_ujian'],
        'durasi_menit' => (int)$ujian['durasi_menit']
    ]);

} catch (Throwable $e) {
    json_response([
        'success' => false,
        'message' => 'Terjadi kesalahan sistem: ' . $e->getMessage()
    ], 500);
}
