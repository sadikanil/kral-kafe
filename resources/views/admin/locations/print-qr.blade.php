@extends('layouts.admin')

@section('title', 'QR Kodları Yazdır - Kral Kafe')
@section('page-title', 'QR Kodları Yazdır')

@section('topbar-actions')
    <button onclick="window.print()" class="btn btn-primary btn-sm">🖨️ Yazdır</button>
@endsection

@section('content')
    <div class="card no-print mb-3">
        <div class="card-body d-flex align-items-center justify-content-between gap-2">
            <div>
                <strong>{{ $locations->count() }}</strong> lokasyonun QR kodu yazdırılmaya hazır.
                <div class="text-muted" style="font-size: 0.8125rem;">
                    Her kodu kesip ilgili rafın veya dolabın üzerine yapıştırabilirsiniz.
                </div>
            </div>
            <a href="{{ route('admin.locations.index') }}" class="btn btn-secondary btn-sm">← Lokasyonlar</a>
        </div>
    </div>

    @forelse($locations as $location)
        @if($loop->first)
            <div class="qr-print-grid">
        @endif

        <div class="card qr-print-item">
            <div class="card-body text-center p-4">
                <h4 class="mb-1">{{ $location->name }}</h4>
                <p class="text-muted mb-3" style="font-size: 0.8125rem;">{{ $location->type_name }}</p>

                <img src="https://api.qrserver.com/v1/create-qr-code/?size=260x260&data={{ urlencode($location->qr_url) }}&format=png"
                    alt="{{ $location->name }} QR kodu" style="border-radius: var(--radius); max-width: 100%;">

                <p class="text-muted mt-3 mb-0" style="font-size: 0.6875rem; word-break: break-all;">
                    {{ $location->qr_code }}
                </p>
            </div>
        </div>

        @if($loop->last)
            </div>
        @endif
    @empty
        <div class="card">
            <div class="card-body text-center p-4 text-muted">
                Yazdırılacak aktif lokasyon bulunamadı.
            </div>
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

            .qr-print-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 0;
            }

            .qr-print-item {
                box-shadow: none !important;
                border: 1px dashed #9ca3af !important;
                break-inside: avoid;
                page-break-inside: avoid;
            }
        }
    </style>
@endpush
