<?php

declare(strict_types=1);

namespace PhpDoctor\Core\Finding;

use PhpDoctor\Core\Rule\Category;

/**
 * Scored health report derived from a FindingBag.
 *
 * ## Scoring formula (per category)
 *
 *   categoryScore = clamp(100 - sum(finding.severity.weight()) * PENALTY_COEFFICIENT, 0, 100)
 *
 * Categories with zero findings stay at 100.
 * The global score is the arithmetic mean of per-category scores across all
 * known Category cases (categories without findings contribute 100).
 *
 * PENALTY_COEFFICIENT = 0.5 — chosen so that a single Critical finding (weight 20)
 * costs 10 points, landing the category at 90.
 */
final readonly class Score
{
    private const PENALTY_COEFFICIENT = 0.5;

    /**
     * @param array<string, int> $byCategory
     */
    public function __construct(
        public int   $global,
        public array $byCategory,
    ) {}

    public static function fromBag(FindingBag $bag): self
    {
        $allCategories = Category::cases();

        // Build penalty sums per category value key.
        $penaltySums = [];
        foreach ($bag->all() as $finding) {
            $key = $finding->category->value;
            $penaltySums[$key] = ($penaltySums[$key] ?? 0) + $finding->severity->weight();
        }

        $byCategory = [];
        $total      = 0;

        foreach ($allCategories as $category) {
            $key      = $category->value;
            $penalty  = ($penaltySums[$key] ?? 0) * self::PENALTY_COEFFICIENT;
            $score    = (int) max(0, min(100, (int) round(100 - $penalty)));

            $byCategory[$key] = $score;
            $total            += $score;
        }

        $global = (int) round($total / count($allCategories));

        return new self($global, $byCategory);
    }

    /**
     * @return array{global: int, byCategory: array<string, int>}
     */
    public function toArray(): array
    {
        return [
            'global'     => $this->global,
            'byCategory' => $this->byCategory,
        ];
    }
}
