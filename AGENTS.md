# Aturan Proyek: Penomoran Versi (Versioning Rules)

Setiap melakukan perubahan pada aplikasi, asisten wajib mematuhi aturan penomoran versi berikut:

## 1. Aturan Semantic Versioning (versionName: MAJOR.MINOR.PATCH)
- **Perubahan Kecil (Digit ke-3 / Patch)**: Perbaikan bug kecil, penyesuaian styling, perbaikan kompatibilitas perangkat, atau perbaikan minor tanpa fitur baru.
  - Format: `X.Y.(Z+1)` (Contoh: `2.0.0` -> `2.0.1`).
- **Perubahan Sedang (Digit ke-2 / Minor)**: Penambahan fitur baru atau pembaruan antarmuka fungsional yang kompatibel dengan alur sebelumnya.
  - Format: `X.(Y+1).0` (Contoh: `2.0.1` -> `2.1.0`).
- **Perubahan Besar (Digit ke-1 / Major)**: Perombakan arsitektur besar, perubahan alur utama aplikasi, atau perombakan sistem yang fundamental.
  - Format: `(X+1).0.0` (Contoh: `2.1.0` -> `3.0.0`).

## 2. Invarian Android Version Code & Sinkronisasi
- Setiap kali ada kenaikan versi (`versionName`), `versionCode` **WAJIB selalu ditambah +1** (integer bertahap: `1 -> 2 -> 3 ...`) agar pembaruan APK selalu diterima sistem Android sebagai *in-place update*.
- Seluruh referensi versi harus diperbarui secara serentak di:
  - `app/build.gradle.kts` (`versionCode` & `versionName`)
  - `app/src/main/res/values/strings.xml` (`about_message`)
  - `app/src/main/java/org/sdn1talun/cbt/MainActivity.java` (`APP_TAG`)
  - `README.md` (Tabel Spesifikasi Teknis)
