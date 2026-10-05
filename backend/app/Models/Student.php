<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * 学生 —— 优惠资格的载体（在读 / 休学）。
 * 资格状态与学生证（StudentCard）是两个独立实体，分别管理。
 */
class Student extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\StudentFactory> */
    use HasApiTokens, HasFactory;

    public const ENROLLED = 'enrolled';
    public const SUSPENDED = 'suspended';

    protected $fillable = [
        'student_number',
        'name',
        'college',
        'password',
        'enrollment_status',
        'suspended_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'suspended_at' => 'datetime',
        ];
    }

    /** @return HasMany<StudentCard, $this> */
    public function cards(): HasMany
    {
        return $this->hasMany(StudentCard::class);
    }

    /** @return HasMany<ReplacementApplication, $this> */
    public function replacementApplications(): HasMany
    {
        return $this->hasMany(ReplacementApplication::class);
    }

    public function isEnrolled(): bool
    {
        return $this->enrollment_status === self::ENROLLED;
    }

    public function activeCard(): ?StudentCard
    {
        return $this->cards()->where('status', StudentCard::ACTIVE)->latest('sequence')->first();
    }
}
