<?php

namespace Database\Factories;

use App\Models\ReplacementApplication;
use App\Models\Student;
use App\Models\StudentCard;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReplacementApplication>
 */
class ReplacementApplicationFactory extends Factory
{
    protected $model = ReplacementApplication::class;

    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'lost_card_id' => StudentCard::factory()->lost(),
            'new_card_id' => null,
            'status' => ReplacementApplication::PENDING,
            'reason' => '证件遗失',
            'submitted_at' => now(),
            'completed_at' => null,
            'rejected_at' => null,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => ReplacementApplication::COMPLETED,
            'completed_at' => now(),
        ]);
    }
}
