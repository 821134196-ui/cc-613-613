<?php

namespace Tests\Feature;

use App\Models\CardRequest;
use App\Models\Student;
use App\Models\StudentCard;
use App\Models\User;
use App\Services\CardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 关键安全规则四：学生只能查询/操作自己的证件与申请，不能跨学生访问补证资料。
 */
class CrossStudentAccessTest extends TestCase
{
    use RefreshDatabase;

    private function makeStudent(string $no, string $name): Student
    {
        return Student::create([
            'student_no' => $no,
            'name' => $name,
            'college' => '测试学院',
            'enrollment_status' => Student::STATUS_ACTIVE,
            'password' => 'secret',
        ]);
    }

    private function makeLostCardWithReissue(Student $student): array
    {
        $card = StudentCard::create([
            'student_id' => $student->id,
            'card_no' => $student->student_no,
            'version' => 1,
            'status' => StudentCard::STATUS_ACTIVE,
            'qr_token' => (string) Str::uuid(),
        ]);
        $service = app(CardService::class);
        $service->reportLoss($student, $card->fresh(), '他人的隐私原因');
        $reissue = $service->applyReissue($student, ['contact_phone' => '13911112222']);

        return [$card, $reissue];
    }

    public function test_student_cannot_view_another_students_card(): void
    {
        $a = $this->makeStudent('2023000001', '学生甲');
        $b = $this->makeStudent('2023000002', '学生乙');
        $bCard = StudentCard::create([
            'student_id' => $b->id,
            'card_no' => '2023000002',
            'version' => 1,
            'status' => StudentCard::STATUS_ACTIVE,
            'qr_token' => (string) Str::uuid(),
        ]);

        Sanctum::actingAs($a, ['student']);

        // 直接用乙的 card id 挂失 -> 按不存在处理，绝不允许操作
        $this->postJson("/api/student/cards/{$bCard->id}/loss")
            ->assertNotFound();

        // 证件列表里只应出现甲自己的（甲目前没有证件）
        $this->getJson('/api/student/cards')
            ->assertOk()
            ->assertJsonCount(0, 'cards');

        // 乙的证件状态必须未被改动
        $this->assertSame(StudentCard::STATUS_ACTIVE, $bCard->fresh()->status);
    }

    public function test_student_cannot_view_another_students_reissue_application(): void
    {
        $a = $this->makeStudent('2023000001', '学生甲');
        $b = $this->makeStudent('2023000002', '学生乙');
        [, $bReissue] = $this->makeLostCardWithReissue($b);

        Sanctum::actingAs($a, ['student']);

        // 枚举他人的申请 id -> 404，不泄露其补证资料/联系方式
        $res = $this->getJson("/api/student/requests/{$bReissue->id}");
        $res->assertNotFound();
        $this->assertStringNotContainsString('13911112222', $res->getContent());
        $this->assertStringNotContainsString('他人的隐私原因', $res->getContent());

        // 申请列表里也不含乙的申请
        $list = $this->getJson('/api/student/requests')->assertOk();
        $this->assertStringNotContainsString('13911112222', $list->getContent());
        $list->assertJsonCount(0, 'requests');
    }

    public function test_owner_can_view_own_request_but_others_cannot(): void
    {
        $a = $this->makeStudent('2023000001', '学生甲');
        [$aCard, $aReissue] = $this->makeLostCardWithReissue($a);
        $b = $this->makeStudent('2023000002', '学生乙');

        // 本人可见
        Sanctum::actingAs($a, ['student']);
        $this->getJson("/api/student/requests/{$aReissue->id}")
            ->assertOk()
            ->assertJsonPath('request.id', $aReissue->id);

        // 乙不可见
        Sanctum::actingAs($b, ['student']);
        $this->getJson("/api/student/requests/{$aReissue->id}")->assertNotFound();
    }

    public function test_student_token_cannot_access_admin_endpoints(): void
    {
        $a = $this->makeStudent('2023000001', '学生甲');
        Sanctum::actingAs($a, ['student']);

        $this->getJson('/api/admin/requests')->assertForbidden();
    }

    public function test_admin_token_can_list_requests_but_not_use_student_endpoints(): void
    {
        $admin = User::create(['name' => '管理员', 'email' => 'admin@x.edu', 'password' => 'secret']);
        Sanctum::actingAs($admin, ['admin']);

        $this->getJson('/api/admin/requests')->assertOk();

        // 教务令牌不能当学生身份用
        $this->getJson('/api/student/cards')->assertForbidden();
    }

    public function test_verify_response_never_contains_full_student_number(): void
    {
        $s = $this->makeStudent('2023000077', '王小七');
        $card = StudentCard::create([
            'student_id' => $s->id,
            'card_no' => '2023000077',
            'version' => 1,
            'status' => StudentCard::STATUS_ACTIVE,
            'qr_token' => (string) Str::uuid(),
        ]);

        $res = $this->postJson('/api/verify', ['qr_token' => $card->qr_token])->assertOk();

        $this->assertStringNotContainsString('2023000077', $res->getContent());
        $this->assertNotEmpty($res->json('identity.student_no_masked'));
        $this->assertStringContainsString('*', $res->json('identity.student_no_masked'));
    }
}
