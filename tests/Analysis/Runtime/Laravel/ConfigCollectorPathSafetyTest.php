<?php

declare(strict_types=1);

namespace PhpDoctor\Tests\Analysis\Runtime\Laravel;

use PhpDoctor\Analysis\Runtime\Laravel\ConfigCollector;
use PhpDoctor\Analysis\Runtime\ProcessExecutorInterface;
use PhpDoctor\Analysis\Runtime\ProcessResult;
use PhpDoctor\Core\Finding\FindingBag;
use PhpDoctor\Core\Project\Framework;
use PhpDoctor\Core\Project\FrameworkContext;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that ConfigCollector does NOT interpolate rootPath into the php -r script.
 *
 * If the path were interpolated, a path containing a single-quote (') could inject
 * arbitrary PHP code (RCE). The fix uses relative paths with cwd=$rootPath instead.
 */
final class ConfigCollectorPathSafetyTest extends TestCase
{
    public function testScriptArgumentDoesNotContainRootPath(): void
    {
        // A root path with a single-quote — classic RCE payload character.
        $maliciousRoot = "/tmp/php-doctor-rce'test";

        $capturedCommand = null;

        $exec = $this->createMock(ProcessExecutorInterface::class);
        $exec->method('run')
            ->willReturnCallback(function (array $command, string $cwd, int $timeout, array $env) use (&$capturedCommand): ProcessResult {
                $capturedCommand = $command;
                return new ProcessResult(exitCode: 1, stdout: '', stderr: 'mocked');
            });

        $ctx = new FrameworkContext(
            framework:     Framework::Laravel,
            rootPath:      $maliciousRoot,
            consoleBinary: $maliciousRoot . '/artisan',
            composerData:  [],
            sourcePaths:   [],
        );

        $bag       = new FindingBag();
        $collector = new ConfigCollector($exec, $bag);
        $collector->collect($ctx);

        $this->assertNotNull($capturedCommand, 'ProcessExecutor::run() must have been called');

        // The -r script (3rd element of the command array: ['php', '-r', $script])
        // must NOT contain the rootPath as a substring.
        $script = $capturedCommand[2] ?? '';
        $this->assertStringNotContainsString(
            $maliciousRoot,
            $script,
            'rootPath MUST NOT be interpolated into the php -r script to prevent RCE via path injection',
        );
    }

    public function testCwdIsSetToRootPath(): void
    {
        $rootPath = '/tmp/php-doctor-cwd-test';

        $capturedCwd = null;

        $exec = $this->createMock(ProcessExecutorInterface::class);
        $exec->method('run')
            ->willReturnCallback(function (array $command, string $cwd, int $timeout, array $env) use (&$capturedCwd): ProcessResult {
                $capturedCwd = $cwd;
                return new ProcessResult(exitCode: 1, stdout: '', stderr: 'mocked');
            });

        $ctx = new FrameworkContext(
            framework:     Framework::Laravel,
            rootPath:      $rootPath,
            consoleBinary: $rootPath . '/artisan',
            composerData:  [],
            sourcePaths:   [],
        );

        $bag       = new FindingBag();
        $collector = new ConfigCollector($exec, $bag);
        $collector->collect($ctx);

        $this->assertSame($rootPath, $capturedCwd, 'cwd must be set to rootPath so relative paths resolve correctly');
    }

    public function testAppEnvForcedToLocal(): void
    {
        $capturedEnv = null;

        $exec = $this->createMock(ProcessExecutorInterface::class);
        $exec->method('run')
            ->willReturnCallback(function (array $command, string $cwd, int $timeout, array $env) use (&$capturedEnv): ProcessResult {
                $capturedEnv = $env;
                return new ProcessResult(exitCode: 1, stdout: '', stderr: 'mocked');
            });

        $ctx = new FrameworkContext(
            framework:     Framework::Laravel,
            rootPath:      '/tmp/php-doctor-env-test',
            consoleBinary: '/tmp/php-doctor-env-test/artisan',
            composerData:  [],
            sourcePaths:   [],
        );

        $bag       = new FindingBag();
        $collector = new ConfigCollector($exec, $bag);
        $collector->collect($ctx);

        $this->assertNotNull($capturedEnv, 'ProcessExecutor::run() must have been called');
        $this->assertSame('local', $capturedEnv['APP_ENV'] ?? null, 'APP_ENV must be forced to local');
        $this->assertSame('0', $capturedEnv['APP_DEBUG'] ?? null, 'APP_DEBUG must be forced to 0');
    }
}
