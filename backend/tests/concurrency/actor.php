<?php

/**
 * 并发测试演员脚本（独立进程，连接同一个文件型 SQLite 库）。
 *
 * 由 Feature 测试通过 Symfony Process 驱动；通过文件信号 / usleep
 * 制造“挂失与核验同时发生”的真实交错，验证服务端以当前已提交状态判断。
 *
 * 必需环境变量：
 *   DB_DATABASE  —— SQLite 文件绝对路径
 *   DB_BUSY_TIMEOUT / DB_JOURNAL_MODE 由测试按需设置
 */

use App\Models\ReplacementApplication;
use App\Models\Student;
use App\Models\StudentCard;
use App\Services\CardService;
use App\Services\EnrollmentService;
use App\Services\VerificationService;
use App\Support\CardNumber;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$mode = $argv[1] ?? '';

function out(string $line): void
{
    fwrite(STDOUT, $line.PHP_EOL);
}

function migrate(): void
{
    Artisan::call('migrate', ['--force' => true]);
    out('MIGRATED');
}

/**
 * @return array{student_id:int, card_id:int, token:string}
 */
function setup(string $studentNumber = '2023090001', string $status = Student::ENROLLED): array
{
    $student = Student::create([
        'student_number' => $studentNumber,
        'name' => '并发测试生',
        'college' => '测试学院',
        'password' => Hash::make('password123'),
        'enrollment_status' => $status,
        'suspended_at' => $status === Student::SUSPENDED ? now() : null,
    ]);

    $card = StudentCard::create([
        'student_id' => $student->id,
        'card_no' => CardNumber::make($student->student_number, 1),
        'status' => StudentCard::ACTIVE,
        'scan_token' => CardNumber::newScanToken(),
        'sequence' => 1,
        'issued_at' => now(),
    ]);

    return [
        'student_id' => $student->id,
        'card_id' => $card->id,
        'token' => $card->scan_token,
    ];
}

try {
    switch ($mode) {
        case 'migrate':
            migrate();
            break;

        case 'setup':
            out(json_encode(setup($argv[2] ?? '2023090001', $argv[3] ?? Student::ENROLLED), JSON_UNESCAPED_UNICODE));
            break;

        /**
         * 完整补证历史：在读学生，旧证已 replaced，新证 active。
         * 返回旧/新两枚 token，供“恢复学籍不能复活旧证”等测试。
         */
        case 'setup-replaced':
            $studentNumber = $argv[2] ?? '2023090002';
            setup($studentNumber);
            $student = Student::where('student_number', $studentNumber)->firstOrFail();
            $oldCard = $student->cards()->firstOrFail();
            $oldToken = $oldCard->scan_token;

            $application = ReplacementApplication::create([
                'student_id' => $student->id,
                'lost_card_id' => $oldCard->id,
                'status' => ReplacementApplication::PENDING,
                'reason' => '证件遗失',
                'submitted_at' => now(),
            ]);

            $newCard = app(CardService::class)->issueReplacement($student, $oldCard, null, $application->id);

            out(json_encode([
                'student_id' => $student->id,
                'old_card_id' => $oldCard->id,
                'new_card_id' => $newCard->id,
                'old_token' => $oldToken,
                'new_token' => $newCard->scan_token,
            ], JSON_UNESCAPED_UNICODE));
            break;

        case 'verify-token':
            $result = app(VerificationService::class)->verifyByToken($argv[2]);
            out('RESULT '.json_encode([
                'eligible' => $result['eligible'],
                'reason_code' => $result['reason_code'],
            ], JSON_UNESCAPED_UNICODE));
            break;

        /**
         * 持锁核验：先开 IMMEDIATE 事务持有 RESERVED 锁，
         * 在事务内读取服务端当前状态并保持 $holdMs 毫秒，然后提交。
         * 并发的挂失请求在此期间只能等待（busy_timeout）。
         */
        case 'hold-verify':
            $cardId = (int) $argv[2];
            $holdMs = (int) ($argv[3] ?? 1000);

            DB::connection()->beginTransaction(); // BEGIN IMMEDIATE
            $started = microtime(true);

            $card = StudentCard::with('student')->findOrFail($cardId);
            usleep($holdMs * 1000); // 持锁，模拟核验进行中

            $eligible = $card->isActive() && $card->student->isEnrolled();
            DB::connection()->commit();

            out('RESULT '.json_encode([
                'eligible' => $eligible,
                'reason_code' => $eligible ? 'ok' : 'state_changed',
                'held_ms' => (int) round((microtime(true) - $started) * 1000),
            ], JSON_UNESCAPED_UNICODE));
            break;

        case 'report-lost':
            $cardId = (int) $argv[2];
            $delayMs = (int) ($argv[3] ?? 0);
            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }
            $started = microtime(true);
            $card = app(CardService::class)->reportLost(StudentCard::findOrFail($cardId));
            out('RESULT '.json_encode([
                'status' => $card->status,
                'waited_ms' => (int) round((microtime(true) - $started) * 1000),
            ], JSON_UNESCAPED_UNICODE));
            break;

        /**
         * 持写锁挂失：先 BEGIN IMMEDIATE 拿到 RESERVED 锁并保持 holdMs，
         * 期间不提交（其他写者等待；WAL 读者读到提交前的旧状态），
         * 然后把证置为 lost 并提交。用于精确构造核验与挂失的交错。
         */
        case 'hold-lost':
            $cardId = (int) $argv[2];
            $holdMs = (int) ($argv[3] ?? 800);
            DB::connection()->beginTransaction();
            usleep($holdMs * 1000);
            StudentCard::whereKey($cardId)->update([
                'status' => StudentCard::LOST,
                'lost_at' => now(),
                'updated_at' => now(),
            ]);
            DB::connection()->commit();
            out('COMMITTED lost');
            break;

        /**
         * 补证完成（与 StaffApplicationController::complete 同一段原子认领逻辑）。
         * 并发两个进程时，只应有一个 ISSUED，另一个 DENIED:422。
         */
        case 'complete':
            $applicationId = (int) $argv[2];
            $delayMs = (int) ($argv[3] ?? 0);
            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }

            try {
                $newCard = DB::transaction(function () use ($applicationId) {
                    $claimed = DB::table('replacement_applications')
                        ->where('id', $applicationId)
                        ->whereNotIn('status', [
                            ReplacementApplication::COMPLETED,
                            ReplacementApplication::REJECTED,
                        ])
                        ->update(['status' => ReplacementApplication::PROCESSING, 'updated_at' => now()]);

                    if ($claimed === 0) {
                        throw new RuntimeException('CLAIM_DENIED');
                    }

                    $application = ReplacementApplication::query()
                        ->with(['student', 'lostCard'])
                        ->findOrFail($applicationId);

                    return app(CardService::class)->issueReplacement(
                        $application->student,
                        $application->lostCard,
                        null,
                        $application->id,
                    );
                });
                out("ISSUED {$newCard->card_no}");
            } catch (RuntimeException $e) {
                if ($e->getMessage() === 'CLAIM_DENIED') {
                    out('DENIED 422');
                    exit(0);
                }
                throw $e;
            }
            break;

        case 'setup-application':
            // 在读学生 + 已挂失证 + 待受理补证申请
            $studentNumber = $argv[2] ?? '2023090009';
            setup($studentNumber);
            $student = Student::where('student_number', $studentNumber)->firstOrFail();
            $card = $student->cards()->firstOrFail();
            $card->update(['status' => StudentCard::LOST, 'lost_at' => now()]);

            $application = ReplacementApplication::create([
                'student_id' => $student->id,
                'lost_card_id' => $card->id,
                'status' => ReplacementApplication::PENDING,
                'reason' => '证件遗失',
                'submitted_at' => now(),
            ]);

            out(json_encode([
                'student_id' => $student->id,
                'card_id' => $card->id,
                'application_id' => $application->id,
                'old_token' => $card->scan_token,
            ], JSON_UNESCAPED_UNICODE));
            break;

        case 'suspend':
            $s = app(EnrollmentService::class)->suspend(Student::findOrFail((int) $argv[2]));
            out("STATUS {$s->enrollment_status}");
            break;

        case 'resume':
            $s = app(EnrollmentService::class)->resume(Student::findOrFail((int) $argv[2]));
            out("STATUS {$s->enrollment_status}");
            break;

        case 'card-status':
            $card = StudentCard::findOrFail((int) $argv[2]);
            out("STATUS {$card->status}");
            break;

        case 'active-cards':
            $count = StudentCard::where('student_id', (int) $argv[2])
                ->where('status', StudentCard::ACTIVE)
                ->count();
            out("COUNT {$count}");
            break;

        default:
            fwrite(STDERR, "Unknown mode: {$mode}\n");
            exit(2);
    }
} catch (Throwable $e) {
            fwrite(STDERR, 'ERROR '.$e::class.': '.$e->getMessage()."\n");
    exit(1);
}
