# Build Plan — Doc OCR Service

**Status:** service berjalan penuh dan bisa diuji berdiri sendiri
**Terakhir diperbarui:** 2026-08-18

Tujuan tahap ini sudah tercapai: **service berjalan dan bisa diuji sepenuhnya berdiri sendiri**, tanpa perlu terhubung ke aplikasi consumer mana pun. Integrasi dikerjakan belakangan, di repo consumer.

---

## Status Implementasi

### ✅ Selesai

| Komponen | Berkas | Catatan |
|---|---|---|
| Scaffold | Laravel 12.66.0, PHP 8.2.9 | |
| Konfigurasi env | `.env`, `.env.example` | stateless — `DB_CONNECTION=null`, session `array`, cache `file`, queue `sync`; `APP_KEY` sudah di-generate |
| Config utama | `config/ocr.php` | binary, timeout, limit, profil per jenis dokumen, token, whitelist path, ambang klasifikasi, playground |
| DTO | `app/DTO/` | `DocumentFile`, `WordBox`, `PageResult`, `FieldResult`, `AnalyzeOptions`, `AnalyzeRequestData`, `AnalyzeResult`, `TextLayerProbe`, `Classification` |
| Kontrak | `app/Contracts/` | `OcrEngineInterface`, `DocumentParserInterface`, 6 interface repository |
| Exception | `app/Exceptions/OcrException.php` | named constructor per kode error, termasuk `unauthorized()` |
| Repository | `app/Repositories/` | `Binary`, `Document`, `Profile`, `Parser`, `Workspace`, `Engine` |
| Support | `app/Support/` | `OcrTextNormalizer`, `TextBlock` |
| Parser | `app/Parsers/` | `NpwpParser`, `NibParser` |
| Engine | `app/Engines/` | `FakeEngine`, `TesseractEngine` |
| Service | `app/Services/` | `Workspace`, `TextLayer`, `Rasterize`, `Preprocess`, `Classification`, `Extraction`, `Confidence`, `Analyze` |
| HTTP | `app/Http/`, `routes/api.php` | middleware token, FormRequest, Resource, 3 controller, render error di `bootstrap/app.php` |
| Sarana uji coba | `app/Console/Commands/`, `resources/views/playground.blade.php`, `postman/` | `ocr:doctor`, `ocr:token`, `ocr:analyze`, `ocr:scan`, playground, koleksi Postman |
| Test | `tests/` | 69 test hijau **tanpa binary OCR terpasang** |
| Dokumentasi | `CLAUDE.md`, `.docs/RULES.md`, `.docs/CONTEXT.md`, `.docs/API.md` | |

### Hasil verifikasi di mesin ini

- `php artisan test` → **69 passed (154 assertions)**, tanpa Tesseract terpasang
- Jalur text layer diuji sungguhan dengan `pdftotext` 4.00 lewat `ocr:analyze`, `ocr:scan`, dan `POST /api/v1/documents/analyze`: NPWP contoh terbaca `mode: TEXT_LAYER`, seluruh field benar, confidence 100
- `GET /api/v1/health` melaporkan `degraded` dengan detail binary yang belum terpasang, tetap 200

### ⬜ Sisa Pekerjaan

#### Kalibrasi dengan dokumen asli — **belum bisa dikerjakan di mesin ini**
- [ ] Kalibrasi `OCR_TEXT_LAYER_MIN_ALNUM` (kini 200) dengan NIB/NPWP asli
- [ ] Kalibrasi `text_layer.max_garbage_ratio` (kini 0.30) dengan PDF scan yang sudah di-OCR mesin pemindai
- [ ] Kalibrasi `classification.min_score` (kini 0.35) — cek tidak ada NIB yang salah dikenali sebagai NPWP
- [ ] Ukur metrik CONTEXT §9 pada sekumpulan dokumen vendor asli

#### Setelah binary lengkap terpasang
- [x] Uji `TesseractEngine` sungguhan (parsing TSV, kolom `conf`) — gambar uji terbaca lengkap, confidence nyata 66–96
- [x] Uji `PreprocessService` dengan ImageMagick — berjalan, profil `document` belum dinilai terhadap dokumen asli
- [x] Tinjau `-resize 200%` pada profil `document` — dibatasi `3500x3500>`; sebelumnya foto 4000×3000 membengkak jadi 8056×6074 dan Tesseract **timeout 120 detik**
- [x] Kalibrasi profil untuk foto kamera — lihat di bawah
- [x] Uji `RasterizeService` jalur `pdftoppm` — pipeline penuh (pdftoppm → magick → tesseract) diuji dengan `--force-ocr` pada PDF, tiga field terbaca, 5,1 detik
- [ ] Uji jalur cadangan Ghostscript — tidak dipasang; hanya relevan bila poppler tidak tersedia
- [ ] Tandai test yang butuh binary dengan `#[Group('binary')]`

#### Pelajaran instalasi binary (relevan untuk deploy IIS)

Terjadi di mesin pengembangan dan akan terulang di produksi kalau tidak diantisipasi:

| Gejala | Sebab | Penanganan |
|---|---|---|
| `ocr:doctor` bilang `ok`, tetapi request HTTP memberi peringatan "ImageMagick tidak terpasang" | `php artisan serve` menjalankan request di proses anak yang PATH-nya **tidak sama** dengan PATH terminal. Menjalankan ulang server tidak menolong — sudah dicoba, hasilnya tetap `magick: null` | isi `OCR_BIN_*` dengan path absolut. Ini satu-satunya cara yang bekerja, dan kebetulan juga bentuk yang dibutuhkan IIS |
| `winget install ImageMagick.Q16` memasang paket MSIX ke `C:\Program Files\WindowsApps\` | direktori berproteksi, hanya terjangkau lewat App Execution Alias milik satu user | untuk produksi pasang lewat installer konvensional; **tidak akan bisa dipakai app pool IIS** |
| poppler dari winget mendarat di `%LOCALAPPDATA%\Microsoft\WinGet\Packages\...` | paket portabel per-user | di produksi pindahkan ke folder mesin, mis. `C:\tools\poppler\` |

Kesimpulannya: **jangan bersandar pada PATH.** Isi seluruh `OCR_BIN_*` dengan path absolut — itulah bentuk yang juga akan dipakai di IIS.

#### Hasil kalibrasi profil foto (foto NPWP 4000×3000, kartu berlaminasi)

Diukur langsung, bukan diperkirakan:

| Perlakuan | Hasil OCR |
|---|---|
| Profil `document` lama (`-resize 200%`, `-lat 25x25-8%`) | **timeout**; setelah dibatasi ukurannya pun keluarannya sampah total |
| `-lat` apa pun (25x25, 35x35, 80x80) pada kartu perak berwatermark | sampah — ambang batas lokal mengubah watermark jadi derau |
| Resolusi penuh / 2800 px | gagal — watermark seukuran huruf, ikut terbaca sebagai teks |
| **Gray + deskew + `2000x2000>` + normalize + despeckle, psm 6** | **terbaik** — NPWP, nama, NIK, dan sebagian alamat terbaca |

Dua pelajaran yang berlawanan dengan dugaan awal: untuk foto kartu, **menyusutkan** gambar menolong (bukan membesarkan), dan **meniadakan** thresholding menolong (bukan menambahnya). Keduanya sudah dituangkan ke profil `photo` di `config/ocr.php`.

Waktu proses turun dari timeout 124 detik menjadi **4,9 detik**.

#### Gap parser yang sudah terbukti (kartu NPWP perorangan gaya baru)

Terkonfirmasi pada foto asli: OCR **berhasil membaca** `AHMAD AFIFUDIN` dan baris `NIK` dengan benar, tetapi parser tidak mengambilnya. Jadi datanya ada, hanya aturan ekstraksinya yang belum menjangkau.

- [x] Fallback posisional untuk `vendor_name` dan `address` — dipakai hanya bila labelnya memang tidak ada, ditandai confidence 75
- [x] Tutup celah NIK→NPWP — baris berlabel NIK/NITKU dikecualikan saat mencari nomor NPWP, dan di luar baris berlabel hanya format bertitik yang diterima
- [x] Tambah field `nik` — diambil **hanya** dari baris berlabelnya sendiri
- [x] Alamat parsial: **dikembalikan**, keraguannya disampaikan lewat confidence. Pada foto asli, Tesseract melaporkan `conf=0` untuk kata yang salah baca sehingga confidence alamat menjadi 0 — peninjau langsung melihat field itu tidak layak dipercaya tanpa perlu aturan tambahan

#### Alamat pada foto kartu — batas yang sudah diukur, bukan diduga

Alamat adalah satu-satunya field yang belum pernah terbaca utuh dari foto. Diuji pada tiga baris berikut, yang tercetak di atas watermark terpadat:

```
DSN KRAJAN 3   RT. 002  RW. 005
BETAK, KALIDAWIR
KAB. TULUNGAGUNG JAWA TIMUR
```

Sweep 4 skala × 3 mode segmentasi pada halaman penuh: **tidak ada satu setelan pun yang menangkap ketiga baris.** Masing-masing menukar satu baris dengan baris lain — `w=1600 psm=3` mendapat baris jalan tetapi kehilangan desa, `w=2000 psm=6` sebaliknya. Tiga field lain (`npwp_no`, `nik`, `vendor_name`) identik pada kedua setelan, jadi default tidak diubah.

Penguat kontras **memperburuk**, bukan memperbaiki: `-level 35%,72%` dan `-clahe` sama-sama mengubah keluaran menjadi sampah total. Watermark terlalu dekat nilainya dengan tinta.

Yang menolong hanya satu hal: **memotong area alamat dan memprosesnya sendiri** — baris `DSN KRAJAN 3 RT 002 RW. 005` langsung terbaca. Ini menegaskan bahwa perbaikan berarti untuk foto menuntut deteksi dan pemotongan dokumen per wilayah, yang berarti OpenCV, yang berarti sidecar Python. Bukan pekerjaan menyetel parameter.

> **Risiko yang perlu diketahui consumer:** alamat bisa kembali **terpotong tetapi terlihat wajar** dengan confidence cukup tinggi — mis. `KAB. TULUNGAGUNG JAWA TIMUR` pada confidence 75. Confidence mengukur keyakinan per kata, **bukan kelengkapan**. Untuk `address`, panel review sebaiknya selalu menampilkan `raw_text` di sampingnya. Perlu diingat `.docs/CONTEXT.md` §9 memang tidak menetapkan target akurasi untuk `address` — tiga field yang ditargetkan semuanya terbaca benar.

#### PDF yang isinya sebenarnya gambar

Kasus nyata: vendor memfoto dokumen lalu menyimpannya sebagai PDF. Diuji dengan membungkus foto NPWP menjadi PDF.

| Sebelum perbaikan | Sesudah |
|---|---|
| **113 detik**, `fields` **kosong**, tanpa peringatan apa pun | **11 detik**, 4 field terbaca |

Dua sebab yang menumpuk, keduanya sudah ditutup:

1. **Ukuran halaman × dpi meledak.** PDF hasil bungkus foto memakai satuan titik sebesar piksel gambarnya, jadi halaman "seluas" 55×42 inci dirender 300 dpi menjadi 208 megapiksel. Kini dpi diturunkan otomatis agar sisi terpanjang tidak melewati `OCR_MAX_RASTER_PX` (default 3.500 px).
2. **Profil preprocessing salah.** PDF tanpa text layer diperlakukan sebagai dokumen bersih dan diberi ambang batas lokal, yang menghancurkannya. Kini penanda yang dipakai adalah ada tidaknya text layer, bukan ekstensi berkas.

Diverifikasi tidak ada regresi pada tiga jalur lain: PDF ber-text-layer, PDF ber-text-layer yang dipaksa OCR, dan foto JPG langsung.

Hasil pada foto NPWP asli, dari 1 field menjadi 4:

| Field | Confidence | Catatan |
|---|---|---|
| `npwp_no` | 91.62 | berlabel |
| `nik` | 88.65 | berlabel |
| `vendor_name` | 75.00 | dibaca dari posisi — dibatasi kepastian struktur |
| `address` | 0.00 | terbaca sebagian; satu kata dilaporkan `conf=0` oleh Tesseract |

#### Jenis dokumen berikutnya
- [ ] `SK_KEMENKUMHAM` (prioritas 2) — ikuti RULES §10
- [ ] `KTP` (prioritas 3) — akurasi Tesseract rendah, tunggu hasil kalibrasi

#### Tahap 8 — Integrasi consumer (repo lain)
Dikerjakan di repo aplikasi consumer, bukan di sini. Sisi service sudah selesai; yang tersisa adalah worker, antrian job, dan panel review di sisi consumer.

---

## Pipeline

```
AnalyzeService::analyze(AnalyzeRequestData): AnalyzeResult
├─ 1. DocumentRepository::resolve()      → DocumentFile (tervalidasi)
├─ 2. WorkspaceService::create()         → direktori kerja unik
├─ 3. TextLayerService::probe()          → PDF punya text layer?
│      ├─ ya   → mode TEXT_LAYER, confidence 100     ──────┐
│      └─ tidak → lanjut                                   │
├─ 4. RasterizeService::toPng()          → 300 dpi         │
├─ 5. PreprocessService::apply()         → deskew, grayscale, LAT threshold
├─ 6. OcrEngine::recognize()             → PageResult[] + WordBox[]
├─ 7. ClassificationService::detect()  ◄───────────────────┘
│      (dilewati bila doc_type dikirim consumer)
├─ 8. ExtractionService::extract()       → FieldResult[]
├─ 9. ConfidenceService::score()         → confidence per field
└─ 10. finally: WorkspaceService::destroy() + DocumentRepository::release()
```

Langkah 10 **wajib** ada di blok `finally`, bukan di akhir jalur sukses. Berkas turunan tidak boleh tertinggal saat terjadi exception. Dijaga test `test_tidak_meninggalkan_berkas_turunan`.

---

## Keputusan Desain yang Sudah Diambil

Jangan diubah tanpa alasan kuat — masing-masing punya konsekuensi ke consumer:

| Keputusan | Alasan |
|---|---|
| **Text layer dicoba lebih dulu** | NIB OSS / NPWP DJP / SK Kemenkumham terbit sebagai PDF digital → akurasi ~100% tanpa OCR. Ini sumber nilai terbesar service, bukan Tesseract-nya |
| **`bbox` ikut di response sejak awal** | memungkinkan consumer menyorot posisi field di atas gambar dokumen. Menambahkannya belakangan = menaikkan versi API |
| **`value` dan `raw` dipisah** | `value` siap masuk DB, `raw` untuk audit & review. Digabung → normalisasi bocor ke dua tempat |
| **Confidence field = minimum, bukan rata-rata** | konservatif: satu kata meragukan sudah cukup untuk menurunkan kepercayaan seluruh field |
| **Kesalahan input tidak memakai 5xx** | consumer memakai status untuk memutuskan retry; salah kelas → job rusak berputar sampai batas percobaan habis |
| **Field tidak ditemukan → tidak muncul di response** | bukan string kosong. Membedakan "tidak ada" dari "ada tapi kosong" |
| **Bila parser ragu, kembalikan kosong** | false positive jauh lebih berbahaya daripada field kosong (lihat CONTEXT §9) |
| **Halaman dibatasi (default 5)** | field yang dicari selalu di halaman awal; memotong waktu proses akta 20 halaman secara drastis |

### Keputusan tambahan saat implementasi

| Keputusan | Alasan |
|---|---|
| **Ekstraksi berbasis label, bukan regex bebas** (`Support/TextBlock`) | dokumen NPWP/NIB punya label konsisten. Regex bebas atas seluruh teks akan menangkap NPWP milik pihak lain yang ikut tercetak di NIB |
| **Koreksi huruf↔angka hanya untuk field numerik** (`OcrTextNormalizer`) | menerapkannya pada nama perusahaan merusak: "PT SOLO" menjadi "PT 5010" |
| **Kandidat nomor ditolak bila rasio digit asli < 0.55** | penjaga false positive: tanpa ini kata seperti "SOLOBIZ" lolos sebagai kandidat karena seluruh hurufnya mirip angka |
| **KBLI dikembalikan seluruhnya, dipisah koma** | NIB umumnya memuat lebih dari satu KBLI. Memilih satu secara sepihak menyembunyikan pilihan dari user yang mereview |
| **`WorkspaceRepository` dan `EngineRepository` ditambahkan** | RULES §1.2 melarang service menyentuh filesystem/config akses data langsung. `WorkspaceService` memegang kebijakan siklus hidup, repository memegang I/O-nya |
| **Deteksi binary lewat pola nomor versi, bukan exit code** | Xpdf mengembalikan exit 99 pada `pdftotext -v` meski berhasil, sementara binary yang tidak ada menghasilkan pesan tanpa nomor versi |
| **Jumlah halaman dihitung dari form feed keluaran `pdftotext`** | `pdfinfo` tidak ada di daftar binary yang dipasang Infra, dan satu pemanggilan `pdftotext` sudah cukup untuk teks sekaligus jumlah halaman |
| **Engine dipilih dari `doc_type` yang dikirim consumer** | pada klasifikasi otomatis, jenis dokumen baru diketahui setelah OCR berjalan — override `engine.per_doc_type` karena itu hanya berlaku bila consumer menyebutkan `doc_type` |
| **Playground dikecualikan dari CSRF** | session memakai driver `array` (stateless), token CSRF tidak bertahan antar-request. Playground adalah alat lokal yang dimatikan di produksi |
| **Confidence dicocokkan ke deretan kata paling rapat, bukan kemunculan pertama** | "INDONESIA" muncul di kop dokumen sekaligus di nama perusahaan. Mengambil kemunculan pertama membuat `bbox` melar sehalaman penuh dan confidence diambil dari kata yang salah — ditemukan saat uji OCR sungguhan |
| **Profil preprocessing dipilih dari ada tidaknya text layer** | ekstensi berkas bukan penanda yang bisa dipercaya: PDF hasil pindaian dan foto yang dibungkus PDF isinya gambar kamera, bukan render bersih. PDF ber-text-layer pasti terbit digital sehingga rendernya bersih dan ambang batas lokal menajamkan huruf; selebihnya diperlakukan sebagai gambar. **Menggantikan aturan sebelumnya** yang memilih berdasarkan PDF-vs-gambar dan terbukti salah untuk foto yang dibungkus PDF |
| **dpi rasterisasi dibatasi oleh jumlah piksel keluaran, bukan dipakai apa adanya** | PDF hasil bungkus foto memakai ukuran halaman sebesar piksel gambarnya. Foto 4000×3000 menjadi halaman 4000×3000 pt — seluas 55×42 inci — dan pada 300 dpi menghasilkan 208 megapiksel: 113 detik lalu gagal total. Ukuran halaman dibaca lewat `pdfinfo`; tanpa binary itu dpi dipakai apa adanya |
| **Penghapusan workspace dicoba ulang, kegagalannya di-log** | proses yang dihentikan paksa saat timeout masih mengunci berkas keluarannya; di Windows penghapusan tunggal gagal diam-diam dan meninggalkan berkas ber-PII. Ditemukan sebagai sisa nyata setelah satu run yang timeout |
| **TTL workspace dipendekkan 24 jam → 1 jam** | TTL hanya jaring pengaman untuk proses yang mati di tengah jalan. Yang mengendap adalah berkas ber-PII, jadi tidak ada alasan menahannya semalaman |
| **Confidence = minimum dari keraguan parser dan keraguan engine** | keduanya jenis ketidakpastian yang berbeda: engine ragu pada **karakternya**, parser ragu pada **penafsirannya**. Field yang dibaca dari posisi (bukan label) tidak boleh dilaporkan 100 hanya karena teksnya berasal dari text layer |
| **Pembacaan posisi hanya cadangan, tidak pernah mendahului label** | kartu gaya lama dan gaya baru harus dilayani satu parser. Mendahulukan label menjaga agar dokumen badan usaha yang selama ini benar tidak berubah perilakunya |

---

## Catatan Lingkungan Pengembangan

Kondisi mesin dev:

| Binary | Status |
|---|---|
| `tesseract` | ✅ 5.4.0 (UB-Mannheim), `ind`+`eng` dari tessdata_best — **jalur OCR sudah diuji sungguhan** |
| `pdftotext` | ✅ 25.07.0 (poppler) — **jalur text layer sudah diuji sungguhan**; sebelumnya memakai Xpdf 4.00 bawaan Git di Laragon |
| `pdftoppm` | ✅ 25.07.0 (poppler) — **jalur rasterisasi sudah diuji sungguhan** |
| `pdfinfo` | ✅ 25.07.0 (poppler) — dipakai membatasi dpi agar rasterisasi tidak meledak |
| `magick` | ✅ 7.1.2 (ImageMagick Q16, paket MSIX — lihat catatan instalasi) |
| `gswin64c` | ❌ belum — hanya cadangan bila poppler tidak ada, jadi tidak diperlukan |

Tesseract terpasang di `C:\Program Files\Tesseract-OCR\` tetapi **tidak masuk PATH**, jadi
`OCR_BIN_TESSERACT` di `.env` diisi path absolutnya. Ini kasus lumrah di Windows dan sudah
diantisipasi config sejak awal — nilai yang sama akan dibutuhkan saat deploy ke IIS.

Sisa yang belum bisa diuji di mesin ini: rasterisasi PDF hasil scan (butuh `pdftoppm` atau
Ghostscript). PDF ber-text-layer tidak terpengaruh karena tidak melewati rasterisasi.

Pasang poppler bila perlu: `winget install --id oschwartz10612.Poppler --exact`.

Berkas contoh untuk uji cepat: `storage/app/inbox/npwp-contoh.pdf` (PDF ber-text-layer, dibuat sintetis — bukan dokumen asli).

### Sisa scaffolding Laravel yang belum dibersihkan

Belum dihapus karena repo ini belum berada di bawah git, sehingga penghapusan tidak bisa dibatalkan:

- `app/Models/User.php`
- `database/` (`migrations/`, `factories/`, `seeders/`, `database.sqlite`)
- entri `Database\Factories\` dan `Database\Seeders\` di `composer.json`
- `tests/Unit/ExampleTest.php`

Tidak ada satu pun yang dirujuk kode service. Aman dihapus setelah `git init` + commit pertama.
