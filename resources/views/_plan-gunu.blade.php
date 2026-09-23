{{--
    Bir gunun icerigi (UX turu, 23 Eyl): haftalik takvim ve ogrenci panelinin
    "Bugun" karti AYNI parcayi cizer, iki ekran ayrismasin.

    Beklenen: $gun (WeekPlan::for() ciktisinin bir gunu), $mode
    ('coach' | 'student' | 'parent'); istege bagli $bos (bos gun metni).
--}}
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
    {{-- id: "✓ Bitti"den sonra takvim bu maddeye doner (capa); ust bar
         yapiskan, madde onun altinda kalmasin diye scroll-margin. --}}
    <div id="madde-{{ $madde->id }}" style="scroll-margin-top: 72px;"
         class="cal-entry cal-item {{ $madde->status === 'done' ? 'done' : '' }} {{ $madde->exam_event_id ? 'cal-exam' : '' }} {{ $mode === 'coach' ? 'has-actions' : '' }}">
        {{-- Bitmislik yalnizca soluk renk ve ustu cizili degil: ekran
             okuyucu da "Tamamlandi" duysun. --}}
        @if($madde->status === 'done')<span role="img" aria-label="Tamamlandı">✓</span>@endif
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

        {{-- Dokunma hedefi tam genislik ve en az 44 px: yogun takvim
             kutusunda kucuk dugme yanlis dokunusa acikti. Yanlis dokunus
             "Geri al" ile duzelir. --}}
        @if($mode === 'student' && $madde->status !== 'done')
            <form method="POST" action="{{ route('user.study-plan.complete', $madde) }}" class="mt-1">
                @csrf
                <button type="submit" class="btn btn-sm btn-success btn-block" style="min-height: 44px;">✓ Bitti</button>
            </form>
        @elseif($mode === 'student')
            <form method="POST" action="{{ route('user.study-plan.reopen', $madde) }}" class="mt-1">
                @csrf
                <button type="submit" class="btn btn-sm btn-secondary">Geri al</button>
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
                {{-- Yalniz simge ekran okuyucuda "yatay uc nokta" diye okunuyordu. --}}
                <summary aria-label="Madde işlemleri: {{ $madde->title }}">⋯</summary>
                <form method="POST" action="{{ route('coach.plan.move', $madde) }}" class="d-flex gap-1 mt-1">
                    @csrf @method('PATCH')
                    <input type="date" name="plan_date" class="form-control" value="{{ $madde->plan_date->toDateString() }}" aria-label="Yeni gün" required>
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
    <p class="text-muted day-empty">{{ $bos ?? '—' }}</p>
@endif
