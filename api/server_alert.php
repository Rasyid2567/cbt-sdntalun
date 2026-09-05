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


try {
    $db = get_db();

    // Ambil alert terbaru yang ID-nya lebih besar dari last_id
    // dan targetnya adalah 'semua' atau sesuai peran pengguna saat ini
    // serta hanya alert dalam rentang 12 jam terakhir
    $stmt = $db->prepare("
        SELECT id, judul, pesan, target, created_at
        FROM server_alerts
        WHERE id > :last_id
          AND (target = 'semua' OR target = :target)
          AND created_at >= NOW() - INTERVAL '12 HOUR'
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
