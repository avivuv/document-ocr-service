# RULES.md — Aturan Inti Doc OCR Service

> Sumber kebenaran tunggal untuk struktur kode. Seluruh kode wajib mengikuti aturan ini.
> Batasan keras tingkat proyek (tanpa DB, tanpa PII di log, dst.) ada di `CLAUDE.md` §2.

---

## 1. Prinsip

1. **Controller = adapter HTTP.** Hanya baca request tervalidasi, panggil service, bentuk response. Tidak ada business logic, tidak ada akses filesystem.
2. **Service = orkestrasi & business logic.** Tidak pernah menyentuh filesystem, proses eksternal, atau config akses data secara langsung — selalu lewat repository.
3. **Repository = akses data.** Filesystem, config, binary eksternal. Tidak ada keputusan bisnis di sini; yang ada adalah validasi teknis milik sumber data (ukuran berkas, ekstensi, batas direktori).
4. **Parser = fungsi murni.** Input teks, output field. Tanpa I/O, tanpa config, tanpa state.
5. **Satu tanggung jawab per class.** Kalau ragu, buat class baru.
6. **Semua yang mengandung logika wajib bisa di-unit test tanpa binary OCR terpasang.**

---

## 2. Struktur Folder

```
app/
├── Contracts/
│   ├── OcrEngineInterface.php                → kontrak engine OCR
│   ├── DocumentParserInterface.php           → kontrak parser jenis dokumen
│   └── Repositories/{X}RepositoryInterface.php
├── DTO/                                      → objek data immutable, readonly
├── Engines/{Nama}Engine.php                  → implements OcrEngineInterface
├── Exceptions/OcrException.php               → satu kelas, named constructor per kode error
├── Http/
│   ├── Controllers/Api/V1/{Nama}Controller.php
│   ├── Middleware/AuthenticateApiToken.php
│   ├── Requests/{Nama}Request.php            → validasi payload
│   └── Resources/{Nama}Resource.php          → bentuk response
├── Parsers/{Jenis}Parser.php                 → implements DocumentParserInterface
├── Providers/OcrServiceProvider.php          → binding interface → implementasi
├── Repositories/{Nama}Repository.php         → implements {Nama}RepositoryInterface
├── Services/{Nama}Service.php
└── Support/                                  → helper murni tanpa dependensi

config/ocr.php                                → seluruh knob konfigurasi
routes/api.php
tests/
├── Unit/{Parsers,Services,Repositories,Support}/
└── Feature/Api/
```

---

## 3. Naming Convention

| Layer | Class | File | Namespace |
|---|---|---|---|
| Controller | `{Nama}Controller` | `{Nama}Controller.php` | `App\Http\Controllers\Api\V1` |
| Service | `{Nama}Service` | `{Nama}Service.php` | `App\Services` |
| Repository | `{Nama}Repository` | `{Nama}Repository.php` | `App\Repositories` |
| Kontrak repo | `{Nama}RepositoryInterface` | idem | `App\Contracts\Repositories` |
| Engine | `{Nama}Engine` | idem | `App\Engines` |
| Parser | `{Jenis}Parser` | idem | `App\Parsers` |
| DTO | `{Nama}` (tanpa sufiks) | idem | `App\DTO` |
| Test | `{Nama}Test` | `{Nama}Test.php` | `Tests\Unit\...` / `Tests\Feature\...` |

**Kode jenis dokumen** (`doc_type`) selalu UPPER_SNAKE: `NPWP`, `NIB`, `KTP`, `SK_KEMENKUMHAM`.
**Nama field** selalu lower_snake: `npwp_no`, `nik`, `vendor_name`, `address`, `nib_no`, `kbli`.

### Penamaan method

| Prefix | Return | Kapan |
|---|---|---|
| `resolve*()` | DTO | mengubah input mentah jadi objek siap pakai |
| `analyze()` / `handle()` | DTO hasil | entry point service orkestrator |
| `is*()` / `has*()` | `bool` | predikat |
| `assert*()` | `void` | validasi yang melempar exception bila gagal |
| `for*()` | objek/array | lookup berdasarkan kunci |

---

## 4. Aturan Layering — Yang Boleh & Tidak

```php
// ✓ BENAR — service memakai repository
final class AnalyzeService
{
    public function __construct(
        private readonly DocumentRepositoryInterface $documents,
        private readonly ProfileRepositoryInterface $profiles,
    ) {}
}

// ✗ SALAH — service menyentuh filesystem langsung
$content = file_get_contents($path);

// ✗ SALAH — service memanggil proses eksternal langsung
$process = new Process(['tesseract', ...]);

// ✗ SALAH — controller memuat business logic
public function analyze(Request $r) {
    if ($r->doc_type === 'NPWP') { /* ... */ }
}
```

**Injeksi selalu lewat interface, bukan kelas konkret.** Binding-nya di `OcrServiceProvider`. Ini yang membuat test bisa menukar implementasi dengan fake.

---

## 5. DTO

- `final class`, seluruh properti `public readonly`, di-promote di constructor.
- Tanpa setter. Butuh varian baru → buat instance baru.
- Tanpa logika bisnis. Method yang diizinkan hanya turunan sepele (`isPdf()`, `bbox()`).
- Jangan mengoper array asosiatif antar layer bila sudah ada DTO-nya.

---

## 6. Error Handling

Seluruh kegagalan yang terduga dilempar sebagai `OcrException` lewat named constructor, **jangan** `abort()` atau `response()->json()` di dalam service.

| Named constructor | HTTP | Kode | Dipakai untuk |
|---|---|---|---|
| `invalidPayload()` | 400 | `INVALID_PAYLOAD` | payload salah bentuk |
| `pathNotAllowed()` | 400 | `PATH_NOT_ALLOWED` | path di luar whitelist |
| `unauthorized()` | 401 | `UNAUTHORIZED` | token tidak ada / salah |
| `fileNotFound()` | 404 | `FILE_NOT_FOUND` | berkas tidak ada |
| `fileTooLarge()` | 413 | `FILE_TOO_LARGE` | melebihi batas ukuran |
| `unsupportedMediaType()` | 415 | `UNSUPPORTED_MEDIA_TYPE` | ekstensi/magic bytes tidak didukung |
| `unreadableDocument()` | 422 | `UNREADABLE_DOCUMENT` | halaman kosong / tidak terbaca |
| `engineFailure()` | 500 | `ENGINE_FAILURE` | binary gagal |
| `timeout()` | 504 | `TIMEOUT` | proses melewati batas waktu |

> **Penting:** consumer memutuskan retry berdasarkan status ini — 5xx boleh diulang, 4xx tidak. **Jangan memakai 500 untuk kesalahan input**, karena akan membuat job rusak berputar sampai batas percobaan habis.

---

## 7. Testing

**Wajib** untuk setiap penambahan:

| Yang ditambahkan | Test minimum |
|---|---|
| Parser baru | 4 kasus: dokumen normal, dokumen ber-noise OCR, teks tanpa field target (harus kembalikan kosong, **bukan** exception), teks acak/halaman kosong |
| Method public service | minimal 2 kasus: jalur sukses + minimal 1 jalur gagal |
| Endpoint baru | feature test: sukses, tanpa token → 401, payload invalid → 400 |
| Repository | jalur sukses + tiap cabang validasi yang melempar exception |

**Aturan mutlak: test suite tidak boleh butuh binary OCR terpasang.**
- Unit test parser memakai fixture teks di `tests/fixtures/`
- Feature test mem-bind `FakeEngine` lewat service container
- Test yang benar-benar butuh binary asli ditandai group `#[Group('binary')]` dan dikecualikan dari run default

```bash
php artisan test                        # harus hijau di mesin tanpa Tesseract
php artisan test --group=binary         # dijalankan manual di mesin yang lengkap
```

---

## 8. Config

- Seluruh knob di `config/ocr.php`, dibaca dari env dengan default yang masuk akal.
- **Dilarang** memanggil `env()` di luar file config — hasilnya `null` begitu config di-cache.
- Path binary, timeout, batas ukuran, profil per jenis dokumen, dan token: semuanya config, tidak boleh hardcode di kode.

---

## 9. Keamanan

| Aturan | Penerapan |
|---|---|
| Argumen proses eksternal selalu **array** | `new Process([$bin, ...$args])` — tidak pernah string shell |
| Path dari luar wajib divalidasi ke whitelist | `DocumentRepository::assertWithinAllowedBasePaths()` |
| Ekstensi tidak cukup — cek magic bytes | `assertMagicBytes()` |
| Token dibandingkan dengan `hash_equals()` | mencegah timing attack |
| File turunan dihapus di `finally` | termasuk saat exception |
| Log tidak pernah memuat `raw_text` | log hanya: request_id, doc_type, mode, durasi, engine |
| Playground dimatikan di produksi | `OCR_PLAYGROUND_ENABLED=false` |

---

## 10. Menambah Jenis Dokumen Baru

Urutan yang benar:

1. Buat `app/Parsers/{Jenis}Parser.php` implements `DocumentParserInterface`
2. Daftarkan di `ParserRepository`
3. Tambah profil di `config/ocr.php` → `profiles.{DOC_TYPE}` (psm, dpi, preprocessing, bahasa)
4. Tambah fixture teks di `tests/fixtures/`
5. Tulis unit test (4 kasus minimum, §7)
6. Jenis dokumen otomatis muncul di `GET /api/v1/doc-types` — **jangan** hardcode daftarnya di controller

Tidak ada langkah yang boleh dilewati. Terutama langkah 6: consumer membaca kemampuan service dari endpoint itu, bukan dari asumsi.
