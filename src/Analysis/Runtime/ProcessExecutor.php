<?php

declare(strict_types=1);

namespace PhpDoctor\Analysis\Runtime;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Thin wrapper around Symfony Process.
 * Captures stdout, stderr, and exit code in a ProcessResult.
 * Never throws — timeout and process failure are encoded in the result.
 */
final class ProcessExecutor implements ProcessExecutorInterface
{
    /**
     * Run a command in the given working directory.
     *
     * @param array<string> $command    Command and arguments as a list (no shell interpolation).
     * @param string        $cwd        Absolute path to the working directory.
     * @param int           $timeoutSec Maximum execution time before kill. Default 30 s.
     */
    public function run(array $command, string $cwd, int $timeoutSec = 30): ProcessResult
    {
        $process = new Process($command, $cwd);
        $process->setTimeout($timeoutSec);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return new ProcessResult(
                exitCode: -1,
                stdout:   '',
                stderr:   'Process timed out after ' . $timeoutSec . ' seconds.',
                timedOut: true,
            );
        }

        return new ProcessResult(
            exitCode: $process->getExitCode() ?? -1,
            stdout:   $process->getOutput(),
            stderr:   $process->getErrorOutput(),
        );
    }
}
