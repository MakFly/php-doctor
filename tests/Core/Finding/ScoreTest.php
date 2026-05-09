<?php

declare(strict_types=1);

namespace PhpDoctor\Tests\Core\Finding;

use PhpDoctor\Core\Finding\Finding;
use PhpDoctor\Core\Finding\FindingBag;
use PhpDoctor\Core\Finding\Score;
use PhpDoctor\Core\Rule\Category;
use PhpDoctor\Core\Rule\Severity;
use PHPUnit\Framework\TestCase;

final class ScoreTest extends TestCase
{
    public function testEmptyBagReturns100(): void
    {
        $score = Score::fromBag(new FindingBag());

        $this->assertSame(100, $score->global);
    }

    public function testSingleCriticalLowersScore(): void
    {
        // Critical weight=20, coefficient=0.5 → penalty=10 → categoryScore=90
        $bag = new FindingBag();
        $bag->add($this->makeFinding(Severity::Critical, Category::Security));

        $score = Score::fromBag($bag);

        // Global is mean across all 6 categories: (90 + 100*5) / 6 = 590/6 ≈ 98
        $this->assertLessThanOrEqual(100, $score->global);
        $this->assertGreaterThanOrEqual(90, $score->global);
        $this->assertSame(90, $score->byCategory[Category::Security->value]);
    }

    public function testManyFindingsClampToZero(): void
    {
        // 50 Critical in same category → penalty = 50 * 20 * 0.5 = 500 → clamp to 0
        $bag = new FindingBag();
        for ($i = 0; $i < 50; $i++) {
            $bag->add($this->makeFinding(Severity::Critical, Category::Security));
        }

        $score = Score::fromBag($bag);

        $this->assertSame(0, $score->byCategory[Category::Security->value]);
        $this->assertGreaterThanOrEqual(0, $score->global);
    }

    public function testCategoryWithoutFindingsStaysAt100(): void
    {
        // Add a finding only to Hygiene — Security should remain 100
        $bag = new FindingBag();
        $bag->add($this->makeFinding(Severity::Critical, Category::Hygiene));

        $score = Score::fromBag($bag);

        $this->assertSame(100, $score->byCategory[Category::Security->value]);
    }

    // ─── helpers ────────────────────────────────────────────────────────────────

    private function makeFinding(Severity $severity, Category $category): Finding
    {
        return new Finding(
            ruleId:   'test.rule',
            severity: $severity,
            category: $category,
            message:  'Test finding',
            file:     null,
            line:     null,
        );
    }
}
