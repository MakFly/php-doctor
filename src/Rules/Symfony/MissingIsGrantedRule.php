<?php

declare(strict_types=1);

namespace PhpDoctor\Rules\Symfony;

use PhpDoctor\Analysis\Ast\ParserPool;
use PhpDoctor\Core\AnalysisInput;
use PhpDoctor\Core\Finding\Finding;
use PhpDoctor\Core\Project\Framework;
use PhpDoctor\Core\Project\FrameworkContext;
use PhpDoctor\Core\Rule\Category;
use PhpDoctor\Core\Rule\Rule;
use PhpDoctor\Core\Rule\Severity;
use PhpParser\Node;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;

/**
 * Detects Symfony controller actions that are publicly accessible without
 * access control (#[IsGranted], #[Security], or denyAccessUnlessGranted()).
 *
 * Works by:
 *  1. Iterating routes from the runtime snapshot.
 *  2. Resolving the controller class/method via PSR-4 heuristic.
 *  3. Parsing the file's AST (or fetching from the AstSnapshot cache).
 *  4. Checking the method for access-control annotations/attributes.
 */
final class MissingIsGrantedRule implements Rule
{
    /** Routes exempt from access control (public by design). */
    private const PUBLIC_PATH_PATTERNS = [
        '/',
        '/login',
        '/logout',
        '/register',
        '/health',
    ];

    public function __construct(
        private readonly ParserPool $parserPool,
    ) {}

    public function id(): string
    {
        return 'symfony.security.missing-is-granted';
    }

    public function category(): Category
    {
        return Category::Security;
    }

    public function severity(): Severity
    {
        return Severity::High;
    }

    public function appliesTo(FrameworkContext $ctx): bool
    {
        return $ctx->framework === Framework::Symfony;
    }

    /**
     * @return iterable<Finding>
     */
    public function analyze(AnalysisInput $input): iterable
    {
        if ($input->runtime === null || $input->runtime->routes === null) {
            return;
        }

        foreach ($input->runtime->routes as $routeName => $routeDef) {
            if (!is_array($routeDef)) {
                continue;
            }

            // Skip routes whose path is in the public whitelist or starts with /_
            $path = $routeDef['path'] ?? '';
            if ($this->isPublicRoute($path)) {
                continue;
            }

            // Resolve controller class and method
            $controller = $this->resolveController($routeDef);
            if ($controller === null) {
                continue;
            }

            [$className, $methodName] = $controller;

            // Only check App\Controller\ namespace
            if (!str_starts_with($className, 'App\\Controller\\')) {
                continue;
            }

            // Resolve file path via PSR-4 heuristic
            $filePath = $this->classToFilePath($className, $input->ctx->rootPath);
            if ($filePath === null || !is_file($filePath)) {
                continue;
            }

            // Get or parse the AST
            $stmts = $input->ast?->forFile($filePath);
            if ($stmts === null) {
                // Parse on demand
                $code = @file_get_contents($filePath);
                if ($code === false) {
                    continue;
                }
                $traverser = $this->parserPool->newTraverser();
                $traverser->addVisitor(new NameResolver(null, ['replaceNodes' => false]));
                $stmts = $this->parserPool->getParser()->parse($code) ?? [];
                $traverser->traverse($stmts);
            }

            // Check if the method has access control
            $finding = $this->checkMethod($stmts, $className, $methodName, $filePath, (string) $routeName);
            if ($finding !== null) {
                yield $finding;
            }
        }
    }

    /**
     * @param array<Node> $stmts
     */
    private function checkMethod(
        array  $stmts,
        string $className,
        string $methodName,
        string $filePath,
        string $routeName,
    ): ?Finding {
        // Extract short class name for matching
        $shortClass = substr($className, strrpos($className, '\\') + 1);

        foreach ($stmts as $stmt) {
            // Find the class
            if (!($stmt instanceof Node\Stmt\Class_) && !($stmt instanceof Node\Stmt\Namespace_)) {
                continue;
            }

            $classStmts = $stmt instanceof Node\Stmt\Namespace_
                ? $stmt->stmts
                : [$stmt];

            foreach ($classStmts as $inner) {
                if (!($inner instanceof Node\Stmt\Class_)) {
                    continue;
                }

                $declaredName = (string) ($inner->name ?? '');
                if ($declaredName !== $shortClass) {
                    continue;
                }

                // If the class itself carries #[IsGranted] or #[Security], every
                // method in it is protected — skip the whole class.
                if ($this->hasAccessControlAttribute($inner->attrGroups)) {
                    return null;
                }

                // Find the method
                foreach ($inner->stmts as $member) {
                    if (!($member instanceof Node\Stmt\ClassMethod)) {
                        continue;
                    }

                    $declaredMethod = (string) $member->name;
                    if ($declaredMethod !== $methodName) {
                        continue;
                    }

                    // Check for #[IsGranted] or #[Security] attribute on the method
                    if ($this->hasAccessControlAttribute($member->attrGroups)) {
                        return null; // protected
                    }

                    // Check for denyAccessUnlessGranted() call in method body
                    if ($this->hasDenyAccessCall($member->stmts ?? [])) {
                        return null; // protected
                    }

                    // No access control found
                    return new Finding(
                        ruleId:   $this->id(),
                        severity: $this->severity(),
                        category: $this->category(),
                        message:  "Controller method {$className}::{$methodName}() on route '{$routeName}' has no access control (#[IsGranted], #[Security], or denyAccessUnlessGranted).",
                        file:     $filePath,
                        line:     $member->getStartLine(),
                        fixHint:  "Add #[IsGranted('ROLE_USER')] attribute or call \$this->denyAccessUnlessGranted().",
                    );
                }
            }
        }

        return null;
    }

    /**
     * Return true if any attribute group contains IsGranted or Security.
     *
     * @param Node\AttributeGroup[] $attrGroups
     */
    private function hasAccessControlAttribute(array $attrGroups): bool
    {
        foreach ($attrGroups as $attrGroup) {
            foreach ($attrGroup->attrs as $attr) {
                $attrName = (string) $attr->name;
                if (str_ends_with($attrName, 'IsGranted') || str_ends_with($attrName, 'Security')) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Recursively search for a $this->denyAccessUnlessGranted() call anywhere
     * in the method body — including inside if/try/foreach/match blocks.
     *
     * Only calls on $this are considered authoritative (guards against false
     * negatives from calls on other objects with the same method name).
     *
     * @param Node[] $stmts
     */
    private function hasDenyAccessCall(array $stmts): bool
    {
        foreach ($stmts as $stmt) {
            if ($this->nodeContainsDenyAccessCall($stmt)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Recursively check whether a node or any of its descendants contains a
     * $this->denyAccessUnlessGranted(...) call.
     */
    private function nodeContainsDenyAccessCall(Node $node): bool
    {
        // Direct match: expression statement with $this->denyAccessUnlessGranted(...)
        if ($node instanceof Node\Stmt\Expression
            && $node->expr instanceof Node\Expr\MethodCall
        ) {
            $call = $node->expr;
            if ($call->name instanceof Node\Identifier
                && $call->name->name === 'denyAccessUnlessGranted'
                && $call->var instanceof Node\Expr\Variable
                && $call->var->name === 'this'
            ) {
                return true;
            }
        }

        // Also match bare method call expressions (e.g. inside a return or condition)
        if ($node instanceof Node\Expr\MethodCall
            && $node->name instanceof Node\Identifier
            && $node->name->name === 'denyAccessUnlessGranted'
            && $node->var instanceof Node\Expr\Variable
            && $node->var->name === 'this'
        ) {
            return true;
        }

        // Recurse into all child nodes
        foreach ($node->getSubNodeNames() as $subName) {
            $sub = $node->$subName;
            if ($sub instanceof Node) {
                if ($this->nodeContainsDenyAccessCall($sub)) {
                    return true;
                }
            } elseif (is_array($sub)) {
                foreach ($sub as $child) {
                    if ($child instanceof Node && $this->nodeContainsDenyAccessCall($child)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $routeDef
     * @return array{string, string}|null  [className, methodName]
     */
    private function resolveController(array $routeDef): ?array
    {
        // Symfony debug:router format: defaults._controller = 'App\Controller\FooController::action'
        $controller = $routeDef['defaults']['_controller']
            ?? $routeDef['class']
            ?? null;

        if (!is_string($controller) || $controller === '') {
            return null;
        }

        if (str_contains($controller, '::')) {
            [$class, $method] = explode('::', $controller, 2);
            return [trim($class), trim($method)];
        }

        // Default method name fallback
        return [trim($controller), '__invoke'];
    }

    /**
     * PSR-4 heuristic: App\Controller\FooController → <root>/src/Controller/FooController.php
     */
    private function classToFilePath(string $className, string $rootPath): ?string
    {
        if (!str_starts_with($className, 'App\\')) {
            return null;
        }

        $relative = str_replace('\\', '/', substr($className, 4)); // remove 'App\'
        return rtrim($rootPath, '/') . '/src/' . $relative . '.php';
    }

    private function isPublicRoute(string $path): bool
    {
        if (str_starts_with($path, '/_')) {
            return true;
        }
        return in_array($path, self::PUBLIC_PATH_PATTERNS, true);
    }
}
