@extends('layouts.app')

@section('title', 'Deneme Sonuçları - Kral Kafe')
@section('page-title', 'Deneme Sonuçları')

{{--
    Kurum geneli deneme sonuc PDF'i (1 Ekim 2026). Yonetici takvimdeki
    denemeyi secip kurumun PDF'ini bir kez yukler; yapay zeka sayfa sayfa
    okur, satirlar ogrencilere eslenir, yonetici kontrol edip yayinlar.
--}}
@section('content')
    <p class="text-muted mb-3">
        Yayın kuruluşunun gönderdiği toplu sonuç PDF'ini buradan bir kez yükle. Sistem net listesini ve öğrenci karnelerini okur,
        adları öğrencilerle eşler; sen kontrol edip yayınlayınca her öğrenci yalnızca kendi sonucunu, eksik konularını ve sıralamasını görür.
        Velisine ve koçuna bildirim gider; koç eksik konuları tek dokunuşla plana ekler.
    </p>

    <div class="card mb-3" style="max-width: 640px;">
        <div class="card-header"><h4>PDF yükle</h4></div>
        <div class="card-body">
            {{-- Serbest denemeler once ve yerinde olusturulabilir (5 Ekim 2026). --}}
            <form action="{{ route('admin.exam-imports.store') }}" method="POST" enctype="multipart/form-data">
                @csrf
                @include('admin._deneme-secimi')
                <div class="form-group">
                    <label for="pdf" class="form-label">Sonuç PDF'i * <small class="text-muted">(en fazla 10 MB)</small></label>
                    <input type="file" id="pdf" name="pdf" accept="application/pdf" required class="form-control @error('pdf') is-invalid @enderror">
                    @error('pdf')<span class="invalid-feedback">{{ $message }}</span>@enderror
                </div>
                <button type="submit" class="btn btn-primary">Yükle ve oku</button>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h4>Yüklenenler</h4></div>
        <div class="card-body p-0">
            @php($kuyruk = $imports->filter->isProcessing()->sortBy('id')->pluck('id')->values())
            @forelse($imports as $aktarim)
                <a href="{{ route('admin.exam-imports.show', $aktarim) }}" class="list-row">
                    <span class="list-row-main">
                        <span class="list-row-title">{{ $aktarim->event->title }}</span>
                        <span class="list-row-sub">
                            {{ $aktarim->created_at->timezone(config('kafe.timezone'))->format('d.m.Y H:i') }}
                            · {{ $aktarim->rows_count }} öğrenci
                        </span>
                    </span>
                    {{-- Kuyruk: okunacaklardan ilki disindakiler "Sirada". --}}
                    @php($sira = $kuyruk->search($aktarim->id))
                    <span class="badge badge-{{ $aktarim->statusBadge() }}">{{ $sira ? 'Sırada · önünde ' . $sira : $aktarim->statusLabel() }}</span>
                </a>
            @empty
                <p class="text-muted mb-0" style="padding: 16px;">Henüz PDF yüklenmedi.</p>
            @endforelse
        </div>
    </div>
@endsection
