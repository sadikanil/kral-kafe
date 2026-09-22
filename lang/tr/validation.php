<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines contain the default error messages used by
    | the validator class. Some of these rules have multiple versions such
    | as the size rules. Feel free to tweak each of these messages here.
    |
    */

    'accepted' => ':attribute kabul edilmelidir.',
    'accepted_if' => ':other :value olduğunda :attribute kabul edilmelidir.',
    'active_url' => ':attribute geçerli bir URL olmalıdır.',
    'after' => ':attribute :date tarihinden sonra bir tarih olmalıdır.',
    'after_or_equal' => ':attribute :date tarihinden sonra veya bu tarihe eşit bir tarih olmalıdır.',
    'alpha' => ':attribute yalnızca harf içermelidir.',
    'alpha_dash' => ':attribute yalnızca harf, rakam, tire ve alt çizgi içermelidir.',
    'alpha_num' => ':attribute yalnızca harf ve rakam içermelidir.',
    'array' => ':attribute bir dizi olmalıdır.',
    'ascii' => ':attribute yalnızca tek baytlık alfanümerik karakterler ve semboller içermelidir.',
    'before' => ':attribute :date tarihinden önce bir tarih olmalıdır.',
    'before_or_equal' => ':attribute :date tarihinden önce veya bu tarihe eşit bir tarih olmalıdır.',
    'between' => [
        'array' => ':attribute :min ile :max arasında öğe içermelidir.',
        'file' => ':attribute :min ile :max kilobayt arasında olmalıdır.',
        'numeric' => ':attribute :min ile :max arasında olmalıdır.',
        'string' => ':attribute :min ile :max karakter arasında olmalıdır.',
    ],
    'boolean' => ':attribute alanı doğru veya yanlış olmalıdır.',
    'can' => ':attribute alanı yetkisiz bir değer içeriyor.',
    'confirmed' => ':attribute doğrulaması eşleşmiyor.',
    'contains' => ':attribute alanında gerekli bir değer eksik.',
    'current_password' => 'Parola yanlış.',
    'date' => ':attribute geçerli bir tarih olmalıdır.',
    'date_equals' => ':attribute :date tarihine eşit bir tarih olmalıdır.',
    'date_format' => ':attribute :format biçimiyle eşleşmiyor.',
    'decimal' => ':attribute :decimal ondalık basamağa sahip olmalıdır.',
    'declined' => ':attribute reddedilmelidir.',
    'declined_if' => ':other :value olduğunda :attribute reddedilmelidir.',
    'different' => ':attribute ve :other farklı olmalıdır.',
    'digits' => ':attribute :digits basamak olmalıdır.',
    'digits_between' => ':attribute :min ile :max basamak arasında olmalıdır.',
    'dimensions' => ':attribute geçersiz görüntü boyutlarına sahip.',
    'distinct' => ':attribute alanı yinelenen bir değere sahip.',
    'doesnt_end_with' => ':attribute şunlardan biriyle bitmemelidir: :values.',
    'doesnt_start_with' => ':attribute şunlardan biriyle başlamamalıdır: :values.',
    'email' => ':attribute geçerli bir e-posta adresi olmalıdır.',
    'ends_with' => ':attribute şunlardan biriyle bitmelidir: :values.',
    'enum' => 'Seçilen :attribute geçersiz.',
    'exists' => 'Seçilen :attribute geçersiz.',
    'extensions' => ':attribute alanı şu uzantılardan birine sahip bir dosya olmalıdır: :values.',
    'file' => ':attribute bir dosya olmalıdır.',
    'filled' => ':attribute alanının bir değeri olmalıdır.',
    'gt' => [
        'array' => ':attribute :value öğeden fazla içermelidir.',
        'file' => ':attribute :value kilobayttan büyük olmalıdır.',
        'numeric' => ':attribute :value değerinden büyük olmalıdır.',
        'string' => ':attribute :value karakterden uzun olmalıdır.',
    ],
    'gte' => [
        'array' => ':attribute :value veya daha fazla öğe içermelidir.',
        'file' => ':attribute :value kilobayt veya daha büyük olmalıdır.',
        'numeric' => ':attribute :value veya daha büyük olmalıdır.',
        'string' => ':attribute :value karakter veya daha uzun olmalıdır.',
    ],
    'hex_color' => ':attribute alanı geçerli bir onaltılık renk olmalıdır.',
    'image' => ':attribute bir görüntü olmalıdır.',
    'in' => 'Seçilen :attribute geçersiz.',
    'in_array' => ':attribute alanı :other içinde mevcut değil.',
    'integer' => ':attribute bir tam sayı olmalıdır.',
    'ip' => ':attribute geçerli bir IP adresi olmalıdır.',
    'ipv4' => ':attribute geçerli bir IPv4 adresi olmalıdır.',
    'ipv6' => ':attribute geçerli bir IPv6 adresi olmalıdır.',
    'json' => ':attribute geçerli bir JSON dizesi olmalıdır.',
    'list' => ':attribute alanı bir liste olmalıdır.',
    'lowercase' => ':attribute küçük harf olmalıdır.',
    'lt' => [
        'array' => ':attribute :value öğeden az içermelidir.',
        'file' => ':attribute :value kilobayttan küçük olmalıdır.',
        'numeric' => ':attribute :value değerinden küçük olmalıdır.',
        'string' => ':attribute :value karakterden kısa olmalıdır.',
    ],
    'lte' => [
        'array' => ':attribute :value öğeden fazla içermemelidir.',
        'file' => ':attribute :value kilobayt veya daha küçük olmalıdır.',
        'numeric' => ':attribute :value veya daha küçük olmalıdır.',
        'string' => ':attribute :value karakter veya daha kısa olmalıdır.',
    ],
    'mac_address' => ':attribute geçerli bir MAC adresi olmalıdır.',
    'max' => [
        'array' => ':attribute :max öğeden fazla içermemelidir.',
        'file' => ':attribute :max kilobayttan büyük olmamalıdır.',
        'numeric' => ':attribute :max değerinden büyük olmamalıdır.',
        'string' => ':attribute :max karakterden uzun olmamalıdır.',
    ],
    'max_digits' => ':attribute :max basamaktan fazla içermemelidir.',
    'mimes' => ':attribute şu türde bir dosya olmalıdır: :values.',
    'mimetypes' => ':attribute şu türde bir dosya olmalıdır: :values.',
    'min' => [
        'array' => ':attribute en az :min öğe içermelidir.',
        'file' => ':attribute en az :min kilobayt olmalıdır.',
        'numeric' => ':attribute en az :min olmalıdır.',
        'string' => ':attribute en az :min karakter olmalıdır.',
    ],
    'min_digits' => ':attribute en az :min basamak içermelidir.',
    'missing' => ':attribute alanı eksik olmalıdır.',
    'missing_if' => ':other :value olduğunda :attribute alanı eksik olmalıdır.',
    'missing_unless' => ':other :value olmadığı sürece :attribute alanı eksik olmalıdır.',
    'missing_with' => ':values mevcut olduğunda :attribute alanı eksik olmalıdır.',
    'missing_with_all' => ':values mevcut olduğunda :attribute alanı eksik olmalıdır.',
    'multiple_of' => ':attribute :value değerinin katı olmalıdır.',
    'not_in' => 'Seçilen :attribute geçersiz.',
    'not_regex' => ':attribute biçimi geçersiz.',
    'numeric' => ':attribute bir sayı olmalıdır.',
    'password' => [
        'letters' => ':attribute en az bir harf içermelidir.',
        'mixed' => ':attribute en az bir büyük ve bir küçük harf içermelidir.',
        'numbers' => ':attribute en az bir rakam içermelidir.',
        'symbols' => ':attribute en az bir sembol içermelidir.',
        'uncompromised' => 'Verilen :attribute bir veri ihlalinde görünmüştür. Lütfen farklı bir :attribute seçin.',
    ],
    'present' => ':attribute alanı mevcut olmalıdır.',
    'present_if' => ':other :value olduğunda :attribute alanı mevcut olmalıdır.',
    'present_unless' => ':other :value olmadığı sürece :attribute alanı mevcut olmalıdır.',
    'present_with' => ':values mevcut olduğunda :attribute alanı mevcut olmalıdır.',
    'present_with_all' => ':values mevcut olduğunda :attribute alanı mevcut olmalıdır.',
    'prohibited' => ':attribute alanı yasaklanmıştır.',
    'prohibited_if' => ':other :value olduğunda :attribute alanı yasaklanmıştır.',
    'prohibited_unless' => ':other :values içinde olmadığı sürece :attribute alanı yasaklanmıştır.',
    'prohibits' => ':attribute alanı :other varlığını yasaklar.',
    'regex' => ':attribute biçimi geçersiz.',
    'required' => ':attribute alanı gereklidir.',
    'required_array_keys' => ':attribute alanı şunlar için girişler içermelidir: :values.',
    'required_if' => ':other :value olduğunda :attribute alanı gereklidir.',
    'required_if_accepted' => ':other kabul edildiğinde :attribute alanı gereklidir.',
    'required_if_declined' => ':other reddedildiğinde :attribute alanı gereklidir.',
    'required_unless' => ':other :values içinde olmadığı sürece :attribute alanı gereklidir.',
    'required_with' => ':values mevcut olduğunda :attribute alanı gereklidir.',
    'required_with_all' => ':values mevcut olduğunda :attribute alanı gereklidir.',
    'required_without' => ':values mevcut olmadığında :attribute alanı gereklidir.',
    'required_without_all' => ':values hiçbiri mevcut olmadığında :attribute alanı gereklidir.',
    'same' => ':attribute ve :other eşleşmelidir.',
    'size' => [
        'array' => ':attribute :size öğe içermelidir.',
        'file' => ':attribute :size kilobayt olmalıdır.',
        'numeric' => ':attribute :size olmalıdır.',
        'string' => ':attribute :size karakter olmalıdır.',
    ],
    'starts_with' => ':attribute şunlardan biriyle başlamalıdır: :values.',
    'string' => ':attribute bir metin olmalıdır.',
    'timezone' => ':attribute geçerli bir saat dilimi olmalıdır.',
    'unique' => ':attribute zaten alınmış.',
    'uploaded' => ':attribute yüklenemedi.',
    'uppercase' => ':attribute büyük harf olmalıdır.',
    'url' => ':attribute geçerli bir URL olmalıdır.',
    'ulid' => ':attribute geçerli bir ULID olmalıdır.',
    'uuid' => ':attribute geçerli bir UUID olmalıdır.',

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | Here you may specify custom validation messages for attributes using the
    | convention "attribute.rule" to name the lines. This makes it quick to
    | specify a specific custom language line for a given attribute rule.
    |
    */

    'custom' => [
        'attribute-name' => [
            'rule-name' => 'custom-message',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Attributes
    |--------------------------------------------------------------------------
    |
    | The following language lines are used to swap our attribute placeholder
    | with something more reader friendly such as "E-Mail Address" instead
    | of "email". This simply helps us make our message a little cleaner.
    |
    */

    'attributes' => [
        'name' => 'ad',
        'reason' => 'red sebebi',
        'role' => 'rol',
        'weekly_goal_hours' => 'haftalık hedef',
        'product_id' => 'ürün',
        'monthly_price' => 'aylık fiyat',
        'weekly_mock_exams' => 'haftalık deneme sayısı',
        'items' => 'paket içeriği',
        'package_id' => 'paket',
        'starts_on' => 'başlangıç tarihi',
        'ends_on' => 'bitiş tarihi',
        'amount' => 'tutar',
        'paid_at' => 'ödeme tarihi',
        'method' => 'ödeme yöntemi',
        'pdf' => 'PDF dosyası',
        'exam_event_id' => 'deneme',
        'title' => 'başlık',
        'exam_type' => 'sınav türü',
        'exam_date' => 'sınav tarihi',
        'starts_at' => 'başlangıç saati',
        'note' => 'not',
        'student_ids' => 'bağlı öğrenciler',
        'student_ids.*' => 'öğrenci',
        'parent_ids' => 'veliler',
        'parent_ids.*' => 'veli',
        'subscription_status' => 'abonelik durumu',
        'email' => 'e-posta',
        'password' => 'şifre',
        'password_confirmation' => 'şifre onayı',
        'resolution_notes' => 'çözüm notu',
        'record_type' => 'sayım tipi',
        'verified_quantity' => 'sayılan adet',
        'photos' => 'fotoğraflar',
        'batch_id' => 'parti numarası',
        'category' => 'kategori',
        'unit_price' => 'birim fiyat',
        'unit_type' => 'birim tipi',
        'barcode' => 'barkod',
        'image' => 'görsel',
        'description' => 'açıklama',
        'phone' => 'telefon',
        'address' => 'adres',
        'quantity' => 'adet',
        'price' => 'fiyat',
        'is_active' => 'aktif durumu',
    ],

];
