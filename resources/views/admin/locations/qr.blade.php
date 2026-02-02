@extends('layouts.admin')

@section('title', 'QR Kod - ' . $location->name)
@section('page-title', 'QR Kod: ' . $location->name)

@section('topbar-actions')
    <button onclick="window.print()" class="btn btn-primary btn-sm">🖨️ Yazdır</button>
@endsection

@section('content')
    <div class="card" style="max-width: 400px; margin: 0 auto;">
        <div class="card-body text-center p-4">
            <h3 class="mb-3">{{ $location->name }}</h3>
            <p class="text-muted">{{ $location->type_name }}</p>

            <div class="mb-4">
                <img src="https://api.qrserver.com/v1/create-qr-code/?size=300x300&data={{ urlencode($location->qr_url) }}&format=png"
                    alt="QR Code" style="border-radius: var(--radius);">
            </div>

            <p class="text-muted" style="font-size: 0.75rem; word-break: break-all;">
                {{ $location->qr_url }}
            </p>

            <div class="mt-4">
                <a href="{{ route('admin.locations.show', $location) }}" class="btn btn-secondary">← Geri Dön</a>
            </div>
        </div>
    </div>

    <style>
        @media print {
            .layout {
                display: block !important;
            }

            .sidebar,
            .topbar,
            .no-print,
            .btn {
                display: none !important;
            }

            .main-content {
                margin: 0 !important;
                padding: 0 !important;
            }

            .page-content {
                padding: 0 !important;
            }

            .card {
                box-shadow: none !important;
                max-width: 100% !important;
            }
        }
    </style>
@endsection