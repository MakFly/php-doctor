<?php

declare(strict_types=1);

namespace PhpDoctor\Tests\Reporting\Html;

use PhpDoctor\Core\Finding\Finding;
use PhpDoctor\Core\Finding\FindingBag;
use PhpDoctor\Core\Finding\Score;
use PhpDoctor\Core\Project\Framework;
use PhpDoctor\Core\Project\FrameworkContext;
use PhpDoctor\Core\Rule\Category;
use PhpDoctor\Core\Rule\Severity;
use PhpDoctor\Reporting\Html\HtmlReporter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

final class HtmlReporterTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/php-doctor-html-test-' . uniqid('', true);
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        // Cleanup temp directory recursively
        $this->removeDir($this->tempDir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    private function makeContext(): FrameworkContext
    {
        return new FrameworkContext(
            framework:     Framework::Symfony,
            rootPath:      $this->tempDir,
            consoleBinary: null,
            composerData:  ['name' => 'acme/html-test-project'],
            sourcePaths:   [$this->tempDir],
        );
    }

    private function makeBag(): FindingBag
    {
        $bag = new FindingBag();
        $bag->add(new Finding(
            ruleId:   'symfony.security.missing-is-granted',
            severity: Severity::High,
            category: Category::Security,
            message:  'Controller action lacks access control',
            file:     $this->tempDir . '/src/Controller/FooController.php',
            line:     25,
            fixHint:  'Add #[IsGranted] attribute',
        ));
        $bag->add(new Finding(
            ruleId:   'common.hygiene.env-desync',
            severity: Severity::Medium,
            category: Category::Hygiene,
            message:  'Key APP_SECRET missing from .env.example',
            file:     null,
            line:     null,
        ));
        return $bag;
    }

    public function testHtmlReportIsWrittenToFile(): void
    {
        $outputFile = $this->tempDir . '/report.html';
        $reporter   = new HtmlReporter($outputFile);
        $output     = new BufferedOutput();
        $bag        = $this->makeBag();
        $score      = Score::fromBag($bag);
        $ctx        = $this->makeContext();

        $reporter->render($bag, $score, $ctx, $output);

        $this->assertFileExists($outputFile, 'HTML report file must be created');
    }

    public function testHtmlReportSizeIsAbove5KB(): void
    {
        $outputFile = $this->tempDir . '/report.html';
        $reporter   = new HtmlReporter($outputFile);
        $output     = new BufferedOutput();
        $bag        = $this->makeBag();
        $score      = Score::fromBag($bag);
        $ctx        = $this->makeContext();

        $reporter->render($bag, $score, $ctx, $output);

        $size = filesize($outputFile);
        $this->assertGreaterThan(5120, $size, 'HTML report must be larger than 5 KB');
    }

    public function testHtmlReportContainsTitle(): void
    {
        $outputFile = $this->tempDir . '/report.html';
        $reporter   = new HtmlReporter($outputFile);
        $output     = new BufferedOutput();
        $bag        = $this->makeBag();
        $score      = Score::fromBag($bag);
        $ctx        = $this->makeContext();

        $reporter->render($bag, $score, $ctx, $output);

        $content = file_get_contents($outputFile);
        $this->assertStringContainsString('<title>', $content);
        $this->assertStringContainsString('php-doctor', $content);
    }

    public function testHtmlReportContainsTailwindCdn(): void
    {
        $outputFile = $this->tempDir . '/report.html';
        $reporter   = new HtmlReporter($outputFile);
        $output     = new BufferedOutput();
        $bag        = $this->makeBag();
        $score      = Score::fromBag($bag);
        $ctx        = $this->makeContext();

        $reporter->render($bag, $score, $ctx, $output);

        $content = file_get_contents($outputFile);
        $this->assertStringContainsString('cdn.tailwindcss.com', $content);
    }

    public function testHtmlReportContainsGlobalScore(): void
    {
        $outputFile = $this->tempDir . '/report.html';
        $reporter   = new HtmlReporter($outputFile);
        $output     = new BufferedOutput();
        $bag        = $this->makeBag();
        $score      = Score::fromBag($bag);
        $ctx        = $this->makeContext();

        $reporter->render($bag, $score, $ctx, $output);

        $content = file_get_contents($outputFile);
        // Score global must appear in the report
        $this->assertStringContainsString((string) $score->global, $content);
    }

    public function testHtmlReportContainsFindingRuleId(): void
    {
        $outputFile = $this->tempDir . '/report.html';
        $reporter   = new HtmlReporter($outputFile);
        $output     = new BufferedOutput();
        $bag        = $this->makeBag();
        $score      = Score::fromBag($bag);
        $ctx        = $this->makeContext();

        $reporter->render($bag, $score, $ctx, $output);

        $content = file_get_contents($outputFile);
        $this->assertStringContainsString('symfony.security.missing-is-granted', $content);
    }

    public function testDefaultOutputWritesToBuildDir(): void
    {
        $reporter = new HtmlReporter(); // no explicit outputFile
        $output   = new BufferedOutput();
        $bag      = $this->makeBag();
        $score    = Score::fromBag($bag);
        $ctx      = $this->makeContext();

        $reporter->render($bag, $score, $ctx, $output);

        $expectedFile = $this->tempDir . '/build/php-doctor-report.html';
        $this->assertFileExists($expectedFile);
    }
}
