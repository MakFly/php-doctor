<?php

declare(strict_types=1);

namespace PhpDoctor\Rules;

use PhpDoctor\Analysis\Ast\ParserPool;
use PhpDoctor\Analysis\Runtime\PhpStan\PhpStanRunnerInterface;
use PhpDoctor\Core\Rule\RuleRegistry;
use PhpDoctor\Rules\Common\ComposerAuditRule;
use PhpDoctor\Rules\Common\EnvDesyncRule;
use PhpDoctor\Rules\Common\HardcodedSecretsRule;
use PhpDoctor\Rules\Common\PhpStanAggregatorRule;
use PhpDoctor\Rules\Laravel\BladeBusinessLogicRule;
use PhpDoctor\Rules\Laravel\EloquentNPlusOneRule;
use PhpDoctor\Rules\Laravel\MassAssignmentRule;
use PhpDoctor\Rules\Symfony\MissingIsGrantedRule;
use PhpDoctor\Rules\Symfony\OrphanRouteRule;

/**
 * Central factory for the MVP rule set.
 *
 * Usage:
 *   $registry = RuleProvider::buildRegistry($parserPool, $phpStanRunner);
 */
final class RuleProvider
{
    /**
     * Build a RuleRegistry with all 8 MVP rules + the PhpStanAggregatorRule.
     *
     * @param PhpStanRunnerInterface|null $phpStanRunner  Optional; if null, PHPStan rule is omitted.
     */
    public static function buildRegistry(
        ParserPool              $parserPool,
        ?PhpStanRunnerInterface $phpStanRunner = null,
    ): RuleRegistry {
        $registry = new RuleRegistry();

        // --- Common rules ---
        $registry->register(new HardcodedSecretsRule($parserPool));
        $registry->register(new EnvDesyncRule());
        $registry->register(new ComposerAuditRule());

        // --- Symfony rules ---
        $registry->register(new MissingIsGrantedRule($parserPool));
        $registry->register(new OrphanRouteRule());

        // --- Laravel rules ---
        $registry->register(new EloquentNPlusOneRule($parserPool));
        $registry->register(new MassAssignmentRule($parserPool));
        $registry->register(new BladeBusinessLogicRule());

        // --- PHPStan (optional) ---
        if ($phpStanRunner !== null) {
            $registry->register(new PhpStanAggregatorRule($phpStanRunner));
        }

        return $registry;
    }
}
