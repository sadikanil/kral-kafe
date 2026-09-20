@extends('layouts.admin')

@section('title', 'Deneme Raporları - ' . $student->name)
@section('page-title', 'Deneme Raporları: ' . $student->name)

@section('topbar-actions')
    <a href="{{ route('admin.users.edit', $student) }}" class="btn btn-secondary btn-sm">Kullanıcıya dön</a>
@endsection

@section('content')
    <div class="card mb-3" style="max-width: 640px;">
        <div class="card-header"><h4>PDF yükle</h4></div>
        <div class="card-body">
            <form action="{{ route('admin.exam-reports.store', $student) }}" method="POST" enctype="multipart/form-data">
                @csrf
                <div class="form-group">
                    <label for="title" class="form-label">Rapor adı *</label>
                    <input type="text" id="title" name="title" maxlength="150" required
                        class="form-control @error('title') is-invalid @enderror"
                        value="{{ old('title') }}" placeholder="Örn. Türkiye Geneli TYT 3 — sonuç">
                    @error('title')<span class="invalid-feedback">{{ $message }}</span>@enderror
                </div>
                <div class="form-group">
                    <label for="exam_event_id" class="form-label">Takvimdeki deneme</label>
                    <select id="exam_event_id" name="exam_event_id" class="form-control @error('exam_event_id') is-invalid @enderror">
                        <option value="">— bağlama —</option>
                        @foreach($events as $deneme)
                            <option value="{{ $deneme->id }}" {{ (string) old('exam_event_id') === (string) $deneme->id ? 'selected' : '' }}>
                                {{ $deneme->exam_date->format('d.m.Y') }} · {{ $deneme->title }} ({{ $deneme->exam_type->label() }})
                            </option>
                        @endforeach
                    </select>
                    @error('exam_event_id')<span class="invalid-feedback">{{ $message }}</span>@enderror
                </div>
                <div class="form-group">
                    <label for="pdf" class="form-label">PDF dosyası * <small class="text-muted">(en fazla 10 MB)</small></label>
                    <input type="file" id="pdf" name="pdf" accept="application/pdf" required
                        class="form-control @error('pdf') is-invalid @enderror">
                    @error('pdf')<span class="invalid-feedback">{{ $message }}</span>@enderror
                    <small class="text-muted">Yükleme bitince yapay zeka PDF'i okur; başarılı ve zayıf alanlar öğrencinin sayfasında görünür.</small>
                </div>
                <button type="submit" class="btn btn-primary">Yükle ve analiz et</button>
            </form>
        </div>
    </div>

    @forelse($reports as $report)
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center" style="flex-wrap: wrap; gap: 8px;">
                <div>
                    <h4 class="mb-0">{{ $report->title }}</h4>
                    <small class="text-muted">
                        {{ $report->created_at->timezone(config('kafe.timezone'))->format('d.m.Y H:i') }}
                        @if($report->examEvent) · {{ $report->examEvent->title }} @endif
                        · <span class="badge badge-{{ $report->statusBadge() }}">{{ $report->statusLabel() }}</span>
                    </small>
                </div>
                <div class="d-flex gap-1">
                    <a href="{{ route('admin.exam-reports.pdf', $report) }}" class="btn btn-secondary btn-sm" target="_blank">PDF</a>
                    <form action="{{ route('admin.exam-reports.analyze', $report) }}" method="POST" class="d-inline-block">
                        @csrf
                        <button type="submit" class="btn btn-secondary btn-sm">{{ $report->isAnalyzed() ? 'Yeniden analiz et' : 'Analiz et' }}</button>
                    </form>
                    <form action="{{ route('admin.exam-reports.destroy', $report) }}" method="POST" class="d-inline-block"
                        onsubmit="return confirm('Rapor ve PDF silinsin mi?');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-danger btn-sm">Kaldır</button>
                    </form>
                </div>
            </div>
            <div class="card-body">
                @include('exam-reports._analiz', ['report' => $report])
            </div>
        </div>
    @empty
        <div class="card"><div class="card-body text-center text-muted p-4">Bu öğrenci için henüz rapor yüklenmedi.</div></div>
    @endforelse
@endsection
