@extends('layouts.app')

@section('title', 'Panel - Kral Kafe')
@section('page-title', 'Hoş Geldin, {{ auth()->user()->name }}!')

@section('content')
    @include('exams._geri-sayim')

    @include('exams._hatirlatici', ['calendarRoute' => 'user.exams'])

    @unless($openSession)
        {{-- Gunun ilk dokunusu: kamerayi uygulamanin icinde acar. --}}
        <a href="{{ route('table.scanner') }}" class="btn btn-primary mb-3">QR Okut</a>
    @endunless

    @if($openSession)
        <div class="session-card mb-3">
            <div class="d-flex align-items-center gap-2 mb-2">
                <span class="live-dot"></span>
                <strong>Çalışma sürüyor — {{ $openSession->table->name }}</strong>
            </div>

            @php $dakika = $openSession->minutesSoFar(); @endphp
            <div class="session-timer" data-started-at="{{ $openSession->started_at->toIso8601String() }}">
                {{ sprintf('%02d:%02d', intdiv($dakika, 60), $dakika % 60) }}
            </div>

            <p class="text-muted mb-3">
                {{ $openSession->started_at->timezone(config('kafe.timezone'))->format('H:i') }}'den beri
            </p>

            {{-- Ders etiketi (Dalga 17a). ISTEGE BAGLI: bos birakilan oturum
                 kirilimda "Genel" kovasina duser. Zorunlu kilmak masaya
                 oturmanin onune bir soru koyardi. --}}
            <form action="{{ route('session.subject', $openSession) }}" method="POST"
                  class="d-flex align-items-center gap-2 mb-3" style="flex-wrap: wrap;">
                @csrf
                <label class="text-muted" for="oturum-ders">Ne çalışıyorsun?</label>
                <select name="subject_id" id="oturum-ders" class="form-control" style="max-width: 220px;">
                    <option value="">Genel</option>
                    @foreach($sessionSubjects as $ders)
                        <option value="{{ $ders->id }}" @selected($openSession->subject_id === $ders->id)>{{ $ders->name }}</option>
                    @endforeach
                </select>
                <button type="submit" class="btn btn-sm btn-secondary">Kaydet</button>
            </form>

            <form action="{{ route('session.end') }}" method="POST">
                @csrf
                <button type="submit" class="btn btn-danger">Çalışmayı Bitir</button>
            </form>
        </div>
    @endif
    @if($subscription)
        @php $subscription->syncPaymentStatus(); @endphp
        <div class="card mb-3">
            <div class="card-body d-flex justify-content-between align-items-center" style="flex-wrap: wrap; gap: 8px;">
                <div>
                    🎫 <strong>{{ $subscription->package->name }}</strong>
                    <span class="text-muted">· {{ $subscription->starts_on->format('d.m.Y') }} – {{ $subscription->ends_on->format('d.m.Y') }}</span>
                </div>
                <span class="badge badge-{{ $subscription->payment_status->badgeClass() }}">
                    Ödeme: {{ $subscription->payment_status->label() }}
                </span>
            </div>
        </div>
    @endif

    <!-- Bu Ay Özeti -->
    <div class="stats-grid">
        <div class="stat-card animate-slide-up">
            <div class="stat-icon primary">💰</div>
            <div class="stat-content">
                <div class="stat-value">{{ number_format($currentMonthTotal, 2, ',', '.') }} ₺</div>
                <div class="stat-label">Bu Ay Toplam</div>
            </div>
        </div>

        <div class="stat-card animate-slide-up" style="animation-delay: 50ms">
            <div class="stat-icon success">📦</div>
            <div class="stat-content">
                <div class="stat-value">{{ $currentMonthItems }}</div>
                <div class="stat-label">Ürün Adedi</div>
            </div>
        </div>
    </div>

    <!-- Son Tüketimler -->
    <div class="card animate-slide-up" style="animation-delay: 100ms">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h4>Son Tüketimlerim</h4>
            <a href="{{ route('user.payments') }}" class="btn btn-sm btn-secondary">Tümünü Gör</a>
        </div>
        <div class="card-body p-0">
            @if($recentConsumptions->isEmpty())
                <div class="p-4 text-center text-muted">
                    Henüz tüketim kaydınız bulunmuyor.
                </div>
            @else
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Tarih</th>
                                <th>Ürün</th>
                                <th>Lokasyon</th>
                                <th>Adet</th>
                                <th>Tutar</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($recentConsumptions as $consumption)
                                <tr>
                                    <td>{{ $consumption->consumed_at->format('d.m.Y H:i') }}</td>
                                    <td>{{ $consumption->product->name }}</td>
                                    <td>
                                        <span class="badge badge-info">{{ $consumption->location->name }}</span>
                                    </td>
                                    <td>{{ $consumption->quantity }}</td>
                                    <td>{{ $consumption->formatted_total }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    <!-- Bu Ay Ürün Dağılımı -->
    @if(!empty($monthlySummary['by_product']) && count($monthlySummary['by_product']) > 0)
        <div class="card mt-4 animate-slide-up" style="animation-delay: 150ms">
            <div class="card-header">
                <h4>Bu Ay Tüketim Dağılımı</h4>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Ürün</th>
                                <th>Adet</th>
                                <th>Toplam</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($monthlySummary['by_product'] as $product)
                                <tr>
                                    <td>{{ $product['product_name'] }}</td>
                                    <td>{{ $product['quantity'] }}</td>
                                    <td>{{ number_format($product['total'], 2, ',', '.') }} ₺</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif

    @php
        use App\Support\Duration;
        $hedefDakika = $weeklyGoal?->target_minutes;
        $yuzde = $hedefDakika ? min(100, (int) round($weekMinutes / $hedefDakika * 100)) : null;
    @endphp

    <div class="d-flex gap-2 mb-3" style="flex-wrap: wrap;">
        <div class="card" style="flex: 1; min-width: 140px;">
            <div class="card-body text-center">
                <div class="session-timer">{{ Duration::human($todayMinutes) }}</div>
                <div class="text-muted">Bugün</div>
            </div>
        </div>
        <div class="card" style="flex: 1; min-width: 140px;">
            <div class="card-body text-center">
                <div class="session-timer">{{ Duration::human($weekMinutes) }}</div>
                <div class="text-muted">Bu hafta</div>
            </div>
        </div>
        <div class="card" style="flex: 1; min-width: 140px;">
            <div class="card-body text-center">
                <div class="session-timer">{{ Duration::human($monthMinutes) }}</div>
                <div class="text-muted">Bu ay</div>
            </div>
        </div>
        <div class="card" style="flex: 1; min-width: 140px;">
            <div class="card-body text-center">
                <div class="session-timer">{{ $streak }} gün</div>
                <div class="text-muted">Üst üste</div>
            </div>
        </div>
    </div>

    {{-- Hedef yoksa cubuk HIC cizilmez: bos bir cubuk "hedefin yok" demez,
         "hedefin var ama hic calismadin" der. --}}
    @if($hedefDakika)
        <div class="card mb-3">
            <div class="card-body">
                <div class="progress-label">
                    <span>Haftalık hedef</span>
                    <span>{{ Duration::human($weekMinutes) }} / {{ Duration::human($hedefDakika) }}</span>
                </div>
                <div class="progress">
                    <div class="progress-bar" style="width: {{ $yuzde }}%;"></div>
                </div>
                @if($yuzde >= 100)
                    <p class="text-success mb-0 mt-2">Bu haftanın hedefi tamam. 👏</p>
                @endif
            </div>
        </div>
    @endif

    {{--
        Calisma plani (Dalga 13; aylik donem Dalga 14). Hedef cubugunun
        ALTINDA: once "ne kadar", sonra "ne". Madde yoksa bolum hic
        cizilmez - bos bir liste "plansizsin" demez, "plan var ama bos" der.

        Iki donem ayri listeleniyor cunku farkli aciliyorlar: haftalik madde
        bugun icin, aylik madde ayin geneli icin anlamli. Tek listede
        birlestirmek, ay basindaki bir aylik hedefi "bugun yapilacak" gibi
        gosterirdi.
    --}}
    @foreach([['Bu haftanın planı', $planItems], ['Bu ayın planı', $monthlyPlanItems]] as [$baslik, $liste])
    @if($liste->isNotEmpty())
        @php $tamamlanan = $liste->where('status', 'done')->count(); @endphp

        <h2 class="mt-4">{{ $baslik }} ({{ $tamamlanan }} / {{ $liste->count() }})</h2>

        @foreach($liste as $madde)
            <div class="session-card mb-2">
                <div class="d-flex align-items-center justify-content-between gap-2">
                    <div>
                        <strong>{{ $madde->title }}</strong>
                        <div class="text-muted">{{ $madde->subject?->name ?? 'Genel' }}</div>
                    </div>

                    @if($madde->status === 'done')
                        <span class="badge badge-success">Tamamlandı</span>
                    @else
                        <form method="POST" action="{{ route('user.study-plan.complete', $madde) }}">
                            @csrf
                            <button type="submit" class="btn btn-primary">Tamamladım</button>
                        </form>
                    @endif
                </div>
            </div>
        @endforeach
    @endif
    @endforeach

    {{--
        Bu haftanin ders kirilimi (Dalga 17a). "12 saat calisti" yerine
        "8 saat matematik, 0 saat Turkce". Kayit yoksa cizilmez.
    --}}
    @if($subjectBreakdown !== [])
        <div class="card mb-3">
            <div class="card-header"><h4>Ders kırılımı · bu hafta</h4></div>
            <div class="card-body">
                @foreach($subjectBreakdown as $ad => $dakika)
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span>{{ $ad }}</span>
                        <strong>{{ \App\Support\Duration::human($dakika) }}</strong>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @include('_zayif-konular')

    @include('_koc-notlari')

    @include('_bildirimler')

    {{--
        Onay bekleyen / reddedilen oturumlar (Dalga 9).

        Yukaridaki sure, seri ve hedef cubugu YALNIZCA onayli oturumlari
        sayiyor. Bu liste olmasa ogrenci iki saat calisip panelde sifir
        gorur ve sebebini hicbir yerde bulamazdi.
    --}}
    @if($notCredited->isNotEmpty())
        <h2 class="mt-4">Henüz sayılmayan oturumlar</h2>

        @foreach($notCredited as $oturum)
            @php $dakika = $oturum->duration_minutes ?? 0; @endphp

            <div class="session-card mb-2">
                <div class="d-flex align-items-center justify-content-between gap-2">
                    <div>
                        <strong>{{ $oturum->table->name }}</strong>
                        <div class="text-muted">
                            {{ $oturum->started_at->timezone(config('kafe.timezone'))->format('d.m H:i') }}–{{ $oturum->ended_at->timezone(config('kafe.timezone'))->format('H:i') }}
                            · {{ sprintf('%ds %ddk', intdiv($dakika, 60), $dakika % 60) }}
                        </div>
                        @if($oturum->rejection_reason)
                            <div class="text-muted">{{ $oturum->rejection_reason }}</div>
                        @endif
                    </div>

                    <span class="badge {{ $oturum->approval_status === \App\Enums\ApprovalStatus::Rejected ? 'badge-danger' : 'badge-warning' }}">
                        {{ $oturum->approval_status->label() }}
                    </span>
                </div>
            </div>
        @endforeach
    @endif

    <!-- Bilgilendirme -->
    <div class="alert alert-info mt-4 animate-slide-up" style="animation-delay: 200ms">
        💡 <strong>Nasıl tüketim eklerim?</strong><br>
        Kafe içindeki raflarda, dolaplarda veya buzdolaplarında bulunan QR kodları telefonunuzla tarayarak kolayca tüketim
        kaydı ekleyebilirsiniz.
    </div>
@endsection