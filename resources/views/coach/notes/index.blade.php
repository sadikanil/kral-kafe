@extends('layouts.app')

@section('title', $student->name . ' - Notlar')
@section('page-title', $student->name)

@section('page-actions')
    <a href="{{ route('coach.plan.index') }}" class="btn btn-sm btn-secondary">← Öğrenciler</a>
@endsection

@section('content')
    @include('coach._sekmeler')


    @php
        use App\Enums\CoachNoteKind;
        use App\Enums\NoteVisibility;
    @endphp

    <div class="card mb-3">
        <div class="card-header"><h4>Yeni kayıt</h4></div>
        <div class="card-body">
            <p class="text-muted">
                Yazdığın not <strong>varsayılan olarak veliyle paylaşılır</strong> ve öğrenci de görür.
                Ham gözlemini kayıt altına almak istiyorsan görünürlüğü "yalnız koç ve yönetici" yap —
                o notu veli de öğrenci de görmez.
            </p>

            <form method="POST" action="{{ route('coach.notes.store', $student) }}">
                @csrf

                <div class="form-group">
                    <label for="body" class="form-label">Not</label>
                    <textarea id="body" name="body" class="form-control" rows="3" maxlength="2000"
                              placeholder="Örn. Matematikte tempo düştü, hafta içi tekrar ekledik." required>{{ old('body') }}</textarea>
                    @error('body')<span class="invalid-feedback">{{ $message }}</span>@enderror
                </div>

                <div class="d-flex gap-2" style="flex-wrap: wrap;">
                    <div class="form-group">
                        <label for="kind" class="form-label">Tür</label>
                        <select id="kind" name="kind" class="form-control">
                            @foreach(CoachNoteKind::cases() as $tur)
                                <option value="{{ $tur->value }}" @selected(old('kind') === $tur->value)>{{ $tur->label() }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="visibility" class="form-label">Görünürlük</label>
                        <select id="visibility" name="visibility" class="form-control">
                            @foreach(NoteVisibility::cases() as $secenek)
                                <option value="{{ $secenek->value }}" @selected(old('visibility') === $secenek->value)>{{ $secenek->label() }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="form-group">
                        {{-- Yalnizca gorusme kaydi icin zorunlu; sunucu tarafi
                             CoachNoteKind::needsDate() ile ayni kurali uygular. --}}
                        <label for="occurred_on" class="form-label">Görüşme günü</label>
                        <input type="date" id="occurred_on" name="occurred_on" class="form-control" value="{{ old('occurred_on') }}">
                        @error('occurred_on')<span class="invalid-feedback">{{ $message }}</span>@enderror
                    </div>
                </div>

                <button type="submit" class="btn btn-primary">Kaydet</button>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h4>Kayıtlar ({{ $notes->count() }})</h4></div>
        <div class="card-body">
            @forelse($notes as $not)
                <div class="session-card mb-2">
                    <div class="d-flex align-items-center justify-content-between gap-2">
                        <div>
                            <span class="badge badge-info">{{ $not->kind->label() }}</span>
                            <span class="badge badge-{{ $not->visibility->badgeClass() }}">{{ $not->visibility->label() }}</span>
                            <div class="text-muted">
                                {{ $not->displayDate()->timezone(config('kafe.timezone'))->format('d.m.Y') }}
                                · {{ $not->author?->name ?? 'Silinmiş kullanıcı' }}
                            </div>
                        </div>

                        <form method="POST" action="{{ route('coach.notes.destroy', $not) }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-sm btn-danger">Sil</button>
                        </form>
                    </div>
                    <p class="mt-2 mb-0">{{ $not->body }}</p>
                </div>
            @empty
                <p class="text-muted mb-0">Henüz kayıt yok.</p>
            @endforelse
        </div>
    </div>
@endsection
