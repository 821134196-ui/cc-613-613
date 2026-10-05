<?php

namespace Database\Seeders;

use App\Models\Student;
use App\Models\StudentCard;
use App\Models\User;
use App\Support\CardNumber;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 窗口工作人员（窗口端登录）
        User::query()->firstOrCreate(
            ['email' => 'staff@example.edu.cn'],
            ['name' => '窗口工作人员', 'password' => Hash::make('staff123'), 'role' => 'staff'],
        );

        // 学生 1：在读，持有有效证
        $s1 = Student::query()->firstOrCreate(
            ['student_number' => '2023010001'],
            ['name' => '张三', 'college' => '计算机学院', 'password' => Hash::make('student123'), 'enrollment_status' => Student::ENROLLED],
        );
        $this->ensureActiveCard($s1, 1);

        // 学生 2：在读，已挂失旧证并补到第 2 版（旧证号 2023010002-01 永久失效）
        $s2 = Student::query()->firstOrCreate(
            ['student_number' => '2023010002'],
            ['name' => '李四', 'college' => '经济管理学院', 'password' => Hash::make('student123'), 'enrollment_status' => Student::ENROLLED],
        );
        if (!$s2->cards()->exists()) {
            StudentCard::create([
                'student_id' => $s2->id,
                'card_no' => CardNumber::make($s2->student_number, 1),
                'status' => StudentCard::REPLACED,
                'scan_token' => CardNumber::newScanToken(),
                'sequence' => 1,
                'issued_at' => now()->subYear(),
                'lost_at' => now()->subMonth(),
            ]);
        }
        $this->ensureActiveCard($s2, 2);

        // 学生 3：休学中（资格中止，证件实体仍 active —— 恢复后资格恢复，证件不受影响）
        $s3 = Student::query()->firstOrCreate(
            ['student_number' => '2023010003'],
            ['name' => '王五', 'college' => '外国语学院', 'password' => Hash::make('student123'), 'enrollment_status' => Student::SUSPENDED, 'suspended_at' => now()->subDays(10)],
        );
        $this->ensureActiveCard($s3, 1);

        // 学生 4：挂失后办理补证前（证件 lost，无新证）
        $s4 = Student::query()->firstOrCreate(
            ['student_number' => '2023010004'],
            ['name' => '赵六', 'college' => '机械工程学院', 'password' => Hash::make('student123'), 'enrollment_status' => Student::ENROLLED],
        );
        if (!$s4->cards()->exists()) {
            StudentCard::create([
                'student_id' => $s4->id,
                'card_no' => CardNumber::make($s4->student_number, 1),
                'status' => StudentCard::LOST,
                'scan_token' => CardNumber::newScanToken(),
                'sequence' => 1,
                'issued_at' => now()->subMonths(6),
                'lost_at' => now()->subDays(3),
            ]);
        }
    }

    private function ensureActiveCard(Student $student, int $sequence): void
    {
        $exists = $student->cards()->where('sequence', $sequence)->exists();
        if ($exists) {
            return;
        }

        StudentCard::create([
            'student_id' => $student->id,
            'card_no' => CardNumber::make($student->student_number, $sequence),
            'status' => StudentCard::ACTIVE,
            'scan_token' => CardNumber::newScanToken(),
            'sequence' => $sequence,
            'issued_at' => now(),
        ]);
    }
}
