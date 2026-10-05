<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * 学生证实体（物理证件）。
 *
 * 状态机（不可逆，只能往失效方向迁移）：
 *   active  --挂失-->   lost
 *   active  --补证替代--> replaced
 *   lost/replaced 永不恢复 active（即使学籍恢复也不恢复旧证）。
 */
class StudentCard extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_LOST = 'lost';
    public const STATUS_REPLACED = 'replaced';

    public const FINAL_STATES = [self::STATUS_LOST, self::STATUS_REPLACED];

    protected $fillable = [
        'student_id',
        'card_no',
        'version',
        'status',
        'qr_token',
        'lost_at',
        'replaced_at',
    ];

    protected $casts = [
        'lost_at' => 'datetime',
        'replaced_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // 令牌只在创建时生成，避免 save 时被意外刷新。
        static::creating(function (StudentCard $card) {
            $card->qr_token ??= (string) Str::uuid();
        });
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function isValid(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /** 旧证失效是终态，任何流程都不允许将其改回。 */
    public function isPermanentlyInvalid(): bool
    {
        return in_array($this->status, self::FINAL_STATES, true);
    }
}
