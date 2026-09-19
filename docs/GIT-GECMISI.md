# Git geçmişi: bilinmesi gerekenler ve yol haritası

Bu deponun geçmişi **yeniden yazıldı**. Bu belge neden yazıldığını, hangi
tuzakların kaldığını ve bir daha aynı kazaların yaşanmaması için ne yapılması
gerektiğini anlatır.

## Özet

Depo `brashlab/kral-kafe` altındayken kök commit'e iki yardımcı araç
commit'lenmişti: `cloudflared.tgz` (20,1 MiB) ve `composer.phar` (3,1 MiB).
Deponun neredeyse tamamı bu iki dosyaydı. `sadikanil/kral-kafe` adresine
taşınırken `git filter-branch` ile geçmişten temizlendiler; depo 21 MB'tan
332 KB'a indi.

Geçmiş yeniden yazıldığı için **eski ve yeni commit SHA'ları farklı**. Bu,
aşağıdaki tuzağın kaynağı.

## Kalan tuzak: `brashlab` remote'u

`brashlab` remote'u silinmedi, yalnızca adı değiştirildi. Dolayısıyla depoda
**yeniden yazılmadan önceki geçmişe işaret eden canlı bir ref** duruyor:

```
refs/remotes/brashlab/main -> e509af4   (eski geçmiş, iki büyük dosya İÇİNDE)
refs/remotes/origin/main   -> da5f269   (yeni geçmiş, temiz)
```

Bu iki geçmiş **ortak ataya sahip** (`33c8a20`). Sonuçları:

1. **Otomatik fetch temizliği geri alır.** VS Code'un `git.autofetch` ayarı tüm
   remote'ları çeker. Bir kez çektiğinde `brashlab/main` eski değerine
   *forced-update* olur ve silinen 24 MB nesne yerele geri iner. Bu bir kez
   yaşandı; yerel depo hâlâ 21 MiB.
2. **Kazara merge mümkün.** Ortak ata bulunduğu için git
   `--allow-unrelated-histories` istemez, yani koruma bariyeri devreye girmez.
   Böyle bir merge 18 add/add çakışması üretir, silinen iki dosyayı geri ekler
   ve çalışma ağacına çakışma işaretleri bulaştırır. Bu da bir kez yaşandı.

### Şu an alınmış önlem

```bash
git config remote.brashlab.skipFetchAll true
```

`git fetch --all` (ve VS Code'un otomatik fetch'i) artık `brashlab`'a
dokunmuyor. Elle `git fetch brashlab` hâlâ çalışır.

### Kalıcı çözüm

`brashlab` ile işiniz bittiyse remote'u tamamen kaldırın; tuzak da yerel
şişkinlik de ortadan kalkar:

```bash
git remote remove brashlab
git reflog expire --expire=now --all
git gc --prune=now
```

Geri almak tek komut: `git remote add brashlab https://github.com/brashlab/kral-kafe.git`

> Not: `brashlab/kral-kafe` deposunun kendisi temizlenmedi; iki büyük dosya
> orada hâlâ duruyor.

## Kurallar

**Depoya ikili dosya girmez.** Yardımcı araçlar (`cloudflared`, `composer.phar`)
`~/bin` ya da gitignore'lu bir `.scratch/` altına inmeli. İkisi de artık
`.gitignore`'da; çalışma dizininde duruyorlar ama sürüm kontrolüne girmiyorlar.

**İlk push'tan önce büyük dosya taraması yapın:**

```bash
git rev-list --objects --all |
  git cat-file --batch-check='%(objecttype) %(objectname) %(objectsize) %(rest)' |
  awk '$1=="blob" && $3 > 1000000 {print $3, $4}' | sort -rn
```

**Geçmişi yeniden yazmadan önce yedek alın** ve yedeği doğrulayana kadar
silmeyin:

```bash
git clone --mirror . ../kral-kafe-yedek.git
```

Geçen sefer `refs/original` ve reflog, işlem doğrulanmadan hemen silindi; geri
dönüş noktası kalmadı. `git filter-branch` artık git tarafından önerilmiyor,
`git-filter-repo` daha güvenli.

**Geçmiş yeniden yazıldıktan sonra eski remote'u derhal kaldırın.** Yukarıdaki
iki kazanın ikisi de bu adım atlanmasaydı imkânsız olurdu.

**Merge etmeden önce hedefi gözle doğrulayın:**

```bash
git log --oneline -1 <hedef>
```

## Kurtarma reçeteleri

**Yarım kalmış merge çalışma ağacını bozduysa** — çakışma işaretleri PHP
dosyalarına bulaşır ve testler sözdizimi hatası verir:

```bash
git status                    # MERGE_HEAD var mı, hangi commit'i gösteriyor
git merge --abort
git status --porcelain        # boş olmalı
php artisan test              # yeşile dönmeli
```

`merge --abort` takip edilmeyen ama sahnelenmiş dosyaları siler; yukarıdaki iki
araç dosyası bu şekilde iki kez silindi ve elle geri konuldu.

**Yerel depo beklenmedik şekilde şiştiyse:**

```bash
git count-objects -vH                            # size-pack'e bak
git for-each-ref refs/remotes                    # hangi ref eski geçmişi tutuyor
```

## Doğrulanmış mevcut durum

- Yerel `HEAD` ile `origin/main` eşit
- Uzaktaki geçmişte büyük dosya yok, çakışma işareti yok
- Hiçbir commit'te `.env` ya da API anahtarı bulunmuyor (tüm geçmiş tarandı)
- Yerel depo `brashlab` refleri nedeniyle hâlâ ~21 MiB; uzaktaki temiz
