<?php

declare(strict_types=1);

namespace PhpDoctor\Tests\Rules\Symfony;

use PhpDoctor\Analysis\Runtime\RuntimeSnapshot;
use PhpDoctor\Core\AnalysisInput;
use PhpDoctor\Core\Project\Framework;
use PhpDoctor\Core\Project\FrameworkContext;
use PhpDoctor\Core\Rule\Severity;
use PhpDoctor\Rules\Symfony\OrphanRouteRule;
use PHPUnit\Framework\TestCase;

final class OrphanRouteRuleTest extends TestCase
{
    private string $tmpDir;
    private OrphanRouteRule $rule;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/phpdoctor_orphan_' . uniqid();
        mkdir($this->tmpDir . '/src/Controller', 0755, true);
        $this->rule = new OrphanRouteRule();
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmpDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $f) {
            $f->isDir() ? rmdir($f->getRealPath()) : unlink($f->getRealPath());
        }
        rmdir($this->tmpDir);
    }

    private function makeInput(array $routes, array $services = []): AnalysisInput
    {
        $ctx = new FrameworkContext(
            framework:     Framework::Symfony,
            rootPath:      $this->tmpDir,
            consoleBinary: null,
            composerData:  [],
            sourcePaths:   [],
        );

        $runtime = new RuntimeSnapshot(routes: $routes, services: $services);
        return new AnalysisInput($ctx, null, $runtime);
    }

    // -------------------------------------------------------------------------
    // Positive tests
    // -------------------------------------------------------------------------

    public function testDetectsOrphanRouteWhenClassMissing(): void
    {
        // No file on disk, not in services
        $routes = [
            'ghost_route' => [
                'path'     => '/ghost',
                'defaults' => ['_controller' => 'App\\Controller\\GhostController::index'],
            ],
        ];

        $findings = iterator_to_array($this->rule->analyze($this->makeInput($routes)));

        $this->assertCount(1, $findings);
        $this->assertSame('symfony.architecture.orphan-route', $findings[0]->ruleId);
        $this->assertSame(Severity::Low, $findings[0]->severity);
        $this->assertStringContainsString('App\\Controller\\GhostController', $findings[0]->message);
    }

    // -------------------------------------------------------------------------
    // Negative tests
    // -------------------------------------------------------------------------

    public function testDoesNotFlagRouteWhenControllerInServices(): void
    {
        $routes = [
            'app_index' => [
                'path'     => '/index',
                'defaults' => ['_controller' => 'App\\Controller\\HomeController::index'],
            ],
        ];

        $services = [
            'App\\Controller\\HomeController' => ['class' => 'App\\Controller\\HomeController'],
        ];

        $findings = iterator_to_array($this->rule->analyze($this->makeInput($routes, $services)));

        $this->assertEmpty($findings);
    }

    public function testDoesNotFlagRouteWhenFileExistsOnDisk(): void
    {
        $controllerFile = $this->tmpDir . '/src/Controller/ExistingController.php';
        file_put_contents($controllerFile, '<?php namespace App\Controller; class ExistingController {}');

        $routes = [
            'app_existing' => [
                'path'     => '/existing',
                'defaults' => ['_controller' => 'App\\Controller\\ExistingController::action'],
            ],
        ];

        $findings = iterator_to_array($this->rule->analyze($this->makeInput($routes)));

        $this->assertEmpty($findings);
    }

    public function testDoesNotFlagNonAppControllerNamespace(): void
    {
        $routes = [
            'framework_route' => [
                'path'     => '/foo',
                'defaults' => ['_controller' => 'Symfony\\Bundle\\FrameworkBundle\\Controller\\TemplateController::templateAction'],
            ],
        ];

        $findings = iterator_to_array($this->rule->analyze($this->makeInput($routes)));

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
