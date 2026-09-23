@extends('layouts.app')

@section('title', 'Stok Sayımı - Kral Kafe')
@section('page-title', 'Ürünler ve Stok')

@section('content')
    @include('admin.products._tabs')

    <div class="alert alert-info mb-4">
        💡 Bir konum seçin, fotoğraf çekin; yapay zeka sayar, siz onaylarsınız. Onaylanan sayı ürünün stoğu olur.
    </div>

    <!-- Konum secimi -->
    <div class="card mb-4">
        <div class="card-header">
            <h4>Hangi konumu sayıyorsun?</h4>
        </div>
        <div class="card-body">
            <div class="d-grid gap-2" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
                @forelse($locations as $location)
                    <a href="{{ route('admin.stock.capture', $location) }}" class="card" style="text-decoration: none;">
                        <div class="card-body text-center">
                            <span style="font-size: 2.5rem;">
                                @switch($location->type)
                                    @case('shelf') 📚 @break
                                    @case('cabinet') 🗄️ @break
                                    @case('fridge') ❄️ @break
                                @endswitch
                            </span>
                            <h5 class="mt-2 mb-1">{{ $location->name }}</h5>
                            <span class="text-muted">{{ $location->products_count }} ürün</span>
                        </div>
                    </a>
                @empty
                    <p class="text-muted">Henüz konum etiketi taşıyan ürün yok. Ürün formundan konum seçin.</p>
                @endforelse
            </div>
        </div>
    </div>
    
    <!-- Çözülmemiş Tutarsızlıklar -->
    @if($unresolvedDiscrepancies->isNotEmpty())
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h4>⚠️ Çözülmemiş Tutarsızlıklar</h4>
                <span class="badge badge-danger">{{ $unresolvedDiscrepancies->count() }}</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Tarih</th>
                                <th>Konum</th>
                                <th>Ürün</th>
                                <th>Tip</th>
                                <th>Fark</th>
                                <th>İşlem</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($unresolvedDiscrepancies as $discrepancy)
                                <tr>
                                    <td>{{ $discrepancy->created_at->timezone(config('kafe.timezone'))->format('d.m.Y H:i') }}</td>
                                    <td>{{ $discrepancy->location->name ?? '-' }}</td>
                                    <td>{{ $discrepancy->product->name ?? '-' }}</td>
                                    <td>
                                        <span class="badge badge-{{ $discrepancy->discrepancy_type == 'shortage' ? 'danger' : 'warning' }}">
                                            {{ $discrepancy->discrepancy_type_name }}
                                        </span>
                                    </td>
                                    <td>{{ $discrepancy->difference }}</td>
                                    <td>
                                        <a href="{{ route('admin.stock.discrepancy', $discrepancy) }}" class="btn btn-sm btn-primary">
                                            İncele
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif
    
    <!-- Son Stok Kayıtları -->
    <div class="card mt-4">
        <div class="card-header">
            <h4>Son Stok Kayıtları</h4>
        </div>
        <div class="card-body p-0">
            @if($recentRecords->isEmpty())
                <div class="p-4 text-center text-muted">
                    Henüz stok kaydı bulunmuyor.
                </div>
            @else
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Tarih</th>
                                <th>Konum</th>
                                <th>Ürün</th>
                                <th>Miktar</th>
                                <th>Kayıt Tipi</th>
                                <th>Kaydeden</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($recentRecords as $record)
                                <tr>
                                    <td>{{ $record->recorded_at->timezone(config('kafe.timezone'))->format('d.m.Y H:i') }}</td>
                                    <td>{{ $record->location->name }}</td>
                                    <td>{{ $record->product->name }}</td>
                                    <td>{{ $record->quantity }}</td>
                                    <td>
                                        <span class="badge badge-{{ $record->record_type == 'ai_verified' ? 'success' : 'info' }}">
                                            {{ $record->record_type_name }}
                                        </span>
                                    </td>
                                    <td>{{ $record->recorder->name ?? '-' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
@endsection
