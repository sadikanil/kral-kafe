{{--
    Serbest denemeler (Dalga 30a): tarihi ogrenci secer, ay icinde.
    Beklenen: $flexible (ExamEvent koleksiyonu).
--}}
@if($flexible->isNotEmpty())
    <div class="card mb-3">
        <div class="card-header"><h4>🗓️ Serbest denemeler</h4></div>
        <div class="card-body">
            <p class="text-muted">Bu denemelerin günü sabit değil; pencere içinde istediğin gün çözebilirsin.</p>
            <ul class="log-list">
                @foreach($flexible as $deneme)
                    <li>
                        <span class="badge badge-{{ $deneme->exam_type->badgeClass() }}">{{ $deneme->exam_type->label() }}</span>
                        <span class="log-label">
                            <strong>{{ $deneme->title }}</strong>
                            @if($deneme->note)<small class="text-muted">· {{ $deneme->note }}</small>@endif
                        </span>
                        <span class="text-muted">{{ $deneme->windowLabel() }}</span>
                        @if($editable ?? false)
                            <a href="{{ route('admin.exams.edit', $deneme) }}" class="btn btn-sm btn-secondary">✏️</a>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
@endif
