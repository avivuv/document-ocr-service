# CLAUDE.md — Doc OCR Service

> Framework: Laravel 12 | PHP 8.2 | **TANPA DATABASE** | Target deploy: Windows Server + IIS
> Repo mandiri, terpisah dari aplikasi mana pun yang memakainya. Dikonsumsi lewat REST API.

**BAHASA INDONESIA** untuk semua komunikasi ke user. Kode, nama variabel, dan perintah teknis → English.

---

## 1. Apa Ini dan Kenapa Ada

Service stateless yang menerima berkas dokumen legalitas vendor Indonesia (NPWP, NIB, KTP, SK Kemenkumham) dan mengembalikan **field terstruktur** beserta confidence — bukan sekadar teks mentah.

```
Aplikasi intranet  ─┐
                    ├─► POST /api/v1/documents/analyze ─► JSON field terstruktur
Aplikasi extranet  ─┘
```

**Kenapa dipisah sebagai service tersendiri:**
- Aplikasi consumer tidak perlu memanggil binary eksternal sama sekali — risiko `disable_functions` di php.ini produksi hilang
- Dependensi berat (Tesseract, poppler, ImageMagick, Ghostscript) terisolasi di satu aplikasi
- Dipakai bersama beberapa aplikasi sekaligus, dan terbuka untuk jenis dokumen baru
- Engine OCR bisa diganti tanpa menyentuh kode consumer

Konteks bisnis lengkap: `@.docs/CONTEXT.md`

---

## 2. Batasan Keras — Jangan Dilanggar

| # | Batasan | Alasan |
|---|---|---|
| 1 | **Tidak boleh menambah database** | Service stateless: tidak ada PII yang mengendap, restart sepele, review keamanan ringan. Tidak ada migration, tidak ada Eloquent model, `DB_CONNECTION` tidak dipakai |
| 2 | **Tidak boleh menyimpan berkas permanen** | Semua file turunan dihapus di blok `finally`. Dokumen asli milik consumer, service hanya membaca |
| 3 | **PHP 8.2 adalah plafon** | Server produksi terkunci di 8.2. Jangan pakai sintaks 8.3+, jangan upgrade Laravel ke versi yang menaikkan minimum PHP |
| 4 | **Tidak boleh memanggil binary di luar `BinaryRepository`** | Satu pintu, argumen selalu array (bukan string shell) → tidak ada jalur command injection |
| 5 | **Parser wajib fungsi murni** | Tanpa I/O, tanpa config global, tanpa state — supaya bisa diuji tuntas tanpa binary terpasang |
| 6 | **Jangan pernah me-log isi `raw_text`** | Berisi NIK, alamat, dan data pribadi lain (UU PDP 27/2022) |
| 7 | **Tidak ada akses ke database consumer** | Kalau sebuah fitur butuh master data milik consumer, fitur itu milik consumer, bukan service ini |

---

## 3. Sebelum Menulis Kode

Baca berurutan:

1. `@.docs/RULES.md` — **wajib, selalu.** Aturan layering, naming, testing, error handling
2. `@.docs/API.md` — kontrak API. Mengubah bentuk response = mengubah kontrak dengan setiap consumer, tidak boleh sembarangan
3. `@.docs/CONTEXT.md` — konteks bisnis & pola integrasi consumer (baca bila tugasnya menyangkut jenis dokumen atau alur consumer)
4. `@.docs/plans/build-plan.md` — status implementasi & sisa pekerjaan

---

## 4. Arsitektur — Service Repository Pattern

```
Controller (adapter HTTP, tipis)
    │  hanya: baca request → panggil service → bentuk response
    ▼
Service (orkestrasi & business logic)
    │  tidak pernah menyentuh filesystem/proses eksternal secara langsung
    ▼
Repository (akses data: filesystem, config, binary eksternal)
```

Ditambah dua kategori kelas pendukung:
- **Engines** — implementasi `OcrEngineInterface` (Tesseract, Fake, kelak yang lain)
- **Parsers** — implementasi `DocumentParserInterface`, fungsi murni per jenis dokumen

Detail dan aturannya di `@.docs/RULES.md`.

---

## 5. Perintah

```bash
# Server dev
php artisan serve                       # http://127.0.0.1:8000

# Test — TIDAK butuh binary OCR terpasang (memakai FakeEngine)
php artisan test
php artisan test --filter=NpwpParser

# Uji coba manual tanpa Postman
#   buka http://127.0.0.1:8000/playground   (butuh OCR_PLAYGROUND_ENABLED=true)

# Uji coba lewat CLI — satu berkas
php artisan ocr:analyze storage/app/inbox/npwp.pdf --doc-type=NPWP

# Uji coba lewat CLI — seluruh berkas di satu folder
php artisan ocr:scan storage/app/inbox --doc-type=NPWP

# Cek ketersediaan binary di mesin ini
php artisan ocr:doctor

# Generate token API baru
php artisan ocr:token
```

---

## 6. Git Rules

> ### ⛔ LARANGAN KERAS
> **DILARANG `git commit`, `git push`, atau membuat PR tanpa diminta eksplisit oleh user.**
> Berlaku tanpa pengecualian — bahkan bila implementasi sudah selesai dan test sudah lulus.
> Tunggu perintah eksplisit: "commit", "push", "buat PR", atau padanannya.

- `git add` per file — **dilarang** `git add -A` atau `git add .`
- **Dilarang** di commit message: referensi AI, "Generated with Claude Code", "Co-Authored-By: Claude"
- Format commit message: `<tipe>: <deskripsi singkat dalam bahasa Indonesia>`

Sebelum commit (bila diminta): jalankan `git status`, review `git diff`, bandingkan dengan scope tugas, baru `git add` per file.

---

## 7. Aturan Komentar Kode

**Default: jangan tulis komentar.** Kode yang jelas tidak butuh komentar.

**Tulis komentar HANYA untuk:**
- Constraint teknis non-obvious yang bila dilanggar bikin kode rusak
- **Kenapa** kode ditulis dengan cara tidak lazim (bukan **apa** yang dilakukan)
- Menandai workaround terhadap bug/limitasi eksternal

**DILARANG:** komentar yang mengulang isi kode, narasi riwayat kerja ("sebelumnya di-hardcode..."), blok header panjang berisi latar belakang masalah, referensi AI.

---

## 8. Work Summary (wajib setelah setiap task)

Tulis sebagai prosa, bukan blok kode siap-copas. Cakup: apa yang dikerjakan, file yang terpengaruh beserta perannya, hasil akhir (termasuk hasil test apa adanya — kalau ada yang gagal, katakan), dan catatan penting bila ada.
