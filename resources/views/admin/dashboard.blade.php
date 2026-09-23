@extends('layouts.app')

@section('title', 'Yönetim Paneli - Kral Kafe')
@section('page-title', 'Panel')

@section('content')
    {{--
        UX turu (23 Eyl): ust satir "simdi" - yoneticinin gunluk sorulari.
        Her kart ilgili sayfaya goturur; dikkat isteyen sayi renklenir.
    --}}
    @if($unresolvedDiscrepancies > 0)
        <div class="alert alert-warning mb-3">
            ⚠️ <strong>{{ $unresolvedDiscrepancies }}</strong> adet çözülmemiş stok tutarsızlığı var.
            <a href="{{ route('admin.stock.counts') }}" class="btn btn-sm btn-warning ml-2">İncele</a>
        </div>
    @endif

    {{-- A20: sayilar (iceride, onay, kritik stok) sekme acikken dakikada bir tazelenir. --}}
    <div class="mini-stats mb-3" data-kendini-yenile="60">
        <a href="{{ route('admin.live') }}" class="mini-stat mini-stat-link">
            <div class="mini-stat-value">{{ $occupancy['inside'] }}</div>
            <div class="mini-stat-label">İçeride · {{ $occupancy['free'] }} boş yer</div>
        </a>
        <a href="{{ route('admin.live') }}#onay" class="mini-stat mini-stat-link {{ $pendingApprovals > 0 ? 'is-warning' : '' }}">
            <div class="mini-stat-value">{{ $pendingApprovals }}</div>
            <div class="mini-stat-label">Onay bekliyor</div>
        </a>
        <a href="{{ route('admin.stock.index', ['durum' => 'critical']) }}" class="mini-stat mini-stat-link {{ $criticalStock > 0 ? 'is-danger' : '' }}">
            <div class="mini-stat-value">{{ $criticalStock }}</div>
            <div class="mini-stat-label">Ürün kritik</div>
        </a>
        <div class="mini-stat">
            <div class="mini-stat-value">{{ number_format($stats['today_total'], 2, ',', '.') }} ₺</div>
            <div class="mini-stat-label">Bugün</div>
        </div>
    </div>

    <div class="mini-stats mini-stats-3 mb-3">
        <div class="mini-stat">
            <div class="mini-stat-value">{{ number_format($stats['this_month_total'], 2, ',', '.') }} ₺</div>
            <div class="mini-stat-label">Bu ay</div>
        </div>
        <div class="mini-stat">
            <div class="mini-stat-value">{{ number_format($stats['this_month_items']) }}</div>
            <div class="mini-stat-label">Bu ay ürün</div>
        </div>
        <div class="mini-stat">
            <div class="mini-stat-value">{{ $stats['active_users'] }}</div>
            <div class="mini-stat-label">Aktif üye</div>
        </div>
    </div>

    <div class="d-grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(min(400px, 100%), 1fr));">
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
                                        <td>{{ $consumption->consumed_at->timezone(config('kafe.timezone'))->format('H:i') }}</td>
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
                <a href="{{ route('admin.stock.counts') }}" class="btn btn-primary">
                    📷 Stok Sayımı Yap
                </a>
                <a href="{{ route('admin.users.create') }}" class="btn btn-success">
                    ➕ Yeni Kullanıcı
                </a>
                <a href="{{ route('admin.products.create') }}" class="btn btn-warning">
                    📦 Yeni Ürün
                </a>
                <a href="{{ route('admin.stock.index') }}" class="btn btn-secondary">
                    📦 Stok
                </a>
                <a href="{{ route('admin.reports.monthly') }}" class="btn btn-secondary">
                    📈 Aylık Rapor
                </a>
            </div>
        </div>
    </div>

@endsection

@push('scripts')
<script>
    (function () {
        const bekleme = Number(document.querySelector('[data-kendini-yenile]').dataset.kendiniYenile) * 1000;
        const yuklendi = Date.now();

        // Arka plandaki sekme sunucuyu yormasin; gorunur olunca hemen tazelenir.
        function tazele() {
            if (document.visibilityState === 'visible' && Date.now() - yuklendi >= bekleme) {
                location.reload();
            }
        }

        document.addEventListener('visibilitychange', tazele);
        setInterval(tazele, 15000);
    })();
</script>
@endpush
