@extends('layouts.app')

@section('title', 'QR Kod - ' . $table->name)
@section('page-title', 'QR Kod: ' . $table->name)

@section('page-actions')
    <button onclick="window.print()" class="btn btn-primary btn-sm">🖨️ Yazdır</button>
@endsection

@section('content')
    @unless(Route::has('table.scan'))
        <div class="alert alert-warning no-print">
            Bu QR'ın gittiği adres <strong>henüz yayında değil</strong>. Etiketi şimdi
            basarsanız okutan kişi boş bir sayfa görür. Kod kalıcı olduğu için
            oturum ekranı yayına girdiğinde aynı etiket çalışmaya başlar.
        </div>
    @endunless

    <div class="card" style="max-width: 400px; margin: 0 auto;">
        <div class="card-body text-center p-4">
            <h3 class="mb-3">{{ $table->name }}</h3>

            <div class="mb-4">
                <img src="{{ \App\Support\QrImage::url($table->qr_url) }}"
                    alt="{{ $table->name }} QR kodu" style="border-radius: var(--r); max-width: 100%;">
            </div>

            <p class="mb-1"><code>{{ $table->qr_code }}</code></p>
            <p class="text-muted mb-0" style="font-size: 0.75rem; word-break: break-all;">
                {{ $table->qr_url }}
            </p>
        </div>
    </div>
@endsection
