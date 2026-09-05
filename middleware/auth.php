<?php
/**
 * Middleware Autentikasi & Role-Based Access Control (RBAC)
 */

require_once __DIR__ . '/../config/database.php';

/**
 * Memastikan user sudah terautentikasi dan memiliki role yang diizinkan
 *
 * @param array $allowed_roles Daftar role yang diizinkan: ['operator', 'guru', 'siswa']
 * @return array Data user yang sedang login
 */
function auth_check(array $allowed_roles = []): array {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (empty($_SESSION['user_id']) || empty($_SESSION['role'])) {
        flash_set('danger', 'Sesi Anda telah berakhir. Silakan login kembali.');
        redirect(base_url('login'));
    }

    $db = get_db();
    $stmt = $db->prepare("SELECT id_user, nis, username, nama_lengkap, role, id_kelas, status_login FROM users WHERE id_user = :id");
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user) {
        // User telah dihapus dari basis data
        $_SESSION = [];
        session_destroy();
        flash_set('danger', 'Akun pengguna tidak ditemukan.');
        redirect(base_url('login'));
    }

    // Validasi izin role
    if (!empty($allowed_roles) && !in_array($user['role'], $allowed_roles, true)) {
        flash_set('danger', 'Anda tidak memiliki hak akses ke halaman tersebut.');
        
        // Alihkan ke halaman yang sesuai dengan perannya
        switch ($user['role']) {
            case 'operator':
                redirect(base_url('operator'));
                break;
            case 'guru':
                redirect(base_url('guru'));
                break;
            case 'siswa':
                redirect(base_url('siswa'));
                break;
            default:
                redirect(base_url('login'));
        }
    }

    return $user;
}

/**
 * Mendapatkan data user yang aktif saat ini dari sesi
 *
 * @return array|null
 */
function get_auth_user(): ?array {
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    return [
        'id_user'      => $_SESSION['user_id'],
        'nis'          => $_SESSION['nis'] ?? null,
        'username'     => $_SESSION['username'] ?? '',
        'nama_lengkap' => $_SESSION['nama_lengkap'] ?? '',
        'role'         => $_SESSION['role'] ?? '',
        'id_kelas'     => $_SESSION['id_kelas'] ?? null,
    ];
}
