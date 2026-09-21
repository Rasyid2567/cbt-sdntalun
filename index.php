<?php
/**
 * Halaman Login Universal CBT System
 * Mengarahkan pengguna secara otomatis sesuai Role (Operator, Guru, Siswa)
 */

require_once __DIR__ . '/config/database.php';

// Fallback routing untuk request clean URL / file langsung (mendukung Apache & dev server php -S)
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$fileBase = basename($requestPath);
if ($fileBase !== 'index' && $fileBase !== '' && file_exists(__DIR__ . '/' . $fileBase . '.php')) {
    require __DIR__ . '/' . $fileBase . '.php';
    exit;
}

// Jika pengguna sudah login, alihkan langsung ke dashboard masing-masing
if (!empty($_SESSION['user_id']) && !empty($_SESSION['role'])) {
    switch ($_SESSION['role']) {
        case 'operator':
            redirect(base_url('operator'));
            break;
        case 'guru':
            redirect(base_url('guru'));
            break;
        case 'siswa':
            redirect(base_url('siswa'));
            break;
    }
}

$error = null;
$accountDisabled = false;
if (!empty($_SESSION["account_disabled"])) {
    $accountDisabled = true;
    unset($_SESSION["account_disabled"]);
}

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
                // Pengecekan Status Akun Aktif / Nonaktif
                if (isset($user['status_akun']) && $user['status_akun'] === 'nonaktif') {
                    $accountDisabled = true;
                    $error = 'Akun anda telah di nonaktifkan. silahkan hubungi operator';
                }
                // Khusus Siswa: Proteksi Sesi Ganda (CBT Strict Login)
                elseif ($user['role'] === 'siswa' && $user['status_login'] === 'online') {
                    $error = 'Akun Anda sedang aktif di perangkat lain. Silakan hubungi Operator/Proktor untuk melakukan Reset Login.';
                } else {
                    // Update status login menjadi 'online'
                    $updateStmt = $db->prepare("UPDATE users SET status_login = 'online' WHERE id_user = :id");
                    $updateStmt->execute([':id' => $user['id_user']]);

                    // Set Sesi Pengguna
                    $_SESSION['user_id']      = $user['id_user'];
                    $_SESSION['nis']          = $user['nis'];
                    $_SESSION['nip']          = $user['nip'] ?? null;
                    $_SESSION['username']     = $user['username'];
                    $_SESSION['nama_lengkap'] = $user['nama_lengkap'];
                    $_SESSION['role']         = $user['role'];
                    $_SESSION['id_kelas']     = $user['id_kelas'];
                    $_SESSION['id_mapel']     = $user['id_mapel'] ?? null;
                    $_SESSION['no_hp']        = $user['no_hp'] ?? null;
                    $_SESSION['orang_tua']    = $user['orang_tua'] ?? null;
                    $_SESSION['no_hp_ortu']   = $user['no_hp_ortu'] ?? null;

                    // Regenerate session id untuk mencegah session fixation
                    session_regenerate_id(true);

                    // Alihkan berdasarkan Role
                    if ($user['role'] === 'operator') {
                        redirect(base_url('operator'));
                    } elseif ($user['role'] === 'guru') {
                        redirect(base_url('guru'));
                    } else {
                        redirect(base_url('siswa'));
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
    <link rel="icon" type="image/png" href="<?= base_url('assets/img/sdntalun.png') ?>">
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

        <form action="<?= base_url('login') ?>" method="POST" class="form-login">
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

<script src="<?= base_url('assets/js/app.js') ?>"></script>
<script src="<?= base_url('assets/js/server-alert.js') ?>"></script>

<!-- Popup Modal Peringatan Akun Dinonaktifkan -->
<?php if (!empty($accountDisabled)): ?>
<div id="modal-akun-nonaktif" class="modal-overlay active" style="z-index: 99999;">
    <div class="modal-box" style="max-width: 440px; text-align: center; border-radius: 16px; padding: 2rem 1.75rem; box-shadow: var(--shadow-lg); background: #ffffff; border: 1px solid var(--gray-200);">
        <div style="width: 60px; height: 60px; border-radius: 50%; background: #fee2e2; color: #dc2626; display: flex; align-items: center; justify-content: center; margin: 0 auto 1.25rem; box-shadow: 0 0 0 6px #fef2f2;">
            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"></circle>
                <line x1="12" y1="8" x2="12" y2="12"></line>
                <line x1="12" y1="16" x2="12.01" y2="16"></line>
            </svg>
        </div>
        <h2 style="font-size: 1.25rem; font-weight: 800; color: #0f172a; margin: 0 0 0.5rem; letter-spacing: -0.01em;">Peringatan Masuk</h2>
        <div style="background: #fff1f2; border: 1px solid #fecdd3; border-radius: 8px; padding: 0.85rem 1rem; margin-bottom: 1.5rem;">
            <p style="font-size: 0.92rem; color: #9f1239; margin: 0; line-height: 1.5; font-weight: 700;">
                Akun anda telah di nonaktifkan. silahkan hubungi operator
            </p>
        </div>
        <button type="button" class="btn btn-primary btn-block" onclick="document.getElementById('modal-akun-nonaktif').classList.remove('active')" style="padding: 0.75rem 1.5rem; font-weight: 700; font-size: 0.95rem; border-radius: 8px;">
            Mengerti / Tutup
        </button>
    </div>
</div>
<?php endif; ?>

</body>
</html>
