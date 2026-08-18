# API.md — Kontrak API v1

> **Kontrak ini sudah disepakati dengan consumer.** Mengubah bentuk response berarti
> mengubah integrasi setiap aplikasi yang memakainya — tambahkan field baru (aman), jangan
> mengubah atau menghapus field yang sudah ada. Perubahan yang memutus kompatibilitas →
> naikkan ke `/api/v2`.

Base URL pengembangan: `http://127.0.0.1:8000`
Base URL produksi: `http://127.0.0.1:8081` (hanya localhost)

---

## Autentikasi

Setiap request ke `/api/*` wajib membawa token. Dua bentuk diterima:

```http
Authorization: Bearer <token>
```
```http
X-Api-Key: <token>
```

Token didefinisikan di `.env` sebagai pasangan `nama:token` dipisah koma:

```dotenv
OCR_API_TOKENS=app-intranet:abc123...,app-extranet:def456...
```

Tidak ada database — token statis dan dibandingkan dengan `hash_equals()`. Generate token baru: `php artisan ocr:token`.

Tanpa token atau token salah → `401 UNAUTHORIZED`.

---

## `POST /api/v1/documents/analyze`

Menganalisa satu dokumen dan mengembalikan field terstruktur.

### Request — JSON

```jsonc
{
  "source": {
    "type":  "path",              // "path" | "base64" | "upload"
    "value": "D:\\inetpub\\app\\uploads\\dokumen\\a1b2c3.pdf",
    "filename": "npwp.pdf"        // wajib untuk "base64" — menentukan ekstensi
  },
  "doc_type": "NPWP",             // null / dihilangkan → service mengklasifikasi sendiri
  "options": {
    "max_pages":       5,         // default dari config, dibatasi hard cap 20
    "lang":            "ind+eng", // default dari profil jenis dokumen
    "return_raw_text": true,
    "return_words":    false,     // true → sertakan bbox per kata
    "force_ocr":       false      // true → abaikan text layer, paksa OCR (debugging)
  }
}
```

### Request — multipart (untuk pengujian manual / Postman)

```
POST /api/v1/documents/analyze
Content-Type: multipart/form-data

file      = <berkas>
doc_type  = NPWP
options   = {"return_words":true}     (opsional, JSON string)
```

Bila `file` terkirim, `source` boleh dihilangkan — service otomatis memakai `source.type = "upload"`.

### Response `200`

```jsonc
{
  "request_id":  "job-8842",
  "doc_type":    "NPWP",
  "doc_type_confidence": 0.94,      // null bila doc_type dikirim consumer
  "mode":        "TEXT_LAYER",      // "TEXT_LAYER" | "OCR"
  "engine":      { "name": "tesseract", "version": "5.3.4", "lang": "ind+eng" },
  "page_count":     3,
  "pages_processed": 3,
  "processing_ms":  4210,
  "fields": {
    "npwp_no": {
      "value":      "0012345678912000",
      "raw":        "12.345.678.9-012.000",
      "confidence": 96.4,
      "page":       1,
      "bbox":       [412, 233, 268, 31]
    },
    "vendor_name": { "value": "PT ABC INDONESIA", "raw": "PT ABC INDONESIA", "confidence": 91.2, "page": 1, "bbox": null }
  },
  "raw_text": "NPWP\n12.345.678.9-012.000\n...",   // null bila return_raw_text=false
  "warnings": ["halaman 4-5 dilewati (max_pages=3)"]
}
```

**Catatan bentuk data:**

| Field | Arti |
|---|---|
| `value` | sudah ternormalisasi, siap masuk kolom database consumer |
| `raw` | apa adanya di dokumen — untuk audit dan ditampilkan saat review |
| `confidence` | 0–100, gabungan dua keraguan: seberapa yakin **teksnya** terbaca (dari engine) dan seberapa yakin **penafsirannya** (dari parser). Diambil yang terendah. Mode `TEXT_LAYER` bernilai 100 untuk field berlabel; field yang terbaca dari posisi — misalnya nama pada kartu NPWP perorangan yang tidak berlabel — bernilai lebih rendah meski teksnya pasti |
| `bbox` | `[x, y, width, height]` piksel, atau `null` bila tidak tersedia (mode text layer) |
| `fields` | objek, bukan array. Field yang tidak ditemukan **tidak muncul** — bukan bernilai kosong |
| `words` | hanya muncul bila `options.return_words = true` — daftar kata beserta confidence dan bbox |

### Response error

```jsonc
{
  "error": {
    "code":       "UNREADABLE_DOCUMENT",
    "message":    "Dokumen tidak dapat dibaca.",
    "request_id": "job-8842"
  }
}
```

| HTTP | `code` | Boleh retry? | Penyebab |
|---|---|---|---|
| 400 | `INVALID_PAYLOAD` | ✗ | payload salah bentuk |
| 400 | `PATH_NOT_ALLOWED` | ✗ | path di luar `OCR_ALLOWED_BASE_PATHS` |
| 401 | `UNAUTHORIZED` | ✗ | token tidak ada / salah |
| 404 | `FILE_NOT_FOUND` | ✗ | berkas tidak ada di path tersebut |
| 413 | `FILE_TOO_LARGE` | ✗ | melebihi `OCR_MAX_FILE_BYTES` |
| 415 | `UNSUPPORTED_MEDIA_TYPE` | ✗ | ekstensi tidak didukung / isi tidak cocok dengan ekstensi |
| 422 | `UNREADABLE_DOCUMENT` | ✗ | halaman kosong atau rusak |
| 500 | `ENGINE_FAILURE` | **✓** | binary gagal dieksekusi |
| 504 | `TIMEOUT` | **✓** | proses melewati batas waktu — ulangi dengan `max_pages` lebih kecil |

> Pembedaan retry vs permanen ini dipakai worker consumer. Menaruh kesalahan input di 5xx akan
> membuat job rusak berputar sampai batas percobaan habis dan membebani worker tanpa guna.

---

## `GET /api/v1/health`

Status service dan ketersediaan binary. Dipakai worker consumer, monitoring, dan checklist Infra saat deploy.

```jsonc
{
  "status": "ok",                  // "ok" | "degraded"
  "engine": "tesseract",
  "binaries": {
    "tesseract": "5.3.4",
    "pdftotext": "24.02.0",
    "pdftoppm":  "24.02.0",
    "magick":    "7.1.1",
    "gs":        null              // null = tidak terpasang
  },
  "languages": ["eng", "ind"],
  "text_layer_enabled": true
}
```

`status` bernilai `degraded` bila ada binary wajib yang tidak terpasang. Endpoint tetap mengembalikan `200` supaya monitoring bisa membaca detailnya.

---

## `GET /api/v1/doc-types`

Jenis dokumen yang didukung beserta field yang mungkin dikembalikan. Consumer membaca kemampuan service dari sini — **jangan hardcode daftarnya di sisi consumer**.

```jsonc
{
  "doc_types": [
    { "code": "NPWP", "fields": ["npwp_no", "nik", "vendor_name", "address"],    "profile": { "psm": 6, "dpi": 300, "lang": "ind+eng" } },
    { "code": "NIB",  "fields": ["nib_no", "vendor_name", "address", "postal_code", "kbli"], "profile": { "psm": 6, "dpi": 300, "lang": "ind+eng" } }
  ]
}
```

---

## Contoh cURL

```bash
TOKEN="<isi dari OCR_API_TOKENS>"

# Health
curl -s http://127.0.0.1:8000/api/v1/health -H "Authorization: Bearer $TOKEN"

# Analisa via upload multipart
curl -s -X POST http://127.0.0.1:8000/api/v1/documents/analyze \
  -H "Authorization: Bearer $TOKEN" \
  -F "file=@storage/app/inbox/npwp.pdf" \
  -F "doc_type=NPWP"

# Analisa via path di server
curl -s -X POST http://127.0.0.1:8000/api/v1/documents/analyze \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -H "X-Request-Id: manual-001" \
  -d '{"source":{"type":"path","value":"C:/laragon/www/cimb-ocr/storage/app/inbox/npwp.pdf"},"doc_type":"NPWP"}'
```

Koleksi Postman siap impor: `postman/doc-ocr.postman_collection.json`.
