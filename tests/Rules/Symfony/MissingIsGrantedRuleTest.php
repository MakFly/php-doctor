<?php

declare(strict_types=1);

namespace PhpDoctor\Tests\Rules\Symfony;

use PhpDoctor\Analysis\Ast\AstSnapshot;
use PhpDoctor\Analysis\Ast\ParserPool;
use PhpDoctor\Analysis\Runtime\RuntimeSnapshot;
use PhpDoctor\Core\AnalysisInput;
use PhpDoctor\Core\Project\Framework;
use PhpDoctor\Core\Project\FrameworkContext;
use PhpDoctor\Core\Rule\Severity;
use PhpDoctor\Rules\Symfony\MissingIsGrantedRule;
use PhpParser\ErrorHandler\Collecting;
use PHPUnit\Framework\TestCase;

final class MissingIsGrantedRuleTest extends TestCase
{
    private string $tmpDir;
    private ParserPool $parserPool;
    private MissingIsGrantedRule $rule;

    protected function setUp(): void
    {
        $this->tmpDir    = sys_get_temp_dir() . '/phpdoctor_mig_' . uniqid();
        mkdir($this->tmpDir . '/src/Controller', 0755, true);
        $this->parserPool = new ParserPool();
        $this->rule       = new MissingIsGrantedRule($this->parserPool);
    }

    protected function tearDown(): void
    {
        // Recursively remove temp dir
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmpDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $f) {
            $f->isDir() ? rmdir($f->getRealPath()) : unlink($f->getRealPath());
        }
        rmdir($this->tmpDir);
    }

    private function makeInput(array $routes, string $controllerCode, string $controllerClass): AnalysisInput
    {
        // Write controller file
        $filePath = $this->tmpDir . '/src/Controller/TestController.php';
        file_put_contents($filePath, $controllerCode);

        $ctx = new FrameworkContext(
            framework:     Framework::Symfony,
            rootPath:      $this->tmpDir,
            consoleBinary: null,
            composerData:  [],
            sourcePaths:   [],
        );

        $snapshot = new AstSnapshot($this->parserPool, new Collecting());
        $snapshot->forFile($filePath);

        $runtime = new RuntimeSnapshot(routes: $routes);

        return new AnalysisInput($ctx, $snapshot, $runtime);
    }

    // -------------------------------------------------------------------------
    // Positive tests
    // -------------------------------------------------------------------------

    public function testDetectsMissingAccessControl(): void
    {
        $code = <<<'PHP'
            <?php
            namespace App\Controller;
            class TestController {
                public function show(): void {}
            }
            PHP;

        $routes = [
            'app_show' => [
                'path'     => '/posts',
                'defaults' => ['_controller' => 'App\\Controller\\TestController::show'],
            ],
        ];

        $findings = iterator_to_array($this->rule->analyze(
            $this->makeInput($routes, $code, 'App\\Controller\\TestController')
        ));

        $this->assertNotEmpty($findings);
        $this->assertSame('symfony.security.missing-is-granted', $findings[0]->ruleId);
        $this->assertSame(Severity::High, $findings[0]->severity);
        $this->assertStringContainsString('TestController::show', $findings[0]->message);
    }

    // -------------------------------------------------------------------------
    // Negative tests
    // -------------------------------------------------------------------------

    public function testDoesNotFlagMethodWithIsGrantedAttribute(): void
    {
        $code = <<<'PHP'
            <?php
            namespace App\Controller;
            use Symfony\Component\Security\Http\Attribute\IsGranted;
            class TestController {
                #[IsGranted('ROLE_USER')]
                public function show(): void {}
            }
            PHP;

        $routes = [
            'app_show' => [
                'path'     => '/posts',
                'defaults' => ['_controller' => 'App\\Controller\\TestController::show'],
            ],
        ];

        $findings = iterator_to_array($this->rule->analyze(
            $this->makeInput($routes, $code, 'App\\Controller\\TestController')
        ));

        $this->assertEmpty($findings);
    }

    public function testDoesNotFlagMethodWithDenyAccessUnlessGranted(): void
    {
        $code = <<<'PHP'
            <?php
            namespace App\Controller;
            class TestController {
                public function show(): void {
                    $this->denyAccessUnlessGranted('ROLE_USER');
                }
            }
            PHP;

        $routes = [
            'app_show' => [
                'path'     => '/posts',
                'defaults' => ['_controller' => 'App\\Controller\\TestController::show'],
            ],
        ];

        $findings = iterator_to_array($this->rule->analyze(
            $this->makeInput($routes, $code, 'App\\Controller\\TestController')
        ));

        $this->assertEmpty($findings);
    }

    public function testDoesNotFlagPublicRoute(): void
    {
        $code = <<<'PHP'
            <?php
            namespace App\Controller;
            class TestController {
                public function login(): void {}
            }
            PHP;

        $routes = [
            'app_login' => [
                'path'     => '/login',
                'defaults' => ['_controller' => 'App\\Controller\\TestController::login'],
            ],
        ];

        $findings = iterator_to_array($this->rule->analyze(
            $this->makeInput($routes, $code, 'App\\Controller\\TestController')
        ));

        $this->assertEmpty($findings);
    }

    public function testDoesNotFlagNonSymfonyProject(): void
    {
        $ctx = new FrameworkContext(
            framework:     Framework::Laravel,
            rootPath:      '/fake',
            consoleBinary: null,
            composerData:  [],
            sourcePaths:   [],
        );

        $this->assertFalse($this->rule->appliesTo($ctx));
    }
}
