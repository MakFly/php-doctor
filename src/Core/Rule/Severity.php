<?php

declare(strict_types=1);

namespace PhpDoctor\Core\Rule;

enum Severity: string
{
    case Critical = 'critical';
    case High     = 'high';
    case Medium   = 'medium';
    case Low      = 'low';
    case Info     = 'info';

    /**
     * Penalty weight used by Score::fromBag().
     * Critical=20, High=10, Medium=5, Low=2, Info=0.
     */
    public function weight(): int
    {
        return match ($this) {
            self::Critical => 20,
            self::High     => 10,
            self::Medium   => 5,
            self::Low      => 2,
            self::Info     => 0,
        };
    }
}
