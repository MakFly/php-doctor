<?php

declare(strict_types=1);

namespace PhpDoctor\Analysis\Runtime;

/**
 * Contract for running external processes.
 * The production implementation wraps Symfony Process; tests inject a mock.
 */
interface ProcessExecutorInterface
{
    /**
     * Run a command in the given working directory.
     *
     * @param array<string>       $command    Command and arguments (no shell interpolation).
     * @param string              $cwd        Absolute path to the working directory.
     * @param int                 $timeoutSec Maximum execution time in seconds.
     * @param array<string,string> $env       Extra environment variables to inject (merged on top of the inherited env).
     */
    public function run(array $command, string $cwd, int $timeoutSec = 30, array $env = []): ProcessResult;
}
