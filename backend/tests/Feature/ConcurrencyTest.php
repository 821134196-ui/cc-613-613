<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * 跨进程并发测试：多个 PHP 进程连接同一个文件型 SQLite（WAL + IMMEDIATE），
 * 模拟窗口扫码与学生挂失 / 补证在真实并发下的交错。
 *
 * 这些用例不经过 HTTP 内存库，而是驱动 tests/concurrency/actor.php，
 * 因此锁、busy_timeout、事务隔离都是真实行为。
 */
#[Group('concurrency')]
class ConcurrencyTest extends TestCase
{
    private string $dbFile;

    private string $actor;

    /** @var array<string, string> */
    private array $childEnv;

    protected function setUp(): void
    {
        parent::setUp();

        $dir = sys_get_temp_dir().'/sid-concurrency-'.bin2hex(random_bytes(4));
        @mkdir($dir, 0777, true);
        $this->dbFile = $dir.'/database.sqlite';
        touch($this->dbFile);

        $this->actor = base_path('tests/concurrency/actor.php');

        $this->childEnv = [
            'APP_ENV' => 'local',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $this->dbFile,
            'DB_JOURNAL_MODE' => 'wal',
            'DB_BUSY_TIMEOUT' => '8000',
            'DB_TRANSACTION_MODE' => 'IMMEDIATE',
            'CACHE_STORE' => 'file',
            'SESSION_DRIVER' => 'file',
            'QUEUE_CONNECTION' => 'sync',
            'PATH' => getenv('PATH') ?: '',
            'HOME' => getenv('HOME') ?: '/tmp',
        ];

        $this->actor('migrate', mustContain: 'MIGRATED');
    }

    protected function tearDown(): void
    {
        foreach (glob(dirname($this->dbFile).'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir(dirname($this->dbFile));

        parent::tearDown();
    }

    /**
     * 挂失与扫码核验同时发生：
     *  - 核验语句在挂失提交前开始：读到的是该时刻服务端已提交快照（可放行）；
     *  - 挂失提交后再次扫码：必然 card_lost 拒绝。
     * 结论永远由服务端当前状态实时计算，旧页面显示无法放行。
     */
    public function test_verification_during_concurrent_loss_denied_after_commit(): void
    {
        $setup = json_decode($this->actor('setup', '2023070001'), true, 512, JSON_THROW_ON_ERROR);

        // 进程 A：开只读事务拿到 active 快照后保持 1 秒（代表核验进行中）
        $verifyInFlight = new Process(
            [PHP_BINARY, $this->actor, 'hold-verify', (string) $setup['card_id'], '1000'],
            base_path(),
            $this->childEnv,
        );
        $verifyInFlight->setTimeout(20);

        // 进程 B：300ms 后挂失（只读不阻塞它，立即提交 lost）
        $lose = new Process(
            [PHP_BINARY, $this->actor, 'report-lost', (string) $setup['card_id'], '300'],
            base_path(),
            $this->childEnv,
        );
        $lose->setTimeout(20);

        $verifyInFlight->start();
        usleep(150_000); // 确保 A 的读快照先于 B 建立
        $lose->start();

        $verifyInFlight->wait();
        $lose->wait();

        $inFlight = $this->extractJson($verifyInFlight->getOutput());
        $lostResult = $this->extractJson($lose->getOutput());

        $this->assertTrue($verifyInFlight->isSuccessful(), '持锁核验进程异常: '.$verifyInFlight->getErrorOutput());
        $this->assertTrue($lose->isSuccessful(), '挂失进程异常: '.$lose->getErrorOutput());

        // 核验开始时挂失尚未提交 → 快照为 active（那一刻服务端状态如此）
        $this->assertTrue($inFlight['eligible'], '挂失提交前已开始的核验应反映提交前快照');
        $this->assertSame('lost', $lostResult['status']);

        // 挂失提交后的任何新扫码：服务端当前状态 = lost → 拒绝
        $after = $this->extractJson($this->actor('verify-token', $setup['token']));
        $this->assertFalse($after['eligible']);
        $this->assertSame('card_lost', $after['reason_code']);
    }

    /**
     * 挂失写事务持有 RESERVED 锁期间，另一个写请求等待，提交顺序串行化，
     * 不会出现脏状态或 SQLITE_BUSY 导致的失败。
     */
    public function test_write_lock_serializes_concurrent_state_changes(): void
    {
        $setup = json_decode($this->actor('setup', '2023070002'), true, 512, JSON_THROW_ON_ERROR);

        $holder = new Process(
            [PHP_BINARY, $this->actor, 'hold-lost', (string) $setup['card_id'], '1000'],
            base_path(),
            $this->childEnv,
        );
        $holder->setTimeout(20);

        // 第二个挂失在持锁开始后 200ms 到达，必须等待前一个提交
        $waiter = new Process(
            [PHP_BINARY, $this->actor, 'report-lost', (string) $setup['card_id'], '200'],
            base_path(),
            $this->childEnv,
        );
        $waiter->setTimeout(20);

        $holder->start();
        usleep(200_000);
        $waiter->start();
        $holder->wait();
        $waiter->wait();

        $this->assertTrue($holder->isSuccessful(), $holder->getErrorOutput());
        $this->assertTrue($waiter->isSuccessful(), $waiter->getErrorOutput());

        $waiterResult = $this->extractJson($waiter->getOutput());
        // 等待者确实等过了持锁窗口
        $this->assertGreaterThanOrEqual(500, $waiterResult['waited_ms'], '第二个写请求应当等待写锁串行化');
        $this->assertSame('lost', $waiterResult['status']);

        $this->assertSame('STATUS lost', trim($this->actor('card-status', (string) $setup['card_id'])));
    }

    /**
     * 同一补证申请被并发“完成”两次：原子认领保证只签发一张新证，
     * 另一个收到 422 —— 旧证号只失效一次、新证号不重复。
     */
    public function test_concurrent_completion_issues_exactly_one_new_card(): void
    {
        $setup = json_decode($this->actor('setup-application', '2023070003'), true, 512, JSON_THROW_ON_ERROR);

        $first = new Process(
            [PHP_BINARY, $this->actor, 'complete', (string) $setup['application_id']],
            base_path(),
            $this->childEnv,
        );
        $second = new Process(
            [PHP_BINARY, $this->actor, 'complete', (string) $setup['application_id'], '150'],
            base_path(),
            $this->childEnv,
        );
        foreach ([$first, $second] as $p) {
            $p->setTimeout(20);
        }

        $first->start();
        $second->start();
        $first->wait();
        $second->wait();

        $this->assertTrue($first->isSuccessful(), $first->getErrorOutput());
        $this->assertTrue($second->isSuccessful(), $second->getErrorOutput());

        $outs = [trim($first->getOutput()), trim($second->getOutput())];
        $issued = array_filter($outs, fn ($o) => str_starts_with($o, 'ISSUED'));
        $denied = array_filter($outs, fn ($o) => str_starts_with($o, 'DENIED'));

        $this->assertCount(1, $issued, "应只签发一张新证，实际输出: ".implode(' | ', $outs));
        $this->assertCount(1, $denied, "并发的第二次完成必须被拒绝，实际输出: ".implode(' | ', $outs));
        $this->assertSame('ISSUED 2023070003-02', array_values($issued)[0]);

        // 只有一张有效证；旧证 replaced
        $this->assertSame('COUNT 1', trim($this->actor('active-cards', (string) $setup['student_id'])));
        $this->assertSame('STATUS replaced', trim($this->actor('card-status', (string) $setup['card_id'])));

        // 旧证 token 此刻再扫码：永久拒绝
        $after = $this->extractJson($this->actor('verify-token', $setup['old_token']));
        $this->assertFalse($after['eligible']);
        $this->assertSame('card_replaced', $after['reason_code']);
    }

    /**
     * 休学 → 挂失旧证 → 恢复在读：资格恢复，旧证保持 lost，跨进程端到端验证。
     */
    public function test_resume_enrollment_never_revives_lost_card(): void
    {
        $setup = json_decode($this->actor('setup', '2023070004', 'suspended'), true, 512, JSON_THROW_ON_ERROR);

        $this->assertStringContainsString('STATUS suspended', $this->actor('suspend', (string) $setup['student_id']));
        $this->actor('report-lost', (string) $setup['card_id']);
        $this->assertStringContainsString('STATUS enrolled', $this->actor('resume', (string) $setup['student_id']));

        // 资格恢复，但旧证没有复活
        $this->assertSame('STATUS lost', trim($this->actor('card-status', (string) $setup['card_id'])));
        $scan = $this->extractJson($this->actor('verify-token', $setup['token']));
        $this->assertFalse($scan['eligible']);
        $this->assertSame('card_lost', $scan['reason_code']);
    }

    /**
     * 补证后旧证号永久失效、永不复用：
     * 即使之后又经历休学/恢复，旧证依旧 replaced。
     */
    public function test_replaced_old_card_stays_dead_through_enrollment_changes(): void
    {
        $setup = json_decode($this->actor('setup-replaced', '2023070005'), true, 512, JSON_THROW_ON_ERROR);

        $this->actor('suspend', (string) $setup['student_id']);
        $this->actor('resume', (string) $setup['student_id']);

        $this->assertSame('STATUS replaced', trim($this->actor('card-status', (string) $setup['old_card_id'])));

        $old = $this->extractJson($this->actor('verify-token', $setup['old_token']));
        $this->assertFalse($old['eligible']);
        $this->assertSame('card_replaced', $old['reason_code']);

        $new = $this->extractJson($this->actor('verify-token', $setup['new_token']));
        $this->assertTrue($new['eligible']);
        $this->assertSame('ok', $new['reason_code']);
    }

    /**
     * @param array<int, string> $args
     */
    private function actor(string ...$args): string
    {
        $process = new Process(array_merge([PHP_BINARY, $this->actor], $args), base_path(), $this->childEnv);
        $process->setTimeout(30);
        $process->run();

        if (!$process->isSuccessful()) {
            $this->fail(sprintf("actor(%s) 失败:\n%s\n%s", implode(' ', $args), $process->getOutput(), $process->getErrorOutput()));
        }

        return $process->getOutput();
    }

    /**
     * 从输出中提取最后一个 RESULT 行里的 JSON。
     *
     * @return array<string, mixed>
     */
    private function extractJson(string $output): array
    {
        foreach (array_reverse(array_filter(array_map('trim', explode("\n", $output)))) as $line) {
            if (str_starts_with($line, 'RESULT ')) {
                return json_decode(substr($line, 7), true, 512, JSON_THROW_ON_ERROR);
            }
            if (str_starts_with($line, '{')) {
                return json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            }
        }

        $this->fail("无法从输出提取 JSON: {$output}");
    }
}
