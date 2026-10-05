<?php

namespace App\Services;

use App\Models\CardRequest;
use App\Models\Student;
use App\Models\StudentCard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * 学生证核心业务。
 *
 * 设计要点：
 *  - 资格(Student) 与 证件(StudentCard) 分离；
 *  - 证件状态只允许 active -> lost/replaced，终态不可逆（恢复学籍也不会复活旧证）；
 *  - 所有状态迁移用「条件式 UPDATE ... WHERE status=...」完成，
 *    靠受影响行数判断是否抢到，SQLite 单写者保证该判定原子，
 *    因此挂失与核验并发时结果只取决于服务端提交顺序；
 *  - 多语句写入放在 DB 事务内。
 */
class CardService
{
    /**
     * 挂失：旧证一经挂失立即永久失效。
     * 幂等——对已挂失/已替代的证件重复挂失返回原申请单。
     */
    public function reportLoss(Student $student, StudentCard $card, ?string $reason = null): CardRequest
    {
        $this->assertOwns($student, $card);

        return DB::transaction(function () use ($student, $card, $reason) {
            // 锁行后重读最新状态（SQLite 下事务写锁即全库写锁，天然串行化）。
            $fresh = StudentCard::whereKey($card->id)->lockForUpdate()->firstOrFail();

            if ($fresh->isPermanentlyInvalid()) {
                // 已挂失/已替代：返回对应挂失单，保持幂等，不做任何状态回退。
                $existing = CardRequest::where('card_id', $fresh->id)
                    ->where('type', CardRequest::TYPE_REPORT_LOSS)
                    ->latest('id')->first();
                if ($existing) {
                    return $existing;
                }
                // replaced 证没有独立挂失单的情况，补一张留痕单。
                return CardRequest::create([
                    'student_id' => $student->id,
                    'card_id' => $fresh->id,
                    'type' => CardRequest::TYPE_REPORT_LOSS,
                    'status' => CardRequest::STATUS_APPROVED,
                    'reason' => $reason ?? '证件已失效（已被新证替代）',
                    'handled_at' => now(),
                ]);
            }

            // 原子条件更新：只有仍是 active 才会生效。affected=0 说明被并发抢先。
            $affected = StudentCard::where('id', $fresh->id)
                ->where('status', StudentCard::STATUS_ACTIVE)
                ->update([
                    'status' => StudentCard::STATUS_LOST,
                    'lost_at' => now(),
                    'updated_at' => now(),
                ]);

            if ($affected === 0) {
                // 极端并发下被其他事务抢先失效；递归一次走幂等分支。
                return $this->reportLoss($student, $fresh->refresh(), $reason);
            }

            return CardRequest::create([
                'student_id' => $student->id,
                'card_id' => $fresh->id,
                'type' => CardRequest::TYPE_REPORT_LOSS,
                // 挂失即时生效，申请单直接办结留痕。
                'status' => CardRequest::STATUS_APPROVED,
                'reason' => $reason,
                'handled_at' => now(),
            ]);
        });
    }

    /**
     * 提交补证申请（办理中）。必须存在已挂失的旧证且没有在途补证申请。
     */
    public function applyReissue(Student $student, array $payload = []): CardRequest
    {
        return DB::transaction(function () use ($student, $payload) {
            $student = Student::whereKey($student->id)->lockForUpdate()->firstOrFail();

            $lostCard = StudentCard::where('student_id', $student->id)
                ->where('status', StudentCard::STATUS_LOST)
                ->latest('id')
                ->first();

            if (!$lostCard) {
                throw new InvalidArgumentException('没有已挂失的证件，无需补证；如证件有效请先挂失。');
            }

            $inFlight = CardRequest::where('student_id', $student->id)
                ->where('type', CardRequest::TYPE_REISSUE)
                ->where('status', CardRequest::STATUS_PENDING)
                ->exists();

            if ($inFlight) {
                throw new InvalidArgumentException('已有办理中的补证申请，请勿重复提交。');
            }

            return CardRequest::create([
                'student_id' => $student->id,
                'card_id' => $lostCard->id,
                'type' => CardRequest::TYPE_REISSUE,
                'status' => CardRequest::STATUS_PENDING,
                'reason' => $payload['reason'] ?? null,
                'contact_phone' => $payload['contact_phone'] ?? null,
            ]);
        });
    }

    /**
     * 补证办结（模拟教务/窗口受理）：
     * 旧证 lost -> replaced（再次确认终态），签发新 active 证。
     * 旧证号永久失效，新证使用新证号与新二维码令牌。
     */
    public function approveReissue(CardRequest $request): StudentCard
    {
        if ($request->type !== CardRequest::TYPE_REISSUE) {
            throw new InvalidArgumentException('该申请不是补证申请。');
        }

        return DB::transaction(function () use ($request) {
            $request = CardRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();

            if ($request->status !== CardRequest::STATUS_PENDING) {
                throw new RuntimeException('该申请已办结，不能重复制证。');
            }

            $oldCard = StudentCard::whereKey($request->card_id)->lockForUpdate()->firstOrFail();

            // 任何在途旧证都必须是失效终态才允许制新证。
            if (!$oldCard->isPermanentlyInvalid()) {
                // 防御：理论上挂失单已保证，这里再以条件更新兜底。
                StudentCard::where('id', $oldCard->id)
                    ->where('status', StudentCard::STATUS_ACTIVE)
                    ->update(['status' => StudentCard::STATUS_LOST, 'lost_at' => now()]);
                $oldCard->refresh();
            }

            $student = Student::whereKey($request->student_id)->lockForUpdate()->firstOrFail();
            $nextVersion = (int) StudentCard::where('student_id', $student->id)->max('version') + 1;

            $newCard = StudentCard::create([
                'student_id' => $student->id,
                'card_no' => $this->generateCardNo($student, $nextVersion),
                'version' => $nextVersion,
                'status' => StudentCard::STATUS_ACTIVE,
                'qr_token' => (string) Str::uuid(),
            ]);

            // 旧证明确置为 replaced（lost -> replaced），旧证号永久失效。
            StudentCard::where('id', $oldCard->id)
                ->whereIn('status', StudentCard::FINAL_STATES)
                ->update(['status' => StudentCard::STATUS_REPLACED, 'replaced_at' => now()]);

            $request->update([
                'status' => CardRequest::STATUS_APPROVED,
                'new_card_id' => $newCard->id,
                'handled_at' => now(),
            ]);

            return $newCard;
        });
    }

    /** 驳回补证申请（旧证仍保持挂失失效，不会恢复）。 */
    public function rejectReissue(CardRequest $request, ?string $reason = null): void
    {
        DB::transaction(function () use ($request, $reason) {
            $request = CardRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($request->status !== CardRequest::STATUS_PENDING) {
                throw new RuntimeException('该申请已办结。');
            }
            $request->update([
                'status' => CardRequest::STATUS_REJECTED,
                'reject_reason' => $reason,
                'handled_at' => now(),
            ]);
            // 注意：驳回不触碰旧证状态——旧证依旧挂失失效，只能重新申请补证。
        });
    }

    /**
     * 学籍恢复：只恢复在读资格，绝不恢复任何已挂失/替代的旧证。
     */
    public function reactivateEnrollment(Student $student): Student
    {
        return DB::transaction(function () use ($student) {
            $fresh = Student::whereKey($student->id)->lockForUpdate()->firstOrFail();
            $fresh->update(['enrollment_status' => Student::STATUS_ACTIVE]);

            // 刻意不更新任何 student_cards 行——旧证终态由证件自身状态机守护。
            return $fresh;
        });
    }

    /** 休学：资格变为休学，证件实体不改动（核验时因不在读而拒绝优惠）。 */
    public function suspendEnrollment(Student $student): Student
    {
        return DB::transaction(function () use ($student) {
            $fresh = Student::whereKey($student->id)->lockForUpdate()->firstOrFail();
            $fresh->update(['enrollment_status' => Student::STATUS_SUSPENDED]);
            return $fresh;
        });
    }

    private function assertOwns(Student $student, StudentCard $card): void
    {
        if ((int) $card->student_id !== (int) $student->id) {
            throw new InvalidArgumentException('不能操作他人的学生证。');
        }
    }

    private function generateCardNo(Student $student, int $version): string
    {
        // 新证号与旧证号不同：学号 + 版本后缀，肉眼可辨且不再复用旧号。
        return sprintf('%s-R%02d', $student->student_no, $version);
    }
}
