<?php
/**
 * CLI Server Alert Broadcaster
 * CBT SDN TALUN
 * 
 * Skrip terminal / konsol untuk mengirimkan pesan pop-up pengumuman server
 * secara instan ke layar siswa (ruang ujian), guru, atau operator.
 * 
 * Penggunaan:
 *   php alert.php "Pesan alert"
 *   php alert.php "Judul" "Pesan alert" [target]
 *   php alert.php [kata demi kata tanpa petik]
 *   php alert.php -y "Pesan" (tanpa konfirmasi)
 *   php alert.php (interaktif)
 *   php alert.php --list
 *   php alert.php --clear
 */

if (php_sapi_name() !== "cli") {
    die("Akses ditolak: Skrip ini hanya dapat dijalankan melalui terminal / CLI server.\n");
}

require_once __DIR__ . "/config/database.php";

// ANSI Color Helpers
const C_RESET  = "\033[0m";
const C_BOLD   = "\033[1m";
const C_RED    = "\033[31m";
const C_GREEN  = "\033[32m";
const C_YELLOW = "\033[33m";
const C_BLUE   = "\033[34m";
const C_MAGENTA= "\033[35m";
const C_CYAN   = "\033[36m";
const C_WHITE  = "\033[37m";

function print_banner(): void {
    echo C_CYAN . C_BOLD;
    echo "==========================================================\n";
    echo "       CBT SDN TALUN - SERVER ALERT BROADCASTER           \n";
    echo "==========================================================\n" . C_RESET;
}

function show_help(): void {
    print_banner();
    echo C_BOLD . "CARA PENGGUNAAN:\n" . C_RESET;
    echo "  php alert.php [pesan]\n";
    echo "  php alert.php [judul] [pesan] [target]\n";
    echo "  php alert.php kata demi kata tanpa tanda petik\n\n";
    echo C_BOLD . "CONTOH PENGGUNAAN:\n" . C_RESET;
    echo "  " . C_GREEN . "php alert.php \"Waktu ujian sisa 10 menit lagi!\"" . C_RESET . "\n";
    echo "  " . C_GREEN . "php alert.php \"Peringatan Server\" \"Server akan restart dalam 5 menit\"" . C_RESET . "\n";
    echo "  " . C_GREEN . "php alert.php \"INFO UJIAN\" \"Periksa kembali jawaban Anda\" siswa" . C_RESET . "\n";
    echo "  " . C_GREEN . "php alert.php server akan restart dalam 5 menit" . C_RESET . "\n";
    echo "  " . C_GREEN . "php alert.php -y \"Pesan darurat tanpa konfirmasi\"" . C_RESET . "\n\n";
    echo C_BOLD . "OPSI LAINNYA:\n" . C_RESET;
    echo "  " . C_YELLOW . "php alert.php" . C_RESET . "                 Masuk ke mode tanya-jawab interaktif\n";
    echo "  " . C_YELLOW . "php alert.php -y [opsi...]" . C_RESET . "        Bypass konfirmasi (langsung kirim/eksekusi)\n";
    echo "  " . C_YELLOW . "php alert.php --list" . C_RESET . "          Melihat daftar alert yang baru saja dikirim\n";
    echo "  " . C_YELLOW . "php alert.php --clear" . C_RESET . "         Menghapus seluruh riwayat alert lama\n";
    echo "  " . C_YELLOW . "php alert.php --help" . C_RESET . "          Menampilkan panduan ini\n\n";
}

// Parse argumen baris perintah
$rawArgs = array_slice($argv, 1);

// Cek opsi bantuan (tanpa butuh koneksi database)
if (in_array("--help", $rawArgs, true) || in_array("-h", $rawArgs, true)) {
    show_help();
    exit(0);
}

// Ekstrak opsi auto-yes / force (-y, --yes, -f)
$autoYes = false;
$argvClean = [];
foreach ($rawArgs as $arg) {
    if (in_array($arg, ["-y", "--yes", "-f"], true)) {
        $autoYes = true;
    } else {
        $argvClean[] = $arg;
    }
}

// Opsi list riwayat alert
if (in_array("--list", $argvClean, true)) {
    print_banner();
    $db = get_db();
    $stmt = $db->query("SELECT * FROM server_alerts ORDER BY id DESC LIMIT 10");
    $alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($alerts)) {
        echo C_YELLOW . "Belum ada riwayat alert yang dikirim.\n" . C_RESET;
        exit(0);
    }

    echo C_BOLD . "Daftar 10 Alert Terakhir:\n" . C_RESET;
    echo str_repeat("-", 60) . "\n";
    foreach ($alerts as $a) {
        $waktu = date("d/m/Y H:i:s", strtotime($a["created_at"]));
        echo C_CYAN . "[#" . $a["id"] . "] " . C_BOLD . $a["judul"] . C_RESET;
        echo " (" . C_MAGENTA . "Target: " . $a["target"] . C_RESET . " | " . $waktu . ")\n";
        echo "    " . C_WHITE . $a["pesan"] . C_RESET . "\n\n";
    }
    echo str_repeat("-", 60) . "\n";
    exit(0);
}

// Helper untuk membaca input STDIN
function get_stdin_input(): string {
    $handle = defined("STDIN") ? STDIN : fopen("php://stdin", "r");
    return trim((string)fgets($handle));
}

// Opsi clear seluruh alert
if (in_array("--clear", $argvClean, true)) {
    print_banner();
    if ($autoYes) {
        $confirm = "y";
    } else {
        echo C_YELLOW . "Apakah Anda yakin ingin menghapus SEMUA riwayat alert di database? (y/N): " . C_RESET;
        $confirm = get_stdin_input();
    }
    if (in_array(strtolower($confirm), ["y", "ya", "yes"], true)) {
        $db = get_db();
        $db->exec("TRUNCATE TABLE server_alerts RESTART IDENTITY");
        echo C_GREEN . "✓ Seluruh riwayat alert berhasil dibersihkan!\n" . C_RESET;
    } else {
        echo "Dibatalkan.\n";
    }
    exit(0);
}

$validTargets = ["semua", "siswa", "guru", "operator"];
$target = "semua";
$judul  = "Pemberitahuan Admin Server";
$pesan  = "";

if (!empty($argvClean)) {
    // 1. Cek apakah kata terakhir adalah target (semua / siswa / guru / operator)
    if (count($argvClean) > 1 && in_array(strtolower(end($argvClean)), $validTargets, true)) {
        $target = strtolower(array_pop($argvClean));
    }

    // 2. Tentukan judul dan isi pesan
    $count = count($argvClean);
    if ($count === 1) {
        // Satu argumen: selalu dianggap sebagai isi pesan
        $pesan = trim($argvClean[0]);
    } elseif ($count === 2) {
        // Dua argumen: Satu judul, satu pesan.
        $arg0 = trim($argvClean[0]);
        $arg1 = trim($argvClean[1]);

        $titleKeywords = ["peringatan", "info", "pengumuman", "perhatian", "alert", "pemberitahuan", "pesan", "kritis", "penting", "server"];
        $arg0IsTitle = false;
        foreach ($titleKeywords as $kw) {
            if (stripos($arg0, $kw) !== false) {
                $arg0IsTitle = true;
                break;
            }
        }

        // Jika argumen pertama memiliki kata kunci judul atau lebih pendek dari argumen kedua, jadikan sebagai judul
        if ($arg0IsTitle || mb_strlen($arg0) <= mb_strlen($arg1)) {
            $judul = $arg0;
            $pesan = $arg1;
        } else {
            $pesan = $arg0;
            $judul = $arg1;
        }
    } else {
        // Lebih dari 2 argumen: pengguna mengetik kalimat tanpa tanda petik
        // Contoh: php alert.php server akan restart dalam 5 menit
        $pesan = trim(implode(" ", $argvClean));
    }
} else {
    // Mode Interaktif
    print_banner();
    echo C_BOLD . ">> Mode Pengiriman Interaktif <<\n" . C_RESET;

    // 1. Judul
    echo C_CYAN . "Masukkan Judul Pop-up " . C_RESET . "[Default: Pemberitahuan Admin Server]: ";
    $inputJudul = get_stdin_input();
    if ($inputJudul !== "") {
        $judul = $inputJudul;
    }

    // 2. Pesan
    while (empty($pesan)) {
        echo C_CYAN . "Masukkan Pesan Pop-up " . C_RED . "(Wajib diisi)" . C_RESET . ": ";
        $pesan = get_stdin_input();
        if (empty($pesan)) {
            echo C_RED . "Pesan tidak boleh kosong!\n" . C_RESET;
        }
    }

    // 3. Target
    echo C_CYAN . "Pilih Target Penerima:\n" . C_RESET;
    echo "  [1] Semua Pengguna (Siswa, Guru, Operator) " . C_YELLOW . "[Default]" . C_RESET . "\n";
    echo "  [2] Siswa Saja (Ruang Ujian & Konfirmasi)\n";
    echo "  [3] Guru Saja\n";
    echo "  [4] Operator Saja\n";
    echo "Pilihan (1/2/3/4): ";
    $pilihanTarget = get_stdin_input();
    switch ($pilihanTarget) {
        case "2": $target = "siswa"; break;
        case "3": $target = "guru"; break;
        case "4": $target = "operator"; break;
        default:  $target = "semua"; break;
    }
    echo "\n";
}

if (empty($pesan)) {
    echo C_RED . "Error: Pesan tidak boleh kosong! Jalankan \x27php alert.php --help\x27 untuk panduan.\n" . C_RESET;
    exit(1);
}

// Tampilkan Detail Alert Sebelum Dikirim
echo "\n" . C_BOLD . "Detail Alert yang Akan Dikirim:\n" . C_RESET;
echo str_repeat("-", 55) . "\n";
echo "  " . C_BOLD . "Judul  " . C_RESET . " : " . C_CYAN . $judul . C_RESET . "\n";
echo "  " . C_BOLD . "Pesan  " . C_RESET . " : " . C_WHITE . $pesan . C_RESET . "\n";
echo "  " . C_BOLD . "Target " . C_RESET . " : " . C_MAGENTA . strtoupper($target) . C_RESET . "\n";
echo str_repeat("-", 55) . "\n";

// Konfirmasi Pengiriman
if (!$autoYes) {
    echo C_YELLOW . "Apakah Anda yakin ingin mengirim alert ini? (y/N): " . C_RESET;
    $confirm = get_stdin_input();

    if (!in_array(strtolower($confirm), ["y", "ya", "yes"], true)) {
        echo C_YELLOW . "Pengiriman alert dibatalkan.\n" . C_RESET;
        exit(0);
    }
    echo "\n";
}

// Simpan ke Database
try {
    $db = get_db();
    $stmt = $db->prepare("INSERT INTO server_alerts (judul, pesan, target, created_at) VALUES (:judul, :pesan, :target, NOW()) RETURNING id, created_at");
    $stmt->execute([
        ":judul"  => $judul,
        ":pesan"  => $pesan,
        ":target" => $target,
    ]);
    $inserted = $stmt->fetch(PDO::FETCH_ASSOC);
    $alertId  = $inserted["id"] ?? null;
    $waktu    = isset($inserted["created_at"]) ? date("H:i:s", strtotime($inserted["created_at"])) : date("H:i:s");

    echo C_GREEN . C_BOLD . "✓ SUKSES: Alert berhasil dikirim ke antarmuka web CBT!" . C_RESET . "\n";
    echo "  " . C_BOLD . "ID Alert" . C_RESET . " : #" . $alertId . "\n";
    echo "  " . C_BOLD . "Judul   " . C_RESET . " : " . $judul . "\n";
    echo "  " . C_BOLD . "Pesan   " . C_RESET . " : " . $pesan . "\n";
    echo "  " . C_BOLD . "Target  " . C_RESET . " : " . strtoupper($target) . "\n";
    echo "  " . C_BOLD . "Waktu   " . C_RESET . " : " . $waktu . "\n";
    echo C_YELLOW . "  >> Web browser klien akan menampilkan pop-up dalam 3-5 detik. <<" . C_RESET . "\n\n";

} catch (Throwable $e) {
    echo C_RED . "Gagal mengirim alert: " . $e->getMessage() . "\n" . C_RESET;
    exit(1);
}
