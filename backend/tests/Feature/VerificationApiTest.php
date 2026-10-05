<?php

namespace Tests\Feature;

use App\Models\ReplacementApplication;
use App\Models\Student;
use App\Models\StudentCard;
use App\Models\User;
use App\Support\CardNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 窗口扫码核验：在读资格 + 证件有效性双条件，
 * 且窗口端只暴露掩码身份信息。
 */
class VerificationApiTest extends TestCase
{
    use RefreshDatabase;

    private function staffToken(): User
    {
        return User::factory()->create(['role' => 'staff']);
    }

    public function test_active_card_of_enrolled_student_is_eligible(): void
    {
        $student = Student::factory()->create(['student_number' => '2023010001']);
        $card = StudentCard::factory()->create([
            'student_id' => $student->id,
            'card_no' => '2023010001-01',
            'status' => StudentCard::ACTIVE,
        ]);

        Sanctum::actingAs($this->staffToken());

        $response = $this->postJson('/api/verification/scan', ['scan_token' => $card->scan_token]);

        $response->assertOk()
            ->assertJsonPath('eligible', true)
            ->assertJsonPath('reason_code', 'ok')
            ->assertJsonPath('identity.student_number_masked', '2023****01')
            ->assertJsonPath('identity.name', $student->name)
            ->assertJsonPath('identity.card_status', 'active');
    }

    public function test_scan_response_never_contains_full_student_number(): void
    {
        $student = Student::factory()->create(['student_number' => '2023010777']);
        $card = StudentCard::factory()->create([
            'student_id' => $student->id,
            'card_no' => '2023010777-01',
        ]);

        Sanctum::actingAs($this->staffToken());

        $response = $this->postJson('/api/verification/scan', ['scan_token' => $card->scan_token]);

        $body = $response->getContent();
        $this->assertStringNotContainsString('2023010777', $body, '窗口核验响应中不得出现完整学号');
        $this->assertStringContainsString('2023****77', $body);
    }

    public function test_lost_card_is_denied_immediately_after_report(): void
    {
        $student = Student::factory()->create();
        $card = StudentCard::factory()->lost()->create(['student_id' => $student->id]);

        Sanctum::actingAs($this->staffToken());

        $this->postJson('/api/verification/scan', ['scan_token' => $card->scan_token])
            ->assertStatus(403)
            ->assertJsonPath('eligible', false)
            ->assertJsonPath('reason_code', 'card_lost');
    }

    public function test_replaced_old_card_is_denied_forever(): void
    {
        // 旧证补证后再被出示：永久拒绝
        $student = Student::factory()->create();
        $old = StudentCard::factory()->replaced()->create(['student_id' => $student->id, 'sequence' => 1]);
        $new = StudentCard::factory()->create(['student_id' => $student->id, 'sequence' => 2]);

        Sanctum::actingAs($this->staffToken());

        $this->postJson('/api/verification/scan', ['scan_token' => $old->scan_token])
            ->assertStatus(403)
            ->assertJsonPath('reason_code', 'card_replaced');

        $this->postJson('/api/verification/scan', ['scan_token' => $new->scan_token])
            ->assertOk()
            ->assertJsonPath('eligible', true);
    }

    public function test_suspended_student_is_denied_even_with_active_card(): void
    {
        // 资格与证件分离：休学只中止资格，不动证件
        $student = Student::factory()->suspended()->create();
        $card = StudentCard::factory()->create([
            'student_id' => $student->id,
            'status' => StudentCard::ACTIVE,
        ]);

        Sanctum::actingAs($this->staffToken());

        $this->postJson('/api/verification/scan', ['scan_token' => $card->scan_token])
            ->assertStatus(403)
            ->assertJsonPath('reason_code', 'not_enrolled')
            ->assertJsonPath('identity.card_status', 'active');
    }

    public function test_unknown_token_is_denied(): void
    {
        Sanctum::actingAs($this->staffToken());

        $this->postJson('/api/verification/scan', ['scan_token' => CardNumber::newScanToken()])
            ->assertStatus(403)
            ->assertJsonPath('reason_code', 'card_not_found');
    }

    public function test_student_token_cannot_use_window_scan(): void
    {
        $student = Student::factory()->create();
        $card = StudentCard::factory()->create(['student_id' => $student->id]);

        Sanctum::actingAs($student);

        $this->postJson('/api/verification/scan', ['scan_token' => $card->scan_token])
            ->assertStatus(401);
    }

    public function test_scan_requires_authentication(): void
    {
        $this->postJson('/api/verification/scan', ['scan_token' => 'x'])
            ->assertStatus(401);
    }
}
