<?php

namespace App\Policies;

use App\Models\User;

/**
 * Bir kullanicinin baska bir kullanicinin CALISMA verisine bakabilmesi.
 *
 * Tekil kayit icin policy, listeler icin User::scopeVisibleTo; ikisi de
 * User::accessibleStudentIds()'e delege eder. Kural burada tekrar YAZILMAZ.
 */
class UserPolicy
{
    public function viewStudy(User $viewer, User $student): bool
    {
        return $viewer->canViewStudent($student);
    }
}
