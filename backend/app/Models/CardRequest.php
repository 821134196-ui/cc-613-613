<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 挂失 / 补证申请单。
 */
class CardRequest extends Model
{
    use HasFactory;

    public const TYPE_REPORT_LOSS = 'report_loss';
    public const TYPE_REISSUE = 'reissue';

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'student_id',
        'card_id',
        'new_card_id',
        'type',
        'status',
        'reason',
        'contact_phone',
        'reject_reason',
        'handled_at',
    ];

    protected $casts = [
        'handled_at' => 'datetime',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(StudentCard::class, 'card_id');
    }

    public function newCard(): BelongsTo
    {
        return $this->belongsTo(StudentCard::class, 'new_card_id');
    }
}
