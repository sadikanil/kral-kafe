@include('errors._sayfa', [
    'kod' => 403,
    'baslik' => 'Bu sayfaya erişimin yok',
    'mesaj' => 'Bu sayfa hesabının yetkisi dışında. Başka bir hesapla girmen gerekiyorsa çıkış yapıp o hesapla gir.',
])
