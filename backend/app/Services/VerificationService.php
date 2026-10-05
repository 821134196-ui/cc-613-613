<?php

namespace App\Services;

use App\Models\Student;
use App\Models\StudentCard;
use App\Support\CardNumber;
use Illuminate\Support\Facades\DB;

/**
 * 窗口扫码核验。
 *
 * 每次核验都在服务端读取“已提交的最新状态”并即时计算结论：
 * 窗口端本地页面的显示不作为放行依据（接口不接受客户端传入的资格结论，
 * 也不签发可长期缓存的凭证）。
 *
 * 并发正确性：
 *   - 核验是单条 JOIN 语句（autocommit 下的 DEFERRED 只读事务），
 *     WAL 模式下读取该语句开始时刻的最新已提交快照，读不阻塞写；
 *   - 挂失 / 补证使用 IMMEDIATE 写事务串行提交；
 *   - 因此“挂失与扫码同时发生”时以服务端提交顺序为准：挂失先提交则本次
 *     拒绝；核验语句先执行则其放行只代表核验那一刻的服务端状态，
 *     挂失提交后的下一次扫码必然拒绝 —— 旧页面显示不能作为放行依据。
 *
 * 两个维度必须同时满足才能享受优惠：
 *   1) 证件维度：该学生证为 active（未挂失、未被补证取代）
 *   2) 资格维度：该学生当前在读（enrolled）
 */
class VerificationService
{
    public function verifyByToken(string $scanToken): array
    {
        $row = DB::table('student_cards as c')
            ->join('students as s', 's.id', '=', 'c.student_id')
            ->where('c.scan_token', $scanToken)
            ->select($this->selectColumns())
            ->first();

        if (!$row) {
            return $this->deny('card_not_found', '未查到该学生证（二维码无效或证号不存在），不能享受优惠。');
        }

        return $this->evaluate($row);
    }

    /** 供后台 / 测试按证号核验（旧证号补证后再被出示的场景） */
    public function verifyByCardNo(string $cardNo): array
    {
        $row = DB::table('student_cards as c')
            ->join('students as s', 's.id', '=', 'c.student_id')
            ->where('c.card_no', $cardNo)
            ->select($this->selectColumns())
            ->first();

        if (!$row) {
            return $this->deny('card_not_found', '未查到该学生证（证号不存在），不能享受优惠。');
        }

        return $this->evaluate($row);
    }

    private function selectColumns(): array
    {
        return [
            'c.id as c_id', 'c.card_no as c_card_no', 'c.status as c_status',
            's.id as s_id', 's.student_number as s_student_number', 's.name as s_name',
            's.college as s_college', 's.enrollment_status as s_enrollment_status',
        ];
    }

    private function evaluate(object $row): array
    {
        // 1) 证件有效性优先判断 —— 旧证拦截不被学籍状态掩盖
        if ($row->c_status === StudentCard::LOST) {
            return $this->deny('card_lost', '该学生证已挂失，自挂失时起不可使用。', $row);
        }

        if ($row->c_status === StudentCard::REPLACED) {
            return $this->deny('card_replaced', '这是已失效的旧学生证，补证完成后旧证号永久失效，请使用新证。', $row);
        }

        // 2) 在读资格判断（资格与证件分离：休学只影响资格，不动证件）
        if ($row->s_enrollment_status !== Student::ENROLLED) {
            return $this->deny('not_enrolled', '该学生当前为休学状态，在读优惠资格中止，不能享受优惠。', $row);
        }

        return $this->allow($row);
    }

    private function allow(object $row): array
    {
        return [
            'eligible' => true,
            'reason_code' => 'ok',
            'reason' => '在读且学生证有效，可以享受优惠。',
            'checked_at' => now()->toDateTimeString(),
            'identity' => $this->identity($row),
        ];
    }

    private function deny(string $code, string $reason, ?object $row = null): array
    {
        return [
            'eligible' => false,
            'reason_code' => $code,
            'reason' => $reason,
            'checked_at' => now()->toDateTimeString(),
            'identity' => $row ? $this->identity($row) : null,
        ];
    }

    /**
     * 仅返回核验所需的最少身份信息：姓名、掩码学号、学院。
     * 完整学号绝不出现在任何窗口端响应里。
     */
    private function identity(object $row): array
    {
        return [
            'name' => $row->s_name,
            'student_number_masked' => CardNumber::maskStudentNumber($row->s_student_number),
            'college' => $row->s_college,
            'card_no_masked' => $this->maskCardNo($row->c_card_no),
            'card_status' => $row->c_status,
        ];
    }

    /** 证号同样只展示掩码：2023****01-01 */
    private function maskCardNo(string $cardNo): string
    {
        $parts = explode('-', $cardNo, 2);
        $masked = CardNumber::maskStudentNumber($parts[0]);

        return isset($parts[1]) ? $masked.'-'.$parts[1] : $masked;
    }
}
