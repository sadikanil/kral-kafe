@extends('layouts.app')

@section('title', 'Raporlar - Kral Kafe')
@section('page-title', 'Raporlar')

@section('content')
    <!-- Ay Özeti -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon primary">💰</div>
            <div class="stat-content">
                <div class="stat-value">{{ number_format($stats['monthly_revenue'], 2, ',', '.') }} ₺</div>
                <div class="stat-label">Bu Ay Gelir</div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon success">📦</div>
            <div class="stat-content">
                <div class="stat-value">{{ number_format($stats['monthly_items']) }}</div>
                <div class="stat-label">Satılan Ürün</div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon warning">👥</div>
            <div class="stat-content">
                <div class="stat-value">{{ $stats['active_consumers'] }}</div>
                <div class="stat-label">Aktif Tüketici</div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon danger">📊</div>
            <div class="stat-content">
                <div class="stat-value">{{ number_format($stats['avg_per_user'], 2, ',', '.') }} ₺</div>
                <div class="stat-label">Kişi Başı Ortalama</div>
            </div>
        </div>
    </div>

    <div class="d-grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(min(400px, 100%), 1fr));">
        <!-- Fatura Oluştur -->
        <div class="card">
            <div class="card-header">
                <h4>📄 Aylık Fatura Oluştur</h4>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.reports.generate-bills') }}">
                    @csrf

                    <div class="d-flex gap-2 mb-3">
                        <div class="form-group mb-0" style="flex: 1;">
                            <label for="year" class="form-label">Yıl</label>
                            <select id="year" name="year" class="form-control">
                                @for($y = $year; $y >= $year - 2; $y--)
                                    <option value="{{ $y }}" {{ $y == $year ? 'selected' : '' }}>{{ $y }}</option>
                                @endfor
                            </select>
                        </div>

                        <div class="form-group mb-0" style="flex: 1;">
                            <label for="month" class="form-label">Ay</label>
                            <select id="month" name="month" class="form-control">
                                @php
                                    $months = ['Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
                                @endphp
                                @for($m = 1; $m <= 12; $m++)
                                    <option value="{{ $m }}" {{ $m == $month ? 'selected' : '' }}>{{ $months[$m - 1] }}</option>
                                @endfor
                            </select>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary btn-block">
                        Faturaları Oluştur
                    </button>
                </form>
            </div>
        </div>

        <!-- Rapor İndir -->
        <div class="card">
            <div class="card-header">
                <h4>📥 Rapor İndir</h4>
            </div>
            <div class="card-body">
                <div class="d-flex gap-2 mb-3" style="flex-wrap: wrap;">
                    <a href="{{ route('admin.reports.export-summary', ['year' => $year, 'month' => $month]) }}"
                        class="btn btn-secondary" style="flex: 1;">
                        📊 Özet Rapor (CSV)
                    </a>
                    <a href="{{ route('admin.reports.export-detailed', ['year' => $year, 'month' => $month]) }}"
                        class="btn btn-secondary" style="flex: 1;">
                        📋 Detaylı Rapor (CSV)
                    </a>
                </div>

                <a href="{{ route('admin.reports.monthly', ['year' => $year, 'month' => $month]) }}"
                    class="btn btn-primary btn-block">
                    📈 Aylık Raporu Görüntüle
                </a>
            </div>
        </div>
    </div>

    <!-- Son Faturalar -->
    <div class="card mt-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h4>Son Oluşturulan Faturalar</h4>
        </div>
        <div class="card-body p-0">
            @if($recentBills->isEmpty())
                <div class="p-4 text-center text-muted">
                    Henüz fatura oluşturulmamış.
                </div>
            @else
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Kullanıcı</th>
                                <th>Dönem</th>
                                <th>Toplam</th>
                                <th>Durum</th>
                                <th>Oluşturma</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($recentBills as $bill)
                                <tr>
                                    <td>{{ $bill->user->name }}</td>
                                    <td>{{ $bill->period_name }}</td>
                                    <td>{{ $bill->formatted_total }}</td>
                                    <td>
                                        {{-- Aylik rapordaki rozetle ayni: faturada 'paid' durumu yok --}}
                                        <span class="badge badge-{{ $bill->status === 'pending' ? 'warning' : ($bill->status === 'sent' ? 'success' : 'info') }}">
                                            {{ $bill->status_name }}
                                        </span>
                                    </td>
                                    <td>{{ $bill->created_at->timezone(config('kafe.timezone'))->format('d.m.Y') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    <!-- En Çok Tüketen Kullanıcılar -->
    <div class="card mt-4">
        <div class="card-header">
            <h4>Bu Ay En Çok Tüketen Kullanıcılar</h4>
        </div>
        <div class="card-body p-0">
            @if($topConsumers->isEmpty())
                <div class="p-4 text-center text-muted">
                    Bu ay tüketim verisi yok.
                </div>
            @else
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Kullanıcı</th>
                                <th>Toplam Ürün</th>
                                <th>Toplam Tutar</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($topConsumers as $index => $consumer)
                                <tr>
                                    <td>
                                        <span class="badge badge-{{ $index < 3 ? 'warning' : 'info' }}">
                                            {{ $index + 1 }}
                                        </span>
                                    </td>
                                    <td>{{ $consumer->user->name ?? 'Silinmiş Kullanıcı' }}</td>
                                    <td>{{ number_format($consumer->total_items) }}</td>
                                    <td>{{ number_format($consumer->total_spent, 2, ',', '.') }} ₺</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
@endsection