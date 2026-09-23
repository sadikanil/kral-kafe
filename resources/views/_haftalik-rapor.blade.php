{{--
    Haftalik rapor govdesi (Dalga 15a).

    TEK parca, uc ekran (veli, ogrenci, koc): SS6.1-3 geregi ucu de ayni
    sayilari gorur. Ayri ayri yazilsaydi biri gun gelip digerinden farkli
    bir sayi gosterirdi - raporun butun degeri ayni sayiyi konusmak.

    Beklenen: $student, $hafta, $report (null olabilir), $finished.
--}}
@php
    use App\Enums\PlanPeriod;
    use App\Support\Duration;
@endphp

<div class="card mb-3">
    <div class="card-body">
        <div class="d-flex align-items-center justify-content-between gap-2" style="flex-wrap: wrap;">
            <a href="{{ request()->url() }}?hafta={{ PlanPeriod::Week->shift($hafta, -1) }}" class="btn btn-sm btn-secondary">←</a>
            <strong>{{ PlanPeriod::Week->titleFor($hafta) }}</strong>
            <a href="{{ request()->url() }}?hafta={{ PlanPeriod::Week->shift($hafta, 1) }}" class="btn btn-sm btn-secondary">→</a>
        </div>
    </div>
</div>

@if(! $finished)
    <div class="card">
        <div class="card-body">
            <div class="empty-state">
                <div class="empty-state-icon">📅</div>
                <div class="empty-state-title">Hafta tamamlanınca hazır olacak</div>
                <p class="text-muted">
                    Rapor yalnızca bitmiş haftalar için üretilir. Süren bir haftanın
                    yarısını göstermek yanıltıcı olurdu.
                </p>
            </div>
        </div>
    </div>
@elseif($report === null)
    <div class="card">
        <div class="card-body">
            <p class="text-muted mb-0">Bu hafta için kayıt yok.</p>
        </div>
    </div>
@else
    @php $p = $report->payload; @endphp

    <div class="stats-grid mb-3">
        <div class="stat-card">
            <div class="stat-icon primary">⏱️</div>
            <div class="stat-content">
                <div class="stat-value">{{ Duration::human($p['minutes']) }}</div>
                <div class="stat-label">
                    Onaylanmış süre
                    @if($report->minutesChange() > 0)
                        <span class="badge badge-success">+{{ Duration::human($report->minutesChange()) }}</span>
                    @elseif($report->minutesChange() < 0)
                        <span class="badge badge-danger">−{{ Duration::human(abs($report->minutesChange())) }}</span>
                    @endif
                </div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon success">📅</div>
            <div class="stat-content">
                <div class="stat-value">{{ $p['attended_days'] }}</div>
                <div class="stat-label">Geldiği gün</div>
            </div>
        </div>

        {{-- Eski raporlarda 'logged' yok (Dalga 28 oncesi). --}}
        @if(! empty($p['logged']))
            <div class="stat-card">
                <div class="stat-icon success">✅</div>
                <div class="stat-content">
                    <div class="stat-value">{{ collect($p['logged'])->map(fn ($adet, $birim) => "$adet $birim")->implode(' · ') }}</div>
                    <div class="stat-label">Çalışma kayıtları</div>
                </div>
            </div>
        @endif

        @if($p['plan_total'] > 0)
            <div class="stat-card">
                <div class="stat-icon warning">🗓️</div>
                <div class="stat-content">
                    <div class="stat-value">{{ $p['plan_done'] }} / {{ $p['plan_total'] }}</div>
                    <div class="stat-label">Plan tamamlama</div>
                </div>
            </div>
        @endif
    </div>

    {{-- Hedefi OLMAYAN ogrenci "tutturamadi" sayilmaz; cubuk hic cizilmez. --}}
    @if($report->goalPercent() !== null)
        <div class="card mb-3">
            <div class="card-body">
                <div class="progress-label">
                    <span>Haftalık hedef</span>
                    <span>{{ Duration::human($p['minutes']) }} / {{ Duration::human($p['goal_minutes']) }}</span>
                </div>
                <div class="progress">
                    <div class="progress-bar" style="width: {{ $report->goalPercent() }}%"></div>
                </div>
                @if($report->goalMet())
                    <span class="badge badge-success mt-2">Hedef tuttu</span>
                @endif
            </div>
        </div>
    @endif

    @if($p['exams'] !== [])
        <div class="card mb-3">
            <div class="card-header"><h4>Bu haftanın denemeleri</h4></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table">
                        <thead><tr><th>Deneme</th><th>Tarih</th><th>Net</th></tr></thead>
                        <tbody>
                            @foreach($p['exams'] as $deneme)
                                <tr>
                                    <td>{{ $deneme['title'] }}</td>
                                    <td>{{ \Illuminate\Support\Carbon::parse($deneme['date'])->format('d.m.Y') }}</td>
                                    <td>
                                        <strong>{{ number_format($deneme['net'], 2, ',', '.') }}</strong>
                                        @if($loop->last && $p['net_change'] !== null)
                                            @if($p['net_change'] > 0)
                                                <span class="badge badge-success">+{{ number_format($p['net_change'], 2, ',', '.') }}</span>
                                            @elseif($p['net_change'] < 0)
                                                <span class="badge badge-danger">{{ number_format($p['net_change'], 2, ',', '.') }}</span>
                                            @endif
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif

    {{-- Yorum raporun tek INSAN uretimi parcasi (SS6.1-6: sistem sayi
         gosterir, sifat uretmez). --}}
    @if($report->coach_comment)
        <div class="card mb-3">
            <div class="card-header"><h4>Koç yorumu</h4></div>
            <div class="card-body">
                <p class="mb-0">{{ $report->coach_comment }}</p>
            </div>
        </div>
    @endif

    <p class="text-muted">
        {{ $report->generated_at->timezone(config('kafe.timezone'))->format('d.m.Y H:i') }} tarihinde hesaplandı.
    </p>
@endif
