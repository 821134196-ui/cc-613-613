<?php

namespace Tests\Feature;

use App\Models\ReplacementApplication;
use App\Models\Student;
use App\Models\StudentCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 挂失 / 补证生命周期与“学生只能访问自己的证件及申请”。
 */
class CardLifecycleApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_report_own_card_lost_and_it_becomes_unusable(): void
    {
        $student = Student::factory()->create();
        $card = StudentCard::factory()->create(['student_id' => $student->id]);

        Sanctum::actingAs($student);

        $this->postJson("/api/student/cards/{$card->id}/lost")
            ->assertOk()
            ->assertJsonPath('data.status', StudentCard::LOST);

        $this->assertSame(StudentCard::LOST, $card->fresh()->status);
        $this->assertNotNull($card->fresh()->lost_at);
    }

    public function test_report_lost_is_idempotent(): void
    {
        $student = Student::factory()->create();
        $card = StudentCard::factory()->lost()->create(['student_id' => $student->id]);

        Sanctum::actingAs($student);

        $this->postJson("/api/student/cards/{$card->id}/lost")
            ->assertOk()
            ->assertJsonPath('data.status', StudentCard::LOST);
    }

    public function test_student_cannot_report_another_students_card_lost(): void
    {
        $me = Student::factory()->create();
        $other = Student::factory()->create();
        $othersCard = StudentCard::factory()->create(['student_id' => $other->id]);

        Sanctum::actingAs($me);

        $this->postJson("/api/student/cards/{$othersCard->id}/lost")
            ->assertForbidden();

        $this->assertSame(StudentCard::ACTIVE, $othersCard->fresh()->status);
    }

    public function test_cards_list_only_contains_own_cards(): void
    {
        $me = Student::factory()->create();
        $other = Student::factory()->create();
        StudentCard::factory()->create(['student_id' => $me->id, 'sequence' => 1]);
        StudentCard::factory()->replaced()->create(['student_id' => $me->id, 'sequence' => 2]);
        StudentCard::factory()->create(['student_id' => $other->id]);

        Sanctum::actingAs($me);

        $ids = $this->getJson('/api/student/cards')->assertOk()->json('data.*.id');

        $this->assertCount(2, $ids);
        foreach ($ids as $id) {
            $this->assertSame($me->id, StudentCard::find($id)->student_id);
        }
    }

    public function test_full_replacement_flow_old_number_permanently_dead(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $student = Student::factory()->create(['student_number' => '2023010100']);
        $oldCard = StudentCard::factory()->create([
            'student_id' => $student->id,
            'card_no' => '2023010100-01',
            'sequence' => 1,
            'status' => StudentCard::ACTIVE,
        ]);

        Sanctum::actingAs($student);

        // 学生提交补证申请（提交时证件未挂失 → 服务端先挂失）
        $this->postJson('/api/student/applications', [
            'lost_card_id' => $oldCard->id,
            'reason' => '公交车上遗失',
        ])->assertStatus(201);

        $application = ReplacementApplication::firstOrFail();
        $this->assertSame(StudentCard::LOST, $oldCard->fresh()->status);

        // 工作人员受理并完成补证
        Sanctum::actingAs($staff);
        $this->postJson("/api/staff/applications/{$application->id}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        // 旧证终态 replaced；新证 active 且证号递增、全局唯一
        $this->assertSame(StudentCard::REPLACED, $oldCard->fresh()->status);
        $newCard = StudentCard::where('student_id', $student->id)
            ->where('status', StudentCard::ACTIVE)->firstOrFail();
        $this->assertSame('2023010100-02', $newCard->card_no);
        $this->assertNotSame($oldCard->scan_token, $newCard->scan_token);
        $this->assertSame(1, StudentCard::where('status', StudentCard::ACTIVE)->where('student_id', $student->id)->count());

        // 旧证号仍唯一存在（没有被复用），且核验永久拒绝
        $this->assertSame(1, StudentCard::where('card_no', '2023010100-01')->count());

        $this->postJson('/api/verification/scan', ['scan_token' => $oldCard->scan_token])
            ->assertStatus(403)->assertJsonPath('reason_code', 'card_replaced');
        $this->postJson('/api/verification/scan', ['scan_token' => $newCard->scan_token])
            ->assertOk()->assertJsonPath('eligible', true);
    }

    public function test_completing_application_twice_does_not_issue_second_card(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $student = Student::factory()->create();
        $oldCard = StudentCard::factory()->lost()->create(['student_id' => $student->id]);
        $application = ReplacementApplication::create([
            'student_id' => $student->id,
            'lost_card_id' => $oldCard->id,
            'status' => ReplacementApplication::PENDING,
            'reason' => '遗失',
            'submitted_at' => now(),
        ]);

        Sanctum::actingAs($staff);

        $this->postJson("/api/staff/applications/{$application->id}/complete")->assertOk();
        $this->postJson("/api/staff/applications/{$application->id}/complete")
            ->assertStatus(422);

        $this->assertSame(1, StudentCard::where('student_id', $student->id)->where('status', StudentCard::ACTIVE)->count());
    }

    public function test_cannot_apply_replacement_for_replaced_card(): void
    {
        $student = Student::factory()->create();
        $replaced = StudentCard::factory()->replaced()->create(['student_id' => $student->id]);

        Sanctum::actingAs($student);

        $this->postJson('/api/student/applications', ['lost_card_id' => $replaced->id])
            ->assertStatus(422);
    }

    public function test_cannot_apply_with_another_students_card_id(): void
    {
        // 跨学生提交补证：他人 card id 在本人作用域下查不到 → 404
        $me = Student::factory()->create();
        $other = Student::factory()->create();
        $othersCard = StudentCard::factory()->lost()->create(['student_id' => $other->id]);

        Sanctum::actingAs($me);

        $this->postJson('/api/student/applications', ['lost_card_id' => $othersCard->id])
            ->assertNotFound();
        $this->assertSame(0, ReplacementApplication::where('student_id', $me->id)->count());
    }

    public function test_cannot_view_another_students_application(): void
    {
        $me = Student::factory()->create();
        $other = Student::factory()->create();
        $othersCard = StudentCard::factory()->lost()->create(['student_id' => $other->id]);
        $application = ReplacementApplication::create([
            'student_id' => $other->id,
            'lost_card_id' => $othersCard->id,
            'status' => ReplacementApplication::PENDING,
            'reason' => '遗失',
            'submitted_at' => now(),
        ]);

        Sanctum::actingAs($me);

        // 直接按 id 访问他人补证资料 → 403
        $this->getJson("/api/student/applications/{$application->id}")
            ->assertForbidden();
    }

    public function test_applications_index_only_contains_own(): void
    {
        $me = Student::factory()->create();
        $other = Student::factory()->create();

        foreach ([1, 2] as $seq) {
            $card = StudentCard::factory()->lost()->create(['student_id' => $me->id, 'sequence' => $seq]);
            ReplacementApplication::factory()->create(['student_id' => $me->id, 'lost_card_id' => $card->id]);
        }
        $otherCard = StudentCard::factory()->lost()->create(['student_id' => $other->id]);
        ReplacementApplication::factory()->create(['student_id' => $other->id, 'lost_card_id' => $otherCard->id]);

        Sanctum::actingAs($me);

        $list = $this->getJson('/api/student/applications')->assertOk()->json('data');
        $this->assertCount(2, $list);
    }

    public function test_duplicate_inflight_application_is_rejected(): void
    {
        $student = Student::factory()->create();
        $card = StudentCard::factory()->lost()->create(['student_id' => $student->id]);
        ReplacementApplication::create([
            'student_id' => $student->id,
            'lost_card_id' => $card->id,
            'status' => ReplacementApplication::PENDING,
            'reason' => '遗失',
            'submitted_at' => now(),
        ]);

        Sanctum::actingAs($student);

        $this->postJson('/api/student/applications', ['lost_card_id' => $card->id])
            ->assertStatus(409);
    }
}
