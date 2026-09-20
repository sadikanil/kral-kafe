<?php

namespace App\Models;

use App\Enums\Role;
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
     * null: sinir yok (yonetici). Bos dizi: hicbiri. Koc/ogretmen/gorevli
     * icin henuz panel yok; panelleri geldigi dalgada burasi genisler, o
     * gune kadar hicbir ogrenciyi gormezler.
     *
     * @return list<int>|null
     */
    public function accessibleStudentIds(): ?array
    {
        return match ($this->role()) {
            Role::Admin => null,
            Role::Parent => $this->students()->pluck('users.id')->all(),
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
        return $this->subscriptions()->activeOn()->with('package')->orderByDesc('starts_on')->first();
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
