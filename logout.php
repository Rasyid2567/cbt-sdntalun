<?php
/**
 * Script Logout CBT System
 * Mengubah status login menjadi offline dan membersihkan sesi pengguna
 */

require_once __DIR__ . '/config/database.php';

if (!empty($_SESSION['user_id'])) {
    try {
        $db = get_db();
        $stmt = $db->prepare("UPDATE users SET status_login = 'offline' WHERE id_user = :id");
        $stmt->execute([':id' => $_SESSION['user_id']]);
    } catch (Exception $e) {
        // Abaikan error pada logout agar sesi tetap berhasil dibersihkan
    }
}

// Bersihkan seluruh data sesi
$_SESSION = [];

if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

session_destroy();

// Mulai sesi baru untuk pesan flash
session_start();
flash_set('info', 'Anda telah berhasil logout dari sistem.');
redirect(base_url('login'));
