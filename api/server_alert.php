<?php
/**
 * API Server Alert Polling Endpoint
 * CBT SDN TALUN
 * 
 * Digunakan oleh klien browser untuk mengecek pesan pop-up broadcast
 * yang dikirimkan oleh Admin Server via terminal/CLI (alert.php).
 */

require_once dirname(__DIR__) . '/config/database.php';

// Nonaktifkan caching browser untuk endpoint polling
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('Content-Type: application/json; charset=utf-8');

$lastId = isset($_GET['last_id']) ? (int)$_GET['last_id'] : 0;
$userRole = $_SESSION['user']['role'] ?? ($_GET['role'] ?? 'semua');
$isInit = !empty($_GET['init']);

try {
    $db = get_db();

    // Jika browser baru pertama kali buka (lastId <= 0 atau mode inisialisasi):
    // Hanya simpan baseline ID alert saat ini, jangan munculkan popup riwayat masa lalu
    if ($isInit || $lastId <= 0) {
        $stmtLatest = $db->query("SELECT id FROM server_alerts ORDER BY id DESC LIMIT 1");
        $latestId = (int)$stmtLatest->fetchColumn();
        
        echo json_encode([
            'success'     => true,
            'has_alert'   => false,
            'baseline_id' => $latestId
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // Ambil alert terbaru yang:
    // 1. ID-nya lebih besar dari last_id
    // 2. Dibuat dalam kurun waktu maksimal 10 menit terakhir (masih aktif, bukan alert basi)
    // 3. Ditujukan untuk 'semua' atau sesuai peran pengguna saat ini
    $stmt = $db->prepare("
        SELECT id, judul, pesan, target, created_at
        FROM server_alerts
        WHERE id > :last_id
          AND created_at >= (NOW() - INTERVAL '10 minutes')
          AND (LOWER(target) = 'semua' OR LOWER(target) = LOWER(:target))
        ORDER BY id DESC
        LIMIT 1
    ");

    $stmt->execute([
        ':last_id' => $lastId,
        ':target'  => $userRole
    ]);

    $alert = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($alert) {
        $waktuFormatted = date('H:i', strtotime($alert['created_at']));
        $tanggalFormatted = date('d/m/Y', strtotime($alert['created_at']));

        echo json_encode([
            'success'   => true,
            'has_alert' => true,
            'alert'     => [
                'id'         => (int)$alert['id'],
                'judul'      => $alert['judul'],
                'pesan'      => $alert['pesan'],
                'target'     => $alert['target'],
                'waktu'      => $waktuFormatted,
                'tanggal'    => $tanggalFormatted,
                'created_at' => $alert['created_at']
            ]
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } else {
        echo json_encode([
            'success'   => true,
            'has_alert' => false
        ]);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success'   => false,
        'has_alert' => false,
        'error'     => 'Database error'
    ]);
}
