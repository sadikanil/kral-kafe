{{-- Ayrinti (istisna mesaji) asla gosterilmez; loglarda (stderr) duruyor. --}}
@include('errors._sayfa', [
    'kod' => 500,
    'baslik' => 'Bir şeyler ters gitti',
    'mesaj' => 'Beklenmeyen bir hata oluştu. Birazdan tekrar dene; sorun sürerse kafe yönetimine haber ver.',
    'yenile' => 'Tekrar dene',
])
