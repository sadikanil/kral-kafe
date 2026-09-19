@extends('layouts.admin')

@php
    $firstPhoto = $photos->first();
    $recordType = $firstPhoto->record_type;

    $locationProducts = $location->products;
    $locationProductIds = $locationProducts->pluck('id')->map(fn ($id) => (string) $id)->all();

    // AI sonucu urun id'sine gore eslestirilir; eslesmeyenler ayri listelenir.
    $aiByProduct = [];
    $unmatchedDetections = [];

    foreach ($analysisResult['products_detected'] ?? [] as $detection) {
        $rawId = $detection['product_id'] ?? null;
        $key = ($rawId === null || $rawId === '') ? null : (string) $rawId;

        if ($key !== null && in_array($key, $locationProductIds, true)) {
            $aiByProduct[$key] = $detection;
        } else {
            $unmatchedDetections[] = $detection;
        }
    }

    $anomalies = $analysisResult['anomalies'] ?? [];
    $anomalyLabels = [
        'missing_product' => ['Eksik Ürün', 'danger'],
        'low_stock' => ['Düşük Stok', 'warning'],
        'empty_shelf' => ['Boş Raf', 'warning'],
        'unexpected_item' => ['Beklenmeyen Ürün', 'info'],
        'damaged' => ['Hasarlı', 'danger'],
    ];
@endphp

@section('title', 'Stok Analizi - ' . $location->name)
@section('page-title', 'Stok Analizi: ' . $location->name)

@section('topbar-actions')
    <a href="{{ route('admin.stock.capture', $location) }}" class="btn btn-secondary btn-sm">
        📷 Yeniden Fotoğraf Yükle
    </a>
    <a href="{{ route('admin.stock.index') }}" class="btn btn-secondary btn-sm">
        📋 Stok Sayım
    </a>
@endsection

@section('content')
    <!-- Özet -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon primary">🕐</div>
            <div class="stat-content">
                <div class="stat-value">{{ $firstPhoto->record_type_name }}</div>
                <div class="stat-label">Sayım Tipi</div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon success">📷</div>
            <div class="stat-content">
                <div class="stat-value">{{ $analysisResult['photo_count'] ?? $photos->count() }}</div>
                <div class="stat-label">Fotoğraf</div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon warning">🤖</div>
            <div class="stat-content">
                <div class="stat-value">%{{ round(($analysisResult['overall_confidence'] ?? 0) * 100) }}</div>
                <div class="stat-label">AI Güveni</div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon primary">📦</div>
            <div class="stat-content">
                <div class="stat-value">{{ count($analysisResult['products_detected'] ?? []) }}</div>
                <div class="stat-label">Tespit Edilen Ürün</div>
            </div>
        </div>
    </div>

    <!-- Analiz durumu -->
    @if($analysisResult['success'] ?? false)
        <div class="alert alert-info mb-4">
            🤖 {{ $analysisResult['summary'] ?? 'Analiz tamamlandı.' }}
        </div>
    @else
        <div class="alert alert-warning mb-4">
            <strong>Yapay zeka analizi tamamlanamadı.</strong><br>
            {{ $analysisResult['summary'] ?? 'Fotoğraflar analiz edilemedi.' }}<br>
            Adetleri elle girerek sayımı yine de kaydedebilirsiniz.
        </div>
    @endif

    <!-- Anomaliler -->
    @if(!empty($anomalies))
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h4>⚠️ Dikkat Edilmesi Gerekenler</h4>
                <span class="badge badge-warning">{{ count($anomalies) }}</span>
            </div>
            <div class="card-body">
                @foreach($anomalies as $anomaly)
                    @php
                        [$anomalyLabel, $anomalyVariant] = $anomalyLabels[$anomaly['type'] ?? ''] ?? [$anomaly['type'] ?? 'Bilinmiyor', 'info'];
                    @endphp
                    <div class="d-flex align-items-center gap-2 {{ $loop->last ? '' : 'mb-2' }}">
                        <span class="badge badge-{{ $anomalyVariant }}">{{ $anomalyLabel }}</span>
                        <span>{{ $anomaly['description'] ?? '-' }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <!-- Sayım formu -->
    @if($locationProducts->isEmpty())
        <div class="card mb-4">
            <div class="card-header">
                <h4>Sayım Sonuçları</h4>
            </div>
            <div class="card-body text-center p-4">
                <p class="text-muted mb-3">Bu lokasyona bağlı ürün bulunmuyor.</p>
                <a href="{{ route('admin.locations.edit', $location) }}" class="btn btn-primary">
                    ➕ Lokasyona Ürün Ekle
                </a>
            </div>
        </div>
    @else
        <form method="POST" action="{{ route('admin.stock.confirm', $location) }}">
            @csrf
            <input type="hidden" name="batch_id" value="{{ $batchId }}">
            <input type="hidden" name="record_type" value="{{ $recordType }}">

            <div class="card mb-4">
                <div class="card-header">
                    <h4>Sayım Sonuçları</h4>
                    <span class="form-text">
                        Yapay zekanın tahminlerini kontrol edin. Yanlış olanları düzeltip sayımı onaylayın.
                    </span>
                </div>

                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Ürün</th>
                                    <th>Beklenen</th>
                                    <th>AI Tahmini</th>
                                    <th>Güven</th>
                                    <th>Sayılan Adet *</th>
                                    <th>Fark (Sayılan − Beklenen)</th>
                                    <th>Not</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($locationProducts as $product)
                                    @php
                                        $detection = $aiByProduct[(string) $product->id] ?? null;
                                        $aiQuantity = isset($detection['estimated_quantity'])
                                            ? (int) $detection['estimated_quantity']
                                            : null;
                                        // max:1 kuralini kayan nokta artigi patlatmasin diye yuvarlanip sinirlanir.
                                        $aiConfidence = isset($detection['confidence'])
                                            ? max(0, min(1, round((float) $detection['confidence'], 2)))
                                            : null;
                                        $expectedQuantity = $product->pivot->expected_quantity;
                                        $defaultQuantity = $aiQuantity ?? $expectedQuantity ?? 0;
                                        $initialDifference = $defaultQuantity - ($expectedQuantity ?? 0);
                                    @endphp
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <div
                                                    style="width: 40px; height: 40px; background: var(--gray-100); border-radius: var(--radius); display: flex; align-items: center; justify-content: center; font-size: 1.5rem;">
                                                    {{ $product->emoji ? $product->emoji : '📦' }}
                                                </div>
                                                <span>{{ $product->name }}</span>
                                            </div>
                                        </td>
                                        <td>
                                            @if($expectedQuantity === null)
                                                <span class="text-muted">-</span>
                                            @else
                                                {{ $expectedQuantity }}
                                            @endif
                                        </td>
                                        <td>
                                            @if($aiQuantity === null)
                                                <span class="text-muted">-</span>
                                            @else
                                                {{ $aiQuantity }}
                                            @endif
                                        </td>
                                        <td>
                                            @if($aiConfidence === null)
                                                <span class="text-muted">-</span>
                                            @else
                                                <span class="badge badge-{{ $aiConfidence >= 0.8 ? 'success' : ($aiConfidence >= 0.5 ? 'warning' : 'danger') }}">
                                                    %{{ round($aiConfidence * 100) }}
                                                </span>
                                            @endif
                                        </td>
                                        <td>
                                            <input type="hidden" name="products[{{ $product->id }}][product_id]"
                                                value="{{ $product->id }}">
                                            <input type="hidden" name="products[{{ $product->id }}][ai_suggested_quantity]"
                                                value="{{ old('products.' . $product->id . '.ai_suggested_quantity', $aiQuantity ?? '') }}">
                                            <input type="hidden" name="products[{{ $product->id }}][ai_confidence]"
                                                value="{{ old('products.' . $product->id . '.ai_confidence', $aiConfidence ?? '') }}">
                                            <input type="number"
                                                class="form-control js-counted @error('products.' . $product->id . '.verified_quantity') is-invalid @enderror"
                                                name="products[{{ $product->id }}][verified_quantity]"
                                                value="{{ old('products.' . $product->id . '.verified_quantity', $defaultQuantity) }}"
                                                min="0" step="1" required
                                                style="max-width: 110px;"
                                                data-expected="{{ $expectedQuantity ?? '' }}"
                                                data-target="fark-{{ $product->id }}">
                                            @error('products.' . $product->id . '.verified_quantity')
                                                <span class="invalid-feedback">{{ $message }}</span>
                                            @enderror
                                        </td>
                                        <td>
                                            <span id="fark-{{ $product->id }}"
                                                class="{{ $expectedQuantity === null ? 'text-muted' : ($initialDifference < 0 ? 'text-danger' : ($initialDifference > 0 ? 'text-success' : 'text-muted')) }}">
                                                @if($expectedQuantity === null)
                                                    -
                                                @else
                                                    {{ $initialDifference > 0 ? '+' : '' }}{{ $initialDifference }}
                                                @endif
                                            </span>
                                        </td>
                                        <td>
                                            <input type="text" class="form-control"
                                                name="products[{{ $product->id }}][notes]"
                                                value="{{ old('products.' . $product->id . '.notes') }}"
                                                placeholder="Opsiyonel">
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="text-center p-4 text-muted">
                                            Bu lokasyona bağlı ürün bulunmuyor.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card-footer d-flex justify-content-between align-items-center gap-2">
                    <a href="{{ route('admin.stock.capture', $location) }}" class="btn btn-secondary">İptal</a>
                    <button type="submit" class="btn btn-primary">✅ Sayımı Onayla ve Kaydet</button>
                </div>
            </div>
        </form>
    @endif

    <!-- Eşleştirilemeyen tespitler -->
    @if(!empty($unmatchedDetections))
        <div class="card mb-4">
            <div class="card-header">
                <h4>🔍 Eşleştirilemeyen Tespitler</h4>
                <span class="form-text">Bu ürünler lokasyon listesinde yok, kayda dahil edilmedi.</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Tespit Edilen</th>
                                <th>Tahmini Adet</th>
                                <th>Güven</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($unmatchedDetections as $detection)
                                @php
                                    $unmatchedConfidence = isset($detection['confidence'])
                                        ? max(0, min(1, round((float) $detection['confidence'], 2)))
                                        : null;
                                @endphp
                                <tr>
                                    <td>{{ $detection['name'] ?? '-' }}</td>
                                    <td>{{ $detection['estimated_quantity'] ?? '-' }}</td>
                                    <td>
                                        @if($unmatchedConfidence === null)
                                            <span class="text-muted">-</span>
                                        @else
                                            %{{ round($unmatchedConfidence * 100) }}
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif

    <!-- Fotoğraflar -->
    <div class="card">
        <div class="card-header">
            <h4>📷 Yüklenen Fotoğraflar</h4>
        </div>
        <div class="card-body">
            <div class="d-grid gap-2" style="grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));">
                @forelse($photos as $photo)
                    <div>
                        <img src="{{ $photo->photo_url }}" alt="Stok fotoğrafı"
                            style="width: 100%; height: 140px; object-fit: cover; border-radius: var(--radius); background: var(--gray-100);">
                        <div class="mt-1 text-muted">{{ $photo->uploaded_at->format('d.m.Y H:i') }}</div>
                        <span class="badge badge-{{ $photo->isProcessed() ? 'success' : 'warning' }}">
                            {{ $photo->isProcessed() ? 'Analiz edildi' : 'İşlenmedi' }}
                        </span>
                    </div>
                @empty
                    <p class="text-muted">Bu sayıma ait fotoğraf bulunmuyor.</p>
                @endforelse
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.querySelectorAll('.js-counted').forEach(function (input) {
            var target = document.getElementById(input.dataset.target);

            if (!target) {
                return;
            }

            function updateDifference() {
                var expected = input.dataset.expected;

                if (expected === '') {
                    return;
                }

                var counted = parseInt(input.value, 10);

                if (isNaN(counted)) {
                    target.textContent = '-';
                    target.className = 'text-muted';
                    return;
                }

                var difference = counted - parseInt(expected, 10);

                target.textContent = (difference > 0 ? '+' : '') + difference;
                target.className = difference < 0 ? 'text-danger' : (difference > 0 ? 'text-success' : 'text-muted');
            }

            input.addEventListener('input', updateDifference);
        });
    </script>
@endpush
