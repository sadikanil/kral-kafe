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
        <div class="d-flex align-items-center justify-content-between gap-2 mb-3" style="flex-wrap: wrap;">
            <a href="{{ $navUrl($onceki) }}" class="btn btn-sm btn-secondary" aria-label="Önceki hafta">←</a>
            <strong>
                {{ \Illuminate\Support\Carbon::parse($hafta)->locale('tr')->translatedFormat('j F') }} – {{ $son->locale('tr')->translatedFormat('j F Y') }}
                @if($haftaninMaddeleri->isNotEmpty())
                    <span class="badge {{ $haftaninMaddeleri->every(fn ($m) => $m->status === 'done') ? 'badge-success' : 'badge-info' }}">
                        {{ $haftaninMaddeleri->where('status', 'done')->count() }} / {{ $haftaninMaddeleri->count() }} tamamlandı
                    </span>
                @endif
            </strong>
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

                    @foreach($gun['commitments'] as $program)
                        <div class="cal-entry cal-commit">
                            <span class="cal-time">{{ $program->starts_at }}–{{ $program->ends_at }}</span>
                            {{ $program->kind->icon() }} {{ $program->label() }}
                        </div>
                    @endforeach

                    @foreach($gun['lessons'] as $ders)
                        @continue($ders['status'] === 'cancelled')
                        <div class="cal-entry cal-lesson">
                            <span class="cal-time">{{ $ders['starts_at'] }}–{{ $ders['ends_at'] }}</span>
                            👨‍🏫 Özel ders
                        </div>
                    @endforeach

                    @foreach($gun['exams'] as $deneme)
                        <div class="cal-entry cal-exam">
                            @if($deneme->starts_at)<span class="cal-time">{{ $deneme->starts_at }}</span>@endif
                            📝 {{ $deneme->exam_type->label() }} · {{ $deneme->title }}
                        </div>
                    @endforeach

                    @foreach($gun['items'] as $madde)
                        <div class="cal-entry cal-item {{ $madde->status === 'done' ? 'done' : '' }} {{ $madde->exam_event_id ? 'cal-exam' : '' }}">
                            @if($madde->starts_at)
                                <span class="cal-time">{{ $madde->starts_at }}{{ $madde->duration_minutes ? ' · ' . $madde->duration_minutes . ' dk' : '' }}</span>
                            @elseif($madde->duration_minutes)
                                <span class="cal-time">{{ $madde->duration_minutes }} dk</span>
                            @endif
                            @if($madde->exam_event_id)
                                📝 {{ $madde->title }}
                            @else
                                @if($madde->subject)<strong>{{ $madde->subject->name }}</strong><br>@endif
                                {{ $madde->title }}
                            @endif

                            @if($mode === 'student' && $madde->status !== 'done')
                                <form method="POST" action="{{ route('user.study-plan.complete', $madde) }}" class="mt-1">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-success">✓ Bitti</button>
                                </form>
                            @endif
                            @if($mode === 'student' && $madde->exam_event_id && $madde->created_by === auth()->id())
                                <form method="POST" action="{{ route('user.plan.exam.destroy', $madde) }}" class="mt-1"
                                      onsubmit="return confirm('Bu denemeyi planından çıkaralım mı?')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-secondary">Çıkar</button>
                                </form>
                            @endif
                            @if($mode === 'coach')
                                <details class="cal-actions">
                                    <summary>⋯</summary>
                                    <form method="POST" action="{{ route('coach.plan.move', $madde) }}" class="d-flex gap-1 mt-1">
                                        @csrf @method('PATCH')
                                        <input type="date" name="plan_date" class="form-control" value="{{ $madde->plan_date->toDateString() }}" aria-label="Yeni gün">
                                        <button type="submit" class="btn btn-sm btn-secondary">Taşı</button>
                                    </form>
                                    <form method="POST" action="{{ route('coach.plan.destroy', $madde) }}" class="mt-1"
                                          onsubmit="return confirm('Plandan silinsin mi?')">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-danger">Sil</button>
                                    </form>
                                </details>
                            @endif
                        </div>
                    @endforeach

                    @if($gun['commitments']->isEmpty() && empty($gun['lessons']) && $gun['exams']->isEmpty() && $gun['items']->isEmpty())
                        <p class="text-muted day-empty">—</p>
                    @endif
                </section>
            @endforeach
        </div>

        <p class="text-muted mt-2 mb-0 cal-legend">
            <span class="cal-dot cal-commit"></span> okul/dershane
            <span class="cal-dot cal-lesson"></span> özel ders
            <span class="cal-dot cal-exam"></span> deneme
            <span class="cal-dot cal-item"></span> plan
        </p>
    </div>
</div>
