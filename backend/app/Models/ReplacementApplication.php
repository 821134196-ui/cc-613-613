<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 补证申请与办理进度。仅申请人本人可见。
 */
class ReplacementApplication extends Model
{
    /** @use HasFactory<\Database\Factories\ReplacementApplicationFactory> */
    use HasFactory;

    public const PENDING = 'pending';
    public const PROCESSING = 'processing';
    public const COMPLETED = 'completed';
    public const REJECTED = 'rejected';

    protected $fillable = [
        'student_id',
        'lost_card_id',
        'new_card_id',
        'status',
        'reason',
        'reviewed_by',
        'submitted_at',
        'completed_at',
        'rejected_at',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'completed_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @return BelongsTo<StudentCard, $this> */
    public function lostCard(): BelongsTo
    {
        return $this->belongsTo(StudentCard::class, 'lost_card_id');
    }

    /** @return BelongsTo<StudentCard, $this> */
    public function newCard(): BelongsTo
    {
        return $this->belongsTo(StudentCard::class, 'new_card_id');
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::COMPLETED, self::REJECTED], true);
    }
}
