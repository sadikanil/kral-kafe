{{--
    Haftalik plan takvimi (Dalga 30c) - koc, ogrenci ve veli AYNI parca.

    Beklenen:
      $days     App\Support\WeekPlan::for() ciktisi
      $hafta    haftanin pazartesisi (Y-m-d)
      $navUrl   fn(string $hafta): string - hafta oklarinin adresi
      $mode     'coach' (ekler, tasir, siler) | 'student' (tamamlar) | 'parent'

    Renkler: gri = sabit program, mor = kafede ozel ders, turuncu = deneme,
    beyaz = plan maddesi.
--}}
@php
    $mode ??= 'parent';
    $onceki = \Illuminate\Support\Carbon::parse($hafta)->subWeek()->toDateString();
    $sonraki = \Illuminate\Support\Carbon::parse($hafta)->addWeek()->toDateString();
    $son = \Illuminate\Support\Carbon::parse($hafta)->addDays(6);
    $haftaninMaddeleri = collect($days)->flatMap(fn ($g) => $g['items']);
@endphp

<div class="card mb-3">
    <div class="card-body">
        {{-- Oklar ve baslik TEK satirda; rozet basligin altinda (telefonda kirilmasin). --}}
        <div class="week-nav mb-3">
            <a href="{{ $navUrl($onceki) }}" class="btn btn-sm btn-secondary" aria-label="Önceki hafta">←</a>
            <div class="week-nav-title">
                <strong>{{ \Illuminate\Support\Carbon::parse($hafta)->locale('tr')->translatedFormat('j F') }} – {{ $son->locale('tr')->translatedFormat('j F Y') }}</strong>
                @if($haftaninMaddeleri->isNotEmpty())
                    <span class="badge {{ $haftaninMaddeleri->every(fn ($m) => $m->status === 'done') ? 'badge-success' : 'badge-info' }}">
                        {{ $haftaninMaddeleri->where('status', 'done')->count() }} / {{ $haftaninMaddeleri->count() }} tamamlandı
                    </span>
                @endif
            </div>
            <a href="{{ $navUrl($sonraki) }}" class="btn btn-sm btn-secondary" aria-label="Sonraki hafta">→</a>
        </div>

        <div class="week-grid">
            @foreach($days as $gun)
                <section class="day-col {{ $gun['isToday'] ? 'today' : '' }}">
                    <header class="day-head">
                        <span>{{ $gun['label'] }}</span>
                        @if($mode === 'coach')
                            <button type="button" class="btn btn-sm btn-secondary js-gune-ekle" data-gun="{{ $gun['date'] }}"
                                    aria-label="{{ $gun['label'] }} gününe ekle">+</button>
                        @endif
                    </header>

                    @include('_plan-gunu', ['gun' => $gun, 'mode' => $mode])
                </section>
            @endforeach
        </div>

        <p class="text-muted mt-2 mb-0 cal-legend">
            <span class="cal-legend-item"><span class="cal-dot cal-commit"></span> okul/dershane</span>
            <span class="cal-legend-item"><span class="cal-dot cal-lesson"></span> özel ders</span>
            <span class="cal-legend-item"><span class="cal-dot cal-exam"></span> deneme</span>
            <span class="cal-legend-item"><span class="cal-dot cal-item"></span> plan</span>
        </p>
    </div>
</div>
