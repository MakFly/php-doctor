<?php

declare(strict_types=1);

namespace PhpDoctor\Core\Rule;

enum Category: string
{
    case Security     = 'security';
    case Performance  = 'performance';
    case Architecture = 'architecture';
    case TypeSafety   = 'type-safety';
    case Hygiene      = 'hygiene';
    case Dependencies = 'dependencies';
}
