<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * 学生资格（学籍）实体。
 */
class Student extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    public const STATUS_ACTIVE = 'active';      // 在读
    public const STATUS_SUSPENDED = 'suspended'; // 休学
    public const STATUS_GRADUATED = 'graduated'; // 毕业

    protected $fillable = [
        'student_no',
        'name',
        'college',
        'major',
        'enrollment_status',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }

    public function cards(): HasMany
    {
        return $this->hasMany(StudentCard::class);
    }

    public function requests(): HasMany
    {
        return $this->hasMany(CardRequest::class);
    }

    public function isEnrolled(): bool
    {
        return $this->enrollment_status === self::STATUS_ACTIVE;
    }

    /** 当前有效证件（状态 active），正常情况下最多一张。 */
    public function activeCard(): ?StudentCard
    {
        return $this->cards()->where('status', StudentCard::STATUS_ACTIVE)->latest('version')->first();
    }
}
