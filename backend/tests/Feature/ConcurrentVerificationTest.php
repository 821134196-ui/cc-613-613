<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 以子进程方式运行多进程竞态脚本（tests/concurrency/loss_vs_verify_race.php），
 * 让「挂失 vs 扫码核验并发」也能随 phpUnit 套件一起被检验。
 */
class ConcurrentVerificationTest extends TestCase
{
    public function test_loss_and_verify_race_never_revives_lost_card(): void
    {
        $script = base_path('tests/concurrency/loss_vs_verify_race.php');
        $php = PHP_BINARY;

        $desc = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = proc_open([$php, $script], $desc, $pipes, base_path(), [
            // 保证子进程能加载到本地扩展 ini
            'PHP_INI_SCAN_DIR' => getenv('PHP_INI_SCAN_DIR') ?: (getenv('HOME') . '/php-local/conf.d'),
            'LD_LIBRARY_PATH' => getenv('HOME') . '/php-local/root/usr/lib/x86_64-linux-gnu',
        ]);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        $this->assertSame(0, $code, "并发竞态脚本失败：\nSTDOUT: {$stdout}\nSTDERR: {$stderr}");
        $this->assertStringContainsString('并发核验断言全部通过', $stdout);
    }
}
