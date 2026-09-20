<?php
/**
 * CBT SDN Talun - Database Migration CLI Tool
 *
 * Jalankan perintah ini di terminal jika melakukan update struktur database baru:
 *   php migrate.php
 */

if (php_sapi_name() !== "cli") {
    die("Akses ditolak: Skrip ini hanya dapat dijalankan via CLI / terminal.\n");
}

require_once __DIR__ . "/config/database.php";

echo "==================================================\n";
echo " CBT SDN TALUN - DATABASE MIGRATION TOOL\n";
echo "==================================================\n";
echo "Menghubungkan ke database...\n";

$db = get_db();

try {
    echo "Menjalankan migrasi skema tabel dan indeks...\n";
    $db->exec("
        -- 1. Buat tabel paket_soal jika belum ada
        CREATE TABLE IF NOT EXISTS paket_soal (
            id_paket SERIAL PRIMARY KEY,
            id_guru INT NOT NULL REFERENCES users(id_user) ON DELETE CASCADE,
            id_mapel INT NOT NULL REFERENCES mapel(id_mapel) ON DELETE CASCADE,
            nama_paket VARCHAR(150) NOT NULL,
            deskripsi TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        -- 1b. Tambah kolom nip, status_akun, no_hp, orang_tua, no_hp_ortu di tabel users jika belum ada
        ALTER TABLE users ADD COLUMN IF NOT EXISTS nip VARCHAR(30) NULL;
        ALTER TABLE users ADD COLUMN IF NOT EXISTS status_akun VARCHAR(20) DEFAULT 'aktif';
        ALTER TABLE users ADD COLUMN IF NOT EXISTS no_hp VARCHAR(30) NULL;
        ALTER TABLE users ADD COLUMN IF NOT EXISTS orang_tua VARCHAR(100) NULL;
        ALTER TABLE users ADD COLUMN IF NOT EXISTS no_hp_ortu VARCHAR(30) NULL;

        ALTER TABLE mapel ADD COLUMN IF NOT EXISTS urutan INT DEFAULT 0;

        -- 2. Tambah kolom id_paket di bank_soal dan sesi_ujian
        ALTER TABLE bank_soal ADD COLUMN IF NOT EXISTS id_paket INT REFERENCES paket_soal(id_paket) ON DELETE CASCADE;
        ALTER TABLE sesi_ujian ADD COLUMN IF NOT EXISTS id_paket INT REFERENCES paket_soal(id_paket) ON DELETE SET NULL;
        ALTER TABLE sesi_ujian ALTER COLUMN token_ujian TYPE VARCHAR(20);
        ALTER TABLE jawaban_siswa ADD COLUMN IF NOT EXISTS nilai_soal NUMERIC(5,2) DEFAULT NULL;
        ALTER TABLE ujian_siswa ADD COLUMN IF NOT EXISTS nilai_pg NUMERIC(5,2) DEFAULT NULL;
        ALTER TABLE ujian_siswa ADD COLUMN IF NOT EXISTS nilai_essai NUMERIC(5,2) DEFAULT NULL;
        ALTER TABLE bank_soal ADD COLUMN IF NOT EXISTS bobot_soal NUMERIC(5,2) DEFAULT NULL;
        ALTER TABLE bank_soal ADD COLUMN IF NOT EXISTS konten_soal JSONB DEFAULT NULL;
        ALTER TABLE bank_soal ALTER COLUMN jenis_soal TYPE VARCHAR(30);
        ALTER TABLE ujian_siswa ADD COLUMN IF NOT EXISTS total_skor NUMERIC(6,2) DEFAULT NULL;
        ALTER TABLE ujian_siswa ADD COLUMN IF NOT EXISTS skor_maksimal NUMERIC(6,2) DEFAULT NULL;

        -- 3. Migrasi data lama dari bank_soal ke paket_soal jika kolom judul_soal masih ada
        DO \$\$
        BEGIN
            IF EXISTS (
                SELECT 1 FROM information_schema.columns 
                WHERE table_name = 'bank_soal' AND column_name = 'judul_soal'
            ) THEN
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

                UPDATE bank_soal b
                SET id_paket = p.id_paket
                FROM paket_soal p
                WHERE b.id_paket IS NULL
                  AND b.id_guru = p.id_guru 
                  AND b.id_mapel = p.id_mapel 
                  AND COALESCE(NULLIF(TRIM(b.judul_soal), ''), 'Latihan Soal') = p.nama_paket;
            END IF;

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
        CREATE INDEX IF NOT EXISTS idx_users_status_akun ON users(status_akun);
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

    echo "✓ Sukses: Semua migrasi skema dan indeks berhasil diaplikasikan!\n";
} catch (Throwable $e) {
    echo "✗ Gagal migrasi: " . $e->getMessage() . "\n";
    exit(1);
}
