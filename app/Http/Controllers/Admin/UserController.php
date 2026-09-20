<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\StudyGoal;
use App\Models\User;
use App\Support\LocalDay;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Support\SqlDialect;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;

class UserController extends Controller
{
    /**
     * Display a listing of users.
     */
    public function index(Request $request)
    {
        $query = User::query();

        // Filter by role
        if ($request->has('role') && $request->role !== 'all') {
            $query->where('role', $request->role);
        }

        // Filter by subscription status
        if ($request->has('status') && $request->status !== 'all') {
            $query->where('subscription_status', $request->status);
        }

        // Search
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $like = SqlDialect::likeOperator(DB::connection()->getDriverName());
            $query->where(function ($q) use ($search, $like) {
                $q->where('name', $like, "%{$search}%")
                    ->orWhere('email', $like, "%{$search}%");
            });
        }

        $users = $query->orderBy('name')->paginate(20);

        return view('admin.users.index', [
            'users' => $users,
        ]);
    }

    /**
     * Show the form for creating a new user.
     */
    public function create()
    {
        return view('admin.users.create');
    }

    /**
     * Store a newly created user.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'role' => ['required', Rule::enum(Role::class)],
            'phone' => ['nullable', 'string', 'max:20'],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'],
            'phone' => $validated['phone'] ?? null,
            'subscription_status' => 'active',
            'subscription_start' => now(),
        ]);

        return redirect()->route('admin.users.index')
            ->with('success', 'Kullanıcı başarıyla oluşturuldu.');
    }

    /**
     * Show the form for editing a user.
     */
    public function edit(User $user)
    {
        return view('admin.users.edit', [
            'user' => $user,
            'weeklyGoal' => StudyGoal::activeFor($user, LocalDay::today()),
        ]);
    }

    /**
     * Update the specified user.
     */
    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email,' . $user->id],
            'password' => ['nullable', 'confirmed', Rules\Password::defaults()],
            'role' => ['required', Rule::enum(Role::class)],
            'subscription_status' => ['required', 'in:active,inactive,suspended'],
            'phone' => ['nullable', 'string', 'max:20'],
            'weekly_goal_hours' => ['nullable', 'integer', 'min:1', 'max:120'],
        ]);

        $user->update([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'role' => $validated['role'],
            'subscription_status' => $validated['subscription_status'],
            'phone' => $validated['phone'] ?? null,
        ]);

        if (!empty($validated['password'])) {
            $user->update(['password' => Hash::make($validated['password'])]);
        }

        $this->syncWeeklyGoal($user, $validated['weekly_goal_hours'] ?? null);

        return redirect()->route('admin.users.index')
            ->with('success', 'Kullanıcı başarıyla güncellendi.');
    }

    /**
     * Remove the specified user.
     */
    public function destroy(User $user)
    {
        // Prevent deleting yourself
        if ($user->id === auth()->id()) {
            return back()->with('error', 'Kendinizi silemezsiniz.');
        }

        $user->delete();

        return redirect()->route('admin.users.index')
            ->with('success', 'Kullanıcı başarıyla silindi.');
    }

    /**
     * Haftalik hedefi gunceller.
     *
     * Mevcut hedefin UZERINE YAZMAZ: eskisini bugunden kapatip yenisini acar.
     * Uzerine yazmak gecmisi sessizce degistirirdi - gecen hafta 10 saatlik
     * hedefi tutturan ogrenci, hedef 25 saate cikinca "tutturamamis" gorunur.
     */
    private function syncWeeklyGoal(User $user, ?int $hours): void
    {
        if ($hours === null) {
            return;
        }

        $dakika = $hours * 60;
        $bugun = LocalDay::today();
        $mevcut = StudyGoal::activeFor($user, $bugun);

        if ($mevcut && $mevcut->target_minutes === $dakika) {
            return;
        }

        $mevcut?->supersedeOn($bugun);

        StudyGoal::create([
            'student_id' => $user->id,
            'period' => 'weekly',
            'target_minutes' => $dakika,
            'effective_from' => $bugun,
            'created_by' => auth()->id(),
        ]);
    }

    /**
     * Toggle user subscription status.
     */
    public function toggleStatus(User $user)
    {
        $newStatus = $user->subscription_status === 'active' ? 'suspended' : 'active';
        $user->update(['subscription_status' => $newStatus]);

        $statusText = $newStatus === 'active' ? 'aktifleştirildi' : 'askıya alındı';

        return back()->with('success', "Kullanıcı aboneliği {$statusText}.");
    }
}
