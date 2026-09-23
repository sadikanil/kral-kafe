@extends('layouts.app')

@section('title', $student->name . ' - Zayıf Konular')
@section('page-title', $student->name)

@section('topbar-actions')
    <a href="{{ route('coach.plan.show', $student) }}" class="btn btn-sm btn-secondary">Plan</a>
    <a href="{{ route('coach.notes.index', $student) }}" class="btn btn-sm btn-secondary">Notlar</a>
    <a href="{{ route('coach.report', $student) }}" class="btn btn-sm btn-secondary">Rapor</a>
    <a href="{{ route('coach.plan.index') }}" class="btn btn-sm btn-secondary">← Öğrenciler</a>
@endsection

@section('content')

    <div class="card mb-3">
        <div class="card-header"><h4>Geliştirilmesi gereken konu ekle</h4></div>
        <div class="card-body">
            <p class="text-muted">
                Açık konuları öğrenci ve velisi de görür. Kapatılan konu onların
                listesinden çıkar ama burada kalır — "neyi hallettik" sorusunun cevabı.
            </p>

            <form method="POST" action="{{ route('coach.topics.store', $student) }}"
                  class="d-flex align-items-center gap-2" style="flex-wrap: wrap;">
                @csrf
                <input type="text" name="topic" class="form-control" maxlength="150" required
                       placeholder="Türev - zincir kuralı" value="{{ old('topic') }}">

                <select name="subject_id" class="form-control">
                    <option value="">Ders (isteğe bağlı)</option>
                    @foreach($subjects as $ders)
                        <option value="{{ $ders->id }}" @selected(old('subject_id') == $ders->id)>{{ $ders->name }}</option>
                    @endforeach
                </select>

                <button type="submit" class="btn btn-primary">Ekle</button>
            </form>
            @error('topic')<span class="invalid-feedback">{{ $message }}</span>@enderror
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h4>Konular ({{ $topics->count() }})</h4></div>
        <div class="card-body">
            @forelse($topics as $konu)
                <div class="session-card mb-2">
                    <div class="d-flex align-items-center justify-content-between gap-2" style="flex-wrap: wrap;">
                        <div>
                            <strong>{{ $konu->topic }}</strong>
                            <div class="text-muted">
                                {{ $konu->subject?->name ?? 'Genel' }}
                                @if($konu->status === 'closed' && $konu->closed_at)
                                    · {{ $konu->closed_at->timezone(config('kafe.timezone'))->format('d.m.Y') }} tarihinde kapatıldı
                                @endif
                            </div>
                        </div>

                        <div class="d-flex align-items-center gap-1" style="flex-wrap: wrap;">
                            @if($konu->status === 'open')
                                <span class="badge badge-warning">Açık</span>

                                <form method="POST" action="{{ route('coach.topics.plan', $konu) }}">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-primary">Plana ekle</button>
                                </form>

                                <form method="POST" action="{{ route('coach.topics.close', $konu) }}">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-secondary">Kapat</button>
                                </form>
                            @else
                                <span class="badge badge-success">Kapandı</span>

                                <form method="POST" action="{{ route('coach.topics.reopen', $konu) }}">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-secondary">Yeniden aç</button>
                                </form>
                            @endif

                            <form method="POST" action="{{ route('coach.topics.destroy', $konu) }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-danger">Sil</button>
                            </form>
                        </div>
                    </div>
                </div>
            @empty
                <p class="text-muted mb-0">Henüz konu eklenmemiş.</p>
            @endforelse
        </div>
    </div>
@endsection
