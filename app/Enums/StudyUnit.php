<?php

namespace App\Enums;

/** Dalga 28: calisma kaydinin birimi. Sira ekrandaki sira. */
enum StudyUnit: string
{
    case Question = 'soru';
    case Page = 'sayfa';
    case Topic = 'konu';
    case Exam = 'deneme';

    public function label(): string
    {
        return $this->value;
    }
}
