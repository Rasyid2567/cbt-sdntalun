<?php
/**
 * Konfigurasi Database & Helper Umum CBT System
 * Driver: PostgreSQL via PDO (PHP 8.x+)
 */

// Aktifkan output buffering untuk mencegah error header/redirect
if (ob_get_level() === 0) {
    ob_start();
}

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
define('DB_PASS', getenv('DB_PASS') ?: 'ngebel1234');

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

        // Auto-migration & Self-Healing: Pastikan struktur paket_soal dan kolom baru otomatis dibuat jika menggunakan DB versi lama
        try {
            $pdo->exec("
                -- 1. Buat tabel paket_soal jika belum ada
                CREATE TABLE IF NOT EXISTS paket_soal (
                    id_paket SERIAL PRIMARY KEY,
                    id_guru INT NOT NULL REFERENCES users(id_user) ON DELETE CASCADE,
                    id_mapel INT NOT NULL REFERENCES mapel(id_mapel) ON DELETE CASCADE,
                    nama_paket VARCHAR(150) NOT NULL,
                    deskripsi TEXT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                );

                -- 2. Tambah kolom id_paket di bank_soal dan sesi_ujian
                ALTER TABLE bank_soal ADD COLUMN IF NOT EXISTS id_paket INT REFERENCES paket_soal(id_paket) ON DELETE CASCADE;
                ALTER TABLE sesi_ujian ADD COLUMN IF NOT EXISTS id_paket INT REFERENCES paket_soal(id_paket) ON DELETE SET NULL;
                ALTER TABLE sesi_ujian ALTER COLUMN token_ujian TYPE VARCHAR(20);
                ALTER TABLE jawaban_siswa ADD COLUMN IF NOT EXISTS nilai_soal NUMERIC(5,2) DEFAULT NULL;
                ALTER TABLE ujian_siswa ADD COLUMN IF NOT EXISTS nilai_pg NUMERIC(5,2) DEFAULT NULL;
                ALTER TABLE ujian_siswa ADD COLUMN IF NOT EXISTS nilai_essai NUMERIC(5,2) DEFAULT NULL;

                -- 3. Migrasi data lama dari bank_soal ke paket_soal jika kolom judul_soal masih ada
                DO \$\$
                BEGIN
                    IF EXISTS (
                        SELECT 1 FROM information_schema.columns 
                        WHERE table_name = 'bank_soal' AND column_name = 'judul_soal'
                    ) THEN
                        -- Masukkan paket yang belum ada di paket_soal
                        INSERT INTO paket_soal (id_guru, id_mapel, nama_paket, created_at)
                        SELECT b.id_guru, b.id_mapel, COALESCE(NULLIF(TRIM(b.judul_soal), ''), 'Latihan Soal'), MIN(b.created_at)
                        FROM bank_soal b
                        WHERE NOT EXISTS (
                            SELECT 1 FROM paket_soal p 
                            WHERE p.id_guru = b.id_guru 
                              AND p.id_mapel = b.id_mapel 
                              AND p.nama_paket = COALESCE(NULLIF(TRIM(b.judul_soal), ''), 'Latihan Soal')
                        )
                        GROUP BY b.id_guru, b.id_mapel, COALESCE(NULLIF(TRIM(b.judul_soal), ''), 'Latihan Soal');

                        -- Hubungkan bank_soal yang id_paket nya masih NULL
                        UPDATE bank_soal b
                        SET id_paket = p.id_paket
                        FROM paket_soal p
                        WHERE b.id_paket IS NULL
                          AND b.id_guru = p.id_guru 
                          AND b.id_mapel = p.id_mapel 
                          AND COALESCE(NULLIF(TRIM(b.judul_soal), ''), 'Latihan Soal') = p.nama_paket;
                    END IF;

                    -- Hubungkan sesi_ujian yang id_paket nya masih NULL
                    IF EXISTS (
                        SELECT 1 FROM information_schema.columns 
                        WHERE table_name = 'sesi_ujian' AND column_name = 'judul_soal'
                    ) THEN
                        UPDATE sesi_ujian s
                        SET id_paket = p.id_paket
                        FROM paket_soal p
                        WHERE s.id_paket IS NULL 
                          AND s.judul_soal IS NOT NULL
                          AND s.id_guru = p.id_guru 
                          AND s.id_mapel = p.id_mapel 
                          AND s.judul_soal = p.nama_paket;
                    END IF;
                END \$\$;

                -- 4. Hapus kolom lama yang sudah tidak digunakan (Bersihkan redundansi)
                ALTER TABLE bank_soal DROP COLUMN IF EXISTS judul_soal CASCADE;
                ALTER TABLE bank_soal DROP COLUMN IF EXISTS id_guru CASCADE;
                ALTER TABLE bank_soal DROP COLUMN IF EXISTS id_mapel CASCADE;
                ALTER TABLE sesi_ujian DROP COLUMN IF EXISTS judul_soal CASCADE;

                -- 5. Indeks optimasi performa
                CREATE INDEX IF NOT EXISTS idx_paket_soal_guru ON paket_soal(id_guru);
                CREATE INDEX IF NOT EXISTS idx_paket_soal_mapel ON paket_soal(id_mapel);
                CREATE INDEX IF NOT EXISTS idx_bank_soal_paket ON bank_soal(id_paket);
                CREATE INDEX IF NOT EXISTS idx_sesi_ujian_paket ON sesi_ujian(id_paket);

                -- 6. Tabel Alert Server CLI
                CREATE TABLE IF NOT EXISTS server_alerts (
                    id SERIAL PRIMARY KEY,
                    judul VARCHAR(150) DEFAULT 'Pemberitahuan Admin Server',
                    pesan TEXT NOT NULL,
                    target VARCHAR(50) DEFAULT 'semua',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                );
                CREATE INDEX IF NOT EXISTS idx_server_alerts_id ON server_alerts(id);
            ");
        } catch (Throwable $e) {
            // Abaikan jika tabel belum diinisialisasi
        }

        // Pastikan folder uploads selalu ada dan memiliki izin akses
        $uploadsDir = dirname(__DIR__) . '/assets/uploads';
        if (!is_dir($uploadsDir)) {
            @mkdir($uploadsDir, 0777, true);
            @chmod($uploadsDir, 0777);
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

    $cleanPath = ltrim($path, '/');
    // Normalisasi direktori modul agar selalu memiliki trailing slash sebelum query string (mencegah 301 redirect POST dari Apache)
    $cleanPath = preg_replace('/^(operator|guru|siswa)(\?|$)/', '$1/$2', $cleanPath);

    // Jika path menuju file .php (dan bukan asset statis css/js/gambar), buang ekstensi .php
    if (str_ends_with($cleanPath, '.php')) {
        $cleanPath = substr($cleanPath, 0, -4);
        if ($cleanPath === 'index') {
            $cleanPath = 'login';
        }
    } elseif (str_ends_with($cleanPath, '.css') || str_ends_with($cleanPath, '.js')) {
        // Auto Cache-Busting: Tambahkan timestamp versi agar Tunnel/CDN & Browser langsung memuat update terbaru
        $localFilePath = dirname(__DIR__) . '/' . $cleanPath;
        if (file_exists($localFilePath)) {
            $cleanPath .= '?v=' . filemtime($localFilePath);
        }
    }

    return ($scriptDir === '' ? '' : $scriptDir) . '/' . $cleanPath;
}
