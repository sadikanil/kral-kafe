@extends('layouts.app')

@section('title', 'Tüketim Geçmişi - Kral Kafe')
@section('page-title', 'Tüketim Geçmişim')

@section('content')
    <!-- Filtre -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="d-flex gap-2" style="flex-wrap: wrap;">
                <div class="form-group mb-0">
                    <input type="month" name="month" class="form-control" value="{{ request('month', date('Y-m')) }}">
                </div>
                <button type="submit" class="btn btn-secondary">Filtrele</button>
                <a href="{{ route('user.history') }}" class="btn btn-secondary">Tümü</a>
            </form>
        </div>
    </div>

    <!-- Tüketim Listesi -->
    <div class="card">
        <div class="card-body p-0">
            @if($consumptions->isEmpty())
                <div class="p-4 text-center text-muted">
                    Seçilen dönemde tüketim kaydı bulunmuyor.
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
                            @foreach($consumptions as $consumption)
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

        @if($consumptions->hasPages())
            <div class="card-footer">
                {{ $consumptions->withQueryString()->links() }}
            </div>
        @endif
    </div>

    <!-- Özet -->
    <div class="card mt-4">
        <div class="card-header">
            <h4>Dönem Özeti</h4>
        </div>
        <div class="card-body">
            <div class="d-flex gap-3" style="flex-wrap: wrap;">
                <div>
                    <span class="text-muted">Toplam Ürün:</span>
                    <strong>{{ $consumptions->sum('quantity') }}</strong>
                </div>
                <div>
                    <span class="text-muted">Toplam Tutar:</span>
                    <strong>{{ number_format($consumptions->sum('total_price'), 2, ',', '.') }} ₺</strong>
                </div>
            </div>
        </div>
    </div>
@endsection