# Doc OCR Service

Service OCR / Document Intelligence untuk **dokumen legalitas Indonesia** — NPWP, NIB, KTP, SK Kemenkumham. Menerima berkas dokumen, mengembalikan **field terstruktur** beserta confidence per field, bukan sekadar teks mentah.

```
Aplikasi apa pun ──► POST /api/v1/documents/analyze ──► JSON field terstruktur
```

Dirancang sebagai service mandiri yang dipakai bersama oleh beberapa aplikasi lewat REST API. **Stateless — tanpa database, tanpa penyimpanan berkas.**

> Laravel 12 · PHP 8.2 · self-hosted, data tidak pernah keluar server

**Yang membuatnya berbeda dari OCR biasa:** dokumen legalitas Indonesia kini banyak terbit sebagai PDF digital ber-*text layer* (NIB dari OSS, NPWP dari Coretax, SK dari AHU Online). Untuk berkas seperti itu service membacanya persis lewat `pdftotext` — akurasi ~100% tanpa OCR sama sekali. Tesseract hanya jaring pengaman untuk hasil scan dan foto.

---

## Dokumentasi

| Berkas | Isi |
|---|---|
| [CLAUDE.md](CLAUDE.md) | Instruksi kerja, batasan keras proyek, perintah |
| [.docs/RULES.md](.docs/RULES.md) | Aturan struktur kode (service–repository), naming, testing |
| [.docs/API.md](.docs/API.md) | Kontrak API v1 — payload, response, kode error |
| [.docs/CONTEXT.md](.docs/CONTEXT.md) | Konteks bisnis consumer pertama, karakteristik tiap jenis dokumen, batas tanggung jawab |
| [.docs/plans/build-plan.md](.docs/plans/build-plan.md) | Status implementasi, hasil kalibrasi, keputusan desain |

> Service ini lahir dari kebutuhan modul Vendor Registration di sebuah sistem e-Procurement, dan `.docs/CONTEXT.md` masih memakai consumer itu sebagai contoh konkret. Kontrak API-nya sendiri tidak mengandaikan consumer tertentu.

---

## Setup

### 1. Aplikasi

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan ocr:token nama-consumer    # salin keluarannya ke OCR_API_TOKENS di .env
```

Tidak ada `php artisan migrate` — service ini memang tidak memakai database. Sampai di sini service sudah bisa dijalankan dan seluruh test sudah hijau, karena test memakai `FakeEngine` dan tidak menyentuh binary apa pun.

### 2. Binary eksternal

Diperlukan hanya untuk memproses dokumen sungguhan.

| Binary | Untuk | Status |
|---|---|---|
| `pdftotext` | deteksi & ekstraksi text layer | **wajib** — jalur bernilai tertinggi, PDF digital dibaca tanpa OCR |
| `tesseract` | OCR dokumen hasil scan & foto | **wajib** untuk dokumen non-digital |
| `pdftoppm` | rasterisasi PDF → PNG | wajib bila memproses PDF hasil scan |
| `pdfinfo` | membaca ukuran halaman PDF | disarankan — penjaga agar rasterisasi tidak meledak, lihat catatan di bawah |
| `magick` | perbaikan citra sebelum OCR | disarankan — tanpa ini akurasi foto anjlok drastis |
| `gswin64c` | rasterisasi cadangan | opsional — **tidak perlu** selama poppler terpasang |

`pdftotext`, `pdftoppm`, dan `pdfinfo` ketiganya berasal dari satu paket: **poppler**.

### 3. Instalasi di Windows

Jalankan PowerShell **sebagai Administrator**.

```powershell
winget install --id UB-Mannheim.TesseractOCR --exact
winget install --id oschwartz10612.Poppler   --exact
winget install --id ImageMagick.Q16          --exact
```

Lalu ambil model bahasa Indonesia dari **tessdata_best** — instalasi senyap hanya membawa `eng` dan `osd`, padahal seluruh profil memakai `ind+eng`:

```powershell
$dest = 'C:\Program Files\Tesseract-OCR\tessdata'
$base = 'https://github.com/tesseract-ocr/tessdata_best/raw/main'
Invoke-WebRequest "$base/ind.traineddata" -OutFile "$dest\ind.traineddata"
Invoke-WebRequest "$base/eng.traineddata" -OutFile "$dest\eng.traineddata"
```

### 4. Isi path absolut di `.env` — jangan bersandar pada PATH

Ini bukan langkah opsional. **PATH proses yang melayani request berbeda dari PATH terminal Anda**, dan itu sudah terbukti menimbulkan kegagalan yang membingungkan: `ocr:doctor` melaporkan sebuah binary `ok` sementara request HTTP untuk berkas yang sama gagal dengan peringatan "binary tidak terpasang". Menjalankan ulang server tidak menolongnya.

Karena itu isi seluruh `OCR_BIN_*` dengan path lengkap:

```dotenv
OCR_BIN_TESSERACT="C:/Program Files/Tesseract-OCR/tesseract.exe"
OCR_BIN_PDFTOTEXT="C:/tools/poppler/Library/bin/pdftotext.exe"
OCR_BIN_PDFTOPPM="C:/tools/poppler/Library/bin/pdftoppm.exe"
OCR_BIN_PDFINFO="C:/tools/poppler/Library/bin/pdfinfo.exe"
OCR_BIN_MAGICK="C:/Program Files/ImageMagick-7.1.2-Q16/magick.exe"
```

Sesuaikan dengan lokasi sebenarnya di mesin Anda. Paket poppler dari winget mendarat di `%LOCALAPPDATA%\Microsoft\WinGet\Packages\oschwartz10612.Poppler_*\poppler-<versi>\Library\bin`. Cari cepat:

```powershell
Get-ChildItem $env:LOCALAPPDATA\Microsoft\WinGet\Packages -Recurse -Filter 'pdftoppm.exe' | Select-Object FullName
(Get-Command tesseract, magick -ErrorAction SilentlyContinue).Source
```

Setelah menyunting `.env`, jalankan `php artisan config:clear` bila Anda pernah memakai `config:cache`.

### 5. Verifikasi

```bash
php artisan ocr:doctor
```

Yang diharapkan — `gs` boleh kosong:

```
tesseract  5.4.0    ok
pdftotext  25.07.0  ok
pdftoppm   25.07.0  ok
pdfinfo    25.07.0  ok
magick     7.1.2    ok
gs         -        tidak terpasang
```

Uji ujung ke ujung dengan berkas contoh yang ikut di repo:

```bash
php artisan ocr:analyze storage/app/inbox/npwp-contoh.pdf --doc-type=NPWP
```

### Catatan untuk produksi (IIS)

Yang berikut bukan detail sepele — semuanya berasal dari kegagalan nyata saat pemasangan:

- **Jangan pasang lewat Microsoft Store atau paket winget bertipe MSIX.** `winget install ImageMagick.Q16` memasang MSIX ke `C:\Program Files\WindowsApps\`, direktori berproteksi yang hanya terjangkau lewat *App Execution Alias* milik satu user. Identitas app pool IIS tidak akan bisa mengeksekusinya, bahkan dengan path absolut sekalipun. Untuk produksi pakai installer konvensional dari [imagemagick.org](https://imagemagick.org/script/download.php).
- **Jangan pasang ke folder profil user.** Paket poppler dari winget mendarat di `%LOCALAPPDATA%`; pindahkan ke folder mesin seperti `C:\tools\poppler\` agar terjangkau app pool.
- **Path absolut wajib**, dengan alasan yang sama seperti di atas dan diperkuat: PATH app pool berbeda lagi dari PATH administrator.
- App pool butuh **read-write** ke `storage/` dan **read-only** ke folder dokumen milik aplikasi consumer.

Rinciannya di [.docs/CONTEXT.md](.docs/CONTEXT.md) §7.

### Tanpa binary sama sekali

Seluruh test tetap hijau tanpa satu binary pun terpasang. Untuk menjalankan pipeline secara manual, set `OCR_ENGINE=fake` di `.env` — `FakeEngine` membaca berkas `.txt` bersebelahan dengan gambar, sehingga pipeline OCR berjalan penuh lengkap dengan confidence dan `bbox`:

```bash
# storage/app/inbox/contoh.png  +  storage/app/inbox/contoh.txt
php artisan ocr:analyze storage/app/inbox/contoh.png --doc-type=NPWP
```

Cara ini hanya bekerja untuk sumber `path`; pada upload berkasnya disalin ke lokasi sementara sehingga sidecar-nya tidak ikut.

---

## Menjalankan

```bash
php artisan serve      # http://127.0.0.1:8000
```

Ambil token dev dari `.env` (`OCR_API_TOKENS`, bentuknya `nama:token`).

---

## Cara Menguji — Empat Jalur

Semuanya berdiri sendiri — tidak perlu aplikasi consumer apa pun.

### 1. Playground (paling cepat, tanpa alat bantu)

Set `OCR_PLAYGROUND_ENABLED=true` di `.env`, lalu buka:

```
http://127.0.0.1:8000/playground
```

Form upload manual — pilih foto/PDF, pilih jenis dokumen, lihat hasilnya langsung. **Wajib `false` di produksi.**

### 2. Postman

Impor `postman/doc-ocr.postman_collection.json`, isi variabel `base_url` dan `token`.

### 3. Taruh berkas di folder

```bash
# taruh berkas uji di storage/app/inbox/
php artisan ocr:analyze storage/app/inbox/npwp.pdf --doc-type=NPWP

# tanpa --doc-type, service mengklasifikasi sendiri
php artisan ocr:analyze storage/app/inbox/npwp-contoh.pdf

# proses seluruh isi folder — untuk mengukur akurasi pada banyak dokumen sekaligus
php artisan ocr:scan storage/app/inbox --doc-type=NPWP
```

`storage/app/inbox/npwp-contoh.pdf` adalah NPWP sintetis ber-text-layer untuk uji cepat — bukan dokumen asli.

Token baru: `php artisan ocr:token nama-consumer`.

### 4. cURL

```bash
TOKEN="<isi dari OCR_API_TOKENS>"

curl -s http://127.0.0.1:8000/api/v1/health -H "Authorization: Bearer $TOKEN"

curl -s -X POST http://127.0.0.1:8000/api/v1/documents/analyze \
  -H "Authorization: Bearer $TOKEN" \
  -F "file=@storage/app/inbox/npwp.pdf" \
  -F "doc_type=NPWP"
```

---

## Test

```bash
php artisan test                    # wajib hijau di mesin TANPA Tesseract terpasang
php artisan test --filter=NpwpParser
php artisan test --group=binary     # hanya di mesin dengan binary lengkap
```

Feature test mem-bind `FakeEngine` lewat service container, sehingga pipeline CI tidak perlu binary OCR apa pun.

---

## Endpoint

| Method | Path | Fungsi |
|---|---|---|
| `POST` | `/api/v1/documents/analyze` | analisa dokumen → field terstruktur |
| `GET` | `/api/v1/health` | status service + versi binary |
| `GET` | `/api/v1/doc-types` | jenis dokumen yang didukung + field-nya |
| `GET` | `/playground` | form uji coba manual (non-produksi) |

Detail payload dan response: [.docs/API.md](.docs/API.md).

---

## Keamanan

- Token statis di `.env`, dibandingkan dengan `hash_equals()` — tanpa database
- `source.type = "path"` hanya boleh membaca direktori di `OCR_ALLOWED_BASE_PATHS`
- Ekstensi berkas diverifikasi dengan **magic bytes**, bukan sekadar nama
- Argumen proses eksternal selalu array — tidak ada jalur command injection
- Berkas turunan dihapus setelah tiap request; **tidak ada PII yang mengendap**
- Log tidak pernah memuat isi dokumen (UU PDP 27/2022)
- Di produksi: bind `127.0.0.1` saja, playground dimatikan
