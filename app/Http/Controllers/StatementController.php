<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\PaymentStatement;
use App\Support\ExamCalendar;
use App\Support\MonthParameter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Odemeler sayfasi (Dalga 22): ogrenci kendisininkini, veli cocugununkini
 * gorur. Salt okunur - odemeyi yalnizca yonetici yazar.
 */
class StatementController extends Controller
{
    public function student(Request $request): View
    {
        return $this->render($request, $request->user(), 'user.payments', []);
    }

    public function parent(Request $request, User $student): View
    {
        Gate::authorize('viewStudy', $student);
        abort_unless($student->isStudent(), 404);

        return $this->render($request, $student, 'parent.payments', ['student' => $student]);
    }

    private function render(Request $request, User $student, string $rota, array $rotaParametresi): View
    {
        [$yil, $ay] = MonthParameter::resolve($request->query('ay'));

        return view('payments.statement', [
            'student' => $student,
            'statement' => PaymentStatement::for($student, $yil, $ay),
            'monthLabel' => ExamCalendar::monthLabel($yil, $ay),
            'neighbours' => ExamCalendar::neighbours($yil, $ay),
            'route' => $rota,
            'routeParams' => $rotaParametresi,
        ]);
    }
}
