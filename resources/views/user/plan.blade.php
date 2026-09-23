@extends('layouts.app')

@section('title', 'Planım - Kral Kafe')
@section('page-title', 'Planım')

{{--
    Ogrencinin haftasi (Dalga 30c): kocun plani, okul/dershane, ozel ders ve
    denemeler tek takvimde. Ogrenci maddeyi tamamlar; serbest denemeyi
    istedigi gune koyar (Deneme Kulubu).
--}}
@section('content')
    @include('_plan-takvimi', [
        'mode' => 'student',
        'navUrl' => fn ($h) => route('user.plan', ['hafta' => $h]),
    ])

    @if($examClub && $flexible->isNotEmpty())
        <div class="card mb-3">
            <div class="card-header"><h4>🗓️ Serbest denemeyi planına koy</h4></div>
            <div class="card-body">
                <p class="text-muted">Bu denemelerin günü sabit değil. Pencere içinde sana uyan günü seç.</p>
                @error('plan_date')<p class="text-danger">{{ $message }}</p>@enderror
                @error('exam_event_id')<p class="text-danger">{{ $message }}</p>@enderror
                <ul class="log-list">
                    @foreach($flexible as $deneme)
                        @php
                            $ilk = max($deneme->exam_date->toDateString(), \App\Support\LocalDay::today());
                            $planGunu = $scheduled->get($deneme->id);
                        @endphp
                        <li>
                            <span class="badge badge-{{ $deneme->exam_type->badgeClass() }}">{{ $deneme->exam_type->label() }}</span>
                            <span class="log-label"><strong>{{ $deneme->title }}</strong>
                                <small class="text-muted">· {{ $deneme->windowLabel() }}</small>
                                @if($planGunu)
                                    <small class="text-muted">· Planında: {{ $planGunu->locale('tr')->translatedFormat('j F') }}</small>
                                @endif
                            </span>
                            {{-- Dugme ilk gonderimde kilitlenir: es zamanli cift
                                 dokunusta iki istek de "yok" gorup iki satir acardi. --}}
                            <form method="POST" action="{{ route('user.plan.exam') }}" class="d-flex gap-1"
                                  onsubmit="this.querySelector('button').disabled = true">
                                @csrf
                                <input type="hidden" name="exam_event_id" value="{{ $deneme->id }}">
                                <input type="date" name="plan_date" class="form-control" required
                                       min="{{ $ilk }}" max="{{ $deneme->available_until->toDateString() }}"
                                       value="{{ max($planGunu?->toDateString() ?? $ilk, $ilk) }}"
                                       aria-label="{{ $deneme->title }} günü">
                                <button type="submit" class="btn btn-sm btn-primary">{{ $planGunu ? 'Taşı' : 'Ekle' }}</button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif
@endsection
