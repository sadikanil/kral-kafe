@extends('layouts.admin')

@section('title', 'Yönetim Paneli - Kral Kafe')
@section('page-title', 'Panel')

@section('content')
    <!-- İstatistik Kartları -->
    <div class="stats-grid">
        <div class="stat-card animate-slide-up">
            <div class="stat-icon primary">💰</div>
            <div class="stat-content">
                <div class="stat-value">{{ number_format($stats['this_month_total'], 2, ',', '.') }} ₺</div>
                <div class="stat-label">Bu Ay Toplam</div>
            </div>
        </div>

        <div class="stat-card animate-slide-up" style="animation-delay: 50ms">
            <div class="stat-icon success">📦</div>
            <div class="stat-content">
                <div class="stat-value">{{ number_format($stats['this_month_items']) }}</div>
                <div class="stat-label">Bu Ay Ürün</div>
            </div>
        </div>

        <div class="stat-card animate-slide-up" style="animation-delay: 100ms">
            <div class="stat-icon warning">👥</div>
            <div class="stat-content">
                <div class="stat-value">{{ $stats['active_users'] }}</div>
                <div class="stat-label">Aktif Üye</div>
            </div>
        </div>

        <div class="stat-card animate-slide-up" style="animation-delay: 150ms">
            <div class="stat-icon danger">📊</div>
            <div class="stat-content">
                <div class="stat-value">{{ number_format($stats['today_total'], 2, ',', '.') }} ₺</div>
                <div class="stat-label">Bugün</div>
            </div>
        </div>
    </div>

    <div class="d-grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));">
        <!-- Bugünkü Tüketimler -->
        <div class="card animate-slide-up" style="animation-delay: 200ms">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h4>Bugünkü Tüketimler</h4>
                <span class="badge badge-primary">{{ $todayConsumptions->count() }} kayıt</span>
            </div>
            <div class="card-body p-0">
                @if($todayConsumptions->isEmpty())
                    <div class="p-4 text-center text-muted">
                        Bugün henüz tüketim kaydı yok.
                    </div>
                @else
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Kullanıcı</th>
                                    <th>Ürün</th>
                                    <th>Adet</th>
                                    <th>Saat</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($todayConsumptions as $consumption)
                                    <tr>
                                        <td>{{ $consumption->user->name }}</td>
                                        <td>{{ $consumption->product->name }}</td>
                                        <td>{{ $consumption->quantity }}</td>
                                        <td>{{ $consumption->consumed_at->format('H:i') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>

        <!-- En Çok Tüketilen Ürünler -->
        <div class="card animate-slide-up" style="animation-delay: 250ms">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h4>Bu Ay En Çok Tüketilen</h4>
                <span class="badge badge-success">Top 5</span>
            </div>
            <div class="card-body p-0">
                @if($topProducts->isEmpty())
                    <div class="p-4 text-center text-muted">
                        Bu ay henüz tüketim kaydı yok.
                    </div>
                @else
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Ürün</th>
                                    <th>Adet</th>
                                    <th>Gelir</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($topProducts as $index => $item)
                                    <tr>
                                        <td>
                                            <span class="badge badge-{{ $index < 3 ? 'warning' : 'info' }}">
                                                {{ $index + 1 }}
                                            </span>
                                        </td>
                                        <td>{{ $item->product->name ?? 'Silinmiş Ürün' }}</td>
                                        <td>{{ number_format($item->total_quantity) }}</td>
                                        <td>{{ number_format($item->total_revenue, 2, ',', '.') }} ₺</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </div>

    <!-- Hızlı Erişim -->
    <div class="card mt-4 animate-slide-up" style="animation-delay: 300ms">
        <div class="card-header">
            <h4>Hızlı Erişim</h4>
        </div>
        <div class="card-body">
            <div class="d-flex gap-2" style="flex-wrap: wrap;">
                <a href="{{ route('admin.stock.index') }}" class="btn btn-primary">
                    📷 Stok Sayımı Yap
                </a>
                <a href="{{ route('admin.users.create') }}" class="btn btn-success">
                    ➕ Yeni Kullanıcı
                </a>
                <a href="{{ route('admin.products.create') }}" class="btn btn-warning">
                    📦 Yeni Ürün
                </a>
                <a href="{{ route('admin.locations.create') }}" class="btn btn-secondary">
                    📍 Yeni Lokasyon
                </a>
                <a href="{{ route('admin.reports.monthly') }}" class="btn btn-secondary">
                    📈 Aylık Rapor
                </a>
            </div>
        </div>
    </div>

    @if($unresolvedDiscrepancies > 0)
        <div class="alert alert-warning mt-4 animate-slide-up">
            ⚠️ <strong>{{ $unresolvedDiscrepancies }}</strong> adet çözülmemiş stok tutarsızlığı var.
            <a href="{{ route('admin.stock.index') }}" class="btn btn-sm btn-warning ml-2">İncele</a>
        </div>
    @endif
@endsection