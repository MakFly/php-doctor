<?php

declare(strict_types=1);

namespace PhpDoctor\Core\Project;

/**
 * Minimal placeholder for Phase 2.
 *
 * @todo Phase 3 will replace $framework with a Framework enum, add
 *       $consoleBinary, $composerData, and $sourcePaths, and introduce
 *       ProjectDetector to populate this from a real project root.
 */
final readonly class FrameworkContext
{
    public function __construct(
        public string $rootPath,
        public string $framework,
    ) {}
}
