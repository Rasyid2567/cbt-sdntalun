<?php
/**
 * Halaman Login Universal CBT System
 * Mengarahkan pengguna secara otomatis sesuai Role (Operator, Guru, Siswa)
 */

require_once __DIR__ . '/config/database.php';

// Jika pengguna sudah login, alihkan langsung ke dashboard masing-masing
if (!empty($_SESSION['user_id']) && !empty($_SESSION['role'])) {
    switch ($_SESSION['role']) {
        case 'operator':
            redirect(base_url('operator/dashboard.php'));
            break;
        case 'guru':
            redirect(base_url('guru/dashboard.php'));
            break;
        case 'siswa':
            redirect(base_url('siswa/konfirmasi.php'));
            break;
    }
}

$error = null;

// Proses Form Login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $error = 'Validasi keamanan (CSRF Token) gagal. Silakan muat ulang halaman.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');

        if ($username === '' || $password === '') {
            $error = 'Username dan password wajib diisi!';
        } else {
            $db = get_db();
            $stmt = $db->prepare("SELECT * FROM users WHERE username = :username LIMIT 1");
            $stmt->execute([':username' => $username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                // Khusus Siswa: Proteksi Sesi Ganda (CBT Strict Login)
                if ($user['role'] === 'siswa' && $user['status_login'] === 'online') {
                    $error = 'Akun Anda sedang aktif di perangkat lain. Silakan hubungi Operator/Proktor untuk melakukan Reset Login.';
                } else {
                    // Update status login menjadi 'online'
                    $updateStmt = $db->prepare("UPDATE users SET status_login = 'online' WHERE id_user = :id");
                    $updateStmt->execute([':id' => $user['id_user']]);

                    // Set Sesi Pengguna
                    $_SESSION['user_id']      = $user['id_user'];
                    $_SESSION['nis']          = $user['nis'];
                    $_SESSION['username']     = $user['username'];
                    $_SESSION['nama_lengkap'] = $user['nama_lengkap'];
                    $_SESSION['role']         = $user['role'];
                    $_SESSION['id_kelas']     = $user['id_kelas'];

                    // Regenerate session id untuk mencegah session fixation
                    session_regenerate_id(true);

                    // Alihkan berdasarkan Role
                    if ($user['role'] === 'operator') {
                        redirect(base_url('operator/dashboard.php'));
                    } elseif ($user['role'] === 'guru') {
                        redirect(base_url('guru/dashboard.php'));
                    } else {
                        redirect(base_url('siswa/konfirmasi.php'));
                    }
                }
            } else {
                $error = 'Kombinasi username dan password tidak valid.';
            }
        }
    }
}

$flash = flash_get();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Login - CBT Computer Based Test</title>
    <link rel="stylesheet" href="<?= base_url('assets/css/cbt-style.css') ?>">
</head>
<body class="page-login">

<div class="login-wrapper">
    <div class="card shadow-lg login-card">
        <div class="login-header">
            <div class="logo-circle">
                <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M22 10v6M2 10l10-5 10 5-10 5z"></path>
                    <path d="M6 12v5c3 3 9 3 12 0v-5"></path>
                </svg>
            </div>
            <h1 class="login-title">CBT PORTAL</h1>
            <p class="login-subtitle">Computer Based Test Examination System</p>
        </div>

        <?php if ($flash): ?>
            <div class="alert alert-<?= sanitize($flash['type']) ?>">
                <?= sanitize($flash['message']) ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger">
                <?= sanitize($error) ?>
            </div>
        <?php endif; ?>

        <form action="<?= base_url('index.php') ?>" method="POST" class="form-login">
            <?= csrf_field() ?>
            
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" class="form-control" placeholder="Masukkan username..." required autofocus autocomplete="username">
            </div>

            <div class="form-group">
                <label for="password">Kata Sandi</label>
                <input type="password" id="password" name="password" class="form-control" placeholder="Masukkan kata sandi..." required autocomplete="current-password">
            </div>

            <button type="submit" class="btn btn-primary btn-block btn-lg mt-3" style="min-height: 48px;">
                MASUK SEKARANG
            </button>
        </form>
    </div>
</div>

</body>
</html>
