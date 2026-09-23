{{--
    Marka: renkli olan tek sey logo (Faz 3). Sahibi public/img/logo.svg (ya
    da .png) koyana kadar yalniz yazi markasi. Logo suslemedir (alt bos);
    ad hemen yaninda metin olarak var.

    $sinif: logonun kabuktaki yerine gore sinifi (sidebar-logo-icon, ...).
--}}
@php
    $logo = collect(['img/logo.svg', 'img/logo.png'])->first(fn ($yol) => is_file(public_path($yol)));
@endphp
@if($logo)
    <span class="{{ $sinif ?? 'sidebar-logo-icon' }}" aria-hidden="true"><img src="{{ \App\Support\Asset::url($logo) }}" alt="" class="brand-logo"></span>
@endif
<span>Kral Kafe</span>
