@extends('layouts.app')

@php
    $monthNames = ['Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
    $periodLabel = $summary['month_name'] . ' ' . $year;
@endphp

@section('title', $user->name . ' - Tüketim Raporu - Kral Kafe')
@section('page-title', $user->name . ' - ' . $periodLabel)

@section('topbar-actions')
    <a href="{{ route('admin.reports.monthly', ['year' => $year, 'month' => $month]) }}"
        class="btn btn-secondary btn-sm">← Aylık Rapor</a>
    <a href="{{ route('admin.users.edit', $user) }}" class="btn btn-secondary btn-sm">✏️ Kullanıcıyı Düzenle</a>
@endsection

@section('content')
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon primary">💰</div>
            <div class="stat-content">
                <div class="stat-value">{{ number_format($summary['total_amount'], 2, ',', '.') }} ₺</div>
                <div class="stat-label">Toplam Tutar</div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon success">📦</div>
            <div class="stat-content">
                <div class="stat-value">{{ number_format($summary['total_items']) }}</div>
                <div class="stat-label">Toplam Ürün</div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon warning">📅</div>
            <div class="stat-content">
                <div class="stat-value">{{ $summary['by_day']->count() }}</div>
                <div class="stat-label">Tüketim Yapılan Gün</div>
            </div>
        </div>
    </div>

    <!-- Kullanıcı Bilgisi -->
    <div class="card mb-3">
        <div class="card-header">
            <h4>👤 Kullanıcı Bilgisi</h4>
        </div>
        <div class="card-body">
            <table class="table">
                <tr>
                    <td class="text-muted">E-posta</td>
                    <td>{{ $user->email }}</td>
                </tr>
                <tr>
                    <td class="text-muted">Telefon</td>
                    <td>{{ $user->phone ?? '—' }}</td>
                </tr>
                <tr>
                    <td class="text-muted">Rol</td>
                    <td>
                        <span class="badge badge-{{ $user->role()?->badgeClass() ?? 'info' }}">
                            {{ $user->role()?->label() ?? $user->role }}
                        </span>
                    </td>
                </tr>
                <tr>
                    <td class="text-muted">Abonelik Durumu</td>
                    <td>
                        @switch($user->subscription_status)
                            @case('active')
                                <span class="badge badge-success">Aktif</span>
                                @break
                            @case('suspended')
                                <span class="badge badge-danger">Askıda</span>
                                @break
                            @default
                                <span class="badge badge-warning">Pasif</span>
                        @endswitch
                    </td>
                </tr>
                <tr>
                    <td class="text-muted">Kayıt Tarihi</td>
                    <td>{{ $user->created_at->timezone(config('kafe.timezone'))->format('d.m.Y') }}</td>
                </tr>
            </table>
        </div>
    </div>

    <!-- Dönem Filtresi -->
    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" action="{{ route('admin.reports.user', $user) }}" class="d-flex gap-2">
                <div class="form-group mb-0" style="flex: 1; max-width: 160px;">
                    <label for="year" class="form-label">Yıl</label>
                    <select id="year" name="year" class="form-control">
                        @for($y = date('Y'); $y >= date('Y') - 2; $y--)
                            <option value="{{ $y }}" {{ $y == $year ? 'selected' : '' }}>{{ $y }}</option>
                        @endfor
                    </select>
                </div>

                <div class="form-group mb-0" style="flex: 1; max-width: 200px;">
                    <label for="month" class="form-label">Ay</label>
                    <select id="month" name="month" class="form-control">
                        @for($m = 1; $m <= 12; $m++)
                            <option value="{{ $m }}" {{ $m == $month ? 'selected' : '' }}>{{ $monthNames[$m - 1] }}</option>
                        @endfor
                    </select>
                </div>

                <button type="submit" class="btn btn-primary">Göster</button>
            </form>
        </div>
    </div>

    <!-- Ürün Bazında -->
    <div class="card">
        <div class="card-header">
            <h4>📦 Ürün Bazında</h4>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Ürün</th>
                            <th class="text-center">Adet</th>
                            <th>Tutar</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($summary['by_product']->sortByDesc('total') as $row)
                            <tr>
                                <td>{{ $row['product_name'] }}</td>
                                <td class="text-center">{{ number_format($row['quantity']) }}</td>
                                <td><strong>{{ number_format($row['total'], 2, ',', '.') }} ₺</strong></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="text-center p-4 text-muted">
                                    Bu dönemde ürün tüketimi bulunamadı.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Gün Bazında -->
    <div class="card mt-4">
        <div class="card-header">
            <h4>📅 Gün Bazında</h4>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Tarih</th>
                            <th class="text-center">Adet</th>
                            <th>Tutar</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($summary['by_day'] as $row)
                            <tr>
                                <td>{{ $row['formatted_date'] }}</td>
                                <td class="text-center">{{ number_format($row['count']) }}</td>
                                <td><strong>{{ number_format($row['total'], 2, ',', '.') }} ₺</strong></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="text-center p-4 text-muted">
                                    Bu dönemde tüketim kaydı bulunmuyor.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Tüm Tüketimler -->
    <div class="card mt-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h4>🧾 Tüm Tüketimler</h4>
            <span class="badge badge-info">{{ $summary['consumptions']->count() }} kayıt</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Tarih</th>
                            <th>Ürün</th>
                            <th>Lokasyon</th>
                            <th class="text-center">Adet</th>
                            <th>Tutar</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($summary['consumptions'] as $consumption)
                            <tr>
                                <td>{{ $consumption->formatted_date }}</td>
                                <td>{{ $consumption->product?->emoji }} {{ $consumption->product?->name ?? '—' }}</td>
                                <td>
                                    @if($consumption->location)
                                        <span class="badge badge-info">{{ $consumption->location->name }}</span>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="text-center">{{ $consumption->quantity }}</td>
                                <td><strong>{{ $consumption->formatted_total }}</strong></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center p-4 text-muted">
                                    {{ $periodLabel }} için tüketim kaydı bulunamadı.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
