@extends('layouts.app')

@section('title', 'Deneme Sonucu - Kral Kafe')
@section('page-title', 'Deneme Sonucu')

@section('content')
    <div class="session-card mb-3">
        <strong>{{ $event->title }}</strong>
        <div class="text-muted">
            {{ $student->name }} ·
            {{ $event->exam_date->timezone(config('kafe.timezone'))->format('d.m.Y') }}
        </div>
    </div>

    @if($subjects->isEmpty())
        <div class="empty-state">
            <div class="empty-state-icon">📚</div>
            <div class="empty-state-title">Önce ders tanımlamalısın</div>
            <p class="text-muted">Bu deneme türü için tanımlı ders yok; dersler müfredattan gelir.</p>
        </div>
    @else
        <form method="POST" action="{{ route('admin.exam-results.store', [$event, $student]) }}">
            @csrf

            <h2>Ders bazlı sonuç</h2>
            <p class="text-muted">Net otomatik hesaplanır: doğru − yanlış/4.</p>

            @foreach($subjects as $ders)
                @php $satir = $result?->subjects->firstWhere('subject_id', $ders->id); @endphp

                {{-- Kutular 0 ile dolu geldigi icin placeholder hic gorunmuyordu:
                     uc ozdes "0" kutusu, Yanlis/Bos karisirsa net sisiyordu. Gorunur
                     etiket + ders basina fieldset (ekran okuyucu "TYT Türkçe, Doğru"). --}}
                <fieldset class="session-card mb-2" style="min-width: 0; margin-left: 0; margin-right: 0;">
                    <legend style="float: left; width: 100%; padding: 0; font-size: inherit; font-weight: 700;">{{ $ders->name }}</legend>
                    <div class="d-flex align-items-center gap-2 mt-2" style="clear: both;">
                        @foreach(['correct' => 'Doğru', 'wrong' => 'Yanlış', 'blank' => 'Boş'] as $alan => $etiket)
                            @php $kimlik = "subjects_{$ders->id}_{$alan}"; $anahtar = "subjects.{$ders->id}.{$alan}"; @endphp
                            <div style="flex: 1;">
                                <label for="{{ $kimlik }}" class="form-label">{{ $etiket }}</label>
                                <input type="number" id="{{ $kimlik }}" min="0" max="200" inputmode="numeric"
                                       class="form-control @error($anahtar) is-invalid @enderror"
                                       name="subjects[{{ $ders->id }}][{{ $alan }}]"
                                       value="{{ old($anahtar, $satir->{$alan} ?? 0) }}" required>
                                @error($anahtar)<span class="invalid-feedback">{{ $message }}</span>@enderror
                            </div>
                        @endforeach
                    </div>
                </fieldset>
            @endforeach

            <h2 class="mt-4">Sıralamalar</h2>
            <p class="text-muted">
                Boş bırakabilirsin — kurum sıralaması ertesi gün, Türkiye geneli
                bir hafta sonra açıklanabiliyor. Katılımcı sayısı olmadan sıra
                tek başına karşılaştırılamaz.
            </p>

            {{-- Sira ve katilimci kutulari da yalnizca placeholder'la adlaniyordu;
                 karisirsa "87 kişide 1.240." cikar. Gorunur etiket (Faz 2 A3). --}}
            @foreach(['institution' => 'Kurum', 'district' => 'İlçe', 'city' => 'İl', 'country' => 'Türkiye'] as $alan => $etiket)
                <fieldset class="mb-2" style="border: 0; padding: 0; margin-left: 0; margin-right: 0; min-width: 0;">
                    <legend class="text-muted" style="float: left; width: 100%; padding: 0; font-size: inherit;">{{ $etiket }}</legend>
                    <div class="d-flex align-items-center gap-2" style="clear: both;">
                        @foreach(['rank' => 'Sıra', 'total' => 'Katılımcı'] as $tur => $kutu)
                            @php $ad = "{$tur}_{$alan}"; @endphp
                            <div style="flex: 1;">
                                <label for="{{ $ad }}" class="form-label">{{ $kutu }}</label>
                                <input type="number" id="{{ $ad }}" min="1" inputmode="numeric"
                                       class="form-control @error($ad) is-invalid @enderror" name="{{ $ad }}"
                                       value="{{ old($ad, $result?->{$ad}) }}">
                                @error($ad)<span class="invalid-feedback">{{ $message }}</span>@enderror
                            </div>
                        @endforeach
                    </div>
                </fieldset>
            @endforeach

            <h2 class="mt-4">Değerlendirme</h2>
            <p class="text-muted" id="note-ipucu">
                Yüklediğin PDF raporunun özetini okuyup buraya yaz. Bu not
                öğrencinin ve velisinin panelinde görünür.
            </p>
            <label for="note" class="form-label">Değerlendirme notu</label>
            <textarea id="note" name="note" class="form-control @error('note') is-invalid @enderror" rows="4" maxlength="1000"
                      aria-describedby="note-ipucu">{{ old('note', $result?->note) }}</textarea>
            @error('note')<span class="invalid-feedback">{{ $message }}</span>@enderror

            <button type="submit" class="btn btn-primary mt-3">Kaydet</button>
        </form>
    @endif
@endsection
