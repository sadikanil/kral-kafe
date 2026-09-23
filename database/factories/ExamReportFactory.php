<?php

namespace Database\Factories;

use App\Models\ExamReport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ExamReport>
 */
class ExamReportFactory extends Factory
{
    protected $model = ExamReport::class;

    public function definition(): array
    {
        return [
            // Rapor yalnizca deneme kulubunde gorunur (Dalga 19)
            'student_id' => User::factory()->student()->withPackage(\App\Models\Package::factory()->tier3()),
            'exam_event_id' => null,
            'title' => 'Deneme ' . fake()->numberBetween(1, 20),
            'file_path' => 'deneme-raporlari/' . Str::uuid() . '.pdf',
            'uploaded_by' => null,
            'status' => ExamReport::PENDING,
            'analysis' => null,
        ];
    }

    public function analyzed(array $analysis = []): static
    {
        return $this->state(fn () => [
            'status' => ExamReport::DONE,
            'analyzed_at' => now(),
            'analysis' => $analysis ?: [
                'exam' => ['name' => 'Genel Deneme', 'type' => 'TYT', 'date' => null],
                'overall' => ['correct' => 80, 'wrong' => 20, 'blank' => 20, 'net' => 75.0, 'score' => null, 'rank' => null],
                'subjects' => [
                    ['name' => 'Türkçe', 'correct' => 30, 'wrong' => 5, 'blank' => 5, 'net' => 28.75],
                    ['name' => 'Matematik', 'correct' => 15, 'wrong' => 10, 'blank' => 15, 'net' => 12.5],
                ],
                'strong_areas' => [['subject' => 'Türkçe', 'topic' => 'Paragraf', 'evidence' => '18/18']],
                'weak_areas' => [['subject' => 'Matematik', 'topic' => 'Problemler', 'evidence' => '2/12']],
                'focus_suggestions' => ['Problemler konusunu tekrar et.'],
                'summary' => 'Türkçe güçlü, matematik problemleri zayıf.',
            ],
        ]);
    }
}
