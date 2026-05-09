<?php

declare(strict_types=1);

namespace PhpDoctor\Analysis\Runtime\PhpStan;

/**
 * Immutable DTO holding the parsed output of a PHPStan run.
 *
 * Each message is already flattened from the PHPStan JSON structure:
 *   { "files": { "/abs/Foo.php": { "messages": [...] } }, "totals": { ... } }
 * into a simple list keyed by file.
 *
 * @phpstan-type PhpStanMessage array{
 *   file: string,
 *   line: int|null,
 *   message: string,
 *   identifier: string|null,
 *   ignorable: bool,
 *   tip: string|null,
 * }
 */
final readonly class PhpStanReport
{
    /**
     * @param int                  $totalErrors Total error count from PHPStan totals.
     * @param PhpStanMessage[]     $messages    Flattened list of per-file messages.
     */
    public function __construct(
        public int   $totalErrors,
        public array $messages,
    ) {}
}
