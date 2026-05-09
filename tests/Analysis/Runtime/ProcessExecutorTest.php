<?php

declare(strict_types=1);

namespace PhpDoctor\Tests\Analysis\Runtime;

use PhpDoctor\Analysis\Runtime\ProcessExecutor;
use PHPUnit\Framework\TestCase;

final class ProcessExecutorTest extends TestCase
{
    private ProcessExecutor $executor;

    protected function setUp(): void
    {
        $this->executor = new ProcessExecutor();
    }

    public function testRunsSimpleCommandAndCapturesStdout(): void
    {
        $result = $this->executor->run(['bash', '-c', 'echo hello'], sys_get_temp_dir());

        $this->assertTrue($result->isSuccessful);
        $this->assertSame(0, $result->exitCode);
        $this->assertSame("hello\n", $result->stdout);
        $this->assertSame('', $result->stderr);
        $this->assertFalse($result->timedOut);
    }

    public function testCapturesNonZeroExitCode(): void
    {
        $result = $this->executor->run(['bash', '-c', 'exit 42'], sys_get_temp_dir());

        $this->assertFalse($result->isSuccessful);
        $this->assertSame(42, $result->exitCode);
        $this->assertFalse($result->timedOut);
    }

    public function testCapturesStderr(): void
    {
        $result = $this->executor->run(['bash', '-c', 'echo error-text >&2; exit 1'], sys_get_temp_dir());

        $this->assertFalse($result->isSuccessful);
        $this->assertStringContainsString('error-text', $result->stderr);
    }

    public function testTimedOutResultIsNotSuccessful(): void
    {
        // A very short timeout to force a timeout on a sleeping command.
        $result = $this->executor->run(['bash', '-c', 'sleep 60'], sys_get_temp_dir(), 1);

        $this->assertTrue($result->timedOut);
        $this->assertFalse($result->isSuccessful);
        $this->assertStringContainsString('timed out', strtolower($result->stderr));
    }
}
