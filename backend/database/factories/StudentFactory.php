<?php

namespace Database\Factories;

use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Student>
 */
class StudentFactory extends Factory
{
    protected $model = Student::class;

    public function definition(): array
    {
        return [
            'student_number' => '2023'.str_pad((string) fake()->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'name' => fake()->name(),
            'college' => fake()->randomElement(['计算机学院', '经济管理学院', '外国语学院', '机械工程学院']),
            'password' => 'password123',
            'enrollment_status' => Student::ENROLLED,
            'suspended_at' => null,
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn () => [
            'enrollment_status' => Student::SUSPENDED,
            'suspended_at' => now(),
        ]);
    }
}
