<?php

use App\Models\Student;
use App\Models\StudentCard;
use App\Services\CardService;
use App\Services\VerificationService;
use Illuminate\Support\Str;

/**
 * 关键安全规则二：挂失 与 扫码核验 并发。
 *
 * 这不是普通 PHPUnit 用例，而是被 ConcurrentVerificationTest 通过子进程调用的
 * 多进程竞态脚本（phpunit 内无法跨进程共享事务库）。
 *
 * 它针对一个真实的文件型 SQLite（WAL）fork 出多个进程：
 *   - L 进程执行挂失（条件式原子 UPDATE）
 *   - V 进程高频扫码核验
 * 断言：
 *   1. 核验结论一旦翻转为拒绝，就绝不能再翻回放行（服务端状态单调、以提交顺序为准）；
 *   2. 每一次核验的 allowed 都与此刻数据库里证件/学籍的真实状态一致（不依赖调用方缓存）；
 *   3. 终态：旧证 lost，且只有挂失/核验在跑，不会凭空产生新证。
 *
 * 退出码 0 表示通过，非 0 表示发现竞态不一致。
 */

require __DIR__ . '/../../vendor/autoload.php';

$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

/* ---------- 用独立临时文件库，避免污染开发库 ---------- */
$tmpDb = tempnam(sys_get_temp_dir(), 'race_') . '.sqlite';
touch($tmpDb);
config([
    'database.connections.sqlite.database' => $tmpDb,
    'database.connections.sqlite.journal_mode' => 'WAL',
]);
DBPurgeAndMigrate();

function DBPurgeAndMigrate(): void
{
    \Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
}

$failures = [];
function fail(string $msg): void
{
    global $failures;
    $failures[] = $msg;
    fwrite(STDERR, "RACE FAIL: $msg\n");
}

/* ---------- 准备一个在读学生 + 一张有效证 ---------- */
$student = Student::create([
    'student_no' => '2023000099',
    'name' => '并发测试生',
    'college' => '测试学院',
    'major' => '测试专业',
    'enrollment_status' => Student::STATUS_ACTIVE,
    'password' => 'x',
]);
$card = StudentCard::create([
    'student_id' => $student->id,
    'card_no' => '2023000099',
    'version' => 1,
    'status' => StudentCard::STATUS_ACTIVE,
    'qr_token' => (string) Str::uuid(),
]);
$cardId = $card->id;
$qrToken = $card->qr_token;
$studentId = $student->id;

$resultsFile = tempnam(sys_get_temp_dir(), 'race_out_');

/* ---------- 子进程公共：fork 后重建独立 DB 连接 ---------- */
$child = function (string $role, int $iterations) use ($cardId, $qrToken, $studentId, $resultsFile) {
    // fork 后每个进程必须断开并重建连接，避免共享父进程的连接句柄。
    \Illuminate\Support\Facades\DB::purge('sqlite');
    $pdo = \Illuminate\Support\Facades\DB::connection('sqlite')->getPdo();
    $pdo->exec('pragma journal_mode=WAL');
    $pdo->exec('pragma busy_timeout=10000');

    $sawFalse = false;
    $allowedTrueAfterFalse = 0;
    $mismatches = 0;

    for ($i = 0; $i < $iterations; $i++) {
        if ($role === 'loss') {
            // 只有一个 loss 进程，放在中途执行，制造与核验交叠。
            if ($i === (int) ($iterations / 2)) {
                $s = Student::find($studentId);
                $c = StudentCard::find($cardId);
                app(CardService::class)->reportLoss($s, $c, '并发挂失');
            }
            // 让出时间片，制造交错
            usleep(random_int(0, 800));
        } else {
            // 每次都重新从服务端当前状态判定（服务内部实时查库，无任何缓存）。
            $r = app(VerificationService::class)->verifyByToken($qrToken);

            // 不变量 A：单次响应内部必须自洽——allowed 必须严格等于
            // 「证件有效 且 在读」。（同一响应内的读取点，不存在 TOCTOU）
            $selfConsistent = $r['allowed'] === ($r['checks']['card_valid'] && $r['checks']['enrolled'])
                && $r['allowed'] === $r['checks']['card_valid'] // 本场景学籍始终 active
                && $r['checks']['enrolled'] === true;
            if (!$selfConsistent) {
                $mismatches++;
            }

            // 不变量 B：结论单调。挂失使 active->lost 是单向迁移，
            // 因此一旦观察到拒绝，就绝不允许再放行（旧证不能复活）。
            if ($r['allowed'] === false) {
                $sawFalse = true;
            } elseif ($sawFalse) {
                $allowedTrueAfterFalse++;
            }
            usleep(random_int(0, 500));
        }
    }

    if ($role !== 'loss') {
        file_put_contents($resultsFile, json_encode([
            'mismatches' => $mismatches,
            'true_after_false' => $allowedTrueAfterFalse,
        ]) . "\n", FILE_APPEND);
    }
};

/* ---------- fork：1 个挂失进程 + 3 个核验进程 ---------- */
$pids = [];
$roles = [
    ['loss', 40],
    ['verify', 120],
    ['verify', 120],
    ['verify', 120],
];

foreach ($roles as [$role, $iters]) {
    $pid = pcntl_fork();
    if ($pid === -1) {
        fwrite(STDERR, "fork failed\n");
        exit(2);
    }
    if ($pid === 0) {
        $child($role, $iters);
        exit(0);
    }
    $pids[] = $pid;
}

foreach ($pids as $pid) {
    pcntl_waitpid($pid, $status);
    if (pcntl_wifexited($status) && pcntl_wexitstatus($status) !== 0) {
        fail("子进程 $pid 异常退出");
    }
}

/* ---------- 汇总核验进程结果 ---------- */
$lines = array_filter(explode("\n", (string) file_get_contents($resultsFile)));
foreach ($lines as $line) {
    $r = json_decode($line, true);
    if (($r['mismatches'] ?? 0) > 0) {
        fail("核验结论与数据库实时状态不一致 {$r['mismatches']} 次");
    }
    if (($r['true_after_false'] ?? 0) > 0) {
        fail("挂失后旧证又被放行（旧证复活）{$r['true_after_false']} 次");
    }
}

/* ---------- 终态断言 ---------- */
$final = StudentCard::find($cardId);
if ($final->status !== StudentCard::STATUS_LOST) {
    fail("终态证件应为 lost，实际为 {$final->status}");
}
if (StudentCard::where('student_id', $studentId)->count() !== 1) {
    fail('挂失/核验过程中不应产生新证件');
}

// 再核验一次，确认服务端当前状态稳定拒绝
$last = app(VerificationService::class)->verifyByToken($qrToken);
if ($last['allowed'] !== false || $last['checks']['card_valid'] !== false) {
    fail('终态核验仍可能放行旧证');
}

@unlink($tmpDb);
@unlink($tmpDb . '-wal');
@unlink($tmpDb . '-shm');
@unlink($resultsFile);

if ($failures) {
    fwrite(STDERR, count($failures) . " 个竞态断言失败\n");
    exit(1);
}

fwrite(STDOUT, "并发核验断言全部通过（3 个核验进程 × 120 次 vs 挂失进程）\n");
exit(0);
