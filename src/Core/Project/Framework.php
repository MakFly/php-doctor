<?php

declare(strict_types=1);

namespace PhpDoctor\Core\Project;

enum Framework: string
{
    case Symfony = 'symfony';
    case Laravel = 'laravel';
    case Generic = 'generic';

    public function label(): string
    {
        return match($this) {
            self::Symfony => 'Symfony',
            self::Laravel => 'Laravel',
            self::Generic => 'Generic / autre',
        };
    }
}
