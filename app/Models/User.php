<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
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
