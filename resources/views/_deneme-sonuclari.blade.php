{{--
    Deneme sonuclari listesi. Ogrenci ve veli panelinde AYNI (karar 2:
    profile islenen her sey veliye acik).

    Siralama, gizlilik kurali 4'e (ogrenciler birbiriyle karsilastirilmaz)
    takilmiyor: bu, SINAVIN kendi verisi - sistemin urettigi bir kiyas degil.
--}}
@include('_net-grafigi')

@if($examResults->isNotEmpty())
    <h2>Deneme sonuçları</h2>

    @foreach($examResults as $sonuc)
        <div class="session-card mb-2">
            <strong>{{ $sonuc->event->title }}</strong>
            <div class="text-muted">
                {{ $sonuc->event->exam_date->timezone(config('kafe.timezone'))->format('d.m.Y') }}
                · Toplam net {{ number_format($sonuc->totalNet(), 2, ',', '.') }}
                @if($sonuc->score !== null) · Puan {{ number_format((float) $sonuc->score, 3, ',', '.') }}@endif
            </div>

            <div class="mt-2">
                @foreach($sonuc->subjects as $satir)
                    <div class="text-muted">
                        {{ $satir->subject->name }}:
                        {{ $satir->correct }}D {{ $satir->wrong }}Y {{ $satir->blank }}B
                        · net {{ number_format($satir->net, 2, ',', '.') }}
                    </div>
                @endforeach
            </div>

            @php
                $siralamalar = collect([
                    'Kurum' => $sonuc->rankLabel('institution'),
                    'İlçe' => $sonuc->rankLabel('district'),
                    'İl' => $sonuc->rankLabel('city'),
                    'Türkiye' => $sonuc->rankLabel('country'),
                ])->filter();
            @endphp

            @if($siralamalar->isNotEmpty())
                <div class="mt-2">
                    @foreach($siralamalar as $etiket => $metin)
                        <div class="text-muted">{{ $etiket }}: {{ $metin }}</div>
                    @endforeach
                </div>
            @endif

            {{-- Kurum PDF'inden gelen konu tablosu (1 Ekim 2026). Eksik konu
                 kuralla (ExamTopics::isWeak); sifat yok, yalnizca sayi. --}}
            @if(! empty($sonuc->topics))
                @php $eksikler = $sonuc->weakTopics(); @endphp
                @if($eksikler !== [])
                    <div class="mt-2">
                        <strong>Eksik konular</strong>
                        <ul class="mb-0">
                            @foreach($eksikler as $konu)
                                <li>
                                    @if($konu['subject'])<span class="text-muted">{{ $konu['subject'] }} ·</span>@endif
                                    {{ $konu['topic'] }}
                                    <span class="text-muted">— {{ $konu['questions'] }} soruda {{ $konu['correct'] }} doğru</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                <details class="mt-2">
                    <summary>Konu konu sonuçlar</summary>
                    <ul class="mb-0">
                        @foreach($sonuc->topics as $konu)
                            <li>
                                @if($konu['subject'])<span class="text-muted">{{ $konu['subject'] }} ·</span>@endif
                                {{ $konu['topic'] }}:
                                {{ $konu['correct'] }}D {{ $konu['wrong'] }}Y {{ $konu['blank'] }}B
                                · %{{ \App\Support\ExamTopics::success($konu) }}
                            </li>
                        @endforeach
                    </ul>
                </details>
            @endif

            @if($sonuc->note)
                <div class="alert alert-info mt-2">{{ $sonuc->note }}</div>
            @endif
        </div>
    @endforeach
@endif
