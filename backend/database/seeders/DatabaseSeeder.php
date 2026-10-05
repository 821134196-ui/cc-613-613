<?php

namespace Database\Seeders;

use App\Models\CardRequest;
use App\Models\Student;
use App\Models\StudentCard;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 教务管理账号（窗口/教务端模拟）。
        User::updateOrCreate(
            ['email' => 'admin@school.edu.cn'],
            ['name' => '教务处管理员', 'password' => Hash::make('admin123')]
        );

        $students = [
            ['student_no' => '2023010101', 'name' => '张晓明', 'college' => '计算机学院', 'major' => '软件工程', 'status' => Student::STATUS_ACTIVE],
            ['student_no' => '2023010202', 'name' => '李思雨', 'college' => '经济管理学院', 'major' => '会计学', 'status' => Student::STATUS_SUSPENDED],
            ['student_no' => '2022030303', 'name' => '王浩然', 'college' => '机械工程学院', 'major' => '机械设计', 'status' => Student::STATUS_ACTIVE],
        ];

        foreach ($students as $s) {
            $student = Student::updateOrCreate(
                ['student_no' => $s['student_no']],
                [
                    'name' => $s['name'],
                    'college' => $s['college'],
                    'major' => $s['major'],
                    'enrollment_status' => $s['status'],
                    'password' => Hash::make('student123'),
                ]
            );

            // 每位学生先发一张初版有效学生证。
            if (!$student->cards()->exists()) {
                StudentCard::create([
                    'student_id' => $student->id,
                    'card_no' => $student->student_no,
                    'version' => 1,
                    'status' => StudentCard::STATUS_ACTIVE,
                    'qr_token' => (string) Str::uuid(),
                ]);
            }
        }

        // 第三位学生演示「已挂失旧证 + 补证办结 -> 新证」场景：
        $wang = Student::where('student_no', '2022030303')->first();
        $v1 = $wang->cards()->where('version', 1)->first();
        if ($v1 && $v1->isValid()) {
            // 挂失旧证
            $v1->update(['status' => StudentCard::STATUS_LOST, 'lost_at' => now()]);
            CardRequest::create([
                'student_id' => $wang->id,
                'card_id' => $v1->id,
                'type' => CardRequest::TYPE_REPORT_LOSS,
                'status' => CardRequest::STATUS_APPROVED,
                'reason' => '演示：钱包遗失',
                'handled_at' => now(),
            ]);

            // 补证办结：旧证 replaced，签发新证
            $newCard = StudentCard::create([
                'student_id' => $wang->id,
                'card_no' => sprintf('%s-R%02d', $wang->student_no, 2),
                'version' => 2,
                'status' => StudentCard::STATUS_ACTIVE,
                'qr_token' => (string) Str::uuid(),
            ]);
            $v1->update(['status' => StudentCard::STATUS_REPLACED, 'replaced_at' => now()]);

            CardRequest::create([
                'student_id' => $wang->id,
                'card_id' => $v1->id,
                'new_card_id' => $newCard->id,
                'type' => CardRequest::TYPE_REISSUE,
                'status' => CardRequest::STATUS_APPROVED,
                'reason' => '演示：补办新证',
                'handled_at' => now(),
            ]);
        }
    }
}
