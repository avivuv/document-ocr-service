<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | API Tokens
    |--------------------------------------------------------------------------
    | Service ini stateless — tanpa database. Token statis didefinisikan di env
    | dengan format "nama:token,nama2:token2" dan dibandingkan memakai
    | hash_equals() agar tidak bocor lewat timing attack.
    |
    | Generate token: php artisan ocr:token
    */
    'tokens' => array_filter(array_reduce(
        array_filter(array_map('trim', explode(',', (string) env('OCR_API_TOKENS', '')))),
        static function (array $carry, string $pair): array {
            [$name, $token] = array_pad(explode(':', $pair, 2), 2, null);
            if ($name !== null && $token !== null && $token !== '') {
                $carry[$name] = $token;
            }

            return $carry;
        },
        []
    )),

    /*
    |--------------------------------------------------------------------------
    | Binary Eksternal
    |--------------------------------------------------------------------------
    | Kosongkan untuk memakai nama binary di PATH. Isi dengan path absolut bila
    | binary tidak terdaftar di PATH (kasus umum di Windows Server / IIS).
    */
    'bin' => [
        'tesseract' => env('OCR_BIN_TESSERACT', 'tesseract'),
        'pdftotext' => env('OCR_BIN_PDFTOTEXT', 'pdftotext'),
        'pdftoppm'  => env('OCR_BIN_PDFTOPPM', 'pdftoppm'),
        'pdfinfo'   => env('OCR_BIN_PDFINFO', 'pdfinfo'),
        'magick'    => env('OCR_BIN_MAGICK', 'magick'),
        'gs'        => env('OCR_BIN_GS', 'gswin64c'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Timeout per Proses (detik)
    |--------------------------------------------------------------------------
    */
    'timeout' => [
        'probe'      => (int) env('OCR_TIMEOUT_PROBE', 20),
        'rasterize'  => (int) env('OCR_TIMEOUT_RASTERIZE', 120),
        'preprocess' => (int) env('OCR_TIMEOUT_PREPROCESS', 60),
        'ocr'        => (int) env('OCR_TIMEOUT_OCR', 120),
        'version'    => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Batasan Input
    |--------------------------------------------------------------------------
    */
    'limits' => [
        'max_file_bytes' => (int) env('OCR_MAX_FILE_BYTES', 10 * 1024 * 1024),
        'max_pages'      => (int) env('OCR_MAX_PAGES', 5),
        'max_pages_hard' => 20,

        /*
         * Batas sisi terpanjang hasil rasterisasi, dalam piksel.
         *
         * dpi saja tidak cukup sebagai pengaman. PDF hasil bungkus foto memakai
         * ukuran halaman sebesar piksel gambarnya — foto 4000x3000 menjadi
         * halaman 4000x3000 pt, yaitu 55x42 inci. Dirender 300 dpi hasilnya 208
         * megapiksel dan prosesnya memakan dua menit lalu gagal.
         */
        'max_raster_px'  => (int) env('OCR_MAX_RASTER_PX', 3500),
    ],

    'allowed_extensions' => ['pdf', 'jpg', 'jpeg', 'png', 'tif', 'tiff', 'bmp'],

    /*
    |--------------------------------------------------------------------------
    | Base Path yang Boleh Dibaca (source.type = "path")
    |--------------------------------------------------------------------------
    | Whitelist direktori. Path di luar daftar ini ditolak — mencegah path
    | traversal membaca file sembarang di server.
    |
    | Saat integrasi, tambahkan folder tempat aplikasi consumer menyimpan dokumen.
    */
    'allowed_base_paths' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('OCR_ALLOWED_BASE_PATHS', storage_path('app/inbox')))
    ))),

    /*
    |--------------------------------------------------------------------------
    | Deteksi Text Layer
    |--------------------------------------------------------------------------
    | PDF digital (NIB dari OSS, NPWP dari DJP, SK Kemenkumham) punya text layer
    | sehingga bisa dibaca persis tanpa OCR. Ambang ini menentukan berapa banyak
    | karakter alfanumerik per halaman yang dianggap "punya text layer".
    |
    | WAJIB dikalibrasi dengan dokumen asli sebelum dipakai di produksi.
    */
    'text_layer' => [
        'enabled'             => (bool) env('OCR_TEXT_LAYER_ENABLED', true),
        'probe_pages'         => 3,
        'min_alnum_per_page'  => (int) env('OCR_TEXT_LAYER_MIN_ALNUM', 200),
        'max_garbage_ratio'   => 0.30, // PDF hasil scan yang sudah di-OCR mesin → text layer kotor
    ],

    /*
    |--------------------------------------------------------------------------
    | Profil per Jenis Dokumen
    |--------------------------------------------------------------------------
    | psm        — page segmentation mode Tesseract
    | preprocess — profil ImageMagick (lihat 'preprocess_profiles')
    */
    'profiles' => [
        'NPWP' => ['psm' => 6, 'dpi' => 300, 'preprocess' => 'document', 'lang' => 'ind+eng'],
        'NIB'  => ['psm' => 6, 'dpi' => 300, 'preprocess' => 'document', 'lang' => 'ind+eng'],
        'KTP'  => ['psm' => 4, 'dpi' => 400, 'preprocess' => 'photo',    'lang' => 'ind'],
    ],

    'default_profile' => ['psm' => 3, 'dpi' => 300, 'preprocess' => 'document', 'lang' => 'ind+eng'],

    'preprocess_profiles' => [
        /*
         * Untuk halaman hasil rasterisasi PDF: render bersih, kontras tinggi,
         * sehingga ambang batas lokal (LAT) menajamkan huruf.
         *
         * "-resize 3500x3500>" di belakang adalah pengaman: tanpa itu gambar
         * masukan yang sudah besar membengkak dua kali lipat dan membuat
         * Tesseract melewati batas waktu.
         */
        'document' => [
            '-auto-orient', '-colorspace', 'Gray',
            '-deskew', '40%',
            '-resize', '200%',
            '-resize', '3500x3500>',
            '-normalize', '-despeckle',
            '-lat', '25x25-8%',
        ],

        /*
         * Untuk foto kamera (kartu NPWP/KTP berlaminasi, dokumen difoto HP).
         *
         * Sengaja TANPA "-lat": pada kartu berlatar perak dengan watermark
         * berulang, ambang batas lokal justru mengubah watermark menjadi derau
         * dan hasil OCR menjadi tidak terbaca sama sekali.
         *
         * Penyusutan ke 2000 px juga disengaja, bukan penghematan waktu: pada
         * resolusi penuh watermark seukuran huruf sehingga ikut terbaca sebagai
         * teks. Diukur pada foto NPWP 4000x3000 — 2000 px memberi hasil terbaik.
         */
        'photo' => [
            '-auto-orient', '-colorspace', 'Gray',
            '-deskew', '40%',
            '-resize', '2000x2000>',
            '-normalize', '-despeckle',
        ],

        'none' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Profil untuk Berkas Gambar Langsung
    |--------------------------------------------------------------------------
    | Halaman hasil rasterisasi PDF dan foto kamera menuntut perlakuan berbeda,
    | dan perbedaannya tidak bisa disimpulkan dari jenis dokumen. Berkas yang
    | masuk sebagai gambar (bukan PDF) memakai profil ini, apa pun doc_type-nya.
    */
    'image_preprocess_profile' => env('OCR_IMAGE_PREPROCESS_PROFILE', 'photo'),

    /*
    |--------------------------------------------------------------------------
    | Engine
    |--------------------------------------------------------------------------
    | "tesseract" untuk produksi, "fake" untuk test tanpa binary terpasang.
    | Bisa dioverride per jenis dokumen — inilah jalan keluar bila suatu saat
    | KTP perlu engine lain tanpa mengubah kontrak API.
    */
    'engine' => [
        'default' => env('OCR_ENGINE', 'tesseract'),
        'per_doc_type' => [
            // 'KTP' => 'some_other_engine',
        ],

        /*
         * Engine yang boleh dipilih per permintaan. Daftar putih, bukan sekadar
         * dokumentasi: nama engine dari luar tidak boleh menjangkau apa pun yang
         * tidak disebut di sini — termasuk "fake", yang akan mengembalikan hasil
         * palsu bila bisa dipanggil dari HTTP.
         */
        'selectable' => ['tesseract', 'vlm', 'hybrid'],
    ],

    /*
    |--------------------------------------------------------------------------
    | VLM (Vision Language Model) Lokal
    |--------------------------------------------------------------------------
    | Engine kedua yang membaca gambar secara langsung, dipakai untuk kasus yang
    | tidak sanggup ditangani Tesseract — terutama foto kartu berlaminasi yang
    | baris alamatnya hilang. Hasil pengukurannya di .docs/plans/vlm-hasil-evaluasi.md.
    |
    | Host bicara lewat HTTP (Ollama). Berbeda dari binary lain, jadi TIDAK lewat
    | BinaryRepository — tetapi tetap lewat repository tersendiri, bukan service.
    |
    | Model diminta MENTRANSKRIPSI, bukan mengekstrak field: parser yang sudah ada
    | beserta test-nya tetap berlaku, dan jalur ekstraksi tetap deterministik.
    */
    'vlm' => [
        'base_url' => env('OCR_VLM_BASE_URL', 'http://127.0.0.1:11434'),
        'model'    => env('OCR_VLM_MODEL', 'qwen3-vl:4b'),
        'timeout'  => (int) env('OCR_VLM_TIMEOUT', 180),

        /*
         * Model ditahan di VRAM selama ini. Tanpa penahanan, pemanggilan pertama
         * setelah model dilepas memakan ~55 detik tambahan untuk memuat ulang —
         * melewati anggaran 30 detik per dokumen.
         */
        'keep_alive' => env('OCR_VLM_KEEP_ALIVE', '30m'),

        /*
         * Batas sisi terpanjang gambar yang dikirim ke model.
         *
         * Bukan penghematan waktu: beban VRAM sesungguhnya adalah jumlah token
         * citra, bukan bobot model. Satu halaman resolusi penuh bisa meledakkan
         * KV cache jauh melebihi bobotnya. 2000 px terukur cukup pada korpus uji.
         */
        'max_pixels' => (int) env('OCR_VLM_MAX_PIXELS', 2000),

        'prompt' => env('OCR_VLM_PROMPT', 'Transcribe ALL text visible in this document image exactly as it appears, '
            .'line by line, preserving the original order. Do not translate. Do not summarize. '
            .'Do not explain. Do not invent or guess any text that is not clearly visible. '
            .'If a character is unreadable, write [?]. If there is no text at all, output nothing. '
            .'Output only the transcription.'),

        /*
         * Confidence yang dilaporkan pada field hasil bacaan VLM.
         *
         * VLM tidak memberi confidence per kata seperti kolom conf TSV Tesseract,
         * sehingga angka ini TIDAK diturunkan dari model — ia bersandar pada
         * kesepakatan antar-pembaca di mode hybrid. Pada mode vlm murni nilainya
         * tetap null: tidak ada dasar untuk mengaku tahu.
         */
        'agreement_confidence' => (float) env('OCR_VLM_AGREEMENT_CONFIDENCE', 90.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Hybrid — Tesseract + VLM
    |--------------------------------------------------------------------------
    | Tesseract berjalan lebih dulu. VLM dipanggil HANYA bila hasilnya meragukan,
    | sehingga dokumen yang sudah terbaca baik tidak membayar belasan detik
    | tambahan tanpa perbaikan apa pun.
    |
    | Ambang confidence memakai sinyal yang sudah tersedia: pada foto NPWP yang
    | gagal, address bernilai 0 dan vendor_name 1,72 — service sudah tahu hasilnya
    | meragukan sebelum VLM dilibatkan.
    */
    'hybrid' => [
        /*
         * Penanda keraguan BUKAN rata-rata confidence halaman.
         *
         * Diukur pada korpus uji: foto NPWP yang baris alamatnya hilang tetap
         * memberi rata-rata 66,1 — di atas ambang mana pun yang masuk akal —
         * karena kata yang terbaca baik menutupi kata yang gagal. Yang benar-benar
         * membedakan adalah PROPORSI kata berconfidence rendah: 31% pada dokumen
         * yang gagal, 0% pada dokumen yang terbaca utuh.
         */
        'low_word_confidence' => (float) env('OCR_HYBRID_LOW_WORD_CONFIDENCE', 60.0),
        'max_low_word_ratio'  => (float) env('OCR_HYBRID_MAX_LOW_WORD_RATIO', 0.15),

        /* Halaman yang nyaris tidak menghasilkan teks: gagal telak, bukan sekadar ragu. */
        'min_text_length' => (int) env('OCR_HYBRID_MIN_TEXT_LENGTH', 40),
    ],

    /*
    |--------------------------------------------------------------------------
    | Playground
    |--------------------------------------------------------------------------
    | Halaman upload manual di GET /playground untuk uji coba tanpa Postman.
    | WAJIB dimatikan di produksi.
    */
    'playground_enabled' => (bool) env('OCR_PLAYGROUND_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Workspace
    |--------------------------------------------------------------------------
    | Direktori kerja sementara. File turunan (PNG hasil rasterisasi) dihapus
    | setelah tiap request — service tidak menyimpan PII apa pun.
    |
    | TTL hanya jaring pengaman untuk sisa akibat proses yang mati di tengah
    | jalan; jalur normal menghapus berkas di blok finally. Dibuat pendek karena
    | yang mengendap adalah berkas ber-PII.
    */
    'workspace_path'      => storage_path('app/work'),
    'workspace_ttl_hours' => (int) env('OCR_WORKSPACE_TTL_HOURS', 1),

    /*
    |--------------------------------------------------------------------------
    | Klasifikasi Otomatis
    |--------------------------------------------------------------------------
    | Dipakai saat consumer tidak mengirim doc_type. Skor di bawah ambang ini
    | dianggap tidak meyakinkan — lebih baik service tidak menebak jenis dokumen
    | daripada salah memilih parser (lihat CONTEXT §9).
    */
    'classification' => [
        'min_score' => (float) env('OCR_CLASSIFY_MIN_SCORE', 0.35),
    ],
];
