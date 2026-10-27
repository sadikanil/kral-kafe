{{--
    Adisyonum'daki odeme durumu alani (DEV-506). Tahsilat isaretlemesi
    DEV-507'de; o zamana kadar yalnizca hesabin kesilip kesilmedigi.
--}}
@if($isCurrent)
    <span class="badge badge-info">Ay sonunda kesinleşir</span>
@elseif($bill)
    <span class="badge badge-warning">Hesap çıkarıldı</span>
@else
    <span class="badge badge-info">Hesap henüz çıkarılmadı</span>
@endif
