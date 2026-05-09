<?php

declare(strict_types=1);

namespace PhpDoctor\Core\Rule;

use PhpDoctor\Core\Project\FrameworkContext;

/**
 * Ordered collection of registered Rules.
 * Rules are stored in insertion order.
 */
final class RuleRegistry
{
    /** @var Rule[] */
    private array $rules = [];

    public function register(Rule $rule): void
    {
        $this->rules[] = $rule;
    }

    /**
     * Yield only rules whose appliesTo() returns true for the given context.
     *
     * @return iterable<Rule>
     */
    public function enabledFor(FrameworkContext $ctx): iterable
    {
        foreach ($this->rules as $rule) {
            if ($rule->appliesTo($ctx)) {
                yield $rule;
            }
        }
    }

    public function count(): int
    {
        return count($this->rules);
    }

    /**
     * @return iterable<Rule>
     */
    public function all(): iterable
    {
        return $this->rules;
    }
}
