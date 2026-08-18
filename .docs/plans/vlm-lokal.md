# Plan — Evaluasi VLM Lokal untuk Menaikkan Akurasi

**Status:** usulan, belum dikerjakan
**Ditulis:** 2026-08-18

Dokumen ini merencanakan penggunaan **Vision Language Model (VLM) yang berjalan lokal** untuk menutup kasus yang tidak sanggup ditangani Tesseract. Ditulis sebagai rencana **evaluasi lebih dulu**, bukan rencana implementasi — dengan syarat lulus yang eksplisit di setiap tahap.

---

## 1. Pertanyaan yang harus dijawab

Bukan "bagaimana memasang LLM lokal", melainkan:

> **Apakah VLM membaca dokumen yang hari ini gagal dibaca Tesseract, cukup andal untuk membenarkan biaya operasional GPU?**

Kalau jawabannya tidak, plan ini berhenti di Tahap 1 dan tidak ada kode yang berubah.

---

## 2. Baseline — apa yang sudah terukur

Diukur pada foto kartu NPWP 4000×3000, kartu berlaminasi dengan watermark rapat:

| Field | Hasil Tesseract 5.4.0 | Confidence |
|---|---|---|
| `npwp_no` | ✅ benar | 91.62 |
| `nik` | ✅ benar | 88.65 |
| `vendor_name` | ✅ benar | 75.00 |
| `address` | ⚠️ satu dari tiga baris hilang, satu baris rusak | 0.00 |

Waktu proses 4–5 detik. Jalur text-layer (PDF digital) sudah **~100% akurat** dan tidak menyentuh Tesseract sama sekali.

**Batas yang sudah dipetakan:** sweep 4 skala × 3 mode segmentasi tidak menemukan satu setelan pun yang menangkap ketiga baris alamat. Penguat kontras (`-level`, `-clahe`) justru merusak. Yang menolong hanya memotong area alamat dan memprosesnya terpisah — artinya informasinya **ada di gambar**, Tesseract yang tidak sanggup mengambilnya pada konteks halaman penuh.

Itulah celah yang VLM berpeluang tutup.

---

## 3. Batasan yang tidak boleh dilanggar

| Batasan | Konsekuensi untuk plan ini |
|---|---|
| **Data tidak keluar organisasi** (UU PDP 27/2022) | model wajib lokal. API cloud mana pun langsung gugur |
| **Kontrak API tidak berubah** | consumer tetap memanggil endpoint yang sama; VLM hanya boleh masuk di belakang `OcrEngineInterface` |
| **False positive ≤ 3%** | model generatif cenderung mengarang. Ini risiko utama plan ini, lihat §7 |
| **Service tetap stateless** | tidak ada cache hasil model, tidak ada penyimpanan gambar |

---

## 4. Perangkat keras yang tersedia

| Komponen | Spesifikasi |
|---|---|
| GPU | NVIDIA RTX 5060 8 GB (Blackwell, sm_120) |
| CPU | AMD Ryzen 5 7600 (6 core) |
| RAM | 32 GB DDR5 |
| OS | Windows 11 |

**VRAM 8 GB adalah pengikatnya**, dan pada dokumen ini beban sesungguhnya bukan bobot model melainkan **jumlah token citra**. VLM modern memecah gambar menjadi ribuan token; satu halaman resolusi penuh bisa meledakkan KV cache jauh melebihi bobotnya.

Perkiraan kasar untuk Qwen2.5-VL-7B pada kuantisasi 4-bit: bobot ~4,7 GB, encoder visual ~0,7 GB, KV cache ~0,5–1 GB pada konteks wajar → **sekitar 6–6,5 GB**. Muat, tetapi sempit; resolusi masukan **wajib** dibatasi.

Menariknya ini sejalan dengan temuan hari ini: menyusutkan gambar ke ~2000 px justru menaikkan akurasi Tesseract. Pembatasan yang sama kemungkinan besar juga menguntungkan VLM.

**Catatan Blackwell:** RTX 50-series menuntut CUDA 12.8+ dan build inference yang sudah mendukung sm_120. Ini pemeriksaan pertama sebelum apa pun — kalau stack-nya belum mendukung, seluruh plan tertunda di situ.

---

## 5. Kandidat model

Tidak ada yang bisa dipilih dari spesifikasi saja; ketiganya harus diuji pada dokumen yang sama.

| Model | Ukuran | Catatan |
|---|---|---|
| **Qwen2.5-VL-7B-Instruct** | 4-bit ~5 GB | kandidat utama; punya knob `max_pixels` untuk mengendalikan token citra |
| **Qwen2.5-VL-3B-Instruct** | 4-bit ~2,2 GB | cadangan bila 7B terlalu sempit; ruang lega untuk token citra |
| **MiniCPM-V 2.6** | 4-bit ~5,5 GB | reputasi kuat pada teks di dalam citra |
| **InternVL** (2B–8B) | bervariasi | alternatif yang layak dibandingkan |

**Peringatan jujur:** model 2–3B belum tentu mengalahkan Tesseract pada dokumen berbahasa Indonesia. Kalau 7B tidak muat dan 3B tidak lebih baik, itu jawaban yang sah — dan berarti plan ini berhenti.

---

## 6. Tahapan

### Tahap 0 — Kumpulkan korpus dan ukur (**gerbang go/no-go**)

Tidak ada GPU yang perlu disentuh di tahap ini.

1. Kumpulkan **20–30 dokumen vendor asli** (NPWP dan NIB, campuran PDF digital, hasil scan, dan foto)
2. Jalankan `php artisan ocr:scan storage/app/inbox`
3. Hitung tiga angka:

| Angka | Artinya |
|---|---|
| % dokumen lewat jalur text-layer | VLM memberi **nol** perbaikan pada kelompok ini |
| % dokumen jalur OCR yang hasilnya sudah memadai | juga tidak perlu VLM |
| % dokumen yang benar-benar gagal | **ini saja** yang menjadi sasaran |

**Syarat lanjut:** kelompok ketiga cukup besar untuk membenarkan biaya. Kalau hanya 10%, hentikan — biaya operasional GPU tidak sebanding. Kalau 30–40%, lanjutkan.

Tahap ini juga menghasilkan korpus uji yang dipakai seluruh tahap berikutnya, sekaligus menyelesaikan kalibrasi empat ambang yang masih tertunda di `build-plan.md`.

### Tahap 1 — Eksperimen manual, tanpa menyentuh kode

Di PC GPU, di luar repo ini:

1. Pasang **Ollama** (native Windows, menyediakan API kompatibel OpenAI di `localhost:11434`). Alternatif: LM Studio. `vLLM` butuh WSL2 — tunda sampai terbukti perlu.
2. Verifikasi GPU terpakai dan sm_120 didukung
3. Tarik model kandidat, batasi resolusi masukan
4. Umpankan **dokumen yang gagal dari Tahap 0**, minta transkripsi teks apa adanya — belum field terstruktur
5. Bandingkan dengan `raw_text` Tesseract pada dokumen yang sama

**Syarat lulus:** VLM membaca baris yang Tesseract lewatkan, **dan** tidak mengarang baris yang tidak ada di dokumen. Syarat kedua sama pentingnya dengan yang pertama.

Ukur juga waktu per halaman. Anggaran total per dokumen adalah 30 detik (`CONTEXT.md` §9); Tesseract memakai 4–5 detik, jadi VLM punya ruang tetapi tidak tak terbatas.

### Tahap 2 — Integrasi sebagai engine

Baru dikerjakan bila Tahap 1 lulus.

```
app/Engines/VlmEngine.php                       implements OcrEngineInterface
app/Contracts/Repositories/VlmRepositoryInterface.php
app/Repositories/VlmRepository.php              HTTP ke host model
```

Keputusan desain penting: **VLM diminta mentranskripsi, bukan mengekstrak field.** Keluarannya berupa teks, lalu masuk ke parser yang sudah ada.

Alasannya bukan kemalasan. Cara ini menjaga seluruh parser beserta 97 test-nya tetap berlaku, menjaga jalur ekstraksi tetap deterministik dan dapat diaudit, dan membatasi luas kerusakan bila model berperilaku aneh. Meminta model langsung mengeluarkan JSON field memang berpeluang lebih akurat, tetapi memindahkan penalaran ke tempat yang tidak bisa diuji dengan fixture.

Konfigurasinya sudah tersedia — tidak ada yang perlu dibongkar:

```php
'engine' => [
    'default' => env('OCR_ENGINE', 'tesseract'),
    'per_doc_type' => [
        'KTP' => 'vlm',      // arahkan hanya jenis yang sulit
    ],
],
```

Akses HTTP ditempatkan di repository, bukan di service, sesuai `RULES.md` §1.

### Tahap 3 — Pola pemakaian

Tiga pilihan, dari paling aman ke paling agresif:

| Pola | Cara kerja | Kapan dipilih |
|---|---|---|
| **Fallback** | jalankan Tesseract dulu; panggil VLM hanya bila field kosong atau confidence rendah | default yang disarankan — biaya dan risiko terbatas pada kasus sulit |
| **Pemeriksa silang** | jalankan keduanya; terima field **hanya bila sepakat**, selisih ditandai untuk review | paling menjaga metrik false positive |
| **Pengganti** | VLM menjadi engine utama untuk jenis dokumen tertentu | hanya setelah terbukti konsisten pada korpus nyata |

Pola pemeriksa silang layak diperhatikan khusus. Kelemahan struktural yang sudah dicatat: NPWP **tidak punya digit pemeriksa**, sehingga satu digit salah baca tetap menghasilkan nomor yang berbentuk sah. Kesepakatan dua pembaca yang saling bebas adalah verifikasi terbaik yang bisa dicapai tanpa mengakses database consumer — sesuatu yang dilarang `CLAUDE.md` §2.

---

## 7. Risiko

| Risiko | Mitigasi |
|---|---|
| **Model mengarang field** | minta transkripsi, bukan ekstraksi (Tahap 2); pola pemeriksa silang; uji khusus dokumen kosong dan buram — keluarannya harus kosong, bukan tebakan |
| **`confidence` kehilangan makna** | VLM tidak memberi confidence per kata seperti kolom `conf` TSV Tesseract. Seluruh pertahanan false-positive bersandar pada itu. Pada mode VLM, confidence harus diturunkan dari kesepakatan antar-pembaca, bukan dari model |
| **`bbox` hilang** | bagian dari kontrak API. Pada mode VLM nilainya `null` — sudah diizinkan kontrak, tetapi fitur sorot posisi di sisi consumer akan mati |
| **VRAM tidak cukup** | turunkan ke model 3B, atau batasi resolusi lebih agresif; ukur, jangan asumsikan |
| **Beban approval Infra** | server GPU + runtime Python + bobot model adalah versi jauh lebih besar dari masalah yang membuat Python ditolak di awal (`CONTEXT.md` §8). Bawa hasil Tahap 1 sebagai bukti, jangan mengajukan berdasarkan harapan |
| **Data melintasi jaringan** | bila host model terpisah dari service, gambar melintas antar-mesin. Wajib di jaringan internal tertutup, dan `CONTEXT.md` §6 perlu diperbarui |
| **PC evaluasi ≠ server produksi** | PC ini untuk membuktikan nilainya. Produksi menuntut host GPU tersendiri beserta seluruh konsekuensi pengadaannya |

---

## 8. Yang **tidak** dikerjakan

- **LLM teks di atas keluaran OCR.** Kegagalan hari ini adalah kegagalan pembacaan, bukan penafsiran — karakternya tidak pernah ada di `raw_text`. LLM teks tidak bisa memulihkannya, ia hanya akan mengarang.
- **Mengganti jalur text-layer.** Jalur itu sudah ~100% akurat dan gratis. VLM tidak boleh menyentuhnya.
- **Membuang Tesseract.** Ia tetap jalur utama untuk scan yang bersih, dan menjadi pembanding pada pola pemeriksa silang.
- **Mengubah kontrak API.** Seluruh pekerjaan ini berada di belakang `OcrEngineInterface`.

---

## 9. Langkah pertama

Tahap 0. Kumpulkan 20–30 dokumen asli ke `storage/app/inbox/`, jalankan `ocr:scan`, lalu lihat angkanya.

Selama angka itu belum ada, seluruh pembicaraan tentang model dan GPU masih bersandar pada satu foto — dan satu sampel bukan dasar untuk membeli perangkat keras.
