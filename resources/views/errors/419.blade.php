{{-- Tarayici formlari icin bootstrap/app.php forma geri yonlendiriyor; bu
     sayfa yalnizca yonlendirmenin yapilamadigi durumda gorunur. --}}
@include('errors._sayfa', [
    'kod' => 419,
    'baslik' => 'Sayfanın süresi doldu',
    'mesaj' => 'Sayfa uzun süre açık kaldığı için güvenlik süresi doldu. Sayfayı yenileyip tekrar dene.',
    'yenile' => 'Sayfayı yenile',
])
