<?php

namespace App\Support;

class CardNumber
{
    /**
     * 证号规则：学号 + 版次，如 2023010001-02。
     * 版次递增，旧证号永不复用（DB 唯一索引兜底）。
     */
    public static function make(string $studentNumber, int $sequence): string
    {
        return sprintf('%s-%02d', $studentNumber, $sequence);
    }

    /**
     * 窗口端展示的掩码学号：保留前 4 位与后 2 位，如 2023****01。
     * 核验响应中绝不返回完整学号。
     */
    public static function maskStudentNumber(string $studentNumber): string
    {
        $len = mb_strlen($studentNumber);
        if ($len <= 6) {
            return str_repeat('*', $len);
        }

        return mb_substr($studentNumber, 0, 4).'****'.mb_substr($studentNumber, -2);
    }

    /** 二维码扫码凭证：不可猜测的随机 token，与证号解耦 */
    public static function newScanToken(): string
    {
        return 'tok_'.bin2hex(random_bytes(24));
    }
}
