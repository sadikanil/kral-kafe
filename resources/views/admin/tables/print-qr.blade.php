@extends('layouts.app')

@section('title', 'Masa QR Kodları - Kral Kafe')
@section('page-title', 'Masa QR Kodlarını Yazdır')

@section('page-actions')
    <button onclick="window.print()" class="btn btn-primary btn-sm">🖨️ Yazdır</button>
@endsection

@section('content')
    {{-- Uyari rotanin VARLIGINA bagli: oturum ekrani yayina girince kendiliginden
         kaybolur, kimsenin eski bir metni silmeyi hatirlamasi gerekmez. --}}
    @unless(Route::has('table.scan'))
        <div class="alert alert-warning no-print">
            Bu kodların gittiği adres <strong>henüz yayında değil</strong>. Şimdi
            basılan etiketler okutulduğunda boş sayfa gösterir. Kodlar kalıcı
            olduğu için oturum ekranı yayına girdiğinde aynı etiketler çalışır —
            yine de asmadan önce beklemek daha güvenli.
        </div>
    @endunless

    <div class="card no-print mb-3">
        <div class="card-body d-flex align-items-center justify-content-between gap-2">
            <div>
                <strong>{{ $tables->count() }}</strong> masanın QR kodu yazdırılmaya hazır.
                <div class="text-muted" style="font-size: 0.8125rem;">
                    Yalnızca kullanımdaki masalar listelenir. Her kodu kesip masaya yapıştırın.
                </div>
            </div>
            <a href="{{ route('admin.tables.index') }}" class="btn btn-secondary btn-sm">← Masalar</a>
        </div>
    </div>

    @forelse($tables as $table)
        @if($loop->first)
            <div class="qr-print-grid">
        @endif

        <div class="card qr-print-item">
            <div class="card-body text-center p-4">
                <h4 class="mb-3">{{ $table->name }}</h4>

                <img src="{{ \App\Support\QrImage::url($table->qr_url, 260) }}"
                    alt="{{ $table->name }} QR kodu" style="border-radius: var(--r); max-width: 100%;">

                <p class="text-muted mt-3 mb-0" style="font-size: 0.6875rem; word-break: break-all;">
                    {{ $table->qr_code }}
                </p>
            </div>
        </div>

        @if($loop->last)
            </div>
        @endif
    @empty
        <div class="empty-state">
            <div class="empty-state-icon">🪑</div>
            <div class="empty-state-title">Yazdırılacak masa yok</div>
            <p class="text-muted">Kullanımdaki bir masa ekleyin.</p>
        </div>
    @endforelse
@endsection

@push('styles')
    <style>
        .qr-print-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 1rem;
        }

        @media print {
            .layout {
                display: block !important;
            }

            .sidebar,
            .sidebar-overlay,
            .topbar,
            .no-print,
            .btn {
                display: none !important;
            }

            .main-content,
            .page-content {
                margin: 0 !important;
                padding: 0 !important;
            }

            /* A4'e 3x3 = 9 etiket; 32 yer 4 sayfa. 5 cm QR masadan rahat okunur. */
            @page {
                size: A4;
                margin: 1cm;
            }

            .qr-print-grid {
                grid-template-columns: repeat(3, 1fr);
                gap: 0;
            }

            .qr-print-item {
                box-shadow: none !important;
                border: 1px dashed #9ca3af !important;
                border-radius: 0 !important;
                break-inside: avoid;
                page-break-inside: avoid;
            }

            .qr-print-item .card-body {
                padding: 0.6cm 0.3cm !important;
            }

            .qr-print-item h4 {
                font-size: 16pt;
                margin-bottom: 0.3cm !important;
            }

            .qr-print-item img {
                width: 5cm;
                height: 5cm;
            }

            .qr-print-item p {
                margin-top: 0.2cm !important;
            }
        }
    </style>
@endpush
