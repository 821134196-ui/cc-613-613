<?php

namespace App\Services;

use App\Models\Student;
use App\Models\StudentCard;
use Illuminate\Support\Str;

/**
 * 窗口扫码核验。
 *
 * 铁律：
 *  1. 每次核验都按 qr_token 实时查库，服务端当前状态是唯一依据，
 *     窗口本地页面显示的任何缓存都不能作为放行依据；
 *  2. 必须同时满足「证件实体有效」与「学籍在读」两个条件，缺一不可；
 *  3. 旧证（挂失 lost / 已被替代 replaced）一律拒绝；
 *  4. 响应只给核验所需的最小身份信息，不返回完整学号。
 */
class VerificationService
{
    /**
     * @return array<string, mixed>
     */
    public function verifyByToken(string $qrToken): array
    {
        /** @var StudentCard|null $card */
        $card = StudentCard::with('student')->where('qr_token', $qrToken)->first();

        if (!$card) {
            return [
                'allowed' => false,
                'checked_at' => now()->toDateTimeString(),
                'identity' => null,
                'reasons' => ['未查询到该证件，二维码无效或不是本校学生证。'],
                'checks' => [
                    'card_exists' => false,
                    'card_valid' => false,
                    'enrolled' => false,
                ],
            ];
        }

        $student = $card->student;
        $reasons = [];
        $cardValid = $card->isValid();
        $enrolled = $student !== null && $student->isEnrolled();

        if (!$cardValid) {
            $reasons[] = match ($card->status) {
                StudentCard::STATUS_LOST => '该学生证已挂失，旧证立即失效，不能享受优惠。',
                StudentCard::STATUS_REPLACED => '该学生证已被补办的新证替代，旧证号永久失效，不能享受优惠。',
                default => '该学生证当前不是有效证件。',
            };
        }

        if ($student === null) {
            $reasons[] = '未找到对应的学生学籍信息。';
        } elseif (!$enrolled) {
            $reasons[] = match ($student->enrollment_status) {
                Student::STATUS_SUSPENDED => '该学生当前处于休学状态，不具备在读优惠资格。',
                Student::STATUS_GRADUATED => '该学生已毕业，不再享受学生优惠。',
                default => '该学生当前不具备在读资格。',
            };
        }

        if ($cardValid && $enrolled) {
            $reasons[] = '证件有效且学生在读，可以享受学生优惠。';
        }

        return [
            'allowed' => $cardValid && $enrolled,
            'checked_at' => now()->toDateTimeString(),
            // 最小化身份信息：姓名、院系、掩码学号、证件版本，不含完整学号/手机号等。
            'identity' => [
                'name' => $student?->name,
                'college' => $student?->college,
                'major' => $student?->major,
                'student_no_masked' => $this->maskStudentNo($student?->student_no),
                'card_no_masked' => $this->maskCardNo($card->card_no),
                'card_version' => $card->version,
            ],
            'reasons' => $reasons,
            'checks' => [
                'card_exists' => true,
                'card_valid' => $cardValid,
                'enrolled' => $enrolled,
            ],
        ];
    }

    /** 学号脱敏：保留首 4 位与末 2 位，其余星号。 */
    public function maskStudentNo(?string $no): ?string
    {
        if ($no === null) {
            return null;
        }
        $len = Str::length($no);
        if ($len <= 6) {
            return Str::substr($no, 0, 2) . str_repeat('*', max($len - 3, 1)) . Str::substr($no, -1);
        }
        return Str::substr($no, 0, 4) . str_repeat('*', $len - 6) . Str::substr($no, -2);
    }

    public function maskCardNo(string $no): string
    {
        $len = Str::length($no);
        if ($len <= 6) {
            return str_repeat('*', max($len - 2, 1)) . Str::substr($no, -2);
        }
        return Str::substr($no, 0, 4) . str_repeat('*', $len - 6) . Str::substr($no, -2);
    }
}
