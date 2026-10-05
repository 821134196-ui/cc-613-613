<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\StudentCard;
use App\Services\CardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 关键安全规则三：休学后恢复在读，只恢复「资格」，绝不恢复已挂失旧证。
 */
class ReenrollmentDoesNotReviveOldCardTest extends TestCase
{
    use RefreshDatabase;

    private function scenario(): array
    {
        $student = Student::create([
            'student_no' => '2023010202',
            'name' => '李思雨',
            'college' => '经济管理学院',
            'major' => '会计学',
            'enrollment_status' => Student::STATUS_ACTIVE,
            'password' => 'secret',
        ]);
        $card = StudentCard::create([
            'student_id' => $student->id,
            'card_no' => '2023010202',
            'version' => 1,
            'status' => StudentCard::STATUS_ACTIVE,
            'qr_token' => (string) Str::uuid(),
        ]);

        return [$student, $card];
    }

    public function test_suspended_student_is_denied_even_with_valid_card(): void
    {
        [$student, $card] = $this->scenario();
        $service = app(CardService::class);

        // 休学：证件实体仍是 active，但不在读
        $service->suspendEnrollment($student);
        $this->assertSame(StudentCard::STATUS_ACTIVE, $card->fresh()->status);

        $res = $this->postJson('/api/verify', ['qr_token' => $card->qr_token]);
        $res->assertOk()->assertJson([
            'allowed' => false,
            'checks' => ['card_valid' => true, 'enrolled' => false],
        ]);
        $this->assertSame('该学生当前处于休学状态，不具备在读优惠资格。', $res->json('reasons.0'));
    }

    public function test_reactivate_enrollment_restores_eligibility_but_not_lost_card(): void
    {
        [$student, $card] = $this->scenario();
        $service = app(CardService::class);

        // 先挂失旧证，再休学，再恢复在读
        $service->reportLoss($student, $card->fresh());
        $service->suspendEnrollment($student);
        $service->reactivateEnrollment($student);

        // 资格恢复在读
        $this->assertSame(Student::STATUS_ACTIVE, $student->fresh()->enrollment_status);
        // 旧证依旧挂失，没有被恢复
        $this->assertSame(StudentCard::STATUS_LOST, $card->fresh()->status);
        $this->assertNotNull($card->fresh()->lost_at);

        // 旧证核验仍然拒绝（即便学生已在读）
        $this->postJson('/api/verify', ['qr_token' => $card->qr_token])
            ->assertJson(['allowed' => false, 'checks' => ['card_valid' => false, 'enrolled' => true]]);

        // 学生当前没有任何有效证件
        $this->assertNull($student->fresh()->activeCard());
    }

    public function test_reactivate_after_reissue_keeps_old_card_replaced(): void
    {
        [$student, $oldCard] = $this->scenario();
        $service = app(CardService::class);

        // 挂失 -> 补证办结（旧证 replaced，新证 active）-> 休学 -> 恢复
        $service->reportLoss($student, $oldCard->fresh());
        $reissue = $service->applyReissue($student);
        $newCard = $service->approveReissue($reissue->fresh());

        $service->suspendEnrollment($student);
        $service->reactivateEnrollment($student);

        // 旧证永久 replaced，新证继续有效
        $this->assertSame(StudentCard::STATUS_REPLACED, $oldCard->fresh()->status);
        $this->assertSame(StudentCard::STATUS_ACTIVE, $newCard->fresh()->status);

        $this->postJson('/api/verify', ['qr_token' => $oldCard->qr_token])
            ->assertJson(['allowed' => false]);
        $this->postJson('/api/verify', ['qr_token' => $newCard->qr_token])
            ->assertJson(['allowed' => true]);
    }
}
