<?php
/**
 * Page: Import Soal CSV / Excel Sederhana
 * Mendukung Soal Pilihan Ganda (3-5 opsi), Pilihan Ganda Kompleks (PGK), Menjodohkan (MJDK), Isian Singkat (IJS), & Soal Essai (Uraian)
 */

require_once __DIR__ . '/../../middleware/auth.php';
require_once __DIR__ . '/../../config/scoring.php';

$currentUser = auth_check(['guru', 'operator']);
$db = get_db();

$idGuru = $currentUser['id_user'];

// Tangani Download Template CSV
if (isset($_GET['action']) && $_GET['action'] === 'download_template') {
    if (ob_get_level() > 0) {
        ob_end_clean();
    }

    $filename = 'template_import_soal_cbt.csv';
    header('Content-Description: File Transfer');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Expires: 0');
    header('Pragma: public');

    $output = fopen('php://output', 'w');
    // Tulis UTF-8 BOM agar Excel membukanya dengan karakter dan encoding yang benar
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Baris instruksi delimiter untuk Microsoft Excel
    fwrite($output, "sep=,
");

    // Header Kolom
    fputcsv($output, [
        'jenis_soal',
        'pertanyaan',
        'opsi_a',
        'opsi_b',
        'opsi_c',
        'opsi_d',
        'opsi_e',
        'kunci_jawaban',
        'bobot'
    ]);

    // 1. Contoh Soal Pilihan Ganda (Standar SD 4 Opsi A-D, Bobot 2)
    fputcsv($output, [
        'pg',
        'Salah satu sila dalam Pancasila yang dilambangkan dengan pohon beringin adalah sila ke-...',
        'Pertama',
        'Kedua',
        'Ketiga',
        'Keempat',
        '',
        'C',
        '2'
    ]);

    // 2. Contoh Soal Pilihan Ganda (SD Kelas Rendah 3 Opsi A-C, Bobot 2)
    fputcsv($output, [
        'pg',
        'Hewan berikut yang berkembang biak dengan cara bertelur adalah ...',
        'Ayam',
        'Kucing',
        'Sapi',
        '',
        '',
        'A',
        '2'
    ]);

    // 3. Contoh Soal Pilihan Ganda Kompleks (> 1 Jawaban Benar, Bobot 3)
    fputcsv($output, [
        'pgk',
        'Manakah di antara bilangan berikut yang merupakan bilangan prima? (Pilih lebih dari satu jawaban)',
        '2',
        '4',
        '5',
        '9',
        '',
        'A,C',
        '3'
    ]);

    // 4. Contoh Soal Menjodohkan / MJDK (Pasangan diisi di Opsi A-D, Bobot 6)
    fputcsv($output, [
        'mjdk',
        'Pasangkanlah nama tarian tradisional daerah berikut dengan provinsi asalnya!',
        'Tari Piring = Sumatera Barat',
        'Tari Saman = Aceh',
        'Tari Jaipong = Jawa Barat',
        'Tari Pendet = Bali',
        '',
        '',
        '6'
    ]);

    // 5. Contoh Soal Isian / Jawaban Singkat (IJS, Bobot 5)
    fputcsv($output, [
        'ijs',
        'Siapakah nama proklamator sekaligus presiden pertama Republik Indonesia?',
        '',
        '',
        '',
        '',
        '',
        'Ir. Soekarno | Soekarno',
        '5'
    ]);

    // 6. Contoh Soal Essai / Uraian (Opsi A-E dikosongkan, Bobot 7)
    fputcsv($output, [
        'essai',
        'Jelaskan secara singkat apa makna dari semboyan Bhinneka Tunggal Ika bagi bangsa Indonesia!',
        '',
        '',
        '',
        '',
        '',
        'Berbeda-beda tetapi tetap satu jua',
        '7'
    ]);

    fclose($output);
    exit;
}

// Tangani Proses Upload dan Parse CSV
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash_set('danger', 'Validasi token keamanan CSRF gagal.');
        redirect(base_url('guru?page=import_soal'));
    }

    $idMapel   = (int)($_POST['id_mapel'] ?? 0);
    $judulSoal = trim($_POST['judul_soal'] ?? '');
    if ($judulSoal === '') {
        $judulSoal = 'Asesmen Nasional';
    }
    if ($idMapel <= 0) {
        flash_set('danger', 'Silakan pilih Mata Pelajaran tujuan terlebih dahulu.');
        redirect(base_url('guru?page=import_soal'));
    }

    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        flash_set('danger', 'Gagal mengunggah file CSV. Pastikan berkas terpilih.');
        redirect(base_url('guru?page=import_soal'));
    }

    $uploadedName = $_FILES['csv_file']['name'] ?? '';
    $uploadedExt  = strtolower(pathinfo($uploadedName, PATHINFO_EXTENSION));
    $tmpPath      = $_FILES['csv_file']['tmp_name'];
    $rawContent   = file_get_contents($tmpPath);

    if ($rawContent === false || trim($rawContent) === '') {
        flash_set('danger', 'Berkas CSV kosong atau tidak dapat dibaca.');
        redirect(base_url('guru?page=import_soal'));
    }

    // Deteksi jika pengguna mengunggah berkas Excel binary (.xlsx / .xls)
    if (in_array($uploadedExt, ['xlsx', 'xls'], true) || str_starts_with($rawContent, "PK")) {
        flash_set('danger', 'Berkas yang diunggah berupa Excel (.xlsx/.xls). Silakan buka di Microsoft Excel lalu pilih "Save As" / Simpan Sebagai format CSV (Comma delimited (*.csv)), kemudian unggah kembali.');
        redirect(base_url('guru?page=import_soal'));
    }

    // Bersihkan UTF-8 BOM jika ada
    if (str_starts_with($rawContent, "ï»¿")) {
        $rawContent = substr($rawContent, 3);
    }

    // Deteksi pemisah terbaik (koma, titik koma, atau tab) dari baris-baris data
    $lines = explode("
", $rawContent);
    $delimiter = ',';

    // Periksa jika ada baris sep=...
    foreach ($lines as $l) {
        $trimmed = trim($l);
        if (preg_match('/^sep=([,;	|])/i', $trimmed, $mSep)) {
            $delimiter = $mSep[1];
            break;
        }
    }

    if ($delimiter === ',') {
        $checkLines = [];
        foreach ($lines as $l) {
            $trimmed = trim($l);
            if ($trimmed === '' || str_starts_with(strtolower($trimmed), 'sep=')) continue;
            $checkLines[] = $trimmed;
            if (count($checkLines) >= 5) break;
        }
        $sampleText = implode("
", $checkLines);

        $commaCount = substr_count($sampleText, ',');
        $semiCount  = substr_count($sampleText, ';');
        $tabCount   = substr_count($sampleText, "	");

        if ($semiCount > $commaCount && $semiCount > $tabCount) {
            $delimiter = ';';
        } elseif ($tabCount > $commaCount && $tabCount > $semiCount) {
            $delimiter = "	";
        }
    }

    $handle = fopen($tmpPath, 'r');
    if ($handle === false) {
        flash_set('danger', 'Gagal membuka berkas CSV.');
        redirect(base_url('guru?page=import_soal'));
    }

    // Cari atau Buat Paket Soal
    $sqlCek = "SELECT id_paket FROM paket_soal WHERE id_mapel = :m AND nama_paket = :j";
    $pCek = [':m' => $idMapel, ':j' => $judulSoal];
    if ($currentUser['role'] === 'guru') {
        $sqlCek .= " AND id_guru = :g";
        $pCek[':g'] = $idGuru;
    }
    $stmtCek = $db->prepare($sqlCek);
    $stmtCek->execute($pCek);
    $idPaket = (int)$stmtCek->fetchColumn();

    if ($idPaket <= 0) {
        $stmtInsP = $db->prepare("INSERT INTO paket_soal (id_guru, id_mapel, nama_paket) VALUES (:g, :m, :j) RETURNING id_paket");
        $stmtInsP->execute([':g' => $idGuru, ':m' => $idMapel, ':j' => $judulSoal]);
        $idPaket = (int)$stmtInsP->fetchColumn();
        if ($idPaket <= 0) {
            $idPaket = (int)$db->lastInsertId('paket_soal_id_paket_seq');
        }
    }

    $importedPG     = 0;
    $importedMjdk   = 0;
    $importedIjs    = 0;
    $importedEssai  = 0;
    $skipped        = 0;
    $skippedReasons = [];
    $headerMap      = null;
    $rowNumber      = 0;

    $stmtIns = $db->prepare("
        INSERT INTO bank_soal (id_paket, jenis_soal, pertanyaan, opsi_a, opsi_b, opsi_c, opsi_d, opsi_e, kunci_jawaban, bobot_soal, konten_soal)
        VALUES (:p, :jenis, :pert, :oa, :ob, :oc, :od, :oe, :k, :bobot, :konten)
    ");

    while (($row = fgetcsv($handle, 8192, $delimiter)) !== false) {
        $rowNumber++;
        if (empty($row)) continue;

        // Bersihkan UTF-8 BOM dan spasi pada kolom pertama
        if (isset($row[0])) {
            $row[0] = preg_replace('/^ï»¿/', '', trim($row[0]));
        }

        // Abaikan baris kosong atau baris penunjuk sep=...
        if (empty($row[0]) && count(array_filter($row)) === 0) continue;
        if (str_starts_with(strtolower($row[0]), 'sep=')) continue;

        if ($headerMap === null) {
            $tempMap = [
                'jenis'      => null,
                'pertanyaan' => null,
                'opsi_a'     => null,
                'opsi_b'     => null,
                'opsi_c'     => null,
                'opsi_d'     => null,
                'opsi_e'     => null,
                'kunci'      => null,
                'bobot'      => null,
            ];

            foreach ($row as $colIdx => $colName) {
                $clean = strtolower(trim(preg_replace('/[^a-zA-Z0-9]/', '', $colName)));
                if (in_array($clean, ['jenissoal', 'jenis', 'type', 'tipe'], true)) {
                    $tempMap['jenis'] = $colIdx;
                } elseif (in_array($clean, ['pertanyaan', 'soal', 'soalpertanyaan', 'question', 'isi'], true)) {
                    $tempMap['pertanyaan'] = $colIdx;
                } elseif (in_array($clean, ['opsia', 'a', 'pilihan1', 'pilihana'], true)) {
                    $tempMap['opsi_a'] = $colIdx;
                } elseif (in_array($clean, ['opsib', 'b', 'pilihan2', 'pilihanb'], true)) {
                    $tempMap['opsi_b'] = $colIdx;
                } elseif (in_array($clean, ['opsic', 'c', 'pilihan3', 'pilihanc'], true)) {
                    $tempMap['opsi_c'] = $colIdx;
                } elseif (in_array($clean, ['opsid', 'd', 'pilihan4', 'pilihand'], true)) {
                    $tempMap['opsi_d'] = $colIdx;
                } elseif (in_array($clean, ['opsie', 'e', 'pilihan5', 'pilihane'], true)) {
                    $tempMap['opsi_e'] = $colIdx;
                } elseif (in_array($clean, ['kuncijawaban', 'kunci', 'jawaban', 'answer', 'key', 'kuncisoal'], true)) {
                    $tempMap['kunci'] = $colIdx;
                } elseif (in_array($clean, ['bobot', 'bobotsoal', 'skor', 'poin', 'score', 'weight'], true)) {
                    $tempMap['bobot'] = $colIdx;
                }
            }

            // Jika baris pertama memang header yang terdeteksi
            if ($tempMap['pertanyaan'] !== null) {
                $headerMap = $tempMap;
                continue; // Header selesai diproses
            }

            // Fallback jika tidak ada header atau header tidak dikenali
            $firstCell = strtolower(trim($row[0] ?? ''));
            if ($firstCell === 'no' || is_numeric($firstCell) || $firstCell === 'nomor') {
                $headerMap = [
                    'jenis'      => 1,
                    'pertanyaan' => 2,
                    'opsi_a'     => 3,
                    'opsi_b'     => 4,
                    'opsi_c'     => 5,
                    'opsi_d'     => 6,
                    'opsi_e'     => 7,
                    'kunci'      => 8,
                    'bobot'      => 9,
                ];
                continue;
            } elseif (in_array($firstCell, ['jenis_soal', 'jenis', 'tipe', 'soal', 'pertanyaan'])) {
                $headerMap = [
                    'jenis'      => 0,
                    'pertanyaan' => 1,
                    'opsi_a'     => 2,
                    'opsi_b'     => 3,
                    'opsi_c'     => 4,
                    'opsi_d'     => 5,
                    'opsi_e'     => 6,
                    'kunci'      => 7,
                    'bobot'      => 8,
                ];
                continue;
            } else {
                // File tidak memiliki header sama sekali, baris ini langsung diproses sebagai soal pertama
                $headerMap = [
                    'jenis'      => 0,
                    'pertanyaan' => 1,
                    'opsi_a'     => 2,
                    'opsi_b'     => 3,
                    'opsi_c'     => 4,
                    'opsi_d'     => 5,
                    'opsi_e'     => 6,
                    'kunci'      => 7,
                    'bobot'      => 8,
                ];
            }
        }

        $pertanyaan = trim($row[$headerMap['pertanyaan']] ?? '');
        if ($pertanyaan === '') {
            $skipped++;
            $skippedReasons[] = "Baris #{$rowNumber}: Teks pertanyaan kosong.";
            continue;
        }

        $rawJenis = $headerMap['jenis'] !== null ? strtolower(trim($row[$headerMap['jenis']] ?? '')) : '';
        $oa       = $headerMap['opsi_a'] !== null ? trim($row[$headerMap['opsi_a']] ?? '') : '';
        $ob       = $headerMap['opsi_b'] !== null ? trim($row[$headerMap['opsi_b']] ?? '') : '';
        $oc       = $headerMap['opsi_c'] !== null ? trim($row[$headerMap['opsi_c']] ?? '') : '';
        $od       = $headerMap['opsi_d'] !== null ? trim($row[$headerMap['opsi_d']] ?? '') : '';
        $oe       = $headerMap['opsi_e'] !== null ? trim($row[$headerMap['opsi_e']] ?? '') : '';
        $kunci    = $headerMap['kunci'] !== null ? trim($row[$headerMap['kunci']] ?? '') : '';

        // Bobot manual dari CSV jika ada
        $bobotVal = null;
        if ($headerMap['bobot'] !== null && isset($row[$headerMap['bobot']])) {
            $rawBobot = str_replace(',', '.', trim($row[$headerMap['bobot']]));
            if (is_numeric($rawBobot) && (float)$rawBobot > 0) {
                $bobotVal = (float)$rawBobot;
            }
        }

        if ($rawJenis === '') {
            if ($oa === '' && $ob === '') {
                $rawJenis = 'essai';
            } elseif (strpos($oa, '=') !== false || strpos($oa, '->') !== false) {
                $rawJenis = 'mjdk';
            } else {
                $rawJenis = 'pg';
            }
        }

        $isMjdk  = in_array($rawJenis, ['mjdk', 'jodohkan', 'menjodohkan'], true);
        $isIjs   = in_array($rawJenis, ['ijs', 'isian', 'jawaban_singkat', 'isian_singkat'], true);
        $isEssai = in_array($rawJenis, ['essai', 'uraian', 'essay', 'esay', 'u'], true);

        $kontenJson = null;

        // =====================================================================
        // A. MENJODOHKAN (MJDK)
        // =====================================================================
        if ($isMjdk) {
            $rawPairs = array_filter([$oa, $ob, $oc, $od, $oe], function($v) { return $v !== ''; });
            $premisList  = [];
            $pilihanList = [];
            $kunciPairs  = [];

            $pairIdx = 1;
            foreach ($rawPairs as $rp) {
                $rp = trim($rp);
                if ($rp === '') continue;

                $left = '';
                $right = '';
                if (strpos($rp, '=') !== false) {
                    [$left, $right] = explode('=', $rp, 2);
                } elseif (strpos($rp, '->') !== false) {
                    [$left, $right] = explode('->', $rp, 2);
                } elseif (strpos($rp, ':') !== false) {
                    [$left, $right] = explode(':', $rp, 2);
                } elseif (strpos($rp, ' - ') !== false) {
                    [$left, $right] = explode(' - ', $rp, 2);
                } else {
                    $left = $rp;
                    $right = $rp;
                }

                $left = trim($left);
                $right = trim($right);
                if ($left !== '' && $right !== '') {
                    $pId = 'p' . $pairIdx;
                    $jId = 'j' . $pairIdx;
                    $premisList[]  = ['id' => $pId, 'teks' => $left];
                    $pilihanList[] = ['id' => $jId, 'teks' => $right];
                    $kunciPairs[$pId] = $jId;
                    $pairIdx++;
                }
            }

            if (count($premisList) < 2) {
                $skipped++;
                $skippedReasons[] = "Baris #{$rowNumber} ('" . mb_substr($pertanyaan, 0, 30) . "...'): Soal Menjodohkan minimal memiliki 2 pasangan di kolom opsi (contoh: 'Pokok Soal = Pasangan Jawaban').";
                continue;
            }

            $jenisDb = 'mjdk';
            $oaDb    = null;
            $obDb    = null;
            $ocDb    = null;
            $odDb    = null;
            $oeDb    = null;
            $kunciDb = json_encode($kunciPairs);
            $kontenJson = json_encode([
                'premis'  => $premisList,
                'pilihan' => $pilihanList,
                'kunci'   => $kunciPairs
            ]);
            $bobotDb = $bobotVal ?? cbt_get_default_bobot('mjdk');

        // =====================================================================
        // B. ISIAN / JAWABAN SINGKAT (IJS)
        // =====================================================================
        } elseif ($isIjs) {
            $jenisDb = 'ijs';
            $oaDb    = null;
            $obDb    = null;
            $ocDb    = null;
            $odDb    = null;
            $oeDb    = null;
            $kunciDb = ($kunci !== '') ? $kunci : null;
            $bobotDb = $bobotVal ?? cbt_get_default_bobot('ijs');

        // =====================================================================
        // C. URAIAN / ESSAI
        // =====================================================================
        } elseif ($isEssai) {
            $jenisDb = 'essai';
            $oaDb    = null;
            $obDb    = null;
            $ocDb    = null;
            $odDb    = null;
            $oeDb    = null;

            // Fallback kunci jika kolom bergeser
            if ($kunci === '' && isset($row[6]) && trim($row[6]) !== '' && $oa === '' && $ob === '') {
                $kunci = trim($row[6]);
            }
            $kunciDb = ($kunci !== '') ? $kunci : null;
            $bobotDb = $bobotVal ?? cbt_get_default_bobot('uraian');

        // =====================================================================
        // D. PILIHAN GANDA (BIASA & KOMPLEKS)
        // =====================================================================
        } else {
            // Minimal opsi A, B, C untuk SD
            if ($oa === '' || $ob === '' || $oc === '') {
                $skipped++;
                $skippedReasons[] = "Baris #{$rowNumber} ('" . mb_substr($pertanyaan, 0, 30) . "...'): Pilihan jawaban A, B, dan C wajib diisi.";
                continue;
            }

            $allowedKeys = ['A', 'B', 'C'];
            if ($od !== '') $allowedKeys[] = 'D';
            if ($oe !== '') $allowedKeys[] = 'E';

            $kunciUpper    = strtoupper(trim($kunci));
            $kunciRawParts = preg_split('/[,;\s]+/', $kunciUpper, -1, PREG_SPLIT_NO_EMPTY);
            $kunciParts    = array_values(array_unique(array_filter(array_map('trim', $kunciRawParts))));

            $validKeys = !empty($kunciParts);
            foreach ($kunciParts as $kp) {
                if (!in_array($kp, $allowedKeys, true)) {
                    $validKeys = false;
                    break;
                }
            }

            if (!$validKeys) {
                $skipped++;
                $skippedReasons[] = "Baris #{$rowNumber} ('" . mb_substr($pertanyaan, 0, 30) . "...'): Kunci '{$kunci}' tidak valid. Pilihan yang tersedia: " . implode('/', $allowedKeys) . ".";
                continue;
            }

            sort($kunciParts);
            $oaDb    = $oa;
            $obDb    = $ob;
            $ocDb    = $oc;
            $odDb    = ($od !== '' ? $od : null);
            $oeDb    = ($oe !== '' ? $oe : null);
            $kunciDb = implode(',', $kunciParts);

            if (count($kunciParts) > 1 || in_array($rawJenis, ['pgk', 'pgk_l1', 'kompleks'], true)) {
                $jenisDb = 'pgk_l1';
                $bobotDb = $bobotVal ?? cbt_get_default_bobot('pgk_l1', $kunciDb);
            } else {
                $jenisDb = 'pilihan_ganda';
                $bobotDb = $bobotVal ?? cbt_get_default_bobot('pg_1', $kunciDb);
            }
        }

        try {
            $stmtIns->execute([
                ':p'      => $idPaket,
                ':jenis'  => $jenisDb,
                ':pert'   => $pertanyaan,
                ':oa'     => $oaDb,
                ':ob'     => $obDb,
                ':oc'     => $ocDb,
                ':od'     => $odDb,
                ':oe'     => $oeDb,
                ':k'      => $kunciDb,
                ':bobot'  => $bobotDb,
                ':konten' => $kontenJson
            ]);

            if ($isMjdk) {
                $importedMjdk++;
            } elseif ($isIjs) {
                $importedIjs++;
            } elseif ($isEssai) {
                $importedEssai++;
            } else {
                $importedPG++;
            }
        } catch (Exception $e) {
            error_log('Error import soal: ' . $e->getMessage());
            $skipped++;
            $skippedReasons[] = "Baris #{$rowNumber} ('" . mb_substr($pertanyaan, 0, 30) . "...'): Terjadi kesalahan sistem database.";
        }
    }

    fclose($handle);

    $totalImported = $importedPG + $importedMjdk + $importedIjs + $importedEssai;
    if ($totalImported > 0) {
        $parts = [];
        if ($importedPG > 0)    $parts[] = "{$importedPG} PG";
        if ($importedMjdk > 0)  $parts[] = "{$importedMjdk} Menjodohkan";
        if ($importedIjs > 0)   $parts[] = "{$importedIjs} Isian Singkat";
        if ($importedEssai > 0) $parts[] = "{$importedEssai} Essai";

        $msg = "Berhasil mengimpor {$totalImported} butir soal (" . implode(', ', $parts) . ") ke paket '{$judulSoal}'.";
        if ($skipped > 0) {
            $msg .= " Catatan: {$skipped} baris dilewati.";
            if (!empty($skippedReasons)) {
                $msg .= " (" . implode("; ", array_slice($skippedReasons, 0, 3)) . (count($skippedReasons) > 3 ? "... dan " . (count($skippedReasons) - 3) . " lainnya" : "") . ")";
            }
        }
        flash_set('success', $msg);
    } else {
        $msg = "Tidak ada soal yang berhasil diimpor. Pastikan file CSV memiliki kolom dan isi yang sesuai template.";
        if (!empty($skippedReasons)) {
            $msg .= " Detail penyebab: " . implode("; ", array_slice($skippedReasons, 0, 3)) . (count($skippedReasons) > 3 ? "... dan " . (count($skippedReasons) - 3) . " lainnya." : ".");
        }
        flash_set('warning', $msg);
    }
    redirect(base_url('guru?page=bank_soal' . ($idMapel ? '&id_mapel=' . $idMapel : '')));
}

// Data Mapel & Judul yang pernah ada
$mapelList = $db->query("SELECT id_mapel, nama_mapel FROM mapel ORDER BY nama_mapel ASC")->fetchAll();
$sqlJudul = "SELECT DISTINCT nama_paket FROM paket_soal";
$pJudul = [];
if ($currentUser['role'] === 'guru') {
    $sqlJudul .= " WHERE id_guru = :g";
    $pJudul[':g'] = $idGuru;
}
$sqlJudul .= " ORDER BY nama_paket ASC";
$stmtJudul = $db->prepare($sqlJudul);
$stmtJudul->execute($pJudul);
$existingJudul = $stmtJudul->fetchAll(PDO::FETCH_COLUMN);

$page = 'import_soal';
$pageTitle = 'Import Soal Ujian (CSV)';

include __DIR__ . '/../layouts/header.php';
?>

<main class="container">
    <?php if ($flash): ?>
        <div class="alert alert-<?= sanitize($flash['type']) ?>">
            <?= sanitize($flash['message']) ?>
        </div>
    <?php endif; ?>

    <div class="card" style="max-width: 780px; margin: 0 auto;">
        <div class="flex-between mb-4 pb-2" style="border-bottom: 1px solid #e2e8f0;">
            <div>
                <h1 style="font-size: 1.25rem; font-weight: 700; color: #1e293b; margin: 0;">Import Butir Soal (CSV)</h1>
                <p style="font-size: 0.85rem; color: #64748b; margin: 0.25rem 0 0;">Unggah butir soal sekaligus (Pilihan Ganda biasa, PG Kompleks, Menjodohkan, Isian Singkat, & Essai).</p>
            </div>
            <a href="<?= base_url('guru?page=bank_soal') ?>" class="btn btn-sm btn-outline" style="font-size: 0.8rem;">Kembali</a>
        </div>

        <div style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 1.15rem 1.25rem; margin-bottom: 1.5rem;">
            <div style="font-weight: 700; color: #1d4ed8; font-size: 0.92rem; margin-bottom: 0.45rem; display: flex; align-items: center; gap: 0.4rem;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
                <span>Petunjuk Format Berkas CSV:</span>
            </div>
            <ul style="font-size: 0.83rem; color: #1e40af; margin: 0; padding-left: 1.25rem; line-height: 1.6;">
                <li><strong>Kolom 1 (jenis_soal):</strong> 
                    <code>pg</code> (Pilihan Ganda biasa), 
                    <code>pgk</code> (PG Kompleks), 
                    <code>mjdk</code> (Menjodohkan), 
                    <code>ijs</code> (Isian Singkat), atau 
                    <code>essai</code> (Uraian).
                </li>
                <li><strong>Kolom 2 (pertanyaan):</strong> Teks butir pertanyaan asesmen.</li>
                <li><strong>Kolom 3-7 (opsi_a s/d opsi_e):</strong>
                    <ul style="margin: 0.2rem 0; padding-left: 1rem;">
                        <li>Untuk <strong>pg / pgk</strong>: Isi teks pilihan jawaban (A, B, C minimal wajib diisi, D & E opsional).</li>
                        <li>Untuk <strong>mjdk (Menjodohkan)</strong>: Isi pasangan jawaban menggunakan tanda sama dengan (<code>=</code>), contoh: <code>Tari Saman = Aceh</code> di kolom Opsi A, B, C, dst.</li>
                        <li>Untuk <strong>ijs & essai</strong>: Kosongkan seluruh kolom opsi A–E.</li>
                    </ul>
                </li>
                <li><strong>Kolom 8 (kunci_jawaban):</strong>
                    <ul style="margin: 0.2rem 0; padding-left: 1rem;">
                        <li>Untuk <strong>pg</strong>: Huruf jawaban benar (contoh: <code>A</code>, <code>B</code>, atau <code>C</code>).</li>
                        <li>Untuk <strong>pgk</strong>: Huruf dipisah koma (contoh: <code>A,C</code>). Penilaian sistem: benar dikurangi salah (bisa bernilai minus jika salah lebih banyak; jika tidak dijawab nilai tetap 0).</li>
                        <li>Untuk <strong>mjdk</strong>: Dapat dikosongkan (otomatis dipetakan dari kolom opsi yang bertanda <code>=</code>).</li>
                        <li>Untuk <strong>ijs</strong>: Kunci jawaban singkat untuk penilaian otomatis sistem (gunakan tanda <code>|</code> jika ada alternatif variasi jawaban).</li>
                        <li>Untuk <strong>essai (Uraian)</strong>: Pedoman / catatan rubrik penilaian manual guru.</li>
                    </ul>
                </li>
                <li><strong>Kolom 9 (bobot):</strong>
                    <em>(Opsional)</em> Angka nilai bobot soal (contoh: <code>2</code> untuk PG, <code>3</code> untuk PGK, <code>6</code> untuk Menjodohkan, <code>7</code> untuk Essai). Jika kosong, bobot resmi sistem digunakan otomatis.
                </li>
            </ul>
            <div style="margin-top: 0.9rem; display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap;">
                <a href="<?= base_url('guru?page=import_soal&action=download_template') ?>" class="btn btn-sm btn-secondary" style="font-size: 0.82rem; font-weight: 600; display: inline-flex; align-items: center; gap: 0.35rem;">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                    <span>Unduh Template CSV Lengkap (PG, PGK, MJDK, IJS, Essai)</span>
                </a>
                <span style="font-size: 0.78rem; color: #64748b;">Dapat diedit via Microsoft Excel lalu simpan sebagai <strong>CSV (Comma delimited) (*.csv)</strong>.</span>
            </div>
        </div>

        <form action="<?= base_url('guru?page=import_soal') ?>" method="POST" enctype="multipart/form-data">
            <?= csrf_field() ?>

            <div class="form-group mb-3">
                <label for="id_mapel" style="font-size: 0.85rem; font-weight: 600; color: #334155; margin-bottom: 0.35rem; display: block;">Mata Pelajaran Tujuan <span class="text-danger">*</span></label>
                <select name="id_mapel" id="id_mapel" class="form-control" required style="width: 100%; padding: 0.5rem 0.75rem; font-size: 0.875rem; border: 1px solid #cbd5e1; border-radius: 6px;">
                    <option value="">-- Pilih Mata Pelajaran --</option>
                    <?php foreach ($mapelList as $m): ?>
                        <option value="<?= $m['id_mapel'] ?>" <?= (isset($_GET['id_mapel']) && $_GET['id_mapel'] == $m['id_mapel']) ? 'selected' : '' ?>>
                            <?= sanitize($m['nama_mapel']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group mb-3">
                <label for="judul_soal" style="font-size: 0.85rem; font-weight: 600; color: #334155; margin-bottom: 0.35rem; display: block;">Judul / Paket Soal <span class="text-danger">*</span></label>
                <input type="text" name="judul_soal" id="judul_soal" class="form-control" list="list_judul" placeholder="Contoh: Penilaian Harian 1" value="<?= sanitize($_GET['judul_soal'] ?? '') ?>" required style="width: 100%; padding: 0.5rem 0.75rem; font-size: 0.875rem; border: 1px solid #cbd5e1; border-radius: 6px;">
                <datalist id="list_judul">
                    <?php foreach ($existingJudul as $j): ?>
                        <option value="<?= sanitize($j) ?>"></option>
                    <?php endforeach; ?>
                    <option value="Asesmen Nasional"></option>
                    <option value="Penilaian Harian 1"></option>
                    <option value="Penilaian Tengah Semester"></option>
                    <option value="Penilaian Akhir Semester"></option>
                </datalist>
            </div>

            <div class="form-group mb-4">
                <label for="csv_file" style="font-size: 0.85rem; font-weight: 600; color: #334155; margin-bottom: 0.35rem; display: block;">Pilih Berkas CSV <span class="text-danger">*</span></label>
                <input type="file" name="csv_file" id="csv_file" class="form-control" accept=".csv, text/csv, text/plain" required style="width: 100%; padding: 0.45rem 0.75rem; font-size: 0.85rem; border: 1px solid #cbd5e1; border-radius: 6px;">
            </div>

            <div class="flex gap-2" style="justify-content: flex-end;">
                <a href="<?= base_url('guru?page=bank_soal') ?>" class="btn btn-outline" style="font-size: 0.85rem; padding: 0.5rem 1rem;">Batal</a>
                <button type="submit" class="btn btn-primary" style="font-size: 0.85rem; padding: 0.5rem 1.25rem; font-weight: 600;">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="15" x2="12" y2="15"></line></svg>
                    <span>Unggah & Impor Soal</span>
                </button>
            </div>
        </form>
    </div>
</main>

<?php
include __DIR__ . '/../layouts/footer.php';
