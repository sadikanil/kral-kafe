{{-- Ayri sayfasi olmayan diger istemci hatalari (405: adres cubuguna yazilan
     POST adresi gibi) Symfony'nin Ingilizce sayfasina dusmesin. --}}
@include('errors._sayfa', [
    'kod' => $exception->getStatusCode(),
    'baslik' => 'Bu işlem yapılamadı',
    'mesaj' => 'İstek bu haliyle karşılanamadı. Ana sayfadan devam edebilirsin.',
])
