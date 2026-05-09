<?php

declare(strict_types=1);

namespace PhpDoctor\Tests\Rules\Symfony;

use PhpDoctor\Analysis\Ast\AstSnapshot;
use PhpDoctor\Analysis\Ast\ParserPool;
use PhpDoctor\Analysis\Runtime\RuntimeSnapshot;
use PhpDoctor\Core\AnalysisInput;
use PhpDoctor\Core\Project\Framework;
use PhpDoctor\Core\Project\FrameworkContext;
use PhpDoctor\Rules\Symfony\MissingIsGrantedRule;
use PhpParser\ErrorHandler\Collecting;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that MissingIsGrantedRule does NOT flag methods of a class
 * that carries a class-level #[IsGranted] or #[Security] attribute.
 */
final class MissingIsGrantedClassAttributeTest extends TestCase
{
    private string $tmpDir;
    private ParserPool $parserPool;
    private MissingIsGrantedRule $rule;

    protected function setUp(): void
    {
        $this->tmpDir    = sys_get_temp_dir() . '/phpdoctor_migclass_' . uniqid();
        mkdir($this->tmpDir . '/src/Controller', 0755, true);
        $this->parserPool = new ParserPool();
        $this->rule       = new MissingIsGrantedRule($this->parserPool);
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

    private function makeInput(string $controllerCode, array $routes): AnalysisInput
    {
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

    public function testClassLevelIsGrantedAttributeSuppressesAllMethodFindings(): void
    {
        $code = <<<'PHP'
            <?php
            namespace App\Controller;
            use Symfony\Component\Security\Http\Attribute\IsGranted;
            #[IsGranted('ROLE_ADMIN')]
            class TestController {
                public function index(): void {}
                public function show(): void {}
            }
            PHP;

        $routes = [
            'app_index' => [
                'path'     => '/admin',
                'defaults' => ['_controller' => 'App\\Controller\\TestController::index'],
            ],
            'app_show' => [
                'path'     => '/admin/show',
                'defaults' => ['_controller' => 'App\\Controller\\TestController::show'],
            ],
        ];

        $findings = iterator_to_array($this->rule->analyze($this->makeInput($code, $routes)));

        $this->assertEmpty(
            $findings,
            'No findings expected when class carries #[IsGranted] — all methods are implicitly protected',
        );
    }

    public function testClassLevelSecurityAttributeSuppressesAllMethodFindings(): void
    {
        $code = <<<'PHP'
            <?php
            namespace App\Controller;
            use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
            #[Security("is_granted('ROLE_USER')")]
            class TestController {
                public function dashboard(): void {}
            }
            PHP;

        $routes = [
            'app_dashboard' => [
                'path'     => '/dashboard',
                'defaults' => ['_controller' => 'App\\Controller\\TestController::dashboard'],
            ],
        ];

        $findings = iterator_to_array($this->rule->analyze($this->makeInput($code, $routes)));

        $this->assertEmpty(
            $findings,
            'No findings expected when class carries #[Security] — all methods are implicitly protected',
        );
    }

    public function testMethodWithoutClassAttributeStillProducesFindings(): void
    {
        // Sanity check: a class WITHOUT a class-level attribute DOES produce a finding.
        $code = <<<'PHP'
            <?php
            namespace App\Controller;
            class TestController {
                public function open(): void {}
            }
            PHP;

        $routes = [
            'app_open' => [
                'path'     => '/open',
                'defaults' => ['_controller' => 'App\\Controller\\TestController::open'],
            ],
        ];

        $findings = iterator_to_array($this->rule->analyze($this->makeInput($code, $routes)));

        $this->assertNotEmpty(
            $findings,
            'A finding IS expected when neither the class nor the method has an access control attribute',
        );
    }
}
