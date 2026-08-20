<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Doc OCR Service — Playground</title>
    <style>
        :root { color-scheme: light dark; }
        body { font-family: ui-sans-serif, system-ui, "Segoe UI", sans-serif; margin: 0; padding: 2rem; line-height: 1.5; }
        .wrap { max-width: 1000px; margin: 0 auto; }
        h1 { font-size: 1.35rem; margin: 0 0 .25rem; }
        .sub { opacity: .7; font-size: .9rem; margin-bottom: 1.5rem; }
        form { border: 1px solid rgba(128,128,128,.35); border-radius: 8px; padding: 1.25rem; }
        .row { display: flex; flex-wrap: wrap; gap: 1rem; align-items: flex-end; }
        label { display: block; font-size: .85rem; margin-bottom: .25rem; }
        select, input[type=file] { padding: .4rem; }
        .checks { display: flex; gap: 1.25rem; margin: 1rem 0; font-size: .9rem; }
        .checks label { display: inline-flex; align-items: center; gap: .35rem; margin: 0; }
        button { padding: .5rem 1.25rem; border-radius: 6px; border: 0; background: #b8232f; color: #fff; cursor: pointer; font-size: .95rem; }
        .err { border: 1px solid #b8232f; background: rgba(184,35,47,.08); border-radius: 8px; padding: .75rem 1rem; margin-top: 1.5rem; }
        table { border-collapse: collapse; width: 100%; margin-top: 1rem; font-size: .9rem; }
        th, td { border: 1px solid rgba(128,128,128,.35); padding: .45rem .6rem; text-align: left; vertical-align: top; }
        th { background: rgba(128,128,128,.12); }
        pre { background: rgba(128,128,128,.12); padding: 1rem; border-radius: 8px; overflow-x: auto; font-size: .8rem; }
        .meta { display: flex; flex-wrap: wrap; gap: .5rem; margin-top: 1.5rem; }
        .chip { border: 1px solid rgba(128,128,128,.35); border-radius: 999px; padding: .15rem .7rem; font-size: .8rem; }
        .warn { color: #a06000; font-size: .9rem; }
    </style>
</head>
<body>
<div class="wrap">
    <h1>Doc OCR Service — Playground</h1>
    <p class="sub">Uji coba manual tanpa Postman. Halaman ini memproses dokumen tanpa token, jadi wajib dimatikan di produksi.</p>

    <form method="post" action="{{ url('/playground') }}" enctype="multipart/form-data">
        <div class="row">
            <div>
                <label for="file">Berkas dokumen</label>
                <input id="file" type="file" name="file" required>
            </div>
            <div>
                <label for="doc_type">Jenis dokumen</label>
                <select id="doc_type" name="doc_type">
                    <option value="">(klasifikasi otomatis)</option>
                    @foreach ($docTypes as $code)
                        <option value="{{ $code }}">{{ $code }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="row">
            <div>
                <label for="engine">Engine</label>
                <select id="engine" name="engine">
                    <option value="">(default: {{ config('ocr.engine.default') }})</option>
                    @foreach ($engines as $code)
                        <option value="{{ $code }}" @selected(request('engine') === $code)>
                            {{ $code }}@if ($code === 'hybrid') — Tesseract + VLM bila hasil meragukan @elseif ($code === 'vlm') — VLM saja, tanpa bbox @endif
                        </option>
                    @endforeach
                </select>
            </div>
            <div></div>
        </div>

        <div class="checks">
            <label><input type="checkbox" name="return_raw_text" value="1" checked> raw_text</label>
            <label><input type="checkbox" name="return_words" value="1"> bbox per kata</label>
            <label><input type="checkbox" name="force_ocr" value="1"> paksa OCR</label>
        </div>

        <button type="submit">Analisa</button>
    </form>

    @if ($error)
        <div class="err">{{ $error }}</div>
    @endif

    @if ($result)
        <div class="meta">
            <span class="chip">doc_type: {{ $result['doc_type'] ?? '-' }}</span>
            <span class="chip">mode: {{ $result['mode'] }}</span>
            <span class="chip">engine: {{ $result['engine']['name'] }} {{ $result['engine']['version'] }}</span>
            <span class="chip">halaman: {{ $result['pages_processed'] }}/{{ $result['page_count'] }}</span>
            <span class="chip">{{ $result['processing_ms'] }} ms</span>
        </div>

        @foreach ($result['warnings'] as $warning)
            <p class="warn">{{ $warning }}</p>
        @endforeach

        @php $fields = (array) $result['fields']; @endphp

        @if ($fields === [])
            <p class="warn">Tidak ada field yang berhasil diekstrak.</p>
        @else
            <table>
                <thead>
                <tr><th>field</th><th>value</th><th>raw</th><th>confidence</th><th>halaman</th></tr>
                </thead>
                <tbody>
                @foreach ($fields as $name => $field)
                    <tr>
                        <td>{{ $name }}</td>
                        <td>{{ $field['value'] }}</td>
                        <td>{{ $field['raw'] }}</td>
                        <td>{{ $field['confidence'] }}</td>
                        <td>{{ $field['page'] }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif

        <h2 style="font-size:1rem;margin-top:2rem;">Response JSON</h2>
        <pre>{{ json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
    @endif
</div>
</body>
</html>
