// public/sw.js karar mantigi (Faz 4). Calistirma: node tests/js/sw.test.mjs
// (tests/Feature/ShellScriptTest.php bunu PHPUnit icinden de calistirir).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

const kaynak = readFileSync(fileURLToPath(new URL('../../public/sw.js', import.meta.url)), 'utf8');
const baglam = { module: { exports: {} }, URL };
vm.runInNewContext(kaynak, baglam);
const sw = baglam.module.exports;

const KOK = 'https://kral-kafe-ten.vercel.app';
const istek = (yol, ek = {}) => ({ url: KOK + yol, method: 'GET', mode: 'no-cors', ...ek });

test('sayfa gezinmesi agdan gelir, agsizken yedek sayfa', () => {
    assert.equal(sw.strateji(istek('/kullanici/panel', { mode: 'navigate' }), KOK), 'agdan-yoksa-yedek');
});

test('surumlu stil, betik ve simge once onbellekten', () => {
    for (const yol of ['/css/app.css?v=abc123', '/js/kabuk.js?v=1', '/img/simgeler.svg?v=9']) {
        assert.equal(sw.strateji(istek(yol), KOK), 'onbellek-once', yol);
    }
});

test('oturumlu veri, form gonderimi, surumsuz dosya ve baska site hic karismaz', () => {
    assert.equal(sw.strateji(istek('/css/app.css'), KOK), null, 'surumsuz: bayat kalabilirdi');
    assert.equal(sw.strateji(istek('/kullanici/adisyon'), KOK), null);
    assert.equal(sw.strateji(istek('/calisma/bitir', { method: 'POST', mode: 'navigate' }), KOK), null);
    assert.equal(sw.strateji({ url: 'https://cdn.jsdelivr.net/npm/jsqr/dist/jsQR.js?v=1', method: 'GET', mode: 'cors' }, KOK), null);
});

test('ayni dosyanin eski surumleri silinmek uzere secilir', () => {
    const onbellekte = [KOK + '/css/app.css?v=eski', KOK + '/js/kabuk.js?v=1', KOK + '/css/app.css?v=yeni'];

    assert.deepEqual(sw.eskiSurumler(onbellekte, KOK + '/css/app.css?v=yeni'), [KOK + '/css/app.css?v=eski']);
});
