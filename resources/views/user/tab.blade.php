@extends('layouts.user')

@section('title', 'Adisyonum - Kral Kafe')
@section('page-title', 'Adisyonum')

@section('content')
    <div class="stats-grid mb-3">
        <div class="stat-card">
            <div class="stat-icon primary">💰</div>
            <div class="stat-content">
                <div class="stat-value">{{ number_format($monthTotal, 2, ',', '.') }} ₺</div>
                <div class="stat-label">Bu Ay Toplam</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon success">📦</div>
            <div class="stat-content">
                <div class="stat-value">{{ $monthItems }}</div>
                <div class="stat-label">Ürün Adedi</div>
            </div>
        </div>
    </div>

    @if($todayEntries->isNotEmpty())
        <div class="card mb-3">
            <div class="card-header"><h4>Bugün eklediklerim</h4></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table">
                        <thead><tr><th>Saat</th><th>Ürün</th><th>Nereden</th><th>Adet</th><th>Tutar</th><th></th></tr></thead>
                        <tbody>
                            @foreach($todayEntries as $kayit)
                                <tr>
                                    <td>{{ $kayit->consumed_at->timezone(config('kafe.timezone'))->format('H:i') }}</td>
                                    <td>{{ $kayit->product->name }}</td>
                                    <td class="text-muted">{{ $kayit->location->name }}</td>
                                    <td>{{ $kayit->quantity }}</td>
                                    <td>
                                        @if($kayit->isCoveredByPackage())
                                            <span class="badge badge-success">Paketinde</span>
                                        @else
                                            {{ $kayit->formatted_total }}
                                            @if($kayit->covered_quantity > 0)
                                                <span class="badge badge-info">{{ $kayit->covered_quantity }} paketten</span>
                                            @endif
                                        @endif
                                    </td>
                                    <td class="text-right">
                                        @if($kayit->canUndo())
                                            <form action="{{ route('user.tab.undo', $kayit) }}" method="POST" class="d-inline-block">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-secondary">Geri al</button>
                                            </form>
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

    <div class="card">
        <div class="card-header">
            <h4>Ne aldın?</h4>
        </div>
        <div class="card-body">
            @if($products->isEmpty())
                <div class="empty-state">
                    <div class="empty-state-icon">🍫</div>
                    <div class="empty-state-title">Tanımlı ürün yok</div>
                </div>
            @else
                <div class="product-grid">
                    @foreach($products as $product)
                        <form action="{{ route('user.tab.store') }}" method="POST" class="product-card" style="cursor: default;">
                            @csrf
                            <input type="hidden" name="product_id" value="{{ $product->id }}">
                            <div class="product-card-image">
                                @if($product->image_url)
                                    <img src="{{ $product->image_src }}" alt="{{ $product->name }}" style="width: 100%; height: 100%; object-fit: cover; border-radius: var(--radius);">
                                @else
                                    {{ $product->emoji ?: '🍫' }}
                                @endif
                            </div>
                            <div class="product-card-name">{{ $product->name }}</div>
                            <div class="product-card-price">{{ $product->formatted_price }}</div>

                            {{--
                                Raf secimi YALNIZCA urun birden fazla yerdeyse
                                cikar (Dalga 8). Tek raftaki urun soru sormadan
                                oradan duser; rastgele birini dusurmek iki sahte
                                fark uretirdi - biri eksik, oburu fazla.
                            --}}
                            @if($shelves[$product->id]->count() > 1)
                                <select name="location_id" class="form-control mt-2" required>
                                    <option value="">Nereden aldın?</option>
                                    @foreach($shelves[$product->id] as $raf)
                                        <option value="{{ $raf->location_id }}">{{ $raf->location->name }}</option>
                                    @endforeach
                                </select>
                            @endif

                            <div class="d-flex gap-1 justify-content-center align-items-center mt-2">
                                <select name="quantity" class="form-control" style="width: 64px; padding: 4px;">
                                    @for($i = 1; $i <= 5; $i++)
                                        <option value="{{ $i }}">{{ $i }}</option>
                                    @endfor
                                </select>
                                <button type="submit" class="btn btn-primary btn-sm">Ekle</button>
                            </div>
                        </form>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    <div class="alert alert-info mt-3">
        💡 Buradan eklediklerin doğrudan aylık hesabına yazılır ve rafın stoğundan düşülür.
        Paketine dahil olanlar ücretsiz işlenir. Yanlış eklediysen 60 saniye içinde geri alabilirsin.
    </div>
@endsection
