<?php

declare(strict_types=1);

namespace PhpDoctor\Rules\Laravel;

use PhpDoctor\Core\AnalysisInput;
use PhpDoctor\Core\Finding\Finding;
use PhpDoctor\Core\Project\Framework;
use PhpDoctor\Core\Project\FrameworkContext;
use PhpDoctor\Core\Rule\Category;
use PhpDoctor\Core\Rule\Rule;
use PhpDoctor\Core\Rule\Severity;
use Symfony\Component\Finder\Finder;

/**
 * Detects business logic (database calls) inside Blade view templates.
 *
 * Scans resources/views/**\/*.blade.php for patterns like:
 *   DB::, \App\Models\, Model::query, ::all(), ::where(
 *
 * Skips silently if resources/views/ does not exist.
 * Does NOT use the AST — text scan only.
 */
final class BladeBusinessLogicRule implements Rule
{
    /** Patterns that indicate direct database access inside a view. */
    private const SUSPECT_PATTERNS = [
        'DB::',
        '\\App\\Models\\',
        'Model::query',
        '::all()',
        '::where(',
    ];

    public function id(): string
    {
        return 'laravel.architecture.blade-business-logic';
    }

    public function category(): Category
    {
        return Category::Architecture;
    }

    public function severity(): Severity
    {
        return Severity::Medium;
    }

    public function appliesTo(FrameworkContext $ctx): bool
    {
        return $ctx->framework === Framework::Laravel;
    }

    /**
     * @return iterable<Finding>
     */
    public function analyze(AnalysisInput $input): iterable
    {
        $viewsDir = rtrim($input->ctx->rootPath, '/') . '/resources/views';

        if (!is_dir($viewsDir)) {
            return;
        }

        $finder = new Finder();
        $finder->files()
               ->in($viewsDir)
               ->name('*.blade.php')
               ->sortByName();

        foreach ($finder as $file) {
            $path    = $file->getRealPath();
            $content = @file_get_contents($path);
            if ($content === false) {
                continue;
            }

            $lines = explode("\n", $content);

            foreach ($lines as $lineNumber => $lineContent) {
                foreach (self::SUSPECT_PATTERNS as $pattern) {
                    if (str_contains($lineContent, $pattern)) {
                        yield new Finding(
                            ruleId:   $this->id(),
                            severity: $this->severity(),
                            category: $this->category(),
                            message:  "Business logic in Blade view: '{$pattern}' found in template.",
                            file:     $path,
                            line:     $lineNumber + 1,
                            fixHint:  "Move database queries to the controller or a dedicated service/repository class.",
                        );
                        break; // One finding per line (first match wins)
                    }
                }
            }
        }
    }
}
