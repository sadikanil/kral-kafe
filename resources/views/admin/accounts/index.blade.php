@extends('layouts.app')

@php($para = fn ($n) => number_format((float) $n, 2, ',', '.') . ' ₺')
@php($ayAdi = fn ($ay) => \App\Support\ExamCalendar::monthLabel($ay->year, $ay->month))

@section('title', 'Hesaplar - Kral Kafe')
@section('page-title', 'Hesaplar')

{{--
    Hesaplar (21 Kasim 2026). Ay ay, uye uye paket ve adisyon AYRI kutuda:
    yazilan, odenen, kalan, durum. Paket bedeli basladigi aya, adisyon
    siparis ayina yazilir. Odeme formlari mevcut uclara gider ve buraya
    doner. Telefonda tablo yerine kart (bkz. Adisyonlar).
--}}
@section('content')
    <form method="GET" action="{{ route('admin.accounts.index') }}" class="card mb-3">
        <div class="card-body d-flex gap-2 align-items-end" style="flex-wrap: wrap;">
            <div class="form-group mb-0" style="flex: 1; min-width: 150px;">
                <label for="ay" class="form-label">Ay</label>
                <select id="ay" name="ay" class="form-control" data-otomatik-gonder>
                    @if($member)
                        <option value="tum" @selected($allMonths)>Tüm aylar ({{ $member->name }})</option>
                    @endif
                    @foreach($months as $secenek)
                        <option value="{{ $secenek->format('Y-m') }}" @selected($month && $month->isSameMonth($secenek))>
                            {{ $ayAdi($secenek) }} · {{ $loop->first ? 'açık' : 'kapandı' }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="form-group mb-0" style="flex: 1; min-width: 150px;">
                <label for="uye" class="form-label">Üye</label>
                <select id="uye" name="uye" class="form-control" data-otomatik-gonder>
                    <option value="">Tüm üyeler</option>
                    @foreach($members as $secenek)
                        <option value="{{ $secenek->id }}" @selected($member?->id === $secenek->id)>{{ $secenek->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="form-group mb-0" style="flex: 1; min-width: 130px;">
                <label for="tur" class="form-label">Tür</label>
                <select id="tur" name="tur" class="form-control" data-otomatik-gonder>
                    <option value="hepsi" @selected($type === 'hepsi')>Paket + adisyon</option>
                    <option value="paket" @selected($type === 'paket')>Yalnız paket</option>
                    <option value="adisyon" @selected($type === 'adisyon')>Yalnız adisyon</option>
                </select>
            </div>
            <div class="form-group mb-0" style="flex: 1; min-width: 120px;">
                <label for="durum" class="form-label">Durum</label>
                <select id="durum" name="durum" class="form-control" data-otomatik-gonder>
                    <option value="hepsi" @selected($status === 'hepsi')>Hepsi</option>
                    <option value="borclu" @selected($status === 'borclu')>Borçlu</option>
                    <option value="odendi" @selected($status === 'odendi')>Ödendi</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">Göster</button>
        </div>
    </form>

    @if($month && $isClosed)
        <p class="text-muted mb-2">
            <strong>{{ $monthLabel }} kapandı.</strong>
            @if($totals['borclu'] > 0)
                {{ $totals['borclu'] }} üyenin toplam {{ $para($totals['kalan']) }} borcu kaldı.
            @else
                Bu filtrede açık borç yok.
            @endif
        </p>
    @elseif($month)
        <p class="text-muted mb-2"><strong>{{ $monthLabel }}</strong> sürüyor; adisyon her siparişte güncellenir, paketler ayın 1'inde yenilenir.</p>
    @endif

    <div class="d-grid gap-2 mb-3" style="grid-template-columns: repeat(auto-fit, minmax(min(200px, 100%), 1fr));">
        @if($type !== 'adisyon')
            <div class="card mb-0">
                <div class="card-body">
                    <div class="text-muted">🎫 Paket</div>
                    <div><strong>{{ $para($totals['paket']['kalan']) }}</strong> kalan</div>
                    <small class="text-muted">Yazılan {{ $para($totals['paket']['tutar']) }} · Ödenen {{ $para($totals['paket']['odenen']) }}</small>
                </div>
            </div>
        @endif
        @if($type !== 'paket')
            <div class="card mb-0">
                <div class="card-body">
                    <div class="text-muted">🧾 Adisyon</div>
                    <div><strong>{{ $para($totals['adisyon']['kalan']) }}</strong> kalan</div>
                    <small class="text-muted">Yazılan {{ $para($totals['adisyon']['tutar']) }} · Ödenen {{ $para($totals['adisyon']['odenen']) }}</small>
                </div>
            </div>
        @endif
    </div>

    @forelse($rows as $satir)
        @php($p = $satir['paket'])
        @php($a = $satir['adisyon'])
        @php($anahtar = $satir['user']->id . '-' . $satir['ay']->format('Ym'))
        <div class="card mb-2" id="hesap-{{ $anahtar }}">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center gap-2 mb-2" style="flex-wrap: wrap;">
                    <div>
                        @if($allMonths)
                            <strong>{{ $ayAdi($satir['ay']) }}</strong>
                        @else
                            <a href="{{ route('admin.accounts.index', ['uye' => $satir['user']->id, 'ay' => 'tum', 'tur' => $type]) }}"><strong>{{ $satir['user']->name }}</strong></a>
                        @endif
                    </div>
                    @if($satir['kalan'] > 0)
                        <span class="badge badge-danger">Kalan {{ $para($satir['kalan']) }}</span>
                    @else
                        <span class="badge badge-success">Borcu yok</span>
                    @endif
                </div>

                <div class="d-grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(min(260px, 100%), 1fr));">
                    @if($type !== 'adisyon')
                        <section aria-label="Paket">
                            <p class="mb-1"><strong>🎫 Paket</strong>
                                @if($p)<span class="badge badge-{{ $p['renk'] }}">{{ $p['etiket'] }}</span>@endif
                            </p>
                            @if($p)
                                <p class="mb-1 text-muted">
                                    @foreach($p['donemler'] as $donem)
                                        {{ $donem->package->name }} · {{ $donem->starts_on->format('d.m') }}–{{ $donem->ends_on->format('d.m') }}@if($donem->isPartialMonth()) (kıst)@endif @unless($loop->last)<br>@endunless
                                    @endforeach
                                </p>
                                <p class="mb-1">Tutar {{ $para($p['tutar']) }} · Ödenen {{ $para($p['odenen']) }} · Kalan <strong>{{ $para($p['kalan']) }}</strong></p>

                                @if($p['kalan'] > 0 && $p['odeme_donemi'])
                                    @php($d = $p['odeme_donemi'])
                                    <details class="form-section" @if($errors->any() && old('kaynak') === 'paket-' . $d->id) open @endif>
                                        <summary>Paket ödemesi al</summary>
                                        <form method="POST" action="{{ route('admin.subscriptions.payments.store', $d) }}" class="d-flex gap-2 align-items-end" style="flex-wrap: wrap;">
                                            @csrf
                                            <input type="hidden" name="kaynak" value="paket-{{ $d->id }}">
                                            <div class="form-group mb-0">
                                                <label class="form-label" for="p_amount_{{ $d->id }}">Tutar (₺)</label>
                                                <input type="number" id="p_amount_{{ $d->id }}" name="amount" step="0.01" min="0.01" required class="form-control" style="width: 130px;"
                                                    value="{{ number_format($d->balance(), 2, '.', '') }}">
                                            </div>
                                            <div class="form-group mb-0">
                                                <label class="form-label" for="p_method_{{ $d->id }}">Yöntem</label>
                                                <select id="p_method_{{ $d->id }}" name="method" class="form-control">
                                                    @foreach($packageMethods as $yontem)
                                                        <option value="{{ $yontem->value }}">{{ $yontem->label() }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div class="form-group mb-0">
                                                <label class="form-label" for="p_paid_at_{{ $d->id }}">Tarih</label>
                                                <input type="date" id="p_paid_at_{{ $d->id }}" name="paid_at" required value="{{ $today }}" class="form-control">
                                            </div>
                                            <div class="form-group mb-0" style="flex: 1; min-width: 140px;">
                                                <label class="form-label" for="p_note_{{ $d->id }}">Not</label>
                                                <input type="text" id="p_note_{{ $d->id }}" name="note" maxlength="255" class="form-control">
                                            </div>
                                            <button type="submit" class="btn btn-primary">Kaydet</button>
                                        </form>
                                        @if(old('kaynak') === 'paket-' . $d->id)
                                            @foreach(['amount', 'method', 'paid_at', 'note'] as $alan)
                                                @error($alan)<p class="text-danger mb-0 mt-1">{{ $message }}</p>@enderror
                                            @endforeach
                                        @endif
                                    </details>
                                @elseif($p['kalan'] > 0)
                                    <p class="mb-1 text-muted">{{ $p['acik_donem_sayisi'] }} açık dönem var; ödemeyi paket sayfasından dönem seçerek alın.</p>
                                @endif
                                <a href="{{ route('admin.subscriptions.index', $satir['user']) }}">Paket ve ödemeler →</a>
                            @else
                                <p class="text-muted mb-0">Bu ay başlayan paket yok.</p>
                            @endif
                        </section>
                    @endif

                    @if($type !== 'paket')
                        <section aria-label="Adisyon">
                            <p class="mb-1"><strong>🧾 Adisyon</strong>
                                @if($a)<span class="badge badge-{{ $a['renk'] }}">{{ $a['etiket'] }}</span>@endif
                            </p>
                            @if($a)
                                @php($b = $a['bill'])
                                <p class="mb-1">Tutar {{ $para($a['tutar']) }} · Ödenen {{ $para($a['odenen']) }} · Kalan <strong>{{ $para($a['kalan']) }}</strong>
                                    <span class="text-muted">· {{ $b->total_items }} ürün</span></p>

                                @if($a['kalan'] > 0)
                                    <details class="form-section" @if($errors->any() && (int) old('bill_id') === $b->id) open @endif>
                                        <summary>Adisyon ödemesi al</summary>
                                        <form method="POST" action="{{ route('admin.bills.payments.store', $b) }}" class="d-flex gap-2 align-items-end" style="flex-wrap: wrap;">
                                            @csrf
                                            <input type="hidden" name="bill_id" value="{{ $b->id }}">
                                            <div class="form-group mb-0">
                                                <label class="form-label" for="a_amount_{{ $b->id }}">Tutar (₺)</label>
                                                <input type="number" id="a_amount_{{ $b->id }}" name="amount" step="0.01" min="0.01" max="{{ number_format($a['kalan'], 2, '.', '') }}"
                                                    required class="form-control" style="width: 130px;" value="{{ number_format($a['kalan'], 2, '.', '') }}">
                                            </div>
                                            <div class="form-group mb-0">
                                                <label class="form-label" for="a_method_{{ $b->id }}">Yöntem</label>
                                                <select id="a_method_{{ $b->id }}" name="method" class="form-control">
                                                    @foreach($billMethods as $yontem)
                                                        <option value="{{ $yontem->value }}">{{ $yontem->label() }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div class="form-group mb-0">
                                                <label class="form-label" for="a_paid_on_{{ $b->id }}">Tarih</label>
                                                <input type="date" id="a_paid_on_{{ $b->id }}" name="paid_on" max="{{ $today }}" value="{{ $today }}" class="form-control">
                                            </div>
                                            <div class="form-group mb-0" style="flex: 1; min-width: 140px;">
                                                <label class="form-label" for="a_note_{{ $b->id }}">Not</label>
                                                <input type="text" id="a_note_{{ $b->id }}" name="note" maxlength="255" class="form-control">
                                            </div>
                                            <button type="submit" class="btn btn-primary">Kaydet</button>
                                        </form>
                                        @if((int) old('bill_id') === $b->id)
                                            @foreach(['amount', 'method', 'paid_on', 'note'] as $alan)
                                                @error($alan)<p class="text-danger mb-0 mt-1">{{ $message }}</p>@enderror
                                            @endforeach
                                        @endif
                                    </details>
                                @endif
                                <a href="{{ route('admin.bills.index', ['ay' => $satir['ay']->format('Y-m'), 'durum' => 'tumu']) }}">Ödeme geçmişi →</a>
                            @else
                                <p class="text-muted mb-0">Bu ay adisyon yok.</p>
                            @endif
                        </section>
                    @endif
                </div>
            </div>
        </div>
    @empty
        <div class="card"><div class="card-body text-center text-muted p-4">Bu filtrede hesap yok.</div></div>
    @endforelse

    @if($month)
        <form method="POST" action="{{ route('admin.reports.generate-bills') }}" class="mt-3"
              data-confirm="{{ $monthLabel }} adisyonları sipariş kayıtlarından yeniden hesaplansın mı? Alınmış ödemeler silinmez.">
            @csrf
            <input type="hidden" name="year" value="{{ $month->year }}">
            <input type="hidden" name="month" value="{{ $month->month }}">
            <input type="hidden" name="geri" value="hesaplar">
            <button type="submit" class="btn btn-secondary btn-sm">🔄 {{ $monthLabel }} adisyonlarını yeniden hesapla</button>
        </form>
    @endif
@endsection
