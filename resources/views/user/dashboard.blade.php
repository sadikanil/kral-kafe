@extends('layouts.user')

@section('title', 'Panel - Kral Kafe')
@section('page-title', 'Hoş Geldin, {{ auth()->user()->name }}!')

@section('content')
    <!-- Bu Ay Özeti -->
    <div class="stats-grid">
        <div class="stat-card animate-slide-up">
            <div class="stat-icon primary">💰</div>
            <div class="stat-content">
                <div class="stat-value">{{ number_format($currentMonthTotal, 2, ',', '.') }} ₺</div>
                <div class="stat-label">Bu Ay Toplam</div>
            </div>
        </div>

        <div class="stat-card animate-slide-up" style="animation-delay: 50ms">
            <div class="stat-icon success">📦</div>
            <div class="stat-content">
                <div class="stat-value">{{ $currentMonthItems }}</div>
                <div class="stat-label">Ürün Adedi</div>
            </div>
        </div>
    </div>

    <!-- Son Tüketimler -->
    <div class="card animate-slide-up" style="animation-delay: 100ms">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h4>Son Tüketimlerim</h4>
            <a href="{{ route('user.history') }}" class="btn btn-sm btn-secondary">Tümünü Gör</a>
        </div>
        <div class="card-body p-0">
            @if($recentConsumptions->isEmpty())
                <div class="p-4 text-center text-muted">
                    Henüz tüketim kaydınız bulunmuyor.
                </div>
            @else
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Tarih</th>
                                <th>Ürün</th>
                                <th>Lokasyon</th>
                                <th>Adet</th>
                                <th>Tutar</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($recentConsumptions as $consumption)
                                <tr>
                                    <td>{{ $consumption->consumed_at->format('d.m.Y H:i') }}</td>
                                    <td>{{ $consumption->product->name }}</td>
                                    <td>
                                        <span class="badge badge-info">{{ $consumption->location->name }}</span>
                                    </td>
                                    <td>{{ $consumption->quantity }}</td>
                                    <td>{{ $consumption->formatted_total }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    <!-- Bu Ay Ürün Dağılımı -->
    @if(!empty($monthlySummary['by_product']) && count($monthlySummary['by_product']) > 0)
        <div class="card mt-4 animate-slide-up" style="animation-delay: 150ms">
            <div class="card-header">
                <h4>Bu Ay Tüketim Dağılımı</h4>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Ürün</th>
                                <th>Adet</th>
                                <th>Toplam</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($monthlySummary['by_product'] as $product)
                                <tr>
                                    <td>{{ $product['product_name'] }}</td>
                                    <td>{{ $product['quantity'] }}</td>
                                    <td>{{ number_format($product['total'], 2, ',', '.') }} ₺</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif

    <!-- Bilgilendirme -->
    <div class="alert alert-info mt-4 animate-slide-up" style="animation-delay: 200ms">
        💡 <strong>Nasıl tüketim eklerim?</strong><br>
        Kafe içindeki raflarda, dolaplarda veya buzdolaplarında bulunan QR kodları telefonunuzla tarayarak kolayca tüketim
        kaydı ekleyebilirsiniz.
    </div>
@endsection