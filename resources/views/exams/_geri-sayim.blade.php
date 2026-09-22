{{--
    Resmi sinav geri sayimi (Dalga 15b). Beklenen: $officialExam (null
    olabilir).

    Deneme hatirlaticisindan AYRI duruyor: YKS bir deneme degil, hedefin
    kendisi. Sinav yoksa hicbir sey cizilmez.
--}}
@if($officialExam)
    <div class="card mb-3 animate-slide-up">
        <div class="card-body text-center">
            <div class="text-muted">Sınava kalan</div>
            <div class="stat-value" style="font-size: 2.25rem;">{{ $officialExam->countdownLabel() }}</div>
            <div>
                <strong>{{ $officialExam->title }}</strong>
                · {{ $officialExam->dateLabel() }}
            </div>
        </div>
    </div>
@endif
