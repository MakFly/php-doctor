<?php

declare(strict_types=1);

namespace PhpDoctor\Tests\Core\Rule;

use PhpDoctor\Core\AnalysisInput;
use PhpDoctor\Core\Finding\Finding;
use PhpDoctor\Core\Project\Framework;
use PhpDoctor\Core\Project\FrameworkContext;
use PhpDoctor\Core\Rule\Category;
use PhpDoctor\Core\Rule\Rule;
use PhpDoctor\Core\Rule\RuleRegistry;
use PhpDoctor\Core\Rule\Severity;
use PHPUnit\Framework\TestCase;

final class RuleRegistryTest extends TestCase
{
    public function testEnabledForFiltersByAppliesTo(): void
    {
        $registry = new RuleRegistry();
        $registry->register($this->makeRule('rule.a', appliesTo: true));
        $registry->register($this->makeRule('rule.b', appliesTo: false));

        $ctx     = new FrameworkContext(Framework::Generic, '/tmp', null, [], []);
        $enabled = iterator_to_array($registry->enabledFor($ctx), false);

        $this->assertCount(1, $enabled);
        $this->assertSame('rule.a', $enabled[0]->id());
    }

    public function testCountReturnsAllRegistered(): void
    {
        $registry = new RuleRegistry();
        $registry->register($this->makeRule('rule.1', true));
        $registry->register($this->makeRule('rule.2', true));
        $registry->register($this->makeRule('rule.3', false));

        $this->assertSame(3, $registry->count());
    }

    // ─── helpers ────────────────────────────────────────────────────────────────

    private function makeRule(string $id, bool $appliesTo): Rule
    {
        return new class ($id, $appliesTo) implements Rule {
            public function __construct(
                private readonly string $ruleId,
                private readonly bool   $applies,
            ) {}

            public function id(): string
            {
                return $this->ruleId;
            }

            public function category(): Category
            {
                return Category::Hygiene;
            }

            public function severity(): Severity
            {
                return Severity::Info;
            }

            public function appliesTo(FrameworkContext $ctx): bool
            {
                return $this->applies;
            }

            public function analyze(AnalysisInput $input): iterable
            {
                return [];
            }
        };
    }
}
