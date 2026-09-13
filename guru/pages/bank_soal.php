<?php
/**
 * Modul Bank Soal Guru (Daftar & Kelola Paket Soal Terstruktur & Rapi)
 */

require_once __DIR__ . '/../../middleware/auth.php';
require_once __DIR__ . '/../../config/scoring.php';

$currentUser = auth_check(['guru', 'operator']);
$db = get_db();
$idGuru = $currentUser['id_user'];
$page = 'bank_soal';
$pageTitle = 'Bank Soal Ujian';

// Tangani Export CSV per Paket
if (isset($_GET['action']) && $_GET['action'] === 'export_csv') {
    $idPaket  = (int)($_GET['id_paket'] ?? 0);
    $expMapel = (int)($_GET['id_mapel'] ?? 0);
    $expJudul = trim($_GET['judul_soal'] ?? '');

    $paket = null;
    if ($idPaket > 0) {
        $sql = "
            SELECT p.*, m.nama_mapel 
            FROM paket_soal p
            JOIN mapel m ON p.id_mapel = m.id_mapel
            WHERE p.id_paket = :p
        ";
        $params = [':p' => $idPaket];
        if ($currentUser['role'] === 'guru') {
            $sql .= " AND p.id_guru = :g";
            $params[':g'] = $idGuru;
        }
        $stmtP = $db->prepare($sql);
        $stmtP->execute($params);
        $paket = $stmtP->fetch();
    } elseif ($expMapel > 0 && $expJudul !== '') {
        $sql = "
            SELECT p.*, m.nama_mapel 
            FROM paket_soal p
            JOIN mapel m ON p.id_mapel = m.id_mapel
            WHERE p.id_mapel = :m AND p.nama_paket = :j
        ";
        $params = [':m' => $expMapel, ':j' => $expJudul];
        if ($currentUser['role'] === 'guru') {
            $sql .= " AND p.id_guru = :g";
            $params[':g'] = $idGuru;
        }
        $stmtP = $db->prepare($sql);
        $stmtP->execute($params);
        $paket = $stmtP->fetch();
    }

    if (!$paket) {
        flash_set('danger', 'Paket soal tidak ditemukan.');
        redirect(base_url('guru?page=bank_soal'));
    }

    $stmtExp = $db->prepare("
        SELECT * FROM bank_soal 
        WHERE id_paket = :p
        ORDER BY id_soal ASC
    ");
    $stmtExp->execute([':p' => $paket['id_paket']]);
    $rows = $stmtExp->fetchAll();

    if (ob_get_level() > 0) {
        ob_end_clean();
    }

    $filename = 'paket_soal_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $paket['nama_paket']) . '.csv';
    header('Content-Description: File Transfer');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Expires: 0');
    header('Pragma: public');

    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM
    fwrite($out, "sep=,
");
    fputcsv($out, ['jenis_soal', 'pertanyaan', 'opsi_a', 'opsi_b', 'opsi_c', 'opsi_d', 'opsi_e', 'kunci_jawaban', 'bobot']);
    foreach ($rows as $r) {
        $isEssai = in_array($r['jenis_soal'], ['essai', 'uraian'], true);
        if ($isEssai) {
            $jenisExport = 'essai';
        } elseif ($r['jenis_soal'] === 'mjdk') {
            $jenisExport = 'mjdk';
        } elseif (in_array($r['jenis_soal'], ['ijs', 'isian', 'jawaban_singkat'], true)) {
            $jenisExport = 'ijs';
        } elseif ($r['jenis_soal'] === 'pgk_l1' || strpos($r['kunci_jawaban'] ?? '', ',') !== false) {
            $jenisExport = 'pgk';
        } else {
            $jenisExport = 'pg';
        }

        $oaExp = $r['opsi_a'] ?? '';
        $obExp = $r['opsi_b'] ?? '';
        $ocExp = $r['opsi_c'] ?? '';
        $odExp = $r['opsi_d'] ?? '';
        $oeExp = $r['opsi_e'] ?? '';
        $kunciExp = $r['kunci_jawaban'] ?? '';

        if ($jenisExport === 'mjdk') {
            $kontenDec = !empty($r['konten_soal']) ? (is_array($r['konten_soal']) ? $r['konten_soal'] : json_decode((string)$r['konten_soal'], true)) : null;
            if (!empty($kontenDec['premis']) && !empty($kontenDec['pilihan'])) {
                $pilihanById = [];
                foreach ($kontenDec['pilihan'] as $pil) {
                    $pilihanById[$pil['id']] = $pil['teks'] ?? '';
                }
                $kunciMap = $kontenDec['kunci'] ?? [];
                if (is_string($kunciMap)) {
                    $kunciMap = json_decode($kunciMap, true) ?: [];
                }
                $pairs = [];
                foreach ($kontenDec['premis'] as $prm) {
                    $targetJId = $kunciMap[$prm['id']] ?? null;
                    $targetText = $targetJId ? ($pilihanById[$targetJId] ?? '') : '';
                    if (!empty($prm['teks']) && $targetText !== '') {
                        $pairs[] = $prm['teks'] . ' = ' . $targetText;
                    } elseif (!empty($prm['teks'])) {
                        $pairs[] = $prm['teks'];
                    }
                }
                $oaExp = $pairs[0] ?? '';
                $obExp = $pairs[1] ?? '';
                $ocExp = $pairs[2] ?? '';
                $odExp = $pairs[3] ?? '';
                $oeExp = $pairs[4] ?? '';
                $kunciExp = '';
            }
        }

        fputcsv($out, [
            $jenisExport,
            $r['pertanyaan'],
            $oaExp,
            $obExp,
            $ocExp,
            $odExp,
            $oeExp,
            $kunciExp,
            $r['bobot_soal'] ?? ''
        ]);
    }
    fclose($out);
    exit;
}

// Tangani Form POST (Hapus Soal Tunggal, Rename Paket, Ratakan Bobot, Hapus Paket)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash_set('danger', 'Validasi token keamanan gagal.');
        redirect(base_url('guru?page=bank_soal'));
    }

    $action = $_POST['action'] ?? '';

    // Hapus Butir Soal Tunggal
    if ($action === 'hapus') {
        $idSoal = (int)($_POST['id_soal'] ?? 0);
        if ($idSoal > 0) {
            $sqlImg = "
                SELECT b.gambar 
                FROM bank_soal b
                JOIN paket_soal p ON b.id_paket = p.id_paket
                WHERE b.id_soal = :id
            ";
            $pImg = [':id' => $idSoal];
            if ($currentUser['role'] === 'guru') {
                $sqlImg .= " AND p.id_guru = :g";
                $pImg[':g'] = $idGuru;
            }
            $stmtImg = $db->prepare($sqlImg);
            $stmtImg->execute($pImg);
            $soalImg = $stmtImg->fetchColumn();

            if ($soalImg && file_exists(__DIR__ . '/../../' . ltrim($soalImg, '/'))) {
                @unlink(__DIR__ . '/../../' . ltrim($soalImg, '/'));
            }

            $sqlDel = "DELETE FROM bank_soal WHERE id_soal = :id";
            $pDel = [':id' => $idSoal];
            if ($currentUser['role'] === 'guru') {
                $sqlDel .= " AND id_paket IN (SELECT id_paket FROM paket_soal WHERE id_guru = :g)";
                $pDel[':g'] = $idGuru;
            }
            $del = $db->prepare($sqlDel);
            $del->execute($pDel);
            flash_set('success', 'Butir soal berhasil dihapus dari paket.');
        }
        redirect(base_url('guru?page=bank_soal' . (!empty($_POST['redirect_mapel']) ? '&id_mapel=' . (int)$_POST['redirect_mapel'] : '')));
    }

    // Rename Nama Paket
    if ($action === 'rename_paket') {
        $idPaket  = (int)($_POST['id_paket'] ?? 0);
        $newJudul = trim($_POST['new_judul'] ?? '');

        if ($idPaket > 0 && $newJudul !== '') {
            $sqlUpd = "UPDATE paket_soal SET nama_paket = :new WHERE id_paket = :id";
            $pUpd = [':new' => $newJudul, ':id' => $idPaket];
            if ($currentUser['role'] === 'guru') {
                $sqlUpd .= " AND id_guru = :g";
                $pUpd[':g'] = $idGuru;
            }
            $upd = $db->prepare($sqlUpd);
            $upd->execute($pUpd);
            flash_set('success', "Nama paket soal berhasil diubah menjadi '{$newJudul}'.");
        }
        redirect(base_url('guru?page=bank_soal' . (!empty($_POST['id_mapel']) ? '&id_mapel=' . (int)$_POST['id_mapel'] : '')));
    }

    // Ratakan Bobot Butir Soal dalam Paket agar Total = 100 Poin
    if ($action === 'ratakan_bobot') {
        $idPaket = (int)($_POST['id_paket'] ?? 0);
        if ($idPaket > 0) {
            $sqlCount = "SELECT COUNT(*) FROM bank_soal b JOIN paket_soal p ON b.id_paket = p.id_paket WHERE b.id_paket = :p";
            $pCount   = [':p' => $idPaket];
            if ($currentUser['role'] === 'guru') {
                $sqlCount .= " AND p.id_guru = :g";
                $pCount[':g'] = $idGuru;
            }
            $stmtCount = $db->prepare($sqlCount);
            $stmtCount->execute($pCount);
            $count = (int)$stmtCount->fetchColumn();

            if ($count > 0) {
                $bobotPerSoal = round(100.0 / $count, 2);
                $sqlUpd = "UPDATE bank_soal SET bobot_soal = :b WHERE id_paket = :p";
                $pUpd   = [':b' => $bobotPerSoal, ':p' => $idPaket];
                $db->prepare($sqlUpd)->execute($pUpd);

                flash_set('success', "Bobot {$count} butir soal pada paket ini berhasil disesuaikan menjadi @ {$bobotPerSoal} poin (Total Pas 100.0 Poin).");
            }
        }
        redirect(base_url('guru?page=bank_soal' . (!empty($_POST['id_mapel']) ? '&id_mapel=' . (int)$_POST['id_mapel'] : '')));
    }

    // Hapus Seluruh Paket Soal (Cascade Butir Soal & Gambar)
    if ($action === 'hapus_paket') {
        $idPaket = (int)($_POST['id_paket'] ?? 0);
        $idMapel = (int)($_POST['id_mapel'] ?? 0);

        if ($idPaket > 0) {
            $sqlP = "SELECT nama_paket, id_mapel FROM paket_soal WHERE id_paket = :id";
            $pP = [':id' => $idPaket];
            if ($currentUser['role'] === 'guru') {
                $sqlP .= " AND id_guru = :g";
                $pP[':g'] = $idGuru;
            }
            $stmtP = $db->prepare($sqlP);
            $stmtP->execute($pP);
            $pInfo = $stmtP->fetch();

            if ($pInfo) {
                $idMapel = (int)$pInfo['id_mapel'];
                $namaPaket = $pInfo['nama_paket'];

                // Hapus berkas gambar butir soal
                $stmtImgs = $db->prepare("SELECT gambar FROM bank_soal WHERE id_paket = :p AND gambar IS NOT NULL");
                $stmtImgs->execute([':p' => $idPaket]);
                $imgs = $stmtImgs->fetchAll(PDO::FETCH_COLUMN);
                foreach ($imgs as $img) {
                    if ($img && file_exists(__DIR__ . '/../../' . ltrim($img, '/'))) {
                        @unlink(__DIR__ . '/../../' . ltrim($img, '/'));
                    }
                }

                $sqlDelP = "DELETE FROM paket_soal WHERE id_paket = :id";
                $pDelP = [':id' => $idPaket];
                if ($currentUser['role'] === 'guru') {
                    $sqlDelP .= " AND id_guru = :g";
                    $pDelP[':g'] = $idGuru;
                }
                $del = $db->prepare($sqlDelP);
                $del->execute($pDelP);
                flash_set('danger', "Seluruh butir soal dalam paket '{$namaPaket}' berhasil dihapus.");
            }
        }
        redirect(base_url('guru?page=bank_soal' . ($idMapel > 0 ? '&id_mapel=' . $idMapel : '')));
    }
}

// Helper format nama mapel
if (!function_exists('format_mapel_name')) {
    function format_mapel_name($nama, $kode) {
        if (empty($kode)) return $nama;
        if (stripos($nama, "({$kode})") !== false || strcasecmp($nama, $kode) === 0) {
            return $nama;
        }
        return $nama . " ({$kode})";
    }
}

// Filter Mapel & Pencarian
$filterMapel = !empty($_GET['id_mapel']) ? (int)$_GET['id_mapel'] : null;
$search      = trim($_GET['search'] ?? '');

// Ambil Statistik Paket per Mapel untuk Guru/Operator ini
$sqlMapel = "
    SELECT m.id_mapel, m.nama_mapel, m.kode_mapel,
           COUNT(DISTINCT p.id_paket) AS total_paket,
           COUNT(b.id_soal) AS total_soal
    FROM mapel m
    LEFT JOIN paket_soal p ON (m.id_mapel = p.id_mapel" . ($currentUser['role'] === 'guru' ? " AND p.id_guru = :g" : "") . ")
    LEFT JOIN bank_soal b ON p.id_paket = b.id_paket
    GROUP BY m.id_mapel, m.nama_mapel, m.kode_mapel
    ORDER BY m.nama_mapel ASC
";
$stmtMapel = $db->prepare($sqlMapel);
$stmtMapel->execute($currentUser['role'] === 'guru' ? [':g' => $idGuru] : []);
$mapelList = $stmtMapel->fetchAll();

$totalSemuaPaket = 0;
foreach ($mapelList as $m) {
    $totalSemuaPaket += (int)$m['total_paket'];
}

// Query Ambil Seluruh Paket Soal (Guru terfilter, Operator melihat semua)
$sql = "
    SELECT p.id_paket, p.nama_paket, p.id_mapel, p.created_at,
           m.nama_mapel, m.kode_mapel,
           COUNT(b.id_soal) AS total_butir,
           COALESCE(SUM(COALESCE(b.bobot_soal, 
               CASE 
                   WHEN b.jenis_soal = 'pg_1' THEN 2.0
                   WHEN b.jenis_soal = 'pgk_l1' THEN 3.0
                   WHEN b.jenis_soal = 'pgk_bs_1' THEN 1.0
                   WHEN b.jenis_soal = 'pgk_bs_l1' THEN 6.0
                   WHEN b.jenis_soal = 'mjdk' THEN 6.0
                   WHEN b.jenis_soal = 'ijs' THEN 5.0
                   WHEN b.jenis_soal = 'uraian' OR b.jenis_soal = 'essai' THEN 7.0
                   ELSE 2.0
               END
           )), 0.00) AS total_bobot,
           COUNT(CASE WHEN b.jenis_soal = 'uraian' OR b.jenis_soal = 'essai' THEN 1 END) AS total_essai,
           COUNT(CASE WHEN b.jenis_soal != 'uraian' AND b.jenis_soal != 'essai' THEN 1 END) AS total_pg
    FROM paket_soal p
    JOIN mapel m ON p.id_mapel = m.id_mapel
    LEFT JOIN bank_soal b ON p.id_paket = b.id_paket
    WHERE 1=1
";
$params = [];
if ($currentUser['role'] === 'guru') {
    $sql .= " AND p.id_guru = :g";
    $params[':g'] = $idGuru;
}

if ($filterMapel) {
    $sql .= " AND p.id_mapel = :m";
    $params[':m'] = $filterMapel;
}

if ($search !== '') {
    $sql .= " AND (p.nama_paket ILIKE :s OR b.pertanyaan ILIKE :s)";
    $params[':s'] = "%{$search}%";
}

$sql .= " GROUP BY p.id_paket, p.nama_paket, p.id_mapel, p.created_at, m.nama_mapel, m.kode_mapel ORDER BY m.nama_mapel ASC, p.nama_paket ASC";
$stmtPaket = $db->prepare($sql);
$stmtPaket->execute($params);
$paketList = $stmtPaket->fetchAll();

// Hitung Statistik Dashboard Atas
$totalPaketCount = count($paketList);
$totalButirCount = 0;
$totalPgCount    = 0;
$totalEssaiCount = 0;
$totalPas100Count = 0;
$paketIds = [];

foreach ($paketList as $p) {
    $paketIds[] = (int)$p['id_paket'];
    $totalButirCount += (int)$p['total_butir'];
    $totalPgCount    += (int)$p['total_pg'];
    $totalEssaiCount += (int)$p['total_essai'];
    if (abs((float)$p['total_bobot'] - 100.0) < 0.1 && (int)$p['total_butir'] > 0) {
        $totalPas100Count++;
    }
}

// Ambil Seluruh Butir Soal untuk Accordion Pratinjau Cepat (Single Query)
$questionsByPaket = [];
if (!empty($paketIds)) {
    $placeholders = implode(',', array_fill(0, count($paketIds), '?'));
    $stmtQ = $db->prepare("
        SELECT id_soal, id_paket, jenis_soal, pertanyaan, gambar,
               opsi_a, opsi_b, opsi_c, opsi_d, opsi_e, kunci_jawaban, bobot_soal, konten_soal
        FROM bank_soal 
        WHERE id_paket IN ($placeholders)
        ORDER BY id_paket ASC, id_soal ASC
    ");
    $stmtQ->execute($paketIds);
    $rawQ = $stmtQ->fetchAll();
    foreach ($rawQ as $rq) {
        $questionsByPaket[$rq['id_paket']][] = $rq;
    }
}

// Kelompokkan Paket Berdasarkan Mata Pelajaran
$groupedPaket = [];
foreach ($paketList as $p) {
    $mapelKey = $p['id_mapel'];
    if (!isset($groupedPaket[$mapelKey])) {
        $groupedPaket[$mapelKey] = [
            'id_mapel'   => $p['id_mapel'],
            'nama_mapel' => $p['nama_mapel'],
            'kode_mapel' => $p['kode_mapel'],
            'items'      => []
        ];
    }
    $groupedPaket[$mapelKey]['items'][] = $p;
}

$flash = flash_get();
include __DIR__ . '/../layouts/header.php';
?>

<main class="container mb-5">
    <?php if ($flash): ?>
        <div class="alert alert-<?= sanitize($flash['type']) ?> mb-4">
            <?= sanitize($flash['message']) ?>
        </div>
    <?php endif; ?>

    <!-- Header & Aksi Utama -->
    <div class="flex-between mb-4 pb-2" style="border-bottom: 1px solid #e2e8f0; flex-wrap: wrap; gap: 1rem;">
        <div>
            <h1 style="font-size: 1.45rem; font-weight: 800; color: #0f172a; margin: 0; letter-spacing: -0.02em; display: flex; align-items: center; gap: 0.5rem;">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--primary);"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg>
                <span>Bank Soal Ujian</span>
            </h1>
            <p style="font-size: 0.85rem; color: #64748b; margin: 0.3rem 0 0;">
                Kelola paket soal asesmen, standarisasi bobot 100 poin, dan pratinjau butir soal terstruktur.
            </p>
        </div>
        <div class="flex gap-2" style="align-items: center; flex-wrap: wrap;">
            <a href="<?= base_url('guru?page=tambah_soal' . ($filterMapel ? '&id_mapel=' . $filterMapel : '')) ?>" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 0.4rem; font-weight: 600; padding: 0.5rem 1rem;">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                <span>Buat Paket Soal</span>
            </a>
            <a href="<?= base_url('guru?page=import_soal' . ($filterMapel ? '&id_mapel=' . $filterMapel : '')) ?>" class="btn btn-secondary" style="display: inline-flex; align-items: center; gap: 0.4rem; font-weight: 600; padding: 0.5rem 1rem;">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                <span>Import CSV</span>
            </a>
        </div>
    </div>

    <!-- Ringkasan Statistik (KPI Cards) -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
        <!-- Card 1: Total Paket -->
        <div class="card" style="margin-bottom: 0; padding: 1.15rem; border: 1px solid #e2e8f0; border-radius: 10px; background: #ffffff; display: flex; align-items: center; gap: 0.9rem;">
            <div style="width: 44px; height: 44px; border-radius: 8px; background: #eff6ff; color: #2563eb; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path></svg>
            </div>
            <div>
                <div style="font-size: 0.78rem; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.04em;">Total Paket</div>
                <div style="font-size: 1.35rem; font-weight: 800; color: #0f172a; line-height: 1.2;"><?= $totalPaketCount ?> <span style="font-size: 0.85rem; font-weight: 600; color: #64748b;">Paket</span></div>
            </div>
        </div>

        <!-- Card 2: Total Butir Pertanyaan -->
        <div class="card" style="margin-bottom: 0; padding: 1.15rem; border: 1px solid #e2e8f0; border-radius: 10px; background: #ffffff; display: flex; align-items: center; gap: 0.9rem;">
            <div style="width: 44px; height: 44px; border-radius: 8px; background: #f0fdf4; color: #16a34a; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
            </div>
            <div>
                <div style="font-size: 0.78rem; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.04em;">Total Soal</div>
                <div style="font-size: 1.35rem; font-weight: 800; color: #0f172a; line-height: 1.2;">
                    <?= $totalButirCount ?> <span style="font-size: 0.82rem; font-weight: 600; color: #64748b;">(<?= $totalPgCount ?> PG, <?= $totalEssaiCount ?> Essai)</span>
                </div>
            </div>
        </div>

        <!-- Card 3: Standarisasi 100 Poin -->
        <div class="card" style="margin-bottom: 0; padding: 1.15rem; border: 1px solid #e2e8f0; border-radius: 10px; background: #ffffff; display: flex; align-items: center; gap: 0.9rem;">
            <div style="width: 44px; height: 44px; border-radius: 8px; background: #faf5ff; color: #9333ea; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
            </div>
            <div>
                <div style="font-size: 0.78rem; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.04em;">Bobot 100 Poin</div>
                <div style="font-size: 1.35rem; font-weight: 800; color: #0f172a; line-height: 1.2;">
                    <?= $totalPas100Count ?> / <?= $totalPaketCount ?> <span style="font-size: 0.82rem; font-weight: 600; color: #16a34a;">Paket Ideal</span>
                </div>
            </div>
        </div>

        <!-- Card 4: Mata Pelajaran Terdata -->
        <div class="card" style="margin-bottom: 0; padding: 1.15rem; border: 1px solid #e2e8f0; border-radius: 10px; background: #ffffff; display: flex; align-items: center; gap: 0.9rem;">
            <div style="width: 44px; height: 44px; border-radius: 8px; background: #fff7ed; color: #ea580c; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"></path><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"></path></svg>
            </div>
            <div>
                <div style="font-size: 0.78rem; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.04em;">Mata Pelajaran</div>
                <div style="font-size: 1.35rem; font-weight: 800; color: #0f172a; line-height: 1.2;">
                    <?= count($groupedPaket) ?> <span style="font-size: 0.85rem; font-weight: 600; color: #64748b;">Mapel Aktif</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter & Pencarian Cepat -->
    <div class="card mb-4" style="padding: 1rem 1.25rem; border: 1px solid #e2e8f0; border-radius: 10px;">
        <form method="GET" action="<?= base_url('guru') ?>" style="display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap;">
            <input type="hidden" name="page" value="bank_soal">
            
            <div style="flex: 1; min-width: 240px; position: relative;">
                <input type="text" name="search" class="form-control" placeholder="Cari nama paket atau kata kunci butir soal..." value="<?= sanitize($search) ?>" style="padding-left: 2.25rem;">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="position: absolute; left: 0.75rem; top: 50%; transform: translateY(-50%); pointer-events: none;"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
            </div>

            <div style="min-width: 220px;">
                <select name="id_mapel" class="form-control" onchange="this.form.submit()">
                    <option value="">Semua Mata Pelajaran (<?= $totalSemuaPaket ?>)</option>
                    <?php foreach ($mapelList as $m): ?>
                        <option value="<?= $m['id_mapel'] ?>" <?= ($filterMapel == $m['id_mapel']) ? 'selected' : '' ?>>
                            <?= sanitize(format_mapel_name($m['nama_mapel'], $m['kode_mapel'])) ?> (<?= (int)$m['total_paket'] ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="flex gap-2">
                <button type="submit" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 0.35rem;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                    <span>Filter</span>
                </button>
                <?php if ($filterMapel || $search !== ''): ?>
                    <a href="<?= base_url('guru?page=bank_soal') ?>" class="btn btn-outline" style="color: #64748b;" title="Reset Filter">Reset</a>
                <?php endif; ?>
            </div>
        </form>

        <!-- Filter Pills Navigasi Mapel Cepat -->
        <div style="display: flex; gap: 0.45rem; flex-wrap: wrap; margin-top: 0.85rem; padding-top: 0.75rem; border-top: 1px dashed #e2e8f0;">
            <a href="<?= base_url('guru?page=bank_soal' . ($search !== '' ? '&search=' . urlencode($search) : '')) ?>" 
               style="text-decoration: none; padding: 0.3rem 0.7rem; border-radius: 20px; font-size: 0.8rem; font-weight: 600; display: inline-flex; align-items: center; gap: 0.3rem; <?= empty($filterMapel) ? 'background: var(--primary); color: #ffffff;' : 'background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1;' ?>">
                <span>Semua</span>
                <span style="font-size: 0.72rem; opacity: 0.9; background: rgba(0,0,0,0.12); padding: 0.05rem 0.35rem; border-radius: 10px;"><?= $totalSemuaPaket ?></span>
            </a>
            <?php foreach ($mapelList as $m): ?>
                <?php $isActive = ($filterMapel == $m['id_mapel']); ?>
                <a href="<?= base_url('guru?page=bank_soal&id_mapel=' . $m['id_mapel'] . ($search !== '' ? '&search=' . urlencode($search) : '')) ?>" 
                   style="text-decoration: none; padding: 0.3rem 0.75rem; border-radius: 20px; font-size: 0.8rem; font-weight: 600; display: inline-flex; align-items: center; gap: 0.3rem; <?= $isActive ? 'background: var(--primary); color: #ffffff;' : 'background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1;' ?>">
                    <span><?= sanitize($m['kode_mapel'] ?: $m['nama_mapel']) ?></span>
                    <span style="font-size: 0.72rem; opacity: 0.9; background: rgba(0,0,0,0.12); padding: 0.05rem 0.35rem; border-radius: 10px;"><?= (int)$m['total_paket'] ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- DAFTAR PAKET SOAL TERKELOMPOK -->
    <?php if (empty($groupedPaket)): ?>
        <div class="card text-center" style="padding: 3.5rem 1.5rem; border: 1px dashed #cbd5e1; border-radius: 12px;">
            <div style="width: 56px; height: 56px; margin: 0 auto 1rem; border-radius: 50%; background: #eff6ff; color: var(--primary); display: flex; align-items: center; justify-content: center;">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg>
            </div>
            <h3 style="font-size: 1.15rem; font-weight: 700; color: #1e293b; margin-bottom: 0.4rem;">
                Belum Ada Paket Soal <?= $filterMapel ? 'pada Mata Pelajaran Terpilih' : '' ?>
            </h3>
            <p style="font-size: 0.85rem; color: #64748b; max-width: 460px; margin: 0 auto 1.25rem;">
                <?= $search !== '' ? "Tidak ditemukan paket atau pertanyaan dengan kata kunci '{$search}'." : "Buat paket soal baru atau impor butir soal dari berkas CSV Excel untuk memulai." ?>
            </p>
            <div class="flex gap-2" style="justify-content: center;">
                <a href="<?= base_url('guru?page=tambah_soal' . ($filterMapel ? '&id_mapel=' . $filterMapel : '')) ?>" class="btn btn-primary">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                    <span>Buat Paket Soal Baru</span>
                </a>
                <a href="<?= base_url('guru?page=import_soal' . ($filterMapel ? '&id_mapel=' . $filterMapel : '')) ?>" class="btn btn-secondary">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                    <span>Import dari CSV</span>
                </a>
            </div>
        </div>
    <?php else: ?>
        <div style="display: flex; flex-direction: column; gap: 1.75rem;">
            <?php foreach ($groupedPaket as $grp): ?>
                <div class="group-mapel-section">
                    <!-- Header Kelompok Mapel -->
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.85rem; padding-bottom: 0.4rem; border-bottom: 2px solid #e2e8f0;">
                        <div style="display: flex; align-items: center; gap: 0.6rem;">
                            <span style="display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 6px; background: #e0e7ff; color: #3730a3;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"></path><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"></path></svg>
                            </span>
                            <h2 style="font-size: 1.12rem; font-weight: 800; color: #1e293b; margin: 0;">
                                <?= sanitize(format_mapel_name($grp['nama_mapel'], $grp['kode_mapel'])) ?>
                            </h2>
                            <span style="background: #f1f5f9; color: #475569; font-size: 0.75rem; font-weight: 700; padding: 0.2rem 0.55rem; border-radius: 12px; border: 1px solid #e2e8f0;">
                                <?= count($grp['items']) ?> Paket
                            </span>
                        </div>
                        <a href="<?= base_url('guru?page=tambah_soal&id_mapel=' . $grp['id_mapel']) ?>" class="btn btn-sm btn-outline" style="font-size: 0.78rem; padding: 0.25rem 0.6rem; display: inline-flex; align-items: center; gap: 0.25rem;">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                            <span>Tambah di Mapel Ini</span>
                        </a>
                    </div>

                    <!-- Kartu-kartu Paket dalam Mapel ini -->
                    <div style="display: flex; flex-direction: column; gap: 0.85rem;">
                        <?php foreach ($grp['items'] as $p): ?>
                            <?php 
                                $totBobot = (float)$p['total_bobot'];
                                $isPas100 = (abs($totBobot - 100.0) < 0.1 && (int)$p['total_butir'] > 0);
                                $qList = $questionsByPaket[$p['id_paket']] ?? [];
                            ?>
                            <div class="card" style="margin-bottom: 0; padding: 1.15rem 1.35rem; border: 1px solid #e2e8f0; border-radius: 10px; background: #ffffff; box-shadow: 0 1px 3px rgba(0,0,0,0.03); transition: box-shadow 0.2s ease;">
                                <!-- Baris Atas: Informasi Pokok & Tombol Aksi -->
                                <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
                                    <!-- Informasi Paket -->
                                    <div style="display: flex; align-items: flex-start; gap: 0.85rem; flex: 1; min-width: 280px;">
                                        <div style="width: 42px; height: 42px; border-radius: 8px; background: #eff6ff; color: #2563eb; display: flex; align-items: center; justify-content: center; flex-shrink: 0; margin-top: 2px;">
                                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path></svg>
                                        </div>
                                        <div>
                                            <div style="display: flex; align-items: center; gap: 0.6rem; flex-wrap: wrap;">
                                                <h3 style="font-size: 1.1rem; font-weight: 800; color: #0f172a; margin: 0; letter-spacing: -0.01em;">
                                                    <?= sanitize($p['nama_paket']) ?>
                                                </h3>
                                                <button type="button" class="btn-link" style="border: none; background: transparent; padding: 0; cursor: pointer; color: #94a3b8;" 
                                                        title="Ganti Nama Paket" 
                                                        onclick="openModalRename(<?= $p['id_paket'] ?>, '<?= sanitize(addslashes($p['nama_paket'])) ?>', <?= $p['id_mapel'] ?>)">
                                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                                </button>
                                            </div>

                                            <!-- Metadata Paket (Pills Rapi) -->
                                            <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; margin-top: 0.4rem; font-size: 0.8rem;">
                                                <!-- Total Butir & Jenis -->
                                                <span style="background: #f1f5f9; color: #334155; padding: 0.2rem 0.55rem; border-radius: 6px; font-weight: 700; display: inline-flex; align-items: center; gap: 0.3rem;">
                                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                                                    <span><?= (int)$p['total_butir'] ?> Butir</span>
                                                    <span style="font-weight: 500; color: #64748b;">(<?= (int)$p['total_pg'] ?> PG<?= (int)$p['total_essai'] > 0 ? ', ' . (int)$p['total_essai'] . ' Essai' : '' ?>)</span>
                                                </span>

                                                <!-- Status Bobot -->
                                                <?php if ($isPas100): ?>
                                                    <span style="background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; padding: 0.2rem 0.55rem; border-radius: 6px; font-weight: 700; display: inline-flex; align-items: center; gap: 0.3rem;" title="Total bobot soal tepat 100 poin (ideal untuk penilaian asesmen).">
                                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                                        <span>Bobot: 100 Poin (Pas)</span>
                                                    </span>
                                                <?php else: ?>
                                                    <span style="background: #fef3c7; color: #92400e; border: 1px solid #fde68a; padding: 0.2rem 0.55rem; border-radius: 6px; font-weight: 700; display: inline-flex; align-items: center; gap: 0.3rem;" title="Akumulasi bobot saat ini. Nilai akhir siswa tetap dikonversi ke skala 100.">
                                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                                                        <span>Bobot: <?= number_format($totBobot, 1) ?> Poin</span>
                                                    </span>
                                                <?php endif; ?>

                                                <!-- Tanggal Pembuatan -->
                                                <?php if (!empty($p['created_at'])): ?>
                                                    <span style="color: #94a3b8; display: inline-flex; align-items: center; gap: 0.25rem;">
                                                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                                                        <span><?= date('d M Y, H:i', strtotime($p['created_at'])) ?></span>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Toolbar Tombol Aksi Paket -->
                                    <div style="display: flex; align-items: center; gap: 0.45rem; flex-wrap: wrap;">
                                        <!-- Tombol Set ke 100 Poin Cepat -->
                                        <?php if (!$isPas100 && (int)$p['total_butir'] > 0): ?>
                                            <form action="<?= base_url('guru?page=bank_soal') ?>" method="POST" style="display:inline;" onsubmit="return confirm('Ratakan bobot <?= (int)$p['total_butir'] ?> butir soal di paket ini agar pas 100 Poin (@ <?= round(100.0 / (int)$p['total_butir'], 2) ?> poin/soal)?');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="ratakan_bobot">
                                                <input type="hidden" name="id_paket" value="<?= $p['id_paket'] ?>">
                                                <input type="hidden" name="id_mapel" value="<?= $p['id_mapel'] ?>">
                                                <button type="submit" class="btn btn-sm" style="background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; font-weight: 700; font-size: 0.78rem; display: inline-flex; align-items: center; gap: 0.3rem;" title="Bagi rata seluruh butir soal agar totalnya pas 100 poin">
                                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
                                                    <span>Set 100 Poin</span>
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <!-- Tombol Toggle Pratinjau Accordion -->
                                        <?php if (!empty($qList)): ?>
                                            <button type="button" class="btn btn-outline btn-sm" id="btn-preview-<?= $p['id_paket'] ?>" onclick="togglePreviewPaket(<?= $p['id_paket'] ?>)" style="font-size: 0.8rem; font-weight: 600; display: inline-flex; align-items: center; gap: 0.35rem; color: #334155; border-color: #cbd5e1;">
                                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                                                <span class="preview-text">Pratinjau Soal (<?= count($qList) ?>)</span>
                                            </button>
                                        <?php endif; ?>

                                        <!-- Tombol Edit Soal Lengkap -->
                                        <a href="<?= base_url('guru?page=tambah_soal&id_paket=' . $p['id_paket']) ?>" class="btn btn-primary btn-sm" style="font-size: 0.8rem; font-weight: 600; display: inline-flex; align-items: center; gap: 0.35rem;" title="Buka form editor untuk mengubah isi butir soal">
                                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                            <span>Kelola Soal</span>
                                        </a>

                                        <!-- Tombol Export CSV -->
                                        <a href="<?= base_url('guru?page=bank_soal&action=export_csv&id_paket=' . $p['id_paket']) ?>" class="btn btn-outline btn-sm" style="font-size: 0.8rem; padding: 0.35rem 0.55rem; color: #475569;" title="Unduh data paket soal dalam berkas CSV">
                                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                                        </a>

                                        <!-- Tombol Hapus Paket -->
                                        <form action="<?= base_url('guru?page=bank_soal') ?>" method="POST" style="display:inline;" 
                                              data-confirm="Apakah Anda yakin ingin menghapus SELURUH butir pertanyaan dalam paket '<?= sanitize($p['nama_paket']) ?>'?" 
                                              data-confirm-title="Hapus Paket Soal" 
                                              data-confirm-type="danger" 
                                              data-confirm-btn="Ya, Hapus Paket">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="hapus_paket">
                                            <input type="hidden" name="id_paket" value="<?= $p['id_paket'] ?>">
                                            <input type="hidden" name="id_mapel" value="<?= $p['id_mapel'] ?>">
                                            <button type="submit" class="btn btn-sm" style="background: transparent; color: #dc2626; border: 1px solid #fecaca; padding: 0.35rem 0.55rem;" title="Hapus seluruh paket dan butir soal">
                                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                                            </button>
                                        </form>
                                    </div>
                                </div>

                                <!-- Accordion Pratinjau Butir Soal -->
                                <?php if (!empty($qList)): ?>
                                    <div id="preview-paket-<?= $p['id_paket'] ?>" style="display: none; margin-top: 1.15rem; padding-top: 1.15rem; border-top: 1px dashed #cbd5e1;">
                                        <div style="font-weight: 700; color: #334155; font-size: 0.88rem; margin-bottom: 0.75rem; display: flex; align-items: center; justify-content: space-between;">
                                            <span>Daftar Butir Pertanyaan (<?= count($qList) ?> Butir)</span>
                                            <a href="<?= base_url('guru?page=tambah_soal&id_paket=' . $p['id_paket']) ?>" style="font-size: 0.78rem; color: var(--primary); text-decoration: none; font-weight: 600;">+ Tambah / Edit di Editor</a>
                                        </div>

                                        <div style="display: flex; flex-direction: column; gap: 0.65rem;">
                                            <?php foreach ($qList as $qIdx => $q): ?>
                                                <?php 
                                                    $isQEssai = in_array($q['jenis_soal'], ['essai', 'uraian'], true);
                                                    $isQMjdk  = ($q['jenis_soal'] === 'mjdk');
                                                    $isQIjs   = in_array($q['jenis_soal'], ['ijs', 'isian', 'jawaban_singkat'], true);
                                                    $kunciList = array_filter(array_map('trim', explode(',', $q['kunci_jawaban'] ?? '')));
                                                    $isQPGK   = ($q['jenis_soal'] === 'pgk_l1' || count($kunciList) > 1);

                                                    $kontenDecoded = null;
                                                    if (!empty($q['konten_soal'])) {
                                                        $kontenDecoded = is_array($q['konten_soal']) ? $q['konten_soal'] : json_decode((string)$q['konten_soal'], true);
                                                    }
                                                ?>
                                                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 0.85rem 1rem; font-size: 0.85rem;">
                                                    <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 0.75rem;">
                                                        <div style="display: flex; align-items: flex-start; gap: 0.6rem; flex: 1;">
                                                            <span style="font-weight: 800; color: #475569; min-width: 20px;"><?= $qIdx + 1 ?>.</span>
                                                            <div style="flex: 1;">
                                                                <div style="color: #1e293b; font-weight: 600; line-height: 1.45; margin-bottom: 0.4rem;">
                                                                    <?= nl2br(sanitize($q['pertanyaan'])) ?>
                                                                </div>

                                                                <!-- Pratinjau Gambar Jika Ada -->
                                                                <?php if (!empty($q['gambar'])): ?>
                                                                    <div style="margin-bottom: 0.5rem;">
                                                                        <img src="<?= base_url(ltrim($q['gambar'], '/')) ?>" alt="Gambar Soal" style="max-width: 220px; max-height: 120px; object-fit: contain; border-radius: 4px; border: 1px solid #cbd5e1;">
                                                                    </div>
                                                                <?php endif; ?>

                                                                <!-- Pilihan / Kunci Jawaban Berdasarkan Bentuk Soal -->
                                                                <?php if ($isQMjdk): ?>
                                                                    <!-- Bentuk Menjodohkan (MJDK) -->
                                                                    <?php 
                                                                        $premis = $kontenDecoded['premis'] ?? [];
                                                                        $pilihan = $kontenDecoded['pilihan'] ?? [];
                                                                        $kunciMap = $kontenDecoded['kunci'] ?? [];
                                                                        if (is_string($kunciMap)) {
                                                                            $kunciMap = json_decode($kunciMap, true) ?: [];
                                                                        }
                                                                        $pilihanById = [];
                                                                        foreach ($pilihan as $pil) {
                                                                            $pilihanById[$pil['id']] = $pil['teks'] ?? '';
                                                                        }
                                                                    ?>
                                                                    <div style="margin-top: 0.5rem; border: 1px solid #e2e8f0; border-radius: 6px; overflow: hidden; background: #ffffff;">
                                                                        <div style="background: #f8fafc; padding: 0.4rem 0.75rem; font-size: 0.76rem; font-weight: 700; color: #475569; display: grid; grid-template-columns: 1fr 28px 1fr; gap: 0.5rem; border-bottom: 1px solid #e2e8f0;">
                                                                            <span>Pokok Soal (Lajur Kiri)</span>
                                                                            <span style="text-align: center; color: #94a3b8;">➔</span>
                                                                            <span>Pasangan Jawaban (Kunci)</span>
                                                                        </div>
                                                                        <div style="display: flex; flex-direction: column;">
                                                                            <?php if (!empty($premis)): ?>
                                                                                <?php foreach ($premis as $pIdx => $pItem): 
                                                                                    $targetJId = $kunciMap[$pItem['id']] ?? null;
                                                                                    $targetTeks = $targetJId ? ($pilihanById[$targetJId] ?? '') : '';
                                                                                ?>
                                                                                    <div style="display: grid; grid-template-columns: 1fr 28px 1fr; gap: 0.5rem; align-items: center; padding: 0.4rem 0.75rem; font-size: 0.8rem; border-bottom: 1px solid #f1f5f9; <?= $pIdx % 2 === 1 ? 'background: #fafafa;' : '' ?>">
                                                                                        <span style="color: #1e293b; font-weight: 600;"><?= sanitize($pItem['teks']) ?></span>
                                                                                        <span style="text-align: center; color: #94a3b8; font-size: 0.75rem;">➔</span>
                                                                                        <span style="color: #166534; font-weight: 700; background: #dcfce7; padding: 0.15rem 0.45rem; border-radius: 4px; display: inline-block; width: fit-content;">
                                                                                            <?= sanitize($targetTeks ?: '-') ?>
                                                                                        </span>
                                                                                    </div>
                                                                                <?php endforeach; ?>
                                                                            <?php else: ?>
                                                                                <div style="padding: 0.5rem 0.75rem; font-size: 0.8rem; color: #64748b; font-style: italic;">
                                                                                    Format pasangan menjodohkan belum diatur.
                                                                                </div>
                                                                            <?php endif; ?>
                                                                        </div>
                                                                    </div>

                                                                <?php elseif ($isQIjs): ?>
                                                                    <!-- Bentuk Isian Singkat (IJS) -->
                                                                    <div style="margin-top: 0.4rem; font-size: 0.8rem; background: #eef2ff; border: 1px solid #c7d2fe; padding: 0.35rem 0.65rem; border-radius: 4px; color: #3730a3;">
                                                                        <strong>Kunci Jawaban Singkat:</strong> <?= !empty($q['kunci_jawaban']) ? sanitize($q['kunci_jawaban']) : '<em style="color:#a1a1aa;">(Belum diisi)</em>' ?>
                                                                    </div>

                                                                <?php elseif ($isQEssai): ?>
                                                                    <!-- Kata Kunci / Panduan Jawaban Essai -->
                                                                    <div style="margin-top: 0.4rem; font-size: 0.8rem; background: #fefce8; border: 1px solid #fef08a; padding: 0.35rem 0.65rem; border-radius: 4px; color: #854d0e;">
                                                                        <strong>Panduan Jawaban / Kata Kunci:</strong> <?= !empty($q['kunci_jawaban']) ? sanitize($q['kunci_jawaban']) : '<em style="color:#a1a1aa;">(Tidak ada panduan)</em>' ?>
                                                                    </div>

                                                                <?php else: ?>
                                                                    <!-- Pilihan Jawaban PG / PGK -->
                                                                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 0.35rem; margin-top: 0.45rem;">
                                                                        <?php 
                                                                            $opsiMap = ['A' => $q['opsi_a'], 'B' => $q['opsi_b'], 'C' => $q['opsi_c'], 'D' => $q['opsi_d'], 'E' => $q['opsi_e']];
                                                                            foreach ($opsiMap as $code => $txt):
                                                                                if ($txt === null || $txt === '') continue;
                                                                                $isKey = in_array($code, $kunciList, true);
                                                                        ?>
                                                                            <div style="padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.8rem; display: flex; align-items: center; gap: 0.4rem; <?= $isKey ? 'background: #dcfce7; color: #166534; font-weight: 700; border: 1px solid #86efac;' : 'background: #ffffff; color: #475569; border: 1px solid #e2e8f0;' ?>">
                                                                                <span style="display: inline-flex; width: 18px; height: 18px; border-radius: 50%; align-items: center; justify-content: center; font-size: 0.72rem; <?= $isKey ? 'background: #16a34a; color: #ffffff;' : 'background: #e2e8f0; color: #64748b;' ?>">
                                                                                    <?= $code ?>
                                                                                </span>
                                                                                <span style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?= sanitize($txt) ?></span>
                                                                                <?php if ($isKey): ?>
                                                                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" style="margin-left: auto; flex-shrink: 0;"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                                                                <?php endif; ?>
                                                                            </div>
                                                                        <?php endforeach; ?>
                                                                    </div>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>

                                                        <!-- Badge & Aksi Soal Satuan -->
                                                        <div style="display: flex; flex-direction: column; align-items: flex-end; gap: 0.35rem; flex-shrink: 0;">
                                                            <div style="display: flex; align-items: center; gap: 0.3rem;">
                                                                <span style="font-size: 0.72rem; font-weight: 700; padding: 0.15rem 0.45rem; border-radius: 4px; <?= $isQMjdk ? 'background: #dbeafe; color: #1e40af; border: 1px solid #bfdbfe;' : ($isQIjs ? 'background: #e0e7ff; color: #3730a3; border: 1px solid #c7d2fe;' : ($isQEssai ? 'background: #fef3c7; color: #92400e; border: 1px solid #fde68a;' : ($isQPGK ? 'background: #f3e8ff; color: #6b21a8; border: 1px solid #e9d5ff;' : 'background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe;'))) ?>">
                                                                    <?= $isQMjdk ? 'Menjodohkan' : ($isQIjs ? 'Isian' : ($isQEssai ? 'Essai' : ($isQPGK ? 'PGK' : 'PG'))) ?>
                                                                </span>
                                                                <span style="font-size: 0.72rem; font-weight: 700; background: #e2e8f0; color: #334155; padding: 0.15rem 0.45rem; border-radius: 4px;">
                                                                    <?= (float)$q['bobot_soal'] > 0 ? number_format((float)$q['bobot_soal'], 1) : '2.0' ?> Poin
                                                                </span>
                                                            </div>

                                                            <div style="display: flex; align-items: center; gap: 0.25rem;">
                                                                <a href="<?= base_url('guru?page=tambah_soal&id_paket=' . $p['id_paket'] . '&edit=' . $q['id_soal']) ?>" 
                                                                   class="btn-link" style="color: var(--primary); font-size: 0.75rem; text-decoration: none; padding: 0.15rem 0.35rem;" title="Edit Soal Ini">
                                                                    Edit
                                                                </a>
                                                                <span style="color: #cbd5e1;">|</span>
                                                                <form action="<?= base_url('guru?page=bank_soal') ?>" method="POST" style="display:inline;"
                                                                      data-confirm="Hapus butir pertanyaan ini dari paket?"
                                                                      data-confirm-title="Hapus Butir Soal"
                                                                      data-confirm-type="danger"
                                                                      data-confirm-btn="Ya, Hapus">
                                                                    <?= csrf_field() ?>
                                                                    <input type="hidden" name="action" value="hapus">
                                                                    <input type="hidden" name="id_soal" value="<?= $q['id_soal'] ?>">
                                                                    <input type="hidden" name="redirect_mapel" value="<?= $p['id_mapel'] ?>">
                                                                    <button type="submit" class="btn-link" style="border: none; background: transparent; color: #dc2626; font-size: 0.75rem; cursor: pointer; padding: 0.15rem 0.35rem;" title="Hapus Soal Ini">
                                                                        Hapus
                                                                    </button>
                                                                </form>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>

<!-- Modal Ganti Nama Paket Soal -->
<div id="modal-rename-paket" class="modal-overlay">
    <div class="modal-box" style="max-width: 460px;">
        <div class="modal-header">
            <h3 class="modal-title" style="font-size: 1.15rem; font-weight: 700; color: #0f172a;">Ganti Nama Paket Soal</h3>
            <button type="button" class="modal-close" onclick="closeModal('modal-rename-paket')">&times;</button>
        </div>
        <form action="<?= base_url('guru?page=bank_soal') ?>" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="rename_paket">
            <input type="hidden" name="id_paket" id="rename_id_paket" value="">
            <input type="hidden" name="id_mapel" id="rename_id_mapel" value="">

            <div class="modal-body" style="padding: 1.25rem 0;">
                <div class="form-group mb-2">
                    <label for="rename_new_judul" style="font-size: 0.85rem; font-weight: 600; color: #334155; margin-bottom: 0.35rem; display: block;">
                        Nama Paket Soal Baru <span class="text-danger">*</span>
                    </label>
                    <input type="text" name="new_judul" id="rename_new_judul" class="form-control" required placeholder="Contoh: Penilaian Harian 1" style="width: 100%;">
                </div>
                <p style="font-size: 0.78rem; color: #64748b; margin: 0.4rem 0 0;">
                    Perubahan nama ini akan langsung diperbarui di semua modul terkait.
                </p>
            </div>

            <div class="modal-footer" style="display: flex; justify-content: flex-end; gap: 0.5rem;">
                <button type="button" class="btn btn-outline" onclick="closeModal('modal-rename-paket')" style="font-size: 0.85rem;">Batal</button>
                <button type="submit" class="btn btn-primary" style="font-size: 0.85rem; font-weight: 600;">Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>

<script>
function togglePreviewPaket(idPaket) {
    const el = document.getElementById('preview-paket-' + idPaket);
    const btn = document.getElementById('btn-preview-' + idPaket);
    if (!el) return;

    const isHidden = (el.style.display === 'none' || el.style.display === '');
    el.style.display = isHidden ? 'block' : 'none';
    if (btn) {
        const textSpan = btn.querySelector('.preview-text');
        if (textSpan) {
            if (!btn.getAttribute('data-orig-text')) {
                btn.setAttribute('data-orig-text', textSpan.textContent);
            }
            textSpan.textContent = isHidden ? 'Tutup Pratinjau' : btn.getAttribute('data-orig-text');
        }
    }
}

function openModalRename(idPaket, currentTitle, idMapel) {
    document.getElementById('rename_id_paket').value = idPaket;
    document.getElementById('rename_new_judul').value = currentTitle;
    document.getElementById('rename_id_mapel').value = idMapel || '';
    if (typeof openModal === 'function') {
        openModal('modal-rename-paket');
    } else {
        const m = document.getElementById('modal-rename-paket');
        if (m) m.classList.add('active');
    }
}
</script>

<?php
include __DIR__ . '/../layouts/footer.php';
?>
