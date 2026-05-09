<?php

declare(strict_types=1);

namespace PhpDoctor\Rules\Common;

use PhpDoctor\Analysis\Ast\ParserPool;
use PhpDoctor\Analysis\Ast\Visitors\AbstractRuleVisitor;
use PhpDoctor\Core\AnalysisInput;
use PhpDoctor\Core\Finding\Finding;
use PhpDoctor\Core\Finding\FindingBag;
use PhpDoctor\Core\Project\FrameworkContext;
use PhpDoctor\Core\Rule\Category;
use PhpDoctor\Core\Rule\Rule;
use PhpDoctor\Core\Rule\Severity;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use PhpParser\NodeVisitor\NameResolver;

/**
 * Detects hardcoded secrets in PHP source code.
 *
 * Triggers on:
 *  - AWS access keys (AKIA...)
 *  - GitHub personal access tokens (ghp_...)
 *  - JWT tokens (eyJ...eyJ...sig)
 *  - Secrets passed as second arg to define() or value of putenv("KEY=value")
 *    when the key matches PASSWORD|SECRET|TOKEN|API_KEY (case-insensitive) and
 *    the value is non-empty and not a placeholder ('changeme', '***', etc.).
 */
final class HardcodedSecretsRule implements Rule
{
    public function __construct(
        private readonly ParserPool $parserPool,
    ) {}

    public function id(): string
    {
        return 'common.security.hardcoded-secrets';
    }

    public function category(): Category
    {
        return Category::Security;
    }

    public function severity(): Severity
    {
        return Severity::Critical;
    }

    public function appliesTo(FrameworkContext $ctx): bool
    {
        return true;
    }

    /**
     * @return iterable<Finding>
     */
    public function analyze(AnalysisInput $input): iterable
    {
        if ($input->ast === null) {
            return;
        }

        foreach ($input->ast->entries() as $file => $stmts) {
            if ($stmts === null) {
                continue;
            }

            $bag = new FindingBag();
            $visitor = new HardcodedSecretsVisitor($bag, $file);

            $traverser = $this->parserPool->newTraverser();
            $traverser->addVisitor(new NameResolver(null, ['replaceNodes' => false]));
            $traverser->addVisitor($visitor);
            $traverser->traverse($stmts);

            foreach ($bag->all() as $finding) {
                yield $finding;
            }
        }
    }
}

/**
 * @internal
 */
final class HardcodedSecretsVisitor extends AbstractRuleVisitor
{
    private const SECRET_PATTERNS = [
        '/AKIA[0-9A-Z]{16}/',
        '/ghp_[0-9A-Za-z]{36}/',
        '/eyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}/',
    ];

    private const PLACEHOLDERS = ['changeme', '***', '', 'your_secret', 'your_token', 'your_key', 'xxxxxxxx'];

    private const SECRET_KEY_PATTERN = '/PASSWORD|SECRET|TOKEN|API_KEY/i';

    public function enterNode(Node $node): ?int
    {
        // 1. Check raw string literals against known secret patterns
        if ($node instanceof Node\Scalar\String_) {
            $value = $node->value;
            foreach (self::SECRET_PATTERNS as $pattern) {
                if (preg_match($pattern, $value)) {
                    $this->emit(
                        'common.security.hardcoded-secrets',
                        Severity::Critical,
                        Category::Security,
                        "Hardcoded secret detected: value matches known secret pattern.",
                        $node->getStartLine(),
                        "Remove the secret and use environment variables instead.",
                    );
                    return null;
                }
            }
        }

        // 2. Check define('SOME_SECRET', 'actual-value') — second arg
        if ($node instanceof Node\Expr\FuncCall
            && $node->name instanceof Node\Name
            && strtolower((string) $node->name) === 'define'
            && count($node->args) >= 2
        ) {
            $nameArg  = $node->args[0];
            $valueArg = $node->args[1];

            if ($nameArg instanceof Node\Arg
                && $nameArg->value instanceof Node\Scalar\String_
                && $valueArg instanceof Node\Arg
                && $valueArg->value instanceof Node\Scalar\String_
            ) {
                $keyName   = $nameArg->value->value;
                $keyValue  = $valueArg->value->value;

                if (preg_match(self::SECRET_KEY_PATTERN, $keyName)
                    && !in_array(strtolower($keyValue), self::PLACEHOLDERS, true)
                    && $keyValue !== ''
                ) {
                    $this->emit(
                        'common.security.hardcoded-secrets',
                        Severity::Critical,
                        Category::Security,
                        "Hardcoded secret in define('{$keyName}', ...) — key name suggests sensitive data.",
                        $node->getStartLine(),
                        "Use getenv() or \$_ENV['{$keyName}'] instead.",
                    );
                }
            }
        }

        // 3. Check putenv("KEY=value") — key matches secret pattern, value non-empty non-placeholder
        if ($node instanceof Node\Expr\FuncCall
            && $node->name instanceof Node\Name
            && strtolower((string) $node->name) === 'putenv'
            && count($node->args) >= 1
        ) {
            $arg = $node->args[0];
            if ($arg instanceof Node\Arg && $arg->value instanceof Node\Scalar\String_) {
                $envStr = $arg->value->value;
                if (str_contains($envStr, '=')) {
                    [$keyName, $keyValue] = explode('=', $envStr, 2);
                    if (preg_match(self::SECRET_KEY_PATTERN, $keyName)
                        && !in_array(strtolower($keyValue), self::PLACEHOLDERS, true)
                        && $keyValue !== ''
                    ) {
                        $this->emit(
                            'common.security.hardcoded-secrets',
                            Severity::Critical,
                            Category::Security,
                            "Hardcoded secret in putenv('{$keyName}=...') — key name suggests sensitive data.",
                            $node->getStartLine(),
                            "Do not call putenv() with secrets. Use a .env file loaded at bootstrap.",
                        );
                    }
                }
            }
        }

        return null;
    }
}
