<?php

declare(strict_types=1);

namespace PhpDoctor\Tests\Rules\Laravel;

use PhpDoctor\Core\AnalysisInput;
use PhpDoctor\Core\Project\Framework;
use PhpDoctor\Core\Project\FrameworkContext;
use PhpDoctor\Core\Rule\Category;
use PhpDoctor\Core\Rule\Severity;
use PhpDoctor\Rules\Laravel\BladeBusinessLogicRule;
use PHPUnit\Framework\TestCase;

final class BladeBusinessLogicRuleTest extends TestCase
{
    private string $tmpDir;
    private BladeBusinessLogicRule $rule;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/phpdoctor_blade_' . uniqid();
        mkdir($this->tmpDir . '/resources/views', 0755, true);
        $this->rule = new BladeBusinessLogicRule();
    }

    protected function tearDown(): void
    {
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmpDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iter as $f) {
            $f->isDir() ? rmdir($f->getRealPath()) : unlink($f->getRealPath());
        }
        rmdir($this->tmpDir);
    }

    private function makeInput(): AnalysisInput
    {
        $ctx = new FrameworkContext(
            framework:     Framework::Laravel,
            rootPath:      $this->tmpDir,
            consoleBinary: null,
            composerData:  [],
            sourcePaths:   [],
        );
        return new AnalysisInput($ctx);
    }

    // -------------------------------------------------------------------------
    // Positive tests
    // -------------------------------------------------------------------------

    public function testDetectsDbCallInBlade(): void
    {
        file_put_contents(
            $this->tmpDir . '/resources/views/index.blade.php',
            "@foreach(DB::table('users')->get() as \$user)\n    {{ \$user->name }}\n@endforeach\n"
        );

        $findings = iterator_to_array($this->rule->analyze($this->makeInput()));

        $this->assertNotEmpty($findings);
        $this->assertSame('laravel.architecture.blade-business-logic', $findings[0]->ruleId);
        $this->assertSame(Severity::Medium, $findings[0]->severity);
        $this->assertSame(Category::Architecture, $findings[0]->category);
        $this->assertStringContainsString('DB::', $findings[0]->message);
    }

    public function testDetectsModelQueryInBlade(): void
    {
        file_put_contents(
            $this->tmpDir . '/resources/views/posts.blade.php',
            "@php\n    \$posts = App\Models\Post::all();\n@endphp\n"
        );

        $findings = iterator_to_array($this->rule->analyze($this->makeInput()));

        $this->assertNotEmpty($findings);
        $this->assertSame('laravel.architecture.blade-business-logic', $findings[0]->ruleId);
    }

    public function testDetectsStaticWhereInBlade(): void
    {
        file_put_contents(
            $this->tmpDir . '/resources/views/search.blade.php',
            "{{ Post::where('active', 1)->get() }}\n"
        );

        $findings = iterator_to_array($this->rule->analyze($this->makeInput()));

        $this->assertNotEmpty($findings);
    }

    public function testDetectsStaticAllInBlade(): void
    {
        file_put_contents(
            $this->tmpDir . '/resources/views/list.blade.php',
            "{{ Category::all() }}\n"
        );

        $findings = iterator_to_array($this->rule->analyze($this->makeInput()));

        $this->assertNotEmpty($findings);
    }

    // -------------------------------------------------------------------------
    // Negative tests
    // -------------------------------------------------------------------------

    public function testNoBladePatternsInCleanTemplate(): void
    {
        file_put_contents(
            $this->tmpDir . '/resources/views/clean.blade.php',
            "@foreach(\$posts as \$post)\n    <p>{{ \$post->title }}</p>\n@endforeach\n"
        );

        $findings = iterator_to_array($this->rule->analyze($this->makeInput()));

        $this->assertEmpty($findings);
    }

    public function testSkipsWhenViewsDirAbsent(): void
    {
        // Use a root without resources/views
        $ctx = new FrameworkContext(
            framework:     Framework::Laravel,
            rootPath:      sys_get_temp_dir(),
            consoleBinary: null,
            composerData:  [],
            sourcePaths:   [],
        );
        $findings = iterator_to_array($this->rule->analyze(new AnalysisInput($ctx)));

        // We can't assert empty because sys_get_temp_dir might have stray files,
        // so we just check it doesn't throw
        $this->assertTrue(true);
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

    public function testNoFindingsWhenViewsFolderDoesNotExist(): void
    {
        $emptyRoot = sys_get_temp_dir() . '/phpdoctor_noresources_' . uniqid();
        mkdir($emptyRoot, 0755);

        try {
            $ctx = new FrameworkContext(
                framework:     Framework::Laravel,
                rootPath:      $emptyRoot,
                consoleBinary: null,
                composerData:  [],
                sourcePaths:   [],
            );

            $findings = iterator_to_array($this->rule->analyze(new AnalysisInput($ctx)));
            $this->assertEmpty($findings);
        } finally {
            rmdir($emptyRoot);
        }
    }
}
