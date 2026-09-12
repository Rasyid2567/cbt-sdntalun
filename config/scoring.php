<?php
/**
 * ============================================================================
 * CBT SDN TALUN - SCORING ENGINE & EVALUATOR RESMI
 * ============================================================================
 * Mengatur standarisasi 7 bentuk soal CBT, pembobotan, rubrik, dan formula
 * penilaian otomatis sesuai panduan resmi asesmen sekolah:
 *
 * 1. PG-1      : Pilihan Ganda 1 Jawaban Benar (Default bobot: 2.00)
 * 2. PGK-L1   : Pilihan Ganda Kompleks > 1 Jawaban Benar (Default bobot: 3.00)
 * 3. PGK-BS-1  : Benar - Salah 1 Pernyataan (Default bobot: 1.00)
 * 4. PGK-BS-L1 : Benar - Salah > 1 Pernyataan (Default bobot: 6.00)
 * 5. MJDK      : Menjodohkan (Default bobot: 6.00)
 * 6. IJS       : Isian / Jawaban Singkat (Default bobot: 5.00)
 * 7. URAIAN    : Uraian / Esai (Default bobot: 7.00)
 *
 * Total Akumulasi Skor Standar (30 butir soal) = 100.00
 */

if (!defined('CBT_SOAL_TYPES')) {
    define('CBT_SOAL_TYPES', [
        'pg_1' => [
            'code'          => 'pg_1',
            'alias'         => ['pg', 'pilihan_ganda'],
            'short_label'   => 'PG-1',
            'name'          => 'Pilihan Ganda (1 Jawaban Benar)',
            'default_bobot' => 2.00,
            'is_auto_grade' => true,
        ],
        'pgk_l1' => [
            'code'          => 'pgk_l1',
            'alias'         => ['pilihan_ganda_kompleks', 'pgk'],
            'short_label'   => 'PGK-L1',
            'name'          => 'Pilihan Ganda Kompleks (> 1 Jawaban Benar)',
            'default_bobot' => 3.00,
            'is_auto_grade' => true,
        ],
        'pgk_bs_1' => [
            'code'          => 'pgk_bs_1',
            'alias'         => ['bs_1', 'benar_salah_1'],
            'short_label'   => 'PGK-BS-1',
            'name'          => 'Benar / Salah (1 Pernyataan)',
            'default_bobot' => 1.00,
            'is_auto_grade' => true,
        ],
        'pgk_bs_l1' => [
            'code'          => 'pgk_bs_l1',
            'alias'         => ['bs_l1', 'benar_salah_kompleks'],
            'short_label'   => 'PGK-BS-L1',
            'name'          => 'Benar / Salah (> 1 Pernyataan)',
            'default_bobot' => 6.00,
            'is_auto_grade' => true,
        ],
        'mjdk' => [
            'code'          => 'mjdk',
            'alias'         => ['jodohkan', 'menjodohkan'],
            'short_label'   => 'MJDK',
            'name'          => 'Menjodohkan',
            'default_bobot' => 6.00,
            'is_auto_grade' => true,
        ],
        'ijs' => [
            'code'          => 'ijs',
            'alias'         => ['isian', 'jawaban_singkat', 'isian_singkat'],
            'short_label'   => 'IJS',
            'name'          => 'Isian / Jawaban Singkat',
            'default_bobot' => 5.00,
            'is_auto_grade' => true,
        ],
        'uraian' => [
            'code'          => 'uraian',
            'alias'         => ['essai', 'essay'],
            'short_label'   => 'Uraian',
            'name'          => 'Uraian / Esai',
            'default_bobot' => 7.00,
            'is_auto_grade' => false,
        ],
    ]);
}

/**
 * Normalisasi kode jenis soal menjadi standar kanonikal internal
 */
function cbt_normalize_jenis_soal(?string $rawJenis, ?string $kunciJawaban = null): string {
    $clean = strtolower(trim((string)$rawJenis));
    
    // Auto-detect untuk backward compatibility
    if ($clean === '' || $clean === 'pilihan_ganda' || $clean === 'pg') {
        if ($kunciJawaban !== null) {
            $kunciArr = array_filter(array_map('trim', explode(',', $kunciJawaban)));
            if (count($kunciArr) > 1) {
                return 'pgk_l1';
            }
        }
        return 'pg_1';
    }

    if ($clean === 'essai' || $clean === 'essay') {
        return 'uraian';
    }

    foreach (CBT_SOAL_TYPES as $key => $meta) {
        if ($clean === $key || in_array($clean, $meta['alias'], true)) {
            return $key;
        }
    }

    return 'pg_1';
}

/**
 * Dapatkan informasi metadata jenis soal
 */
function cbt_get_soal_meta(string $jenis, ?string $kunci = null): array {
    $norm = cbt_normalize_jenis_soal($jenis, $kunci);
    return CBT_SOAL_TYPES[$norm] ?? CBT_SOAL_TYPES['pg_1'];
}

/**
 * Dapatkan bobot default berdasarkan jenis soal
 */
function cbt_get_default_bobot(string $jenis, ?string $kunci = null): float {
    $meta = cbt_get_soal_meta($jenis, $kunci);
    return (float)$meta['default_bobot'];
}

/**
 * Normalisasi teks jawaban isian singkat (IJS)
 */
function cbt_normalize_text_ijs(string $str): string {
    $str = mb_strtolower(trim($str), 'UTF-8');
    $str = trim($str, " \t\n\r\0\x0B.,!?:;\"'()[]{}");
    $str = preg_replace('/\s+/', ' ', $str);
    return $str;
}

/**
 * Evaluasi skor dan status butir soal berdasarkan rubrik resmi SDN Talun
 *
 * @param array $soal Data baris butir soal dari bank_soal
 * @param string|null $jawabanSiswa Jawaban yang disimpan di jawaban_siswa
 * @param float|int|null $nilaiManualGuru Nilai koreksi manual jika soal bertipe uraian
 * @return array Hasil evaluasi terperinci
 */
function cbt_evaluasi_soal(array $soal, ?string $jawabanSiswa, $nilaiManualGuru = null): array {
    $jenisKanonik = cbt_normalize_jenis_soal($soal['jenis_soal'] ?? 'pg_1', $soal['kunci_jawaban'] ?? null);
    $meta         = CBT_SOAL_TYPES[$jenisKanonik];

    // Tentukan Bobot Maksimal Soal
    $bobotMax = (isset($soal['bobot_soal']) && $soal['bobot_soal'] !== null && is_numeric($soal['bobot_soal']))
        ? (float)$soal['bobot_soal']
        : (float)$meta['default_bobot'];

    if ($bobotMax <= 0) {
        $bobotMax = (float)$meta['default_bobot'];
    }

    $rawJwb   = trim((string)$jawabanSiswa);
    $rawKunci = trim((string)($soal['kunci_jawaban'] ?? ''));
    
    // Parse konten terstruktur jika ada (JSONB)
    $kontenSoal = null;
    if (!empty($soal['konten_soal'])) {
        $kontenSoal = is_array($soal['konten_soal']) 
            ? $soal['konten_soal'] 
            : json_decode((string)$soal['konten_soal'], true);
    }

    $hasil = [
        'jenis'         => $jenisKanonik,
        'short_label'   => $meta['short_label'],
        'nama_jenis'    => $meta['name'],
        'bobot_max'     => $bobotMax,
        'skor'          => 0.00,
        'is_correct'    => false,
        'is_partial'    => false,
        'is_empty'      => ($rawJwb === ''),
        'status_label'  => 'Salah',
        'keterangan'    => '',
        'detail'        => []
    ];

    // Jika siswa tidak menjawab sama sekali
    if ($rawJwb === '') {
        $hasil['skor'] = 0.00;
        $hasil['status_label'] = 'Kosong';
        $hasil['keterangan'] = 'Siswa tidak memberikan jawaban untuk butir soal ini.';
        return $hasil;
    }

    // =========================================================================
    // 1. PG-1: Pilihan Ganda 1 Jawaban Benar
    // =========================================================================
    if ($jenisKanonik === 'pg_1') {
        $jwbCode   = strtoupper($rawJwb);
        $kunciCode = strtoupper($rawKunci);

        if ($kunciCode !== '' && $jwbCode === $kunciCode) {
            $hasil['skor']         = $bobotMax;
            $hasil['is_correct']   = true;
            $hasil['status_label'] = 'Benar';
            $hasil['keterangan']   = "Jawaban {$jwbCode} tepat sesuai kunci.";
        } else {
            $hasil['skor']         = 0.00;
            $hasil['is_correct']   = false;
            $hasil['status_label'] = 'Salah';
            $hasil['keterangan']   = "Jawaban {$jwbCode}, kunci yang benar adalah {$kunciCode}.";
        }
        return $hasil;
    }

    // =========================================================================
    // 2. PGK-L1: Pilihan Ganda Kompleks Lebih dari 1 Jawaban Benar
    // Formula resmi: max(0, (C - W) * (BobotMax / N_kunci))
    // =========================================================================
    if ($jenisKanonik === 'pgk_l1') {
        $kunciArr = array_values(array_unique(array_filter(array_map('trim', explode(',', strtoupper($rawKunci))))));
        $jwbArr   = array_values(array_unique(array_filter(array_map('trim', explode(',', strtoupper($rawJwb))))));

        $nKunci = count($kunciArr);
        if ($nKunci <= 0) {
            $nKunci = 1;
        }

        $correctPicks = array_values(array_intersect($jwbArr, $kunciArr));
        $wrongPicks   = array_values(array_diff($jwbArr, $kunciArr));

        $c = count($correctPicks);
        $w = count($wrongPicks);

        $nilaiPerItem = $bobotMax / $nKunci;
        $rawScore     = ($c - $w) * $nilaiPerItem;
        $finalScore   = max(0.00, round($rawScore, 2));
        if ($finalScore > $bobotMax) {
            $finalScore = $bobotMax;
        }

        $hasil['skor']   = $finalScore;
        $hasil['detail'] = [
            'kunci'         => $kunciArr,
            'jawaban_siswa' => $jwbArr,
            'benar_dipilih' => $correctPicks,
            'salah_dipilih' => $wrongPicks,
            'c'             => $c,
            'w'             => $w,
            'n_kunci'       => $nKunci,
            'skor_per_opsi' => round($nilaiPerItem, 2)
        ];

        if ($finalScore >= $bobotMax && $w === 0 && $c === $nKunci) {
            $hasil['is_correct']   = true;
            $hasil['status_label'] = 'Benar Sempurna';
            $hasil['keterangan']   = "Memilih {$c} opsi benar tanpa opsi salah. Skor maksimal ({$finalScore} / {$bobotMax}).";
        } elseif ($finalScore > 0) {
            $hasil['is_partial']   = true;
            $hasil['status_label'] = 'Sebagian Benar';
            $hasil['keterangan']   = "Memilih {$c} opsi benar dan {$w} opsi salah. Skor: ({$c} - {$w}) × " . round($nilaiPerItem, 2) . " = {$finalScore} / {$bobotMax}.";
        } else {
            $hasil['is_correct']   = false;
            $hasil['status_label'] = 'Salah';
            $hasil['keterangan']   = "Memilih {$c} opsi benar dan {$w} opsi salah. Skor dipotong penalti menjadi 0 / {$bobotMax}.";
        }
        return $hasil;
    }

    // =========================================================================
    // 3. PGK-BS-1: Benar - Salah 1 Pernyataan
    // =========================================================================
    if ($jenisKanonik === 'pgk_bs_1') {
        $normalizeBS = function($val) {
            $v = strtoupper(trim((string)$val));
            if ($v === 'BENAR' || $v === 'B' || $v === 'TRUE' || $v === '1') return 'B';
            if ($v === 'SALAH' || $v === 'S' || $v === 'FALSE' || $v === '0') return 'S';
            return $v;
        };

        $jwbBS   = $normalizeBS($rawJwb);
        $kunciBS = $normalizeBS($rawKunci);

        if ($kunciBS !== '' && $jwbBS === $kunciBS) {
            $hasil['skor']         = $bobotMax;
            $hasil['is_correct']   = true;
            $hasil['status_label'] = 'Benar';
            $hasil['keterangan']   = "Pilihan pernyataan ({$jwbBS}) tepat sesuai kunci.";
        } else {
            $hasil['skor']         = 0.00;
            $hasil['is_correct']   = false;
            $hasil['status_label'] = 'Salah';
            $hasil['keterangan']   = "Pilihan siswa ({$jwbBS}), kunci yang benar adalah ({$kunciBS}).";
        }
        return $hasil;
    }

    // =========================================================================
    // 4. PGK-BS-L1: Benar - Salah Lebih dari 1 Pernyataan (3 - 6 pernyataan)
    // Formula resmi: (Jumlah pernyataan benar / Jumlah seluruh pernyataan) * BobotMax
    // =========================================================================
    if ($jenisKanonik === 'pgk_bs_l1') {
        $items = [];
        if (!empty($kontenSoal['pernyataan']) && is_array($kontenSoal['pernyataan'])) {
            $items = $kontenSoal['pernyataan'];
        } elseif (!empty($rawKunci)) {
            $rawKunciParts = explode(',', $rawKunci);
            foreach ($rawKunciParts as $ki => $kv) {
                $items[] = [
                    'id'         => (string)$ki,
                    'pernyataan' => 'Pernyataan ' . ($ki + 1),
                    'kunci'      => trim($kv)
                ];
            }
        }

        $totalItems = count($items);
        if ($totalItems <= 0) {
            $totalItems = 1;
        }

        $jwbMap = [];
        $decoded = json_decode($rawJwb, true);
        if (is_array($decoded)) {
            $jwbMap = $decoded;
        } else {
            $parts = explode(',', $rawJwb);
            foreach ($parts as $pi => $pv) {
                $jwbMap[$pi] = trim($pv);
            }
        }

        $normalizeBS = function($val) {
            $v = strtoupper(trim((string)$val));
            if ($v === 'BENAR' || $v === 'B' || $v === 'TRUE' || $v === '1') return 'B';
            if ($v === 'SALAH' || $v === 'S' || $v === 'FALSE' || $v === '0') return 'S';
            return $v;
        };

        $benarCount = 0;
        $evalRows   = [];

        foreach ($items as $idx => $it) {
            $keyId      = (string)($it['id'] ?? $idx);
            $kunciVal   = $normalizeBS($it['kunci'] ?? ($it['kunci_jawaban'] ?? ''));
            $siswaVal   = $normalizeBS($jwbMap[$keyId] ?? ($jwbMap[$idx] ?? ''));
            $isRowRight = ($kunciVal !== '' && $siswaVal === $kunciVal);

            if ($isRowRight) {
                $benarCount++;
            }

            $evalRows[] = [
                'index'      => $idx + 1,
                'pernyataan' => $it['pernyataan'] ?? ('Pernyataan ' . ($idx + 1)),
                'kunci'      => $kunciVal,
                'siswa'      => $siswaVal,
                'is_correct' => $isRowRight
            ];
        }

        $finalScore = round(($benarCount / $totalItems) * $bobotMax, 2);
        $hasil['skor']   = $finalScore;
        $hasil['detail'] = [
            'total_pernyataan' => $totalItems,
            'benar_count'      => $benarCount,
            'rows'             => $evalRows
        ];

        if ($benarCount === $totalItems) {
            $hasil['is_correct']   = true;
            $hasil['status_label'] = 'Benar Sempurna';
            $hasil['keterangan']   = "Semua {$totalItems} pernyataan dijawab benar ({$finalScore} / {$bobotMax}).";
        } elseif ($benarCount > 0) {
            $hasil['is_partial']   = true;
            $hasil['status_label'] = 'Sebagian Benar';
            $hasil['keterangan']   = "Menjawab {$benarCount} dari {$totalItems} pernyataan dengan benar ({$finalScore} / {$bobotMax}).";
        } else {
            $hasil['is_correct']   = false;
            $hasil['status_label'] = 'Salah';
            $hasil['keterangan']   = "Tidak ada pernyataan yang tepat (0 / {$bobotMax}).";
        }
        return $hasil;
    }

    // =========================================================================
    // 5. MJDK: Menjodohkan (Matching)
    // Formula resmi: (Jumlah pasangan benar / Jumlah seluruh pokok soal) * BobotMax
    // =========================================================================
    if ($jenisKanonik === 'mjdk') {
        $premisList  = $kontenSoal['premis'] ?? [];
        $kunciPasang = $kontenSoal['kunci'] ?? [];

        if (empty($premisList) && !empty($rawKunci)) {
            $decodedKunci = json_decode($rawKunci, true);
            if (is_array($decodedKunci)) {
                $kunciPasang = $decodedKunci;
                foreach (array_keys($kunciPasang) as $kIdx => $kKey) {
                    $premisList[] = ['id' => (string)$kKey, 'teks' => 'Pokok Soal ' . ($kIdx + 1)];
                }
            }
        }

        $totalPokok = count($premisList);
        if ($totalPokok <= 0) {
            $totalPokok = count($kunciPasang) > 0 ? count($kunciPasang) : 1;
        }

        $jwbPairs = [];
        $decoded = json_decode($rawJwb, true);
        if (is_array($decoded)) {
            $jwbPairs = $decoded;
        }

        $benarCount = 0;
        $pairRows   = [];

        foreach ($premisList as $idx => $p) {
            $pId = (string)($p['id'] ?? $idx);
            $kunciTgt = (string)($kunciPasang[$pId] ?? ($kunciPasang[$idx] ?? ''));
            $siswaTgt = (string)($jwbPairs[$pId] ?? ($jwbPairs[$idx] ?? ''));

            $isMatch = ($kunciTgt !== '' && trim(strtoupper($kunciTgt)) === trim(strtoupper($siswaTgt)));
            if ($isMatch) {
                $benarCount++;
            }

            $pairRows[] = [
                'index'      => $idx + 1,
                'premis'     => $p['teks'] ?? ('Pokok Soal ' . ($idx + 1)),
                'kunci'      => $kunciTgt,
                'siswa'      => $siswaTgt,
                'is_correct' => $isMatch
            ];
        }

        $finalScore = round(($benarCount / $totalPokok) * $bobotMax, 2);
        $hasil['skor']   = $finalScore;
        $hasil['detail'] = [
            'total_pokok' => $totalPokok,
            'benar_count' => $benarCount,
            'rows'        => $pairRows
        ];

        if ($benarCount === $totalPokok) {
            $hasil['is_correct']   = true;
            $hasil['status_label'] = 'Benar Sempurna';
            $hasil['keterangan']   = "Semua {$totalPokok} pasangan pokok soal dijodohkan dengan benar ({$finalScore} / {$bobotMax}).";
        } elseif ($benarCount > 0) {
            $hasil['is_partial']   = true;
            $hasil['status_label'] = 'Sebagian Benar';
            $hasil['keterangan']   = "Menjodohkan {$benarCount} dari {$totalPokok} pasangan dengan benar ({$finalScore} / {$bobotMax}).";
        } else {
            $hasil['is_correct']   = false;
            $hasil['status_label'] = 'Salah';
            $hasil['keterangan']   = "Tidak ada pasangan yang dijodohkan dengan benar (0 / {$bobotMax}).";
        }
        return $hasil;
    }

    // =========================================================================
    // 6. IJS: Isian / Jawaban Singkat (1-2 kata atau angka)
    // =========================================================================
    if ($jenisKanonik === 'ijs') {
        $normSiswa = cbt_normalize_text_ijs($rawJwb);
        $alternatifKunci = array_filter(array_map('cbt_normalize_text_ijs', preg_split('/[\|\/]+/', $rawKunci)));

        $isMatch = false;
        foreach ($alternatifKunci as $alt) {
            if ($normSiswa !== '' && $normSiswa === $alt) {
                $isMatch = true;
                break;
            }
        }

        if ($isMatch) {
            $hasil['skor']         = $bobotMax;
            $hasil['is_correct']   = true;
            $hasil['status_label'] = 'Benar';
            $hasil['keterangan']   = "Jawaban singkat '{$rawJwb}' tepat sesuai kunci.";
        } else {
            $hasil['skor']         = 0.00;
            $hasil['is_correct']   = false;
            $hasil['status_label'] = 'Salah';
            $hasil['keterangan']   = "Jawaban singkat '{$rawJwb}', kunci yang diharapkan adalah '{$rawKunci}'.";
        }
        return $hasil;
    }

    // =========================================================================
    // 7. URAIAN: Uraian / Esai (Penilaian Manual Guru dengan batas 0 s/d BobotMax)
    // =========================================================================
    if ($jenisKanonik === 'uraian') {
        if ($nilaiManualGuru !== null && $nilaiManualGuru !== '' && is_numeric($nilaiManualGuru)) {
            $cleanVal = max(0.00, min($bobotMax, (float)$nilaiManualGuru));
            $hasil['skor']         = $cleanVal;
            $hasil['is_correct']   = ($cleanVal >= $bobotMax);
            $hasil['is_partial']   = ($cleanVal > 0 && $cleanVal < $bobotMax);
            $hasil['status_label'] = ($cleanVal >= $bobotMax) ? 'Dinilai Penuh' : (($cleanVal > 0) ? 'Dinilai Sebagian' : 'Dinilai 0');
            $hasil['keterangan']   = "Nilai guru: {$cleanVal} / {$bobotMax}.";
        } else {
            $hasil['skor']         = 0.00;
            $hasil['status_label'] = 'Menunggu Koreksi';
            $hasil['keterangan']   = "Jawaban uraian telah dikirim siswa, menunggu pemeriksaan guru (Skala: 0 - {$bobotMax}).";
        }
        return $hasil;
    }

    return $hasil;
}

/**
 * Hitung seluruh rekapitulasi nilai ujian seorang siswa (Skala Akumulasi Bobot & Skala 100)
 */
function cbt_hitung_rekap_ujian(int $idUjianSiswa, PDO $db, ?array $manualScores = null): array {
    $stmtUs = $db->prepare("SELECT id_ujian_siswa, id_sesi, urutan_soal FROM ujian_siswa WHERE id_ujian_siswa = :id");
    $stmtUs->execute([':id' => $idUjianSiswa]);
    $usRow = $stmtUs->fetch();
    if (!$usRow) {
        return ['error' => 'Data ujian siswa tidak ditemukan'];
    }

    $urutanIds = json_decode($usRow['urutan_soal'], true) ?: [];
    if (empty($urutanIds)) {
        return ['error' => 'Urutan butir soal kosong'];
    }

    $placeholders = implode(',', array_fill(0, count($urutanIds), '?'));

    $stmtSoal = $db->prepare("
        SELECT b.id_soal, b.jenis_soal, b.kunci_jawaban, b.bobot_soal, b.konten_soal,
               js.jawaban_terpilih, js.nilai_soal
        FROM bank_soal b
        LEFT JOIN jawaban_siswa js ON (js.id_soal = b.id_soal AND js.id_ujian_siswa = ?)
        WHERE b.id_soal IN ($placeholders)
    ");
    $stmtSoal->execute(array_merge([$idUjianSiswa], $urutanIds));
    $rawSoal = $stmtSoal->fetchAll();

    $soalMap = [];
    foreach ($rawSoal as $s) {
        $soalMap[$s['id_soal']] = $s;
    }

    $totalSkorMaksimal = 0.00;
    $totalSkorDiperoleh = 0.00;
    $jumlahBenarSempurna = 0;
    $totalButir = count($urutanIds);
    $adaUraianBelumDinilai = false;
    $evaluasiList = [];

    $stmtUpdJawaban = $db->prepare("
        INSERT INTO jawaban_siswa (id_ujian_siswa, id_soal, nilai_soal, updated_at)
        VALUES (:us, :soal, :nilai, CURRENT_TIMESTAMP)
        ON CONFLICT (id_ujian_siswa, id_soal)
        DO UPDATE SET nilai_soal = EXCLUDED.nilai_soal, updated_at = CURRENT_TIMESTAMP
    ");

    foreach ($urutanIds as $sid) {
        if (!isset($soalMap[$sid])) continue;
        $sRow = $soalMap[$sid];

        $manualVal = $manualScores[$sid] ?? $sRow['nilai_soal'];
        $eval = cbt_evaluasi_soal($sRow, $sRow['jawaban_terpilih'], $manualVal);

        $totalSkorMaksimal += $eval['bobot_max'];
        $totalSkorDiperoleh += $eval['skor'];

        if ($eval['is_correct']) {
            $jumlahBenarSempurna++;
        }

        if ($eval['jenis'] === 'uraian' && !$eval['is_empty'] && $manualVal === null) {
            $adaUraianBelumDinilai = true;
        }

        $stmtUpdJawaban->execute([
            ':us'    => $idUjianSiswa,
            ':soal'  => $sid,
            ':nilai' => $eval['skor']
        ]);

        $evaluasiList[$sid] = $eval;
    }

    if ($totalSkorMaksimal > 0) {
        $nilaiAkhir = round(($totalSkorDiperoleh / $totalSkorMaksimal) * 100, 2);
    } else {
        $nilaiAkhir = 0.00;
    }

    // Update langsung ke ujian_siswa
    $stmtUpdUs = $db->prepare("
        UPDATE ujian_siswa 
        SET jumlah_benar   = :benar,
            total_skor     = :tot_skor,
            skor_maksimal  = :tot_max,
            nilai_akhir    = :nilai,
            nilai_pg       = :tot_skor
        WHERE id_ujian_siswa = :us
    ");
    $stmtUpdUs->execute([
        ':benar'    => $jumlahBenarSempurna,
        ':tot_skor' => round($totalSkorDiperoleh, 2),
        ':tot_max'  => round($totalSkorMaksimal, 2),
        ':nilai'    => $nilaiAkhir,
        ':us'       => $idUjianSiswa
    ]);

    return [
        'total_butir'            => $totalButir,
        'jumlah_benar'           => $jumlahBenarSempurna,
        'total_skor_diperoleh'   => round($totalSkorDiperoleh, 2),
        'total_skor_maksimal'    => round($totalSkorMaksimal, 2),
        'nilai_akhir'            => $nilaiAkhir,
        'ada_uraian_pending'     => $adaUraianBelumDinilai,
        'evaluasi'               => $evaluasiList
    ];
}
