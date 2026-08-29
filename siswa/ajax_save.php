<?php
/**
 * Endpoint AJAX Auto-Save Jawaban Siswa
 * Menggunakan Prepared Statements & PostgreSQL Upsert (ON CONFLICT)
 */

require_once __DIR__ . '/../middleware/auth.php';

// Pastikan request adalah method POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'Metode request tidak diizinkan.'], 405);
}

// Cek autentikasi siswa
$user = get_auth_user();
if (!$user || $user['role'] !== 'siswa') {
    json_response(['success' => false, 'message' => 'Akses ditolak.'], 403);
}

// Baca Payload JSON atau Form POST
$rawInput = file_get_contents('php://input');
$input    = json_decode($rawInput, true);

if (!is_array($input)) {
    $input = $_POST;
}

// Validasi Token Keamanan CSRF
$csrfToken = $input['csrf_token'] ?? null;
if (!verify_csrf($csrfToken)) {
    json_response(['success' => false, 'message' => 'Token CSRF tidak valid atau kedaluwarsa.'], 400);
}

$idUjianSiswa    = (int)($input['id_ujian_siswa'] ?? 0);
$idSoal          = (int)($input['id_soal'] ?? 0);
$jawabanTerpilih = trim($input['jawaban_terpilih'] ?? '');
$sisaDetik       = isset($input['sisa_detik']) ? (int)$input['sisa_detik'] : null;

if ($idUjianSiswa <= 0 || $idSoal <= 0) {
    json_response(['success' => false, 'message' => 'Data parameter tidak lengkap.'], 422);
}

$db = get_db();

try {
    // 1. Verifikasi Kepemilikan Sesi Ujian Siswa
    $stmtCek = $db->prepare("
        SELECT id_ujian_siswa, status, sisa_detik 
        FROM ujian_siswa 
        WHERE id_ujian_siswa = :us AND id_siswa = :siswa
    ");
    $stmtCek->execute([':us' => $idUjianSiswa, ':siswa' => $user['id_user']]);
    $ujian = $stmtCek->fetch();

    if (!$ujian) {
        json_response(['success' => false, 'message' => 'Sesi pengerjaan tidak ditemukan.'], 404);
    }

    if ($ujian['status'] !== 'sedang') {
        json_response(['success' => false, 'message' => 'Sesi ujian ini telah ditutup atau selesai.'], 403);
    }

    // 2. Upsert Jawaban Siswa ke Tabel jawaban_siswa
    $stmtUpsert = $db->prepare("
        INSERT INTO jawaban_siswa (id_ujian_siswa, id_soal, jawaban_terpilih, updated_at)
        VALUES (:us, :soal, :jwb, CURRENT_TIMESTAMP)
        ON CONFLICT (id_ujian_siswa, id_soal) 
        DO UPDATE SET 
            jawaban_terpilih = EXCLUDED.jawaban_terpilih,
            updated_at = CURRENT_TIMESTAMP
    ");
    $stmtUpsert->execute([
        ':us'   => $idUjianSiswa,
        ':soal' => $idSoal,
        ':jwb'  => $jawabanTerpilih
    ]);

    // 3. Perbarui Sisa Detik Terkini di Server
    if ($sisaDetik !== null && $sisaDetik >= 0) {
        $stmtWaktu = $db->prepare("UPDATE ujian_siswa SET sisa_detik = :sisa WHERE id_ujian_siswa = :us");
        $stmtWaktu->execute([':sisa' => $sisaDetik, ':us' => $idUjianSiswa]);
    }

    json_response([
        'success' => true,
        'message' => 'Jawaban berhasil disimpan otomatis.',
        'data' => [
            'id_soal' => $idSoal,
            'jawaban' => $jawabanTerpilih
        ]
    ]);

} catch (Exception $e) {
    json_response([
        'success' => false,
        'message' => 'Terjadi kesalahan sistem: ' . $e->getMessage()
    ], 500);
}
