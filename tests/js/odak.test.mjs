// public/js/odak.js mantigi (Faz 4, odak modu). Calistirma: node tests/js/odak.test.mjs
// (tests/Feature/ShellScriptTest.php bunu PHPUnit icinden de calistirir).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

const kaynak = readFileSync(fileURLToPath(new URL('../../public/js/odak.js', import.meta.url)), 'utf8');
const baglam = { module: { exports: {} } };
vm.runInNewContext(kaynak, baglam);
const k = baglam.module.exports;

const bekle = () => new Promise((r) => setTimeout(r, 0));

function ortam({ pil = null, kilitVar = true } = {}) {
    const kayit = { gonderilen: [], kilitler: 0, birakilan: 0, tamEkran: 0, tamCikis: 0, not: null, acik: null };
    let simdi = 0;
    const o = {
        simdi: () => simdi,
        ilerle: (ms) => { simdi += ms; },
        ekranKilidi: kilitVar ? {
            request: async () => {
                kayit.kilitler++;
                return { release: async () => { kayit.birakilan++; }, released: false };
            },
        } : null,
        pil: pil ? async () => pil : null,
        gonder: (saniye) => kayit.gonderilen.push(saniye),
        tamEkran: { iste: async () => { kayit.tamEkran++; }, birak: async () => { kayit.tamCikis++; } },
        gorunum: { ac: () => { kayit.acik = true; }, kapat: () => { kayit.acik = false; }, pilNotu: (d) => { kayit.not = d; } },
    };
    return { o, kayit };
}

function pil(level, charging) {
    const dinleyiciler = {};
    return { level, charging, addEventListener: (ad, fn) => { dinleyiciler[ad] = fn; }, tetikle: (ad) => dinleyiciler[ad](), dinleyiciler };
}

test('pil uygunlugu: sarjda ya da %20 ve ustu; bilinmiyorsa uygun', () => {
    assert.equal(k.pilUygun(null), true, 'iPhone pil bilgisi vermez');
    assert.equal(k.pilUygun({ level: 0.5, charging: false }), true);
    assert.equal(k.pilUygun({ level: 0.2, charging: false }), true);
    assert.equal(k.pilUygun({ level: 0.19, charging: false }), false);
    assert.equal(k.pilUygun({ level: 0.05, charging: true }), true);
});

test('acinca ekran acik tutulur, tam ekran istenir, gorunum acilir', async () => {
    const { o, kayit } = ortam({ pil: pil(0.8, false) });
    const m = k.odakModu(o);

    await m.ac();

    assert.equal(m.acikMi(), true);
    assert.equal(kayit.kilitler, 1);
    assert.equal(kayit.tamEkran, 1);
    assert.equal(kayit.acik, true);
    assert.equal(kayit.not, false);
});

test('pil dusukse ekran acik tutulmaz ve not gosterilir', async () => {
    const { o, kayit } = ortam({ pil: pil(0.1, false) });

    await k.odakModu(o).ac();

    assert.equal(kayit.kilitler, 0);
    assert.equal(kayit.not, true);
});

test('odak sirasinda pil duserse kilit birakilir; sarja takilinca geri alinir', async () => {
    const p = pil(0.5, false);
    const { o, kayit } = ortam({ pil: p });
    await k.odakModu(o).ac();

    p.level = 0.15;
    p.tetikle('levelchange');
    await bekle();
    assert.equal(kayit.birakilan, 1);
    assert.equal(kayit.not, true);

    p.charging = true;
    p.tetikle('chargingchange');
    await bekle();
    assert.equal(kayit.kilitler, 2);
    assert.equal(kayit.not, false);
});

test('odakta 3 sn ve uzeri ayrilis bildirilir, kisasi bildirilmez', async () => {
    const { o, kayit } = ortam();
    const m = k.odakModu(o);
    await m.ac();

    m.gizlendi(); o.ilerle(2000); await m.gorundu();
    m.gizlendi(); o.ilerle(45400); await m.gorundu();

    assert.deepEqual(kayit.gonderilen, [45]);
});

test('geri donunce ekran kilidi yeniden alinir (tarayici gizlenince birakir)', async () => {
    const { o, kayit } = ortam();
    const m = k.odakModu(o);
    await m.ac();

    m.gizlendi(); o.ilerle(10000); await m.gorundu();

    assert.equal(kayit.kilitler, 2);
});

test('odak kapaliyken ayrilis sayilmaz', async () => {
    const { o, kayit } = ortam();
    const m = k.odakModu(o);

    m.gizlendi(); o.ilerle(60000); await m.gorundu();

    assert.deepEqual(kayit.gonderilen, []);
});

test('kapatinca kilit ve tam ekran birakilir, sonraki ayrilis sayilmaz', async () => {
    const { o, kayit } = ortam();
    const m = k.odakModu(o);
    await m.ac();

    await m.kapat();
    m.gizlendi(); o.ilerle(60000); await m.gorundu();

    assert.equal(m.acikMi(), false);
    assert.equal(kayit.birakilan, 1);
    assert.equal(kayit.tamCikis, 1);
    assert.equal(kayit.acik, false);
    assert.deepEqual(kayit.gonderilen, []);
});

test('ekran kilidi olmayan tarayicida da odak acilir', async () => {
    const { o, kayit } = ortam({ kilitVar: false });
    const m = k.odakModu(o);

    await m.ac();

    assert.equal(m.acikMi(), true);
    assert.equal(kayit.acik, true);
});

test('saat gorunumu: saat ve dakika, iki basamak', () => {
    assert.equal(k.saatMetni(0), '00:00');
    assert.equal(k.saatMetni(3 * 3600 + 7 * 60 + 59), '03:07');
});
