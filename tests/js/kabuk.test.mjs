// public/js/kabuk.js mantigi (Faz 2 / H). Calistirma: node tests/js/kabuk.test.mjs
// (tests/Feature/ShellScriptTest.php bunu PHPUnit icinden de calistirir).
//
// Tarayici yok: dosya bir vm baglaminda "module" ile yuklenir, baslat()
// calismaz ve fonksiyonlar disari verilir. Elemanlar asagidaki kucuk
// sahte sinifla taklit edilir - betigin kullandigi kadar DOM.

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

const kaynak = readFileSync(fileURLToPath(new URL('../../public/js/kabuk.js', import.meta.url)), 'utf8');
const baglam = { module: { exports: {} } };
vm.runInNewContext(kaynak, baglam);
const k = baglam.module.exports;

let odaktaki = null;

class Eleman {
    constructor(etiket, nitelik = {}, secimler = {}) {
        this.tagName = etiket.toUpperCase();
        this.nitelik = { ...nitelik };
        this.siniflar = new Set((nitelik.class || '').split(' ').filter(Boolean));
        this.secimler = secimler;
        this.cocuklar = [];
        this.dinleyiciler = {};
        this.id = nitelik.id || '';
        this.type = nitelik.type || '';
        this.value = nitelik.value || '';
        this.innerHTML = nitelik.html || '';
        this.textContent = nitelik.metin ?? this.innerHTML.replace(/<[^>]*>/g, '');
        this.disabled = false;
        this.inert = false;
        this.style = {};
        this.nextElementSibling = null;
        this.href = '';
    }
    getAttribute(ad) { return ad in this.nitelik ? this.nitelik[ad] : null; }
    setAttribute(ad, deger) { this.nitelik[ad] = String(deger); }
    removeAttribute(ad) { delete this.nitelik[ad]; }
    hasAttribute(ad) { return ad in this.nitelik; }
    get classList() {
        const s = this.siniflar;
        return { add: (c) => s.add(c), remove: (c) => s.delete(c), contains: (c) => s.has(c) };
    }
    querySelector(secici) { return this.secimler[secici] ?? null; }
    focus() { odaktaki = this; }
    contains(el) { return el === this || this.cocuklar.some((c) => c.contains(el)); }
    appendChild(el) { this.cocuklar.push(el); }
    addEventListener(ad, fn) { this.dinleyiciler[ad] = fn; }
}

function gonderim(form, submitter = null) {
    return {
        target: form,
        submitter,
        defaultPrevented: false,
        preventDefault() { this.defaultPrevented = true; },
    };
}

function kilit() {
    const bekleyen = [];
    const k0 = k.gonderimKilidi((is) => bekleyen.push(is));
    return { ...k0, calistir: () => bekleyen.splice(0).forEach((is) => is()) , bekleyen };
}

// --- Cift gonderim kilidi -----------------------------------------------

test('ikinci gonderim engellenir, dugme veri toplandiktan sonra kapanir', () => {
    const dugme = new Eleman('button', { type: 'submit', html: 'Ekle' });
    const form = new Eleman('form', { method: 'POST' });
    const kl = kilit();

    const ilk = gonderim(form, dugme);
    kl.yakala(ilk);
    assert.equal(ilk.defaultPrevented, false, 'ilk gonderim gitmeli');
    // Olay sirasinda kapanirsa dugmenin name=value'su gonderimden duser.
    assert.equal(dugme.disabled, false);

    kl.calistir();
    assert.equal(dugme.disabled, true);
    assert.equal(dugme.getAttribute('aria-busy'), 'true');
    assert.equal(dugme.textContent, 'İşleniyor…');

    const ikinci = gonderim(form, dugme);
    kl.yakala(ikinci);
    assert.equal(ikinci.defaultPrevented, true, 'ikinci dokunus gitmemeli');
});

test('iptal edilen confirm() formu kilitlemez', () => {
    const dugme = new Eleman('button', { type: 'submit', html: 'Sil' });
    const form = new Eleman('form', { method: 'POST' });
    const kl = kilit();

    const olay = gonderim(form, dugme);
    olay.defaultPrevented = true; // onsubmit="return confirm(...)" -> Vazgec
    kl.yakala(olay);
    kl.calistir();

    assert.equal(form.hasAttribute('data-gonderiliyor'), false);
    assert.equal(dugme.disabled, false);

    // Kullanici bu kez onaylarsa form gider.
    const onayli = gonderim(form, dugme);
    kl.yakala(onayli);
    assert.equal(onayli.defaultPrevented, false);
});

test('GET formu ve yeni sekmeye giden form kilitlenmez', () => {
    const kl = kilit();
    const filtre = new Eleman('form', { method: 'GET' });
    const varsayilan = new Eleman('form');
    const sekme = new Eleman('form', { method: 'POST', target: '_blank' });
    const dugmeHedefli = new Eleman('button', { type: 'submit', formtarget: '_blank', html: 'Yazdır' });

    for (const [form, dugme] of [[filtre, null], [varsayilan, null], [sekme, null], [new Eleman('form', { method: 'post' }), dugmeHedefli]]) {
        kl.yakala(gonderim(form, dugme));
        const tekrar = gonderim(form, dugme);
        kl.yakala(tekrar);
        assert.equal(tekrar.defaultPrevented, false);
    }
    assert.equal(kl.bekleyen.length, 0);
});

test('yalniz simgeli dugmenin metni degismez, ozel bekleme metni kullanilir', () => {
    const kl = kilit();
    const cikis = new Eleman('button', { type: 'submit', html: '🚪' });
    const analiz = new Eleman('button', { type: 'submit', html: 'Analiz et', 'data-bekleme-metni': 'Analiz ediliyor…' });

    kl.yakala(gonderim(new Eleman('form', { method: 'POST' }), cikis));
    kl.yakala(gonderim(new Eleman('form', { method: 'POST' }), analiz));
    kl.calistir();

    assert.equal(cikis.textContent, '🚪');
    assert.equal(cikis.innerHTML, '🚪');
    assert.equal(cikis.disabled, true);
    assert.equal(analiz.textContent, 'Analiz ediliyor…');
});

test('submitter yoksa (Enter) formun gonder dugmesi kapanir; input dugmenin degeri degisir', () => {
    const kl = kilit();
    const girdi = new Eleman('input', { type: 'submit', value: 'Kaydet' });
    const form = new Eleman('form', { method: 'POST' }, { '[type="submit"], button:not([type])': girdi });

    kl.yakala(gonderim(form, null));
    kl.calistir();

    assert.equal(girdi.disabled, true);
    assert.equal(girdi.value, 'İşleniyor…');
});

test('geri/ileri onbellegi donusunde kilit cozulur ve dugme eski haline doner', () => {
    const kl = kilit();
    const dugme = new Eleman('button', { type: 'submit', html: '<span>✅</span> Ödeme kaydet' });
    const form = new Eleman('form', { method: 'POST' });

    kl.yakala(gonderim(form, dugme));
    kl.calistir();
    kl.coz();

    assert.equal(dugme.disabled, false);
    assert.equal(dugme.hasAttribute('aria-busy'), false);
    assert.equal(dugme.innerHTML, '<span>✅</span> Ödeme kaydet');

    const tekrar = gonderim(form, dugme);
    kl.yakala(tekrar);
    assert.equal(tekrar.defaultPrevented, false, 'geri gelen sayfadaki form yeniden gonderilebilmeli');
});

// --- Telefon menusu ------------------------------------------------------

function menuParcalari() {
    const ilkLink = new Eleman('a', { href: '/panel' });
    return {
        ilkLink,
        kenar: new Eleman('aside', { id: 'sidebar' }, { 'a[href], button': ilkLink }),
        karartma: new Eleman('button', { class: 'sidebar-overlay' }),
        ana: new Eleman('main'),
        govde: new Eleman('body'),
        dugme: new Eleman('button', { 'aria-expanded': 'false' }),
    };
}

test('menu acilinca durum, odak ve arka plan kilidi', () => {
    const p = menuParcalari();
    const m = k.menu(p);

    m.degistir();

    assert.equal(m.acikMi(), true);
    assert.equal(p.karartma.classList.contains('open'), true);
    assert.equal(p.dugme.getAttribute('aria-expanded'), 'true');
    assert.equal(p.govde.style.overflow, 'hidden');
    assert.equal(p.ana.inert, true);
    assert.equal(odaktaki, p.ilkLink);
});

test('menu kapaninca her sey geri doner ve odak dugmeye gelir', () => {
    const p = menuParcalari();
    const m = k.menu(p);

    m.ac();
    m.degistir();

    assert.equal(m.acikMi(), false);
    assert.equal(p.karartma.classList.contains('open'), false);
    assert.equal(p.dugme.getAttribute('aria-expanded'), 'false');
    assert.equal(p.govde.style.overflow, '');
    assert.equal(p.ana.inert, false);
    assert.equal(odaktaki, p.dugme);
});

// --- Zil paneli ------------------------------------------------------------

test('zil paneli disari dokununca kapanir, icerde acik kalir', () => {
    const icerik = new Eleman('a');
    const zil = new Eleman('details', { open: '' });
    zil.appendChild(icerik);

    k.zilDisariTiklandi(zil, icerik);
    assert.equal(zil.hasAttribute('open'), true);

    k.zilDisariTiklandi(zil, new Eleman('div'));
    assert.equal(zil.hasAttribute('open'), false);
});

// --- Hata ozeti ------------------------------------------------------------

test('hata anahtari form alan adina cevrilir', () => {
    assert.deepEqual([...k.alanAdlari('subjects.7.correct')], ['subjects[7][correct]', 'subjects.7.correct', 'subjects.7.correct[]']);
    assert.deepEqual([...k.alanAdlari('code')], ['code', 'code[]']);
});

function belge(alanlar) {
    return {
        getElementsByName: (ad) => alanlar[ad] ?? [],
        createElement: (etiket) => new Eleman(etiket),
    };
}

test('ozet satiri sayfadaki alana goturen baglantiya donusur', () => {
    const gizli = new Eleman('input', { type: 'hidden' });
    const alan = new Eleman('input', { type: 'number' });
    const satir = new Eleman('li', { 'data-hata-alani': 'subjects.7.correct', metin: 'Türkçe doğru sayısı en fazla 200 olabilir.' });

    k.ozetiBagla(belge({ 'subjects[7][correct]': [gizli, alan] }), [satir]);

    assert.equal(satir.cocuklar.length, 1);
    const baglanti = satir.cocuklar[0];
    assert.equal(baglanti.textContent, 'Türkçe doğru sayısı en fazla 200 olabilir.');
    assert.equal(baglanti.href, '#' + alan.id);
    assert.notEqual(alan.id, '');
    assert.equal(alan.getAttribute('aria-invalid'), 'true');

    let engellendi = false;
    baglanti.dinleyiciler.click({ preventDefault: () => { engellendi = true; } });
    assert.equal(engellendi, true);
    assert.equal(odaktaki, alan);
});

test('sayfada olmayan alanin satiri duz metin kalir', () => {
    const satir = new Eleman('li', { 'data-hata-alani': 'yok', metin: 'Genel hata' });

    k.ozetiBagla(belge({}), [satir]);

    assert.equal(satir.cocuklar.length, 0);
    assert.equal(satir.textContent, 'Genel hata');
});

test('kirmizi alan hatali isaretlenir ve ardindaki mesaja baglanir', () => {
    const alan = new Eleman('input', { class: 'form-control is-invalid', 'aria-describedby': 'ipucu' });
    const ipucu = new Eleman('small', { class: 'text-muted' });
    const mesaj = new Eleman('span', { class: 'invalid-feedback' });
    alan.nextElementSibling = ipucu;
    ipucu.nextElementSibling = mesaj;

    const mesajsiz = new Eleman('select', { class: 'form-control is-invalid' });

    k.alanlariIsaretle([alan, mesajsiz]);
    k.alanlariIsaretle([alan]); // ikinci cagri ayni kimligi tekrar eklemez

    assert.equal(alan.getAttribute('aria-invalid'), 'true');
    assert.equal(alan.getAttribute('aria-describedby'), 'ipucu ' + mesaj.id);
    assert.equal(mesajsiz.getAttribute('aria-invalid'), 'true');
    assert.equal(mesajsiz.hasAttribute('aria-describedby'), false);
});
