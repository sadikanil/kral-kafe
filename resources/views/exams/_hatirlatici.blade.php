{{-- Siradaki deneme hatirlaticisi. Beklenen: $upcomingExams (yakindan uzaga),
     $calendarRoute. Deneme yoksa hicbir sey cizilmez: bos bir kutu
     "takvim bos" demez, "bir sey bozuk" der. --}}
@if($upcomingExams->isNotEmpty())
    @php
        $ilk = $upcomingExams->first();
        $yakin = $ilk->daysUntil() <= (int) config('kafe.deneme_hatirlatma_gun');
    @endphp
    <div class="alert {{ $yakin ? 'alert-warning' : 'alert-info' }} animate-slide-up mb-3">
        📝 <strong>Sıradaki deneme:</strong>
        {{ $ilk->title }} ({{ $ilk->exam_type->label() }}) —
        {{ $ilk->dateLabel() }}{{ $ilk->starts_at ? ' ' . $ilk->starts_at : '' }}
        · <strong>{{ $ilk->countdownLabel() }}</strong>
        @if($upcomingExams->count() > 1)
            <br><small class="text-muted">
                Sonra:
                @foreach($upcomingExams->slice(1) as $deneme)
                    {{ $deneme->title }} ({{ $deneme->exam_date->format('d.m') }}){{ $loop->last ? '' : ',' }}
                @endforeach
            </small>
        @endif
        <br><a href="{{ route($calendarRoute) }}">Takvimi gör →</a>
    </div>
@endif
