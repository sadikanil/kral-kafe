@extends('layouts.app')

@section('title', 'Ürün ekle - Kral Kafe')
@section('page-title', 'Ürün ekle')

{{--
    Urun secme (DEV-506, 26 Ekim 2026): Adisyonum'daki "Urun ekle"
    dugmesinin actigi ekran. Masadan gelindiyse masa kodu gizli alanla
    gider ve ekleme ayni masanin adisyonuna doner.
--}}
@section('page-actions')
    <a href="{{ $table ? route('table.scan', $table->qr_code) : route('user.tab') }}" class="btn btn-secondary btn-sm">← Adisyonum</a>
@endsection

@section('content')
    <div class="card">
        <div class="card-header">
            <h4>Ne aldın?</h4>
            @if($table)<span class="text-muted">{{ $table->name }}</span>@endif
        </div>
        <div class="card-body">
            @if($products->isEmpty())
                <div class="empty-state">
                    <div class="empty-state-icon">☕</div>
                    <div class="empty-state-title">Menüde ürün yok</div>
                </div>
            @else
                <div class="product-grid">
                    @foreach($products as $product)
                        <form action="{{ route('user.tab.store') }}" method="POST" class="product-card" style="cursor: default;">
                            @csrf
                            <input type="hidden" name="product_id" value="{{ $product->id }}">
                            @if($table)<input type="hidden" name="masa" value="{{ $table->qr_code }}">@endif
                            <div class="product-card-image">
                                @if($product->image_url)
                                    <img src="{{ $product->image_src }}" alt="{{ $product->name }}" style="width: 100%; height: 100%; object-fit: cover; border-radius: var(--r);">
                                @else
                                    {{ $product->emoji ?: '☕' }}
                                @endif
                            </div>
                            <div class="product-card-name">{{ $product->name }}</div>
                            <div class="product-card-price">{{ $product->formatted_price }}</div>

                            <div class="d-flex gap-1 justify-content-center align-items-center mt-2">
                                <select name="quantity" class="form-control" style="width: 64px; padding: 4px;"
                                    aria-label="{{ $product->name }} adet">
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
        💡 Eklediğin ürün aylık adisyonuna yazılır. Paketine dahil olanlar ücretsiz işlenir.
        Yanlış eklediysen 60 saniye içinde Adisyonum'dan geri alabilirsin.
    </div>
@endsection
