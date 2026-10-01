{{--
    Koc yetkisi ve brans (1 Ekim 2026). Rol tek kalir; veli ya da ogretmen
    ayrica koc olabilir (ornek: hem velisi oldugu cocugu olan hem ozel ders
    verdigi ogrencilere odev veren matematik ogretmeni). Brans ogrencinin
    "kim, hangi dersten" gormesi icin.

    Beklenen: $user (duzenlemede) ya da null (eklemede). Gorunurluk rol
    secimine gore (asagidaki betik); sunucu da rol disi degeri yok sayar.
--}}
@php
    $kocOlabilen = array_map(fn ($r) => $r->value, \App\Models\User::KOC_OLABILEN);
    $kocYetkili = (bool) old('is_coach', $user?->is_coach);
@endphp
<div class="form-group js-koc-yetkisi" data-roller="{{ implode(',', $kocOlabilen) }}">
    <div class="form-check">
        <input type="hidden" name="is_coach" value="0">
        <input type="checkbox" class="form-check-input" id="is_coach" name="is_coach" value="1" @checked($kocYetkili)>
        <label class="form-check-label" for="is_coach">Koçluk yetkisi de var</label>
    </div>
    <small class="text-muted">Veli ya da öğretmen aynı zamanda koçsa işaretleyin. Koç sayfalarında yalnızca kendisine atanan öğrencileri görür, ödeme ve paket bilgisi görmez.</small>
</div>

<div class="form-group js-brans" data-roller="coach,admin">
    <label for="coach_subject" class="form-label">Branş</label>
    <input type="text" id="coach_subject" name="coach_subject" maxlength="60" autocomplete="off"
        class="form-control @error('coach_subject') is-invalid @enderror"
        value="{{ old('coach_subject', $user?->coach_subject) }}" placeholder="Örn. Matematik">
    @error('coach_subject')<span class="invalid-feedback">{{ $message }}</span>@enderror
    <small class="text-muted">Öğrenci ödevi ve özel dersi "İbrahim Acar · Matematik" diye görür.</small>
</div>

@push('scripts')
<script>
    (function () {
        const rol = document.getElementById('role');
        const yetki = document.querySelector('.js-koc-yetkisi');
        const brans = document.querySelector('.js-brans');
        const kutu = document.getElementById('is_coach');
        if (!rol || !yetki || !brans) { return; }

        function guncelle() {
            const kocOlabilir = yetki.dataset.roller.split(',').includes(rol.value);
            yetki.hidden = !kocOlabilir;
            brans.hidden = !(brans.dataset.roller.split(',').includes(rol.value) || (kocOlabilir && kutu.checked));
        }

        rol.addEventListener('change', guncelle);
        kutu.addEventListener('change', guncelle);
        guncelle();
    })();
</script>
@endpush
