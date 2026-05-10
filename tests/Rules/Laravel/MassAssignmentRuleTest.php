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

    /**
     * Helper that loads two files into the same AstSnapshot: a model file and a
     * controller file. This allows the rule to resolve $fillable / $guarded from
     * the model class when checking the controller's mass-assignment call.
     */
    private function makeInputMultiFile(string $modelCode, string $controllerCode): AnalysisInput
    {
        $ctx = new FrameworkContext(
            framework:     Framework::Laravel,
            rootPath:      '/fake',
            consoleBinary: null,
            composerData:  [],
            sourcePaths:   [],
        );

        $modelFile = tempnam(sys_get_temp_dir(), 'phpdoctor_mass_model_') . '.php';
        file_put_contents($modelFile, $modelCode);
        $this->tmpFiles[] = $modelFile;

        $controllerFile = tempnam(sys_get_temp_dir(), 'phpdoctor_mass_ctrl_') . '.php';
        file_put_contents($controllerFile, $controllerCode);
        $this->tmpFiles[] = $controllerFile;

        $snapshot = new AstSnapshot($this->parserPool, new Collecting());
        $snapshot->forFile($modelFile);
        $snapshot->forFile($controllerFile);

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

    // -------------------------------------------------------------------------
    // New tests (Fix 3 — $fillable / $guarded resolution)
    // -------------------------------------------------------------------------

    public function testDowngradesToLowWhenModelHasFillable(): void
    {
        // Model has $fillable defined → severity should be Low, not High
        $modelCode = <<<'PHP'
            <?php
            namespace App\Models;
            class Post extends Model {
                protected $fillable = ['name', 'email'];
            }
            PHP;

        $controllerCode = <<<'PHP'
            <?php
            use App\Models\Post;
            class PostController {
                public function store(Request $request) {
                    Post::create($request->all());
                }
            }
            PHP;

        $findings = iterator_to_array($this->rule->analyze($this->makeInputMultiFile($modelCode, $controllerCode)));

        $this->assertNotEmpty($findings, 'Should still produce a finding (Low) to remind to verify $fillable coverage');
        $this->assertSame('laravel.security.mass-assignment', $findings[0]->ruleId);
        $this->assertSame(Severity::Low, $findings[0]->severity, 'Severity should be Low when $fillable is defined');
    }

    public function testUpgradesToCriticalWhenModelHasGuardedEmpty(): void
    {
        // Model has $guarded = [] → severity should be Critical
        $modelCode = <<<'PHP'
            <?php
            namespace App\Models;
            class Post extends Model {
                protected $guarded = [];
            }
            PHP;

        $controllerCode = <<<'PHP'
            <?php
            use App\Models\Post;
            class PostController {
                public function store(Request $request) {
                    Post::create($request->all());
                }
            }
            PHP;

        $findings = iterator_to_array($this->rule->analyze($this->makeInputMultiFile($modelCode, $controllerCode)));

        $this->assertNotEmpty($findings, 'Should produce a Critical finding for $guarded = []');
        $this->assertSame('laravel.security.mass-assignment', $findings[0]->ruleId);
        $this->assertSame(Severity::Critical, $findings[0]->severity, 'Severity should be Critical when $guarded = []');
    }

    public function testKeepsHighWhenModelNotFound(): void
    {
        // Model class not in the AST cache → severity stays High (original behaviour)
        $code = <<<'PHP'
            <?php
            class UserController {
                public function store(Request $request) {
                    User::create($request->all());
                }
            }
            PHP;

        $findings = iterator_to_array($this->rule->analyze($this->makeInput($code)));

        $this->assertNotEmpty($findings);
        $this->assertSame(Severity::High, $findings[0]->severity, 'Unknown model → should stay High');
    }
}
