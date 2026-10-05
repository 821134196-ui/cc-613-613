<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 学生证 —— 证件实体。
 *
 * 状态机（全部为单向终态，不可回退）：
 *   active ──挂失──▶ lost ──补证完成──▶ replaced
 *   active └────────补证完成──────────▶ replaced
 *
 * 旧证一经挂失即刻不可用；补证完成后旧证号永久失效（replaced 终态），
 * 无论学籍如何变动（休学 / 恢复）都不会复活。
 */
class StudentCard extends Model
{
    /** @use HasFactory<\Database\Factories\StudentCardFactory> */
    use HasFactory;

    public const ACTIVE = 'active';
    public const LOST = 'lost';
    public const REPLACED = 'replaced';

    /** 终态集合，进入后不可逆转 */
    public const TERMINAL_STATUSES = [self::LOST, self::REPLACED];

    protected $fillable = [
        'student_id',
        'card_no',
        'status',
        'scan_token',
        'sequence',
        'issued_at',
        'lost_at',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'lost_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @return HasMany<ReplacementApplication, $this> */
    public function replacementApplications(): HasMany
    {
        return $this->hasMany(ReplacementApplication::class, 'lost_card_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    public function isUsable(): bool
    {
        // 证件维度有效：仅 active 可用。lost / replaced 均为终态，永不恢复。
        return $this->status === self::ACTIVE;
    }
}
