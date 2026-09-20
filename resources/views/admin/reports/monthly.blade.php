@extends('layouts.admin')

@php
    $monthNames = ['Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
    $periodLabel = $monthNames[$month - 1] . ' ' . $year;
@endphp

@section('title', 'Aylık Rapor - Kral Kafe')
@section('page-title', 'Aylık Rapor: ' . $periodLabel)

@section('topbar-actions')
    <a href="{{ route('admin.reports.export-summary', ['year' => $year, 'month' => $month]) }}"
        class="btn btn-secondary btn-sm">📥 Özet CSV</a>
    <a href="{{ route('admin.reports.export-detailed', ['year' => $year, 'month' => $month]) }}"
        class="btn btn-secondary btn-sm">📥 Detay CSV</a>
@endsection

@section('content')
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon primary">💰</div>
            <div class="stat-content">
                <div class="stat-value">{{ number_format($totalAmount, 2, ',', '.') }} ₺</div>
                <div class="stat-label">Toplam Tutar</div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon success">📦</div>
            <div class="stat-content">
                <div class="stat-value">{{ number_format($totalItems) }}</div>
                <div class="stat-label">Toplam Ürün</div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon warning">🧾</div>
            <div class="stat-content">
                <div class="stat-value">{{ $bills->total() }}</div>
                <div class="stat-label">Fatura Sayısı</div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" action="{{ route('admin.reports.monthly') }}" class="d-flex gap-2 align-items-end">
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

    <div class="card">
        <div class="card-header">
            <h4>🧾 {{ $periodLabel }} Faturaları</h4>
        </div>

        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Kullanıcı</th>
                            <th>E-posta</th>
                            <th class="text-center">Ürün</th>
                            <th>Tüketim</th>
                            <th>Paket</th>
                            <th>Genel Toplam</th>
                            <th>Durum</th>
                            <th>Oluşturulma</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($bills as $bill)
                            <tr>
                                <td>
                                    @if($bill->user)
                                        <a href="{{ route('admin.reports.user', ['user' => $bill->user, 'year' => $year, 'month' => $month]) }}">{{ $bill->user->name }}</a>
                                    @else
                                        <span class="text-muted">Silinmiş kullanıcı</span>
                                    @endif
                                </td>
                                <td class="text-muted">{{ $bill->user?->email ?? '—' }}</td>
                                <td class="text-center">{{ number_format($bill->total_items) }}</td>
                                <td>{{ $bill->formatted_amount }}</td>
                                <td>{{ $bill->formatted_package }}</td>
                                <td><strong>{{ $bill->formatted_total }}</strong></td>
                                <td>
                                    <span class="badge badge-{{ $bill->status === 'pending' ? 'warning' : ($bill->status === 'sent' ? 'success' : 'info') }}">
                                        {{ $bill->status_name }}
                                    </span>
                                </td>
                                <td class="text-muted">{{ $bill->generated_at?->format('d.m.Y H:i') ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center p-4 text-muted">
                                    {{ $periodLabel }} için fatura bulunamadı.
                                    Raporlar sayfasından bu ayın faturalarını oluşturabilirsiniz.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @if($bills->hasPages())
            <div class="card-footer">
                {{ $bills->withQueryString()->links() }}
            </div>
        @endif
    </div>
@endsection
