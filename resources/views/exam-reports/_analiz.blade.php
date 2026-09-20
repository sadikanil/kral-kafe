{{-- Yapay zeka analizinin gosterimi. Beklenen: $report (ExamReport).
     Yonetici ve ogrenci ayni parcayi gorur. --}}
@if($report->status === \App\Models\ExamReport::FAILED)
    <div class="alert alert-danger">❌ Analiz yapılamadı: {{ $report->error }}</div>
@elseif(!$report->isAnalyzed())
    <div class="alert alert-warning">⏳ Analiz henüz yapılmadı.</div>
@else
    @php $a = $report->analysis; $o = $a['overall'] ?? []; @endphp

    @if(!empty($a['exam']['name']) || !empty($a['exam']['type']))
        <p class="text-muted mb-2">
            {{ $a['exam']['name'] ?? '' }}
            @if(!empty($a['exam']['type'])) <span class="badge badge-info">{{ $a['exam']['type'] }}</span> @endif
            @if(!empty($a['exam']['date'])) · {{ $a['exam']['date'] }} @endif
        </p>
    @endif

    <div class="d-flex gap-2 mb-3" style="flex-wrap: wrap;">
        @foreach(['net' => 'Net', 'correct' => 'Doğru', 'wrong' => 'Yanlış', 'blank' => 'Boş', 'score' => 'Puan'] as $anahtar => $etiket)
            @if(isset($o[$anahtar]) && $o[$anahtar] !== null)
                <div class="card" style="flex: 1; min-width: 100px;">
                    <div class="card-body text-center">
                        <div class="session-timer">{{ is_numeric($o[$anahtar]) ? rtrim(rtrim(number_format((float) $o[$anahtar], 2, ',', '.'), '0'), ',') : $o[$anahtar] }}</div>
                        <div class="text-muted">{{ $etiket }}</div>
                    </div>
                </div>
            @endif
        @endforeach
        @if(!empty($o['rank']))
            <div class="card" style="flex: 1; min-width: 100px;">
                <div class="card-body text-center">
                    <div class="session-timer" style="font-size: 1.1rem;">{{ $o['rank'] }}</div>
                    <div class="text-muted">Sıralama</div>
                </div>
            </div>
        @endif
    </div>

    @if(!empty($a['subjects']))
        <div class="table-responsive mb-3">
            <table class="table">
                <thead><tr><th>Ders</th><th>Doğru</th><th>Yanlış</th><th>Boş</th><th>Net</th></tr></thead>
                <tbody>
                    @foreach($a['subjects'] as $ders)
                        <tr>
                            <td><strong>{{ $ders['name'] ?? '—' }}</strong></td>
                            <td>{{ $ders['correct'] ?? '—' }}</td>
                            <td>{{ $ders['wrong'] ?? '—' }}</td>
                            <td>{{ $ders['blank'] ?? '—' }}</td>
                            <td>{{ isset($ders['net']) && $ders['net'] !== null ? number_format((float) $ders['net'], 2, ',', '.') : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <div class="d-flex gap-2 mb-3" style="flex-wrap: wrap;">
        <div class="card" style="flex: 1; min-width: 240px;">
            <div class="card-header"><h4 class="text-success">✅ Başarılı alanlar</h4></div>
            <div class="card-body">
                @forelse($a['strong_areas'] as $alan)
                    <div class="mb-2">
                        <strong>{{ $alan['subject'] ?? '' }}</strong>@if(!empty($alan['topic'])) · {{ $alan['topic'] }}@endif
                        @if(!empty($alan['evidence']))<br><small class="text-muted">{{ $alan['evidence'] }}</small>@endif
                    </div>
                @empty
                    <p class="text-muted mb-0">Belgede ayırt edilebilir güçlü alan bulunamadı.</p>
                @endforelse
            </div>
        </div>
        <div class="card" style="flex: 1; min-width: 240px;">
            <div class="card-header"><h4 class="text-danger">⚠️ Geliştirilmesi gereken alanlar</h4></div>
            <div class="card-body">
                @forelse($a['weak_areas'] as $alan)
                    <div class="mb-2">
                        <strong>{{ $alan['subject'] ?? '' }}</strong>@if(!empty($alan['topic'])) · {{ $alan['topic'] }}@endif
                        @if(!empty($alan['evidence']))<br><small class="text-muted">{{ $alan['evidence'] }}</small>@endif
                    </div>
                @empty
                    <p class="text-muted mb-0">Belgede ayırt edilebilir zayıf alan bulunamadı.</p>
                @endforelse
            </div>
        </div>
    </div>

    @if(!empty($a['focus_suggestions']))
        <div class="card mb-3">
            <div class="card-header"><h4>🎯 Çalışma odağı</h4></div>
            <div class="card-body">
                <ul class="mb-0">
                    @foreach($a['focus_suggestions'] as $oneri)
                        <li>{{ $oneri }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif

    @if(!empty($a['summary']))
        <div class="alert alert-info">{{ $a['summary'] }}</div>
    @endif

    <p class="text-muted" style="font-size: 0.8125rem;">
        Bu analiz yapay zeka tarafından PDF'ten çıkarıldı; sayılar belgeyle karşılaştırılmalı.
        Analiz: {{ $report->analyzed_at?->timezone(config('kafe.timezone'))->format('d.m.Y H:i') }}
    </p>
@endif
