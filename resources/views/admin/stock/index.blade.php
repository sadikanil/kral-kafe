@extends('layouts.app')

@section('title', 'Stok - Kral Kafe')
@section('page-title', 'Ürünler ve Stok')

{{--
    Stok sayfasi (Dalga 29): urunler konum etiketi ve stokla. Konuma ve
    duruma gore suzulur; stok ve kritik sayi burada toplu girilir. Bos stok
    = takip yok (sicak icecek, cay).
--}}
@php
    $rozetler = [
        'out' => ['Tükendi', 'danger'],
        'critical' => ['Kritik', 'warning'],
        'ok' => ['Yeterli', 'success'],
        'untracked' => ['Takip yok', 'info'],
    ];
@endphp

@section('content')
    @include('admin.products._tabs')

    <form method="GET" action="{{ route('admin.stock.index') }}" class="card mb-3">
        <div class="card-body d-flex gap-2 align-items-end" style="flex-wrap: wrap;">
            <div class="form-group mb-0" style="min-width: 180px;">
                <label for="konum" class="form-label">Konum</label>
                <select id="konum" name="konum" class="form-control" onchange="this.form.submit()">
                    <option value="">Tümü</option>
                    @foreach($locations as $konum)
                        <option value="{{ $konum->id }}" @selected((int) ($filter['konum'] ?? 0) === $konum->id)>{{ $konum->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="form-group mb-0" style="min-width: 180px;">
                <label for="durum" class="form-label">Durum</label>
                <select id="durum" name="durum" class="form-control" onchange="this.form.submit()">
                    <option value="">Tümü</option>
                    <option value="critical" @selected(($filter['durum'] ?? '') === 'critical')>Kritik ve tükenen</option>
                    <option value="ok" @selected(($filter['durum'] ?? '') === 'ok')>Yeterli</option>
                    <option value="untracked" @selected(($filter['durum'] ?? '') === 'untracked')>Takip yok</option>
                </select>
            </div>
            <noscript><button type="submit" class="btn btn-secondary">Süz</button></noscript>
            @if($criticalCount > 0)
                <a href="{{ route('admin.stock.index', ['durum' => 'critical']) }}" class="btn btn-warning">
                    ⚠️ {{ $criticalCount }} ürün kritik
                </a>
            @endif
        </div>
    </form>

    <form method="POST" action="{{ route('admin.stock.update') }}" class="card">
        @csrf
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Ürün</th>
                            <th>Konum</th>
                            <th style="width: 110px;">Stok</th>
                            <th style="width: 110px;">Kritik</th>
                            <th>Durum</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($products as $urun)
                            @php [$etiket, $renk] = $rozetler[$urun->stockStatus()]; @endphp
                            <tr>
                                <td>
                                    <a href="{{ route('admin.products.edit', $urun) }}">{{ $urun->emoji ?: '📦' }} {{ $urun->name }}</a>
                                    <div class="text-muted" style="font-size: .8rem;">{{ $urun->category ?? '' }}</div>
                                </td>
                                <td>{{ $urun->location->name ?? '—' }}</td>
                                <td>
                                    <input type="number" min="0" name="stok[{{ $urun->id }}][quantity]" inputmode="numeric"
                                           class="form-control @error("stok.{$urun->id}.quantity") is-invalid @enderror"
                                           value="{{ old("stok.{$urun->id}.quantity", $urun->stock_quantity) }}" placeholder="—"
                                           aria-label="{{ $urun->name }} stok">
                                </td>
                                <td>
                                    <input type="number" min="0" name="stok[{{ $urun->id }}][critical]" inputmode="numeric"
                                           class="form-control @error("stok.{$urun->id}.critical") is-invalid @enderror"
                                           value="{{ old("stok.{$urun->id}.critical", $urun->critical_quantity) }}" placeholder="—"
                                           aria-label="{{ $urun->name }} kritik stok">
                                    @error("stok.{$urun->id}.critical")<span class="invalid-feedback">{{ $message }}</span>@enderror
                                </td>
                                <td><span class="badge badge-{{ $renk }}">{{ $etiket }}</span></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center p-4 text-muted">Bu süzgece uyan ürün yok.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if($products->isNotEmpty())
            <div class="card-footer d-flex justify-content-between align-items-center gap-2" style="flex-wrap: wrap;">
                <span class="text-muted" style="font-size: .85rem;">Boş stok = takip yok. Kritik sayıya inince bildirim gelir.</span>
                <button type="submit" class="btn btn-primary">💾 Stokları kaydet</button>
            </div>
        @endif
    </form>
@endsection
