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

    // Pisahkan query string dan hash jika ada agar tidak mengganggu pembersihan ekstensi .php
    $query = '';
    if (($pos = strpos($cleanPath, '?')) !== false) {
        $query = substr($cleanPath, $pos);
        $cleanPath = substr($cleanPath, 0, $pos);
    } elseif (($pos = strpos($cleanPath, '#')) !== false) {
        $query = substr($cleanPath, $pos);
        $cleanPath = substr($cleanPath, 0, $pos);
    }

    // Normalisasi alias modul dashboard (misal guru/dashboard.php atau guru/dashboard -> guru)
    $cleanPath = preg_replace('/^(operator|guru|siswa)\/dashboard(\.php)?$/', '$1', $cleanPath);

    // Normalisasi direktori modul utama agar selalu memiliki trailing slash (mencegah 301 redirect POST dari Apache)
    $cleanPath = preg_replace('/^(operator|guru|siswa)$/', '$1/', $cleanPath);

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
            $query = ($query === '' ? '?' : $query . '&') . 'v=' . filemtime($localFilePath);
        }
    }

    return ($scriptDir === '' ? '' : $scriptDir) . '/' . $cleanPath . $query;
}

/**
 * Helper Menambah Durasi Waktu Sesi Ujian (Custom Menit)
 * Sinkronisasi otomatis ke durasi_menit sesi dan sisa_detik peserta aktif.
 *
 * @param int $idSesi
 * @param int $tambahMenit
 * @param int|null $idGuru (Opsional: batasi kepemilikan guru)
 * @return array
 */
function tambah_durasi_sesi(int $idSesi, int $tambahMenit, ?int $idGuru = null): array {
    if ($idSesi <= 0 || $tambahMenit <= 0) {
        return ['success' => false, 'message' => 'Parameter sesi atau penambahan waktu tidak valid.'];
    }

    $db = get_db();

    // Verifikasi sesi
    $sqlCek = "SELECT id_sesi, nama_ujian, durasi_menit, status, created_at FROM sesi_ujian WHERE id_sesi = :id";
    $paramsCek = [':id' => $idSesi];
    if ($idGuru !== null) {
        $sqlCek .= " AND id_guru = :g";
        $paramsCek[':g'] = $idGuru;
    }
    $stmtCek = $db->prepare($sqlCek);
    $stmtCek->execute($paramsCek);
    $sesi = $stmtCek->fetch();

    if (!$sesi) {
        return ['success' => false, 'message' => 'Sesi ujian tidak ditemukan atau bukan milik Anda.'];
    }

    // Perbarui durasi_menit & created_at (jika sesi stale > 1 hari)
    $sqlUpd = "
        UPDATE sesi_ujian
        SET 
            created_at = CASE 
                WHEN (CURRENT_TIMESTAMP - created_at) > INTERVAL '1 day' 
                    THEN CURRENT_TIMESTAMP 
                ELSE created_at 
            END,
            durasi_menit = CASE 
                WHEN (CURRENT_TIMESTAMP - created_at) > INTERVAL '1 day' 
                    THEN :tambah
                WHEN (created_at + (durasi_menit * INTERVAL '1 minute')) >= CURRENT_TIMESTAMP 
                    THEN durasi_menit + :tambah
                ELSE 
                    CEIL(EXTRACT(EPOCH FROM (CURRENT_TIMESTAMP - created_at)) / 60.0)::int + :tambah
            END
        WHERE id_sesi = :id
    ";
    if ($idGuru !== null) {
        $sqlUpd .= " AND id_guru = :g";
    }
    $sqlUpd .= " RETURNING id_sesi, nama_ujian, durasi_menit, 
                GREATEST(0, FLOOR(EXTRACT(EPOCH FROM (created_at + (durasi_menit * INTERVAL '1 minute') - CURRENT_TIMESTAMP))))::int as sisa_detik_baru";

    $paramsUpd = [':tambah' => $tambahMenit, ':id' => $idSesi];
    if ($idGuru !== null) {
        $paramsUpd[':g'] = $idGuru;
    }

    $stmtUpd = $db->prepare($sqlUpd);
    $stmtUpd->execute($paramsUpd);
    $hasil = $stmtUpd->fetch();

    if (!$hasil) {
        return ['success' => false, 'message' => 'Gagal memperbarui waktu sesi ujian.'];
    }

    $sisaDetikBaru = (int)$hasil['sisa_detik_baru'];

    // Update sisa_detik pada seluruh siswa yang sedang aktif mengerjakan sesi ini
    $stmtSiswa = $db->prepare("
        UPDATE ujian_siswa
        SET sisa_detik = :sisa
        WHERE id_sesi = :id AND status = 'sedang'
    ");
    $stmtSiswa->execute([
        ':sisa' => $sisaDetikBaru,
        ':id'   => $idSesi
    ]);

    return [
        'success'         => true,
        'nama_ujian'      => $hasil['nama_ujian'],
        'durasi_baru'     => (int)$hasil['durasi_menit'],
        'sisa_detik_baru' => $sisaDetikBaru,
        'tambah_menit'    => $tambahMenit
    ];
}

