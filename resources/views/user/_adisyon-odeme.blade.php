{{--
    Adisyonum'daki odeme durumu rozeti (DEV-506 alani, DEV-507 icerigi).
    Ayin adisyonu ancak yonetici "fatura olustur" deyince cikiyor; o
    zamana kadar bu ay "Ay sonunda kesinlesir". Tahsil edilecek tutar
    adisyonun kendisi (paket bedeli Odemeler'de).
--}}
@if($bill && $bill->dueAmount() > 0)
    @switch($bill->payment_status)
        @case(\App\Enums\BillPaymentStatus::Paid)
            <span class="badge badge-success">Ödendi{{ $bill->paid_at ? ' (' . $bill->paid_at->format('d.m.Y') . ')' : '' }}</span>
            @break
        @case(\App\Enums\BillPaymentStatus::Partial)
            <span class="badge badge-info">Kısmen ödendi · {{ \App\Support\TabSummary::money($bill->remaining()) }} kaldı</span>
            @break
        @default
            <span class="badge badge-warning">Ödeme bekleniyor</span>
    @endswitch
@elseif($bill)
    <span class="badge badge-info">Ödenecek tutar yok</span>
@elseif($isCurrent)
    <span class="badge badge-info">Ay sonunda kesinleşir</span>
@else
    <span class="badge badge-info">Hesap henüz çıkarılmadı</span>
@endif
