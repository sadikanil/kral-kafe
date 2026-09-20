<?php

namespace App\Policies;

use App\Models\ExamReport;
use App\Models\User;

/**
 * Rapor, ogrencinin calisma verisidir: kim ogrenciyi gorebiliyorsa raporu
 * da gorur (User::canViewStudent -> accessibleStudentIds). Veli icin
 * "deneme sonuclarini gorsun mu" bayragi (can_view_exams) geldiginde
 * burada daraltilir; bugun veli rotasi yok.
 */
class ExamReportPolicy
{
    public function view(User $viewer, ExamReport $report): bool
    {
        return $viewer->canViewStudent($report->student);
    }
}
