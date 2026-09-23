{{--
    Calisma kayitlari (Dalga 28) - veli ve koc ekrani, salt okunur.

    Ogrencinin kendi beyani; onayli sure degil. Gun gun, yeniden eskiye.
    Beklenen: $studyLogs (StudyLog koleksiyonu, subject yuklu).
--}}
<div class="card mb-3">
    <div class="card-header"><h4>✅ Çalışma kayıtları · son 14 gün</h4></div>
    <div class="card-body">
        @if($studyLogs->isEmpty())
            <p class="text-muted mb-0">Henüz kayıt yok.</p>
        @else
            @foreach($studyLogs->groupBy(fn ($k) => \App\Support\LocalDay::of($k->created_at)) as $gun => $kayitlar)
                <p class="mt-2 mb-1">
                    <strong>{{ \Illuminate\Support\Carbon::parse($gun)->locale('tr')->translatedFormat('j F l') }}</strong>
                    <span class="text-muted">· {{ collect(\App\Models\StudyLog::totals($kayitlar))->map(fn ($adet, $birim) => "$adet $birim")->implode(' · ') }}</span>
                </p>
                <ul class="log-list mb-2">
                    @foreach($kayitlar as $kayit)
                        <li>
                            <span class="text-muted">{{ $kayit->created_at->timezone(config('kafe.timezone'))->format('H:i') }}</span>
                            <span class="log-label">{{ $kayit->label() }}
                                @if($kayit->note)<small class="text-muted">— {{ $kayit->note }}</small>@endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endforeach
        @endif
    </div>
</div>
