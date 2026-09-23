{{-- Aylik takvim izgarasi. Beklenen: $weeks, $monthLabel, $neighbours,
     $calendarRoute (ay parametresi eklenecek rota adi), istege bagli $editable.
     Deneme adi yalnizca deneme kulubunde (Dalga 19); yonetici her seyi gorur. --}}
@php $detay = $detay ?? auth()->user()?->entitlements()->examClub; @endphp
@php $gunAdlari = ['Pzt', 'Sal', 'Çar', 'Per', 'Cum', 'Cmt', 'Paz']; @endphp

<div class="d-flex justify-content-between align-items-center mb-3">
    <a href="{{ route($calendarRoute, ['ay' => $neighbours['prev']]) }}" class="btn btn-secondary btn-sm">‹ Önceki</a>
    <strong>{{ $monthLabel }}</strong>
    <a href="{{ route($calendarRoute, ['ay' => $neighbours['next']]) }}" class="btn btn-secondary btn-sm">Sonraki ›</a>
</div>

<div class="table-responsive">
    <table class="table" style="table-layout: fixed;">
        <thead>
            <tr>
                @foreach($gunAdlari as $ad)
                    <th class="text-center">{{ $ad }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach($weeks as $hafta)
                <tr>
                    @foreach($hafta as $gun)
                        <td style="vertical-align: top; height: 84px; {{ $gun['inMonth'] ? '' : 'opacity: 0.4;' }} {{ $gun['isToday'] ? 'background: var(--primary-light);' : '' }}">
                            <div class="{{ $gun['isToday'] ? 'text-primary' : 'text-muted' }}" style="font-size: 0.8125rem;">
                                {{ $gun['isToday'] ? '● ' : '' }}{{ $gun['day'] }}
                            </div>
                            @foreach(($lessons ?? [])[$gun['date']] ?? [] as $ders)
                                <div class="mt-1">
                                    <span class="badge badge-success" style="{{ $ders['status'] === 'cancelled' ? 'text-decoration: line-through; opacity: .6;' : '' }}">
                                        Özel ders {{ $ders['starts_at'] }}
                                    </span>
                                    @if($ders['status'] === 'cancelled')<div style="font-size: .75rem;">iptal</div>@endif
                                    @if($ders['status'] === 'moved')<div style="font-size: .75rem;">taşındı</div>@endif
                                </div>
                            @endforeach
                            @foreach($gun['events'] as $deneme)
                                <div class="mt-1">
                                    @if(!empty($editable))
                                        <a href="{{ route('admin.exams.edit', $deneme) }}" class="badge badge-{{ $deneme->exam_type->badgeClass() }}" title="{{ $detay ? $deneme->title : '' }}">
                                            {{ $deneme->exam_type->label() }}{{ $deneme->starts_at ? ' ' . $deneme->starts_at : '' }}
                                        </a>
                                    @else
                                        <span class="badge badge-{{ $deneme->exam_type->badgeClass() }}" title="{{ $detay ? $deneme->title : '' }}">
                                            {{ $deneme->exam_type->label() }}{{ $deneme->starts_at ? ' ' . $deneme->starts_at : '' }}
                                        </span>
                                    @endif
                                    <div style="font-size: 0.75rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">{{ $detay ? $deneme->title : '' }}</div>
                                </div>
                            @endforeach
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
