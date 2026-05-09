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
use PhpDoctor\Rules\Laravel\MassAssignmentRule;
use PhpParser\ErrorHandler\Collecting;
use PHPUnit\Framework\TestCase;

final class MassAssignmentRuleTest extends TestCase
{
    private ParserPool $parserPool;
    private MassAssignmentRule $rule;
    /** @var string[] */
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        $this->parserPool = new ParserPool();
        $this->rule       = new MassAssignmentRule($this->parserPool);
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

        $tmpFile = tempnam(sys_get_temp_dir(), 'phpdoctor_mass_') . '.php';
        file_put_contents($tmpFile, $code);
        $this->tmpFiles[] = $tmpFile;

        $snapshot = new AstSnapshot($this->parserPool, new Collecting());
        $snapshot->forFile($tmpFile);

        return new AnalysisInput($ctx, $snapshot);
    }

    // -------------------------------------------------------------------------
    // Positive tests
    // -------------------------------------------------------------------------

    public function testDetectsStaticCreateWithRequestAll(): void
    {
        $code = <<<'PHP'
            <?php
            use App\Models\User;
            class UserController {
                public function store(Request $request) {
                    User::create($request->all());
                }
            }
            PHP;

        $findings = iterator_to_array($this->rule->analyze($this->makeInput($code)));

        $this->assertNotEmpty($findings);
        $this->assertSame('laravel.security.mass-assignment', $findings[0]->ruleId);
        $this->assertSame(Severity::High, $findings[0]->severity);
        $this->assertSame(Category::Security, $findings[0]->category);
        $this->assertStringContainsString('fillable', $findings[0]->fixHint ?? '');
    }

    public function testDetectsInstanceFillWithRequestAll(): void
    {
        $code = <<<'PHP'
            <?php
            class UserController {
                public function update(Request $req) {
                    $user = User::find(1);
                    $user->fill($req->all());
                    $user->save();
                }
            }
            PHP;

        $findings = iterator_to_array($this->rule->analyze($this->makeInput($code)));

        $this->assertNotEmpty($findings);
        $this->assertSame('laravel.security.mass-assignment', $findings[0]->ruleId);
    }

    public function testDetectsUpdateWithRequestAll(): void
    {
        $code = <<<'PHP'
            <?php
            class PostController {
                public function update(Request $request, Post $post) {
                    $post->update($request->all());
                }
            }
            PHP;

        $findings = iterator_to_array($this->rule->analyze($this->makeInput($code)));

        $this->assertNotEmpty($findings);
        $this->assertSame('laravel.security.mass-assignment', $findings[0]->ruleId);
    }

    // -------------------------------------------------------------------------
    // Negative tests
    // -------------------------------------------------------------------------

    public function testDoesNotFlagCreateWithOnlyMethod(): void
    {
        $code = <<<'PHP'
            <?php
            class UserController {
                public function store(Request $request) {
                    User::create($request->only(['name', 'email']));
                }
            }
            PHP;

        $findings = iterator_to_array($this->rule->analyze($this->makeInput($code)));

        $this->assertEmpty($findings);
    }

    public function testDoesNotFlagCreateWithValidatedMethod(): void
    {
        $code = <<<'PHP'
            <?php
            class UserController {
                public function store(Request $request) {
                    User::create($request->validated());
                }
            }
            PHP;

        $findings = iterator_to_array($this->rule->analyze($this->makeInput($code)));

        $this->assertEmpty($findings);
    }

    public function testDoesNotFlagCreateWithPlainArray(): void
    {
        $code = <<<'PHP'
            <?php
            User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
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
