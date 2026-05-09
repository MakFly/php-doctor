<?php

declare(strict_types=1);

namespace Fixture;

readonly class Point
{
    public function __construct(
        public float $x,
        public float $y,
    ) {}
}

enum Color: string
{
    case Red   = 'red';
    case Green = 'green';
    case Blue  = 'blue';
}
