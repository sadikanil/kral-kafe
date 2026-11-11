<?php

namespace Database\Seeders;

use App\Enums\ApprovalStatus;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Enums\SessionEndReason;
use App\Models\Consumption;
use App\Models\ExamEvent;
use App\Models\ExamResult;
use App\Models\ExamResultSubject;
use App\Models\Location;
use App\Models\Package;
use App\Models\Product;
use App\Models\StudentParent;
use App\Models\StudySession;
use App\Models\StudyTable;
use App\Models\Subject;
use App\Models\Subscription;
use App\Models\User;
use App\Support\LocalDay;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Onizleme veritabaninin demo verisi (9 Kasim 2026). YALNIZCA bos onizleme
 * veritabaninda, kafe:onizleme-hazirla ile calisir; canliya asla.
 *
 * Kisiler SAHTE ve SIFRESIZ: ilk giriste herkes kendi sifresini belirler
 * (giris akisinin "belirle" adimi). Boylece hicbir sifre koda, belgeye ya da
 * sohbete yazilmaz. Faker kullanilmaz: derleme --no-dev ile kuruluyor.
 *
 * Girisler: yonetici@onizleme.test (e-posta), gorevli 0555 000 00 10,
 * veli 0555 000 00 20, uyeler 0555 000 01 01 ... 0555 000 01 08.
 */
class OnizlemeSeeder extends Seeder
{
    private const UYELER = [
        'Ayşe Kaya', 'Burak Demir', 'Cem Yılmaz', 'Deniz Akın',
        'Elif Şahin', 'Furkan Öz', 'Gizem Arslan', 'Hakan Çelik',
    ];

    public function run(): void
    {
        $bugun = Carbon::parse(LocalDay::today(), LocalDay::timezone());

        User::create(['name' => 'Önizleme Yönetici', 'email' => 'yonetici@onizleme.test', 'role' => Role::Admin->value, 'subscription_status' => 'active']);
        User::create(['name' => 'Görevli Gökay', 'phone' => '5550000010', 'role' => Role::Staff->value, 'subscription_status' => 'active']);
        $veli = User::create(['name' => 'Veli Vildan', 'phone' => '5550000020', 'role' => Role::Parent->value, 'subscription_status' => 'active']);

        $paket = Package::create([
            'name' => 'Kral (önizleme)', 'tier' => 3, 'monthly_price' => 7500,
            'has_reserved_table' => true, 'includes_coaching' => true, 'includes_exam_club' => true,
            'includes_private_lessons' => true, 'is_addon' => false, 'weekly_mock_exams' => 1,
            'description' => 'Önizleme verisi', 'is_active' => true,
        ]);

        $uyeler = collect(self::UYELER)->values()->map(function (string $ad, int $i) use ($paket, $bugun) {
            $uye = User::create([
                'name' => $ad,
                'phone' => '55500001' . str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT),
                'role' => Role::Student->value,
                'subscription_status' => 'active',
            ]);

            Subscription::create([
                'student_id' => $uye->id, 'package_id' => $paket->id,
                'starts_on' => $bugun->copy()->startOfMonth()->toDateString(),
                'ends_on' => $bugun->copy()->startOfMonth()->addMonthNoOverflow()->subDay()->toDateString(),
                'price' => 7500,
                'payment_status' => ($i % 3 === 0 ? PaymentStatus::Pending : PaymentStatus::Paid)->value,
            ]);

            return $uye;
        });

        StudentParent::create(['student_id' => $uyeler[0]->id, 'parent_id' => $veli->id]);

        $masalar = collect(range(1, 8))->map(fn (int $n) => StudyTable::create([
            'name' => "Masa {$n}",
            'area' => $n <= 6 ? 'Salon' : 'Sessiz oda',
            'assigned_student_id' => $n === 8 ? $uyeler[0]->id : null,
        ]));

        $raf = Location::create(['name' => 'Ön raf', 'type' => 'shelf', 'qr_code' => 'ONIZLEME-RAF', 'is_active' => true]);
        $urunler = collect([
            ['Çay', '🍵', 15], ['Filtre Kahve', '☕', 40], ['Tost', '🥪', 90], ['Su', '💧', 10], ['Kek', '🍰', 50],
        ])->map(fn ($u) => Product::create([
            'name' => $u[0], 'emoji' => $u[1], 'unit_price' => $u[2], 'unit_type' => 'adet', 'is_active' => true,
            'location_id' => $raf->id, 'stock_quantity' => 40, 'critical_quantity' => 5,
        ]));

        // Calisma: bu hafta ve gecen hafta, uyeden uyeye farkli (liderlik dolu gorunsun).
        foreach ($uyeler as $i => $uye) {
            foreach ([1, 2, 3, 6, 8, 9] as $j => $gunOnce) {
                $dakika = 240 - $i * 25 + ($j % 2) * 30;
                if ($dakika < 40) {
                    continue;
                }
                $bas = $bugun->copy()->subDays($gunOnce)->setTime(9 + ($i % 3), 0)->utc();
                StudySession::create([
                    'student_id' => $uye->id, 'study_table_id' => $masalar[$i % 6]->id,
                    'started_at' => $bas, 'ended_at' => $bas->copy()->addMinutes($dakika),
                    'duration_minutes' => $dakika, 'end_reason' => SessionEndReason::Manual->value,
                    'approval_status' => ApprovalStatus::Approved->value,
                ]);
            }
        }

        // Haftanin hangi gunu derlenirse derlensin bu haftada da veri olsun
        // (pazartesi yukaridaki gunlerin hepsi gecen haftaya duser): her uyeye
        // az once biten bir oturum.
        foreach ($uyeler as $i => $uye) {
            $dakika = 150 - $i * 15;
            $bitis = now()->subMinutes(20 + $i * 5);
            StudySession::create([
                'student_id' => $uye->id, 'study_table_id' => $masalar[$i % 6]->id,
                'started_at' => $bitis->copy()->subMinutes($dakika), 'ended_at' => $bitis,
                'duration_minutes' => $dakika, 'end_reason' => SessionEndReason::Manual->value,
                'approval_status' => ApprovalStatus::Approved->value,
            ]);
        }

        // Canli ekran: biri iceride, biri onay bekliyor, biri sure asimi (gorulmemis).
        StudySession::create(['student_id' => $uyeler[1]->id, 'study_table_id' => $masalar[1]->id, 'started_at' => now()->subMinutes(95)]);
        $dun = $bugun->copy()->subDay()->setTime(15, 0)->utc();
        StudySession::create([
            'student_id' => $uyeler[2]->id, 'study_table_id' => $masalar[2]->id,
            'started_at' => $dun, 'ended_at' => $dun->copy()->addMinutes(80), 'duration_minutes' => 80,
            'end_reason' => SessionEndReason::Manual->value, 'approval_status' => ApprovalStatus::Pending->value,
        ]);
        $uzun = $bugun->copy()->subDays(2)->setTime(8, 0)->utc();
        StudySession::create([
            'student_id' => $uyeler[5]->id, 'study_table_id' => $masalar[5]->id,
            'started_at' => $uzun, 'ended_at' => $uzun->copy()->addHours(12), 'duration_minutes' => 720,
            'end_reason' => SessionEndReason::OverLimit->value, 'approval_status' => ApprovalStatus::Pending->value,
        ]);

        // Adisyon: birkac siparis (bu hafta).
        $self = Location::selfService();
        foreach ($uyeler->take(6) as $i => $uye) {
            foreach ([0, 2] as $k) {
                $urun = $urunler[($i + $k) % $urunler->count()];
                Consumption::create([
                    'user_id' => $uye->id, 'product_id' => $urun->id, 'location_id' => $self->id,
                    'quantity' => 1 + ($k > 0 ? 1 : 0), 'covered_quantity' => 0, 'unit_price' => $urun->unit_price,
                    'consumed_at' => $bugun->copy()->subDays($k + 1)->setTime(12, 30)->utc(),
                ]);
            }
        }

        // Deneme: gecen hafta ve bu hafta TYT; ikinci denemede gelisim farkli.
        $dersler = Subject::where('exam_type', 'tyt')->orderBy('sort_order')->take(4)->get();
        if ($dersler->isEmpty()) {
            return;
        }

        foreach ([[10, 'Önizleme TYT 1'], [1, 'Önizleme TYT 2']] as $d => [$gunOnce, $baslik]) {
            $deneme = ExamEvent::create([
                'title' => $baslik, 'exam_type' => 'tyt',
                'exam_date' => $bugun->copy()->subDays($gunOnce)->toDateString(), 'starts_at' => '10:00',
            ]);

            foreach ($uyeler->take(6) as $i => $uye) {
                $sonuc = ExamResult::create(['exam_event_id' => $deneme->id, 'student_id' => $uye->id]);
                foreach ($dersler as $s => $ders) {
                    $dogru = max(5, 30 - $i * 3 + ($d === 1 ? ($i % 2 ? 4 : -2) : 0) - $s);
                    ExamResultSubject::create([
                        'exam_result_id' => $sonuc->id, 'subject_id' => $ders->id,
                        'correct' => $dogru, 'wrong' => 3 + $s, 'blank' => max(0, 40 - $dogru - 3 - $s),
                    ]);
                }
            }
        }
    }
}
