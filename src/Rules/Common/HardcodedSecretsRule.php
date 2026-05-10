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
 *  - Stripe live secret keys (sk_live_...)
 *  - Slack bot tokens (xoxb-...)
 *  - Google API keys (AIza...)
 *  - Secrets passed as second arg to define() or value of putenv("KEY=value")
 *    when the key matches PASSWORD|SECRET|TOKEN|API_KEY|PRIVATE_KEY|ACCESS_KEY|
 *    SECRET_KEY (case-insensitive) and the value is non-empty and not a
 *    placeholder ('changeme', '***', etc.).
 *  - ArrayItem whose key matches the secret-key pattern and value is a
 *    non-placeholder string (e.g. ['api_key' => 'sk_live_...'] in config files).
 *  - ClassConst whose name matches the secret-key pattern and value is a
 *    non-placeholder string.
 *  - Property (class property) whose name matches the secret-key pattern and
 *    the default value is a non-placeholder string.
 *  - Simple assignments $x = '...' or $x['key'] = '...' whose LHS matches
 *    the secret-key pattern.
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
    /** Patterns that unconditionally indicate a real secret token value. */
    private const SECRET_PATTERNS = [
        '/AKIA[0-9A-Z]{16}/',
        '/ghp_[0-9A-Za-z]{36}/',
        '/eyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}/',
        '/sk_live_[A-Za-z0-9]{24,}/',
        '/xoxb-[0-9A-Za-z-]{10,}/',
        '/AIza[0-9A-Za-z\-_]{35}/',
    ];

    /** Values that look like placeholders — never a real secret. */
    private const PLACEHOLDERS = [
        'changeme', '***', '', 'your_secret', 'your_token', 'your_key',
        'xxxxxxxx', 'placeholder', 'replace_me', 'todo', 'fixme',
        'secret', 'password', 'token',
    ];

    /**
     * Key-name pattern that suggests the value is sensitive.
     * Matches PASSWORD, SECRET, TOKEN, API_KEY, PRIVATE_KEY, ACCESS_KEY, SECRET_KEY.
     */
    private const SECRET_KEY_PATTERN = '/PASSWORD|SECRET|TOKEN|API_KEY|PRIVATE_KEY|ACCESS_KEY|SECRET_KEY/i';

    public function enterNode(Node $node): ?int
    {
        // ------------------------------------------------------------------ //
        // 1. Raw string literals against known secret patterns                //
        // ------------------------------------------------------------------ //
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

        // ------------------------------------------------------------------ //
        // 2. define('SOME_SECRET', 'actual-value') — second arg              //
        // ------------------------------------------------------------------ //
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
                    && !$this->isPlaceholder($keyValue)
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

        // ------------------------------------------------------------------ //
        // 3. putenv("KEY=value") — key matches secret pattern                //
        // ------------------------------------------------------------------ //
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
                        && !$this->isPlaceholder($keyValue)
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

        // ------------------------------------------------------------------ //
        // 4. ArrayItem: ['api_key' => 'sk_live_...'] or ['password' => 'x']  //
        // ------------------------------------------------------------------ //
        if ($node instanceof Node\Expr\ArrayItem
            && $node->key instanceof Node\Scalar\String_
            && $node->value instanceof Node\Scalar\String_
        ) {
            $keyName  = $node->key->value;
            $keyValue = $node->value->value;

            if (preg_match(self::SECRET_KEY_PATTERN, $keyName)
                && !$this->isPlaceholder($keyValue)
            ) {
                $this->emit(
                    'common.security.hardcoded-secrets',
                    Severity::Critical,
                    Category::Security,
                    "Hardcoded secret in array key '{$keyName}' — value appears to be a real credential.",
                    $node->getStartLine(),
                    "Use env('{$keyName}') or getenv('{$keyName}') instead of a hardcoded string.",
                );
            }
        }

        // ------------------------------------------------------------------ //
        // 5. ClassConst: const API_TOKEN = 'real-value';                      //
        // ------------------------------------------------------------------ //
        if ($node instanceof Node\Stmt\ClassConst) {
            foreach ($node->consts as $const) {
                // $const->name is always Node\Identifier (non-nullable per php-parser API)
                $constName  = $const->name->name;
                $constValue = $const->value instanceof Node\Scalar\String_ ? $const->value->value : null;

                if ($constValue !== null
                    && preg_match(self::SECRET_KEY_PATTERN, $constName)
                    && !$this->isPlaceholder($constValue)
                ) {
                    $this->emit(
                        'common.security.hardcoded-secrets',
                        Severity::Critical,
                        Category::Security,
                        "Hardcoded secret in class constant '{$constName}' — value appears to be a real credential.",
                        $const->getStartLine(),
                        "Use an environment variable loaded at runtime instead of a class constant.",
                    );
                }
            }
        }

        // ------------------------------------------------------------------ //
        // 6. Property: private string $apiKey = 'real-value';                //
        // ------------------------------------------------------------------ //
        if ($node instanceof Node\Stmt\Property) {
            foreach ($node->props as $prop) {
                // $prop->name is Node\VarLikeIdentifier (extends Node\Identifier, always non-null)
                $propName  = $prop->name->name;
                $propValue = $prop->default instanceof Node\Scalar\String_ ? $prop->default->value : null;

                if ($propValue !== null
                    && preg_match(self::SECRET_KEY_PATTERN, $propName)
                    && !$this->isPlaceholder($propValue)
                ) {
                    $this->emit(
                        'common.security.hardcoded-secrets',
                        Severity::Critical,
                        Category::Security,
                        "Hardcoded secret in property '\${$propName}' — value appears to be a real credential.",
                        $prop->getStartLine(),
                        "Inject the value via constructor or environment variable.",
                    );
                }
            }
        }

        // ------------------------------------------------------------------ //
        // 7. Assignment: $apiKey = '...'; or $config['api_key'] = '...';     //
        // ------------------------------------------------------------------ //
        if ($node instanceof Node\Expr\Assign
            && $node->expr instanceof Node\Scalar\String_
        ) {
            $keyName  = $this->extractAssignmentKeyName($node->var);
            $keyValue = $node->expr->value;

            if ($keyName !== null
                && preg_match(self::SECRET_KEY_PATTERN, $keyName)
                && !$this->isPlaceholder($keyValue)
            ) {
                $this->emit(
                    'common.security.hardcoded-secrets',
                    Severity::Critical,
                    Category::Security,
                    "Hardcoded secret assigned to '{$keyName}' — value appears to be a real credential.",
                    $node->getStartLine(),
                    "Use an environment variable instead of a hardcoded string.",
                );
            }
        }

        return null;
    }

    /**
     * Extract a representative key name from the left-hand side of an assignment.
     *
     * Handles:
     *  - $apiKey  → "apiKey"
     *  - $config['api_key']  → "api_key"
     */
    private function extractAssignmentKeyName(Node\Expr $var): ?string
    {
        if ($var instanceof Node\Expr\Variable && is_string($var->name)) {
            return $var->name;
        }

        if ($var instanceof Node\Expr\ArrayDimFetch
            && $var->dim instanceof Node\Scalar\String_
        ) {
            return $var->dim->value;
        }

        return null;
    }

    /**
     * Return true if the value is clearly a placeholder / sentinel and
     * should never be treated as a real secret.
     */
    /**
     * Return true if the value looks like a PCRE regex literal (starts and ends
     * with a delimiter, optionally followed by flags). Regex constants like
     * `/PASSWORD|TOKEN/i` are common in security tooling itself and must not
     * be flagged as real secrets — that would be a self-inflicted false positive.
     */
    private function looksLikeRegex(string $value): bool
    {
        if (strlen($value) < 3) {
            return false;
        }
        $first = $value[0];
        // Common PCRE delimiters
        if (!in_array($first, ['/', '#', '~', '%', '@', '!'], true)) {
            return false;
        }
        // Find a matching closing delimiter somewhere after position 0
        $rest = substr($value, 1);
        $closing = strrpos($rest, $first);
        if ($closing === false || $closing === 0) {
            return false;
        }
        $afterDelim = substr($rest, $closing + 1);
        // After the closing delimiter, only valid PCRE flags (or nothing) may appear
        return $afterDelim === '' || preg_match('/^[imsxuADSUXJ]+$/', $afterDelim) === 1;
    }

    private function isPlaceholder(string $value): bool
    {
        if ($value === '') {
            return true;
        }
        if (in_array(strtolower($value), self::PLACEHOLDERS, true)) {
            return true;
        }
        // Regex literals (e.g. SECRET_KEY_PATTERN = '/PASSWORD|TOKEN/i') are
        // tooling, not credentials — treat as placeholder to avoid self-FPs.
        return $this->looksLikeRegex($value);
    }
}
