<?php

declare(strict_types=1);

namespace PhpDoctor\Analysis\Runtime;

/**
 * Immutable result of a process execution.
 */
final readonly class ProcessResult
{
    public bool $isSuccessful;

    public function __construct(
        public int    $exitCode,
        public string $stdout,
        public string $stderr,
        public bool   $timedOut = false,
    ) {
        $this->isSuccessful = $exitCode === 0 && !$timedOut;
    }
}
