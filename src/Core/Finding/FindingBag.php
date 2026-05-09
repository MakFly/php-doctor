<?php

declare(strict_types=1);

namespace PhpDoctor\Core\Finding;

use PhpDoctor\Core\Rule\Category;

/**
 * Mutable, add-only collection of Findings produced during a scan.
 */
final class FindingBag
{
    /** @var Finding[] */
    private array $findings = [];

    public function add(Finding $finding): void
    {
        $this->findings[] = $finding;
    }

    /**
     * @return iterable<Finding>
     */
    public function all(): iterable
    {
        return $this->findings;
    }

    /**
     * Group findings by category value.
     *
     * @return array<string, Finding[]>
     */
    public function byCategory(): array
    {
        $grouped = [];
        foreach ($this->findings as $finding) {
            $grouped[$finding->category->value][] = $finding;
        }
        return $grouped;
    }

    public function count(): int
    {
        return count($this->findings);
    }

    /**
     * Count findings per severity value.
     *
     * @return array<string, int>
     */
    public function countBySeverity(): array
    {
        $counts = [];
        foreach ($this->findings as $finding) {
            $key = $finding->severity->value;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        return $counts;
    }
}
