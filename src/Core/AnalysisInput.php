<?php

declare(strict_types=1);

namespace PhpDoctor\Core;

use PhpDoctor\Analysis\Ast\AstSnapshot;
use PhpDoctor\Analysis\Runtime\RuntimeSnapshot;
use PhpDoctor\Core\Project\FrameworkContext;

/**
 * Immutable DTO passed to every Rule::analyze() call.
 * AstSnapshot and RuntimeSnapshot are stubs until Phase 4 and Phase 5.
 */
final readonly class AnalysisInput
{
    public function __construct(
        public FrameworkContext  $ctx,
        public ?AstSnapshot      $ast     = null,
        public ?RuntimeSnapshot  $runtime = null,
    ) {}
}
