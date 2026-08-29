<?php
/**
 * Konfigurasi Database & Helper Umum CBT System
 * Driver: PostgreSQL via PDO (PHP 8.x+)
 */

// Pastikan sesi aktif dengan parameter aman
if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'use_strict_mode' => true,
    ]);
}

// Konfigurasi Kredensial Database PostgreSQL
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('DB_PORT') ?: '5432');
define('DB_NAME', getenv('DB_NAME') ?: 'cbt_sdntalun');
define('DB_USER', getenv('DB_USER') ?: 'postgres');
define('DB_PASS', getenv('DB_PASS') ?: 'postgres');

/**
 * Mendapatkan instance koneksi PDO PostgreSQL (Singleton Pattern)
 * Mendukung deteksi otomatis host lokal & host Docker (172.17.0.1 / host.docker.internal)
 *
 * @return PDO
 */
function get_db(): PDO {
    static $pdo = null;

    if ($pdo === null) {
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        // Daftar kandidat host (Docker bridge, Docker internal, dan localhost)
        $hostsToTry = array_unique([
            DB_HOST,
            'host.docker.internal',
            '172.17.0.1',
            '127.0.0.1',
            'localhost'
        ]);

        $dbNamesToTry = array_unique([
            DB_NAME,
            'cbt_sdntalun',
            'cbt_db'
        ]);

        $lastException = null;

        foreach ($hostsToTry as $host) {
            foreach ($dbNamesToTry as $dbName) {
                try {
                    $dsn = sprintf(
                        "pgsql:host=%s;port=%s;dbname=%s;options='--client_encoding=UTF8'",
                        $host,
                        DB_PORT,
                        $dbName
                    );
                    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
                    break 2; // Berhasil terhubung
                } catch (PDOException $e) {
                    $lastException = $e;
                }
            }
        }

        if ($pdo === null && $lastException !== null) {
            die("Koneksi database PostgreSQL gagal: " . htmlspecialchars($lastException->getMessage()) . "<br><small>Pastikan service PostgreSQL aktif dan database '" . htmlspecialchars(DB_NAME) . "' dapat diakses.</small>");
        }

        // Auto-migration & Self-Healing: Pastikan kolom baru otomatis dibuat jika menggunakan DB versi lama
        try {
            $pdo->exec("
                ALTER TABLE bank_soal ADD COLUMN IF NOT EXISTS jenis_soal VARCHAR(20) DEFAULT 'pilihan_ganda';
                ALTER TABLE bank_soal ADD COLUMN IF NOT EXISTS judul_soal VARCHAR(150) DEFAULT 'Latihan Soal';
                ALTER TABLE sesi_ujian ADD COLUMN IF NOT EXISTS judul_soal VARCHAR(150) NULL;
                ALTER TABLE sesi_ujian ALTER COLUMN token_ujian TYPE VARCHAR(20);
            ");
        } catch (Throwable $e) {
            // Abaikan jika tabel belum diinisialisasi
        }
    }

    return $pdo;
}

/**
 * Menghasilkan CSRF Token yang aman
 *
 * @return string
 */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Menghasilkan input hidden untuk CSRF Token
 *
 * @return string
 */
function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

/**
 * Memvalidasi CSRF Token dari input POST atau header request
 *
 * @param string|null $token
 * @return bool
 */
function verify_csrf(?string $token = null): bool {
    if ($token === null) {
        $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    }
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Sanitasi string output untuk mencegah Cross-Site Scripting (XSS)
 *
 * @param mixed $data
 * @return string
 */
function sanitize($data): string {
    return htmlspecialchars((string)($data ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Fungsi redirect URL yang aman
 *
 * @param string $url
 * @return void
 */
function redirect(string $url): void {
    header("Location: " . $url);
    exit;
}

/**
 * Menyimpan pesan flash untuk alert UI
 *
 * @param string $type ('success', 'danger', 'warning', 'info')
 * @param string $message
 * @return void
 */
function flash_set(string $type, string $message): void {
    $_SESSION['flash'] = [
        'type'    => $type,
        'message' => $message,
    ];
}

/**
 * Mengambil dan menghapus pesan flash dari sesi
 *
 * @return array|null
 */
function flash_get(): ?array {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

/**
 * Mengirimkan respon JSON standar untuk AJAX / Fetch API
 *
 * @param array $data
 * @param int $statusCode
 * @return void
 */
function json_response(array $data, int $statusCode = 200): void {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Helper untuk menentukan base URL root aplikasi
 *
 * @param string $path
 * @return string
 */
function base_url(string $path = ''): string {
    // Tentukan direktori root relatif terhadap letak dokumen
    $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
    // Normalisasi jika berada di subfolder (misal /operator atau /guru)
    $scriptDir = preg_replace('/(\/operator|\/guru|\/siswa|\/config)$/', '', $scriptDir);
    $scriptDir = rtrim($scriptDir, '/\\');
    return ($scriptDir === '' ? '' : $scriptDir) . '/' . ltrim($path, '/');
}
