<?php

declare(strict_types=1);

namespace PhpDoctor\Core\Project;

/**
 * Immutable description of a detected PHP project.
 *
 * @param Framework     $framework      Detected framework variant.
 * @param string        $rootPath       Absolute, realpath-resolved project root.
 * @param string|null   $consoleBinary  Absolute path to CLI binary (bin/console or artisan), or null.
 * @param array<mixed>  $composerData   Decoded composer.json as an associative array.
 * @param string[]      $sourcePaths    Absolute paths to source directories that exist on disk.
 */
final readonly class FrameworkContext
{
    /**
     * @param string[]     $sourcePaths
     * @param array<mixed> $composerData
     */
    public function __construct(
        public Framework $framework,
        public string    $rootPath,
        public ?string   $consoleBinary,
        public array     $composerData,
        public array     $sourcePaths,
    ) {}
}
