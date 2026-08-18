# CONTEXT.md — Konteks Bisnis & Pola Integrasi Consumer

> Dokumen ini membuat repo ini bisa dikerjakan **tanpa membuka repo aplikasi consumer**.
> Berisi latar belakang, karakteristik dokumen, dan batas tanggung jawab dengan consumer.
>
> Rencana integrasi sisi consumer (worker, antrian job, panel review) berada di repo consumer,
> bukan di sini.

---

## 1. Latar Belakang

Service ini lahir dari kebutuhan sebuah sistem e-Procurement dengan modul **Vendor Registration**. Vendor mengunggah dokumen legalitas (NPWP, NIB, KTP pengurus, akta, laporan keuangan, rekening bank), lalu tim Vendor Management memverifikasi manual. Data yang sama diketik ulang ke form — sumber utama kelambatan dan salah ketik.

Kebutuhannya: **mengurangi input manual**, bukan menggantikan verifikasi manusia. Hasil OCR selalu melewati review sebelum jadi data final.

Konteks itu dipakai di sepanjang dokumen ini sebagai contoh konkret. Pola yang dijelaskan — worker memanggil service, hasil ditinjau manusia, master data tetap milik consumer — berlaku sama untuk consumer lain.

**Stack consumer pertama:** CodeIgniter 3 (termodifikasi) · PHP 8.2 · IIS · SQL Server. Dua aplikasi (intranet & extranet), satu database.

---

## 2. Wawasan Terpenting: Sebagian Dokumen Tidak Perlu OCR

Dokumen legalitas Indonesia sekarang banyak yang terbit **digital**:

| Dokumen | Penerbit | Bentuk umum |
|---|---|---|
| **NIB** | OSS | PDF digital ber-*text layer* |
| **NPWP** | DJP / Coretax | PDF digital ber-*text layer* |
| **SK Kemenkumham** | AHU Online | PDF digital ber-*text layer* |
| Akta notaris | notaris | scan / foto |
| KTP | — | foto HP / fotokopi scan |
| Laporan keuangan | vendor | scan, tabel kompleks |

Untuk yang ber-text-layer, `pdftotext` mengembalikan teks **persis** — akurasi ~100%, gratis, tanpa OCR sama sekali.

**Konsekuensi desain:** pipeline selalu mencoba jalur text-layer lebih dulu (`mode: TEXT_LAYER`, confidence 100). Tesseract adalah *fallback* untuk berkas hasil scan, bukan jalur utama. Ini yang membuat pendekatan self-hosted layak meski akurasi mentah Tesseract terbatas.

Ada kasus perantara yang harus ditangani: PDF hasil scan yang sudah di-OCR mesin scanner — text layer ada tapi kotor. Deteksi lewat rasio karakter aneh; kalau tinggi, perlakukan sebagai scan.

---

## 3. Jenis Dokumen & Prioritas

| Prioritas | doc_type | Nama umum di form vendor | Alasan |
|---|---|---|---|
| **1** | `NPWP` | NPWP | format baku, sering PDF digital, field langsung dipakai form |
| **1** | `NIB` | Izin Usaha/NIB dan KBU | PDF digital OSS, label konsisten, kaya field |
| 2 | `SK_KEMENKUMHAM` | Akta Perubahan & SK Menkumham | SK digital; akta notaris tetap scan |
| 3 | `KTP` | Copy KTP Pengurus | **akurasi Tesseract rendah** — foto HP, font khusus, latar hologram. Sengaja ditunda |
| — | — | Laporan Keuangan | tabel kompleks, di luar scope |
| — | — | Kode Etik, NDA, Surat Pernyataan, Surat Kuasa, Sertifikat | dokumen tanda tangan, tidak ada field terstruktur |

### Field yang dibutuhkan consumer

Kolom tujuan di form registrasi vendor milik consumer:

| Field service | Kolom consumer | Sumber dokumen |
|---|---|---|
| `npwp_no` | `npwp_no` | NPWP |
| `vendor_name` | `vendor_name` | NPWP / NIB |
| `address` | `vendor_address` | NPWP / NIB |
| `postal_code` | `address_postcode` | NIB |
| `nib_no` | — (baru) | NIB |
| `kbli` | `vendor_bidang_usaha` | NIB |
| `nik` | — | KTP |

### Aturan format yang wajib diketahui parser

- **NPWP** punya dua format: lama **15 digit** (`12.345.678.9-012.000`) dan baru **16 digit** berbasis NIK (berlaku sejak 2024). Normalisasi: buang non-digit; 15 digit di-*pad* jadi 16 dengan `0` di depan. Kolom `npwp_no` di sisi consumer untuk vendor dalam negeri dibatasi 16 karakter — sudah siap format baru.
- **NIB** = 13 digit. **KBLI** = 5 digit.
- **NIK** = 16 digit; digit 1–6 kode wilayah, digit 7–12 tanggal lahir (`dd` > 40 menandakan perempuan, kurangi 40), sisanya nomor urut.

---

## 4. Batas Tanggung Jawab

| Tanggung jawab | Di sini | Di consumer |
|---|---|---|
| Deteksi text-layer, rasterisasi, preprocessing, OCR | ✓ | |
| Klasifikasi jenis dokumen | ✓ | |
| Parsing field & normalisasi | ✓ | |
| Confidence per field | ✓ (lapor saja) | ✓ (tentukan threshold) |
| Mapping master dokumen → `doc_type` | | ✓ |
| Antrian job, retry, scanner | | ✓ |
| Validasi silang vs master wilayah & isian form | | ✓ |
| Workflow review & penyimpanan | | ✓ |

**Garis yang tidak boleh dilewati:** apa pun yang butuh query ke database consumer adalah milik consumer. Kalau sebuah fitur menuntut service ini mengakses database consumer, fitur itu salah tempat.

Contoh konkret: validasi kode wilayah NIK butuh tabel master wilayah Indonesia milik consumer → **service hanya mengembalikan nilai NIK**, consumer yang memvalidasi.

---

## 5. Cara Consumer Memakai Service

Consumer **tidak** memanggil service secara sinkron saat user mengunggah berkas. Alurnya:

```
1. User unggah dokumen (intranet atau extranet) → tersimpan seperti biasa
2. Worker consumer (mis. Windows Task Scheduler, tiap menit) memindai dokumen baru
   → membuat baris antrian di tabel vnd_ocr_job
3. Worker memanggil POST /api/v1/documents/analyze  ← DI SINI
4. Hasil disimpan consumer, ditampilkan di panel review
5. User mengoreksi bila perlu, lalu menekan "Gunakan data ini"
```

Implikasi untuk service:
- **Pemanggil adalah mesin, bukan browser.** Tidak perlu CORS, tidak perlu session, tidak perlu rate limit agresif.
- **Latensi 10–60 detik dapat diterima**, tapi timeout tetap harus tegas supaya worker tidak menggantung.
- **Idempotensi tidak diperlukan** — service stateless, memanggil dua kali menghasilkan hal yang sama.
- Header `X-Request-Id` diisi `job_id` milik consumer; **sertakan selalu di log dan response** agar satu dokumen bisa ditelusuri melintasi log kedua aplikasi.

### Cara berkas dikirim

Default `source.type = "path"`: service berjalan di host yang sama dan membaca berkas langsung dari disk — tidak ada penyalinan 10 MB lewat HTTP tiap job. Dua lokasi yang perlu masuk `OCR_ALLOWED_BASE_PATHS` saat integrasi:

| Consumer | Lokasi berkas |
|---|---|
| Extranet (registrasi & pengkinian vendor) | `{app}/extranet/attachment/{vendor_id}/dokumen_aktivasi/` |
| Intranet (pengajuan & update vendor) | `{app}/uploads/vendor/requestvendor/` |

Akses cukup **read-only**. `base64` dan `upload` tersedia untuk pengujian dan untuk kalau service dipindah ke host lain.

---

## 6. Kepatuhan (UU PDP No. 27/2022)

Dokumen vendor memuat data pribadi: NIK, NPWP, alamat, rekening, tanda tangan.

Alasan utama pendekatan self-hosted dipilih: **data tidak pernah keluar server** — tidak perlu Data Processing Agreement dengan penyedia cloud, tidak perlu review data residency.

Yang wajib dijaga di repo ini:
- Service **tidak menyimpan apa pun secara permanen** — semua berkas turunan dihapus setelah request
- **Log tidak pernah memuat `raw_text`**
- Service hanya bind ke `127.0.0.1` di produksi — tidak dapat dijangkau dari jaringan luar
- Playground dimatikan di produksi

---

## 7. Target Deployment

Windows Server + IIS, sebagai **site tersendiri** di samping aplikasi consumer:

- docroot → `public/`, handler PHP 8.2 FastCGI
- **binding `127.0.0.1:8081`** — tidak terjangkau dari luar host
- FastCGI `activityTimeout`/`requestTimeout` ≥ 180 detik
- App pool: **read-only** ke folder dokumen milik consumer, read-write hanya ke `storage/`
- Binary yang harus dipasang Infra: Tesseract (UB-Mannheim v5.3+, `ind`+`eng` dari **tessdata_best**), poppler-utils (`pdftotext`, `pdftoppm`, `pdfinfo`), ImageMagick, Ghostscript

> **Cara memasangnya menentukan, bukan hanya versinya.** Terbukti di mesin pengembangan:
> `winget install ImageMagick.Q16` memasang paket **MSIX** ke `C:\Program Files\WindowsApps\`
> — direktori berproteksi yang hanya dapat dijangkau lewat *App Execution Alias* milik satu
> user. Identitas app pool IIS tidak akan bisa mengeksekusinya, dan mengisi `OCR_BIN_MAGICK`
> dengan path itu pun akan ditolak izin akses.
>
> Karena itu Infra wajib memasang seluruh binary dengan **installer/arsip konvensional ke
> folder mesin** (mis. `C:\Program Files\ImageMagick-7.x-Q16\`, `C:\tools\poppler\`), bukan
> lewat Microsoft Store maupun paket winget bertipe MSIX, dan bukan ke folder profil user.
> Lalu isi seluruh `OCR_BIN_*` di `.env` dengan **path absolut** — jangan bersandar pada PATH,
> karena PATH app pool berbeda dari PATH terminal administrator.

Alternatif bila site IIS kedua tidak disetujui: jalankan sebagai Windows Service lewat NSSM, tetap di localhost.

---

## 8. Kenapa Laravel, dan Batasannya

**Dipilih karena jalur approval, bukan fitur.** PHP 8.2 sudah terpasang dan disetujui di server, handler FastCGI sudah ada, tim sudah menguasainya. Python (PaddleOCR/OpenCV) akurasinya lebih tinggi tapi menuntut runtime baru yang harus disetujui, di-patch, dan dipantau Infra.

**Batasan yang diterima sadar:** plafon akurasi ada di Tesseract; preprocessing terbatas ImageMagick CLI (kalah dari OpenCV), terasa terutama pada foto HP dan KTP.

**Katup pengaman:** `OcrEngineInterface` adalah kontrak *internal*. Menambah sidecar Python khusus KTP di kemudian hari **tidak mengubah kontrak API** — consumer tetap memanggil endpoint yang sama. Memilih Laravel menunda keputusan itu, bukan menutupnya.

---

## 9. Kriteria Keberhasilan

Bukan "OCR berhasil membaca dokumen", melainkan angka terukur pada dokumen vendor asli:

| Metrik | Target minimum |
|---|---|
| % NPWP+NIB ber-text-layer | ≥ 40% |
| Akurasi field `npwp_no` | ≥ 90% |
| Akurasi field `nib_no` | ≥ 90% |
| Akurasi field `vendor_name` | ≥ 75% |
| **False positive** (terisi tapi salah, confidence ≥ 90) | **≤ 3%** |
| Waktu proses per dokumen (≤ 5 halaman) | ≤ 30 detik |

> **False positive adalah metrik paling kritis.** Field kosong hanya merepotkan user (ketik manual, seperti sekarang). Field terisi salah dengan confidence tinggi berpotensi lolos ke data master vendor — itu risiko nyata, bukan sekadar gangguan UX.
>
> Karena itu: bila parser ragu, **kembalikan kosong**, jangan menebak.
