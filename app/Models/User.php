<?php

namespace App\Models;

use App\Enums\Role;
use App\Support\Entitlements;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'subscription_status',
        'subscription_start',
        'subscription_end',
        'phone',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'subscription_start' => 'date',
            'subscription_end' => 'date',
        ];
    }

    /**
     * Check if user is admin.
     */
    public function role(): ?Role
    {
        return Role::tryFrom((string) $this->role);
    }

    public function hasRole(Role ...$roles): bool
    {
        return in_array($this->role(), $roles, strict: true);
    }

    /**
     * Abonelik kontrolu bu kullaniciyi ilgilendiriyor mu?
     *
     * Bkz. Role::needsActiveSubscription() - yalnizca ogrenci icin gecerli.
     */
    public function needsActiveSubscription(): bool
    {
        return $this->role()?->needsActiveSubscription() ?? true;
    }

    /**
     * Giristen sonra bu kullanicinin indigi rota adi.
     *
     * Rolu taninmayan bir satir (elle duzenlenmis veri, silinmis bir rol)
     * kullaniciyi bosluga dusurmemeli; en dar yetkili panele iner.
     */
    /** Listelerde kimlik: once telefon (Dalga 18), yoksa e-posta. */
    public function contactLabel(): string
    {
        return $this->phone ? \App\Support\Telefon::format($this->phone) : (string) $this->email;
    }

    public function homeRoute(): string
    {
        return $this->role()?->homeRoute() ?? 'user.dashboard';
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * Check if user is student.
     */
    public function isStudent(): bool
    {
        return $this->role === 'student';
    }

    /**
     * Check if subscription is active.
     */
    public function hasActiveSubscription(): bool
    {
        return $this->subscription_status === 'active';
    }

    /**
     * Velinin bagli oldugu ogrenciler (student_parent).
     */
    public function students(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'student_parent', 'parent_id', 'student_id')
            ->using(StudentParent::class)
            ->withPivot('created_by')
            ->withTimestamps();
    }

    /**
     * Ogrencinin velileri (student_parent).
     */
    public function parents(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'student_parent', 'student_id', 'parent_id')
            ->using(StudentParent::class)
            ->withPivot('created_by')
            ->withTimestamps();
    }

    /**
     * Kocun izledigi ogrenciler (coach_assignments).
     */
    public function coachStudents(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'coach_assignments', 'coach_id', 'student_id')
            ->withPivot('created_by')
            ->withTimestamps();
    }

    /**
     * Ogrencinin koclari (coach_assignments).
     *
     * Coklu koc bilerek serbest: bir ogrencinin hem TYT hem AYT kocu olabilir.
     */
    public function coaches(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'coach_assignments', 'student_id', 'coach_id')
            ->withPivot('created_by')
            ->withTimestamps();
    }

    /**
     * Bu kullanicinin calisma verisini GOREBILDIGI ogrencilerin kimlikleri.
     *
     * Veli sinirinin tek kaynagi burasi. Tekil kayit (UserPolicy::viewStudy)
     * ve listeler (scopeVisibleTo) ikisi de buraya delege eder; iki yerde
     * ayri yazilirsa biri gunun birinde digerinden fazlasini gosterir.
     *
     * Global scope BILEREK kullanilmiyor: gorunmez bir scope yonetici
     * toplamlarina ve raporlara sizar, "kac ogrenci geldi" sorusu bakan
     * kisiye gore degisir.
     *
     * null: sinir yok (yonetici). Bos dizi: hicbiri. Koc Dalga 14'te
     * eklendi ve YALNIZCA kendine atanmis ogrencileri gorur; atamasi olmayan
     * bir koc bos dizi alir. Ogretmen/gorevli icin hala panel yok.
     *
     * @return list<int>|null
     */
    public function accessibleStudentIds(): ?array
    {
        return match ($this->role()) {
            Role::Admin => null,
            Role::Parent => $this->students()->pluck('users.id')->all(),
            Role::Coach => $this->coachStudents()->pluck('users.id')->all(),
            Role::Student => [$this->id],
            default => [],
        };
    }

    public function canViewStudent(User $student): bool
    {
        $ids = $this->accessibleStudentIds();

        return $ids === null || in_array($student->id, $ids, strict: true);
    }

    /**
     * Bu kullanici o ogrencinin KOCU gibi davranabilir mi?
     *
     * Plan yazmak, not birakmak ve gorusme kaydi girmek AYNI sinir; ucune
     * ayri isim vermek, gun gelip birinin digerinden gevsek kalmasi demekti.
     *
     * Gormek ile yazmak ayni sey DEGIL: veli cocugunun planini ve paylasilan
     * notlari gorur ama ikisini de koc/yonetici yazar. Bu yuzden once rol
     * kapisi, sonra ayni gorunurluk siniri - sinir yine
     * accessibleStudentIds()'den geliyor ki atama kalktiginda yazma yetkisi
     * de ayni anda kapansin.
     */
    public function canCoach(User $student): bool
    {
        if (! $this->hasRole(Role::Admin, Role::Coach)) {
            return false;
        }

        return $this->canViewStudent($student);
    }

    /**
     * Bakan kisinin gorebildigi ogrenciler. Acik scope: cagiran yerde
     * gorunur, gizli bir filtre degil.
     */
    public function scopeVisibleTo(Builder $query, User $viewer): Builder
    {
        $ids = $viewer->accessibleStudentIds();

        $query->where('role', Role::Student->value);

        return $ids === null ? $query : $query->whereIn('id', $ids);
    }

    /**
     * Paketlerden dogan haklar (Dalga 19). Istek boyunca bir kez hesaplanir.
     *
     * Ogrenci: bugun yururlukteki paketlerinin birlesimi (ana + ekler).
     * Veli: cocuklarinin birlesimi - ayri etiket tasimaz (karar, 23 Eyl).
     * Personel (yonetici, koc, ogretmen, gorevli): paketle SINIRLANMAZ; kime
     * erisecegini kendi kurallari belirler (ornegin koc yalnizca atanan
     * ogrencisinin raporunu acar).
     */
    public function entitlements(): Entitlements
    {
        return $this->entitlementsCache ??= match (true) {
            $this->isStudent() => Entitlements::fromPackages(
                Package::whereIn('id', $this->subscriptions()->activeOn()->select('package_id'))->get()
            ),
            $this->role() === Role::Parent => Entitlements::fromPackages(
                Package::whereIn('id', Subscription::activeOn()
                    ->whereIn('student_id', $this->students()->select('users.id'))
                    ->select('package_id'))->get()
            ),
            default => Entitlements::all(),
        };
    }

    private ?Entitlements $entitlementsCache = null;

    /** Dalga 25: haftalik ozel ders saatleri (Tier 3). */
    public function privateLessonSlots(): HasMany
    {
        return $this->hasMany(PrivateLessonSlot::class, 'student_id')->orderBy('weekday')->orderBy('starts_at');
    }

    /**
     * Ogrencinin paket gecmisi (Dalga 7).
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'student_id');
    }

    /**
     * Bugun yururlukteki abonelik (iptal haric); birden fazlaysa en yenisi.
     */
    public function currentSubscription(): ?Subscription
    {
        // Ekler (deneme kulubu eki gibi) "paketin" sayilmaz; haklar icin
        // entitlements() tum paketleri birlestirir.
        return $this->subscriptions()->activeOn()
            ->whereHas('package', fn ($q) => $q->where('is_addon', false))
            ->with('package')->orderByDesc('starts_on')->first();
    }

    /**
     * Get user's consumptions.
     */
    public function consumptions()
    {
        return $this->hasMany(Consumption::class);
    }

    /**
     * Get user's monthly bills.
     */
    public function monthlyBills()
    {
        return $this->hasMany(MonthlyBill::class);
    }

    /**
     * Get current month consumption total.
     */
    public function getCurrentMonthTotal(): float
    {
        return $this->consumptions()
            ->whereMonth('consumed_at', now()->month)
            ->whereYear('consumed_at', now()->year)
            ->where('is_undone', false)
            ->sum('total_price');
    }

    /**
     * Get current month item count.
     */
    public function getCurrentMonthItemCount(): int
    {
        return $this->consumptions()
            ->whereMonth('consumed_at', now()->month)
            ->whereYear('consumed_at', now()->year)
            ->where('is_undone', false)
            ->sum('quantity');
    }
}
