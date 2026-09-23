@extends('layouts.app')

@section('title', 'Lokasyonlar - Kral Kafe')
@section('page-title', 'Lokasyonlar')

@section('topbar-actions')
    <a href="{{ route('admin.locations.create') }}" class="btn btn-primary btn-sm">
        ➕ Yeni Lokasyon
    </a>
@endsection

@section('content')
    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Lokasyon</th>
                            <th>Tip</th>
                            <th>Ürün Sayısı</th>
                            <th>Durum</th>
                            <th>İşlemler</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($locations as $location)
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <span style="font-size: 1.5rem;">
                                            @switch($location->type)
                                                @case('shelf') 📚 @break
                                                @case('cabinet') 🗄️ @break
                                                @case('fridge') ❄️ @break
                                            @endswitch
                                        </span>
                                        <div>
                                            <strong>{{ $location->name }}</strong>
                                            @if($location->description)
                                                <br><small class="text-muted">{{ Str::limit($location->description, 50) }}</small>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td>{{ $location->type_name }}</td>
                                <td>
                                    <span class="badge badge-info">{{ $location->products->count() }}</span>
                                </td>
                                <td>
                                    <form action="{{ route('admin.locations.toggle-status', $location) }}" method="POST">
                                        @csrf
                                        <button type="submit" class="badge badge-{{ $location->is_active ? 'success' : 'warning' }}" style="border: none; cursor: pointer; opacity: 0.9; transition: opacity 0.2s;" title="Değiştirmek için tıkla" onmouseover="this.style.opacity=1" onmouseout="this.style.opacity=0.9">
                                            {{ $location->is_active ? 'Aktif' : 'Pasif' }}
                                        </button>
                                    </form>
                                </td>
                                <td>
                                    <div class="d-flex gap-1">
                                        <a href="{{ route('admin.locations.show', $location) }}" class="btn btn-sm btn-secondary" title="Detay">👁️</a>
                                        <a href="{{ route('admin.locations.edit', $location) }}" class="btn btn-sm btn-secondary" title="Düzenle">✏️</a>
                                        
                                        <form action="{{ route('admin.locations.destroy', $location) }}" method="POST" class="d-inline-block" onsubmit="return confirm('Bu lokasyonu silmek istediğinize emin misiniz?')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-danger" title="Sil">🗑️</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center p-4 text-muted">
                                    Henüz lokasyon eklenmemiş.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
