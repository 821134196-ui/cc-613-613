<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StudentCard;
use App\Support\CardNumber;
use Illuminate\Http\JsonResponse;

/**
 * 仅用于本地模拟扫码：列出每张证的扫码 token 供演示页面“模拟扫描枪”选取。
 * 生产环境中扫码 token 只存在于实体二维码里，不会有此列表接口。
 */
class ScanSimulationController extends Controller
{
    public function cards(): JsonResponse
    {
        $cards = StudentCard::query()
            ->with('student')
            ->orderBy('card_no')
            ->get()
            ->map(fn (StudentCard $c) => [
                'id' => $c->id,
                'scan_token' => $c->scan_token,
                'card_no_masked' => $this->mask($c->card_no),
                'card_status' => $c->status,
                'student_name' => $c->student->name,
                'student_number_masked' => CardNumber::maskStudentNumber($c->student->student_number),
            ]);

        return response()->json(['data' => $cards]);
    }

    private function mask(string $cardNo): string
    {
        [$base, $seq] = array_pad(explode('-', $cardNo, 2), 2, null);

        return CardNumber::maskStudentNumber($base).($seq ? '-'.$seq : '');
    }
}
