# Hasil Evaluasi VLM Lokal — Tahap 1

**Status:** Tahap 1 & 2 selesai — VLM terintegrasi sebagai engine
**Diukur:** 2026-08-19
**Rencana asal:** `vlm-lokal.md`

Dokumen ini mencatat hasil pengukuran nyata di PC evaluasi. Menggantikan
dugaan di `vlm-lokal.md` §4 dan §5 dengan angka terukur.

---

## 1. Lingkungan yang terpasang

| Komponen | Hasil |
|---|---|
| Runtime | Ollama 0.32.13 (native Windows, API di `localhost:11434`) |
| GPU terdeteksi | RTX 5060, `library=CUDA compute=12.0`, driver 13.3 |
| VRAM | 8,0 GiB total, ~6,9 GiB tersedia (desktop memakai sisanya) |
| Lokasi model | `F:\AI\ollama\models` (drive C hanya tersisa 19 GB) |

**Catatan Blackwell terjawab.** `vlm-lokal.md` §4 menandai dukungan sm_120
sebagai pemeriksaan pertama sebelum apa pun. Ollama mengenali GPU sebagai
compute 12.0 lewat CUDA v13 tanpa penyetelan tambahan. Tidak ada penghalang.

---

## 2. Langkah pemasangan (mesin baru)

Dijalankan di PC evaluasi; urutan yang sama berlaku untuk mesin lain.

```powershell
# 1. Pasang Ollama
winget install --id Ollama.Ollama -e

# 2. Simpan bobot model di drive lain bila C: sempit.
#    Set SEBELUM menarik model; bila model sudah terlanjur ada,
#    hentikan Ollama, pindahkan folder models, baru set variabel ini.
[Environment]::SetEnvironmentVariable("OLLAMA_MODELS", "F:\AI\ollama\models", "User")

# 3. Tarik model (jalankan di shell BARU agar variabel di atas terbaca)
ollama pull qwen3-vl:4b

# 4. Pastikan GPU terpakai — cari "library=CUDA" pada log
ollama serve
```

Verifikasi cepat:

```bash
curl http://localhost:11434/api/tags          # model terdaftar
php artisan ocr:doctor                        # binary Tesseract dkk
php artisan ocr:analyze <berkas> --doc-type=NPWP --engine=hybrid --raw
```

Yang menandakan GPU benar-benar dipakai, dari log `ollama serve`:

```
library=CUDA compute=12.0 name="NVIDIA GeForce RTX 5060" driver=13.3
```

Bila `ollama ps` menampilkan `100% GPU`, model muat seluruhnya. Bila tampil
`11%/89% CPU/GPU` seperti pada 8B, sebagian lapisan melimpah ke CPU dan
prosesnya melambat hampir dua kali.

**Catatan:** service ini tidak memerlukan Ollama untuk berjalan. Tanpa host VLM,
engine `tesseract` bekerja seperti sebelumnya dan `hybrid` mengembalikan hasil
Tesseract apa adanya.

---

## 3. Penempatan model — VRAM 8 GB

| Model | Ukuran | Penempatan | Waktu (foto NPWP) |
|---|---|---|---|
| `qwen3-vl:8b` | 6,2 GB | **11% CPU / 89% GPU** | 21,8 detik |
| `qwen3-vl:4b` | 3,5 GB | **100% GPU** | 13,1 detik |

8B tidak muat penuh: 7778 dari 8151 MiB terpakai dan sebagian lapisan
melimpah ke CPU. 4B muat seluruhnya dan hampir dua kali lebih cepat.

**Qwen3-VL, bukan Qwen2.5-VL.** `vlm-lokal.md` §5 menominasikan Qwen2.5-VL;
generasi Qwen3-VL sudah tersedia dan menjadi yang dipakai di sini.

---

## 4. Hasil pada dokumen asli

Berkas: `NPWP.jpg` — foto kartu NPWP berlaminasi, 3,7 MB. Inilah dokumen
yang menjadi baseline kegagalan di `vlm-lokal.md` §2.

### Tesseract 5.4.0 (`raw_text`)

```
NPWP : 82.354.186.7-629.000
AHMAD AFIFUDIN
NIK : 3504142707920002 _
ee PALDAWIR
| KAB. TULUNGAGUNG JAWA TIMUR
```

`address` terparsing menjadi `ee PALDAWIR KAB. TULUNGAGUNG JAWA TIMUR`,
**confidence 0**. `vendor_name` benar tetapi **confidence 1,72**.

### Qwen3-VL (4B dan 8B, keluaran identik)

```
NPWP : 82.354.186.7-629.000
AHMAD AFIFUDIN
NIK : 3504142707920002
DSN KRAJAN 3 RT. 002 RW. 005
BETAK, KALIDAWIR
KAB. TULUNGAGUNG JAWA TIMUR
KPP PRATAMA TULUNGAGUNG
```

**Yang dipulihkan VLM:**

| Baris | Tesseract | VLM |
|---|---|---|
| `DSN KRAJAN 3 RT. 002 RW. 005` | **hilang total** | terbaca |
| `BETAK, KALIDAWIR` | rusak → `ee PALDAWIR` | terbaca |
| `KAB. TULUNGAGUNG JAWA TIMUR` | terbaca | terbaca |

Nomor NPWP dan NIK: **sepakat persis** antara Tesseract dan VLM.

Pola yang sama terulang pada `npwp-foto-dalam.pdf` (PDF berisi foto yang
sama): Tesseract hanya menyisakan baris kabupaten, dua baris di atasnya hilang.

---

## 5. Syarat lulus Tahap 1

`vlm-lokal.md` §Tahap 1 menetapkan dua syarat yang sama pentingnya.

| Syarat | Hasil |
|---|---|
| Membaca baris yang Tesseract lewatkan | **LULUS** — dua baris alamat dipulihkan |
| Tidak mengarang baris yang tidak ada | **LULUS** — lihat di bawah |

Uji tidak-mengarang yang dijalankan:

- **Gambar putih kosong** → keluaran kosong, bukan tebakan
- **Pengulangan 3x pada foto yang sama** → digit NPWP dan NIK identik setiap kali
- **Dua model berbeda ukuran (4B vs 8B)** → keluaran identik karakter demi karakter
- **Dokumen yang Tesseract sudah benar** (`uji-ocr.png`) → VLM tetap benar, tidak merusak

Kesepakatan dua model independen dan stabilitas antar-pengulangan adalah
bukti terkuat yang bisa didapat tanpa kunci jawaban — dan ini penting karena
NPWP tidak punya digit pemeriksa (`vlm-lokal.md` §6 Tahap 3).

---

## 6. Anggaran waktu

Anggaran per dokumen 30 detik (`CONTEXT.md` §9).

| Jalur | Waktu |
|---|---|
| Tesseract (foto NPWP) | 3,4 detik |
| VLM 4B, model panas | 8–13 detik |
| VLM 8B, model panas | 15–22 detik |
| VLM, **cold start** | **+55 detik** |

Cold start adalah risiko nyata: pemanggilan pertama setelah model dilepas
dari VRAM memakan 68 detik total — melewati anggaran. Ollama melepas model
setelah idle beberapa menit. Bila VLM dipakai di produksi, model wajib
ditahan di memori (`keep_alive`), dan itu berarti GPU tidak bisa dipakai
hal lain.

---

## 7. Rekomendasi

**Model:** `qwen3-vl:4b`. Bukan karena lebih murah, melainkan karena pada
korpus ini akurasinya **sama persis** dengan 8B sementara muat 100% di GPU
dan hampir dua kali lebih cepat. 8B tidak memberi keuntungan yang terukur
di sini dan justru melimpah ke CPU.

**Pola:** fallback, bukan pengganti — sesuai `vlm-lokal.md` §Tahap 3.

Alasannya terlihat jelas dari data: `npwp-contoh.pdf` lewat jalur
TEXT_LAYER dalam 426 ms dengan hasil sempurna. Menjalankan VLM di situ
menambah belasan detik tanpa perbaikan apa pun. VLM hanya bernilai pada
kelompok ketiga — foto kartu yang Tesseract gagal baca.

Pemicu fallback yang datanya sudah mendukung: **`confidence` rendah**.
Pada `NPWP.jpg`, `address` bernilai 0 dan `vendor_name` bernilai 1,72 —
service sudah tahu hasilnya meragukan. Itu sinyal yang siap dipakai.

**Yang tetap tidak berubah:** Tesseract tidak dibuang. Ia tetap jalur utama
untuk scan bersih, tetap satu-satunya sumber `bbox` dan `confidence`
per kata, dan menjadi pembanding pada pola pemeriksa silang.

---

## 8. Alur kerja setelah diintegrasikan

Tahap 2 sudah dikerjakan. Bagian ini menjelaskan cara kerjanya di dalam kode.

### 8.1 Tiga engine yang bisa dipilih

| Engine | Cara kerja | bbox | Kapan dipakai |
|---|---|---|---|
| `tesseract` | seperti sebelumnya | ada | scan bersih, PDF hasil render |
| `vlm` | VLM saja, Tesseract tidak dijalankan | **null** | membandingkan bacaan saat menyelidiki |
| `hybrid` | Tesseract dulu, VLM bila hasilnya meragukan | ada | **pilihan yang disarankan** |

Pemilihan tersedia di tiga tempat: dropdown playground, opsi `--engine` pada
`ocr:analyze` dan `ocr:scan`, serta `options.engine` pada permintaan API.
Nama engine divalidasi terhadap daftar putih `ocr.engine.selectable` — `fake`
sengaja tidak masuk daftar agar tidak bisa dipanggil dari luar.

### 8.2 Alur `hybrid` per halaman

```
berkas
  │
  ├─ punya text layer? ──ya──► pdftotext ──► parser        (VLM tidak terlibat)
  │                            ~430 ms, akurasi ~100%
  ▼ tidak
rasterisasi + perbaikan citra
  │
  ▼
Tesseract  ──►  PageResult (teks + word box + confidence)
  │
  ▼
ragu?  ── tidak ──►  pakai hasil Tesseract          (~3 detik, tanpa biaya VLM)
  │
  ▼ ya
VLM transkripsi (gambar dikecilkan ke 2000 px)
  │
  ▼
gabung dua bacaan  ──►  parser  ──►  ConfidenceService
```

Memilih engine secara eksplisit **memaksa jalur OCR**. Tanpa itu, PDF
ber-text-layer akan mengabaikan pilihan tersebut tanpa penjelasan, dan di
playground pilihannya seolah tidak berpengaruh.

### 8.3 Penanda "ragu"

Yang memicu VLM, diperiksa per halaman:

1. Teks hasil Tesseract lebih pendek dari 40 karakter, **atau**
2. Lebih dari 15% katanya berconfidence di bawah 60

Penanda kedua sengaja **bukan rata-rata**. Diukur pada korpus:

| Dokumen | Rata-rata | Kata rendah | Kenyataan |
|---|---|---|---|
| `NPWP.jpg` | 66,1 | **31%** | dua baris alamat hilang |
| `uji-ocr.png` | 93,4 | **0%** | terbaca utuh |

Rata-rata 66,1 lolos ambang mana pun yang masuk akal — kata yang terbaca baik
menutupi kata yang gagal. Proporsi kata rendah memisahkan keduanya dengan tegas.

### 8.4 Cara dua bacaan digabung

Bukan penempelan di akhir. Parser membaca alamat **secara posisional** —
beberapa baris tepat setelah label NIK — sehingga urutan baris menentukan
apakah sebuah baris terparsing atau tidak.

Aturannya:

1. Bacaan VLM menjadi kerangka teks (pada halaman ini ia yang lebih utuh)
2. Baris Tesseract yang tidak dikenali VLM disisipkan mengikuti tetangganya
3. Baris Tesseract yang **mirip ≥60%** dengan baris VLM dibuang — itu versi
   rusaknya (`ee PALDAWIR` terhadap `BETAK, KALIDAWIR`)
4. Baris yang lebih pendek dari 4 karakter dibuang

Aturan keempat penting dan sempat terlewat. Serpihan seperti `N` menyita satu
slot dari jatah tiga baris parser dan mendorong baris ketiga keluar — hasilnya
`"N DSN KRAJAN 3 RT. 002 RW. 005 BETAK, KALIDAWIR"` dengan
`KAB. TULUNGAGUNG JAWA TIMUR` **hilang**. Serpihan itu memakan data, bukan
sekadar mengotori.

Word box tetap milik Tesseract, tetapi hanya untuk kata yang benar-benar
bertahan di teks akhir. Tanpa penyaringan itu, field yang teksnya dipulihkan
VLM akan dinilai memakai keyakinan Tesseract atas bacaan yang justru salah.

### 8.5 Membaca angka confidence

Perbandingan pada `npwp-foto-dalam.pdf` — nilai fieldnya **identik**, angkanya berbeda:

| Field | `vlm` | `hybrid` |
|---|---|---|
| `npwp_no` | 100 | 91,88 |
| `nik` | 100 | 91,45 |
| `vendor_name` | 75 | 35,64 |
| `address` | 75 | 75 |

**Angka `vlm` yang lebih tinggi bukan berarti lebih dapat dipercaya.** VLM tidak
menghasilkan word box, sehingga `ConfidenceService` melewati penilaian karakter
seluruhnya dan meneruskan nilai parser apa adanya: 100 untuk field berlabel.
Model tidak pernah menyatakan keyakinan apa pun — 100 di situ adalah kekosongan
informasi yang menyamar sebagai kepastian.

Angka `hybrid` lebih rendah justru karena ia benar-benar diukur. Untuk service
yang seluruh pertahanan false-positive-nya bersandar pada confidence, angka yang
terukur lebih berguna daripada angka yang tinggi.

Catatan: `vendor_name` 35,64 juga muncul pada `tesseract` murni. Itu perilaku
Tesseract yang diwarisi apa adanya, bukan akibat penggabungan.

### 8.6 Ketika host VLM mati

VLM adalah penolong, bukan syarat. Bila host tidak dapat dihubungi, `HybridEngine`
mengembalikan hasil Tesseract apa adanya — permintaan tidak digagalkan. Pada mode
`vlm` murni, kegagalan host memang menggagalkan permintaan, karena tidak ada
bacaan lain yang tersisa.

### 8.7 Biaya

| Berkas | Jalur | Waktu |
|---|---|---|
| `npwp-contoh.pdf` | text layer (default) | 428 ms |
| `uji-ocr.png` | Tesseract, VLM tidak dipicu | 652 ms |
| `NPWP.jpg` | Tesseract + VLM | 14,1 detik |
| `npwp-foto-dalam.pdf` | Tesseract + VLM | 17–18 detik |

Dokumen yang sudah terbaca baik tidak membayar apa pun. Yang membayar hanya
dokumen yang memang gagal — dan itulah maksud pola fallback.

Model ditahan di VRAM selama 30 menit (`keep_alive`). Tanpa penahanan,
pemanggilan pertama setelah model dilepas menambah ~55 detik.

### 8.8 Berkas yang terlibat

| Berkas | Peran |
|---|---|
| `app/Engines/HybridEngine.php` | penanda ragu, penggabungan, penyaringan word box |
| `app/Engines/VlmEngine.php` | pembungkus VLM sebagai `OcrEngineInterface` |
| `app/Repositories/VlmRepository.php` | HTTP ke host model, pengecilan gambar |
| `app/Contracts/Repositories/VlmRepositoryInterface.php` | kontrak repository |
| `config/ocr.php` | blok `vlm`, `hybrid`, `engine.selectable` |
| `app/Services/AnalyzeService.php` | meneruskan pilihan engine, memaksa jalur OCR |
| `app/DTO/AnalyzeOptions.php` | validasi nama engine terhadap daftar putih |
| `resources/views/playground.blade.php` | dropdown engine |
| `tests/Unit/Engines/HybridEngineTest.php` | 14 test, tanpa binary maupun host model |

---

## 9. Yang masih belum terjawab

Ukuran korpus. Evaluasi ini memakai **4 berkas, dan hanya 2 dokumen unik**
(foto NPWP yang sama dalam bentuk JPG dan PDF, plus dua contoh sintetis).
`vlm-lokal.md` §Tahap 0 meminta 20–30 dokumen vendor asli untuk menghitung
tiga angka pembagi: berapa persen lewat text-layer, berapa persen OCR-nya
sudah memadai, dan berapa persen yang benar-benar gagal.

Angka ketiga itulah yang menentukan apakah biaya GPU sebanding — dan angka
itu **belum ada**. Yang sudah terbukti hari ini adalah VLM *sanggup*
menutup celah, bukan *seberapa sering* celah itu muncul.

Belum diuji juga: NIB, KTP, SK Kemenkumham. Seluruh pengukuran di atas
hanya NPWP.
