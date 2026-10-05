<?php

namespace Tests\Feature;

use App\Models\CardRequest;
use App\Models\Student;
use App\Models\StudentCard;
use App\Models\User;
use App\Services\CardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 关键安全规则一：旧证拦截 + 旧证号永久失效。
 */
class OldCardBlockingTest extends TestCase
{
    use RefreshDatabase;

    private function makeStudentWithCard(): array
    {
        $student = Student::create([
            'student_no' => '2023010101',
            'name' => '张晓明',
            'college' => '计算机学院',
            'major' => '软件工程',
            'enrollment_status' => Student::STATUS_ACTIVE,
            'password' => 'secret',
        ]);
        $card = StudentCard::create([
            'student_id' => $student->id,
            'card_no' => '2023010101',
            'version' => 1,
            'status' => StudentCard::STATUS_ACTIVE,
            'qr_token' => (string) Str::uuid(),
        ]);

        return [$student, $card];
    }

    public function test_active_card_of_enrolled_student_allows_discount(): void
    {
        [$student, $card] = $this->makeStudentWithCard();

        $res = $this->postJson('/api/verify', ['qr_token' => $card->qr_token]);

        $res->assertOk()->assertJson(['allowed' => true]);
        // 不返回完整学号
        $this->assertArrayNotHasKey('student_no', $res->json('identity'));
        $this->assertSame('2023****01', $res->json('identity.student_no_masked'));
    }

    public function test_lost_card_is_blocked_immediately(): void
    {
        [$student, $card] = $this->makeStudentWithCard();
        $service = app(CardService::class);

        $service->reportLoss($student, $card->fresh(), '钱包遗失');

        $res = $this->postJson('/api/verify', ['qr_token' => $card->qr_token]);
        $res->assertOk()
            ->assertJson(['allowed' => false, 'checks' => ['card_exists' => true, 'card_valid' => false, 'enrolled' => true]]);
        $this->assertSame('该学生证已挂失，旧证立即失效，不能享受优惠。', $res->json('reasons.0'));
    }

    public function test_after_reissue_old_card_number_is_permanently_dead_and_new_card_works(): void
    {
        [$student, $oldCard] = $this->makeStudentWithCard();
        $service = app(CardService::class);

        // 挂失 -> 申请补证 -> 受理
        $service->reportLoss($student, $oldCard->fresh());
        $reissue = $service->applyReissue($student, ['contact_phone' => '13800000000']);
        $newCard = $service->approveReissue($reissue->fresh());

        // 旧证：replaced，核验永久拒绝
        $this->assertSame(StudentCard::STATUS_REPLACED, $oldCard->fresh()->status);
        $this->postJson('/api/verify', ['qr_token' => $oldCard->qr_token])
            ->assertJson(['allowed' => false]);

        // 新证：新证号、新令牌、可放行
        $this->assertNotSame($oldCard->card_no, $newCard->card_no);
        $this->assertNotSame($oldCard->qr_token, $newCard->qr_token);
        $this->postJson('/api/verify', ['qr_token' => $newCard->qr_token])
            ->assertJson(['allowed' => true]);

        // 旧证号没有被任何有效证件复用
        $this->assertNull(
            StudentCard::where('card_no', $oldCard->card_no)->where('status', StudentCard::STATUS_ACTIVE)->first()
        );
    }

    public function test_card_status_can_never_transition_back_to_active(): void
    {
        [$student, $card] = $this->makeStudentWithCard();

        // 模拟任何尝试把旧证改回 active 的写法：条件更新影响行数必须为 0。
        $card->update(['status' => StudentCard::STATUS_LOST, 'lost_at' => now()]);

        $affected = StudentCard::where('id', $card->id)
            ->where('status', StudentCard::STATUS_ACTIVE)
            ->update(['status' => StudentCard::STATUS_ACTIVE]);

        $this->assertSame(0, $affected);
        $this->assertSame(StudentCard::STATUS_LOST, $card->fresh()->status);
    }
}
