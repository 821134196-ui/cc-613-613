<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\StudentCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 休学 / 恢复只作用于在读资格，绝不复活已挂失 / 已失效的旧证。
 */
class EnrollmentSeparationTest extends TestCase
{
    use RefreshDatabase;

    private function asStaff(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'staff']));
    }

    public function test_suspend_then_resume_restores_eligibility_but_not_lost_card(): void
    {
        $student = Student::factory()->create();
        $card = StudentCard::factory()->create(['student_id' => $student->id]);

        $this->asStaff();

        // 休学：资格中止
        $this->postJson("/api/staff/students/{$student->id}/suspend")->assertOk();
        $this->assertSame(Student::SUSPENDED, $student->fresh()->enrollment_status);
        $this->postJson('/api/verification/scan', ['scan_token' => $card->scan_token])
            ->assertStatus(403)->assertJsonPath('reason_code', 'not_enrolled');

        // 证件实体在休学期间未被改动
        $this->assertSame(StudentCard::ACTIVE, $card->fresh()->status);

        // 学生此时挂失
        Sanctum::actingAs($student);
        $this->postJson("/api/student/cards/{$card->id}/lost")->assertOk();

        // 恢复在读：只恢复资格
        $this->asStaff();
        $this->postJson("/api/staff/students/{$student->id}/resume")->assertOk();
        $this->assertSame(Student::ENROLLED, $student->fresh()->enrollment_status);

        // 已挂失的旧证仍然 lost，核验拒绝 —— 恢复学籍不能恢复旧证
        $this->assertSame(StudentCard::LOST, $card->fresh()->status);
        $this->postJson('/api/verification/scan', ['scan_token' => $card->scan_token])
            ->assertStatus(403)->assertJsonPath('reason_code', 'card_lost');
    }

    public function test_resume_does_not_revive_replaced_old_card(): void
    {
        $student = Student::factory()->suspended()->create();
        $old = StudentCard::factory()->replaced()->create(['student_id' => $student->id, 'sequence' => 1]);
        $new = StudentCard::factory()->create(['student_id' => $student->id, 'sequence' => 2]);

        $this->asStaff();
        $this->postJson("/api/staff/students/{$student->id}/resume")->assertOk();

        // 旧证依旧 replaced；新证可用
        $this->assertSame(StudentCard::REPLACED, $old->fresh()->status);
        $this->postJson('/api/verification/scan', ['scan_token' => $old->scan_token])
            ->assertStatus(403)->assertJsonPath('reason_code', 'card_replaced');
        $this->postJson('/api/verification/scan', ['scan_token' => $new->scan_token])
            ->assertOk();
    }

    public function test_resume_is_idempotent_and_touches_no_card_rows(): void
    {
        $student = Student::factory()->suspended()->create();
        $lost = StudentCard::factory()->lost()->create(['student_id' => $student->id]);

        $this->asStaff();

        $lostTs = $lost->fresh()->updated_at;
        $this->postJson("/api/staff/students/{$student->id}/resume")->assertOk();
        $this->postJson("/api/staff/students/{$student->id}/resume")->assertOk();

        // 证件行的 updated_at 不随学籍恢复而变化
        $this->assertEquals($lostTs, $lost->fresh()->updated_at);
        $this->assertSame(StudentCard::LOST, $lost->fresh()->status);
    }
}
