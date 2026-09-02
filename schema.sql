-- ====================================================================
-- SKRIP DATABASE POSTGRESQL - COMPUTER BASED TEST (CBT) SYSTEM
-- Versi: 1.0 (Kompatibel dengan PostgreSQL 14.x / 15.x / 16.x)
-- ====================================================================

-- 1. Hapus Tipe ENUM & Tabel Jika Sudah Ada (Untuk Fresh Import)
DROP TABLE IF EXISTS jawaban_siswa CASCADE;
DROP TABLE IF EXISTS ujian_siswa CASCADE;
DROP TABLE IF EXISTS sesi_ujian CASCADE;
DROP TABLE IF EXISTS bank_soal CASCADE;
DROP TABLE IF EXISTS paket_soal CASCADE;
DROP TABLE IF EXISTS users CASCADE;
DROP TABLE IF EXISTS mapel CASCADE;
DROP TABLE IF EXISTS kelas CASCADE;

DROP TYPE IF EXISTS user_role CASCADE;
DROP TYPE IF EXISTS login_status CASCADE;
DROP TYPE IF EXISTS session_status CASCADE;
DROP TYPE IF EXISTS exam_status CASCADE;

-- 2. Definisi Custom Enum Types
CREATE TYPE user_role AS ENUM ('operator', 'guru', 'siswa');
CREATE TYPE login_status AS ENUM ('offline', 'online');
CREATE TYPE session_status AS ENUM ('nonaktif', 'aktif', 'selesai');
CREATE TYPE exam_status AS ENUM ('belum', 'sedang', 'selesai');

-- 3. Tabel Kelas
CREATE TABLE kelas (
    id_kelas SERIAL PRIMARY KEY,
    nama_kelas VARCHAR(50) NOT NULL
);

-- 4. Tabel Mata Pelajaran
CREATE TABLE mapel (
    id_mapel SERIAL PRIMARY KEY,
    nama_mapel VARCHAR(100) NOT NULL,
    kode_mapel VARCHAR(20) UNIQUE NOT NULL
);

-- 5. Tabel Users
CREATE TABLE users (
    id_user SERIAL PRIMARY KEY,
    nis VARCHAR(30) UNIQUE NULL, -- Nomor Induk Siswa (Khusus role Siswa)
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    nama_lengkap VARCHAR(100) NOT NULL,
    role user_role NOT NULL,
    id_kelas INT REFERENCES kelas(id_kelas) ON DELETE SET NULL,
    status_login login_status DEFAULT 'offline',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 6. Tabel Master Paket Soal (Header)
CREATE TABLE paket_soal (
    id_paket SERIAL PRIMARY KEY,
    id_guru INT NOT NULL REFERENCES users(id_user) ON DELETE CASCADE,
    id_mapel INT NOT NULL REFERENCES mapel(id_mapel) ON DELETE CASCADE,
    nama_paket VARCHAR(150) NOT NULL,
    deskripsi TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 7. Tabel Butir Bank Soal (Detail)
CREATE TABLE bank_soal (
    id_soal SERIAL PRIMARY KEY,
    id_paket INT NOT NULL REFERENCES paket_soal(id_paket) ON DELETE CASCADE,
    jenis_soal VARCHAR(20) DEFAULT 'pilihan_ganda', -- 'pilihan_ganda' atau 'essai'
    pertanyaan TEXT NOT NULL,
    gambar VARCHAR(255) NULL,
    opsi_a TEXT NULL,
    opsi_b TEXT NULL,
    opsi_c TEXT NULL,
    opsi_d TEXT NULL,
    opsi_e TEXT NULL,
    kunci_jawaban VARCHAR(255) NULL, -- Menyimpan 'A', 'A,B', atau pedoman essai
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 8. Tabel Sesi Ujian
CREATE TABLE sesi_ujian (
    id_sesi SERIAL PRIMARY KEY,
    id_guru INT NOT NULL REFERENCES users(id_user) ON DELETE CASCADE,
    id_mapel INT NOT NULL REFERENCES mapel(id_mapel) ON DELETE CASCADE,
    id_kelas INT NOT NULL REFERENCES kelas(id_kelas) ON DELETE CASCADE,
    id_paket INT NULL REFERENCES paket_soal(id_paket) ON DELETE SET NULL,
    nama_ujian VARCHAR(100) NOT NULL,
    token_ujian VARCHAR(20) NOT NULL,
    durasi_menit INT NOT NULL,
    acak_soal BOOLEAN DEFAULT FALSE,
    acak_opsi BOOLEAN DEFAULT FALSE,
    status session_status DEFAULT 'nonaktif',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 9. Tabel Ujian Siswa (Log Sesi Peserta)
CREATE TABLE ujian_siswa (
    id_ujian_siswa SERIAL PRIMARY KEY,
    id_sesi INT NOT NULL REFERENCES sesi_ujian(id_sesi) ON DELETE CASCADE,
    id_siswa INT NOT NULL REFERENCES users(id_user) ON DELETE CASCADE,
    urutan_soal JSONB NULL, -- Menyimpan array urutan ID soal yang sudah diacak
    waktu_mulai TIMESTAMP NULL,
    waktu_selesai TIMESTAMP NULL,
    sisa_detik INT NOT NULL,
    status exam_status DEFAULT 'belum',
    jumlah_benar INT DEFAULT 0,
    nilai_pg NUMERIC(5,2) DEFAULT 0.00,
    nilai_essai NUMERIC(5,2) DEFAULT NULL,
    nilai_akhir NUMERIC(5,2) DEFAULT 0.00,
    CONSTRAINT unique_siswa_sesi UNIQUE (id_sesi, id_siswa)
);

-- 10. Tabel Jawaban Siswa
CREATE TABLE jawaban_siswa (
    id_jawaban SERIAL PRIMARY KEY,
    id_ujian_siswa INT NOT NULL REFERENCES ujian_siswa(id_ujian_siswa) ON DELETE CASCADE,
    id_soal INT NOT NULL REFERENCES bank_soal(id_soal) ON DELETE CASCADE,
    jawaban_terpilih TEXT NULL, -- Menyimpan 'A', 'A,B' (checklist), atau teks jawaban essai
    status_ragu BOOLEAN DEFAULT FALSE,
    nilai_soal NUMERIC(5,2) DEFAULT NULL, -- Nilai yang diberikan guru untuk soal essai (0 - 100)
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT unique_ujian_soal UNIQUE (id_ujian_siswa, id_soal)
);

-- Index Optimasi Performa Query Real-time
CREATE INDEX idx_users_role ON users(role);
CREATE INDEX idx_users_login ON users(status_login);
CREATE INDEX idx_paket_soal_guru ON paket_soal(id_guru);
CREATE INDEX idx_paket_soal_mapel ON paket_soal(id_mapel);
CREATE INDEX idx_bank_soal_paket ON bank_soal(id_paket);
CREATE INDEX idx_sesi_ujian_paket ON sesi_ujian(id_paket);
CREATE INDEX idx_sesi_status ON sesi_ujian(status);
CREATE INDEX idx_ujian_siswa_status ON ujian_siswa(status);
CREATE INDEX idx_jawaban_ujian ON jawaban_siswa(id_ujian_siswa);

-- Data Kelas (Tingkat Sekolah Dasar / SD: Kelas 1 - 6)
INSERT INTO kelas (id_kelas, nama_kelas) VALUES 
(1, 'Kelas 1'),
(2, 'Kelas 2'),
(3, 'Kelas 3'),
(4, 'Kelas 4'),
(5, 'Kelas 5'),
(6, 'Kelas 6');

-- Data Mata Pelajaran (Kurikulum SD)
INSERT INTO mapel (id_mapel, nama_mapel, kode_mapel) VALUES 
(1, 'Matematika', 'MTK'),
(2, 'Bahasa Indonesia', 'BIND'),
(3, 'Ilmu Pengetahuan Alam dan Sosial', 'IPAS'),
(4, 'Pendidikan Pancasila', 'PKN');

-- Data Users
INSERT INTO users (id_user, nis, username, password, nama_lengkap, role, id_kelas, status_login) VALUES
(1, NULL, 'admin', '$2y$12$BPsZUNoLE44wAvDysjumCe5n6zQOjNVd2vgNoOUH0DP403Y2WtNhC', 'Administrator Sekolah', 'operator', NULL, 'offline');

-- Sinkronisasi Sequence ID
SELECT setval('kelas_id_kelas_seq', COALESCE((SELECT MAX(id_kelas) FROM kelas), 1));
SELECT setval('mapel_id_mapel_seq', COALESCE((SELECT MAX(id_mapel) FROM mapel), 1));
SELECT setval('users_id_user_seq', COALESCE((SELECT MAX(id_user) FROM users), 1));

