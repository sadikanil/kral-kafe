@extends('layouts.app')

@php($para = fn ($n) => number_format((float) $n, 2, ',', '.') . ' ₺')

@section('title', 'Adisyonlar - Kral Kafe')
@section('page-title', 'Adisyonlar')

{{--
    Adisyon tahsilati (DEV-507, 27 Ekim 2026). Aylik adisyonlar; varsayilan
    filtre "Odenmemis" (odenmedi + kismen, tahsil edilecek tutari olan).
    Ustte acik alacak. Her adisyonda "Odeme alindi" (tutar, yontem, tarih,
    not) ve odeme gecmisi; gecmisteki her odeme geri alinabilir, kayit
    silinmez. Telefonda tablo yerine kart: tutar ve dugme kutudan tasmasin.
--}}
@section('page-actions')
    @if($month)
        <a href="{{ route('admin.reports.export-summary', ['year' => $month->year, 'month' => $month->month]) }}" class="btn btn-secondary btn-sm">📥 CSV</a>
    @endif
@endsection

@section('content')
    <section class="tab-hero mb-3" aria-label="Açık alacak">
        <div class="tab-hero-head">
            <span class="tab-hero-month">🧾 Toplam açık alacak</span>
            @if($monthLabel)<span class="tab-hero-table">{{ $monthLabel }}</span>@endif
        </div>
        <div class="tab-hero-total">{{ $para($openTotal) }}</div>
        <div class="tab-hero-meta">Ödenmemiş ve kısmen ödenmiş adisyonların kalanı. Paket bedelleri Ödemeler'de.</div>
    </section>

    <form method="GET" action="{{ route('admin.bills.index') }}" class="card mb-3">
        <div class="card-body d-flex gap-2 align-items-end" style="flex-wrap: wrap;">
            <div class="form-group mb-0" style="flex: 1; min-width: 140px;">
                <label for="durum" class="form-label">Durum</label>
                <select id="durum" name="durum" class="form-control">
                    <option value="odenmemis" @selected($status === 'odenmemis')>Ödenmemiş</option>
                    <option value="odendi" @selected($status === 'odendi')>Ödendi</option>
                    <option value="tumu" @selected($status === 'tumu')>Tümü</option>
                </select>
            </div>
            <div class="form-group mb-0" style="flex: 1; min-width: 140px;">
                <label for="ay" class="form-label">Ay</label>
                <select id="ay" name="ay" class="form-control">
                    <option value="">Tüm aylar</option>
                    @foreach($months as $secenek)
                        <option value="{{ $secenek->format('Y-m') }}" @selected($month && $month->isSameMonth($secenek))>{{ \App\Support\ExamCalendar::monthLabel($secenek->year, $secenek->month) }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="btn btn-primary">Göster</button>
        </div>
    </form>

    @forelse($bills as $bill)
        @php($kalan = $bill->remaining())
        <div class="card mb-2">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center gap-2">
                    <div>
                        <strong>{{ $bill->user?->name ?? 'Silinmiş kullanıcı' }}</strong>
                        <div class="text-muted">{{ $bill->month_name }} · {{ $bill->total_items }} ürün</div>
                    </div>
                    @if($bill->dueAmount() <= 0)
                        <span class="badge badge-info">Ödenecek tutar yok</span>
                    @else
                        <span class="badge badge-{{ $bill->payment_status->badgeClass() }}">{{ $bill->payment_status->label() }}</span>
                    @endif
                </div>

                <p class="mb-0 mt-1">
                    Adisyon {{ $para($bill->dueAmount()) }} · Ödenen {{ $para($bill->paid_amount) }} · Kalan <strong>{{ $para($kalan) }}</strong>
                    @if($bill->paid_at)<span class="text-muted"> · son ödeme {{ $bill->paid_at->format('d.m.Y') }}</span>@endif
                </p>

                @if($kalan > 0)
                    <details class="form-section" @if($errors->any() && (int) old('bill_id') === $bill->id) open @endif>
                        <summary>Ödeme alındı</summary>
                        <form method="POST" action="{{ route('admin.bills.payments.store', $bill) }}" class="d-flex gap-2 align-items-end" style="flex-wrap: wrap;">
                            @csrf
                            <input type="hidden" name="bill_id" value="{{ $bill->id }}">
                            <div class="form-group mb-0">
                                <label class="form-label" for="amount_{{ $bill->id }}">Tutar (₺)</label>
                                <input type="number" id="amount_{{ $bill->id }}" name="amount" step="0.01" min="0.01" max="{{ number_format($kalan, 2, '.', '') }}"
                                    required class="form-control" style="width: 130px;" value="{{ number_format($kalan, 2, '.', '') }}">
                            </div>
                            <div class="form-group mb-0">
                                <label class="form-label" for="method_{{ $bill->id }}">Yöntem</label>
                                <select id="method_{{ $bill->id }}" name="method" class="form-control">
                                    @foreach($methods as $yontem)
                                        <option value="{{ $yontem->value }}">{{ $yontem->label() }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="form-group mb-0">
                                <label class="form-label" for="paid_on_{{ $bill->id }}">Tarih</label>
                                <input type="date" id="paid_on_{{ $bill->id }}" name="paid_on" max="{{ $today }}" value="{{ $today }}" class="form-control">
                            </div>
                            <div class="form-group mb-0" style="flex: 1; min-width: 160px;">
                                <label class="form-label" for="note_{{ $bill->id }}">Not</label>
                                <input type="text" id="note_{{ $bill->id }}" name="note" maxlength="255" class="form-control">
                            </div>
                            <button type="submit" class="btn btn-primary">Kaydet</button>
                        </form>
                        @if((int) old('bill_id') === $bill->id)
                            @foreach(['amount', 'method', 'paid_on', 'note'] as $alan)
                                @error($alan)<p class="text-danger mb-0 mt-1">{{ $message }}</p>@enderror
                            @endforeach
                        @endif
                    </details>
                @endif

                @if($bill->payments->isNotEmpty())
                    <details class="form-section">
                        <summary>Ödeme geçmişi <span class="text-muted">({{ $bill->payments->count() }})</span></summary>
                        <ul class="log-list">
                            @foreach($bill->payments->reverse() as $odeme)
                                <li>
                                    <span class="text-muted">{{ $odeme->paid_on->format('d.m.Y') }}</span>
                                    <span class="log-label">
                                        @if($odeme->isReversed())<s>{{ $para($odeme->amount) }}</s>@else<strong>{{ $para($odeme->amount) }}</strong>@endif
                                        · {{ $odeme->method->label() }}
                                        <br><small class="text-muted">{{ $odeme->recorder?->name ?? '—' }}, {{ $odeme->created_at->timezone(config('kafe.timezone'))->format('d.m.Y H:i') }}@if($odeme->note) · {{ $odeme->note }}@endif</small>
                                        @if($odeme->isReversed())
                                            <br><small class="text-danger">Geri alındı: {{ $odeme->reverser?->name ?? '—' }}, {{ $odeme->reversed_at->timezone(config('kafe.timezone'))->format('d.m.Y H:i') }}</small>
                                        @endif
                                    </span>
                                    @unless($odeme->isReversed())
                                        <form method="POST" action="{{ route('admin.bills.payments.reverse', $odeme) }}"
                                            onsubmit="return confirm('{{ $para($odeme->amount) }} ödeme geri alınsın mı? Kayıt geçmişte kalır.');">
                                            @csrf
                                            <button type="submit" class="btn btn-secondary" aria-label="{{ $para($odeme->amount) }} ödemeyi geri al">Geri al</button>
                                        </form>
                                    @endunless
                                </li>
                            @endforeach
                        </ul>
                    </details>
                @endif
            </div>
        </div>
    @empty
        <div class="card"><div class="card-body text-center text-muted p-4">
            {{ $status === 'odenmemis' ? 'Açık adisyon yok.' : 'Bu filtrede adisyon yok.' }}
            Adisyonlar ay sonunda Raporlar sayfasından oluşturulur.
        </div></div>
    @endforelse

    @if($bills->hasPages())
        <div class="mt-3">{{ $bills->links() }}</div>
    @endif
@endsection
