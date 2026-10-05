<?php

namespace Database\Factories;

use App\Models\Student;
use App\Models\StudentCard;
use App\Support\CardNumber;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudentCard>
 */
class StudentCardFactory extends Factory
{
    protected $model = StudentCard::class;

    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'card_no' => fn (array $attributes) => $this->makeCardNo($attributes),
            'status' => StudentCard::ACTIVE,
            'scan_token' => CardNumber::newScanToken(),
            'sequence' => 1,
            'issued_at' => now(),
            'lost_at' => null,
        ];
    }

    public function lost(): static
    {
        return $this->state(fn () => [
            'status' => StudentCard::LOST,
            'lost_at' => now(),
        ]);
    }

    public function replaced(): static
    {
        return $this->state(fn () => [
            'status' => StudentCard::REPLACED,
            'lost_at' => now(),
        ]);
    }

    private function makeCardNo(array $attributes): string
    {
        $student = Student::find($attributes['student_id'] ?? null);
        $number = $student?->student_number ?? '2023000000';

        return CardNumber::make($number, $attributes['sequence'] ?? 1);
    }
}
