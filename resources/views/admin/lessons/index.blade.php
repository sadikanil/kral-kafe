@extends('layouts.app')

@section('title', 'Özel Dersler - Kral Kafe')
@section('page-title', 'Özel Dersler')

{{--
    Ozel dersler (1 Ekim 2026). Yalnizca yonetici (Cahit Hoca):
      - haftalik ders saati: tek ogrenciye ya da ayni saat birden cok ogrenciye
      - ozel ders paketi: birden cok ogrenciye birden (ek paket, fiyat paketten)
      - suren dersler ogretmene gore

    Paketten BAGIMSIZ: kocluk paketi olmayan ogrenciye de ders saati
    tanimlanir. Dersi veren koc kendi derslerini "Özel Derslerim"de gorur;
    ucret, paket ve odeme yalnizca burada ve Odemeler'de.
--}}
@section('content')
    <p class="text-muted mb-3">
        Ders saati paketten bağımsızdır: paketinde özel ders olmayan öğrenciye de saat tanımlayabilirsin.
        Dersi veren koç, öğrencinin koçu olarak da atanır; ödev verebilir, kendi derslerini taşıyıp iptal edebilir.
        Ücret ve ödeme koçlara görünmez.
    </p>

    <div class="card mb-3">
        <div class="card-header"><h4>Ders saati ekle</h4></div>
        <div class="card-body">
            <form method="POST" action="{{ route('admin.lessons.store-many') }}">
                @csrf
                <input type="hidden" name="_form" value="ders">
                <div class="d-flex gap-2" style="flex-wrap: wrap;">
                    <div class="form-group" style="flex: 1 1 200px;">
                        <label for="teacher_id" class="form-label">Dersi veren *</label>
                        <select id="teacher_id" name="teacher_id" class="form-control @error('teacher_id') is-invalid @enderror" required>
                            <option value="">Seçin…</option>
                            @foreach($teachers as $ogretmen)
                                <option value="{{ $ogretmen->id }}" data-brans="{{ $ogretmen->coach_subject }}" @selected((int) old('teacher_id') === $ogretmen->id)>{{ $ogretmen->coachLabel() }}</option>
                            @endforeach
                        </select>
                        @error('teacher_id')<span class="invalid-feedback">{{ $message }}</span>@enderror
                    </div>
                    <div class="form-group" style="flex: 1 1 160px;">
                        <label for="branch" class="form-label">Ders</label>
                        <input type="text" id="branch" name="branch" maxlength="60" class="form-control" value="{{ old('branch') }}" placeholder="Boşsa öğretmenin branşı">
                    </div>
                </div>
                <div class="d-flex gap-2" style="flex-wrap: wrap;">
                    <div class="form-group" style="flex: 1 1 140px;">
                        <label for="weekday" class="form-label">Gün</label>
                        <select id="weekday" name="weekday" class="form-control">
                            @foreach(\App\Models\PrivateLessonSlot::GUNLER as $no => $ad)
                                <option value="{{ $no }}" @selected((int) old('weekday') === $no)>{{ $ad }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group" style="flex: 1 1 110px;">
                        <label for="starts_at" class="form-label">Başlangıç</label>
                        <input type="time" id="starts_at" name="starts_at" class="form-control @error('starts_at') is-invalid @enderror" value="{{ old('starts_at') }}" required>
                    </div>
                    <div class="form-group" style="flex: 1 1 110px;">
                        <label for="ends_at" class="form-label">Bitiş</label>
                        <input type="time" id="ends_at" name="ends_at" class="form-control @error('ends_at') is-invalid @enderror" value="{{ old('ends_at') }}" required>
                        @error('ends_at')<span class="invalid-feedback">{{ $message }}</span>@enderror
                    </div>
                </div>
                @include('admin.lessons._ogrenci-secimi', ['onek' => 'ders'])
                <button type="submit" class="btn btn-primary">Seçilenlere ekle</button>
            </form>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header"><h4>Özel ders paketi ata</h4></div>
        <div class="card-body">
            @if($packages->isEmpty())
                <p class="text-muted mb-0">
                    Özel ders içeren paket yok. <a href="{{ route('admin.packages.create') }}">Paketler</a>'de "Özel ders" kutusu işaretli bir ek paket tanımla
                    (örneğin "Matematik özel ders · 8 ders").
                </p>
            @else
                <form method="POST" action="{{ route('admin.lessons.package') }}">
                    @csrf
                    <input type="hidden" name="_form" value="paket">
                    <div class="d-flex gap-2" style="flex-wrap: wrap;">
                        <div class="form-group" style="flex: 1 1 220px;">
                            <label for="package_id" class="form-label">Paket</label>
                            <select id="package_id" name="package_id" class="form-control @error('package_id') is-invalid @enderror" required>
                                @foreach($packages as $paket)
                                    <option value="{{ $paket->id }}" @selected((int) old('package_id') === $paket->id)>
                                        {{ $paket->name }}{{ $paket->lesson_count ? ' · ' . $paket->lesson_count . ' ders' : '' }} — {{ $paket->formattedPrice() }}
                                    </option>
                                @endforeach
                            </select>
                            @error('package_id')<span class="invalid-feedback">{{ $message }}</span>@enderror
                        </div>
                        <div class="form-group" style="flex: 1 1 150px;">
                            <label for="starts_on" class="form-label">Başlangıç</label>
                            <input type="date" id="starts_on" name="starts_on" class="form-control" value="{{ old('starts_on', $today) }}" required>
                        </div>
                    </div>
                    @include('admin.lessons._ogrenci-secimi', ['onek' => 'paket'])
                    <button type="submit" class="btn btn-primary">Seçilenlere paketi aç</button>
                    <small class="text-muted d-block mt-1">Her öğrenciye ayrı paket dönemi açılır; ödeme takibi Ödemeler'de.</small>
                </form>
            @endif
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header"><h4>Süren dersler</h4></div>
        <div class="card-body p-0">
            @forelse($slots as $ogretmen => $saatler)
                <div class="list-row"><strong>{{ $ogretmen }}</strong><span class="list-row-value">{{ $saatler->count() }} ders</span></div>
                @foreach($saatler as $saat)
                    <div class="list-row">
                        <span class="list-row-main">
                            <a href="{{ route('admin.users.edit', $saat->student) }}" class="list-row-title">{{ $saat->student->name }}</a>
                            <span class="list-row-sub">Her {{ $saat->label() }}{{ $saat->branch ? ' · ' . $saat->branch : '' }}</span>
                        </span>
                        <form method="POST" action="{{ route('admin.lessons.destroy', $saat) }}" onsubmit="return confirm('Bu haftalık saat kaldırılsın mı?')">
                            @csrf @method('DELETE')
                            <button type="submit" class="btn btn-sm btn-secondary">Kaldır</button>
                        </form>
                    </div>
                @endforeach
            @empty
                <p class="text-muted mb-0" style="padding: 16px;">Henüz özel ders saati yok.</p>
            @endforelse
        </div>
    </div>
@endsection

@push('scripts')
<script>
(function () {
    document.querySelectorAll('.js-ogrenci-ara').forEach(function (ara) {
        const liste = document.getElementById(ara.dataset.liste);
        ara.addEventListener('input', function () {
            const q = ara.value.toLocaleLowerCase('tr');
            liste.querySelectorAll('.js-ogrenci').forEach(function (s) {
                s.style.display = s.dataset.ara.includes(q) ? '' : 'none';
            });
        });
    });

    // Ders kutusu bossa ogretmenin bransi onerilir (sunucu da ayni seyi yapar).
    const ogretmen = document.getElementById('teacher_id');
    const brans = document.getElementById('branch');
    ogretmen.addEventListener('change', function () {
        const secili = ogretmen.options[ogretmen.selectedIndex];
        brans.placeholder = (secili && secili.dataset.brans) || 'Boşsa öğretmenin branşı';
    });
})();
</script>
@endpush
