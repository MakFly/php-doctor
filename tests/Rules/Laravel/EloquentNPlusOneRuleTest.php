<?php

declare(strict_types=1);

namespace PhpDoctor\Tests\Rules\Laravel;

use PhpDoctor\Analysis\Ast\AstSnapshot;
use PhpDoctor\Analysis\Ast\ParserPool;
use PhpDoctor\Core\AnalysisInput;
use PhpDoctor\Core\Project\Framework;
use PhpDoctor\Core\Project\FrameworkContext;
use PhpDoctor\Core\Rule\Category;
use PhpDoctor\Core\Rule\Severity;
use PhpDoctor\Rules\Laravel\EloquentNPlusOneRule;
use PhpParser\ErrorHandler\Collecting;
use PHPUnit\Framework\TestCase;

final class EloquentNPlusOneRuleTest extends TestCase
{
    private ParserPool $parserPool;
    private EloquentNPlusOneRule $rule;
    /** @var string[] */
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        $this->parserPool = new ParserPool();
        $this->rule       = new EloquentNPlusOneRule($this->parserPool);
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $f) {
            @unlink($f);
        }
    }

    private function makeInput(string $code): AnalysisInput
    {
        $ctx = new FrameworkContext(
            framework:     Framework::Laravel,
            rootPath:      '/fake',
            consoleBinary: null,
            composerData:  [],
            sourcePaths:   [],
        );

        $tmpFile = tempnam(sys_get_temp_dir(), 'phpdoctor_nplusone_') . '.php';
        file_put_contents($tmpFile, $code);
        $this->tmpFiles[] = $tmpFile;

        $snapshot = new AstSnapshot($this->parserPool, new Collecting());
        $snapshot->forFile($tmpFile);

        return new AnalysisInput($ctx, $snapshot);
    }

    // -------------------------------------------------------------------------
    // Positive tests
    // -------------------------------------------------------------------------

    public function testDetectsNPlusOnePropertyAccess(): void
    {
        $code = <<<'PHP'
            <?php
            $posts = Post::all();
            foreach ($posts as $post) {
                echo $post->author->name;
            }
            PHP;

        $findings = iterator_to_array($this->rule->analyze($this->makeInput($code)));

        $this->assertNotEmpty($findings);
        $this->assertSame('laravel.perf.eloquent-n-plus-one', $findings[0]->ruleId);
        $this->assertSame(Severity::High, $findings[0]->severity);
        $this->assertSame(Category::Performance, $findings[0]->category);
        $this->assertStringContainsString('->with(', $findings[0]->fixHint ?? '');
    }

    public function testDetectsNPlusOneMethodAccess(): void
    {
        $code = <<<'PHP'
            <?php
            $orders = Order::all();
            foreach ($orders as $order) {
                $items = $order->products;
            }
            PHP;

        $findings = iterator_to_array($this->rule->analyze($this->makeInput($code)));

        $this->assertNotEmpty($findings);
        $this->assertSame('laravel.perf.eloquent-n-plus-one', $findings[0]->ruleId);
    }

    // -------------------------------------------------------------------------
    // Negative tests
    // -------------------------------------------------------------------------

    public function testDoesNotFlagWhenWithIsInlinedInForeach(): void
    {
        // When ->with() is directly chained on the foreach expression, the rule
        // detects it and skips. This is the case the heuristic can handle reliably.
        $code = <<<'PHP'
            <?php
            foreach (Post::with('author')->get() as $post) {
                echo $post->author->name;
            }
            PHP;

        $findings = iterator_to_array($this->rule->analyze($this->makeInput($code)));

        $this->assertEmpty($findings);
    }

    public function testDoesNotFlagIdAccess(): void
    {
        $code = <<<'PHP'
            <?php
            $posts = Post::all();
            foreach ($posts as $post) {
                echo $post->id;
            }
            PHP;

        $findings = iterator_to_array($this->rule->analyze($this->makeInput($code)));

        $this->assertEmpty($findings);
    }

    public function testDoesNotFlagTimestampColumns(): void
    {
        $code = <<<'PHP'
            <?php
            $posts = Post::all();
            foreach ($posts as $post) {
                echo $post->created_at;
            }
            PHP;

        $findings = iterator_to_array($this->rule->analyze($this->makeInput($code)));

        $this->assertEmpty($findings);
    }

    public function testDoesNotApplyToNonLaravel(): void
    {
        $ctx = new FrameworkContext(
            framework:     Framework::Symfony,
            rootPath:      '/fake',
            consoleBinary: null,
            composerData:  [],
            sourcePaths:   [],
        );

        $this->assertFalse($this->rule->appliesTo($ctx));
    }
}
