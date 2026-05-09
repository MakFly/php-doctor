<?php

declare(strict_types=1);

namespace PhpDoctor\Core\Finding;

use PhpDoctor\Core\Rule\Category;
use PhpDoctor\Core\Rule\Severity;

/**
 * An immutable diagnostic produced by a Rule.
 * All properties are readonly — no setter is provided or possible.
 */
final readonly class Finding
{
    public function __construct(
        public string    $ruleId,
        public Severity  $severity,
        public Category  $category,
        public string    $message,
        public ?string   $file,
        public ?int      $line,
        public ?string   $fixHint = null,
        public ?string   $docUrl  = null,
    ) {}

    /**
     * Serialize to a plain array for JSON encoding / reporting.
     *
     * @return array{
     *   ruleId: string,
     *   severity: string,
     *   category: string,
     *   message: string,
     *   file: string|null,
     *   line: int|null,
     *   fixHint: string|null,
     *   docUrl: string|null,
     * }
     */
    public function toArray(): array
    {
        return [
            'ruleId'   => $this->ruleId,
            'severity' => $this->severity->value,
            'category' => $this->category->value,
            'message'  => $this->message,
            'file'     => $this->file,
            'line'     => $this->line,
            'fixHint'  => $this->fixHint,
            'docUrl'   => $this->docUrl,
        ];
    }
}
