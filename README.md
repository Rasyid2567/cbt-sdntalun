# CBT SDN 1 Talun - Aplikasi Android WebView

Aplikasi Android resmi untuk portal Computer Based Test (CBT) **SD Negeri 1 Talun** (`https://cbt.sdnsatutalun.sch.id/`).

---

## 📱 Berkas APK Siap Pasang (Download/Install)

Berkas APK yang siap langsung dipasang di HP / Tablet Android tersedia di folder `dist/`:

- 📥 **[CBT-SDN1Talun.apk](file:///home/syid/Projects/sdntalun/apk-cbt/dist/CBT-SDN1Talun.apk)** *(Release Signed - 4.6 MB)*
- 📥 **[CBT-SDN1Talun-debug.apk](file:///home/syid/Projects/sdntalun/apk-cbt/dist/CBT-SDN1Talun-debug.apk)** *(Debug Build - 5.6 MB)*

---

## ✨ Fitur & Keunggulan

1. **WebView Modern & Berperforma Tinggi**:
   - Engine Chromium WebView terbaru dengan dukungan JavaScript, DOM Storage, dan Database Storage.
   - User-Agent khusus `CBT-SDN1Talun-App/1.0`.
   - Linear Progress Bar animasi di bagian atas layar saat memuat halaman ujian.
2. **CBT Lockdown & Proteksi Keamanan Ujian (Anti-Curang)**:
   - **Mode Sematkan Layar / Kiosk (Lock Task)**: Mengunci aplikasi ke layar secara penuh (*override* sistem), menonaktifkan tombol Home, Recent Apps (pindah aplikasi), dan bilah notifikasi atas sehingga siswa tidak dapat membuka aplikasi lain saat ujian.
   - **Taskbar Bawah Khusus Keluar**: Disediakan taskbar di bagian paling bawah layar yang hanya memuat tombol **Keluar Ujian** beraksen merah Material Design dengan dialog konfirmasi (*"Apakah Anda yakin ingin keluar dari ujian?"*).
   - **Anti-Screenshot & Screen Recording (`FLAG_SECURE`)**: Memblokir tangkapan layar, perekaman video layar, serta menyembunyikan pratinjau aplikasi.
   - **Anti Split-Screen & Floating Apps**: Menonaktifkan fitur multi-window agar siswa tidak bisa membagi layar dengan aplikasi lain.
   - **Anti Web Search & Seleksi Teks**: Mematikan fitur seleksi teks dan klik lama (*long press*) pada soal ujian agar siswa tidak bisa memblok kata dan menyalin atau mencari di Google.
   - **Keep Screen On**: Layar tetap menyala selama aplikasi aktif sehingga tidak mati otomatis saat siswa membaca/mengerjakan soal.
   - **Proteksi Tombol Kembali (Back Button)**: Terintegrasi dengan dialog konfirmasi keluar agar tidak sengaja keluar saat menekan tombol navigasi HP.
   - **Pull-to-Refresh**: Swipe down untuk memuat ulang halaman dengan cepat.
3. **Penanganan Masalah Jaringan / Offline**:
   - Tampilan *Offline State* dengan ilustrasi dan tombol **Coba Lagi** (*Retry*).
   - Auto-reconnect detector yang otomatis memuat ulang saat koneksi internet kembali aktif.
4. **Dukungan Kamera & Unggah Berkas**:
   - Dukungan `onShowFileChooser` untuk upload dokumen jawaban atau foto pengerjaan tugas langsung dari kamera/galeri.
5. **Dukungan Unduhan**:
   - Terintegrasi dengan Android `DownloadManager` untuk menyimpan kartu peserta, berkas PDF, atau sertifikat ke folder `Downloads`.
6. **Menu Cepat & Pengaturan Sesi**:
   - Opsi Hapus Cache & Cookie untuk reset sesi ujian.
   - Mode Fullscreen / Immersive.
   - Dialog "Tentang Aplikasi" SDN 1 Talun.

---

## 🛠️ Panduan Build Sendiri (Kompilasi)

Jika ingin memodifikasi kode atau mengompilasi ulang APK:

```bash
# Set JAVA_HOME ke JDK 17+
export JAVA_HOME=/home/syid/.tools/jdks/jdk-17.0.20.1+1

# Build Debug APK
./gradlew assembleDebug

# Build Release APK
./gradlew assembleRelease
```

File APK hasil kompilasi akan berada di:
- `app/build/outputs/apk/release/app-release.apk`
- `app/build/outputs/apk/debug/app-debug.apk`

---

## 📋 Spesifikasi Teknis

- **Package Name**: `org.sdn1talun.cbt`
- **Versi**: 2.0.0 (Version Code: 2)
- **Target SDK**: Android 15 (API 35)
- **Min SDK**: Android 7.0 Nougat (API 24)
- **Bahasa**: Java + Material Design 3
- **URL Target**: `https://cbt.sdnsatutalun.sch.id/`
