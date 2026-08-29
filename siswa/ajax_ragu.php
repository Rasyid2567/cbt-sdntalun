<?php
/**
 * Endpoint AJAX Update Status Ragu-Ragu Soal
 */

require_once __DIR__ . '/../middleware/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'Metode request tidak diizinkan.'], 405);
}

$user = get_auth_user();
if (!$user || $user['role'] !== 'siswa') {
    json_response(['success' => false, 'message' => 'Akses ditolak.'], 403);
}

$rawInput = file_get_contents('php://input');
$input    = json_decode($rawInput, true);

if (!is_array($input)) {
    $input = $_POST;
}

$csrfToken = $input['csrf_token'] ?? null;
if (!verify_csrf($csrfToken)) {
    json_response(['success' => false, 'message' => 'Token CSRF tidak valid.'], 400);
}

$idUjianSiswa = (int)($input['id_ujian_siswa'] ?? 0);
$idSoal       = (int)($input['id_soal'] ?? 0);
$statusRagu   = !empty($input['status_ragu']) ? 'true' : 'false';

if ($idUjianSiswa <= 0 || $idSoal <= 0) {
    json_response(['success' => false, 'message' => 'Parameter tidak lengkap.'], 422);
}

$db = get_db();

try {
    // Verifikasi sesi
    $stmtCek = $db->prepare("
        SELECT id_ujian_siswa, status 
        FROM ujian_siswa 
        WHERE id_ujian_siswa = :us AND id_siswa = :siswa
    ");
    $stmtCek->execute([':us' => $idUjianSiswa, ':siswa' => $user['id_user']]);
    $ujian = $stmtCek->fetch();

    if (!$ujian || $ujian['status'] !== 'sedang') {
        json_response(['success' => false, 'message' => 'Sesi tidak valid.'], 403);
    }

    // Upsert status ragu-ragu
    $stmtUpsert = $db->prepare("
        INSERT INTO jawaban_siswa (id_ujian_siswa, id_soal, status_ragu, updated_at)
        VALUES (:us, :soal, :ragu::boolean, CURRENT_TIMESTAMP)
        ON CONFLICT (id_ujian_siswa, id_soal) 
        DO UPDATE SET 
            status_ragu = EXCLUDED.status_ragu,
            updated_at = CURRENT_TIMESTAMP
    ");
    $stmtUpsert->execute([
        ':us'   => $idUjianSiswa,
        ':soal' => $idSoal,
        ':ragu' => $statusRagu
    ]);

    json_response(['success' => true, 'message' => 'Status ragu berhasil diperbarui.']);
} catch (Exception $e) {
    json_response(['success' => false, 'message' => 'Terjadi kesalahan basis data.'], 500);
}
