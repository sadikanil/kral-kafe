@extends('layouts.app')

@section('title', 'Tutarsızlık Detayı - Kral Kafe')
@section('page-title', 'Tutarsızlık #' . $discrepancy->id)

@section('topbar-actions')
    <a href="{{ route('admin.stock.index') }}" class="btn btn-secondary btn-sm">← Stok Sayımına Dön</a>
    @if($discrepancy->location)
        <a href="{{ route('admin.locations.show', $discrepancy->location) }}" class="btn btn-primary btn-sm">📍 Lokasyon</a>
    @endif
@endsection

@section('content')
    <!-- Durum Şeridi -->
    <div class="stats-grid">
        <div class="stat-card animate-slide-up">
            <div class="stat-icon primary">📦</div>
            <div class="stat-content">
                <div class="stat-value">{{ $discrepancy->expected_quantity }}</div>
                <div class="stat-label">Beklenen</div>
            </div>
        </div>

        <div class="stat-card animate-slide-up" style="animation-delay: 50ms">
            <div class="stat-icon warning">🔢</div>
            <div class="stat-content">
                <div class="stat-value">{{ $discrepancy->actual_quantity }}</div>
                <div class="stat-label">Sayılan</div>
            </div>
        </div>

        <div class="stat-card animate-slide-up" style="animation-delay: 100ms">
            <div class="stat-icon {{ $discrepancy->difference < 0 ? 'danger' : 'success' }}">⚖️</div>
            <div class="stat-content">
                <div class="stat-value">{{ $discrepancy->difference > 0 ? '+' : '' }}{{ $discrepancy->difference }}</div>
                <div class="stat-label">Fark</div>
            </div>
        </div>
    </div>

    <!-- Üst Durum Uyarısı -->
    @if($discrepancy->resolved)
        <div class="alert alert-success mb-4">
            ✅ Bu tutarsızlık {{ $discrepancy->resolved_at?->format('d.m.Y H:i') ?? '-' }} tarihinde
            {{ $discrepancy->resolver?->name ?? 'bilinmeyen yönetici' }} tarafından çözümlendi.
        </div>
    @else
        <div class="alert alert-warning mb-4">
            ⚠️ Bu tutarsızlık henüz çözümlenmedi. Aşağıdaki formu doldurarak kapatabilirsiniz.
        </div>
    @endif

    <!-- Tutarsızlık Bilgisi -->
    <div class="card" style="max-width: 800px;">
        <div class="card-header">
            <h4>Tutarsızlık Bilgisi</h4>
        </div>
        <div class="card-body">
            <table class="table">
                <tr>
                    <td class="text-muted">Lokasyon</td>
                    <td>
                        <span style="font-size: 1.25rem;">
                            @switch($discrepancy->location?->type)
                                @case('shelf') 📚 @break
                                @case('cabinet') 🗄️ @break
                                @case('fridge') ❄️ @break
                            @endswitch
                        </span>
                        @if($discrepancy->location)
                            <a href="{{ route('admin.locations.show', $discrepancy->location) }}">{{ $discrepancy->location->name }}</a>
                            <span class="text-muted">{{ $discrepancy->location->type_name }}</span>
                        @else
                            -
                        @endif
                    </td>
                </tr>
                <tr>
                    <td class="text-muted">Ürün</td>
                    <td>
                        @if($discrepancy->product)
                            {{ $discrepancy->product->emoji }}
                            <a href="{{ route('admin.products.edit', $discrepancy->product) }}">{{ $discrepancy->product->name }}</a>
                        @else
                            -
                        @endif
                    </td>
                </tr>
                <tr>
                    <td class="text-muted">Birim Fiyat</td>
                    <td>{{ $discrepancy->product?->formatted_price ?? '-' }}</td>
                </tr>
                <tr>
                    <td class="text-muted">Birim</td>
                    <td>{{ $discrepancy->product?->unit_type_name ?? '-' }}</td>
                </tr>
                <tr>
                    <td class="text-muted">Kayıt Tipi</td>
                    <td><span class="badge badge-info">{{ $discrepancy->record_type_name }}</span></td>
                </tr>
                <tr>
                    <td class="text-muted">Tutarsızlık Tipi</td>
                    <td>
                        <span class="badge badge-{{ $discrepancy->discrepancy_type === 'shortage' ? 'danger' : ($discrepancy->discrepancy_type === 'surplus' ? 'warning' : 'info') }}">
                            {{ $discrepancy->discrepancy_type_name }}
                        </span>
                    </td>
                </tr>
                <tr>
                    <td class="text-muted">Tespit Tarihi</td>
                    <td>{{ $discrepancy->detected_at?->format('d.m.Y H:i') ?? $discrepancy->created_at->format('d.m.Y H:i') }}</td>
                </tr>
                <tr>
                    <td class="text-muted">Durum</td>
                    <td>
                        <span class="badge badge-{{ $discrepancy->resolved ? 'success' : 'danger' }}">
                            {{ $discrepancy->resolved ? 'Çözümlendi' : 'Bekliyor' }}
                        </span>
                    </td>
                </tr>
                @if($discrepancy->resolved)
                    <tr>
                        <td class="text-muted">Çözümleyen</td>
                        <td>{{ $discrepancy->resolver?->name ?? '-' }}</td>
                    </tr>
                    <tr>
                        <td class="text-muted">Çözüm Tarihi</td>
                        <td>{{ $discrepancy->resolved_at?->format('d.m.Y H:i') ?? '-' }}</td>
                    </tr>
                @endif
            </table>
        </div>
    </div>

    <!-- Çözüm Bölümü -->
    @if($discrepancy->resolved)
        <div class="card mt-4" style="max-width: 800px;">
            <div class="card-header">
                <h4>Çözüm Notu</h4>
            </div>
            <div class="card-body">
                @if($discrepancy->resolution_notes)
                    <p class="mb-0">{{ $discrepancy->resolution_notes }}</p>
                @else
                    <p class="text-muted mb-0">Not girilmemiş.</p>
                @endif
            </div>
        </div>
    @else
        <div class="card mt-4" style="max-width: 800px;">
            <div class="card-header">
                <h4>✅ Tutarsızlığı Çözümle</h4>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.stock.resolve-discrepancy', $discrepancy) }}"
                      onsubmit="return confirm('Bu tutarsızlığı çözümlendi olarak işaretlemek istediğinize emin misiniz?')">
                    @csrf

                    <div class="form-group">
                        <label for="resolution_notes" class="form-label">Çözüm Notu *</label>
                        <textarea id="resolution_notes"
                                  name="resolution_notes"
                                  rows="4"
                                  required
                                  class="form-control @error('resolution_notes') is-invalid @enderror"
                                  placeholder="Tutarsızlığın nedeni ve yapılan işlem...">{{ old('resolution_notes') }}</textarea>
                        @error('resolution_notes')
                            <span class="invalid-feedback">{{ $message }}</span>
                        @enderror
                        <span class="form-text">Bu not kayda işlenir ve daha sonra değiştirilemez.</span>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">Çözümlendi Olarak İşaretle</button>
                        <a href="{{ route('admin.stock.index') }}" class="btn btn-secondary">İptal</a>
                    </div>
                </form>
            </div>
        </div>
    @endif
@endsection
