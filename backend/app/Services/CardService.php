<?php

namespace App\Services;

use App\Models\Student;
use App\Models\StudentCard;
use App\Support\CardNumber;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * 证件生命周期：挂失与补证。
 *
 * 所有写操作都在 IMMEDIATE 事务内完成（见 config/database.php transaction_mode），
 * 与并发核验 / 并发挂失在服务端串行化；事务内重新读取行状态，
 * 不信任请求进入时的旧快照。
 */
class CardService
{
    /**
     * 挂失：旧证一经挂失立即不可用（终态 lost，不可逆）。
     * 幂等：重复挂失返回当前状态，不报错、不新建记录。
     */
    public function reportLost(StudentCard $card): StudentCard
    {
        return DB::transaction(function () use ($card) {
            /** @var StudentCard $fresh 事务内重新读取，拿到服务端当前状态 */
            $fresh = StudentCard::query()->whereKey($card->id)->firstOrFail();

            if ($fresh->status !== StudentCard::ACTIVE) {
                // 已是终态（lost / replaced），保持不变 —— 终态不可逆转
                return $fresh;
            }

            $fresh->update([
                'status' => StudentCard::LOST,
                'lost_at' => now(),
            ]);

            return $fresh;
        });
    }

    /**
     * 补证完成：原子地把旧证置为 replaced（永久失效）并签发新版证。
     * 旧证号永不复用；新证获得新证号与新的扫码 token。
     */
    public function issueReplacement(
        Student $student,
        StudentCard $oldCard,
        ?int $reviewedBy = null,
        ?int $applicationId = null,
    ): StudentCard {
        return DB::transaction(function () use ($student, $oldCard, $reviewedBy, $applicationId) {
            /** @var StudentCard $fresh */
            $fresh = StudentCard::query()->whereKey($oldCard->id)->firstOrFail();

            if ($fresh->student_id !== $student->id) {
                throw new RuntimeException('证件不属于该学生，不能据此补证。');
            }

            if ($fresh->status === StudentCard::REPLACED) {
                throw new RuntimeException('该证已补领新证，旧证号永久失效，不能重复补证。');
            }

            // 原子终结被新证取代的旧证：
            //   - 本次申请针对的失证（active 或 lost）→ replaced
            //   - 该学生名下其他仍 active 的证       → replaced
            // 无关的历史挂失证保持 lost，已 replaced 的历史证保持不动。
            StudentCard::query()
                ->where('student_id', $student->id)
                ->where('status', '!=', StudentCard::REPLACED)
                ->where(function ($query) use ($fresh) {
                    $query->whereKey($fresh->id)->orWhere('status', StudentCard::ACTIVE);
                })
                ->update([
                    'status' => StudentCard::REPLACED,
                    'lost_at' => DB::raw('COALESCE(lost_at, CURRENT_TIMESTAMP)'),
                    'updated_at' => now(),
                ]);

            $nextSequence = (int) StudentCard::query()
                ->where('student_id', $student->id)
                ->max('sequence') + 1;

            $newCard = StudentCard::create([
                'student_id' => $student->id,
                'card_no' => CardNumber::make($student->student_number, $nextSequence),
                'status' => StudentCard::ACTIVE,
                'scan_token' => CardNumber::newScanToken(),
                'sequence' => $nextSequence,
                'issued_at' => now(),
            ]);

            if ($applicationId !== null) {
                DB::table('replacement_applications')
                    ->where('id', $applicationId)
                    ->where('student_id', $student->id) // 只回写本人的申请
                    ->update([
                        'status' => 'completed',
                        'new_card_id' => $newCard->id,
                        'reviewed_by' => $reviewedBy,
                        'completed_at' => now(),
                        'updated_at' => now(),
                    ]);
            }

            return $newCard;
        });
    }
}
